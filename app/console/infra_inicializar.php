<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Cdc\CdcProcessor;
use CRM\Infrastructure\Redis\RedisConnections;

require_once __DIR__ . '/_bootstrap.php';

$reindex = in_array('--reindex', $argv, true);
$reindexOperationalOnly = in_array('--reindex-operacional', $argv, true);
$reindexOperationalKinds = null;
$batch = 500;
$attempts = 12;
$interval = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--reindex-operacional=')) {
        $rawKinds = substr($arg, strlen('--reindex-operacional='));
        $reindexOperationalKinds = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawKinds)))));
        $allowedKinds = ['clientes', 'acordos', 'preventiva', 'acionamentos', 'estrategias'];
        $unknownKinds = array_values(array_diff($reindexOperationalKinds, $allowedKinds));
        if ($reindexOperationalKinds === [] || $unknownKinds !== []) {
            fwrite(STDERR, 'ERRO: use --reindex-operacional=clientes,acordos,preventiva,acionamentos,estrategias' . PHP_EOL);
            exit(1);
        }
    } elseif (str_starts_with($arg, '--batch=')) {
        $batch = max(50, min(2000, (int) substr($arg, 8)));
    } elseif (str_starts_with($arg, '--attempts=')) {
        $attempts = max(1, min(30, (int) substr($arg, 11)));
    } elseif (str_starts_with($arg, '--interval=')) {
        $interval = max(1, min(30, (int) substr($arg, 11)));
    }
}

$retry = static function (string $operation, callable $callback) use ($attempts, $interval): mixed {
    $lastError = null;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            return $callback();
        } catch (Throwable $e) {
            $lastError = $e;
            if ($attempt >= $attempts) {
                break;
            }
            echo sprintf(
                "AGUARDANDO %s; tentativa %d/%d: %s\n",
                $operation,
                $attempt + 1,
                $attempts,
                $e->getMessage()
            );
            sleep($interval);
        }
    }

    throw new RuntimeException(
        sprintf('%s falhou apos %d tentativa(s): %s', $operation, $attempts, $lastError?->getMessage() ?? 'erro desconhecido'),
        0,
        $lastError
    );
};

try {
    echo "Inicializando OpenSearch e ClickHouse...\n";
    $processor = new CdcProcessor(Database::pdo());
    $retry('inicializacao das estruturas', static function () use ($processor): bool {
        $processor->initialize();
        return true;
    });
    RedisConnections::cache()->setEx('crm:infra:initialized', 86400 * 30, date(DATE_ATOM));
    RedisConnections::queue()->ping();
    echo "Estruturas prontas. Redis Cache e Redis Queue acessiveis.\n";

    if ($reindex) {
        echo "Indexando contratos existentes no OpenSearch (batch=$batch)...\n";
        $result = $processor->search()->reindexAll($batch);
        echo sprintf("OpenSearch atualizado: %d contratos; ultimo ID: %d.\n", $result['indexed'], $result['last_id']);
    }

    if ($reindex || $reindexOperationalOnly || $reindexOperationalKinds !== null) {
        $selectedKinds = $reindex ? null : $reindexOperationalKinds;
        $description = $selectedKinds === null ? 'clientes, acordos, preventiva, acionamentos e estrategias' : implode(', ', $selectedKinds);
        echo "Indexando dados operacionais no OpenSearch ($description)...\n";
        $processor->operational()->reindexAll(
            $batch,
            $selectedKinds,
            static function (string $kind, array $summary): void {
                echo sprintf(
                    "OpenSearch %s: %d documentos; ultimo ID: %d.\n",
                    $kind,
                    $summary['indexed'],
                    $summary['last_id']
                );
            }
        );
    }

    // As contagens sao informativas. A validacao obrigatoria de conexao e
    // autenticacao ja ocorreu antes, e um timeout temporario nesta etapa nao
    // deve impedir a ativacao dos workers.
    try {
        $search = $retry('contagem do OpenSearch', static fn (): array => $processor->search()->status(30));
        echo sprintf("OpenSearch contratos: %d documentos.\n", $search['documents']);
        $operationalStatus = $retry('contagem operacional do OpenSearch', static fn (): array => $processor->operational()->status(30));
        foreach ($operationalStatus as $kind => $status) {
            echo sprintf("OpenSearch %s: %d documentos.\n", $kind, $status['documents']);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'AVISO: contagem do OpenSearch indisponivel neste momento: ' . $e->getMessage() . PHP_EOL);
    }

    try {
        $analytics = $retry('contagem do ClickHouse', static fn (): array => $processor->analytics()->status(30));
        echo sprintf("ClickHouse: %d eventos.\n", $analytics['events']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'AVISO: contagem do ClickHouse indisponivel neste momento: ' . $e->getMessage() . PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
