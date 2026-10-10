<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\RedisRateLimiter;

define('OPERACAO_AUTH_SKIP', true);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Login - Pactua Call Center';
$erro = null;
$sessaoMotivo = trim((string) ($_GET['sessao'] ?? ($GLOBALS['operacao_sessao_expirada_motivo'] ?? '')));
$sessaoMensagem = match ($sessaoMotivo) {
    'tempo' => 'Sua sessão atingiu o limite de duração configurado. Entre novamente para continuar.',
    'novo_dia' => 'Por segurança, o Pactua exige um novo login a cada dia.',
    'atualizacao' => 'A política de sessão foi atualizada. Faça login novamente para continuar.',
    default => null,
};
$redirect = (string) ($_GET['redirect'] ?? ($_POST['redirect'] ?? '/operacao/works.php'));
if ($redirect === '' || (!str_starts_with($redirect, '/operacao/') && !str_starts_with($redirect, '/api/docs'))) {
    $redirect = '/operacao/works.php';
}

if (authModuloDisponivel() && usuarioAtual() !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validarCsrf();
        $loginInformado = (string) ($_POST['login'] ?? '');

        // A API ja tinha rate limit por IP+login no /api/v1/auth/token
        // (RedisRateLimiter); o login web nao tinha nenhum - so bloqueava
        // uma CONTA existente apos N falhas, sem limitar por IP nem
        // proteger contra tentativas com logins inexistentes. Mesmos
        // buckets/limites do endpoint da API, pra credential stuffing ser
        // limitado igual nos dois caminhos.
        //
        // strict=false (diferente da API): esta e a tela que os 10
        // operadores usam pra entrar todo dia. Preferimos deixar de
        // limitar numa eventual falha do Redis do que travar o login de
        // todo mundo por causa disso - a API pode se dar ao luxo de falhar
        // fechado, o login operacional diario nao.
        $limit = max(1, (int) Database::env('API_AUTH_RATE_LIMIT_REQUESTS', '10'));
        $window = max(1, (int) Database::env('API_AUTH_RATE_LIMIT_WINDOW', '60'));
        $rateLimiter = new RedisRateLimiter();
        $rateLimiter->consume('auth:ip:' . ApiRequest::fromGlobals()->clientIp(), $limit, $window, false);
        $rateLimiter->consume('auth:login:' . mb_strtolower($loginInformado), max(3, (int) floor($limit / 2)), max($window, 300), false);

        $usuario = authRepository()->autenticar($loginInformado, (string) ($_POST['senha'] ?? ''));
        iniciarSessaoOperacionalAutenticada((int) $usuario['id'], (string) $usuario['nome']);
        registrarAuditoriaSistemaUsuario(
            (int) $usuario['id'],
            'auth.login_sucesso',
            'usuario',
            (int) $usuario['id'],
            'Login realizado na área operacional.',
            null,
            ['login' => $loginInformado, 'redirect' => $redirect]
        );
        header('Location: ' . $redirect);
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        registrarAuditoriaSistemaUsuario(
            null,
            'auth.login_falha',
            'usuario',
            null,
            'Tentativa de login inválida na área operacional.',
            null,
            ['login' => (string) ($_POST['login'] ?? ''), 'motivo' => $erro]
        );
    }
}

http_response_code($erro === null ? 200 : 401);
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
</head>
<body class="login-body">
<a href="#login-form" class="skip-link">Pular para o formulário</a>
<main class="login-shell">
    <section class="login-card">
        <div class="brand login-brand login-brand-logo-holder">
            <img class="login-logo-horizontal" src="/operacao/assets/icons/logo-horizontal.svg" alt="Pactua Call Center" loading="eager">
            
        </div>

        <h1>Entrar no Pactua Call Center</h1>
        <p class="muted">Use seu usuário operacional para consultar clientes, registrar atendimentos, acordos e campanhas conforme suas permissões.</p>

        <?php if (!authModuloDisponivel()): ?>
            <div class="error boxed-message">
                <strong>Módulo de usuários indisponível.</strong>
                <p>Execute a migration <code>019_usuarios_permissoes_escopo.sql</code> antes de ativar o login.</p>
            </div>
        <?php else: ?>
            <?php if ($sessaoMensagem !== null): ?>
                <div class="hint boxed-message"><strong>Sessão encerrada.</strong> <?= h($sessaoMensagem) ?></div>
            <?php endif; ?>
            <?php if ($erro !== null): ?>
                <div class="error boxed-message"><?= h($erro) ?></div>
            <?php endif; ?>

            <form method="post" class="stack-form" id="login-form">
                <?= authCsrfInput() ?>
                <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
                <label>
                    <span>Usuário ou e-mail</span>
                    <input type="text" name="login" autocomplete="username" autofocus required>
                </label>
                <label>
                    <span>Senha</span>
                    <input type="password" name="senha" autocomplete="current-password" required>
                </label>
                <button type="submit">Entrar</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
