<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\RedisRateLimiter;
use CRM\Modules\Api\Auth\ApiTokenRepository;
use CRM\Modules\Api\Auth\ApiTokenService;
use CRM\Modules\Operacao\Repositories\RelatorioApiTokenRepository;
use CRM\Modules\Operacao\Repositories\RelatorioOperacionalRepository;
use CRM\Modules\Operacao\Services\RelatorioCsvExporter;

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

define('OPERACAO_AUTH_SKIP', true);
require_once __DIR__ . '/../../includes/bootstrap.php';

function worksRelatorioApiToken(): string
{
    $bearer = worksRelatorioApiBearerToken();
    if ($bearer !== '') {
        return $bearer;
    }
    $headerKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($headerKey !== '') {
        return $headerKey;
    }
    return trim((string) ($_GET['token'] ?? ''));
}

function worksRelatorioApiBearerToken(): string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim((string) $matches[1]);
    }
    return '';
}

/** @return never */
function worksRelatorioApiErro(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'erro' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return never */
function worksRelatorioApiExecutar(string $tipo): never
{
    try {
        if (!authModuloDisponivel()) {
            worksRelatorioApiErro(503, 'Módulo de autenticação indisponível.');
        }

        $tokenValue = worksRelatorioApiToken();
        if ($tokenValue === '') {
            worksRelatorioApiErro(401, 'Token de relatório não informado.');
        }

        // Endpoint legado sem rate limit nenhum, diferente da API v1 e do
        // login web (ambos com RedisRateLimiter). Ainda aceita o token via
        // ?token= na URL (legado, some em log de proxy/navegador) por
        // compatibilidade com integracoes existentes, mas agora limitado
        // por token igual as demais portas de entrada.
        $limit = max(1, (int) Database::env('RELATORIO_API_RATE_LIMIT_REQUESTS', '30'));
        $window = max(1, (int) Database::env('RELATORIO_API_RATE_LIMIT_WINDOW', '60'));
        (new RedisRateLimiter())->consume('relatorio_csv:token:' . hash('sha256', $tokenValue), $limit, $window, false);

        $pdo = operacaoPdo();
        $tokenRepo = null;
        $tokenId = 0;
        $tokenOrigem = 'relatorio_legado';
        $bearerValue = worksRelatorioApiBearerToken();

        if ($bearerValue !== '' && str_starts_with($bearerValue, 'wrk_api_at_')) {
            $apiTokenRepo = new ApiTokenRepository($pdo);
            $apiTokens = new ApiTokenService($apiTokenRepo, authRepository());
            $context = $apiTokens->autenticar(
                $bearerValue,
                \CRM\Infrastructure\Http\ProxyConfiavel::ipCliente($_SERVER),
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            );
            if (!$context->possuiScope('relatorios.read')) {
                worksRelatorioApiErro(403, 'O token não possui escopo para relatórios.');
            }
            $usuarioId = $context->usuarioId();
            $usuario = $context->usuario;
            $tokenId = $context->tokenId;
            $tokenOrigem = 'api_v1';
        } else {
            $tokenRepo = new RelatorioApiTokenRepository($pdo);
            if (!$tokenRepo->moduloDisponivel()) {
                worksRelatorioApiErro(503, 'Execute a migration 078_relatorios_csv_api.sql.');
            }
            $token = $tokenRepo->validar($tokenValue);
            if ($token === null) {
                worksRelatorioApiErro(401, 'Token inválido, expirado ou revogado.');
            }
            $usuarioId = (int) ($token['usuario_id'] ?? 0);
            $usuario = $usuarioId > 0 ? authRepository()->buscarUsuarioSessao($usuarioId) : null;
            $tokenId = (int) $token['id'];
        }

        if ($usuario === null || (string) ($usuario['status'] ?? '') !== 'ativo') {
            worksRelatorioApiErro(403, 'Usuário do token está inativo ou não existe.');
        }
        if (!authRepository()->usuarioTemPermissao($usuarioId, 'relatorios.visualizar')) {
            worksRelatorioApiErro(403, 'Usuário do token não possui permissão para relatórios.');
        }

        $escopo = authRepository()->escopoUsuarioPorAcao($usuarioId, 'consultar');
        $filtros = [
            'data_inicio' => $_GET['data_inicio'] ?? null,
            'data_fim' => $_GET['data_fim'] ?? null,
            'credor_id' => $_GET['credor_id'] ?? null,
        ];

        if ($tokenRepo instanceof RelatorioApiTokenRepository) {
            $tokenRepo->registrarUso($tokenId);
        }
        registrarAuditoriaSistemaUsuario($usuarioId, 'relatorios.api.consulta', 'relatorio', $tipo, 'Consulta read-only via token de relatório.', null, [
            'data_inicio' => $filtros['data_inicio'],
            'data_fim' => $filtros['data_fim'],
            'credor_id' => $filtros['credor_id'],
            'token_id' => $tokenId,
            'token_origem' => $tokenOrigem,
        ]);

        $repo = new RelatorioOperacionalRepository(operacaoPdo());
        $exporter = new RelatorioCsvExporter($repo);
        $exporter->enviar($tipo, $filtros, $escopo);
    } catch (ApiException $e) {
        worksRelatorioApiErro($e->status, $e->getMessage());
    } catch (Throwable $e) {
        worksRelatorioApiErro(400, $e->getMessage());
    }
}
