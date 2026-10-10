<?php

declare(strict_types=1);

use CRM\Infrastructure\Cdc\CdcWorkerSupport;
use CRM\Infrastructure\Redis\RedisConnections;

require_once __DIR__ . '/_bootstrap.php';

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$processor = null;
for ($initializeAttempt = 1; ; $initializeAttempt++) {
    try {
        $processor = CdcWorkerSupport::newProcessor();
        break;
    } catch (Throwable $e) {
        if ($initializeAttempt >= 12) {
            throw $e;
        }
        fwrite(STDERR, sprintf(
            "Aguardando estruturas CDC Retry (tentativa %d/12): %s\n",
            $initializeAttempt + 1,
            $e->getMessage()
        ));
        sleep(5);
    }
}
$queue = RedisConnections::queue();
echo "CDC Retry Worker iniciado.\n";

$heartbeat = static function (): void {
    try {
        RedisConnections::cache()->setEx('crm:infra:cdc_retry_worker_heartbeat', 120, date(DATE_ATOM));
    } catch (Throwable) {
    }
};
$heartbeat();

$autoRecoverDead = static function () use ($queue): int {
    $initial = (int) $queue->lLen('crm:cdc:dead');
    $limit = min($initial, 200);
    $moved = 0;

    // Processa no maximo a quantidade existente no inicio do sweep. Itens que
    // nao sao recuperaveis sao rotacionados para o fim sem entrar em loop.
    for ($i = 0; $i < $limit; $i++) {
        $raw = $queue->lPop('crm:cdc:dead');
        if (!is_string($raw) || $raw === '') {
            break;
        }

        $job = json_decode($raw, true);
        if (!is_array($job)) {
            $queue->rPush('crm:cdc:dead', $raw);
            continue;
        }

        $prepared = CdcWorkerSupport::prepareAutomaticRetry($job);
        if ($prepared === null) {
            $queue->rPush('crm:cdc:dead', $raw);
            continue;
        }

        $queue->rPush(
            'crm:cdc:retry',
            json_encode($prepared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        $moved++;
    }

    return $moved;
};

$nextAutoRecoveryAt = 0;

while ($running) {
    if (time() >= $nextAutoRecoveryAt) {
        try {
            $moved = $autoRecoverDead();
            if ($moved > 0) {
                echo sprintf("CDC Retry recuperou automaticamente %d evento(s) da fila de analise.\n", $moved);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'Falha no sweep automatico da dead-letter: ' . $e->getMessage() . PHP_EOL);
        }
        $nextAutoRecoveryAt = time() + 60;
    }
    $item = $queue->blPop(['crm:cdc:retry'], 10);
    if (!is_array($item) || !isset($item[1])) {
        $heartbeat();
        continue;
    }

    $job = null;
    try {
        $job = json_decode((string) $item[1], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($job) || !is_array($job['event'] ?? null)) {
            throw new RuntimeException('Job de retry invalido.');
        }
        if ((bool) ($job['analytics_done'] ?? false)) {
            $processor->processProjection((string) $job['topic'], (int) $job['partition'], (int) $job['offset'], $job['event']);
        } else {
            $processor->process((string) $job['topic'], (int) $job['partition'], (int) $job['offset'], $job['event']);
        }
    } catch (Throwable $e) {
        if (CdcWorkerSupport::isMysqlConnectionLost($e) && is_array($job ?? null) && is_array($job['event'] ?? null)) {
            try {
                fwrite(STDERR, 'Conexão MySQL do CDC Retry expirou; recriando conexão e repetindo o evento sem consumir uma tentativa.' . PHP_EOL);
                $processor = CdcWorkerSupport::newProcessor();
                if ((bool) ($job['analytics_done'] ?? false)) {
                    $processor->processProjection((string) $job['topic'], (int) $job['partition'], (int) $job['offset'], $job['event']);
                } else {
                    $processor->process((string) $job['topic'], (int) $job['partition'], (int) $job['offset'], $job['event']);
                }
                try {
                    RedisConnections::cache()->hIncrBy('crm:infra:cdc', 'mysql_reconnect_total', 1);
                } catch (Throwable) {
                }
                $heartbeat();
                continue;
            } catch (Throwable $reconnectError) {
                $e = $reconnectError;
            }
        }

        $job = is_array($job ?? null) ? $job : ['raw' => (string) $item[1]];
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        $job['last_error'] = $e->getMessage();
        $job['last_attempt_at'] = date(DATE_ATOM);
        $target = $job['attempts'] >= 10 ? 'crm:cdc:dead' : 'crm:cdc:retry';
        $queue->rPush($target, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fwrite(STDERR, sprintf("Retry %d falhou: %s\n", $job['attempts'], $e->getMessage()));
        sleep(min(10, max(1, (int) $job['attempts'])));
    }
    $heartbeat();
}

echo "CDC Retry Worker encerrado.\n";
