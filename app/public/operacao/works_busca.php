<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('clientes.consultar');

header('Content-Type: application/json; charset=utf-8');

function worksBuscaBadgeClass(?string $status): string
{
    return match ((string) $status) {
        'pendente', 'formalizado', 'ativo', '(A) - ATIVA', 'ATIVA' => 'adaptor-badge-green',
        'quebrado', 'cancelado', 'incorreto', 'bloqueado' => 'adaptor-badge-red',
        'cumprido', 'correto', 'hot' => 'adaptor-badge-blue',
        default => 'adaptor-badge-gray',
    };
}

function worksBuscaContratoRetiradoCredor(array $contrato): bool
{
    if ((int) ($contrato['retirado_credor'] ?? 0) === 1) {
        return true;
    }

    $status = mb_strtolower(trim((string) ($contrato['status_contrato'] ?? '')));
    $status = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $status) ?: $status;
    return str_contains($status, 'retirado')
        || str_contains($status, 'devolvido')
        || str_contains($status, 'fora da carga')
        || str_contains($status, 'fora_de_carga');
}

function worksBuscaStatusContrato(array $contrato): string
{
    return worksBuscaContratoRetiradoCredor($contrato)
        ? 'Retirado pelo credor'
        : (trim((string) ($contrato['status_contrato'] ?? '')) ?: 'Em Cobrança');
}

try {
    $pdo = operacaoPdo();
    $repository = new AtendimentoAdaptorRepository($pdo);
    $tipoBusca = (string) ($_GET['tipo'] ?? 'auto');
    $termoBusca = trim((string) ($_GET['q'] ?? ''));
    $busca = $repository->normalizarBusca($termoBusca, $tipoBusca);
    $escopoConsulta = usuarioEscopoCredoresAcao('consultar');

    $resultados = [];
    if ($busca['termo'] !== '' && mb_strlen($busca['termo']) >= 2) {
        $resultados = $repository->buscarContratos($termoBusca, $tipoBusca, $escopoConsulta, 500);
    }

    $itens = [];
    foreach ($resultados as $item) {
        $retiradoCredor = worksBuscaContratoRetiradoCredor($item);
        $statusContrato = worksBuscaStatusContrato($item);
        $itens[] = [
            'id' => (int) $item['id'],
            'url' => '/operacao/works.php' . queryString([
                'contrato_id' => (int) $item['id'],
                'q' => $termoBusca,
                'tipo' => $tipoBusca,
                'refresh_api' => 1,
            ]),
            'pessoa_nome' => (string) ($item['pessoa_nome'] ?? ''),
            'status_contrato' => $statusContrato,
            'codigo_contrato' => (string) ($item['codigo_contrato'] ?? ''),
            'saldo_atual' => dinheiro($item['saldo_atual'] ?? null),
            'tem_acordo_ativo' => ((int) ($item['acordos_ativos'] ?? 0)) > 0,
            'badge_class' => $retiradoCredor ? 'adaptor-badge-red' : worksBuscaBadgeClass($item['status_contrato'] ?? null),
            'retirado_credor' => $retiradoCredor,
            'credor_nome' => (string) ($item['credor_nome'] ?? ''),
            'produto' => (string) (($item['produto'] ?? '') ?: '-'),
            'documento' => documentoFormatado((string) ($item['pessoa_documento'] ?? '')),
            'ultimo_atendimento_em' => dataHoraBr($item['ultimo_atendimento_em'] ?? null),
            'total_contatos' => (int) ($item['total_contatos'] ?? 0),
            'total_parcelas' => (int) ($item['total_parcelas'] ?? 0),
            'ultima_carga_data' => dataBr($item['ultima_carga_data_referencia'] ?? null),
            'ultima_carga_escopo_data' => dataBr($item['ultima_carga_escopo_data_referencia'] ?? null),
        ];
    }

    echo json_encode([
        'ok' => true,
        'tipo' => $busca['tipo'],
        'rotulo' => $busca['rotulo'],
        'termo' => $busca['termo'],
        'count' => count($itens),
        'items' => $itens,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
