<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\EstrategiaRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('estrategias.gerenciar');

$pageTitle = 'Supervisão · Estratégias';
$pageSubtitle = 'Selecione, priorize e distribua contratos para a operação com prévia e auditoria.';
$activePage = 'estrategias_supervisao';

$defaults = [
    'estrategia_id' => '',
    'nome' => '',
    'descricao' => '',
    'status' => 'ativa',
    'prioridade' => '100',
    'limite_contratos' => '5000',
    'visibilidade' => 'todos',
    'ordem_1' => 'aging',
    'direcao_1' => 'DESC',
    'ordem_2' => 'valor_faltante',
    'direcao_2' => 'DESC',
    'dias_sem_incluir_nunca' => '1',
    'usuario_ids' => [],
];
$form = $defaults;
$preview = null;
$mensagemSucesso = null;
$mensagemErro = null;
$moduloDisponivel = false;
$credores = [];
$carteiras = [];
$produtos = [];
$statusContratos = [];
$resultadosAtendimento = [];
$usuarios = [];
$estrategias = [];
$resumo = ['estrategias_ativas' => 0, 'contratos_pendentes' => 0, 'em_atendimento' => 0, 'trabalhados' => 0, 'saldo_em_estrategias' => 0.0];
$incluirArquivadas = (string) ($_GET['incluir_arquivadas'] ?? '') === '1';
$editarId = isset($_GET['editar']) && ctype_digit((string) $_GET['editar']) ? (int) $_GET['editar'] : 0;

