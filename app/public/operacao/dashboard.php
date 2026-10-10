<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\DashboardOperacionalRepository;
use CRM\Modules\Operacao\Repositories\RelatorioOperacionalRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('relatorios.visualizar');

$pageTitle = 'Dashboard';
$pageSubtitle = 'Funil de atendimento: contactados, alô, CPC e acordos do dia.';
$activePage = 'dashboard';

$pdo = operacaoPdo();
$repo = new DashboardOperacionalRepository($pdo);
$credorRepo = new RelatorioOperacionalRepository($pdo);
$escopo = usuarioEscopoCredoresAcao('consultar');

$erro = null;
$funil = ['contactados' => 0, 'alo' => 0, 'cpc' => 0, 'acordos' => 0];
$detalhe = [];
$credorOptions = [];
$loteOptions = [];
$funilPorLote = [];

$filtros = [
    'data_inicio' => trim((string) ($_GET['data_inicio'] ?? date('Y-m-d'))),
    'data_fim' => trim((string) ($_GET['data_fim'] ?? date('Y-m-d'))),
    'credor_id' => trim((string) ($_GET['credor_id'] ?? '')),
    'lote' => trim((string) ($_GET['lote'] ?? '')),
];
$estagio = trim((string) ($_GET['estagio'] ?? ''));

$estagiosInfo = [
    'contactados' => ['label' => 'Clientes contactados', 'cor' => '#3b82f6', 'ajuda' => 'Clientes distintos com pelo menos um acionamento registrado no período (ou com acordo fechado no período).'],
    'alo' => ['label' => 'Eventos "Alô"', 'cor' => '#6366f1', 'ajuda' => 'Ligações em que alguém atendeu (tabulações marcadas como "Alô" em Configurações > Tabulações), mais todo acordo fechado em qualquer canal - acordo fechado significa que o operador falou com o cliente.'],
    'cpc' => ['label' => 'Eventos CPC', 'cor' => '#8b5cf6', 'ajuda' => 'Contato Com a Pessoa Certa: ligações cuja tabulação está marcada como contato efetivo, mais todo acordo fechado em qualquer canal.'],
    'acordos' => ['label' => 'Acordos no período', 'cor' => '#16a34a', 'ajuda' => 'Acordos fechados pelo operador aqui no CRM, mais os que têm responsável atribuído (ex.: exceção aprovada que veio por API/carga), dentro do período. Não inclui os que só foram conciliados pela sincronização com a ACME sem responsável.'],
];

