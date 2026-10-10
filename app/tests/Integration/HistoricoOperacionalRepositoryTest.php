<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\HistoricoOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * HistoricoOperacionalRepository.php (414 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. timelineCliente() mescla 7
 * fontes diferentes (atendimentos, retornos, acordos, campanhas,
 * reservas, fila, notas manuais) em uma única timeline ordenada por
 * data - o usort()+array_slice() é o ponto de maior risco (fácil
 * inverter a ordem ou truncar antes de ordenar). Zero teste cobria a
 * mesclagem nem o escopo por credor.
 */
final class HistoricoOperacionalRepositoryTest extends TestCase
{
    private PDO $pdo;
    private HistoricoOperacionalRepository $repository;
    private int $credorAcme;
    private int $credorOutro;
    private int $pessoaId;
    private int $contratoId;
    private int $atendimentoId;
    private int $acordoId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new HistoricoOperacionalRepository($this->pdo);

        $this->credorAcme = $this->credorId('ACME');
        $this->credorOutro = $this->credorId('GLOBEX');

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Historico', 'documento' => '999' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorAcme, 'codigo' => 'TESTE-HIST-' . bin2hex(random_bytes(4))]);
        $this->contratoId = (int) $this->pdo->lastInsertId();

        // Atendimento ha' 2 dias, acordo ha' 1 dia - o acordo deve vir PRIMEIRO
        // na timeline (mais recente primeiro).
        $this->pdo->prepare("INSERT INTO atendimentos_historico (pessoa_id, contrato_id, resultado, criado_em)
            VALUES (:pessoa_id, :contrato_id, 'atendeu', DATE_SUB(NOW(), INTERVAL 2 DAY))")
            ->execute(['pessoa_id' => $this->pessoaId, 'contrato_id' => $this->contratoId]);
        $this->atendimentoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, credor_id, status_operacional, valor_acordo, data_promessa_pagamento, criado_em)
            VALUES (:pessoa_id, :contrato_id, :credor_id, 'formalizado', 100.00, CURRENT_DATE, DATE_SUB(NOW(), INTERVAL 1 DAY))")
            ->execute(['pessoa_id' => $this->pessoaId, 'contrato_id' => $this->contratoId, 'credor_id' => $this->credorAcme]);
        $this->acordoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $this->acordoId]);
        $this->pdo->prepare('DELETE FROM atendimentos_historico WHERE id = :id')->execute(['id' => $this->atendimentoId]);
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    private function credorId(string $codigo): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => $codigo]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "credor {$codigo} não encontrado para o teste");
        return (int) $id;
    }

    private function escopoSo(int $credorId): array
    {
        return ['todos' => false, 'credor_ids' => [$credorId], 'carteira_ids' => []];
    }

    public function testTimelineMesclaEventosDeFontesDiferentesOrdenadosPorDataDesc(): void
    {
        $timeline = $this->repository->timelineCliente($this->pessoaId, $this->escopoSo($this->credorAcme));

        $tipos = array_column($timeline, 'tipo');
        self::assertContains('atendimento', $tipos);
        self::assertContains('acordo', $tipos);

        $indiceAcordo = array_search('acordo', $tipos, true);
        $indiceAtendimento = array_search('atendimento', $tipos, true);
        self::assertLessThan($indiceAtendimento, $indiceAcordo, 'o acordo (mais recente) deve vir antes do atendimento na timeline');
    }

    public function testTimelineComEscopoErradoNaoRetornaEventosDoCliente(): void
    {
        $timeline = $this->repository->timelineCliente($this->pessoaId, $this->escopoSo($this->credorOutro));

        $origensIds = array_map(static fn (array $e): int => (int) $e['origem_id'], array_filter($timeline, static fn (array $e): bool => $e['tipo'] === 'acordo'));
        self::assertNotContains($this->acordoId, $origensIds);
    }

    public function testResumoClienteContaAtendimentosEAcordosCorretamente(): void
    {
        $resumo = $this->repository->resumoCliente($this->pessoaId, $this->escopoSo($this->credorAcme));

        self::assertSame(1, $resumo['atendimentos']);
        self::assertSame(1, $resumo['acordos']);
    }

    public function testResumoClienteComEscopoErradoZeraAsContagens(): void
    {
        $resumo = $this->repository->resumoCliente($this->pessoaId, $this->escopoSo($this->credorOutro));

        self::assertSame(0, $resumo['atendimentos']);
        self::assertSame(0, $resumo['acordos']);
    }
}
