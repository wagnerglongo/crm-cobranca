<?php

declare(strict_types=1);

$worksRequestStartedAt = microtime(true);

use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Repositories\EstrategiaRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use CRM\Modules\Operacao\Repositories\MotivoInadimplenciaRepository;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;
use CRM\Modules\Operacao\Controllers\WorksController;
use CRM\Modules\Operacao\Services\WorksQueryService;
use CRM\Modules\Operacao\Services\WorksTabPolicy;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('clientes.consultar');

$pageTitle = 'Pactua - Call Center';
$activePage = 'works_atendimento';

$pdo = operacaoPdo();
$repository = new AtendimentoAdaptorRepository($pdo);
$atendimentoRepository = new AtendimentoRepository($pdo);
$consultaRepository = new ConsultaClienteRepository($pdo);
$motivosInadimplencia = (new MotivoInadimplenciaRepository($pdo))->listarAtivos();
$worksUrl = '/operacao/works.php';
$acordoRepository = new AcordoOperacionalRepository($pdo);
$credorConfiguracaoRepository = new CredorConfiguracaoRepository($pdo);
$estrategiaRepository = new EstrategiaRepository($pdo);
$credorAdapterFactory = new CredorAdapterFactory($pdo, $credorConfiguracaoRepository);
$escopoConsulta = usuarioEscopoCredoresAcao('consultar');
$escopoOperacao = usuarioEscopoCredoresAcao('operar');



require_once __DIR__ . '/includes/works_helpers.php';

$podeAtribuirAcordo = worksPodeAtribuirAcordo();
$usuariosAtribuiveisAcordo = $podeAtribuirAcordo ? worksUsuariosAtribuiveis($pdo) : [];
$usuarioAtualIdAcordo = usuarioAtualId() ?? 0;
$usuarioAtualNomeAcordo = usuarioAtualNome() ?: 'Operador';
if ($usuariosAtribuiveisAcordo === [] && $usuarioAtualIdAcordo > 0) {
    $usuariosAtribuiveisAcordo = [[
        'id' => $usuarioAtualIdAcordo,
        'nome' => $usuarioAtualNomeAcordo,
        'email' => '',
    ]];
}

$podeEditarLocalizacao = usuarioEhAdminOperacao()
    || usuarioPode('contatos.alterar')
    || usuarioPode('contatos.editar')
    || usuarioPode('clientes.editar')
    || usuarioPode('clientes.gerenciar');
$podeCopiarBoleto = usuarioEhAdminOperacao() || usuarioPode('boletos.copiar') || usuarioPode('acordos.visualizar');
$podeBaixarBoleto = usuarioEhAdminOperacao() || usuarioPode('boletos.baixar') || usuarioPode('acordos.visualizar');

