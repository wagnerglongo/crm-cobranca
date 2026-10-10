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

function hasFlag(array $argv, string $name): bool
{
    return in_array($name, array_slice($argv, 1), true);
}

/**
 * @return list<array<string,mixed>>
 */
function buscarCargasAcmePendentes(PDO $pdo, int $max): array
{
    $sql = "SELECT
            ca.id,
            ca.nome_original,
            ca.status AS status_carga,
            ca.total_linhas,
            ca.linhas_validas,
            COUNT(stg.id) AS staging_validos
        FROM cargas_arquivos ca
        INNER JOIN credores cr ON cr.id = ca.credor_id
        INNER JOIN carga_layouts cl ON cl.id = ca.layout_id
        INNER JOIN stg_carga_canonica stg ON stg.carga_arquivo_id = ca.id
        WHERE ca.status = 'concluido'
          AND cr.codigo = 'ACME'
          AND cl.codigo = 'ACME_COBRANCA_PIPE'
          AND stg.status = 'valido'
        GROUP BY ca.id, ca.nome_original, ca.status, ca.total_linhas, ca.linhas_validas
        ORDER BY ca.id ASC";

    if ($max > 0) {
        $sql .= ' LIMIT :max_rows';
    }

    $stmt = $pdo->prepare($sql);
    if ($max > 0) {
        $stmt->bindValue('max_rows', $max, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    out('Uso: php console/aplicar_cargas_acme_pendentes.php [--max=0] [--batch=500] [--dry-run] [--parar-no-erro]');
    out('');
    out('Exemplos:');
    out('  php console/aplicar_cargas_acme_pendentes.php --dry-run');
    out('  php console/aplicar_cargas_acme_pendentes.php --max=1');
    out('  php console/aplicar_cargas_acme_pendentes.php --batch=1000');
    out('');
    out('O script busca sozinho cargas ACME com cargas_arquivos.status=concluido e stg_carga_canonica.status=valido.');
    out('Cargas canceladas, com erro ou já aplicadas ficam automaticamente fora.');
    exit(0);
}

$maxArg = argValue($argv, '--max');
$batchArg = argValue($argv, '--batch');
$dryRun = hasFlag($argv, '--dry-run');
$pararNoErro = hasFlag($argv, '--parar-no-erro');

$max = $maxArg !== null && ctype_digit($maxArg) ? (int) $maxArg : 0;
$batchSize = $batchArg !== null && ctype_digit($batchArg) ? (int) $batchArg : 500;
$batchSize = max(1, min($batchSize, 5000));

$startedAt = microtime(true);
$pdo = Database::pdo();
$cargas = buscarCargasAcmePendentes($pdo, $max);

out('Buscando cargas ACME pendentes de aplicação...');
out('Cargas encontradas: ' . count($cargas));
out('Batch por carga: ' . $batchSize);

if ($dryRun) {
    out('Modo dry-run: nenhuma carga será aplicada.');
}

if ($cargas === []) {
    out('Nenhuma carga ACME pendente encontrada.');
    exit(0);
}

foreach ($cargas as $carga) {
    out(sprintf(
        '#%d | %s | staging_validos=%d | linhas_validas=%d',
        (int) $carga['id'],
        (string) $carga['nome_original'],
        (int) $carga['staging_validos'],
        (int) $carga['linhas_validas']
    ));
}

if ($dryRun) {
    exit(0);
}

$totais = [
    'cargas_processadas' => 0,
    'cargas_com_sucesso' => 0,
    'cargas_com_erro' => 0,
    'linhas_aplicadas' => 0,
    'pessoas_criadas' => 0,
    'pessoas_atualizadas' => 0,
    'contratos_criados' => 0,
    'contratos_atualizados' => 0,
    'contatos_criados' => 0,
    'contatos_atualizados' => 0,
    'enderecos_criados' => 0,
    'enderecos_atualizados' => 0,
    'veiculos_criados' => 0,
    'veiculos_atualizados' => 0,
    'erros' => 0,
];

foreach ($cargas as $carga) {
    $cargaId = (int) $carga['id'];
    $inicioCarga = microtime(true);

    out('');
    out('============================================================');
    out('Aplicando carga ACME #' . $cargaId . ' - ' . (string) $carga['nome_original']);
    out('============================================================');

    try {
        // Uma nova instância por carga evita acumular contadores internos entre cargas diferentes.
        $repository = new CargaRepository($pdo);
        $service = new AplicarCargaAcmeService($repository);
        $result = $service->aplicar($cargaId, $batchSize);

        $totais['cargas_processadas']++;
        $totais['linhas_aplicadas'] += (int) $result['linhas_aplicadas'];
        $totais['pessoas_criadas'] += (int) $result['pessoas_criadas'];
        $totais['pessoas_atualizadas'] += (int) $result['pessoas_atualizadas'];
        $totais['contratos_criados'] += (int) $result['contratos_criados'];
        $totais['contratos_atualizados'] += (int) $result['contratos_atualizados'];
        $totais['contatos_criados'] += (int) $result['contatos_criados'];
        $totais['contatos_atualizados'] += (int) $result['contatos_atualizados'];
        $totais['enderecos_criados'] += (int) $result['enderecos_criados'];
        $totais['enderecos_atualizados'] += (int) $result['enderecos_atualizados'];
        $totais['veiculos_criados'] += (int) $result['veiculos_criados'];
        $totais['veiculos_atualizados'] += (int) $result['veiculos_atualizados'];
        $totais['erros'] += (int) $result['erros'];

        if (in_array($result['status'], ['aplicado', 'sem_linhas_validas'], true)) {
            $totais['cargas_com_sucesso']++;
        } else {
            $totais['cargas_com_erro']++;
        }

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
        out('Tempo carga: ' . number_format(microtime(true) - $inicioCarga, 2, '.', '') . 's');

        if (!in_array($result['status'], ['aplicado', 'sem_linhas_validas'], true) && $pararNoErro) {
            out('Interrompendo porque --parar-no-erro foi informado.');
            break;
        }
    } catch (Throwable $e) {
        $totais['cargas_processadas']++;
        $totais['cargas_com_erro']++;
        $totais['erros']++;

        fwrite(STDERR, 'ERRO na carga #' . $cargaId . ': ' . $e->getMessage() . PHP_EOL);

        if ($pararNoErro) {
            out('Interrompendo porque --parar-no-erro foi informado.');
            break;
        }
    }
}

out('');
out('==================== RESUMO FINAL ====================');
out('Cargas processadas: ' . $totais['cargas_processadas']);
out('Cargas com sucesso: ' . $totais['cargas_com_sucesso']);
out('Cargas com erro: ' . $totais['cargas_com_erro']);
out('Linhas aplicadas: ' . $totais['linhas_aplicadas']);
out('Pessoas criadas: ' . $totais['pessoas_criadas']);
out('Pessoas atualizadas: ' . $totais['pessoas_atualizadas']);
out('Contratos criados: ' . $totais['contratos_criados']);
out('Contratos atualizados: ' . $totais['contratos_atualizados']);
out('Contatos criados: ' . $totais['contatos_criados']);
out('Contatos atualizados: ' . $totais['contatos_atualizados']);
out('Endereços criados: ' . $totais['enderecos_criados']);
out('Endereços atualizados: ' . $totais['enderecos_atualizados']);
out('Veículos criados: ' . $totais['veiculos_criados']);
out('Veículos atualizados: ' . $totais['veiculos_atualizados']);
out('Erros: ' . $totais['erros']);
out('Tempo total: ' . number_format(microtime(true) - $startedAt, 2, '.', '') . 's');

exit($totais['cargas_com_erro'] > 0 ? 2 : 0);
