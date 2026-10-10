<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AgendaOperadorRepository;
use CRM\Modules\Operacao\Repositories\CampanhaRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('campanhas.gerenciar');

$pageTitle = 'Campanhas Avançadas';
$pageSubtitle = 'Crie campanhas com prévia, filtros operacionais e distribuição para agenda/fila.';
$activePage = 'campanhas';

$mensagemSucesso = null;
$mensagemErro = null;
$form = $_POST ?: [];
$preview = null;
$campanhas = [];
$credores = [];
$carteiras = [];
$resultados = [];
$usuariosOperacionais = [];
$resumo = ['campanhas_ativas' => 0, 'itens_pendentes' => 0, 'itens_em_atendimento' => 0, 'itens_trabalhados' => 0];
$moduloDisponivel = false;
$incluirCanceladas = ($_GET['incluir_canceladas'] ?? '') === '1';

try {
    $repository = new CampanhaRepository(operacaoPdo());
    $agendaRepository = new AgendaOperadorRepository(operacaoPdo());
    $escopoCampanha = usuarioEscopoCredoresAcao('criar_campanha');
    $escopoConsulta = usuarioEscopoCredoresAcao('consultar');
    $moduloDisponivel = $repository->moduloDisponivel();

    if ($moduloDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = (string) ($_POST['acao'] ?? '');
        try {
            if ($acao === 'previsualizar') {
                requireOperacaoPermissao('campanhas.previsualizar');
                $preview = $repository->previsualizarCampanha($_POST, $escopoCampanha, 25);
            } elseif ($acao === 'criar_gerar') {
                if (!usuarioPode('campanhas.distribuir')) { unset($_POST['atribuir_usuario_id']); }
                $_POST['criado_por'] = usuarioAtualNome();
                $_POST['criado_por_usuario_id'] = usuarioAtualId();
                $resultado = $repository->criarCampanhaEGerar($_POST, $escopoCampanha);
                registrarAuditoriaSistema('campanha.criada', 'campanha', (int) $resultado['campanha_id'], 'Campanha avançada criada com fila inicial.', null, ['campanha_id' => (int) $resultado['campanha_id'], 'itens_gerados' => (int) $resultado['itens_gerados'], 'filtros' => $_POST]);
                $mensagemSucesso = 'Campanha #' . (int) $resultado['campanha_id'] . ' criada com ' . (int) $resultado['itens_gerados'] . ' item(ns).';
                $form = [];
            } elseif ($acao === 'regerar' && isset($_POST['campanha_id']) && ctype_digit((string) $_POST['campanha_id'])) {
                $campanhaId = (int) $_POST['campanha_id'];
                $limite = isset($_POST['limite']) && ctype_digit((string) $_POST['limite']) ? (int) $_POST['limite'] : 1000;
                $gerados = $repository->regerarFila($campanhaId, $limite, $escopoCampanha);
                registrarAuditoriaSistema('campanha.fila_gerada', 'campanha', $campanhaId, 'Fila da campanha regerada.', null, ['limite' => $limite, 'itens_gerados' => $gerados]);
                $mensagemSucesso = 'Fila da campanha #' . $campanhaId . ' regerada. Novos itens: ' . $gerados . '.';
            } elseif ($acao === 'status_campanha' && isset($_POST['campanha_id'], $_POST['status'])) {
                $campanhaId = ctype_digit((string) $_POST['campanha_id']) ? (int) $_POST['campanha_id'] : 0;
                $status = (string) $_POST['status'];
                $repository->atualizarStatusCampanha($campanhaId, $status, $escopoCampanha);
                registrarAuditoriaSistema('campanha.status_atualizado', 'campanha', $campanhaId, 'Status da campanha atualizado.', null, ['status' => $status]);
                $mensagemSucesso = 'Status da campanha atualizado.';
            } elseif ($acao === 'excluir_campanha' && isset($_POST['campanha_id'])) {
                $campanhaId = ctype_digit((string) $_POST['campanha_id']) ? (int) $_POST['campanha_id'] : 0;
                $motivo = trim((string) ($_POST['motivo_exclusao'] ?? ''));
                $resultadoExclusao = $repository->excluirCampanhaLogica($campanhaId, usuarioAtualId(), usuarioAtualNome(), $motivo !== '' ? $motivo : null, $escopoCampanha);
                registrarAuditoriaSistema('campanha.apagada', 'campanha', $campanhaId, 'Campanha apagada/arquivada logicamente.', null, ['motivo' => $motivo, 'resultado' => $resultadoExclusao]);
                $mensagemSucesso = 'Campanha #' . $campanhaId . ' apagada/arquivada. Itens descartados: ' . (int) $resultadoExclusao['itens_descartados'] . '. Reservas liberadas: ' . (int) $resultadoExclusao['reservas_liberadas'] . '.';
            }
        } catch (Throwable $e) {
            $mensagemErro = $e->getMessage();
        }
    }

    if ($moduloDisponivel) {
        $resumo = $repository->resumoGeral();
        $campanhas = $repository->listarCampanhas(100, $escopoConsulta, $incluirCanceladas);
        $credores = $repository->listarCredores($escopoCampanha);
        $carteiras = $repository->listarCarteiras($escopoCampanha);
        $resultados = $repository->listarResultadosAtendimento();
        $usuariosOperacionais = $agendaRepository->listarUsuariosOperacionais();
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

function campanhaFormValue(array $form, string $key, mixed $default = ''): string
{
    return h((string) ($form[$key] ?? $default));
}

require __DIR__ . '/includes/header.php';
?>
<main class="container">
    <section class="card compact"><div class="actions-row"><a class="button secondary" href="/operacao/supervisao.php">Supervisão</a></div></section>
    <?php if ($mensagemSucesso !== null): ?><div class="alert success"><?= h($mensagemSucesso) ?></div><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><div class="alert danger"><?= h($mensagemErro) ?></div><?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error"><strong>Módulo de campanhas ainda não está ativo.</strong><p>Execute a migration 012.</p></section>
    <?php else: ?>
        <section class="metrics-grid metrics-grid-wide">
            <div class="metric"><small>Campanhas ativas</small><strong><?= number_format((int) $resumo['campanhas_ativas'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-warning"><small>Pendentes</small><strong><?= number_format((int) $resumo['itens_pendentes'], 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Em atendimento</small><strong><?= number_format((int) $resumo['itens_em_atendimento'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-success"><small>Trabalhados</small><strong><?= number_format((int) $resumo['itens_trabalhados'], 0, ',', '.') ?></strong></div>
        </section>

        <section class="card">
            <div class="section-title"><div><h2>Nova campanha com prévia</h2><p>Teste os filtros antes de materializar a fila.</p></div><?= badgeSoft('preview seguro') ?></div>
            <form class="form-card campanha-form" method="post" action="/operacao/campanhas.php">
                <?= authCsrfInput() ?>
                <div class="form-grid">
                    <label class="field"><span>Nome da campanha</span><input type="text" name="nome" maxlength="160" required value="<?= campanhaFormValue($form, 'nome') ?>" placeholder="Ex.: ACME maior saldo com telefone"></label>
                    <label class="field"><span>Prioridade</span><input type="number" name="prioridade" min="0" max="999" value="<?= campanhaFormValue($form, 'prioridade', '10') ?>"></label>
                    <label class="field"><span>Limite de itens</span><input type="number" name="limite" min="1" max="5000" value="<?= campanhaFormValue($form, 'limite', '500') ?>"></label>
                    <label class="field"><span>Credor</span><select name="credor_id"><option value="">Todos</option><?php foreach ($credores as $credor): ?><option value="<?= (int) $credor['id'] ?>" <?= ((string) ($form['credor_id'] ?? '') === (string) $credor['id']) ? 'selected' : '' ?>><?= h((string) $credor['nome']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Carteira</span><select name="carteira_id"><option value="">Todas</option><?php foreach ($carteiras as $carteira): ?><option value="<?= (int) $carteira['id'] ?>" <?= ((string) ($form['carteira_id'] ?? '') === (string) $carteira['id']) ? 'selected' : '' ?>><?= h((string) $carteira['credor_nome'] . ' / ' . (string) $carteira['nome']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Produto contém</span><input type="text" name="produto" maxlength="180" value="<?= campanhaFormValue($form, 'produto') ?>" placeholder="Cartão, Consignado..."></label>
                    <label class="field"><span>Saldo mínimo</span><input type="text" name="saldo_min" value="<?= campanhaFormValue($form, 'saldo_min') ?>"></label>
                    <label class="field"><span>Saldo máximo</span><input type="text" name="saldo_max" value="<?= campanhaFormValue($form, 'saldo_max') ?>"></label>
                    <label class="field"><span>Dias atraso mínimo</span><input type="number" name="dias_atraso_min" min="0" value="<?= campanhaFormValue($form, 'dias_atraso_min') ?>"></label>
                    <label class="field"><span>Dias atraso máximo</span><input type="number" name="dias_atraso_max" min="0" value="<?= campanhaFormValue($form, 'dias_atraso_max') ?>"></label>
                    <label class="field"><span>Último resultado</span><select name="ultimo_resultado_id"><option value="">Qualquer</option><?php foreach ($resultados as $resultado): ?><option value="<?= (int) $resultado['id'] ?>" <?= ((string) ($form['ultimo_resultado_id'] ?? '') === (string) $resultado['id']) ? 'selected' : '' ?>><?= h((string) $resultado['nome']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Sem contato efetivo há X dias</span><input type="number" name="sem_contato_efetivo_dias" min="1" value="<?= campanhaFormValue($form, 'sem_contato_efetivo_dias') ?>"></label>
                    <?php if (usuarioPode('campanhas.distribuir')): ?>
                        <label class="field"><span>Destino da campanha</span><select name="destino_distribuicao"><option value="geral" <?= ((string) ($form['destino_distribuicao'] ?? 'geral') === 'geral') ? 'selected' : '' ?>>Fila geral do credor/carteira</option><option value="operador" <?= ((string) ($form['destino_distribuicao'] ?? '') === 'operador') ? 'selected' : '' ?>>Reservar para operador específico</option></select><small>Geral mantém os itens disponíveis na Agenda/Fila respeitando credor, carteira e escopo.</small></label>
                        <label class="field"><span>Operador específico</span><select name="atribuir_usuario_id"><option value="">Selecione apenas se o destino for operador</option><?php foreach ($usuariosOperacionais as $usuario): ?><option value="<?= (int) $usuario['id'] ?>" <?= ((string) ($form['atribuir_usuario_id'] ?? '') === (string) $usuario['id']) ? 'selected' : '' ?>><?= h((string) $usuario['nome']) ?><?= !empty($usuario['perfil_nome']) ? ' · ' . h((string) $usuario['perfil_nome']) : '' ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                </div>
                <div class="switch-grid campanha-switches">
                    <?php foreach ([
                        'exigir_contato_valido' => ['Exigir telefone/WhatsApp válido','Somente clientes com contato útil.'],
                        'excluir_retorno_pendente' => ['Excluir retorno pendente','Evita furar agendamentos.'],
                        'excluir_acordo_ativo' => ['Excluir acordo ativo','Não aciona contrato já negociado.'],
                        'excluir_reserva_ativa' => ['Excluir cliente reservado','Não pega cliente preso com operador.'],
                    ] as $campo => $txt): ?>
                        <label class="switch-card"><input type="checkbox" name="<?= h($campo) ?>" value="1" <?= ((string) ($form[$campo] ?? '1') === '1') ? 'checked' : '' ?>><span><strong><?= h($txt[0]) ?></strong><small><?= h($txt[1]) ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <label class="field field-full"><span>Descrição/estratégia</span><textarea name="descricao" maxlength="500" rows="3"><?= h($form['descricao'] ?? '') ?></textarea></label>
                <div class="actions-row form-actions"><button class="button secondary" name="acao" value="previsualizar" type="submit">Previsualizar</button><button class="button" name="acao" value="criar_gerar" type="submit">Criar campanha e gerar fila</button></div>
            </form>
        </section>

        <?php if ($preview !== null): ?>
            <section class="card card-highlight">
                <div class="section-title"><div><h2>Prévia da campanha</h2><p>Estimativa antes de gerar a fila.</p></div><span class="badge"><?= number_format((int) $preview['total'], 0, ',', '.') ?> candidato(s)</span></div>
                <?php if ($preview['amostra'] === []): ?><div class="empty">Nenhum candidato encontrado com estes filtros.</div><?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>Cliente</th><th>Contrato</th><th>Credor/Carteira</th><th>Saldo</th><th>Atraso</th><th>Telefones</th><th>Último atendimento</th></tr></thead><tbody>
                    <?php foreach ($preview['amostra'] as $row): ?><tr><td><strong><?= h((string) $row['pessoa_nome']) ?></strong><div class="muted"><?= documentoFormatado((string) $row['documento']) ?></div></td><td><?= h((string) $row['codigo_contrato']) ?><div class="muted"><?= h((string) ($row['produto'] ?? '')) ?></div></td><td><?= h((string) $row['credor_nome']) ?><div class="muted"><?= h((string) ($row['carteira_nome'] ?? '-')) ?></div></td><td><?= dinheiro($row['saldo_atual'] ?? null) ?></td><td><?= (int) ($row['dias_atraso'] ?? 0) ?></td><td><?= (int) ($row['telefones'] ?? 0) ?></td><td><?= h(dataHoraBr($row['ultimo_atendimento'] ?? null)) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="section-title"><div><h2>Campanhas existentes</h2><p>Acompanhe status, produtividade e arquive campanhas antigas sem perder histórico.</p></div><span class="badge"><?= count($campanhas) ?> campanha(s)</span></div>
            <form class="filter-inline" method="get" action="/operacao/campanhas.php">
                <label class="switch-card switch-card-inline"><input type="checkbox" name="incluir_canceladas" value="1" <?= $incluirCanceladas ? 'checked' : '' ?> onchange="this.form.submit()"><span><strong>Mostrar apagadas/arquivadas</strong><small>Por padrão, campanhas canceladas ficam ocultas.</small></span></label>
                <?php if ($incluirCanceladas): ?><a class="button secondary table-button" href="/operacao/campanhas.php">Ocultar apagadas</a><?php endif; ?>
            </form>
            <div class="table-wrap"><table><thead><tr><th>ID</th><th>Campanha</th><th>Status</th><th>Total</th><th>Pendentes</th><th>Em atendimento</th><th>Trabalhados</th><th>Ações</th></tr></thead><tbody>
            <?php foreach ($campanhas as $campanha): ?>
                <?php $campanhaCancelada = (string) $campanha['status'] === 'cancelada'; ?>
                <tr class="<?= $campanhaCancelada ? 'row-muted' : '' ?>">
                    <td>#<?= (int) $campanha['id'] ?></td>
                    <td><strong><?= h((string) $campanha['nome']) ?></strong><div class="muted"><?= h((string) ($campanha['descricao'] ?? '')) ?></div></td>
                    <td><?= badge(CampanhaRepository::statusCampanhaLabel((string) $campanha['status'])) ?></td>
                    <td><?= number_format((int) ($campanha['total_itens_calc'] ?? 0), 0, ',', '.') ?></td>
                    <td><?= number_format((int) ($campanha['itens_pendentes_calc'] ?? 0), 0, ',', '.') ?></td>
                    <td><?= number_format((int) ($campanha['itens_em_atendimento_calc'] ?? 0), 0, ',', '.') ?></td>
                    <td><?= number_format((int) ($campanha['itens_trabalhados_calc'] ?? 0), 0, ',', '.') ?></td>
                    <td>
                        <div class="actions-row table-actions">
                            <?php if (!$campanhaCancelada): ?>
                                <form class="inline-form" method="post">
                                    <?= authCsrfInput() ?>
                                    <input type="hidden" name="acao" value="status_campanha">
                                    <input type="hidden" name="campanha_id" value="<?= (int) $campanha['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $campanha['status'] === 'ativa' ? 'pausada' : 'ativa' ?>">
                                    <button class="button secondary table-button" type="submit"><?= $campanha['status'] === 'ativa' ? 'Pausar' : 'Ativar' ?></button>
                                </form>
                                <form class="inline-form" method="post" onsubmit="return confirm('Apagar/arquivar esta campanha? Os itens pendentes serão descartados e as reservas ativas da campanha serão liberadas. O histórico será preservado.');">
                                    <?= authCsrfInput() ?>
                                    <input type="hidden" name="acao" value="excluir_campanha">
                                    <input type="hidden" name="campanha_id" value="<?= (int) $campanha['id'] ?>">
                                    <input type="hidden" name="motivo_exclusao" value="Apagada pela tela de campanhas">
                                    <button class="button danger table-button" type="submit">Apagar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
