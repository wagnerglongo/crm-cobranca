<?php

declare(strict_types=1);

$usuarioAdminTecnico = usuarioEhAdminOperacao();
$temCallCenter = usuarioPode('clientes.consultar');
$temOperacao = usuarioPode('estrategias.visualizar')
    || usuarioPode('retornos.gerenciar')
    || usuarioPode('atendimentos.visualizar');
$temSupervisao = usuarioPode('estrategias.gerenciar')
    || usuarioPode('acordos.visualizar')
    || usuarioPode('acordos.carteira.visualizar')
    || usuarioPode('preventiva.visualizar');
$temPlanejamento = usuarioPode('relatorios.visualizar');
$temConfiguracao = usuarioPode('tabulacoes.configurar')
    || usuarioPode('usuarios.gerenciar')
    || usuarioPode('cargas.visualizar')
    || usuarioPode('cargas.gerenciar_rotinas')
    || usuarioPode('seguranca.visualizar')
    || usuarioPode('credores.visualizar')
    || usuarioPode('credores.editar')
    || usuarioPode('acordos.configurar_carencia')
    || usuarioPode('integracoes.visualizar')
    || usuarioPode('auditoria.visualizar')
    || $usuarioAdminTecnico;

$gruposAtivos = [
    'operacao' => ['estrategias_operacao', 'retornos', 'atendimentos'],
    'supervisao' => ['estrategias_supervisao', 'acordos_controle'],
    'dados' => ['dashboard', 'dados_gerais', 'relatorios_csv'],
    'administracao' => ['config_resultados', 'config_motivos_inadimplencia', 'usuarios', 'perfis', 'config_credores', 'integracoes', 'cargas', 'rotinas_carga', 'homologacao', 'seguranca', 'auditoria'],
    'suporte' => ['ajuda'],
];

$grupoAberto = static fn (string $grupo): bool => in_array($activePage, $gruposAtivos[$grupo] ?? [], true);

$navIcon = static function (string $nome): string {
    $paths = match ($nome) {
        'callcenter' => '<path d="M4 13a8 8 0 0 1 16 0v5a2 2 0 0 1-2 2h-2v-7h4"/><path d="M4 13v7H2a2 2 0 0 1-2-2v-5h4" transform="translate(2 0)"/><path d="M9 20h6"/>',
        'operacao' => '<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="18" cy="12" r="2"/><circle cx="15" cy="18" r="2"/>',
        'strategy' => '<path d="M4 19V5m0 0h10l-2 3 2 3H4"/><path d="M9 19h11"/>',
        'agenda' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/><path d="m9 16 2 2 4-4"/>',
        'queue' => '<path d="M6 4h12M6 10h12M6 16h8"/><circle cx="18" cy="16" r="3"/>',
        'return' => '<path d="M9 7 4 12l5 5"/><path d="M5 12h9a5 5 0 0 1 5 5v2"/>',
        'history' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'supervision' => '<path d="M4 20v-8m6 8V4m6 16v-5m4 5V8"/><path d="M2 20h20"/>',
        'agreements' => '<path d="M8 12h8m-4-4v8"/><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/>',
        'campaign' => '<path d="m3 11 15-6v14L3 13v-2Z"/><path d="M7 14v5a2 2 0 0 0 2 2h2"/><path d="M18 9a3 3 0 0 1 0 6"/>',
        'data' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'chart' => '<path d="M4 20V10m6 10V4m6 16v-7"/><path d="M2 20h20"/>',
        'report' => '<path d="M5 3h14v18H5z"/><path d="M8 15v3m4-7v7m4-10v10"/>',
        'admin' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'settings' => '<path d="M4 6h10M18 6h2M4 12h2m4 0h10M4 18h7m4 0h5"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="13" cy="18" r="2"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8m2 3a6 6 0 0 1 4 6"/>',
        'creditor' => '<path d="M3 21h18M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6M9 10h.01M15 10h.01"/>',
        'integration' => '<path d="M8 12h8M6 8l-4 4 4 4m12-8 4 4-4 4"/><path d="M10 5h4M10 19h4"/>',
        'server' => '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01M11 7h7M11 17h7"/>',
        'upload' => '<path d="M12 16V3m0 0-4 4m4-4 4 4"/><path d="M4 14v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>',
        'sync' => '<path d="M4 12a8 8 0 0 1 14-5.3L20 8"/><path d="M20 4v4h-4"/><path d="M20 12a8 8 0 0 1-14 5.3L4 16"/><path d="M4 20v-4h4"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-5"/>',
        'audit' => '<path d="M9 4h6l1 3H8l1-3Z"/><path d="M6 7h12v14H6z"/><path d="M9 12h6m-6 4h4"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 1 1 3.7 2c-.9.6-1.5 1-1.5 2.5M12 17h.01"/>',
        default => '<circle cx="12" cy="12" r="9"/>',
    };

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
};

