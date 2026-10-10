<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Auth;

final class AuthContext
{
    /**
     * @param array<string,mixed> $usuario
     * @param list<string> $permissoes
     * @param array{todos:bool,credor_ids:list<int>,carteira_ids:list<int>} $escopo
     * @param list<string> $tokenScopes
     */
    public function __construct(
        public readonly int $tokenId,
        public readonly ?string $familiaId,
        public readonly string $tokenTipo,
        public readonly array $usuario,
        public readonly array $permissoes,
        public readonly array $escopo,
        public readonly array $tokenScopes
    ) {
    }

    public function usuarioId(): int
    {
        return (int) ($this->usuario['id'] ?? 0);
    }

    public function possuiPermissao(string $permissao): bool
    {
        return in_array('*', $this->permissoes, true) || in_array($permissao, $this->permissoes, true);
    }

    public function possuiScope(string $scope): bool
    {
        return in_array('*', $this->tokenScopes, true) || in_array($scope, $this->tokenScopes, true);
    }
}
