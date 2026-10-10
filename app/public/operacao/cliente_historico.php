<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use CRM\Modules\Operacao\Repositories\HistoricoOperacionalRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoAdminTecnico();
requireOperacaoPermissao('historico.visualizar');

$pageTitle = 'Histórico Operacional do Cliente';
$pageSubtitle = 'Timeline completa de atendimento, retorno, campanha, fila, reserva e acordo.';
$activePage = 'consulta';

$pessoaId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$erro = null;
$pessoa = null;
$resumo = [];
$timeline = [];
$tipoFiltro = trim((string) ($_GET['tipo'] ?? 'todos'));

try {
    if ($pessoaId <= 0) {
        throw new RuntimeException('ID do cliente não informado.');
    }
    requirePessoaNoEscopo($pessoaId, 'consultar');
    $pdo = operacaoPdo();
    $consultaRepo = new ConsultaClienteRepository($pdo);
    $histRepo = new HistoricoOperacionalRepository($pdo);
    $pessoa = $consultaRepo->buscarPessoaPorId($pessoaId);
    if ($pessoa === null) {
        http_response_code(404);
        throw new RuntimeException('Cliente não encontrado.');
    }
    $escopo = usuarioEscopoCredoresAcao('consultar');
    $resumo = $histRepo->resumoCliente($pessoaId, $escopo);
    $timeline = $histRepo->timelineCliente($pessoaId, $escopo, 250);
    if ($tipoFiltro !== '' && $tipoFiltro !== 'todos') {
        $timeline = array_values(array_filter($timeline, static fn(array $item): bool => (string) ($item['tipo'] ?? '') === $tipoFiltro));
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
    if (http_response_code() === 200) { http_response_code(500); }
}

require __DIR__ . '/includes/header.php';
?>
<main class="container">
    <section class="card compact">
        <div class="actions-row">
            <a class="button secondary" href="/operacao/cliente_detalhe.php?id=<?= (int) $pessoaId ?>">Voltar para ficha</a>
            <a class="button secondary" href="/operacao/consulta_cliente.php">Consulta</a>
        </div>
    </section>

    <?php if ($erro !== null): ?>
        <section class="card error"><strong>Não foi possível carregar o histórico:</strong> <?= h($erro) ?></section>
    <?php elseif ($pessoa !== null): ?>
        <section class="card">
            <div class="detail-header">
                <div>
                    <span class="eyebrow">Cliente #<?= (int) $pessoa['id'] ?></span>
                    <h2><?= h((string) $pessoa['nome']) ?></h2>
                    <div class="muted"><?= documentoFormatado((string) $pessoa['documento']) ?></div>
                </div>
                <div class="topbar-actions"><?= badgeSoft('timeline 360') ?><?= badge((string) ($tipoFiltro === 'todos' ? 'todos eventos' : $tipoFiltro)) ?></div>
            </div>
        </section>

        <section class="metrics-grid metrics-grid-wide">
            <div class="metric"><small>Atendimentos</small><strong><?= number_format((int) ($resumo['atendimentos'] ?? 0), 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Campanhas</small><strong><?= number_format((int) ($resumo['campanhas'] ?? 0), 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Reservas</small><strong><?= number_format((int) ($resumo['reservas'] ?? 0), 0, ',', '.') ?></strong></div>
            <div class="metric metric-warning"><small>Retornos pendentes</small><strong><?= number_format((int) ($resumo['retornos_pendentes'] ?? 0), 0, ',', '.') ?></strong></div>
            <div class="metric metric-success"><small>Acordos</small><strong><?= number_format((int) ($resumo['acordos'] ?? 0), 0, ',', '.') ?></strong></div>
        </section>

        <section class="card">
            <form class="filter-form" method="get" action="/operacao/cliente_historico.php">
                <input type="hidden" name="id" value="<?= (int) $pessoaId ?>">
                <label class="field">
                    <span>Tipo de evento</span>
                    <select name="tipo">
                        <?php foreach (['todos' => 'Todos', 'atendimento' => 'Atendimentos', 'retorno' => 'Retornos', 'retorno_vencido' => 'Retornos vencidos', 'campanha' => 'Campanhas', 'reserva' => 'Reservas', 'fila' => 'Fila', 'acordo' => 'Acordos'] as $valor => $label): ?>
                            <option value="<?= h($valor) ?>" <?= $tipoFiltro === $valor ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button" type="submit">Filtrar</button>
                <a class="button secondary" href="/operacao/cliente_historico.php?id=<?= (int) $pessoaId ?>">Limpar</a>
            </form>
        </section>

        <section class="card">
            <div class="section-title">
                <div><h2>Timeline operacional completa</h2><p>Eventos consolidados sem alterar as tabelas finais nem o motor de cargas.</p></div>
                <span class="badge"><?= count($timeline) ?> evento(s)</span>
            </div>
            <?php if ($timeline === []): ?>
                <div class="empty">Nenhum evento encontrado para este filtro.</div>
            <?php else: ?>
                <div class="timeline timeline-full">
                    <?php foreach ($timeline as $item): ?>
                        <article class="timeline-item timeline-item-<?= h((string) ($item['tipo'] ?? 'evento')) ?>">
                            <div class="timeline-marker"><?= h((string) ($item['icone'] ?? 'EV')) ?></div>
                            <div class="timeline-content">
                                <div class="timeline-head">
                                    <strong><?= h((string) ($item['titulo'] ?? 'Evento operacional')) ?></strong>
                                    <span><?= h(dataHoraBr($item['data'] ?? null)) ?></span>
                                </div>
                                <div class="timeline-meta">
                                    <?= badge((string) ($item['badge'] ?? ($item['tipo'] ?? 'evento'))) ?>
                                    <?php if (!empty($item['operador'])): ?><span>Operador: <?= h((string) $item['operador']) ?></span><?php endif; ?>
                                    <?php if (!empty($item['contrato'])): ?><span>Contrato: <?= h((string) $item['contrato']) ?></span><?php endif; ?>
                                    <?php if (!empty($item['credor'])): ?><span><?= h((string) $item['credor']) ?><?= !empty($item['carteira']) ? ' / ' . h((string) $item['carteira']) : '' ?></span><?php endif; ?>
                                </div>
                                <?php if (!empty($item['descricao'])): ?><p><?= nl2br(h((string) $item['descricao'])) ?></p><?php endif; ?>
                                <?php if (!empty($item['contato']) || !empty($item['extra'])): ?>
                                    <div class="timeline-extra">
                                        <?php if (!empty($item['contato'])): ?><span>Contato: <?= h((string) $item['contato']) ?></span><?php endif; ?>
                                        <?php if (!empty($item['extra'])): ?><span><?= h((string) $item['extra']) ?></span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <small class="muted">Origem: <?= h((string) ($item['origem'] ?? '-')) ?> #<?= h((string) ($item['origem_id'] ?? '-')) ?></small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