$renderNavLink = static function (
    string $href,
    string $page,
    string $titulo,
    string $subtitulo,
    string $icone
) use ($activePage, $navIcon): void {
    $ativo = $activePage === $page;
    ?>
    <a class="nav-link nav-link-child<?= $ativo ? ' active' : '' ?>" href="<?= h($href) ?>" <?= $ativo ? 'aria-current="page"' : '' ?>>
        <span class="nav-icon" aria-hidden="true"><?= $navIcon($icone) ?></span>
        <span class="nav-text"><strong><?= h($titulo) ?></strong><small><?= h($subtitulo) ?></small></span>
    </a>
    <?php
};
?>
<aside class="sidebar" aria-label="Navegação principal da operação">
    <div class="brand brand-logo-holder">
        <a class="brand-logo-link" href="/operacao/works.php" aria-label="Pactua Call Center">
            <img class="brand-logo-full" src="/operacao/assets/icons/logo-horizontal-escuro.svg" alt="Pactua Call Center" loading="eager">
            <img class="brand-logo-compact" src="/operacao/assets/icons/logo-icon.svg" alt="Pactua" loading="eager">
        </a>
    </div>

    <button class="sidebar-collapse-toggle" type="button" data-sidebar-toggle aria-label="Minimizar menu lateral" aria-expanded="true">
        <span class="sidebar-toggle-icon" aria-hidden="true">☰</span>
        <span class="sidebar-toggle-text">Minimizar menu</span>
    </button>

    <nav class="sidebar-nav sidebar-nav-modern" aria-label="Menu da operação">
        <?php if ($temCallCenter): ?>
            <a class="nav-link nav-link-callcenter<?= $activePage === 'works_atendimento' ? ' active' : '' ?>" href="/operacao/works.php" <?= $activePage === 'works_atendimento' ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon nav-icon-callcenter" aria-hidden="true"><?= $navIcon('callcenter') ?></span>
                <span class="nav-text">
                    <strong>Call Center</strong>
                    <small>buscar, acionar e negociar</small>
                </span>
                <span class="nav-live-dot" aria-hidden="true"></span>
            </a>
        <?php endif; ?>

        <?php if ($temOperacao): ?>
            <?php $aberto = $grupoAberto('operacao'); ?>
            <section class="nav-group<?= $aberto ? ' is-open' : '' ?>" data-nav-group="operacao">
                <button class="nav-group-toggle" type="button" aria-expanded="<?= $aberto ? 'true' : 'false' ?>" aria-controls="nav-group-operacao">
                    <span class="nav-icon" aria-hidden="true"><?= $navIcon('operacao') ?></span>
                    <span class="nav-group-copy"><strong>Operação</strong><small>filas e rotina diária</small></span>
                    <span class="nav-group-chevron" aria-hidden="true">⌄</span>
                </button>
                <div class="nav-group-body" id="nav-group-operacao">
                    <?php if (usuarioPode('estrategias.visualizar')): ?>
                        <?php $renderNavLink('/operacao/estrategias.php', 'estrategias_operacao', 'Estratégia', 'contratos priorizados', 'strategy'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('retornos.gerenciar')): ?>
                        <?php $renderNavLink('/operacao/retornos.php', 'retornos', 'Retornos', 'próximos acionamentos', 'return'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('atendimentos.visualizar')): ?>
                        <?php $renderNavLink('/operacao/atendimentos.php', 'atendimentos', 'Atendimentos', 'histórico operacional', 'history'); ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($temSupervisao): ?>
            <?php $aberto = $grupoAberto('supervisao'); ?>
            <section class="nav-group<?= $aberto ? ' is-open' : '' ?>" data-nav-group="supervisao">
                <button class="nav-group-toggle" type="button" aria-expanded="<?= $aberto ? 'true' : 'false' ?>" aria-controls="nav-group-supervisao">
                    <span class="nav-icon" aria-hidden="true"><?= $navIcon('supervision') ?></span>
                    <span class="nav-group-copy"><strong>Supervisão</strong><small>controle e direcionamento</small></span>
                    <span class="nav-group-chevron" aria-hidden="true">⌄</span>
                </button>
                <div class="nav-group-body" id="nav-group-supervisao">
                    <?php if (usuarioPode('estrategias.gerenciar')): ?>
                        <?php $renderNavLink('/operacao/supervisao_estrategias.php', 'estrategias_supervisao', 'Estratégias', 'criar, filtrar e priorizar', 'strategy'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('acordos.visualizar') || usuarioPode('acordos.carteira.visualizar') || usuarioPode('preventiva.visualizar')): ?>
                        <?php $renderNavLink('/operacao/acordos.php', 'acordos_controle', 'Controle de Acordos', 'vencimentos e ciclo de vida', 'agreements'); ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($temPlanejamento): ?>
            <?php $aberto = $grupoAberto('dados'); ?>
            <section class="nav-group<?= $aberto ? ' is-open' : '' ?>" data-nav-group="dados">
                <button class="nav-group-toggle" type="button" aria-expanded="<?= $aberto ? 'true' : 'false' ?>" aria-controls="nav-group-dados">
                    <span class="nav-icon" aria-hidden="true"><?= $navIcon('data') ?></span>
                    <span class="nav-group-copy"><strong>Dados</strong><small>exportação e análise</small></span>
                    <span class="nav-group-chevron" aria-hidden="true">⌄</span>
                </button>
                <div class="nav-group-body" id="nav-group-dados">
                    <?php $renderNavLink('/operacao/dashboard.php', 'dashboard', 'Dashboard', 'funil de atendimento', 'chart'); ?>
                    <?php $renderNavLink('/operacao/dados_gerais.php', 'dados_gerais', 'Dados Gerais / Mailing', 'segmentar base e exportar', 'data'); ?>
                    <?php $renderNavLink('/operacao/relatorios.php', 'relatorios_csv', 'Relatórios CSV', 'acionamentos e carteira', 'report'); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($temConfiguracao): ?>
            <?php $aberto = $grupoAberto('administracao'); ?>
            <section class="nav-group<?= $aberto ? ' is-open' : '' ?>" data-nav-group="administracao">
                <button class="nav-group-toggle" type="button" aria-expanded="<?= $aberto ? 'true' : 'false' ?>" aria-controls="nav-group-administracao">
                    <span class="nav-icon" aria-hidden="true"><?= $navIcon('admin') ?></span>
                    <span class="nav-group-copy"><strong>Administração</strong><small>regras, acessos e serviços</small></span>
                    <span class="nav-group-chevron" aria-hidden="true">⌄</span>
                </button>
                <div class="nav-group-body" id="nav-group-administracao">
                    <?php if (usuarioPode('tabulacoes.configurar')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/resultados_atendimento.php', 'config_resultados', 'Tabulações', 'resultados de atendimento', 'settings'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('motivos_inadimplencia.configurar')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/motivos_inadimplencia.php', 'config_motivos_inadimplencia', 'Motivos de Inadimplência', 'perguntado no CPC', 'settings'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('usuarios.gerenciar')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/usuarios.php', 'usuarios', 'Usuários', 'login, perfil e escopo', 'users'); ?>
                        <?php $renderNavLink('/operacao/configuracoes/perfis.php', 'perfis', 'Perfis', 'grupos e permissões', 'shield'); ?>
                    <?php endif; ?>
                    <?php if ($usuarioAdminTecnico || usuarioPode('credores.visualizar') || usuarioPode('credores.editar') || usuarioPode('acordos.configurar_carencia')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/credores.php', 'config_credores', 'Credores', 'regras e parâmetros', 'creditor'); ?>
                    <?php endif; ?>
                    <?php if ($usuarioAdminTecnico || usuarioPode('integracoes.visualizar')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/integracoes.php', 'integracoes', 'Integrações', 'saúde e diagnóstico', 'integration'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('cargas.visualizar')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/cargas.php', 'cargas', 'Cargas', 'status, erros e reprocesso', 'upload'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('cargas.gerenciar_rotinas')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/rotinas_carga.php', 'rotinas_carga', 'Rotinas de Carga', 'coleta automática via SFTP', 'sync'); ?>
                    <?php endif; ?>
                    <?php if ($usuarioAdminTecnico): ?>
                        <?php $renderNavLink('/operacao/configuracoes/homologacao.php', 'homologacao', 'Homologação', 'cópia semanal do banco', 'server'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('seguranca.visualizar')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/seguranca.php', 'seguranca', 'Segurança', 'login e bloqueios', 'shield'); ?>
                    <?php endif; ?>
                    <?php if (usuarioPode('auditoria.visualizar')): ?>
                        <?php $renderNavLink('/operacao/configuracoes/auditoria.php', 'auditoria', 'Auditoria', 'ações sensíveis do CRM', 'audit'); ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php $aberto = $grupoAberto('suporte'); ?>
        <section class="nav-group nav-group-support<?= $aberto ? ' is-open' : '' ?>" data-nav-group="suporte">
            <button class="nav-group-toggle" type="button" aria-expanded="<?= $aberto ? 'true' : 'false' ?>" aria-controls="nav-group-suporte">
                <span class="nav-icon" aria-hidden="true"><?= $navIcon('help') ?></span>
                <span class="nav-group-copy"><strong>Suporte</strong><small>ajuda e orientação</small></span>
                <span class="nav-group-chevron" aria-hidden="true">⌄</span>
            </button>
            <div class="nav-group-body" id="nav-group-suporte">
                <?php $renderNavLink('/operacao/ajuda.php', 'ajuda', 'Ajuda', 'guia rápido e glossário', 'help'); ?>
            </div>
        </section>
    </nav>
</aside>
