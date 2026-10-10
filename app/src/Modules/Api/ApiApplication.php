<?php

declare(strict_types=1);

namespace CRM\Modules\Api;

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Infrastructure\Http\ApiRouter;
use CRM\Infrastructure\Http\CorsMiddleware;
use CRM\Infrastructure\Http\RedisRateLimiter;
use CRM\Modules\Api\Auth\ApiTokenService;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Api\Idempotency\ApiIdempotencyService;
use Throwable;

final class ApiApplication
{
    public function __construct(
        private readonly ApiRouter $router,
        private readonly ApiTokenService $tokens,
        private readonly RedisRateLimiter $rateLimiter,
        private readonly CorsMiddleware $cors,
        private readonly ?ApiIdempotencyService $idempotency = null
    ) {
    }

    public function run(ApiRequest $request): never
    {
        $requestId = $this->requestId($request);
        $corsHeaders = [];
        $rateHeaders = [];
        try {
            $corsHeaders = $this->cors->headers($request);
            if ($request->method() === 'OPTIONS') {
                ApiResponse::noContent()->send($requestId, $corsHeaders);
            }

            $rateHeaders = $this->rateLimiter->consume(
                'global:' . $request->clientIp(),
                max(10, (int) Database::env('API_RATE_LIMIT_REQUESTS', '120')),
                max(1, (int) Database::env('API_RATE_LIMIT_WINDOW', '60')),
                !in_array($request->path(), ['/api/v1/health', '/api/v1/health/components'], true)
            );
            $route = $this->router->match($request->method(), $request->path());
            $metadata = $route['metadata'];
            $context = null;

            if (($metadata['auth'] ?? true) === true) {
                $bearer = $request->bearerToken();
                if ($bearer === '') {
                    throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'Autenticação Bearer obrigatória.', [], [
                        'WWW-Authenticate' => 'Bearer realm="Pactua API"',
                    ]);
                }
                $context = $this->tokens->autenticar($bearer, $request->clientIp(), $request->userAgent());
                $this->autorizar($context, $metadata);
            }

            $handler = $route['handler'];

            // Endpoints marcados 'idempotent' (as acoes de criar
            // acionamento/fechar acordo - ver ApiRoutes) exigem um
            // Idempotency-Key e reproduzem a resposta original em vez de
            // repetir o efeito colateral quando o cliente reenvia a MESMA
            // chave (ex: timeout de rede, cliente reenviando sem saber se
            // a primeira tentativa efetivou). O service existia pronto,
            // implementado, mas nunca era chamado de lugar nenhum.
            $idempotencyId = null;
            if (($metadata['idempotent'] ?? false) === true && $this->idempotency !== null && $context !== null) {
                $resultado = $this->idempotency->iniciar($context, $request, $route['pattern'] ?? $request->path());
                if ($resultado['replay'] !== null) {
                    $resultado['replay']->send($requestId, array_merge($corsHeaders, $rateHeaders));
                }
                $idempotencyId = $resultado['id'];
            }

            try {
                $response = $handler($request, $context, $route['params'], $requestId);
                if (!$response instanceof ApiResponse) {
                    throw new ApiException(500, 'INVALID_CONTROLLER_RESPONSE', 'O controller retornou uma resposta inválida.');
                }
            } catch (Throwable $e) {
                if ($idempotencyId !== null) {
                    $this->idempotency?->falhar($idempotencyId);
                }
                throw $e;
            }

            if ($idempotencyId !== null) {
                $this->idempotency?->concluir($idempotencyId, $response);
            }
            $response->send($requestId, array_merge($corsHeaders, $rateHeaders));
        } catch (ApiException $e) {
            ApiResponse::error($e->status, $e->errorCode, $e->getMessage(), $requestId, $e->details, $e->headers)
                ->send($requestId, array_merge($corsHeaders, $rateHeaders));
        } catch (Throwable $e) {
            error_log('[Pactua API] request_id=' . $requestId . ' exception=' . $this->sanitizarLog($e::class . ': ' . $e->getMessage()));
            ApiResponse::error(500, 'INTERNAL_ERROR', 'Não foi possível concluir a requisição.', $requestId)
                ->send($requestId, array_merge($corsHeaders, $rateHeaders));
        }
    }

    /** @param array<string,mixed> $metadata */
    private function autorizar(AuthContext $context, array $metadata): void
    {
        $scope = trim((string) ($metadata['scope'] ?? 'api'));
        if ($scope !== '' && !$context->possuiScope($scope)) {
            throw new ApiException(403, 'TOKEN_SCOPE_DENIED', 'O token não possui o escopo necessário para esta operação.');
        }
        $permissao = trim((string) ($metadata['permission'] ?? ''));
        if ($permissao !== '' && !$context->possuiPermissao($permissao)) {
            throw new ApiException(403, 'PERMISSION_DENIED', 'O usuário não possui permissão para esta operação.');
        }
    }

    private function requestId(ApiRequest $request): string
    {
        $inbound = trim((string) $request->header('X-Request-ID', ''));
        if ($inbound !== '' && strlen($inbound) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $inbound) === 1) {
            return $inbound;
        }
        return bin2hex(random_bytes(16));
    }

    private function sanitizarLog(string $message): string
    {
        $message = preg_replace('/wrk_api_(?:at|rt)_[A-Fa-f0-9]+/', '[TOKEN_REDACTED]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        return mb_substr($message, 0, 1000);
    }
}
