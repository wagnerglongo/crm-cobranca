<?php
use CRM\Modules\Operacao\Repositories\EstrategiaRepository;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link rel="icon" type="image/svg+xml" href="/operacao/assets/icons/logo-icon.svg">
    <link rel="apple-touch-icon" href="/operacao/assets/icons/logo-icon.svg">
    <link rel="stylesheet" href="<?= h(operacaoCssUrl()) ?>">
    <script src="<?= h(operacaoAssetUrl('sidebar.js')) ?>" defer></script>
    <script src="<?= h(operacaoAssetUrl('request-loading.js')) ?>" defer></script>
    <script src="<?= h(operacaoAssetUrl('works-acme-tracking.js')) ?>" defer></script>
    <script src="<?= h(operacaoAssetUrl('works-acme-location.js')) ?>" defer></script>
    <script src="<?= h(operacaoAssetUrl('works-core.js')) ?>" defer></script>
    <script src="<?= h(operacaoAssetUrl('works-financeiro.js')) ?>" defer></script>
    <script src="<?= h(operacaoAssetUrl('works-acionamento.js')) ?>" defer></script>
</head>
<body class="adaptor-body works-body">
<a href="#adaptor-main" class="skip-link">Pular para o atendimento</a>
<div class="app-shell works-shell" data-sidebar-shell>
    <?php require __DIR__ . '/../../../public/operacao/includes/sidebar.php'; ?>
    <div class="app-main works-app-main">
<header class="adaptor-topbar">
    <div class="adaptor-brand works-brand works-top-logo-holder" aria-label="Pactua Call Center">
        <img class="works-top-logo-horizontal" src="/operacao/assets/icons/logo-horizontal.svg" alt="Pactua Call Center" loading="eager">
        <span class="works-top-logo-subtitle">CALL CENTER</span>
    </div>
    <a class="adaptor-home" href="/operacao/works.php" title="Início">⌂</a>
    <form class="adaptor-search" method="get" action="/operacao/works.php" data-works-search-form>
        <label class="adaptor-search-type">
            <span class="sr-only">Tipo de busca</span>
            <select name="tipo" id="adaptor-tipo-busca">
                <?php foreach ($tiposBusca as $codigo => $label): ?>
                    <option value="<?= h($codigo) ?>" <?= $tipoBusca === $codigo ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <input type="search" name="q" id="adaptor-q" value="<?= h($termoBusca) ?>" placeholder="Digite CPF, contrato, nome ou telefone" autocomplete="off" autofocus>
        <button type="submit" title="Pesquisar">⌕</button>
        <label class="adaptor-closed-check"><input type="checkbox" name="encerradas" value="1" checked> Pesquisar dívidas encerradas</label>
    </form>
    <div class="adaptor-userbar">
        <span>Olá, <?= h(usuarioAtualNome() ?: 'Suporte') ?>!</span>
        <a href="/operacao/works.php" title="Nova busca">＋</a>
        <a href="/operacao/logout.php" title="Sair">⏻</a>
    </div>
</header>

