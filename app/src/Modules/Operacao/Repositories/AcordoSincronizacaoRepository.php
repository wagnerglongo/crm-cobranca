<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use PDO;
use Throwable;

final class AcordoSincronizacaoRepository
{
    private ?bool $disponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->disponivel !== null) {
            return $this->disponivel;
        }
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'acordos_operacionais_sincronizacoes'");
            $this->disponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->disponivel = false;
        }
        return $this->disponivel;
    }

    /** @param array<string,mixed> $snapshot */
    public function registrar(
        int $acordoId,
        ?int $contratoId,
        ?int $credorId,
        string $integracao,
        string $status,
        ?string $mensagem,
        array $snapshot,
        ?int $usuarioId,
        ?string $usuarioNome
    ): int {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $stmt = $this->pdo->prepare('INSERT INTO acordos_operacionais_sincronizacoes (
                acordo_operacional_id, contrato_id, credor_id, integracao, status, mensagem,
                snapshot, usuario_id, usuario_nome
            ) VALUES (
                :acordo_id, :contrato_id, :credor_id, :integracao, :status, :mensagem,
                :snapshot, :usuario_id, :usuario_nome
            )');
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $stmt->bindValue('contrato_id', $contratoId, $contratoId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue('credor_id', $credorId, $credorId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue('integracao', mb_substr(trim($integracao), 0, 60));
        $stmt->bindValue('status', mb_substr(trim($status), 0, 30));
        $stmt->bindValue('mensagem', $mensagem !== null ? mb_substr(trim($mensagem), 0, 500) : null, $mensagem !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->bindValue('snapshot', $json !== false ? $json : null, $json !== false ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue('usuario_id', $usuarioId, $usuarioId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue('usuario_nome', $usuarioNome !== null ? mb_substr(trim($usuarioNome), 0, 150) : null, $usuarioNome !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function ultimaPorAcordo(int $acordoId): ?array
    {
        if (!$this->moduloDisponivel() || $acordoId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM acordos_operacionais_sincronizacoes WHERE acordo_operacional_id = :id ORDER BY id DESC LIMIT 1');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param list<int> $acordoIds @return array<int,array<string,mixed>> */
    public function ultimasPorAcordos(array $acordoIds): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $acordoIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT s.*
            FROM acordos_operacionais_sincronizacoes s
            INNER JOIN (
                SELECT acordo_operacional_id, MAX(id) AS max_id
                FROM acordos_operacionais_sincronizacoes
                WHERE acordo_operacional_id IN (' . $placeholders . ')
                GROUP BY acordo_operacional_id
            ) x ON x.max_id = s.id';
        $stmt = $this->pdo->prepare($sql);
        foreach ($ids as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['acordo_operacional_id']] = $row;
        }
        return $out;
    }
}
