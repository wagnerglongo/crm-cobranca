<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\RedisRateLimiter;
use CRM\Modules\Auth\Repositories\AuthRepository;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;

// Porteiro do OpenSearch Dashboards. Chamado SO pelo Apache (script
// docker/apache/works-auth.sh, via 127.0.0.1) - o httpd.conf bloqueia qualquer
// outro IP neste arquivo. Recebe o Basic Auth do navegador (login/senha do Pactua)
// e responde: 204 liberado | 401 credencial invalida | 403 sem a permissao
// 'dashboards.acessar' | 429 excesso de tentativas.
require_once __DIR__ . '/../vendor/autoload.php';

const PERMISSAO_DASHBOARDS = 'dashboards.acessar';

$responder = static function (int $status): never {
    http_response_code($status);
    exit;
};

$header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Basic\s+(\S+)$/i', $header, $m)) {
    $responder(401);
}
$decodificado = base64_decode($m[1], true);
if ($decodificado === false || !str_contains($decodificado, ':')) {
    $responder(401);
}
[$login, $senha] = explode(':', $decodificado, 2);
$login = mb_strtolower(trim($login));
if ($login === '' || $senha === '' || mb_strlen($login) > 180 || strlen($senha) > 1024) {
    $responder(401);
}

try {
    (new RedisRateLimiter())->consume('dashauth:login:' . $login, 20, 60, false);

    $pdo = Database::pdo();
    $auth = new AuthRepository($pdo);
    $auditoria = new AuditoriaSistemaRepository($pdo);

    try {
        $usuario = $auth->autenticar($login, $senha, false);
    } catch (InvalidArgumentException) {
        $auditoria->registrar(null, 'dashboards.login_falha', 'usuario', null, 'Credencial invalida no acesso ao Dashboards.', null, ['login' => $login]);
        $responder(401);
    }

    if (!$auth->usuarioTemPermissao((int) $usuario['id'], PERMISSAO_DASHBOARDS)) {
        $auditoria->registrar((int) $usuario['id'], 'dashboards.acesso_negado', 'usuario', (int) $usuario['id'], 'Usuario sem permissao dashboards.acessar tentou entrar no Dashboards.', null, ['login' => $login]);
        $responder(403);
    }

    $responder(204);
} catch (ApiException $e) {
    $responder($e->status === 429 ? 429 : 503);
} catch (Throwable) {
    $responder(503);
}