<main class="adaptor-main" id="adaptor-main" data-works-main data-works-tab="<?= h($tab) ?>">
    <?php if ($contrato !== null && $financeiroPerfil === 'acme' && $deveAtualizarApiAcme): ?>
        <div class="works-contract-loading" data-contract-loading>
            <div class="works-contract-loading-card">
                <span class="works-spinner" aria-hidden="true"></span>
                <strong>Atualizando financeiro ACME...</strong>
                <small>Consultando valor atualizado, propostas e vencimentos. Se a API falhar, os dados da base continuam disponíveis.</small>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($contrato !== null && $financeiroPerfil === 'acme'): ?>
        <section class="adaptor-alert adaptor-alert-warning works-acme-page-alert" data-acme-api-page-alert hidden></section>
    <?php endif; ?>

    <?php if ($estrategiaItem !== null): ?>
        <?php
        $estrategiaStatusRetorno = match ((string) $estrategiaItem['status_operacional']) {
            'trabalhado' => 'trabalhado',
            'em_atendimento' => 'em_atendimento',
            default => 'disponiveis',
        };
        ?>
        <section class="works-strategy-context" aria-label="Contexto da estratégia">
            <div class="works-strategy-context-icon" aria-hidden="true">◆</div>
            <div class="works-strategy-context-main">
                <span>Contrato aberto pela estratégia</span>
                <strong><?= h((string) $estrategiaItem['estrategia_nome']) ?></strong>
                <small>
                    Item #<?= (int) $estrategiaItem['id'] ?> ·
                    <?= h(EstrategiaRepository::statusItemLabel((string) $estrategiaItem['status_operacional'])) ?>
                    <?php if (!empty($estrategiaItem['reserva_expira_em'])): ?> · reserva até <?= h(dataHoraBr($estrategiaItem['reserva_expira_em'])) ?><?php endif; ?>
                </small>
            </div>
            <a class="works-strategy-back" href="/operacao/estrategias.php<?= h(queryString([
                'estrategia_id' => (int) $estrategiaItem['estrategia_id'],
                'status' => $estrategiaStatusRetorno,
            ])) ?>">Voltar à Estratégia</a>
        </section>
    <?php elseif ($estrategiaContextoErro !== null): ?>
        <section class="adaptor-alert adaptor-alert-warning"><strong>Atenção:</strong> <?= h($estrategiaContextoErro) ?></section>
    <?php endif; ?>

    <?php if ($estrategiaSincronizacaoAviso): ?>
        <section class="adaptor-alert adaptor-alert-warning">
            <strong>O atendimento foi salvo.</strong> O vínculo da estratégia não foi concluído automaticamente; a supervisão poderá regenerar ou revisar o item.
        </section>
    <?php endif; ?>

    <?php if ($erro !== null): ?>
        <section class="adaptor-alert adaptor-alert-danger"><strong>Erro:</strong> <?= h($erro) ?></section>
    <?php elseif ($sucesso === 'acionamento'): ?>
        <section class="adaptor-alert adaptor-alert-success">Acionamento registrado com sucesso.<?= $estrategiaItem !== null && !$estrategiaSincronizacaoAviso ? ' O contrato foi concluído na estratégia.' : '' ?></section>
    <?php elseif ($sucesso === 'acordo' || $sucesso === 'promessa'): ?>
        <section class="adaptor-alert adaptor-alert-success">Promessa de pagamento registrada com sucesso. O envio/formalização no credor continua preparado para a próxima etapa.</section>
    <?php elseif ($sucesso === 'contato'): ?>
        <section class="adaptor-alert adaptor-alert-success">Contato atualizado com sucesso.</section>
    <?php elseif ($sucesso === 'endereco'): ?>
        <section class="adaptor-alert adaptor-alert-success">Endereço atualizado com sucesso.</section>
    <?php endif; ?>

    <?php if ($contratoRetiradoCredor): ?>
        <section class="adaptor-alert adaptor-alert-danger works-retired-alert">
            <strong>Retirado pelo credor.</strong>
            <?= h(adaptorContratoRetiradoNota($contrato)) ?> Este contrato fica disponível apenas para consulta histórica.
        </section>
    <?php endif; ?>

    <?php if ($contrato === null): ?>
        <?php if ($termoBusca === '' && $pessoaIdBusca <= 0): ?>
            <section class="adaptor-empty-home" data-works-empty-home>
                <p>Digite no campo superior. Exemplos: <strong>contrato ACM10000007</strong>, <strong>cpf 52998224725</strong>, <strong>telefone 11999998888</strong> ou <strong>nome maria</strong>.</p>
                <small>O resultado sempre abre por contrato, para o operador escolher a dívida correta.</small>
            </section>
        <?php else: ?>
            <section class="adaptor-result-panel" data-works-results-panel>
                <div class="adaptor-panel-title">
                    <strong>⌕ Resultado da busca</strong>
                    <a href="/operacao/works.php">× Cancelar Busca</a>
                </div>
                <p class="adaptor-search-resume"><?php if ($pessoaIdBusca > 0): ?>Contratos vinculados ao cliente selecionado.<?php else: ?>Busca interpretada como <strong><?= h($buscaNormalizada['rotulo']) ?></strong>.<?php endif; ?> Retorno por contrato: se o cliente tiver mais de uma dívida, cada contrato aparece separado.</p>
                <?php if ($resultados === []): ?>
                    <div class="adaptor-empty-box">Nenhum contrato encontrado para esta consulta.</div>
                <?php else: ?>
                    <div class="adaptor-contract-results">
                        <?php foreach ($resultados as $item): ?>
                            <?php
                                $itemUrl = '/operacao/works.php' . queryString([
                                    'contrato_id' => (int) $item['id'],
                                    'q' => $termoBusca,
                                    'tipo' => $tipoBusca,
                                    'refresh_api' => 1,
                                ]);
                                $statusContrato = adaptorContratoStatusOperacional($item);
                                $retiradoCredorItem = adaptorContratoRetiradoCredor($item);
                            ?>
                            <a class="adaptor-result-card <?= $retiradoCredorItem ? 'is-retired' : '' ?>" href="<?= h($itemUrl) ?>">
                                <h2><?= h($item['pessoa_nome']) ?></h2>
                                <p class="adaptor-result-status"><?= h($statusContrato) ?></p>
                                <?php if ($retiradoCredorItem): ?><span class="works-retired-badge">Retirado pelo credor</span><?php endif; ?>
                                <div class="adaptor-mini-strip">
                                    <span>Contrato Número: <strong><?= h($item['codigo_contrato']) ?></strong></span>
                                    <span>Valor da Dívida: <strong><?= h(dinheiro($item['saldo_atual'])) ?></strong></span>
                                    <span><?= ((int) ($item['acordos_ativos'] ?? 0)) > 0 ? 'Com acordo ativo' : 'Sem acordo ativo' ?></span>
                                </div>
                                <span class="adaptor-result-situation <?= h($retiradoCredorItem ? 'adaptor-badge-red' : adaptorBadgeClass($item['status_contrato'] ?? null)) ?>">Situação da Dívida: <?= h($statusContrato) ?></span>
                                <p>Contrato do credor <strong><?= h($item['credor_nome']) ?></strong>. Produto <strong><?= h($item['produto'] ?: '-') ?></strong>. Inscrição <?= documentoFormatado((string) $item['pessoa_documento']) ?>.</p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="adaptor-client-ribbon">
            <nav class="adaptor-tabs" aria-label="Abas do atendimento">
                <?php
                $abas = [
                    'acionamento' => 'Acionamento',
                    'localizacao' => 'Localizacao',
                    'financeiro' => 'Financeiro',
                ];
                ?>
                <?php foreach ($abas as $codigo => $label): ?>
                    <a class="<?= $tab === $codigo ? 'active' : '' ?>" href="<?= h(adaptorTabUrl((int) $contrato['id'], $codigo, $termoBusca, $tipoBusca)) ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </section>

        <section class="works-contract-hero" aria-label="Contrato em foco">
            <div class="works-contract-hero-main">
                <span class="works-contract-eyebrow">Contrato em foco</span>
                <div class="works-copy-line works-copy-line-title">
                    <h1><?= h($contrato['pessoa_nome']) ?></h1>
                    <button type="button" class="works-copy-btn" data-copy-value="<?= h((string) $contrato['pessoa_nome']) ?>" data-copy-label="Nome copiado">Copiar nome</button>
                </div>
                <div class="works-contract-hero-meta">
                    <span class="works-copy-line">
                        <span><?= documentoFormatado((string) $contrato['pessoa_documento']) ?></span>
                        <button type="button" class="works-copy-btn works-copy-btn-small" data-copy-value="<?= h((string) $contrato['pessoa_documento']) ?>" data-copy-label="CPF/CNPJ copiado">Copiar CPF</button>
                    </span>
                    <span>Contrato <?= h($contrato['codigo_contrato']) ?></span>
                    <span><?= h($contrato['credor_nome']) ?></span>
                    <span><?= h($contrato['produto'] ?: 'Produto não informado') ?></span>
                </div>
            </div>
            <div class="works-contract-hero-facts">
                <div>
                    <small>Valor da dívida</small>
                    <strong data-acme-debt><?= h(dinheiro($contrato['saldo_atual'])) ?></strong>
                </div>
                <div>
                    <small>Atraso <span class="works-inline-help" tabindex="0" data-help="Dias de atraso informados pelo credor. Sem carga do dia, o Pactua projeta o atraso; com acordo vigente, congela na data do acordo.">?</span></small>
                    <strong data-acme-delay><?= h($atrasoContrato['label']) ?></strong>
                    <em data-acme-delay-note><?= h($atrasoContrato['nota']) ?></em>
                </div>
                <div>
                    <small>Status <span class="works-inline-help" tabindex="0" data-help="Situação operacional do contrato. Contratos retirados pelo credor permanecem para consulta, mas não liberam nova negociação.">?</span></small>
                    <strong data-acme-debt-status><?= h(adaptorContratoStatusOperacional($contrato)) ?></strong>
                </div>
                <div>
                    <small>Promessa/Acordo <span class="works-inline-help" tabindex="0" data-help="Enquanto existir promessa/acordo operacional ativo, o contrato não pode receber uma segunda negociação simultânea.">?</span></small>
                    <strong data-acme-agreement-status><?= $acordoAtivo !== null ? 'Ativa #' . (int) $acordoAtivo['id'] : 'Sem promessa ativa' ?></strong>
                </div>
            </div>
        </section>

        <?php
        $worksTabView = __DIR__ . '/tabs/' . $tab . '.php';
        if (!is_file($worksTabView)) {
            $worksTabView = __DIR__ . '/tabs/acionamento.php';
        }
        require $worksTabView;
        ?>
    <?php endif; ?>
</main>
        <footer class="app-footer">
            <span>Pactua CRM - operação de cobrança e acordos. Desenvolvido por Wagner G Longo Filho.</span>
            <span>MySQL como fonte oficial; relatórios e integrações auxiliares serão evoluídos por etapas.</span>
        </footer>
    </div>
</div>

</body>
</html>
