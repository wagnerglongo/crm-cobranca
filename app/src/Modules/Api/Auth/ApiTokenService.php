<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Auth;

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Modules\Auth\Repositories\AuthRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ApiTokenService
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly AuthRepository $auth
    ) {
    }

    /**
     * @param array<string,mixed> $usuario
     * @return array<string,mixed>
     */
    public function emitirParaUsuario(array $usuario, string $ip, ?string $userAgent, string $nome = 'Sessão API'): array
    {
        if (!$this->tokens->moduloDisponivel()) {
            throw new ApiException(503, 'API_TOKEN_MODULE_UNAVAILABLE', 'Execute a migration 081_api_corporativa_foundation.sql.');
        }
        $usuarioId = (int) ($usuario['id'] ?? 0);
        if ($usuarioId <= 0 || (string) ($usuario['status'] ?? '') !== 'ativo') {
            throw new ApiException(403, 'USER_NOT_ACTIVE', 'O usuário não está ativo para utilizar a API.');
        }
        if ((int) ($usuario['forcar_troca_senha'] ?? 0) === 1) {
            throw new ApiException(403, 'PASSWORD_CHANGE_REQUIRED', 'Altere a senha no CRM antes de emitir um token da API.');
        }

        return $this->emitirAccessToken($usuario, $ip, $userAgent, $nome);
    }

    public function autenticar(string $token, string $ip, ?string $userAgent): AuthContext
    {
        if (!$this->tokens->moduloDisponivel()) {
            throw new ApiException(503, 'API_TOKEN_MODULE_UNAVAILABLE', 'O módulo de tokens da API está indisponível.');
        }
        if (!str_starts_with($token, 'wrk_api_at_') || strlen($token) < 48) {
            throw new ApiException(401, 'INVALID_TOKEN', 'Token ausente, inválido, expirado ou revogado.', [], [
                'WWW-Authenticate' => 'Bearer realm="Pactua API", error="invalid_token"',
            ]);
        }
        $row = $this->tokens->buscarAccessToken($token);
        if ($row === null || $row['revogado_em'] !== null || $this->dataExpirada((string) $row['expira_em'])) {
            throw new ApiException(401, 'INVALID_TOKEN', 'Token ausente, inválido, expirado ou revogado.', [], [
                'WWW-Authenticate' => 'Bearer realm="Pactua API", error="invalid_token"',
            ]);
        }

        $usuarioId = (int) $row['usuario_id'];
        $usuario = $this->auth->buscarUsuarioSessao($usuarioId);
        if ($usuario === null || (string) ($usuario['status'] ?? '') !== 'ativo') {
            throw new ApiException(401, 'INVALID_TOKEN', 'Token ausente, inválido, expirado ou revogado.');
        }
        if ((int) ($usuario['forcar_troca_senha'] ?? 0) === 1 || $this->senhaAlteradaDepois($usuario, (string) $row['criado_em'])) {
            $familiaId = trim((string) ($row['familia_id'] ?? ''));
            if ($familiaId !== '') {
                $this->tokens->revogarFamilia($familiaId, $usuarioId, 'Credencial do usuário alterada');
            } else {
                $this->tokens->revogarAccess((int) $row['id'], $usuarioId, 'Credencial do usuário alterada');
            }
            throw new ApiException(401, 'TOKEN_REVOKED_BY_CREDENTIAL_CHANGE', 'O token foi invalidado por uma alteração de credencial.');
        }

        $scopes = $this->decodeScopes($row['escopos'] ?? null);
        $permissoes = $this->auth->permissoesUsuario($usuarioId);
        $escopo = $this->auth->escopoUsuarioPorAcao($usuarioId, 'consultar');
        $this->tokens->registrarUsoAccess((int) $row['id'], $ip, $userAgent);

        return new AuthContext(
            (int) $row['id'],
            $row['familia_id'] === null ? null : (string) $row['familia_id'],
            (string) $row['tipo'],
            $usuario,
            $permissoes,
            $escopo,
            $scopes
        );
    }

    /** @return array<string,mixed> */
    public function respostaUsuario(AuthContext $context): array
    {
        return [
            'usuario' => $this->usuarioPublico($context->usuario),
            'permissoes' => $context->permissoes,
            'escopo' => $context->escopo,
            'token' => [
                'tipo' => $context->tokenTipo,
                'scopes' => $context->tokenScopes,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $usuario
     * @return array<string,mixed>
     */
    private function emitirAccessToken(
        array $usuario,
        string $ip,
        ?string $userAgent,
        string $nome
    ): array {
        $usuarioId = (int) $usuario['id'];
        $accessTtl = max(300, min(86400, (int) Database::env('API_ACCESS_TOKEN_TTL', '1800')));
        $accessToken = 'wrk_api_at_' . bin2hex(random_bytes(32));
        $agora = $this->agora();
        $accessExpiraEm = $agora->modify('+' . $accessTtl . ' seconds');
        $accessExpira = $accessExpiraEm->format('Y-m-d H:i:s');
        $scopes = $this->scopesDoUsuario($usuarioId);

        $accessId = $this->tokens->inserirAccessToken(
            $usuarioId,
            $nome,
            'usuario',
            $accessToken,
            $scopes,
            null,
            $accessExpira,
            $ip,
            $userAgent
        );

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'expires_at' => $accessExpiraEm->format(DATE_ATOM),
            'scopes' => $scopes,
            'usuario' => $this->usuarioPublico($usuario),
            '_access_token_id' => $accessId,
        ];
    }

    /** @return list<string> */
    private function scopesDoUsuario(int $usuarioId): array
    {
        $scopes = ['api'];
        if ($this->auth->usuarioTemPermissao($usuarioId, 'relatorios.visualizar')) {
            $scopes[] = 'relatorios.read';
        }
        if ($this->auth->usuarioTemPermissao($usuarioId, 'api.tokens.gerenciar')) {
            $scopes[] = 'tokens.manage';
        }
        return $scopes;
    }

    /** @return list<string> */
    private function decodeScopes(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('strval', $decoded))));
    }

    /** @param array<string,mixed> $usuario @return array<string,mixed> */
    private function usuarioPublico(array $usuario): array
    {
        return [
            'id' => (int) ($usuario['id'] ?? 0),
            'nome' => (string) ($usuario['nome'] ?? ''),
            'login' => (string) ($usuario['login'] ?? ''),
            'email' => ($usuario['email'] ?? null) === null ? null : (string) $usuario['email'],
            'perfil' => (string) ($usuario['perfil_codigo'] ?? ''),
            'perfil_nome' => (string) ($usuario['perfil_nome'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $usuario */
    private function senhaAlteradaDepois(array $usuario, string $tokenCriadoEm): bool
    {
        $alterada = trim((string) ($usuario['senha_alterada_em'] ?? ''));
        if ($alterada === '') {
            return false;
        }
        try {
            $timezone = new DateTimeZone((string) Database::env('APP_TIMEZONE', 'America/Sao_Paulo'));
            return new DateTimeImmutable($alterada, $timezone) > new DateTimeImmutable($tokenCriadoEm, $timezone);
        } catch (Throwable) {
            return true;
        }
    }

    private function dataExpirada(string $value): bool
    {
        try {
            $timezone = new DateTimeZone((string) Database::env('APP_TIMEZONE', 'America/Sao_Paulo'));
            return new DateTimeImmutable($value, $timezone) <= new DateTimeImmutable('now', $timezone);
        } catch (Throwable) {
            return true;
        }
    }

    private function agora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone((string) Database::env('APP_TIMEZONE', 'America/Sao_Paulo')));
    }
}
