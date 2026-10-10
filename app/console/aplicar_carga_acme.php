<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\AplicarCargaAcmeService;

require_once __DIR__ . '/../src/Config/Database.php';

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CRM\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

function out(string $message): void
{
    echo $message . PHP_EOL;
}

function argValue(array $argv, string $name): ?string
{
    $prefix = $name . '=';
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === $name) {
            return '';
        }

        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }

    return null;
}

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    out('Uso: php console/aplicar_carga_acme.php --carga=ID [--batch=500]');
    out('Exemplo dentro do container: php console/aplicar_carga_acme.php --carga=12');
    out('Aplica somente linhas de stg_carga_canonica com status=valido e cargas_arquivos.status=concluido.');
    exit(0);
}

$cargaArg = argValue($argv, '--carga');
if ($cargaArg === null || $cargaArg === '' || !ctype_digit($cargaArg)) {
    fwrite(STDERR, 'Informe a carga com --carga=ID. Exemplo: php console/aplicar_carga_acme.php --carga=12' . PHP_EOL);
    exit(1);
}

$batchArg = argValue($argv, '--batch');
$batchSize = $batchArg !== null && ctype_digit($batchArg) ? (int) $batchArg : 500;
$cargaId = (int) $cargaArg;
$startedAt = microtime(true);
$repository = null;

try {
    $pdo = Database::pdo();
    $repository = new CargaRepository($pdo);
    $service = new AplicarCargaAcmeService($repository);

    out('Aplicando carga ACME #' . $cargaId . '...');
    $result = $service->aplicar($cargaId, $batchSize);

    $seconds = number_format(microtime(true) - $startedAt, 2, '.', '');
    out('Staging válidos encontrados: ' . $result['staging_validos_encontrados']);
    out('Linhas aplicadas: ' . $result['linhas_aplicadas']);
    out('Pessoas criadas: ' . $result['pessoas_criadas']);
    out('Pessoas atualizadas: ' . $result['pessoas_atualizadas']);
    out('Contratos criados: ' . $result['contratos_criados']);
    out('Contratos atualizados: ' . $result['contratos_atualizados']);
    out('Contatos criados: ' . $result['contatos_criados']);
    out('Contatos atualizados: ' . $result['contatos_atualizados']);
    out('Endereços criados: ' . $result['enderecos_criados']);
    out('Endereços atualizados: ' . $result['enderecos_atualizados']);
    out('Veículos criados: ' . $result['veiculos_criados']);
    out('Veículos atualizados: ' . $result['veiculos_atualizados']);
    out('Erros: ' . $result['erros']);
    out('Status: ' . $result['status']);
    out('Tempo: ' . $seconds . 's');

    exit(in_array($result['status'], ['aplicado', 'sem_linhas_validas'], true) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);

    if ($repository !== null) {
        try {
            $repository->insertErrorsBatch([[ 
                'carga_arquivo_id' => $cargaId,
                'etapa' => 'aplicacao',
                'codigo_erro' => 'ACME_MERGE_ERRO_FATAL',
                'mensagem' => $e->getMessage(),
                'severidade' => 'critico',
                'payload_contexto' => [
                    'carga_id' => $cargaId,
                    'trace_resumido' => $e->getFile() . ':' . $e->getLine(),
                ],
            ]]);
        } catch (Throwable) {
            // Não mascara o erro original.
        }
    }

    exit(1);
}
