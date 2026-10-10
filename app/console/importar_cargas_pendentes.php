<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Parsers\AcmePipeParser;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\DetectarLayoutService;
use CRM\Modules\Cargas\Services\NormalizarLinhaAcmeService;
use CRM\Modules\Cargas\Services\ProcessarCargaService;
use CRM\Modules\Cargas\Services\ReceberCargaService;
use CRM\Modules\Cargas\Services\ScannerCargasPendentesService;
use CRM\Modules\Cargas\Validators\ValidadorLinhaAcme;

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

function parseOptions(array $argv): array
{
    $options = [
        'credor' => 'acme',
        'max_files' => 50,
        'force' => false,
        'dry_run' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--force') {
            $options['force'] = true;
            continue;
        }

        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if (str_starts_with($arg, '--credor=')) {
            $options['credor'] = strtolower(trim(substr($arg, strlen('--credor='))));
            continue;
        }

        if (str_starts_with($arg, '--max=')) {
            $options['max_files'] = max(1, (int) substr($arg, strlen('--max=')));
            continue;
        }

        throw new InvalidArgumentException('Opção inválida: ' . $arg);
    }

    return $options;
}

try {
    $options = parseOptions($argv);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!empty($options['help'])) {
    out('Uso: php console/importar_cargas_pendentes.php [--credor=acme] [--max=50] [--dry-run] [--force]');
    out('Inbox padrão: STORAGE_CARGAS_PATH/inbox/acme');
    out('Exemplo no container: php console/importar_cargas_pendentes.php --credor=acme --max=10');
    out('Use --force apenas para reprocessar explicitamente um arquivo com hash já importado.');
    exit(0);
}

try {
    $pdo = Database::pdo();
    $repository = new CargaRepository($pdo);
    $parser = new AcmePipeParser();

    $scanner = new ScannerCargasPendentesService(
        $repository,
        new ReceberCargaService($repository),
        new DetectarLayoutService($repository, $parser),
        new ProcessarCargaService(
            $repository,
            $parser,
            new NormalizarLinhaAcmeService(),
            new ValidadorLinhaAcme(),
        ),
    );

    out('Scanner de cargas pendentes iniciado.');
    out('Credor: ' . $options['credor'] . ' | Max arquivos: ' . $options['max_files'] . ' | Force: ' . ($options['force'] ? 'sim' : 'não') . ' | Dry-run: ' . ($options['dry_run'] ? 'sim' : 'não'));

    $result = $scanner->executar($options);

    out('Inbox base: ' . $result['inbox_base']);
    foreach ($result['arquivos'] as $item) {
        $line = '[' . strtoupper((string) ($item['status'] ?? 'indefinido')) . '] ' . ($item['arquivo'] ?? $item['path'] ?? $item['credor'] ?? '-');
        if (isset($item['carga_id'])) {
            $line .= ' | carga #' . $item['carga_id'];
        }
        if (isset($item['mensagem'])) {
            $line .= ' | ' . $item['mensagem'];
        }
        if (isset($item['total_linhas'])) {
            $line .= ' | linhas=' . $item['total_linhas'] . ' validas=' . $item['linhas_validas'] . ' erros=' . $item['linhas_com_erro'];
        }
        out($line);
    }

    out('Resumo: processados=' . $result['processados'] . ' duplicados=' . $result['duplicados'] . ' erros=' . $result['erros'] . ' ignorados=' . $result['ignorados']);

    exit($result['erros'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
