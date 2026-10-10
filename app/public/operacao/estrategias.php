<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\EstrategiaRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('estrategias.visualizar');

$pageTitle = 'Estratégia';
$pageSubtitle = 'Contratos priorizados pela supervisão para abertura direta no Pactua.';
$activePage = 'estrategias_operacao';

$mensagemErro = null;
$mensagemFlashErro = null;
if (!empty($_SESSION['estrategia_flash_erro'])) {
    $mensagemFlashErro = (string) $_SESSION['estrategia_flash_erro'];
    unset($_SESSION['estrategia_flash_erro']);
}
$moduloDisponivel = false;
$estrategias = [];
$resultado = [
    'itens' => [],
    'total' => 0,
    'pagina' => 1,
    'por_pagina' => 40,
    'total_paginas' => 1,
    'resumo' => ['disponiveis' => 0, 'reservados' => 0, 'trabalhados' => 0, 'saldo' => 0.0],
];

$filtros = [
    'estrategia_id' => trim((string) ($_GET['estrategia_id'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? 'disponiveis')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$pagina = isset($_GET['pagina']) && ctype_digit((string) $_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$porPagina = isset($_GET['por_pagina']) && ctype_digit((string) $_GET['por_pagina']) ? (int) $_GET['por_pagina'] : 40;

try {
    $repository = new EstrategiaRepository(operacaoPdo());
    $moduloDisponivel = $repository->moduloDisponivel();
    if ($moduloDisponivel) {
        $usuarioId = usuarioAtualId() ?? 0;
        $escopo = usuarioEscopoCredoresAcao('operar');
        $estrategias = $repository->listarEstrategiasOperacao($usuarioId, $escopo);
        $resultado = $repository->listarContratosOperacao($usuarioId, $filtros, $escopo, $pagina, $porPagina);
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

function estrategiaDocumentoMascarado(?string $documento): string
{
    $digitos = preg_replace('/\D+/', '', (string) $documento) ?? '';
    if (strlen($digitos) === 11) {
        return '***.***.***-' . substr($digitos, -2);
    }
    if (strlen($digitos) === 14) {
        return '**.***.***/****-' . substr($digitos, -2);
    }
    if ($digitos === '') {
        return '-';
    }
    return str_repeat('*', max(0, strlen($digitos) - 3)) . substr($digitos, -3);
}

/** @param array<string,mixed> $extras */
function estrategiaOperacaoQuery(array $filtros, array $extras = []): string
{
    return queryString(array_merge([
        'estrategia_id' => $filtros['estrategia_id'] ?? null,
        'status' => $filtros['status'] ?? null,
        'q' => $filtros['q'] ?? null,
        'por_pagina' => $_GET['por_pagina'] ?? null,
    ], $extras));
}

require __DIR__ . '/includes/header.php';
?>
<main class="container estrategia-operacao-page">
    <?php if ($mensagemErro !== null): ?>
        <div class="alert danger"><strong>Não foi possível carregar as estratégias.</strong><br><?= h($mensagemErro) ?></div>
    <?php endif; ?>
    <?php if ($mensagemFlashErro !== null): ?>
        <div class="alert danger"><strong>Não foi possível abrir o contrato.</strong><br><?= h($mensagemFlashErro) ?></div>
    <?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error">
            <strong>Módulo de estratégias ainda não está ativo.</strong>
            <p>Execute a migration <code>083_estrategias_operacionais.sql</code> e atualize esta página.</p>
        </section>
    <?php else: ?>
        <section class="strategy-operation-hero card">
            <div>
                <span class="eyebrow">Fila inteligente</span>
                <h2>Contratos selecionados para sua operação</h2>
                <p>A lista respeita seu perfil, credores, carteiras e operadores definidos em cada estratégia. Ao abrir, o contrato fica reservado por duas horas.</p>
            </div>
            <?php if (usuarioPode('estrategias.gerenciar')): ?>
                <a class="button secondary" href="/operacao/supervisao_estrategias.php">Gerenciar estratégias</a>
            <?php endif; ?>
        </section>

        <section class="metrics-grid metrics-grid-wide strategy-operation-metrics">
            <div class="metric metric-warning"><small>Disponíveis</small><strong><?= number_format((int) $resultado['resumo']['disponiveis'], 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Reservados por você</small><strong><?= number_format((int) $resultado['resumo']['reservados'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-success"><small>Trabalhados</small><strong><?= number_format((int) $resultado['resumo']['trabalhados'], 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Saldo no conjunto</small><strong><?= dinheiro($resultado['resumo']['saldo']) ?></strong></div>
        </section>

        <section class="card">
            <div class="section-title">
                <div><h2>Localizar contrato</h2><p>Filtre a fila sem alterar a seleção criada pela supervisão.</p></div>
                <?= badgeSoft(number_format((int) $resultado['total'], 0, ',', '.') . ' resultado(s)') ?>
            </div>
            <form class="form-card strategy-operation-filters" method="get" action="/operacao/estrategias.php" data-loading-message="Carregando contratos..." data-loading-detail="Aplicando os filtros na fila da operação.">
                <div class="form-grid-wide filters-grid">
                    <label class="field">
                        <span>Estratégia</span>
                        <select name="estrategia_id">
                            <option value="">Todas as estratégias ativas</option>
                            <?php foreach ($estrategias as $estrategia): ?>
                                <option value="<?= (int) $estrategia['id'] ?>" <?= (string) $filtros['estrategia_id'] === (string) $estrategia['id'] ? 'selected' : '' ?>>
                                    <?= h((string) $estrategia['nome']) ?> · <?= number_format((int) ($estrategia['disponiveis'] ?? 0), 0, ',', '.') ?> disponíveis
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Situação</span>
                        <select name="status">
                            <option value="disponiveis" <?= $filtros['status'] === 'disponiveis' ? 'selected' : '' ?>>Disponíveis para trabalhar</option>
                            <option value="em_atendimento" <?= $filtros['status'] === 'em_atendimento' ? 'selected' : '' ?>>Reservados por mim</option>
                            <option value="trabalhado" <?= $filtros['status'] === 'trabalhado' ? 'selected' : '' ?>>Trabalhados</option>
                            <option value="todos" <?= $filtros['status'] === 'todos' ? 'selected' : '' ?>>Todos, exceto removidos</option>
                        </select>
                    </label>
                    <label class="field field-wide">
                        <span>Cliente, CPF/CNPJ ou contrato</span>
                        <input type="search" name="q" value="<?= h($filtros['q']) ?>" placeholder="Digite ao menos parte do nome, documento ou contrato">
                    </label>
                    <label class="field">
                        <span>Linhas por página</span>
                        <select name="por_pagina">
                            <?php foreach ([20, 40, 60, 100] as $opcao): ?>
                                <option value="<?= $opcao ?>" <?= (int) $resultado['por_pagina'] === $opcao ? 'selected' : '' ?>><?= $opcao ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="actions-row">
                    <button class="button" type="submit">Aplicar filtros</button>
                    <a class="button secondary" href="/operacao/estrategias.php">Limpar</a>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-title">
                <div><h2>Contratos da estratégia</h2><p>Os valores exibidos são a fotografia capturada quando a estratégia foi gerada.</p></div>
                <span class="strategy-page-counter">Página <?= (int) $resultado['pagina'] ?> de <?= (int) $resultado['total_paginas'] ?></span>
            </div>

            <?php if ($resultado['itens'] === []): ?>
                <div class="empty">
                    <strong>Nenhum contrato encontrado.</strong>
                    <p>Não há contratos disponíveis para os filtros atuais ou a supervisão ainda não ativou uma estratégia para seu usuário.</p>
                </div>
            <?php else: ?>
                <div class="strategy-contract-list">
                    <?php foreach ($resultado['itens'] as $item): ?>
                        <?php
                        $reservadoOutro = (int) ($item['reservado_por_usuario_id'] ?? 0) > 0
                            && (int) ($item['reservado_por_usuario_id'] ?? 0) !== (int) (usuarioAtualId() ?? 0)
                            && (empty($item['reserva_expira_em']) || strtotime((string) $item['reserva_expira_em']) >= time());
                        ?>
                        <article class="strategy-contract-card strategy-row-<?= h((string) $item['status_operacional']) ?>">
                            <header class="strategy-contract-card-head">
                                <div class="strategy-contract-priority">
                                    <span>#<?= (int) $item['estrategia_prioridade'] ?></span>
                                    <small>ordem <?= (int) $item['ordem_prioridade'] ?></small>
                                </div>
                                <div class="strategy-contract-identity">
                                    <span class="strategy-contract-source"><?= h((string) $item['estrategia_nome']) ?></span>
                                    <h3><?= h((string) $item['pessoa_nome']) ?></h3>
                                    <small><?= h(estrategiaDocumentoMascarado((string) $item['pessoa_documento'])) ?> · <?= h((string) $item['tipo_documento']) ?></small>
                                </div>
                                <div class="strategy-contract-status">
                                    <?= badge(EstrategiaRepository::statusItemLabel((string) $item['status_operacional'])) ?>
                                    <?php if (!empty($item['reservado_por_nome'])): ?>
                                        <small>Reservado por <?= h((string) $item['reservado_por_nome']) ?> até <?= dataHoraBr($item['reserva_expira_em'] ?? null) ?></small>
                                    <?php endif; ?>
                                </div>
                            </header>

                            <div class="strategy-contract-facts">
                                <div class="strategy-contract-fact">
                                    <span>Contrato</span>
                                    <strong><?= h((string) $item['codigo_contrato']) ?></strong>
                                    <small><?= h((string) ($item['status_contrato'] ?: 'sem status')) ?></small>
                                </div>
                                <div class="strategy-contract-fact">
                                    <span>Credor / Carteira</span>
                                    <strong><?= h((string) $item['credor_nome']) ?></strong>
                                    <small><?= h((string) ($item['carteira_nome'] ?: 'sem carteira')) ?></small>
                                </div>
                                <div class="strategy-contract-fact">
                                    <span>Produto</span>
                                    <strong><?= h((string) ($item['produto'] ?: '-')) ?></strong>
                                    <small><?= h((string) ($item['subproduto'] ?: 'sem subproduto')) ?></small>
                                </div>
                                <div class="strategy-contract-fact">
                                    <span>Entrada na base</span>
                                    <strong><?= dataHoraBr($item['data_entrada_base_snapshot'] ?? null) ?></strong>
                                </div>
                                <div class="strategy-contract-fact">
                                    <span>Vencimento original</span>
                                    <strong><?= dataBr($item['data_vencimento_original_snapshot'] ?? null) ?></strong>
                                </div>
                                <div class="strategy-contract-fact strategy-contract-fact-aging">
                                    <span>Aging</span>
                                    <strong><?= number_format((int) ($item['aging_snapshot'] ?? 0), 0, ',', '.') ?> dias</strong>
                                </div>
                            </div>

                            <div class="strategy-contract-financial">
                                <div><span>Valor original</span><strong><?= dinheiro($item['valor_original_snapshot'] ?? null) ?></strong></div>
                                <div><span>Valor pago</span><strong><?= dinheiro($item['valor_pago_snapshot'] ?? null) ?></strong></div>
                                <div class="is-balance"><span>Valor faltante</span><strong><?= dinheiro($item['valor_faltante_snapshot'] ?? $item['saldo_snapshot'] ?? null) ?></strong></div>
                            </div>

                            <footer class="strategy-contract-card-footer">
                                <div class="strategy-contract-contact">
                                    <span>Última tentativa</span>
                                    <strong><?= dataHoraBr($item['ultima_tentativa_snapshot'] ?? null) ?></strong>
                                    <small><?= h((string) ($item['ultimo_resultado_nome'] ?: 'Sem resultado anterior')) ?> · <?= number_format((int) ($item['quantidade_tentativas_snapshot'] ?? 0), 0, ',', '.') ?> tentativa(s)</small>
                                </div>
                                <div class="strategy-contract-action">
                                    <?php if (in_array((string) $item['status_operacional'], ['pendente', 'em_atendimento'], true) && !$reservadoOutro): ?>
                                        <form method="post" action="/operacao/estrategia_abrir.php" data-loading-message="Abrindo contrato no Pactua..." data-loading-detail="Reservando o contrato e carregando a ficha operacional.">
                                            <?= authCsrfInput() ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <button class="button" type="submit">Abrir no Pactua</button>
                                        </form>
                                    <?php elseif ((string) $item['status_operacional'] === 'trabalhado'): ?>
                                        <a class="button secondary" href="<?= h(worksAtendimentoUrl((int) $item['contrato_id'], (int) $item['pessoa_id'], 'acionamento')) ?>" data-loading-message="Abrindo histórico do contrato...">Consultar no Pactua</a>
                                    <?php else: ?>
                                        <span class="strategy-unavailable">Indisponível para este operador</span>
                                    <?php endif; ?>
                                </div>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ((int) $resultado['total_paginas'] > 1): ?>
                    <nav class="strategy-pagination" aria-label="Paginação dos contratos">
                        <?php if ((int) $resultado['pagina'] > 1): ?>
                            <a class="button secondary small" href="/operacao/estrategias.php<?= h(estrategiaOperacaoQuery($filtros, ['pagina' => (int) $resultado['pagina'] - 1])) ?>">Anterior</a>
                        <?php endif; ?>
                        <span>Página <strong><?= (int) $resultado['pagina'] ?></strong> de <strong><?= (int) $resultado['total_paginas'] ?></strong></span>
                        <?php if ((int) $resultado['pagina'] < (int) $resultado['total_paginas']): ?>
                            <a class="button secondary small" href="/operacao/estrategias.php<?= h(estrategiaOperacaoQuery($filtros, ['pagina' => (int) $resultado['pagina'] + 1])) ?>">Próxima</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
