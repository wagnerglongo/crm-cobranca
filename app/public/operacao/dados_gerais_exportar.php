<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\DadosGeraisRepository;
use CRM\Modules\Operacao\Services\CsvStreamExporter;
use CRM\Modules\Operacao\Services\DadosGeraisFiltroService;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('relatorios.visualizar');

$downloadToken = isset($_POST['_download_token']) ? (string) $_POST['_download_token'] : null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Use o construtor de Dados Gerais para gerar o CSV.');
    }

    validarCsrf();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    @set_time_limit(900);

    $filtroService = new DadosGeraisFiltroService();
    $filtros = $filtroService->normalizar($_POST, true);
    $formato = $filtroService->formato($_POST);
    $escopo = usuarioEscopoCredoresAcao('consultar');

    registrarAuditoriaSistema(
        'dados_gerais.csv.exportado',
        'dados_gerais',
        $formato,
        'Exportacao CSV da base geral solicitada.',
        null,
        [
            'formato' => $formato,
            'situacao_base' => $filtros['situacao_base'] ?? 'ativos',
            'segmentacao_ativa' => (bool) ($filtros['_segmentacao_ativa'] ?? false),
            'filtros' => array_diff_key($filtros, ['_segmentacao_ativa' => true]),
        ]
    );

    unset($filtros['_segmentacao_ativa']);
    $repository = new DadosGeraisRepository(operacaoPdo());
    $prepared = $repository->prepararExportacao($filtros, $escopo, $formato);
    (new CsvStreamExporter())->enviar($prepared, $downloadToken);
} catch (Throwable $e) {
    (new CsvStreamExporter())->sinalizarDownload($downloadToken, 'error');
    http_response_code(400);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo 'Nao foi possivel gerar o CSV de Dados Gerais: ' . $e->getMessage();
    exit;
}
