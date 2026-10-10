<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\CampanhaRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('fila.trabalhar');

$pageTitle = 'Fila Manual';
$pageSubtitle = 'Lista operacional gerada por campanhas materializadas.';
$activePage = 'fila_manual';

$mensagemSucesso = null;
$mensagemErro = null;
$moduloDisponivel = false;
$campanhas = [];
$itens = [];
$resumo = [
    'campanhas_ativas' => 0,
    'itens_pendentes' => 0,
    'itens_em_atendimento' => 0,
    'itens_trabalhados' => 0,
];

$filtroCampanhaId = isset($_GET['campanha_id']) && ctype_digit((string) $_GET['campanha_id']) ? (int) $_GET['campanha_id'] : 0;
$filtroStatus = trim((string) ($_GET['status'] ?? 'pendente'));
$filtroTermo = trim((string) ($_GET['q'] ?? ''));

try {
    $repository = new CampanhaRepository(operacaoPdo());
    $escopoOperar = usuarioEscopoCredoresAcao('operar');
    $moduloDisponivel = $repository->moduloDisponivel();

    if ($moduloDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = (string) ($_POST['acao'] ?? '');
        $itemId = isset($_POST['item_id']) && ctype_digit((string) $_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $observacao = trim((string) ($_POST['observacao'] ?? ''));

        try {
            if ($acao === 'item_status' && $itemId > 0) {
                $status = (string) ($_POST['status'] ?? '');
                $repository->atualizarStatusItem($itemId, $status, $observacao !== '' ? $observacao : null);
                registrarAuditoriaSistema(
                    'fila.item_status_atualizado',
                    'campanha_item',
                    $itemId,
                    'Status do item da fila manual atualizado.',
                    null,
                    ['status' => $status, 'observacao' => $observacao]
                );
                $mensagemSucesso = 'Item de fila atualizado.';
            }
        } catch (Throwable $e) {
            $mensagemErro = $e->getMessage();
        }
    }

    if ($moduloDisponivel) {
        $resumo = $repository->resumoGeral();
        $campanhas = $repository->listarCampanhas(100, $escopoOperar);
        $itens = $repository->listarItens($filtroCampanhaId, $filtroStatus, $filtroTermo, 100, $escopoOperar);
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

require __DIR__ . '/includes/header.php';
?>
<main class="container">
    <section class="card compact">
        <div class="actions-row">
            <a class="button secondary" href="/operacao/campanhas.php">Gerenciar campanhas</a>
            <a class="button secondary" href="/operacao/works.php">Atendimento Pactua</a>
            <a class="button secondary" href="/operacao/retornos.php">Retornos</a>
        </div>
    </section>

    <?php if ($mensagemSucesso !== null): ?>
        <div class="alert success"><?= h($mensagemSucesso) ?></div>
    <?php endif; ?>

    <?php if ($mensagemErro !== null): ?>
        <div class="alert danger"><?= h($mensagemErro) ?></div>
    <?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error">
            <strong>Módulo de campanhas ainda não está ativo.</strong>
            Execute a migration <code>012_campanhas_fila_manual.sql</code> para liberar a fila manual.
        </section>
    <?php else: ?>
        <section class="metrics-grid metrics-grid-wide">
            <div class="metric"><small>Campanhas ativas</small><strong><?= number_format((int) $resumo['campanhas_ativas'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-warning"><small>Pendentes</small><strong><?= number_format((int) $resumo['itens_pendentes'], 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Em atendimento</small><strong><?= number_format((int) $resumo['itens_em_atendimento'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-success"><small>Trabalhados</small><strong><?= number_format((int) $resumo['itens_trabalhados'], 0, ',', '.') ?></strong></div>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Filtros da fila</h2>
                    <p>A fila mostra itens materializados pelo gestor. O atendimento registrado na ficha marca o item como trabalhado.</p>
                </div>
                <?= badgeSoft('MySQL operacional') ?>
            </div>

            <form class="form-card" method="get" action="/operacao/fila_manual.php">
                <div class="form-grid filters-grid">
                    <label class="field">
                        <span>Campanha</span>
                        <select name="campanha_id">
                            <option value="0">Todas</option>
                            <?php foreach ($campanhas as $campanha): ?>
                                <option value="<?= (int) $campanha['id'] ?>" <?= $filtroCampanhaId === (int) $campanha['id'] ? 'selected' : '' ?>>
                                    #<?= (int) $campanha['id'] ?> - <?= h((string) $campanha['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Status</span>
                        <select name="status">
                            <option value="todos" <?= $filtroStatus === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <?php foreach (CampanhaRepository::STATUS_ITEM as $valor => $label): ?>
                                <option value="<?= h($valor) ?>" <?= $filtroStatus === $valor ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Buscar</span>
                        <input type="text" name="q" value="<?= h($filtroTermo) ?>" placeholder="Nome, documento ou contrato">
                    </label>
                </div>

                <div class="actions-row form-actions">
                    <button class="button" type="submit">Filtrar</button>
                    <a class="button secondary" href="/operacao/fila_manual.php">Limpar</a>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Itens da fila</h2>
                    <p>Abra a ficha para registrar o evento real. Pular só tira da visão pendente desta rodada.</p>
                </div>
                <span class="badge"><?= count($itens) ?> item(ns)</span>
            </div>

            <?php if ($itens === []): ?>
                <div class="empty">Nenhum item encontrado para os filtros atuais.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="fila-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Campanha</th>
                            <th>Cliente</th>
                            <th>Documento</th>
                            <th>Contrato</th>
                            <th>Credor</th>
                            <th>Saldo</th>
                            <th>Atraso</th>
                            <th>Contatos</th>
                            <th>Último atendimento</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td>#<?= (int) $item['id'] ?></td>
                                <td>
                                    <strong><?= h((string) $item['campanha_nome']) ?></strong>
                                    <div class="muted">Campanha #<?= (int) $item['campanha_id'] ?></div>
                                </td>
                                <td><?= h((string) $item['pessoa_nome']) ?></td>
                                <td><?= documentoFormatado((string) $item['pessoa_documento']) ?></td>
                                <td>
                                    <?= valorOuTraco($item['codigo_contrato'] ?? null) ?>
                                    <?php if (!empty($item['produto'])): ?>
                                        <div class="muted"><?= h((string) $item['produto']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= valorOuTraco($item['credor_nome'] ?? null) ?>
                                    <?php if (!empty($item['carteira_nome'])): ?>
                                        <div class="muted"><?= h((string) $item['carteira_nome']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(dinheiro($item['saldo_atual'] ?? null)) ?></td>
                                <td><?= $item['dias_atraso'] !== null ? number_format((int) $item['dias_atraso'], 0, ',', '.') . ' dia(s)' : '<span class="muted">-</span>' ?></td>
                                <td><?= number_format((int) ($item['total_contatos'] ?? 0), 0, ',', '.') ?></td>
                                <td><?= h(dataHoraBr($item['ultimo_atendimento'] ?? null)) ?></td>
                                <td><?= badge(CampanhaRepository::statusItemLabel((string) $item['status'])) ?></td>
                                <td class="fila-actions-cell">
                                    <div class="actions-row table-actions">
                                        <a class="button table-button" href="<?= h(worksAtendimentoUrl(!empty($item['contrato_id']) ? (int) $item['contrato_id'] : null, (int) $item['pessoa_id'], 'acionamento', ['campanha_item_id' => (int) $item['id']])) ?>">Abrir no Pactua</a>
                                        <?php if ($item['status'] === 'pendente'): ?>
                                            <form class="inline-form" method="post" action="/operacao/fila_manual.php<?= h(queryString(['campanha_id' => $filtroCampanhaId ?: null, 'status' => $filtroStatus, 'q' => $filtroTermo])) ?>">
                                                <?= authCsrfInput() ?>
                                                <input type="hidden" name="acao" value="item_status">
                                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                <input type="hidden" name="status" value="pulado">
                                                <button class="button secondary table-button" type="submit">Pular</button>
                                            </form>
                                            <form class="inline-form" method="post" action="/operacao/fila_manual.php<?= h(queryString(['campanha_id' => $filtroCampanhaId ?: null, 'status' => $filtroStatus, 'q' => $filtroTermo])) ?>">
                                                <?= authCsrfInput() ?>
                                                <input type="hidden" name="acao" value="item_status">
                                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                <input type="hidden" name="status" value="em_atendimento">
                                                <button class="button secondary table-button" type="submit">Reservar</button>
                                            </form>
                                        <?php elseif (in_array($item['status'], ['pulado','em_atendimento'], true)): ?>
                                            <form class="inline-form" method="post" action="/operacao/fila_manual.php<?= h(queryString(['campanha_id' => $filtroCampanhaId ?: null, 'status' => $filtroStatus, 'q' => $filtroTermo])) ?>">
                                                <?= authCsrfInput() ?>
                                                <input type="hidden" name="acao" value="item_status">
                                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                <input type="hidden" name="status" value="pendente">
                                                <button class="button secondary table-button" type="submit">Voltar</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
