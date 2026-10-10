<?php

declare(strict_types=1);

define('OPERACAO_AUTH_SKIP', true);

require_once __DIR__ . '/includes/bootstrap.php';

$usuarioIdLogout = usuarioAtualId();
if ($usuarioIdLogout !== null) {
    registrarAuditoriaSistemaUsuario(
        $usuarioIdLogout,
        'auth.logout',
        'usuario',
        $usuarioIdLogout,
        'Logout realizado na área operacional.'
    );
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();

header('Location: /operacao/login.php');
exit;
