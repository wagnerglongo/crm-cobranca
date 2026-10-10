<?php

declare(strict_types=1);

use CRM\Config\Database;

define('OPERACAO_AUTH_SKIP', true);
require_once __DIR__ . '/../operacao/includes/bootstrap.php';

if (filter_var(Database::env('API_DOCS_ENABLED', 'true'), FILTER_VALIDATE_BOOL) !== true) {
    http_response_code(404);
    exit;
}

$exigirLogin = filter_var(Database::env('API_DOCS_REQUIRE_LOGIN', 'false'), FILTER_VALIDATE_BOOL) === true;
if ($exigirLogin) {
    requireOperacaoLogin();
    requireOperacaoAlgumaPermissao([
        'api.documentacao.visualizar',
        'integracoes.visualizar',
        'usuarios.gerenciar',
    ]);
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'self'");
}
