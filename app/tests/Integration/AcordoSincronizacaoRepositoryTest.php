<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoSincronizacaoRepository;
use PHPUnit\Framework\TestCase;

/**
 * AcordoSincronizacaoRepository.php (113 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. Grava o histórico de
 * "sincronizar credor" (ação convertida pra AJAX ontem nesta mesma
 * sessão, em acordos.php) e alimenta o card "Credor / sincronização" do
 * Controle de Acordos via ultimasPorAcordos(). Zero teste cobria isso.
 */
final class AcordoSincronizacaoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AcordoSincronizacaoRepository $repository;
    private int $credorId;
    private int $pessoaId;
    private int $contratoId1;
    private int $contratoId2;
    private int $acordoId1;
    private int $acordoId2;
    /** @var list<int> */
    private array $sincronizacoesCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AcordoSincronizacaoRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de sincronização de acordos indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Sinc', 'documento' => '222' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->contratoId1 = $this->criarContrato();
        $this->contratoId2 = $this->criarContrato();
        $this->acordoId1 = $this->criarAcordo($this->contratoId1);
        $this->acordoId2 = $this->criarAcordo($this->contratoId2);
    }

    protected function tearDown(): void
    {
        foreach ($this->sincronizacoesCriadas as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais_sincronizacoes WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id IN (:a1, :a2)')->execute(['a1' => $this->acordoId1, 'a2' => $this->acordoId2]);
        $this->pdo->prepare('DELETE FROM contratos WHERE id IN (:c1, :c2)')->execute(['c1' => $this->contratoId1, 'c2' => $this->contratoId2]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    private function criarContrato(): int
    {
        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-SINC-' . bin2hex(random_bytes(4))]);
        return (int) $this->pdo->lastInsertId();
    }

    private function criarAcordo(int $contratoId): int
    {
        $this->pdo->prepare("INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, credor_id, status_operacional, valor_acordo, data_promessa_pagamento, criado_em)
            VALUES (:pessoa_id, :contrato_id, :credor_id, 'pendente', 100.00, CURRENT_DATE, NOW())")
            ->execute(['pessoa_id' => $this->pessoaId, 'contrato_id' => $contratoId, 'credor_id' => $this->credorId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function registrar(int $acordoId, string $status, string $mensagem): int
    {
        $id = $this->repository->registrar($acordoId, null, $this->credorId, 'ACME', $status, $mensagem, ['ok' => true], 1, 'Teste');
        $this->sincronizacoesCriadas[] = $id;
        return $id;
    }

    public function testRegistrarEUltimaPorAcordoRetornaOMesmoRegistro(): void
    {
        $this->registrar($this->acordoId1, 'ok', 'Sincronizado com sucesso.');

        $ultima = $this->repository->ultimaPorAcordo($this->acordoId1);
        self::assertSame('ok', $ultima['status']);
        self::assertSame('Sincronizado com sucesso.', $ultima['mensagem']);
    }

    public function testUltimaPorAcordoRetornaARegistradaMaisRecente(): void
    {
        $this->registrar($this->acordoId1, 'ok', 'Primeira sincronização.');
        $this->registrar($this->acordoId1, 'divergencia', 'Segunda sincronização, divergente.');

        $ultima = $this->repository->ultimaPorAcordo($this->acordoId1);
        self::assertSame('divergencia', $ultima['status']);
    }

    public function testUltimaPorAcordoSemHistoricoRetornaNull(): void
    {
        self::assertNull($this->repository->ultimaPorAcordo($this->acordoId1));
    }

    public function testUltimasPorAcordosRetornaSoAMaisRecenteDeCadaAcordo(): void
    {
        $this->registrar($this->acordoId1, 'ok', 'Primeira do acordo 1.');
        $this->registrar($this->acordoId1, 'ok', 'Segunda do acordo 1.');
        $this->registrar($this->acordoId2, 'erro', 'Única do acordo 2.');

        $resultado = $this->repository->ultimasPorAcordos([$this->acordoId1, $this->acordoId2]);

        self::assertCount(2, $resultado);
        self::assertSame('Segunda do acordo 1.', $resultado[$this->acordoId1]['mensagem']);
        self::assertSame('Única do acordo 2.', $resultado[$this->acordoId2]['mensagem']);
    }

    public function testUltimasPorAcordosComListaVaziaRetornaVazioSemErro(): void
    {
        self::assertSame([], $this->repository->ultimasPorAcordos([]));
    }
}