$tiposBusca = AtendimentoAdaptorRepository::TIPOS_BUSCA;
$tipoBusca = (string) ($_GET['tipo'] ?? 'auto');
$termoBusca = trim((string) ($_GET['q'] ?? ''));
$buscaNormalizada = $repository->normalizarBusca($termoBusca, $tipoBusca);
$contratoId = filter_var($_GET['contrato_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$pessoaIdBusca = filter_var($_GET['pessoa_id'] ?? ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$estrategiaItemId = filter_var($_POST['estrategia_item_id'] ?? $_GET['estrategia_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$tabPolicy = new WorksTabPolicy();
$tab = $tabPolicy->normalizar((string) ($_GET['tab'] ?? ($contratoId > 0 ? 'acionamento' : 'busca')), $contratoId > 0);

$erro = null;
$sucesso = (string) ($_GET['ok'] ?? '');
$resultados = [];
$contrato = null;
$contatos = [];
$enderecos = [];
$historico = [];
$parcelas = [];
$resumoParcelas = ['total_parcelas' => 0, 'total_saldo_parcelas' => 0.0, 'total_original' => 0.0, 'primeiro_vencimento' => null, 'ultimo_vencimento' => null];
$acordos = [];
$parcelasAcordo = [];
$historicoFinanceiro = [];
$resultadosAtendimento = [];
$estrategiaItem = null;
$estrategiaContextoErro = null;
$estrategiaSincronizacaoAviso = (string) ($_GET['estrategia_sync'] ?? '') === 'erro';
$atrasoContrato = ['base' => null, 'atual' => null, 'data_base' => null, 'dias_projetados' => 0, 'fonte' => 'base', 'label' => '-', 'nota' => ''];

require __DIR__ . '/controllers/works_actions.php';

$worksQueryService = new WorksQueryService(
    $repository,
    $atendimentoRepository,
    $acordoRepository,
    $estrategiaRepository
);
$worksController = new WorksController($worksQueryService);
$worksQueryTimingsMs = [];

try {
    $viewModel = $worksController->carregar(
        $contratoId,
        $pessoaIdBusca,
        $termoBusca,
        $tipoBusca,
        $tab,
        $escopoConsulta,
        $escopoOperacao,
        $estrategiaItemId,
        usuarioAtualId() ?? 0,
    );
    foreach ($viewModel->toArray() as $nome => $valor) {
        ${$nome} = $valor;
    }
    if ($contrato !== null) {
        requireContratoNoEscopo((int) $contrato['id'], 'consultar');
    } elseif ($contratoId > 0) {
        $erro ??= 'Contrato não encontrado ou fora do seu escopo.';
    }
} catch (Throwable $e) {
    $erro ??= $e->getMessage();
}

if ($tab === 'financeiro' && $contrato !== null) {
    $historicoFinanceiro = worksMontarHistoricoFinanceiro($contrato, $acordos, $parcelasAcordo);
}

$acordoAtivo = null;
foreach ($acordos as $acordoItem) {
    if (in_array((string) ($acordoItem['status_operacional'] ?? ''), ['pendente','formalizado'], true)) {
        $acordoAtivo = $acordoItem;
        break;
    }
}

if ($contrato !== null && $resultadosAtendimento !== []) {
    $resultadosAtendimento = worksFiltrarResultadosContexto(
        $resultadosAtendimento,
        $contrato,
        $acordoAtivo !== null,
        $credorConfiguracaoRepository
    );
}

// Regra de negócio (2026-09-23): contato negativado não deve aparecer como
// opção pra acionamento nem pra fechar acordo. $contatos continua com a
// lista completa (a aba Localização precisa mostrar negativado também),
// só o que alimenta os seletores de "contato usado" fica restrito.
$contatosAcionaveis = array_values(array_filter($contatos, static fn (array $contato): bool => !adaptorContatoNegativado($contato)));
$contatoPrincipal = $contatosAcionaveis[0] ?? null;
$valorBaseAcordo = (float) ($contrato['saldo_atual'] ?? $resumoParcelas['total_saldo_parcelas'] ?? 0);
$valorBaseAcordo = $valorBaseAcordo > 0 ? $valorBaseAcordo : (float) ($resumoParcelas['total_saldo_parcelas'] ?? 0);
$financeiroPerfil = $contrato !== null ? adaptorFinanceiroPerfil($contrato, $credorConfiguracaoRepository) : 'padrao';
$contratoRetiradoCredor = $contrato !== null && adaptorContratoRetiradoCredor($contrato);
$atrasoContrato = $contrato !== null ? adaptorAtrasoContrato($contrato) : $atrasoContrato;
$deveAtualizarApiAcme = $contrato !== null
    && $financeiroPerfil === 'acme'
    && !$contratoRetiradoCredor
    && $tab === 'financeiro';
$forcarValidacaoAcordoAcme = (string) ($_GET['refresh_api'] ?? '0') === '1';
$financeiroApiEndpoint = $contrato !== null && $financeiroPerfil === 'acme' && !$contratoRetiradoCredor
    ? '/operacao/works_financeiro_acme.php' . queryString([
        'contrato_id' => (int) $contrato['id'],
        'refresh' => $deveAtualizarApiAcme ? 1 : 0,
        'force_agreement' => $forcarValidacaoAcordoAcme ? 1 : 0,
    ])
    : '';
$acionamentosAcmeEndpoint = $contrato !== null && $financeiroPerfil === 'acme'
    ? '/operacao/works_acionamentos_acme.php' . queryString(['contrato_id' => (int) $contrato['id']])
    : '';
$localizacaoAcmeEndpoint = $contrato !== null && $financeiroPerfil === 'acme'
    ? '/operacao/works_localizacao_acme.php' . queryString(['contrato_id' => (int) $contrato['id']])
    : '';
$emailsFinanceiro = array_values(array_filter($contatosAcionaveis, static fn (array $contato): bool => (string) ($contato['tipo'] ?? '') === 'email'));
$telefonesFinanceiro = array_values(array_filter($contatosAcionaveis, static fn (array $contato): bool => in_array((string) ($contato['tipo'] ?? ''), ['telefone','whatsapp'], true)));
$parcelasOriginais = $parcelas;
if ($parcelasOriginais === [] && $contrato !== null) {
    $parcelasOriginais[] = [
        'numero_parcela' => '1',
        'saldo_parcela' => $contrato['saldo_atual'] ?? $valorBaseAcordo,
        'data_vencimento' => $contrato['data_vencimento_inicial'] ?? ($resumoParcelas['ultimo_vencimento'] ?? ($contrato['data_corte'] ?? null)),
        'valor_original' => $contrato['saldo_atual'] ?? $valorBaseAcordo,
        'status_parcela' => $contrato['status_contrato'] ?? 'em_cobranca',
        'fallback_base_contrato' => true,
    ];
}

http_response_code($erro === null ? 200 : 500);
if (!headers_sent()) {
    
    $timingParts = ['works;dur=' . number_format((microtime(true) - $worksRequestStartedAt) * 1000, 1, '.', '')];
    foreach ($worksQueryTimingsMs as $timingName => $timingMs) {
        $safeName = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $timingName) ?: 'query';
        $timingParts[] = 'works-' . $safeName . ';dur=' . number_format((float) $timingMs, 1, '.', '');
    }
    header('Server-Timing: ' . implode(', ', $timingParts));
}

require __DIR__ . '/../../resources/views/works/page.php';
