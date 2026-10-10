<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Controllers;

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Infrastructure\Http\RedisRateLimiter;
use CRM\Modules\Api\Auth\ApiTokenService;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Auth\Repositories\AuthRepository;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;
use InvalidArgumentException;

final class AuthController
{
    public function __construct(
        private readonly AuthRepository $auth,
        private readonly ApiTokenService $tokens,
        private readonly RedisRateLimiter $rateLimiter,
        private readonly AuditoriaSistemaRepository $auditoria
    ) {
    }

    /** @param array<string,string> $params */
    public function token(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $body = $request->json();
        $login = trim((string) ($body['login'] ?? ''));
        $senha = (string) ($body['senha'] ?? '');
        $details = [];
        if ($login === '' || mb_strlen($login) > 180) {
            $details['login'] = ['Informe um login ou e-mail válido.'];
        }
        if ($senha === '' || strlen($senha) > 1024) {
            $details['senha'] = ['Informe a senha.'];
        }
        if ($details !== []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'Os dados enviados são inválidos.', $details);
        }

        $limit = max(1, (int) Database::env('API_AUTH_RATE_LIMIT_REQUESTS', '10'));
        $window = max(1, (int) Database::env('API_AUTH_RATE_LIMIT_WINDOW', '60'));
        $headers = $this->rateLimiter->consume('auth:ip:' . $request->clientIp(), $limit, $window, true);
        $headers = array_merge($headers, $this->rateLimiter->consume(
            'auth:login:' . mb_strtolower($login),
            max(3, (int) floor($limit / 2)),
            max($window, 300),
            true
        ));

        if (!$this->auth->moduloDisponivel()) {
            throw new ApiException(503, 'AUTH_MODULE_UNAVAILABLE', 'O módulo de usuários do CRM está indisponível.');
        }

        try {
            $usuario = $this->auth->autenticar($login, $senha);
            $nome = trim((string) ($body['nome'] ?? 'Sessão API'));
            $nome = $nome === '' ? 'Sessão API' : mb_substr($nome, 0, 120);
            $resultado = $this->tokens->emitirParaUsuario($usuario, $request->clientIp(), $request->userAgent(), $nome);
            $tokenId = (int) ($resultado['_access_token_id'] ?? 0);
            $prefixo = mb_substr((string) ($resultado['access_token'] ?? ''), 0, 24);
            unset($resultado['_access_token_id']);

            $this->auditoria->registrar(
                (int) $usuario['id'],
                'api.auth.token_emitido',
                'api_token',
                $tokenId,
                'Token de acesso da API emitido por usuário e senha.',
                null,
                [
                    'token_prefixo' => $prefixo,
                    'scopes' => $resultado['scopes'] ?? [],
                    'expires_in' => $resultado['expires_in'] ?? null,
                    'request_id' => $requestId,
                ]
            );
            return ApiResponse::json($resultado, 200, $headers);
        } catch (ApiException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            $this->auditoria->registrar(
                null,
                'api.auth.login_falha',
                'usuario',
                null,
                'Tentativa inválida de emissão de token da API.',
                null,
                ['login' => $login, 'motivo' => $e->getMessage(), 'request_id' => $requestId]
            );
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Usuário ou senha inválidos, ou acesso indisponível.', [], [
                'WWW-Authenticate' => 'Bearer realm="Pactua API"',
            ]);
        }
    }

    /** @param array<string,string> $params */
    public function me(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        if ($context === null) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'Autenticação Bearer obrigatória.');
        }
        return ApiResponse::json($this->tokens->respostaUsuario($context));
    }
}
