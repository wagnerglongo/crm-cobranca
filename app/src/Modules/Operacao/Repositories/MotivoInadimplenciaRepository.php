<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Motivo da inadimplência (migration 095, requisito de 2026-09-23):
 * pergunta obrigatória quando o acionamento é CPC - o operador teve
 * contato com a pessoa certa, então por que o acordo não fechou. Lista
 * configurável pela tela Administração > Motivos de Inadimplência.
 */
final class MotivoInadimplenciaRepository
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
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'motivos_inadimplencia'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @return list<array<string,mixed>> */
    public function listarTodos(): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT id, nome, ativo, ordem, criado_em, atualizado_em
            FROM motivos_inadimplencia
            ORDER BY ativo DESC, ordem ASC, nome ASC');

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return list<array<string,mixed>> */
    public function listarAtivos(): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT id, nome, ordem
            FROM motivos_inadimplencia
            WHERE ativo = 1
            ORDER BY ordem ASC, nome ASC');

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, nome, ativo, ordem, criado_em, atualizado_em
            FROM motivos_inadimplencia
            WHERE id = :id
            LIMIT 1');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function salvar(array $dados): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('A tabela motivos_inadimplencia ainda não existe. Execute a migration 095.');
        }

        $id = $this->normalizarId($dados['id'] ?? null);
        $nome = $this->normalizarNome($dados['nome'] ?? null);
        $ordem = $this->normalizarOrdem($dados['ordem'] ?? null);
        $ativo = $this->normalizarFlag($dados['ativo'] ?? null);

        if ($id !== null && $this->buscar($id) === null) {
            throw new InvalidArgumentException('Motivo de inadimplência não encontrado para edição.');
        }

        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO motivos_inadimplencia (nome, ativo, ordem)
                    VALUES (:nome, :ativo, :ordem)');
            } else {
                $stmt = $this->pdo->prepare('UPDATE motivos_inadimplencia
                    SET nome = :nome, ativo = :ativo, ordem = :ordem
                    WHERE id = :id');
                $stmt->bindValue('id', $id, PDO::PARAM_INT);
            }

            $stmt->bindValue('nome', $nome);
            $stmt->bindValue('ativo', $ativo, PDO::PARAM_INT);
            $stmt->bindValue('ordem', $ordem, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException('Já existe um motivo de inadimplência com esse nome.');
            }

            throw $e;
        }

        return $id ?? (int) $this->pdo->lastInsertId();
    }

    public function alternarAtivo(int $id, bool $ativo): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('A tabela motivos_inadimplencia ainda não existe.');
        }

        if ($this->buscar($id) === null) {
            throw new InvalidArgumentException('Motivo de inadimplência não encontrado.');
        }

        $stmt = $this->pdo->prepare('UPDATE motivos_inadimplencia SET ativo = :ativo WHERE id = :id');
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function normalizarId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit((string) $value)) {
            throw new InvalidArgumentException('ID inválido.');
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function normalizarNome(mixed $value): string
    {
        $nome = trim((string) ($value ?? ''));
        if ($nome === '') {
            throw new InvalidArgumentException('Informe o nome do motivo.');
        }

        if (mb_strlen($nome) > 120) {
            $nome = mb_substr($nome, 0, 120);
        }

        return $nome;
    }

    private function normalizarOrdem(mixed $value): int
    {
        $ordem = trim((string) ($value ?? ''));
        if ($ordem === '') {
            return 100;
        }

        if (!ctype_digit($ordem)) {
            throw new InvalidArgumentException('A ordem deve ser um número inteiro positivo.');
        }

        return max(0, min((int) $ordem, 65535));
    }

    private function normalizarFlag(mixed $value): int
    {
        $valor = (string) ($value ?? '0');
        return in_array($valor, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
    }
}
