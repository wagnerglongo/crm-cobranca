<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Achado (2026-09-22): recalcularQuebrasPorCarencia() só usava
 * dado local/cacheado da ACME (às vezes com dias de defasagem) e quebrava
 * acordo que já tinha sido pago de verdade, porque ninguém tinha ido no
 * CRM marcar a parcela como paga. Zero teste cobria essa lógica antes de
 * hoje. Cobre os 4 dias-gatilho de validação ao vivo (vencimento, D+1,
 * limite da carência, D+1 do limite), a trava da quebra automática
 * enquanto não validado no dia, e a idempotência de validado_credor_em.
 */
final class AcordoOperacionalCarenciaValidacaoTest extends TestCase
{
    private PDO $pdo;
    private AcordoOperacionalRepository $repository;
    private int $credorId;
    private int $pessoaId;
    private int $contratoId;
    private int $acordoId = 0;
    /** @var list<int> */
    private array $parcelasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AcordoOperacionalRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de acordos operacionais indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Carência', 'documento' => '222' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-CARENCIA-' . $sufixo]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->parcelasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais_parcelas WHERE id = :id')->execute(['id' => $id]);
        }
        if ($this->acordoId > 0) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais_auditoria WHERE acordo_operacional_id = :id')->execute(['id' => $this->acordoId]);
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $this->acordoId]);
        }
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    private function criarAcordo(): void
    {
        $this->pdo->prepare("INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, credor_id, status_operacional, valor_acordo, data_promessa_pagamento, quantidade_parcelas, criado_em)
            VALUES (:pessoa_id, :contrato_id, :credor_id, 'formalizado', 300.00, CURRENT_DATE, 1, NOW())")
            ->execute([
                'pessoa_id' => $this->pessoaId,
                'contrato_id' => $this->contratoId,
                'credor_id' => $this->credorId,
            ]);
        $this->acordoId = (int) $this->pdo->lastInsertId();
    }

    private function criarParcela(string $dataVencimento, string $status = 'pendente'): int
    {
        $this->pdo->prepare('INSERT INTO acordos_operacionais_parcelas
                (acordo_operacional_id, numero_parcela, data_vencimento, valor_parcela, status)
            VALUES (:acordo_id, 1, :data, 300.00, :status)')
            ->execute([
                'acordo_id' => $this->acordoId,
                'data' => $dataVencimento,
                'status' => $status,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->parcelasCriadas[] = $id;
        return $id;
    }

    /**
     * Ambiente de teste não tem regra de carência configurada -> carência = 0
     * dias, limite = data de vencimento. Nesse caso situacaoCarencia() já
     * considerava "expirada" só a partir do dia SEGUINTE ao vencimento (no
     * próprio dia do vencimento a situação é "vence_hoje", não expirada) -
     * comportamento anterior, inalterado. O dia do vencimento entra como
     * dia-gatilho de validação (ver testAcordoVencendoHojeEhElegivelParaValidacao),
     * mas quem trava a quebra em si é o D+1, testado abaixo.
     */
    public function testAcordoNoDGaisUmDoVencimentoENaoValidadoAindaNaoQuebra(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d', strtotime('-1 day')));

        $alterados = $this->repository->recalcularQuebrasPorCarencia();

        self::assertSame(0, $alterados, 'D+1 do vencimento é dia-gatilho e ainda não foi validado com o credor: não pode quebrar.');
        $status = $this->pdo->query("SELECT status_operacional FROM acordos_operacionais WHERE id = {$this->acordoId}")->fetchColumn();
        self::assertSame('formalizado', $status);
    }

    public function testAcordoNoDGaisUmDoVencimentoQuebraDepoisDeValidadoNoDia(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d', strtotime('-1 day')));

        $this->repository->registrarValidacaoCredor($this->acordoId);
        $this->repository->recalcularQuebrasPorCarencia();

        $status = $this->pdo->query("SELECT status_operacional FROM acordos_operacionais WHERE id = {$this->acordoId}")->fetchColumn();
        self::assertSame('quebrado', $status, 'Já validado hoje com o credor: pode seguir com a quebra automática normalmente.');
    }

    public function testAcordoVencendoHojeEhElegivelParaValidacao(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d'));

        $elegiveis = $this->repository->acordosElegiveisParaValidacaoCredor();

        self::assertContains($this->acordoId, $elegiveis, 'O próprio dia do vencimento é dia-gatilho de validação, antes de a quebra sequer ser tentada no D+1.');
    }

    public function testAcordoVencidoHaDoisDiasForaDoGatilhoQuebraNormalmenteSemValidacao(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d', strtotime('-2 days')));

        $this->repository->recalcularQuebrasPorCarencia();

        $status = $this->pdo->query("SELECT status_operacional FROM acordos_operacionais WHERE id = {$this->acordoId}")->fetchColumn();
        self::assertSame('quebrado', $status, 'Fora dos dias-gatilho (vencimento/D+1) o comportamento não muda em relação a antes.');
    }

    public function testAcordoVencidoAmanhaJaAparecendoDGaisNaoEhElegivelHoje(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d', strtotime('+1 day')));

        $elegiveis = $this->repository->acordosElegiveisParaValidacaoCredor();

        self::assertNotContains($this->acordoId, $elegiveis);
    }

    public function testAcordoVencidoOntemEhElegivelHojeDMaisUm(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d', strtotime('-1 day')));

        $elegiveis = $this->repository->acordosElegiveisParaValidacaoCredor();

        self::assertContains($this->acordoId, $elegiveis, 'D+1 do vencimento é dia-gatilho de validação.');
    }

    public function testAcordoJaValidadoHojeNaoApareceComoElegivelDeNovo(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d'));

        $this->repository->registrarValidacaoCredor($this->acordoId);
        $elegiveis = $this->repository->acordosElegiveisParaValidacaoCredor();

        self::assertNotContains($this->acordoId, $elegiveis, 'Já validado hoje: não precisa bater no credor de novo na mesma execução/dia.');
    }

    public function testParcelaJaPagaNaoEntraNaValidacaoNemNaQuebra(): void
    {
        $this->criarAcordo();
        $this->criarParcela(date('Y-m-d'), 'paga');

        $elegiveis = $this->repository->acordosElegiveisParaValidacaoCredor();
        $alterados = $this->repository->recalcularQuebrasPorCarencia();

        self::assertNotContains($this->acordoId, $elegiveis, 'Parcela já marcada como paga: já foi "marcado com algum status", não precisa validar.');
        self::assertSame(0, $alterados);
    }

    public function testRegistrarValidacaoCredorGravaCarimboDeHoje(): void
    {
        $this->criarAcordo();

        $this->repository->registrarValidacaoCredor($this->acordoId);

        $validadoEm = $this->pdo->query("SELECT validado_credor_em FROM acordos_operacionais WHERE id = {$this->acordoId}")->fetchColumn();
        self::assertNotEmpty($validadoEm);
        self::assertStringStartsWith(date('Y-m-d'), (string) $validadoEm);
    }
}
