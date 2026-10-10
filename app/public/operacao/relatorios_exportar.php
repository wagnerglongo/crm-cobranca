<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\RelatorioOperacionalRepository;
use CRM\Modules\Operacao\Services\CsvStreamExporter;
use CRM\Modules\Operacao\Services\RelatorioCsvExporter;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('relatorios.visualizar');

$downloadToken = isset($_GET['_download_token']) ? (string) $_GET['_download_token'] : null;

try {
    $tipo = trim((string) ($_GET['tipo'] ?? ''));
    $filtros = [
        'data_inicio' => $_GET['data_inicio'] ?? null,
        'data_fim' => $_GET['data_fim'] ?? null,
        'credor_id' => $_GET['credor_id'] ?? null,
    ];

    $escopo = usuarioEscopoCredoresAcao('consultar');
    registrarAuditoriaSistema('relatorios.csv.exportado', 'relatorio', $tipo, 'Exportação CSV solicitada.', null, $filtros);

    // Uma exportação grande não deve manter o lock da sessão e bloquear o Pactua
    // do mesmo usuário enquanto o navegador recebe o arquivo.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @set_time_limit(900);

    $repository = new RelatorioOperacionalRepository(operacaoPdo());
    $exporter = new RelatorioCsvExporter($repository);
    $exporter->enviar($tipo, $filtros, $escopo, $downloadToken);
} catch (Throwable $e) {
    (new CsvStreamExporter())->sinalizarDownload($downloadToken, 'error');
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Não foi possível gerar o relatório: ' . $e->getMessage();
    exit;
}
