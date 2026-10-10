<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Alertas\AlertaEmailService;
use CRM\Modules\Cargas\Parsers\AcmePipeParser;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\DetectarLayoutService;
use CRM\Modules\Cargas\Services\NormalizarLinhaAcmeService;
use CRM\Modules\Cargas\Services\ProcessarAcmeDiarioService;
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

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

/** @return array<string,mixed> */
function parseOptions(array $argv): array
{
    $options = [
        'max' => 50,
        'batch' => 1000,
        'dry_run' => false,
        'sem_importar' => false,
        'sem_aplicar' => false,
        'usar_lock' => true,
        'parar_no_erro' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if ($arg === '--sem-importar') {
            $options['sem_importar'] = true;
            continue;
        }

        if ($arg === '--sem-aplicar') {
            $options['sem_aplicar'] = true;
            continue;
        }

        if ($arg === '--lock') {
            $options['usar_lock'] = true;
            continue;
        }

        if ($arg === '--sem-lock') {
            $options['usar_lock'] = false;
            continue;
        }

        if ($arg === '--parar-no-erro') {
            $options['parar_no_erro'] = true;
            continue;
        }

        if (str_starts_with($arg, '--max=')) {
            $value = substr($arg, strlen('--max='));
            if (!ctype_digit($value) || (int) $value < 1) {
                throw new InvalidArgumentException('--max precisa ser inteiro maior que zero.');
            }
            $options['max'] = (int) $value;
            continue;
        }

        if (str_starts_with($arg, '--batch=')) {
            $value = substr($arg, strlen('--batch='));
            if (!ctype_digit($value) || (int) $value < 1) {
                throw new InvalidArgumentException('--batch precisa ser inteiro maior que zero.');
            }
            $options['batch'] = (int) $value;
            continue;
        }

        throw new InvalidArgumentException('Opção inválida: ' . $arg);
    }

    return $options;
}

function printHelp(): void
{
    out('Uso: php console/processar_acme_diario.php [--max=50] [--batch=1000] [--dry-run] [--sem-importar] [--sem-aplicar] [--lock] [--sem-lock] [--parar-no-erro]');
    out('');
    out('Exemplos:');
    out('  php console/processar_acme_diario.php --max=50 --batch=1000 --dry-run');
    out('  php console/processar_acme_diario.php --max=50 --batch=1000');
    out('  php console/processar_acme_diario.php --sem-importar --batch=1000');
    out('  php console/processar_acme_diario.php --sem-aplicar --max=50');
    out('');
    out('O comando executa o ciclo diário ACME: scanner/importação de inbox + aplicação das cargas válidas pendentes.');
    out('O lock MySQL GET_LOCK fica ativo por padrão para impedir duas execuções simultâneas. Use --sem-lock somente para debug controlado.');
}

