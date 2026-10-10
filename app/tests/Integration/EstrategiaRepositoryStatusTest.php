<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\EstrategiaRepository;
use PHPUnit\Framework\TestCase;

/**
 * EstrategiaRepository.php (2.229 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18 (já cobrimos reservarEAbrir()). atualizarStatus()
 * tem guards reais: não pode setar 'rascunho' direto, estratégia
 * arquivada nunca pode reativar. Cobre só as transições SEM ativação
 * (pausar/encerrar/arquivar) de propósito - a transição PARA 'ativa'
 * chama materializar(), que roda uma varredura pesada em contratos
 * (mesmo custo que descartei em DadosGeraisRepositoryEscopoTest.php),
 * desproporcional pro valor marginal aqui.
 */
final class EstrategiaRepositoryStatusTest extends TestCase
{
    private PDO $pdo;
    private EstrategiaRepository $repository;
    private int $estrategiaId;
    private int $pessoaId;
    private int $contratoId;
    private int $itemId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new EstrategiaRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de estratégias indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Estratégia Status', 'documento' => '112' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-ESTRSTATUS-' . bin2hex(random_bytes(4))]);
        $this->contratoId = (int) $this->pdo->lastInsertId();

        // Criada ja' 'ativa' direto no banco - evita passar pelo caminho de
        // ativacao de atualizarStatus() (que materializa a fila, caro).
        $this->pdo->prepare("INSERT INTO estrategias (nome, status, ativada_em) VALUES (:nome, 'ativa', NOW())")
            ->execute(['nome' => 'Estratégia Teste Status ' . bin2hex(random_bytes(4))]);
        $this->estrategiaId = (int) $this->pdo->lastInsertId();

        // Um item vinculado ao credor ACME - sem isso, buscarEstrategia()
        // considera a estrategia "visivel a todos" (mesmo padrao ja' visto
        // em campanhas: NOT EXISTS item OR EXISTS item no escopo).
        $this->pdo->prepare('INSERT INTO estrategia_contratos (estrategia_id, contrato_id, pessoa_id) VALUES (:estrategia_id, :contrato_id, :pessoa_id)')
            ->execute(['estrategia_id' => $this->estrategiaId, 'contrato_id' => $this->contratoId, 'pessoa_id' => $this->pessoaId]);
        $this->itemId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM estrategia_contratos WHERE id = :id')->execute(['id' => $this->itemId]);
        $this->pdo->prepare('DELETE FROM estrategias WHERE id = :id')->execute(['id' => $this->estrategiaId]);
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    public function testPausarUmaEstrategiaAtiva(): void
    {
        $this->repository->atualizarStatus($this->estrategiaId, 'pausada', 1);

        $status = $this->pdo->query("SELECT status, pausada_em FROM estrategias WHERE id = {$this->estrategiaId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('pausada', $status['status']);
        self::assertNotNull($status['pausada_em']);
    }

    public function testEncerrarUmaEstrategiaAtiva(): void
    {
        $this->repository->atualizarStatus($this->estrategiaId, 'encerrada', 1);

        $status = $this->pdo->query("SELECT status, encerrada_em FROM estrategias WHERE id = {$this->estrategiaId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('encerrada', $status['status']);
        self::assertNotNull($status['encerrada_em']);
    }

    public function testNaoPermiteSetarStatusRascunhoDireto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use a edição da estratégia');
        $this->repository->atualizarStatus($this->estrategiaId, 'rascunho', 1);
    }

    public function testEstrategiaArquivadaNuncaPodeReativar(): void
    {
        $this->repository->atualizarStatus($this->estrategiaId, 'arquivada', 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Estratégia arquivada não pode ser reativada');
        $this->repository->atualizarStatus($this->estrategiaId, 'pausada', 1);
    }

    public function testAtualizarStatusDeEstrategiaForaDoEscopoLancaExcecao(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'GLOBEX']);
        $credorOutro = (int) $stmt->fetchColumn();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Estratégia não encontrada no seu escopo.');
        $this->repository->atualizarStatus($this->estrategiaId, 'pausada', 1, ['todos' => false, 'credor_ids' => [$credorOutro], 'carteira_ids' => []]);
    }
}
