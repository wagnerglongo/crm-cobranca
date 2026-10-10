<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\CarteiraAcordosRepository;
use PHPUnit\Framework\TestCase;

/**
 * CarteiraAcordosRepository.php (837 linhas) - continuação da auditoria
 * de cobertura de 2026-09-17. atualizarPorIds() é o coração do cron
 * atualizar_carteira_acordos.php (a cada 15min, ao qual eu mesmo
 * adicionei alerta de falha nesta sessão) - decide se uma parcela venceu
 * e se um acordo foi quitado automaticamente. Zero teste cobria essa
 * lógica antes; uma quebra silenciosa aqui significa acordo não
 * quitando quando devia, ou parcela não marcando vencida.
 */
final class CarteiraAcordosRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CarteiraAcordosRepository $repository;
    private int $credorId;
    private int $pessoaId;
    private int $contratoId;
    private int $acordoId = 0;
    /** @var list<int> */
    private array $parcelasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CarteiraAcordosRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de carteira de acordos indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Carteira', 'documento' => '111' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-CART-' . $sufixo]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->parcelasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais_parcelas WHERE id = :id')->execute(['id' => $id]);
        }
        if ($this->acordoId > 0) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $this->acordoId]);
        }
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    private function criarAcordo(int $quantidadeParcelas): void
    {
        $this->pdo->prepare("INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, credor_id, status_operacional, valor_acordo, data_promessa_pagamento, quantidade_parcelas, criado_em)
            VALUES (:pessoa_id, :contrato_id, :credor_id, 'formalizado', 300.00, CURRENT_DATE, :qtd, NOW())")
            ->execute([
                'pessoa_id' => $this->pessoaId,
                'contrato_id' => $this->contratoId,
                'credor_id' => $this->credorId,
                'qtd' => $quantidadeParcelas,
            ]);
        $this->acordoId = (int) $this->pdo->lastInsertId();
    }

    private function criarParcela(int $numero, string $dataVencimento, string $status, ?float $valorPago = null): int
    {
        $this->pdo->prepare('INSERT INTO acordos_operacionais_parcelas
                (acordo_operacional_id, numero_parcela, data_vencimento, valor_parcela, status, valor_pago)
            VALUES (:acordo_id, :numero, :data, 100.00, :status, :valor_pago)')
            ->execute([
                'acordo_id' => $this->acordoId,
                'numero' => $numero,
                'data' => $dataVencimento,
                'status' => $status,
                'valor_pago' => $valorPago,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->parcelasCriadas[] = $id;
        return $id;
    }

    public function testParcelaPendenteVencidaVirouVencidaAposAtualizar(): void
    {
        $this->criarAcordo(1);
        $parcelaId = $this->criarParcela(1, date('Y-m-d', strtotime('-5 days')), 'pendente');

        $this->repository->atualizarPorIds([$this->acordoId]);

        $status = $this->pdo->query("SELECT status FROM acordos_operacionais_parcelas WHERE id = {$parcelaId}")->fetchColumn();
        self::assertSame('vencida', $status);
    }

    public function testParcelaPendenteFuturaNaoMudaStatus(): void
    {
        $this->criarAcordo(1);
        $parcelaId = $this->criarParcela(1, date('Y-m-d', strtotime('+5 days')), 'pendente');

        $this->repository->atualizarPorIds([$this->acordoId]);

        $status = $this->pdo->query("SELECT status FROM acordos_operacionais_parcelas WHERE id = {$parcelaId}")->fetchColumn();
        self::assertSame('pendente', $status);
    }

    public function testAcordoComTodasAsParcelasPagasQuitaAutomaticamente(): void
    {
        $this->criarAcordo(2);
        $this->criarParcela(1, date('Y-m-d', strtotime('-10 days')), 'paga', 100.00);
        $this->criarParcela(2, date('Y-m-d', strtotime('-5 days')), 'paga', 100.00);

        $resultado = $this->repository->atualizarPorIds([$this->acordoId]);

        self::assertSame(1, $resultado['quitados']);
        $status = $this->pdo->query("SELECT status_operacional FROM acordos_operacionais WHERE id = {$this->acordoId}")->fetchColumn();
        self::assertSame('cumprido', $status);
    }

    public function testAcordoComParcelaAbertaNaoQuitaAutomaticamente(): void
    {
        $this->criarAcordo(2);
        $this->criarParcela(1, date('Y-m-d', strtotime('-10 days')), 'paga', 100.00);
        $this->criarParcela(2, date('Y-m-d', strtotime('+5 days')), 'pendente');

        $resultado = $this->repository->atualizarPorIds([$this->acordoId]);

        self::assertSame(0, $resultado['quitados']);
        $status = $this->pdo->query("SELECT status_operacional FROM acordos_operacionais WHERE id = {$this->acordoId}")->fetchColumn();
        self::assertSame('formalizado', $status);
    }

    public function testAcordoInexistenteEIgnoradoSemErro(): void
    {
        $resultado = $this->repository->atualizarPorIds([999999999]);

        self::assertSame(0, $resultado['processados']);
        self::assertSame(0, $resultado['erros']);
    }
}