/** @param array<string,mixed> $result */
function imprimirResultado(array $result): void
{
    $importacao = $result['importacao'];
    $aplicacao = $result['aplicacao'];

    out('============================================================');
    out('PROCESSAMENTO DIÁRIO ACME');
    out('============================================================');
    out('Modo dry-run: ' . ($result['dry_run'] ? 'sim' : 'não'));
    out('Max arquivos/cargas: ' . $result['max']);
    out('Batch por carga: ' . $result['batch']);
    out('Lock: ' . (($result['lock']['habilitado'] ?? false) ? 'ativo' : 'desativado'));
    out('');

    if (($result['status_final'] ?? '') === 'bloqueado') {
        out('Status final: bloqueado');
        out((string) ($result['mensagem'] ?? 'Já existe outro processamento em execução.'));
        out('Tempo total: ' . number_format((float) $result['tempo_total'], 2, '.', '') . 's');
        return;
    }

    out('Etapa 1/2 - Importando arquivos pendentes...');
    if (($importacao['pulada'] ?? false) === true) {
        out('Importação pulada por --sem-importar.');
    } else {
        out('Inbox base: ' . (string) ($importacao['inbox_base'] ?? '-'));
        out('Arquivos encontrados: ' . $importacao['arquivos_encontrados']);
        out('Arquivos importados: ' . $importacao['arquivos_importados']);
        out('Arquivos duplicados: ' . $importacao['arquivos_duplicados']);
        out('Arquivos com erro: ' . $importacao['arquivos_com_erro']);
        out('Arquivos ignorados: ' . $importacao['arquivos_ignorados']);

        foreach (($importacao['arquivos'] ?? []) as $item) {
            $line = '[' . strtoupper((string) ($item['status'] ?? 'indefinido')) . '] ' . (string) ($item['arquivo'] ?? $item['path'] ?? $item['credor'] ?? '-');
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
    }

    out('');
    out('Etapa 2/2 - Aplicando cargas pendentes...');
    if (($aplicacao['pulada'] ?? false) === true) {
        out('Aplicação pulada por --sem-aplicar.');
    } else {
        out('Cargas encontradas: ' . $aplicacao['cargas_encontradas']);

        if ($result['dry_run']) {
            out('Modo dry-run: nenhuma carga será aplicada.');
            foreach (($aplicacao['cargas'] ?? []) as $carga) {
                out(sprintf(
                    '#%d | %s | staging_validos=%d | linhas_validas=%d',
                    (int) $carga['id'],
                    (string) $carga['nome_original'],
                    (int) $carga['staging_validos'],
                    (int) $carga['linhas_validas']
                ));
            }
        } else {
            foreach (($aplicacao['resultados'] ?? []) as $item) {
                $line = sprintf(
                    '#%d | %s | status=%s | linhas=%d | erros=%d | tempo=%ss',
                    (int) ($item['carga_id'] ?? 0),
                    (string) ($item['nome_original'] ?? ''),
                    (string) ($item['status'] ?? 'indefinido'),
                    (int) ($item['linhas_aplicadas'] ?? 0),
                    (int) ($item['erros'] ?? 0),
                    number_format((float) ($item['tempo'] ?? 0), 2, '.', '')
                );
                if (isset($item['erro'])) {
                    $line .= ' | ' . $item['erro'];
                }
                out($line);
            }
        }

        out('Cargas processadas: ' . $aplicacao['cargas_processadas']);
        out('Cargas aplicadas com sucesso: ' . $aplicacao['cargas_aplicadas_com_sucesso']);
        out('Cargas com erro: ' . $aplicacao['cargas_com_erro']);
        out('Linhas aplicadas: ' . $aplicacao['linhas_aplicadas']);
        out('Pessoas criadas: ' . $aplicacao['pessoas_criadas']);
        out('Pessoas atualizadas: ' . $aplicacao['pessoas_atualizadas']);
        out('Contratos criados: ' . $aplicacao['contratos_criados']);
        out('Contratos atualizados: ' . $aplicacao['contratos_atualizados']);
        out('Contatos criados: ' . $aplicacao['contatos_criados']);
        out('Contatos atualizados: ' . $aplicacao['contatos_atualizados']);
        out('Endereços criados: ' . $aplicacao['enderecos_criados']);
        out('Endereços atualizados: ' . $aplicacao['enderecos_atualizados']);
        out('Veículos criados: ' . $aplicacao['veiculos_criados']);
        out('Veículos atualizados: ' . $aplicacao['veiculos_atualizados']);
        out('Erros: ' . $aplicacao['erros']);
    }

    out('');
    out('Status final: ' . $result['status_final']);
    out('Tempo total: ' . number_format((float) $result['tempo_total'], 2, '.', '') . 's');
}

try {
    $options = parseOptions($argv);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!empty($options['help'])) {
    printHelp();
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

    $service = new ProcessarAcmeDiarioService($repository, $scanner);
    $result = $service->executar($options);
    imprimirResultado($result);

    if (empty($options['dry_run']) && $result['status_final'] === 'concluido_com_erros') {
        AlertaEmailService::alertarSeConfigurado(
            '[CRM Pactua] Processamento diário ACME concluído com erros',
            "O ciclo diário ACME terminou com erros e precisa de atenção.\n\n"
            . 'Importação - arquivos com erro: ' . ($result['importacao']['arquivos_com_erro'] ?? 0) . "\n"
            . 'Aplicação - cargas com erro: ' . ($result['aplicacao']['cargas_com_erro'] ?? 0) . "\n\n"
            . 'Verifique os detalhes com: php console/processar_acme_diario.php --dry-run',
        );
    }

    exit(in_array($result['status_final'], ['concluido', 'bloqueado'], true) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    AlertaEmailService::alertarSeConfigurado(
        '[CRM Pactua] Processamento diário ACME falhou',
        "O ciclo diário ACME foi interrompido por um erro fatal e não terminou.\n\n"
        . 'Erro: ' . $e->getMessage(),
    );
    exit(1);
}
