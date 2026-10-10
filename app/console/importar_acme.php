<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Parsers\AcmePipeParser;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\DetectarLayoutService;
use CRM\Modules\Cargas\Services\NormalizarLinhaAcmeService;
use CRM\Modules\Cargas\Services\ProcessarCargaService;
use CRM\Modules\Cargas\Services\ReceberCargaService;
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

$force = in_array('--force', $argv, true);
$path = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') {
        out('Uso: php app/console/importar_acme.php /caminho/do/arquivo_acme.txt [--force]');
        out('Exemplo dentro do container: php app/console/importar_acme.php /var/www/storage/cargas/inbox/acme/ACMECOB_86_ConsignadoLote7.txt');
        out('Use --force apenas quando quiser reprocessar explicitamente um arquivo cujo hash já existe.');
        exit(0);
    }

    if ($arg === '--force') {
        continue;
    }

    if ($path === null) {
        $path = $arg;
    }
}

if ($path === null) {
    out('Uso: php app/console/importar_acme.php /caminho/do/arquivo_acme.txt [--force]');
    exit(1);
}

$startedAt = microtime(true);
$cargaId = null;
$repository = null;

try {
    $pdo = Database::pdo();
    $repository = new CargaRepository($pdo);
    $parser = new AcmePipeParser();

    $receber = new ReceberCargaService($repository);
    $detectar = new DetectarLayoutService($repository, $parser);
    $processar = new ProcessarCargaService(
        $repository,
        $parser,
        new NormalizarLinhaAcmeService(),
        new ValidadorLinhaAcme(),
    );

    out('1/3 Recebendo arquivo, calculando hash e salvando original no storage...');
    $carga = $receber->receber($path, 'acme');
    $cargaId = $carga['id'];
    out('Carga registrada: #' . $cargaId);
    out('Storage: ' . $carga['caminho_storage']);
    out('SHA-256: ' . $carga['hash_arquivo']);

    if ($carga['duplicado'] && !$force) {
        $repository->updateCargaArquivo($cargaId, [
            'status' => 'cancelado',
            'erro_resumo' => 'Arquivo duplicado. Hash já importado na carga #' . $carga['duplicado_de_id'] . '. Use --force para reprocessar explicitamente.',
            'finalizado_em' => date('Y-m-d H:i:s'),
        ]);

        out('Arquivo duplicado da carga #' . $carga['duplicado_de_id'] . '. Processamento ignorado.');
        out('Use --force somente se quiser reprocessar este arquivo de propósito.');
        exit(0);
    }

    if ($carga['duplicado'] && $force) {
        out('Atenção: arquivo duplicado da carga #' . $carga['duplicado_de_id'] . ', mas --force foi informado. Reprocessando explicitamente.');
    }

    out('2/3 Detectando layout ACME_COBRANCA_PIPE e validando cabeçalho...');
    $layout = $detectar->detectarAcme($cargaId, $carga['caminho_storage']);
    out('Layout confirmado: ACME_COBRANCA_PIPE');

    out('3/3 Processando linhas em streaming e gravando raw + staging...');
    $result = $processar->processarAcme(
        $cargaId,
        $carga['caminho_storage'],
        $layout['credor_id'],
        $layout['carteira_id'],
        $layout['layout_id'],
        $layout['header'],
    );

    $seconds = number_format(microtime(true) - $startedAt, 2, '.', '');
    out('Importação ACME finalizada.');
    out('Status final: ' . $result['status_final']);
    out('Total linhas: ' . $result['total_linhas']);
    out('Raw gravadas: ' . $result['linhas_raw_gravadas']);
    out('Staging: ' . $result['linhas_staging']);
    out('Válidas: ' . $result['linhas_validas']);
    out('Com erro: ' . $result['linhas_com_erro']);
    out('Tempo: ' . $seconds . 's');
    exit($result['status_final'] === 'concluido' ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);

    if ($repository !== null && $cargaId !== null) {
        try {
            $repository->updateCargaArquivo($cargaId, [
                'status' => 'erro',
                'erro_resumo' => $e->getMessage(),
                'finalizado_em' => date('Y-m-d H:i:s'),
            ]);

            $repository->insertErrorsBatch([[ 
                'carga_arquivo_id' => $cargaId,
                'etapa' => 'sistema',
                'codigo_erro' => 'IMPORTADOR_ACME_ERRO_FATAL',
                'mensagem' => $e->getMessage(),
                'severidade' => 'critico',
                'payload_contexto' => [
                    'arquivo' => $path,
                    'trace_resumido' => $e->getFile() . ':' . $e->getLine(),
                ],
            ]]);
        } catch (Throwable) {
            // Não mascara o erro original do importador.
        }
    }

    exit(1);
}
