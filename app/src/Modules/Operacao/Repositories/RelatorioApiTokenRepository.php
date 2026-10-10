<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class RelatorioApiTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'relatorios_api_tokens'");
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{token:string,id:int,prefixo:string,expira_em:?string} */
    public function criar(int $usuarioId, string $nome, int $validadeDias = 365): array
    {
        if (!$this->moduloDisponivel()) {
            throw new RuntimeException('Execute a migration 078_relatorios_csv_api.sql antes de gerar tokens.');
        }
        if ($usuarioId <= 0) {
            throw new RuntimeException('Usuário inválido para o token.');
        }
        $nome = trim($nome);
        if ($nome === '') {
            throw new RuntimeException('Informe um nome para identificar o token.');
        }
        if (mb_strlen($nome) > 120) {
            throw new RuntimeException('O nome do token deve ter no máximo 120 caracteres.');
        }
        $validadeDias = max(0, min($validadeDias, 3650));

        $token = 'wrk_rpt_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);
        $prefixo = substr($token, 0, 16);
        $expiraEm = $validadeDias > 0 ? (new DateTimeImmutable('now'))->modify('+' . $validadeDias . ' days')->format('Y-m-d H:i:s') : null;

        $stmt = $this->pdo->prepare('INSERT INTO relatorios_api_tokens
            (usuario_id, nome, token_hash, token_prefixo, ativo, expira_em, criado_em)
            VALUES (:usuario_id, :nome, :token_hash, :token_prefixo, 1, :expira_em, NOW())');
        $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('token_hash', $hash);
        $stmt->bindValue('token_prefixo', $prefixo);
        $stmt->bindValue('expira_em', $expiraEm);
        $stmt->execute();

        return [
            'token' => $token,
            'id' => (int) $this->pdo->lastInsertId(),
            'prefixo' => $prefixo,
            'expira_em' => $expiraEm,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listarPorUsuario(int $usuarioId): array
    {
        if (!$this->moduloDisponivel() || $usuarioId <= 0) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, usuario_id, nome, token_prefixo, ativo, criado_em, expira_em, ultimo_uso_em, revogado_em
            FROM relatorios_api_tokens
            WHERE usuario_id = :usuario_id
            ORDER BY ativo DESC, id DESC');
        $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function revogar(int $tokenId, int $usuarioId): void
    {
        if (!$this->moduloDisponivel() || $tokenId <= 0 || $usuarioId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE relatorios_api_tokens
            SET ativo = 0, revogado_em = NOW()
            WHERE id = :id AND usuario_id = :usuario_id');
        $stmt->bindValue('id', $tokenId, PDO::PARAM_INT);
        $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<string,mixed>|null */
    public function validar(string $token): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }
        $token = trim($token);
        if ($token === '' || strlen($token) < 24) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare('SELECT * FROM relatorios_api_tokens
            WHERE token_hash = :hash
              AND ativo = 1
              AND revogado_em IS NULL
              AND (expira_em IS NULL OR expira_em > NOW())
            LIMIT 1');
        $stmt->bindValue('hash', $hash);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function registrarUso(int $tokenId): void
    {
        if (!$this->moduloDisponivel() || $tokenId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE relatorios_api_tokens SET ultimo_uso_em = NOW() WHERE id = :id');
        $stmt->bindValue('id', $tokenId, PDO::PARAM_INT);
        $stmt->execute();
    }
}
