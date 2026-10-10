<?php

declare(strict_types=1);

$configNavItems = [];

if (usuarioPode('tabulacoes.configurar')) {
    $configNavItems[] = ['codigo' => 'TB', 'active' => 'config_resultados', 'label' => 'Tabulações', 'href' => '/operacao/configuracoes/resultados_atendimento.php'];
}
if (usuarioPode('motivos_inadimplencia.configurar')) {
    $configNavItems[] = ['codigo' => 'MI', 'active' => 'config_motivos_inadimplencia', 'label' => 'Motivos de Inadimplência', 'href' => '/operacao/configuracoes/motivos_inadimplencia.php'];
}
if (usuarioPode('usuarios.gerenciar')) {
    $configNavItems[] = ['codigo' => 'US', 'active' => 'usuarios', 'label' => 'Usuários', 'href' => '/operacao/configuracoes/usuarios.php'];
    $configNavItems[] = ['codigo' => 'PF', 'active' => 'perfis', 'label' => 'Perfis', 'href' => '/operacao/configuracoes/perfis.php'];
}
if (usuarioEhAdminOperacao() || usuarioPode('credores.visualizar') || usuarioPode('credores.editar') || usuarioPode('acordos.configurar_carencia')) {
    $configNavItems[] = ['codigo' => 'CR', 'active' => 'config_credores', 'label' => 'Credores', 'href' => '/operacao/configuracoes/credores.php'];
}
if (usuarioEhAdminOperacao() || usuarioPode('integracoes.visualizar')) {
    $configNavItems[] = ['codigo' => 'IN', 'active' => 'integracoes', 'label' => 'Integrações', 'href' => '/operacao/configuracoes/integracoes.php'];
}
if (usuarioPode('cargas.visualizar')) {
    $configNavItems[] = ['codigo' => 'CG', 'active' => 'cargas', 'label' => 'Cargas', 'href' => '/operacao/configuracoes/cargas.php'];
}
if (usuarioEhAdminOperacao() || usuarioPode('cargas.gerenciar_rotinas')) {
    $configNavItems[] = ['codigo' => 'RC', 'active' => 'rotinas_carga', 'label' => 'Rotinas de Carga', 'href' => '/operacao/configuracoes/rotinas_carga.php'];
}
if (usuarioEhAdminOperacao()) {
    $configNavItems[] = ['codigo' => 'HM', 'active' => 'homologacao', 'label' => 'Homologação', 'href' => '/operacao/configuracoes/homologacao.php'];
}
if (usuarioPode('seguranca.visualizar')) {
    $configNavItems[] = ['codigo' => 'SG', 'active' => 'seguranca', 'label' => 'Segurança', 'href' => '/operacao/configuracoes/seguranca.php'];
}
$configNavAtual = null;
foreach ($configNavItems as $configNavItem) {
    if ($configNavItem['active'] === $activePage) {
        $configNavAtual = $configNavItem;
        break;
    }
}
?>
<section class="config-hub" aria-label="Central de configurações">
    <div class="config-hub-heading">
        <span aria-hidden="true">CFG</span>
        <div>
            <small>Administração</small>
            <strong><?= $configNavAtual !== null ? h($configNavAtual['label']) : 'Central de configurações' ?></strong>
        </div>
    </div>
    <?php if (count($configNavItems) > 1): ?>
        <nav class="config-hub-switch" aria-label="Trocar de módulo de configuração">
            <label for="config-hub-select">Ir para</label>
            <select id="config-hub-select" onchange="if(this.value) window.location.href=this.value;">
                <option value="">Escolher módulo…</option>
                <?php foreach ($configNavItems as $configNavItem): ?>
                    <option value="<?= h($configNavItem['href']) ?>" <?= $activePage === $configNavItem['active'] ? 'selected' : '' ?>><?= h($configNavItem['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </nav>
    <?php endif; ?>
</section>
