<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Auth;

use PDO;
use RuntimeException;
use Throwable;

final class ApiTokenRepository
{
    private ?bool $moduloDisponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'api_tokens'");
            $this->moduloDisponivel = $stmt !== false && (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }
        return $this->moduloDisponivel;
    }

    /** @param list<string> $escopos */
    public function inserirAccessToken(
        int $usuarioId,
        string $nome,
        string $tipo,
        string $token,
        array $escopos,
        ?string $familiaId,
        string $expiraEm,
        string $ip,
        ?string $userAgent
    ): int {
        $json = json_encode(array_values(array_unique($escopos)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Falha ao serializar escopos do token.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO api_tokens (
                usuario_id, nome, tipo, token_hash, token_prefixo, escopos, familia_id,
                criado_em, expira_em, criado_ip, criado_user_agent
            ) VALUES (
                :usuario_id, :nome, :tipo, :token_hash, :token_prefixo, :escopos, :familia_id,
                NOW(), :expira_em, :criado_ip, :criado_user_agent
            )');
        $stmt->execute([
            'usuario_id' => $usuarioId,
            'nome' => mb_substr(trim($nome), 0, 120),
            'tipo' => $tipo,
            'token_hash' => hash('sha256', $token),
            'token_prefixo' => mb_substr($token, 0, 24),
            'escopos' => $json,
            'familia_id' => $familiaId,
            'expira_em' => $expiraEm,
            'criado_ip' => mb_substr($ip, 0, 64),
            'criado_user_agent' => $this->textoOpcional($userAgent, 255),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function buscarAccessToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_tokens WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function registrarUsoAccess(int $tokenId, string $ip, ?string $userAgent): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens
            SET ultimo_uso_em = NOW(), ultimo_ip = :ip, ultimo_user_agent = :user_agent
            WHERE id = :id
              AND (ultimo_uso_em IS NULL OR ultimo_uso_em < DATE_SUB(NOW(), INTERVAL 1 MINUTE))');
        $stmt->execute([
            'id' => $tokenId,
            'ip' => mb_substr($ip, 0, 64),
            'user_agent' => $this->textoOpcional($userAgent, 255),
        ]);
    }

    public function revogarAccess(int $tokenId, ?int $atorUsuarioId, string $motivo): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens
            SET revogado_em = COALESCE(revogado_em, NOW()),
                revogado_por_usuario_id = COALESCE(revogado_por_usuario_id, :ator),
                motivo_revogacao = COALESCE(motivo_revogacao, :motivo)
            WHERE id = :id');
        $stmt->execute([
            'id' => $tokenId,
            'ator' => $atorUsuarioId,
            'motivo' => mb_substr(trim($motivo), 0, 255),
        ]);
    }

    public function revogarFamilia(string $familiaId, ?int $atorUsuarioId, string $motivo): void
    {
        if ($familiaId === '') {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE api_tokens
            SET revogado_em = COALESCE(revogado_em, NOW()),
                revogado_por_usuario_id = COALESCE(revogado_por_usuario_id, :ator),
                motivo_revogacao = COALESCE(motivo_revogacao, :motivo)
            WHERE familia_id = :familia_id');
        $stmt->execute([
            'familia_id' => $familiaId,
            'ator' => $atorUsuarioId,
            'motivo' => mb_substr(trim($motivo), 0, 255),
        ]);
    }

    private function textoOpcional(?string $value, int $limite): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limite);
    }
}