try {
    $repository = new EstrategiaRepository(operacaoPdo());
    $moduloDisponivel = $repository->moduloDisponivel();
    $escopoGerencia = usuarioEscopoCredoresAcao('criar_campanha');

    if ($moduloDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = trim((string) ($_POST['acao'] ?? ''));
        $usuarioAtualId = usuarioAtualId();

        // Consultas e materializacoes grandes nao devem manter o lock da sessao
        // PHP. Assim, outras abas e requisicoes do mesmo supervisor continuam
        // respondendo enquanto esta tela mostra o carregamento.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (in_array($acao, ['previsualizar', 'salvar_gerar', 'regerar', 'status', 'duplicar'], true)) {
            ignore_user_abort(true);
            @set_time_limit(600);
        }

        try {
            if ($acao === 'previsualizar') {
                $form = array_merge($defaults, $_POST);
                $preview = $repository->previsualizar($_POST, $escopoGerencia, 30);
                $mensagemSucesso = 'Prévia atualizada. Nenhum contrato foi materializado nesta etapa.';
            } elseif ($acao === 'salvar_gerar') {
                $_POST['usuario_atual_id'] = $usuarioAtualId;
                $resultado = $repository->salvarEGerar($_POST, $escopoGerencia);
                registrarAuditoriaSistema(
                    $resultado['atualizada'] ? 'estrategia.atualizada' : 'estrategia.criada',
                    'estrategia',
                    (int) $resultado['estrategia_id'],
                    $resultado['atualizada'] ? 'Estratégia atualizada e regenerada.' : 'Estratégia criada e materializada.',
                    null,
                    $resultado
                );
                $mensagemSucesso = sprintf(
                    'Estratégia #%d %s: %s contrato(s) materializado(s), %s conflito(s) ignorado(s).',
                    (int) $resultado['estrategia_id'],
                    $resultado['atualizada'] ? 'atualizada' : 'criada',
                    number_format((int) $resultado['total_materializado'], 0, ',', '.'),
                    number_format((int) $resultado['total_conflitos'], 0, ',', '.')
                );
                $editarId = (int) $resultado['estrategia_id'];
            } elseif ($acao === 'regerar') {
                $id = isset($_POST['estrategia_id']) && ctype_digit((string) $_POST['estrategia_id']) ? (int) $_POST['estrategia_id'] : 0;
                $resultado = $repository->regerar($id, $usuarioAtualId, $escopoGerencia);
                registrarAuditoriaSistema('estrategia.regenerada', 'estrategia', $id, 'Estratégia regenerada manualmente.', null, $resultado);
                $mensagemSucesso = 'Estratégia #' . $id . ' regenerada com ' . number_format((int) $resultado['total_materializado'], 0, ',', '.') . ' contrato(s) elegível(is).';
            } elseif ($acao === 'status') {
                $id = isset($_POST['estrategia_id']) && ctype_digit((string) $_POST['estrategia_id']) ? (int) $_POST['estrategia_id'] : 0;
                $status = trim((string) ($_POST['status'] ?? ''));
                $repository->atualizarStatus($id, $status, $usuarioAtualId, $escopoGerencia);
                registrarAuditoriaSistema('estrategia.status_alterado', 'estrategia', $id, 'Status da estratégia alterado.', null, ['status' => $status]);
                $mensagemSucesso = 'Status da estratégia #' . $id . ' atualizado para ' . EstrategiaRepository::statusEstrategiaLabel($status) . '.';
            } elseif ($acao === 'duplicar') {
                $id = isset($_POST['estrategia_id']) && ctype_digit((string) $_POST['estrategia_id']) ? (int) $_POST['estrategia_id'] : 0;
                $resultado = $repository->duplicar($id, $usuarioAtualId, $escopoGerencia);
                registrarAuditoriaSistema('estrategia.duplicada', 'estrategia', (int) $resultado['nova_estrategia_id'], 'Estratégia duplicada.', null, ['origem_id' => $id] + $resultado);
                $mensagemSucesso = 'Cópia criada como estratégia #' . (int) $resultado['nova_estrategia_id'] . ' em rascunho.';
                $editarId = (int) $resultado['nova_estrategia_id'];
            } elseif ($acao === 'arquivar') {
                $id = isset($_POST['estrategia_id']) && ctype_digit((string) $_POST['estrategia_id']) ? (int) $_POST['estrategia_id'] : 0;
                $repository->atualizarStatus($id, 'arquivada', $usuarioAtualId, $escopoGerencia);
                registrarAuditoriaSistema('estrategia.arquivada', 'estrategia', $id, 'Estratégia arquivada logicamente.', null, []);
                $mensagemSucesso = 'Estratégia #' . $id . ' arquivada. O histórico foi preservado.';
                if ($editarId === $id) {
                    $editarId = 0;
                }
            } else {
                throw new InvalidArgumentException('Ação de estratégia inválida.');
            }
        } catch (Throwable $e) {
            $mensagemErro = $e->getMessage();
            if (in_array($acao, ['previsualizar', 'salvar_gerar'], true)) {
                $form = array_merge($defaults, $_POST);
            }
        }
    }

    if ($moduloDisponivel) {
        if ($editarId > 0 && $preview === null && !in_array((string) ($_POST['acao'] ?? ''), ['previsualizar'], true)) {
            $estrategiaEditar = $repository->buscarEstrategia($editarId, $escopoGerencia);
            if ($estrategiaEditar !== null) {
                $form = array_merge($defaults, $repository->formularioDaEstrategia($estrategiaEditar));
            } elseif ($mensagemErro === null) {
                $mensagemErro = 'A estratégia solicitada para edição não foi encontrada no seu escopo.';
            }
        }

        $credores = $repository->listarCredores($escopoGerencia);
        $carteiras = $repository->listarCarteiras($escopoGerencia);
        $produtos = $repository->listarProdutos($escopoGerencia);
        $statusContratos = $repository->listarStatusContratos($escopoGerencia);
        $resultadosAtendimento = $repository->listarResultadosAtendimento();
        $usuarios = $repository->listarUsuariosOperacionais();
        $estrategias = $repository->listarEstrategiasSupervisao($escopoGerencia, $incluirArquivadas, 200);
        $resumo = $repository->resumoSupervisao($escopoGerencia);
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

function estrategiaSupValue(array $form, string $key, mixed $default = ''): string
{
    return h((string) ($form[$key] ?? $default));
}

function estrategiaSupChecked(array $form, string $key): string
{
    return (string) ($form[$key] ?? '') === '1' ? 'checked' : '';
}

function estrategiaSupSelected(array $form, string $key, mixed $value): string
{
    return (string) ($form[$key] ?? '') === (string) $value ? 'selected' : '';
}

function estrategiaSupUsuarioChecked(array $form, int $usuarioId): string
{
    $ids = is_array($form['usuario_ids'] ?? null) ? array_map('intval', $form['usuario_ids']) : [];
    return in_array($usuarioId, $ids, true) ? 'checked' : '';
}

function estrategiaSupDocumento(?string $documento): string
{
    $digitos = preg_replace('/\D+/', '', (string) $documento) ?? '';
    if (strlen($digitos) === 11) {
        return '***.***.***-' . substr($digitos, -2);
    }
    if (strlen($digitos) === 14) {
        return '**.***.***/****-' . substr($digitos, -2);
    }
    return $digitos === '' ? '-' : str_repeat('*', max(0, strlen($digitos) - 3)) . substr($digitos, -3);
}

require __DIR__ . '/includes/header.php';
?>
<main class="container estrategia-supervisao-page">
    <section class="card compact strategy-supervision-links">
        <div class="actions-row">
            <a class="button secondary" href="/operacao/estrategias.php">Abrir fila da Operação</a>
            <?php if (usuarioPode('acordos.visualizar') || usuarioPode('acordos.carteira.visualizar')): ?>
                <a class="button secondary" href="/operacao/acordos.php">Controle de Acordos</a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($mensagemSucesso !== null): ?><div class="alert success"><?= h($mensagemSucesso) ?></div><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><div class="alert danger"><strong>Não foi possível concluir a ação.</strong><br><?= h($mensagemErro) ?></div><?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error"><strong>Módulo de estratégias ainda não está ativo.</strong><p>Execute a migration <code>083_estrategias_operacionais.sql</code>.</p></section>
    <?php else: ?>
        <section class="strategy-supervision-hero card">
            <div>
                <span class="eyebrow">Supervisão operacional</span>
                <h2>Construtor de estratégias</h2>
                <p>Cada filtro só participa da consulta quando o controle “Usar” estiver marcado. A prévia não grava contratos; a materialização ocorre somente ao salvar.</p>
            </div>
            <div class="strategy-hero-rule">
                <strong>Regra antissobreposição</strong>
                <span>Um contrato pendente ou em atendimento não entra em duas estratégias ativas ao mesmo tempo.</span>
            </div>
        </section>

        <section class="metrics-grid metrics-grid-wide">
            <div class="metric"><small>Estratégias ativas</small><strong><?= number_format((int) $resumo['estrategias_ativas'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-warning"><small>Contratos pendentes</small><strong><?= number_format((int) $resumo['contratos_pendentes'], 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Em atendimento</small><strong><?= number_format((int) $resumo['em_atendimento'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-success"><small>Trabalhados</small><strong><?= number_format((int) $resumo['trabalhados'], 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Saldo materializado</small><strong><?= dinheiro($resumo['saldo_em_estrategias']) ?></strong></div>
        </section>

        <section class="card" id="editor">
            <div class="section-title">
                <div>
                    <h2><?= !empty($form['estrategia_id']) ? 'Editar estratégia #' . (int) $form['estrategia_id'] : 'Nova estratégia' ?></h2>
                    <p>Defina o conjunto, gere uma prévia e publique para a operação.</p>
                </div>
                <?php if (!empty($form['estrategia_id'])): ?><a class="button secondary small" href="/operacao/supervisao_estrategias.php#editor">Criar nova</a><?php endif; ?>
            </div>

            <form class="strategy-builder" method="post" action="/operacao/supervisao_estrategias.php#editor" data-strategy-form>
                <?= authCsrfInput() ?>
                <input type="hidden" name="estrategia_id" value="<?= estrategiaSupValue($form, 'estrategia_id') ?>">

                <details class="strategy-builder-section" open>
                    <summary><span>1. Identificação e publicação</span><small>Nome, status, limite e acesso dos operadores</small></summary>
                    <div class="strategy-builder-body">
                        <div class="form-grid-wide">
                            <label class="field"><span>Nome da estratégia *</span><input type="text" name="nome" maxlength="160" required value="<?= estrategiaSupValue($form, 'nome') ?>" placeholder="Ex.: ACME aging 31–90 com telefone válido"></label>
                            <label class="field"><span>Prioridade</span><input type="number" name="prioridade" min="0" max="9999" value="<?= estrategiaSupValue($form, 'prioridade', '100') ?>"><small>Número maior aparece primeiro na operação.</small></label>
                            <label class="field"><span>Limite de contratos</span><input type="number" name="limite_contratos" min="1" max="50000" value="<?= estrategiaSupValue($form, 'limite_contratos', '5000') ?>"></label>
                            <label class="field"><span>Status ao salvar</span><select name="status"><option value="ativa" <?= estrategiaSupSelected($form, 'status', 'ativa') ?>>Ativa</option><option value="rascunho" <?= estrategiaSupSelected($form, 'status', 'rascunho') ?>>Rascunho</option><option value="pausada" <?= estrategiaSupSelected($form, 'status', 'pausada') ?>>Pausada</option><option value="encerrada" <?= estrategiaSupSelected($form, 'status', 'encerrada') ?>>Encerrada</option></select></label>
                            <label class="field field-full"><span>Descrição</span><textarea name="descricao" maxlength="700" rows="3" placeholder="Objetivo, contexto e orientação para a operação."><?= estrategiaSupValue($form, 'descricao') ?></textarea></label>
                        </div>

                        <div class="strategy-visibility-box">
                            <div class="form-grid">
                                <label class="field"><span>Quem poderá trabalhar</span><select name="visibilidade" data-strategy-visibility><option value="todos" <?= estrategiaSupSelected($form, 'visibilidade', 'todos') ?>>Todos os operadores dentro do escopo</option><option value="usuarios" <?= estrategiaSupSelected($form, 'visibilidade', 'usuarios') ?>>Somente operadores selecionados</option></select></label>
                            </div>
                            <div class="strategy-users-grid" data-strategy-users>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <label class="strategy-user-option"><input type="checkbox" name="usuario_ids[]" value="<?= (int) $usuario['id'] ?>" <?= estrategiaSupUsuarioChecked($form, (int) $usuario['id']) ?>><span><strong><?= h((string) $usuario['nome']) ?></strong><small><?= h((string) $usuario['perfil_nome']) ?> · <?= h((string) $usuario['login']) ?></small></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="strategy-builder-section" open>
                    <summary><span>2. Contrato, credor e produto</span><small>Use somente os filtros necessários para esta estratégia</small></summary>
                    <div class="strategy-builder-body strategy-filter-grid">
                        <div class="strategy-filter-card" data-filter-card>
                            <label class="strategy-filter-switch"><input type="checkbox" name="usar_credor" value="1" <?= estrategiaSupChecked($form, 'usar_credor') ?> data-filter-toggle><span><strong>Usar credor</strong><small>Restringe a um credor do seu escopo.</small></span></label>
                            <label class="field" data-filter-field><span>Credor</span><select name="credor_id"><option value="">Selecione</option><?php foreach ($credores as $credor): ?><option value="<?= (int) $credor['id'] ?>" <?= estrategiaSupSelected($form, 'credor_id', $credor['id']) ?>><?= h((string) $credor['nome']) ?></option><?php endforeach; ?></select></label>
                        </div>
                        <div class="strategy-filter-card" data-filter-card>
                            <label class="strategy-filter-switch"><input type="checkbox" name="usar_carteira" value="1" <?= estrategiaSupChecked($form, 'usar_carteira') ?> data-filter-toggle><span><strong>Usar carteira</strong><small>Permite selecionar uma carteira específica.</small></span></label>
                            <label class="field" data-filter-field><span>Carteira</span><select name="carteira_id"><option value="">Selecione</option><?php foreach ($carteiras as $carteira): ?><option value="<?= (int) $carteira['id'] ?>" <?= estrategiaSupSelected($form, 'carteira_id', $carteira['id']) ?>><?= h((string) $carteira['credor_nome'] . ' / ' . (string) $carteira['nome']) ?></option><?php endforeach; ?></select></label>
                        </div>
                        <div class="strategy-filter-card" data-filter-card>
                            <label class="strategy-filter-switch"><input type="checkbox" name="usar_produto" value="1" <?= estrategiaSupChecked($form, 'usar_produto') ?> data-filter-toggle><span><strong>Usar produto</strong><small>Pesquisa por nome ou parte do produto.</small></span></label>
                            <label class="field" data-filter-field><span>Produto contém</span><input type="text" name="produto" list="strategy-products" maxlength="180" value="<?= estrategiaSupValue($form, 'produto') ?>"></label>
                        </div>
                        <div class="strategy-filter-card" data-filter-card>
                            <label class="strategy-filter-switch"><input type="checkbox" name="usar_subproduto" value="1" <?= estrategiaSupChecked($form, 'usar_subproduto') ?> data-filter-toggle><span><strong>Usar subproduto</strong><small>Pesquisa pelo subproduto canônico.</small></span></label>
                            <label class="field" data-filter-field><span>Subproduto contém</span><input type="text" name="subproduto" maxlength="180" value="<?= estrategiaSupValue($form, 'subproduto') ?>"></label>
                        </div>
                        <div class="strategy-filter-card" data-filter-card>
                            <label class="strategy-filter-switch"><input type="checkbox" name="usar_status_contrato" value="1" <?= estrategiaSupChecked($form, 'usar_status_contrato') ?> data-filter-toggle><span><strong>Usar status do contrato</strong><small>Status recebido e normalizado na base.</small></span></label>
                            <label class="field" data-filter-field><span>Status</span><select name="status_contrato"><option value="">Selecione</option><?php foreach ($statusContratos as $statusContrato): ?><option value="<?= h($statusContrato) ?>" <?= estrategiaSupSelected($form, 'status_contrato', $statusContrato) ?>><?= h($statusContrato) ?></option><?php endforeach; ?></select></label>
                        </div>
                        <div class="strategy-filter-card" data-filter-card>
                            <label class="strategy-filter-switch"><input type="checkbox" name="usar_codigo_contrato" value="1" <?= estrategiaSupChecked($form, 'usar_codigo_contrato') ?> data-filter-toggle><span><strong>Usar código do contrato</strong><small>Útil para lote específico ou conferência.</small></span></label>
                            <label class="field" data-filter-field><span>Código contém</span><input type="text" name="codigo_contrato" maxlength="120" value="<?= estrategiaSupValue($form, 'codigo_contrato') ?>"></label>
                        </div>
                    </div>
                </details>
                <datalist id="strategy-products"><?php foreach ($produtos as $produto): ?><option value="<?= h($produto) ?>"><?php endforeach; ?></datalist>

                <details class="strategy-builder-section" open>
                    <summary><span>3. Datas, vencimento e aging</span><small>Períodos são inclusivos</small></summary>
                    <div class="strategy-builder-body strategy-filter-grid">
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_entrada" value="1" <?= estrategiaSupChecked($form, 'usar_data_entrada') ?> data-filter-toggle><span><strong>Data de entrada na base</strong><small>Primeira carga de origem; usa criação do contrato como fallback.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="data_entrada_de" value="<?= estrategiaSupValue($form, 'data_entrada_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="data_entrada_ate" value="<?= estrategiaSupValue($form, 'data_entrada_ate') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_atualizacao" value="1" <?= estrategiaSupChecked($form, 'usar_data_atualizacao') ?> data-filter-toggle><span><strong>Data de atualização</strong><small>Quando o contrato foi atualizado no MySQL.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="data_atualizacao_de" value="<?= estrategiaSupValue($form, 'data_atualizacao_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="data_atualizacao_ate" value="<?= estrategiaSupValue($form, 'data_atualizacao_ate') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_vencimento_original" value="1" <?= estrategiaSupChecked($form, 'usar_vencimento_original') ?> data-filter-toggle><span><strong>Período do vencimento original</strong><small>Data inicial do contrato ou primeira parcela.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="vencimento_original_de" value="<?= estrategiaSupValue($form, 'vencimento_original_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="vencimento_original_ate" value="<?= estrategiaSupValue($form, 'vencimento_original_ate') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_dia_vencimento" value="1" <?= estrategiaSupChecked($form, 'usar_dia_vencimento') ?> data-filter-toggle><span><strong>Dia do vencimento original</strong><small>Ex.: do dia 1 ao dia 20, independentemente do mês.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Dia inicial</span><input type="number" name="dia_vencimento_de" min="1" max="31" value="<?= estrategiaSupValue($form, 'dia_vencimento_de') ?>"></label><label class="field"><span>Dia final</span><input type="number" name="dia_vencimento_ate" min="1" max="31" value="<?= estrategiaSupValue($form, 'dia_vencimento_ate') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_aging" value="1" <?= estrategiaSupChecked($form, 'usar_aging') ?> data-filter-toggle><span><strong>Aging</strong><small>Dias em atraso calculados pelo vencimento original.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="number" name="aging_min" min="0" value="<?= estrategiaSupValue($form, 'aging_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" name="aging_max" min="0" value="<?= estrategiaSupValue($form, 'aging_max') ?>"></label></div></div>
                    </div>
                </details>

                <details class="strategy-builder-section" open>
                    <summary><span>4. Valores e parcelas</span><small>Campos financeiros continuam semanticamente separados</small></summary>
                    <div class="strategy-builder-body strategy-filter-grid">
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_valor_original" value="1" <?= estrategiaSupChecked($form, 'usar_valor_original') ?> data-filter-toggle><span><strong>Valor original</strong><small>Contratado, financiado, principal ou soma das parcelas.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="text" name="valor_original_min" inputmode="decimal" value="<?= estrategiaSupValue($form, 'valor_original_min') ?>"></label><label class="field"><span>Máximo</span><input type="text" name="valor_original_max" inputmode="decimal" value="<?= estrategiaSupValue($form, 'valor_original_max') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_valor_pago" value="1" <?= estrategiaSupChecked($form, 'usar_valor_pago') ?> data-filter-toggle><span><strong>Valor já pago</strong><small>Maior valor entre o contrato e pagamentos confirmados.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="text" name="valor_pago_min" inputmode="decimal" value="<?= estrategiaSupValue($form, 'valor_pago_min') ?>"></label><label class="field"><span>Máximo</span><input type="text" name="valor_pago_max" inputmode="decimal" value="<?= estrategiaSupValue($form, 'valor_pago_max') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_valor_faltante" value="1" <?= estrategiaSupChecked($form, 'usar_valor_faltante') ?> data-filter-toggle><span><strong>Valor faltante</strong><small>Saldo atual em aberto, sem misturar com valor pago.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="text" name="valor_faltante_min" inputmode="decimal" value="<?= estrategiaSupValue($form, 'valor_faltante_min') ?>"></label><label class="field"><span>Máximo</span><input type="text" name="valor_faltante_max" inputmode="decimal" value="<?= estrategiaSupValue($form, 'valor_faltante_max') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_total_parcelas" value="1" <?= estrategiaSupChecked($form, 'usar_total_parcelas') ?> data-filter-toggle><span><strong>Quantidade de parcelas</strong><small>Total de parcelas vinculadas ao contrato.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="number" name="total_parcelas_min" min="0" value="<?= estrategiaSupValue($form, 'total_parcelas_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" name="total_parcelas_max" min="0" value="<?= estrategiaSupValue($form, 'total_parcelas_max') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_ultimo_pagamento" value="1" <?= estrategiaSupChecked($form, 'usar_data_ultimo_pagamento') ?> data-filter-toggle><span><strong>Data do último pagamento</strong><small>Período do último pagamento importado.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="ultimo_pagamento_de" value="<?= estrategiaSupValue($form, 'ultimo_pagamento_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="ultimo_pagamento_ate" value="<?= estrategiaSupValue($form, 'ultimo_pagamento_ate') ?>"></label></div></div>
                    </div>
                </details>

                <details class="strategy-builder-section" open>
                    <summary><span>5. Acionamentos e tentativas</span><small>Histórico específico do contrato, com fallback para atendimentos gerais do cliente</small></summary>
                    <div class="strategy-builder-body strategy-filter-grid">
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_situacao_tentativas" value="1" <?= estrategiaSupChecked($form, 'usar_situacao_tentativas') ?> data-filter-toggle><span><strong>Com ou sem tentativa</strong><small>Separe contratos nunca acionados.</small></span></label><label class="field" data-filter-field><span>Situação</span><select name="situacao_tentativas"><option value="nunca" <?= estrategiaSupSelected($form, 'situacao_tentativas', 'nunca') ?>>Nunca acionado</option><option value="com" <?= estrategiaSupSelected($form, 'situacao_tentativas', 'com') ?>>Com ao menos uma tentativa</option></select></label></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_quantidade_tentativas" value="1" <?= estrategiaSupChecked($form, 'usar_quantidade_tentativas') ?> data-filter-toggle><span><strong>Quantidade de tentativas</strong><small>Faixa mínima e máxima de acionamentos.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="number" name="tentativas_min" min="0" value="<?= estrategiaSupValue($form, 'tentativas_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" name="tentativas_max" min="0" value="<?= estrategiaSupValue($form, 'tentativas_max') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_ultima_tentativa" value="1" <?= estrategiaSupChecked($form, 'usar_data_ultima_tentativa') ?> data-filter-toggle><span><strong>Período da última tentativa</strong><small>Data do último acionamento registrado.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="ultima_tentativa_de" value="<?= estrategiaSupValue($form, 'ultima_tentativa_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="ultima_tentativa_ate" value="<?= estrategiaSupValue($form, 'ultima_tentativa_ate') ?>"></label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_dias_sem_tentativa" value="1" <?= estrategiaSupChecked($form, 'usar_dias_sem_tentativa') ?> data-filter-toggle><span><strong>Dias sem tentativa</strong><small>Tempo desde o último acionamento.</small></span></label><div data-filter-field><div class="strategy-range"><label class="field"><span>Mínimo</span><input type="number" name="dias_sem_tentativa_min" min="0" value="<?= estrategiaSupValue($form, 'dias_sem_tentativa_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" name="dias_sem_tentativa_max" min="0" value="<?= estrategiaSupValue($form, 'dias_sem_tentativa_max') ?>"></label></div><label class="strategy-inline-check"><input type="checkbox" name="dias_sem_incluir_nunca" value="1" <?= estrategiaSupChecked($form, 'dias_sem_incluir_nunca') ?>> Incluir contratos nunca acionados no mínimo</label></div></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_ultimo_resultado" value="1" <?= estrategiaSupChecked($form, 'usar_ultimo_resultado') ?> data-filter-toggle><span><strong>Último resultado</strong><small>Tabulação padronizada mais recente.</small></span></label><label class="field" data-filter-field><span>Resultado</span><select name="ultimo_resultado_id"><option value="">Selecione</option><?php foreach ($resultadosAtendimento as $resultadoAtendimento): ?><option value="<?= (int) $resultadoAtendimento['id'] ?>" <?= estrategiaSupSelected($form, 'ultimo_resultado_id', $resultadoAtendimento['id']) ?>><?= h((string) $resultadoAtendimento['nome']) ?> · <?= h((string) $resultadoAtendimento['categoria']) ?></option><?php endforeach; ?></select></label></div>
                    </div>
                </details>

                <details class="strategy-builder-section" open>
                    <summary><span>6. Acordos, contatos e localidade</span><small>Disponibilidade para negociação e dados de contato</small></summary>
                    <div class="strategy-builder-body strategy-filter-grid">
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_situacao_acordo" value="1" <?= estrategiaSupChecked($form, 'usar_situacao_acordo') ?> data-filter-toggle><span><strong>Situação de acordo</strong><small>Respeita a regra de um acordo operacional ativo por contrato.</small></span></label><label class="field" data-filter-field><span>Situação</span><select name="situacao_acordo"><option value="sem_acordo" <?= estrategiaSupSelected($form, 'situacao_acordo', 'sem_acordo') ?>>Nunca teve acordo</option><option value="sem_ativo" <?= estrategiaSupSelected($form, 'situacao_acordo', 'sem_ativo') ?>>Sem acordo ativo</option><option value="ativo" <?= estrategiaSupSelected($form, 'situacao_acordo', 'ativo') ?>>Com acordo ativo</option><option value="quebrado" <?= estrategiaSupSelected($form, 'situacao_acordo', 'quebrado') ?>>Com acordo quebrado</option><option value="cumprido" <?= estrategiaSupSelected($form, 'situacao_acordo', 'cumprido') ?>>Com acordo cumprido</option><option value="cancelado" <?= estrategiaSupSelected($form, 'situacao_acordo', 'cancelado') ?>>Com acordo cancelado</option></select></label></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_tipo_contato" value="1" <?= estrategiaSupChecked($form, 'usar_tipo_contato') ?> data-filter-toggle><span><strong>Disponibilidade de contato</strong><small>Usa tipo, status e valor normalizado.</small></span></label><label class="field" data-filter-field><span>Contato</span><select name="tipo_contato"><option value="telefone_valido" <?= estrategiaSupSelected($form, 'tipo_contato', 'telefone_valido') ?>>Com telefone válido</option><option value="sem_telefone_valido" <?= estrategiaSupSelected($form, 'tipo_contato', 'sem_telefone_valido') ?>>Sem telefone válido</option><option value="whatsapp" <?= estrategiaSupSelected($form, 'tipo_contato', 'whatsapp') ?>>Com WhatsApp válido</option><option value="email" <?= estrategiaSupSelected($form, 'tipo_contato', 'email') ?>>Com e-mail válido</option><option value="qualquer_valido" <?= estrategiaSupSelected($form, 'tipo_contato', 'qualquer_valido') ?>>Com qualquer contato válido</option></select></label></div>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_localidade" value="1" <?= estrategiaSupChecked($form, 'usar_localidade') ?> data-filter-toggle><span><strong>Localidade</strong><small>Endereço principal, com fallback para qualquer endereço.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>UF</span><input type="text" name="uf" maxlength="2" value="<?= estrategiaSupValue($form, 'uf') ?>" placeholder="SP"></label><label class="field"><span>Cidade contém</span><input type="text" name="cidade" maxlength="120" value="<?= estrategiaSupValue($form, 'cidade') ?>"></label></div></div>
                    </div>
                </details>

                <details class="strategy-builder-section" open>
                    <summary><span>7. Priorização e segurança</span><small>Ordem de trabalho e confirmação para conjuntos amplos</small></summary>
                    <div class="strategy-builder-body">
                        <div class="form-grid-wide">
                            <label class="field"><span>1º critério</span><select name="ordem_1"><?php foreach (EstrategiaRepository::CAMPOS_ORDENACAO as $codigo => $label): ?><option value="<?= h($codigo) ?>" <?= estrategiaSupSelected($form, 'ordem_1', $codigo) ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                            <label class="field"><span>Direção</span><select name="direcao_1"><option value="DESC" <?= estrategiaSupSelected($form, 'direcao_1', 'DESC') ?>>Decrescente</option><option value="ASC" <?= estrategiaSupSelected($form, 'direcao_1', 'ASC') ?>>Crescente</option></select></label>
                            <label class="field"><span>2º critério</span><select name="ordem_2"><?php foreach (EstrategiaRepository::CAMPOS_ORDENACAO as $codigo => $label): ?><option value="<?= h($codigo) ?>" <?= estrategiaSupSelected($form, 'ordem_2', $codigo) ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                            <label class="field"><span>Direção</span><select name="direcao_2"><option value="DESC" <?= estrategiaSupSelected($form, 'direcao_2', 'DESC') ?>>Decrescente</option><option value="ASC" <?= estrategiaSupSelected($form, 'direcao_2', 'ASC') ?>>Crescente</option></select></label>
                        </div>
                        <label class="strategy-confirm-all"><input type="checkbox" name="confirmar_todos_escopo" value="1" <?= estrategiaSupChecked($form, 'confirmar_todos_escopo') ?>><span><strong>Confirmo o uso de todos os contratos do meu escopo quando nenhum filtro estiver ativo.</strong><small>Esta confirmação evita publicar uma estratégia ampla por engano.</small></span></label>
                    </div>
                </details>

                <div class="strategy-builder-actions">
                    <button class="button secondary" type="submit" name="acao" value="previsualizar" data-loading-message="Gerando prévia da estratégia..." data-loading-detail="Analisando filtros, conflitos e indicadores sem gravar contratos.">Gerar prévia</button>
                    <button class="button" type="submit" name="acao" value="salvar_gerar" data-loading-message="Materializando estratégia..." data-loading-detail="Selecionando e gravando os contratos em lotes otimizados. Não envie novamente."><?= !empty($form['estrategia_id']) ? 'Salvar e regenerar' : 'Salvar e materializar' ?></button>
                    <?php if (!empty($form['estrategia_id'])): ?><a class="button secondary" href="/operacao/estrategias.php?estrategia_id=<?= (int) $form['estrategia_id'] ?>&status=todos">Ver contratos</a><?php endif; ?>
                </div>
            </form>
        </section>

        <?php if (is_array($preview)): ?>
            <?php $metricas = $preview['metricas']; ?>
            <section class="card strategy-preview-panel">
                <div class="section-title"><div><h2>Prévia da seleção</h2><p>Contratos com conflito não serão materializados.</p></div><?= badgeSoft('somente leitura') ?></div>
                <div class="metrics-grid metrics-grid-wide">
                    <div class="metric"><small>Encontrados</small><strong><?= number_format((int) $metricas['total_encontrado'], 0, ',', '.') ?></strong></div>
                    <div class="metric metric-success"><small>Disponíveis</small><strong><?= number_format((int) $metricas['total_disponivel'], 0, ',', '.') ?></strong></div>
                    <div class="metric metric-warning"><small>Conflitos ativos</small><strong><?= number_format((int) $metricas['total_conflitos'], 0, ',', '.') ?></strong></div>
                    <div class="metric"><small>Clientes únicos</small><strong><?= number_format((int) $metricas['clientes_unicos'], 0, ',', '.') ?></strong></div>
                    <div class="metric"><small>Saldo total</small><strong><?= dinheiro($metricas['saldo_total']) ?></strong></div>
                    <div class="metric"><small>Valor já pago</small><strong><?= dinheiro($metricas['valor_pago_total']) ?></strong></div>
                    <div class="metric"><small>Aging médio</small><strong><?= number_format((float) $metricas['aging_medio'], 1, ',', '.') ?> dias</strong></div>
                    <div class="metric"><small>Nunca acionados</small><strong><?= number_format((int) $metricas['nunca_acionados'], 0, ',', '.') ?></strong></div>
                    <div class="metric"><small>Com acordo ativo</small><strong><?= number_format((int) $metricas['com_acordo_ativo'], 0, ',', '.') ?></strong></div>
                </div>
                <?php if ($preview['amostra'] === []): ?>
                    <div class="empty"><strong>Nenhum contrato disponível.</strong><p>Ajuste os filtros ou verifique os conflitos com outras estratégias ativas.</p></div>
                <?php else: ?>
                    <div class="strategy-preview-list">
                        <?php foreach ($preview['amostra'] as $item): ?>
                            <article class="strategy-preview-card">
                                <header>
                                    <div>
                                        <span>Contrato <?= h((string) $item['codigo_contrato']) ?></span>
                                        <h3><?= h((string) $item['pessoa_nome']) ?></h3>
                                        <small><?= h(estrategiaSupDocumento((string) $item['pessoa_documento'])) ?></small>
                                    </div>
                                    <div class="strategy-preview-badges">
                                        <span class="strategy-aging-badge"><?= number_format((int) $item['aging'], 0, ',', '.') ?> dias</span>
                                        <?= (int) $item['acordos_ativos'] > 0 ? badge('Acordo ativo') : badgeSoft((string) ($item['ultimo_status_acordo'] ?: 'Sem acordo')) ?>
                                    </div>
                                </header>
                                <div class="strategy-preview-facts">
                                    <div><span>Credor / Carteira</span><strong><?= h((string) $item['credor_nome']) ?></strong><small><?= h((string) ($item['carteira_nome'] ?: 'sem carteira')) ?></small></div>
                                    <div><span>Produto</span><strong><?= h((string) ($item['produto'] ?: '-')) ?></strong></div>
                                    <div><span>Entrada na base</span><strong><?= dataHoraBr($item['data_entrada_base'] ?? null) ?></strong></div>
                                    <div><span>Vencimento original</span><strong><?= dataBr($item['data_vencimento_original'] ?? null) ?></strong></div>
                                </div>
                                <div class="strategy-preview-financial">
                                    <div><span>Original</span><strong><?= dinheiro($item['valor_original']) ?></strong></div>
                                    <div><span>Pago</span><strong><?= dinheiro($item['valor_pago']) ?></strong></div>
                                    <div class="is-balance"><span>Faltante</span><strong><?= dinheiro($item['valor_faltante']) ?></strong></div>
                                </div>
                                <footer>
                                    <div><span>Última tentativa</span><strong><?= dataHoraBr($item['ultima_tentativa'] ?? null) ?></strong><small><?= h((string) ($item['ultimo_resultado_nome'] ?: 'Sem resultado')) ?></small></div>
                                    <div><span>Tentativas</span><strong><?= number_format((int) $item['quantidade_tentativas'], 0, ',', '.') ?></strong></div>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card" id="estrategias-salvas">
            <div class="section-title">
                <div><h2>Estratégias salvas</h2><p>Gerencie publicação, regeneração, cópia e arquivamento.</p></div>
                <a class="button secondary small" href="/operacao/supervisao_estrategias.php<?= $incluirArquivadas ? '' : '?incluir_arquivadas=1' ?>#estrategias-salvas"><?= $incluirArquivadas ? 'Ocultar arquivadas' : 'Mostrar arquivadas' ?></a>
            </div>
            <?php if ($estrategias === []): ?>
                <div class="empty"><strong>Nenhuma estratégia cadastrada.</strong><p>Use o construtor acima para criar a primeira.</p></div>
            <?php else: ?>
                <div class="strategy-management-list">
                    <?php foreach ($estrategias as $estrategia): ?>
                        <article class="strategy-management-card strategy-management-status-<?= h((string) $estrategia['status']) ?>">
                            <header class="strategy-management-card-head">
                                <div>
                                    <span>Estratégia #<?= (int) $estrategia['id'] ?></span>
                                    <h3><?= h((string) $estrategia['nome']) ?></h3>
                                    <p><?= h((string) ($estrategia['descricao'] ?: 'Sem descrição')) ?></p>
                                </div>
                                <div class="strategy-management-state">
                                    <?= badge(EstrategiaRepository::statusEstrategiaLabel((string) $estrategia['status'])) ?>
                                    <small>Prioridade <?= (int) $estrategia['prioridade'] ?></small>
                                </div>
                            </header>

                            <div class="strategy-management-kpis">
                                <div><span>Contratos</span><strong><?= number_format((int) $estrategia['total_contratos'], 0, ',', '.') ?></strong></div>
                                <div><span>Pendentes</span><strong><?= number_format((int) ($estrategia['total_pendentes_atual'] ?? $estrategia['total_pendentes']), 0, ',', '.') ?></strong></div>
                                <div><span>Em atendimento</span><strong><?= number_format((int) ($estrategia['total_em_atendimento_atual'] ?? $estrategia['total_em_atendimento']), 0, ',', '.') ?></strong></div>
                                <div><span>Trabalhados</span><strong><?= number_format((int) $estrategia['total_trabalhados'], 0, ',', '.') ?></strong></div>
                                <div class="is-balance"><span>Saldo</span><strong><?= dinheiro($estrategia['saldo_total']) ?></strong></div>
                            </div>

                            <div class="strategy-management-meta">
                                <div><span>Distribuição</span><strong><?= (string) $estrategia['visibilidade'] === 'todos' ? 'Todos do escopo' : 'Usuários selecionados' ?></strong><small><?= h((string) ($estrategia['usuarios_nomes'] ?: 'Sem seleção individual')) ?></small></div>
                                <div><span>Última geração</span><strong><?= dataHoraBr($estrategia['gerada_em'] ?? null) ?></strong><small><?= h((string) ($estrategia['criado_por_nome'] ?: 'Usuário não identificado')) ?></small></div>
                            </div>

                            <footer class="strategy-management-card-actions">
                                <?php if ((string) $estrategia['status'] !== 'arquivada'): ?>
                                    <a class="button secondary small" href="/operacao/supervisao_estrategias.php?editar=<?= (int) $estrategia['id'] ?>#editor">Editar</a>
                                    <a class="button secondary small" href="/operacao/estrategias.php?estrategia_id=<?= (int) $estrategia['id'] ?>&status=todos">Contratos</a>
                                    <form method="post" action="/operacao/supervisao_estrategias.php#estrategias-salvas" data-loading-message="Regenerando estratégia #<?= (int) $estrategia['id'] ?>..." data-loading-detail="Reavaliando os filtros e materializando contratos em lotes."><?= authCsrfInput() ?><input type="hidden" name="acao" value="regerar"><input type="hidden" name="estrategia_id" value="<?= (int) $estrategia['id'] ?>"><button class="button secondary small" type="submit">Regenerar</button></form>
                                    <?php if ((string) $estrategia['status'] === 'ativa'): ?>
                                        <form method="post" action="/operacao/supervisao_estrategias.php#estrategias-salvas" data-loading-message="Pausando estratégia..."><?= authCsrfInput() ?><input type="hidden" name="acao" value="status"><input type="hidden" name="estrategia_id" value="<?= (int) $estrategia['id'] ?>"><input type="hidden" name="status" value="pausada"><button class="button secondary small" type="submit">Pausar</button></form>
                                    <?php else: ?>
                                        <form method="post" action="/operacao/supervisao_estrategias.php#estrategias-salvas" data-loading-message="Ativando estratégia..."><?= authCsrfInput() ?><input type="hidden" name="acao" value="status"><input type="hidden" name="estrategia_id" value="<?= (int) $estrategia['id'] ?>"><input type="hidden" name="status" value="ativa"><button class="button secondary small" type="submit">Ativar</button></form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <form method="post" action="/operacao/supervisao_estrategias.php#estrategias-salvas" data-loading-message="Duplicando estratégia..."><?= authCsrfInput() ?><input type="hidden" name="acao" value="duplicar"><input type="hidden" name="estrategia_id" value="<?= (int) $estrategia['id'] ?>"><button class="button secondary small" type="submit">Duplicar</button></form>
                                <?php if ((string) $estrategia['status'] !== 'arquivada'): ?>
                                    <form method="post" action="/operacao/supervisao_estrategias.php#estrategias-salvas" data-loading-message="Arquivando estratégia..." onsubmit="return confirm('Arquivar esta estratégia? Os contratos pendentes serão removidos, mas o histórico será preservado.');"><?= authCsrfInput() ?><input type="hidden" name="acao" value="arquivar"><input type="hidden" name="estrategia_id" value="<?= (int) $estrategia['id'] ?>"><button class="button danger small" type="submit">Arquivar</button></form>
                                <?php endif; ?>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script>
(function () {
    document.querySelectorAll('[data-filter-card]').forEach(function (card) {
        const toggle = card.querySelector('[data-filter-toggle]');
        if (!toggle) return;
        function sync() {
            card.classList.toggle('is-enabled', toggle.checked);
            card.querySelectorAll('[data-filter-field] input, [data-filter-field] select, [data-filter-field] textarea').forEach(function (field) {
                field.disabled = !toggle.checked;
            });
        }
        toggle.addEventListener('change', sync);
        sync();
    });

    const visibility = document.querySelector('[data-strategy-visibility]');
    const users = document.querySelector('[data-strategy-users]');
    if (visibility && users) {
        function syncUsers() {
            const enabled = visibility.value === 'usuarios';
            users.classList.toggle('is-disabled', !enabled);
            users.querySelectorAll('input').forEach(function (input) { input.disabled = !enabled; });
        }
        visibility.addEventListener('change', syncUsers);
        syncUsers();
    }
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
