<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Operação - CRM';
$pageSubtitle = $pageSubtitle ?? 'Área operacional do CRM de cobrança e acordos.';
$activePage = $activePage ?? '';
$usuarioLogado = authModuloDisponivel() ? usuarioAtual() : null;
// Ambiente de homologacao (scripts/homologacao.sh): faixa no topo pra nunca confundir com producao.
$ambienteHomologacao = strtolower((string) \CRM\Config\Database::env('APP_ENV', '')) === 'homolog';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link rel="icon" href="/operacao/assets/icons/logo-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= h(operacaoCssUrl()) ?>">
    <script src="<?= h(operacaoAssetUrl('sidebar.js')) ?>" defer></script>
    <script src="<?= h(operacaoAssetUrl('request-loading.js')) ?>" defer></script>
</head>
<body>
<?php if ($ambienteHomologacao): ?>
    <div style="background:#b45309;color:#fff;text-align:center;padding:6px 12px;font-weight:600;">AMBIENTE DE HOMOLOGAÇÃO · cópia para testes · nada aqui é enviado aos credores nem afeta a produção</div>
<?php endif; ?>
<a href="#main-content" class="skip-link">Pular para o conteúdo principal</a>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main" id="main-content">
        <header class="topbar">
            <div class="topbar-title">
                <span class="eyebrow">Pactua CRM</span>
                <h1><?= h($pageTitle) ?></h1>
                <p><?= h($pageSubtitle) ?></p>
            </div>
            <div class="topbar-actions" aria-label="Resumo da sessão">
                <?php if ($usuarioLogado !== null): ?>
                    <span class="user-pill"><?= h(usuarioAtualNome()) ?> · <?= h((string) ($usuarioLogado['perfil_nome'] ?? 'sem perfil')) ?></span>
                    <a class="source-pill" href="/operacao/trocar_senha.php?redirect=<?= h(rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/operacao/works.php'))) ?>">Trocar senha</a>
                    <a class="source-pill" href="/operacao/logout.php">Sair</a>
                <?php endif; ?>
                <?php if ($ambienteHomologacao): ?>
                    <span class="env-pill" style="background:#b45309;color:#fff;">HOMOLOGAÇÃO</span>
                <?php else: ?>
                    <span class="env-pill">DEV</span>
                <?php endif; ?>
                <span class="source-pill">MySQL oficial</span>
            </div>
        </header>
