<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\LimparStagingAntigoService;

require_once __DIR__ . '/_bootstrap.php';

function out(string $m): void
{
    echo $m . PHP_EOL;
}

function av(array $argv, string $name): ?string
{
    $p = $name . '=';
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, $p)) {
            return substr($a, strlen($p));
        }
    }
    return null;
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    out('Uso: php console/limpar_staging_antigo.php [--dias=7] [--batch=5000] [--min-free-mb=4096]');
    out('');
    out('Purga cargas_linhas_raw e stg_carga_canonica de cargas ja CONCLUIDAS');
    out('(status concluido/concluido_com_erros) com mais de N dias, mantendo');
    out('intocado tudo que ainda esta pendente de aplicacao ou dentro da janela');
    out('de retencao. Nunca mexe em dado canonico (contratos/pessoas/acordos).');
    exit(0);
}

$dias = (int) (av($argv, '--dias') ?? (string) (Database::env('LIMPAR_STAGING_RETENCAO_DIAS', '7') ?? '7'));
$batch = (int) (av($argv, '--batch') ?? '5000');
$minFreeMb = (int) (av($argv, '--min-free-mb') ?? '4096');

$pdo = Database::pdo();
$service = new LimparStagingAntigoService(new CargaRepository($pdo), $pdo);

out('Iniciando limpeza de staging antigo (retencao: ' . $dias . ' dia(s))...');
$inicio = microtime(true);

try {
    $resultado = $service->executar($dias, $batch, $minFreeMb, static function (string $etapa, int $total, int $lote, int $espacoLivreMb): void {
        out('  ' . $etapa . ': ' . $total . ' removidas ate agora (+' . $lote . '), ' . $espacoLivreMb . 'MB livres.');
    });
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$duracao = round(microtime(true) - $inicio, 1);
out('Concluido em ' . $duracao . 's.');
out('Cargas elegiveis (mais antigas que o corte): ' . $resultado['cargas_elegiveis']);
out('Staging removido: ' . $resultado['staging_removido']);
out('Raw removido: ' . $resultado['raw_removido']);
out('Corte de data: ' . $resultado['cutoff']);
out('Espaco livre final: ' . $resultado['espaco_livre_mb_final'] . 'MB');