try {
    $credorOptions = $credorRepo->listarCredores($escopo);

    if (!$repo->moduloDisponivel()) {
        throw new RuntimeException('Módulo de atendimentos indisponível. Execute a migration 009.');
    }

    $loteOptions = $repo->listarLotes($escopo);
    $funil = $repo->funil($filtros, $escopo);
    $funilPorLote = $repo->funilPorLote($filtros, $escopo);

    if ($estagio !== '' && array_key_exists($estagio, $estagiosInfo)) {
        $detalhe = $repo->detalhar($estagio, $filtros, $escopo, 300);
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

$maiorValor = max(1, $funil['contactados']);

function dashPercentual(int $valor, int $base): string
{
    if ($base <= 0) {
        return '-';
    }
    return number_format(($valor / $base) * 100, 1, ',', '.') . '%';
}

$filtrosUrl = array_filter($filtros, static fn (string $v): bool => $v !== '');

require __DIR__ . '/includes/header.php';
?>
<main class="container dash-page">
    <?php if ($erro !== null): ?>
        <section class="card error"><strong>Não foi possível carregar o dashboard:</strong> <?= h($erro) ?></section>
    <?php endif; ?>

    <section class="card dash-filter-card">
        <div class="section-title">
            <div>
                <h2>Filtros</h2>
                <p>O funil e o detalhamento abaixo respeitam o período, o credor e o seu escopo de acesso.</p>
            </div>
        </div>
        <form class="dash-filter-form" method="get" action="/operacao/dashboard.php">
            <label class="field">
                <span>De</span>
                <input type="date" name="data_inicio" value="<?= h($filtros['data_inicio']) ?>" required>
            </label>
            <label class="field">
                <span>Até</span>
                <input type="date" name="data_fim" value="<?= h($filtros['data_fim']) ?>" required>
            </label>
            <label class="field">
                <span>Credor</span>
                <select name="credor_id">
                    <option value="">Todos permitidos</option>
                    <?php foreach ($credorOptions as $credor): ?>
                        <option value="<?= (int) $credor['id'] ?>"<?= $filtros['credor_id'] === (string) $credor['id'] ? ' selected' : '' ?>>
                            <?= h($credor['nome']) ?><?= $credor['codigo'] !== '' ? ' (' . h($credor['codigo']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Lote</span>
                <select name="lote">
                    <option value="">Todos os lotes</option>
                    <?php foreach ($loteOptions as $lote): ?>
                        <option value="<?= h($lote) ?>"<?= $filtros['lote'] === $lote ? ' selected' : '' ?>><?= h($lote) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="dash-filter-actions">
                <button class="button primary" type="submit">Aplicar</button>
                <?php if ($estagio !== ''): ?>
                    <a class="button secondary" href="/operacao/dashboard.php<?= h(queryString($filtrosUrl)) ?>">Fechar detalhe</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card dash-funnel-card">
        <div class="section-title">
            <div>
                <h2>Funil de atendimento</h2>
                <p>Clique numa etapa para ver a lista de registros por trás do número.</p>
            </div>
        </div>

        <div class="dash-funnel">
            <?php
            $etapaAnterior = null;
            foreach (['contactados', 'alo', 'cpc', 'acordos'] as $chave):
                $valor = (int) $funil[$chave];
                $info = $estagiosInfo[$chave];
                $larguraPct = max(12, (int) round(($valor / $maiorValor) * 100));
                $isSelecionado = $estagio === $chave;
                $urlEtapa = '/operacao/dashboard.php' . queryString(array_merge($filtrosUrl, ['estagio' => $chave]));
                ?>
                <a class="dash-funnel-row<?= $isSelecionado ? ' is-selected' : '' ?>" href="<?= h($urlEtapa) ?>" title="Ver detalhe: <?= h($info['label']) ?>">
                    <div class="dash-funnel-track">
                        <div class="dash-funnel-bar" style="width: <?= $larguraPct ?>%; background: <?= h($info['cor']) ?>;">
                            <span class="dash-funnel-value"><?= number_format($valor, 0, ',', '.') ?></span>
                        </div>
                    </div>
                    <div class="dash-funnel-meta">
                        <strong><?= h($info['label']) ?></strong>
                        <span class="dash-funnel-help"><?= h($info['ajuda']) ?></span>
                        <?php if ($etapaAnterior !== null): ?>
                            <span class="dash-funnel-conv"><?= dashPercentual($valor, (int) $funil[$etapaAnterior]) ?> da etapa anterior</span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php $etapaAnterior = $chave; ?>
            <?php endforeach; ?>
        </div>

        <div class="dash-funnel-summary">
            <div><small>Conversão contactados → CPC</small><strong><?= dashPercentual((int) $funil['cpc'], (int) $funil['contactados']) ?></strong></div>
            <div><small>Conversão CPC → acordo</small><strong><?= dashPercentual((int) $funil['acordos'], max(1, (int) $funil['cpc'])) ?></strong></div>
            <div><small>Período</small><strong><?= h(dataBr($filtros['data_inicio'])) ?> a <?= h(dataBr($filtros['data_fim'])) ?></strong></div>
        </div>
    </section>

    <section class="card dash-lote-card">
        <div class="section-title">
            <div>
                <h2>Funil por lote</h2>
                <p>Mesmo funil, quebrado por lote (mesmo campo usado em Dados Gerais / Mailing) - útil pra comparar performance entre lotes no período/credor selecionados.</p>
            </div>
        </div>
        <?php if ($funilPorLote === []): ?>
            <div class="empty">Nenhum lote com movimento no período selecionado.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Lote</th>
                            <th>Contactados</th>
                            <th>Alô</th>
                            <th>CPC</th>
                            <th>Acordos</th>
                            <th>Conversão contactados → CPC</th>
                            <th>Conversão CPC → acordo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($funilPorLote as $linhaLote): ?>
                        <tr>
                            <td>
                                <?php if ($linhaLote['lote'] === 'Sem lote'): ?>
                                    <?= h($linhaLote['lote']) ?>
                                <?php else: ?>
                                    <a href="/operacao/dashboard.php<?= h(queryString(array_merge($filtrosUrl, ['lote' => $linhaLote['lote']]))) ?>" title="Filtrar o funil por este lote"><?= h($linhaLote['lote']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((int) $linhaLote['contactados'], 0, ',', '.') ?></td>
                            <td><?= number_format((int) $linhaLote['alo'], 0, ',', '.') ?></td>
                            <td><?= number_format((int) $linhaLote['cpc'], 0, ',', '.') ?></td>
                            <td><?= number_format((int) $linhaLote['acordos'], 0, ',', '.') ?></td>
                            <td><?= dashPercentual((int) $linhaLote['cpc'], (int) $linhaLote['contactados']) ?></td>
                            <td><?= dashPercentual((int) $linhaLote['acordos'], max(1, (int) $linhaLote['cpc'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($estagio !== '' && array_key_exists($estagio, $estagiosInfo)): ?>
        <section class="card dash-detail-card">
            <div class="section-title">
                <div>
                    <h2>Detalhe: <?= h($estagiosInfo[$estagio]['label']) ?></h2>
                    <p><?= number_format(count($detalhe), 0, ',', '.') ?> registro(s) exibido(s) (limite de 300 por consulta).</p>
                </div>
            </div>
            <?php if ($detalhe === []): ?>
                <div class="empty">Nenhum registro encontrado para esta etapa no período selecionado.</div>
            <?php elseif ($estagio === 'acordos'): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Data</th><th>Cliente</th><th>Documento</th><th>Contrato</th><th>Credor</th><th>Valor</th><th>Parcelas</th><th>Status</th><th>Operador</th></tr></thead>
                        <tbody>
                        <?php foreach ($detalhe as $linha): ?>
                            <tr>
                                <td><?= h(dataHoraBr($linha['criado_em'] ?? null)) ?></td>
                                <td><a href="<?= h(worksAtendimentoUrl(!empty($linha['contrato_id']) ? (int) $linha['contrato_id'] : null, !empty($linha['pessoa_id']) ? (int) $linha['pessoa_id'] : null, 'financeiro')) ?>"><?= h((string) ($linha['pessoa_nome'] ?? '-')) ?></a></td>
                                <td><?= h(documentoFormatado((string) ($linha['pessoa_documento'] ?? ''))) ?></td>
                                <td><?= h((string) ($linha['codigo_contrato'] ?? '-')) ?></td>
                                <td><?= h((string) ($linha['credor_nome'] ?? '-')) ?></td>
                                <td><?= h(dinheiro($linha['valor_acordo'] ?? null)) ?></td>
                                <td><?= (int) ($linha['quantidade_parcelas'] ?? 1) ?></td>
                                <td><?= badgeSoft((string) ($linha['status_operacional'] ?? '')) ?></td>
                                <td><?= h((string) ($linha['operador_nome'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Data</th><th>Cliente</th><th>Documento</th><th>Contrato</th><th>Credor</th><th>Canal</th><th>Resultado</th><th>Contato usado</th><th>Operador</th></tr></thead>
                        <tbody>
                        <?php foreach ($detalhe as $linha): ?>
                            <tr>
                                <td><?= h(dataHoraBr($linha['criado_em'] ?? null)) ?></td>
                                <td><?= h((string) ($linha['pessoa_nome'] ?? '-')) ?></td>
                                <td><?= h(documentoFormatado((string) ($linha['pessoa_documento'] ?? ''))) ?></td>
                                <td><?= h((string) ($linha['codigo_contrato'] ?? '-')) ?></td>
                                <td><?= h((string) ($linha['credor_nome'] ?? '-')) ?></td>
                                <td><?= h(ucfirst((string) ($linha['canal'] ?? '-'))) ?></td>
                                <td><?= h((string) ($linha['resultado'] ?? '-')) ?></td>
                                <td><?= h((string) ($linha['contato_utilizado'] ?? '-')) ?></td>
                                <td><?= h((string) ($linha['operador_nome'] ?? '-')) ?></td>
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
