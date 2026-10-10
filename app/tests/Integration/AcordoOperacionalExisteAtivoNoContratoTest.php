<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Achado (2026-09-29): "tem acordo e mesmo assim tá simulando" -
 * o Financeiro ACME só escondia a simulação quando o acordo estava
 * confirmado PELA ACME (contratos.payload_extra.acme_acordo). Um acordo
 * criado só no CRM (ex.: "Criar acordo" na ficha do cliente, sem
 * integração com o credor) nunca passava por ali, então a simulação
 * continuava liberada mesmo com um acordo pendente/formalizado aqui
 * dentro. existeAcordoAtivoNoContrato() cobre esse caso (usado no gate do
 * Financeiro ACME em works_financeiro_acme.php).
 */
final class AcordoOperacionalExisteAtivoNoContratoTest extends TestCase
{
    private PDO $pdo;
    private AcordoOperacionalRepository $repository;
    private int $contratoId = 0;
    private int $outroContratoId = 0;
    /** @var list<int> */
    private array $acordos = [];
    /** @var list<int> */
    private array $contratos = [];
    private int $pessoaId = 0;
    private int $credorId = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AcordoOperacionalRepository($this->pdo);
        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de acordos indisponível nesta instância.');
        }

        $sufixo = bin2hex(random_bytes(4));
        $this->credorId = (int) $this->pdo->query("SELECT id FROM credores WHERE codigo = 'ACME' LIMIT 1")->fetchColumn();

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:n, :d)')
            ->execute(['n' => 'Pessoa Teste Acordo Ativo', 'd' => '557' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:p, :c, :codigo)')
            ->execute(['p' => $this->pessoaId, 'c' => $this->credorId, 'codigo' => 'TESTE-ACORDO-ATIVO-' . $sufixo]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
        $this->contratos[] = $this->contratoId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:p, :c, :codigo)')
            ->execute(['p' => $this->pessoaId, 'c' => $this->credorId, 'codigo' => 'TESTE-ACORDO-OUTRO-' . $sufixo]);
        $this->outroContratoId = (int) $this->pdo->lastInsertId();
        $this->contratos[] = $this->outroContratoId;
    }

    protected function tearDown(): void
    {
        foreach ($this->acordos as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->contratos as $id) {
            $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    private function criarAcordo(int $contratoId, string $status): int
    {
        $this->pdo->prepare("INSERT INTO acordos_operacionais (pessoa_id, contrato_id, status_operacional, valor_acordo, data_promessa_pagamento, quantidade_parcelas, origem, criado_em)
            VALUES (:p, :c, :status, 100, CURRENT_DATE, 1, 'manual', NOW())")
            ->execute(['p' => $this->pessoaId, 'c' => $contratoId, 'status' => $status]);
        $id = (int) $this->pdo->lastInsertId();
        $this->acordos[] = $id;
        return $id;
    }

    public function testContratoSemAcordoRetornaFalse(): void
    {
        self::assertFalse($this->repository->existeAcordoAtivoNoContrato($this->contratoId));
    }

    public function testAcordoPendenteRetornaTrue(): void
    {
        $this->criarAcordo($this->contratoId, 'pendente');
        self::assertTrue($this->repository->existeAcordoAtivoNoContrato($this->contratoId));
    }

    public function testAcordoFormalizadoRetornaTrue(): void
    {
        $this->criarAcordo($this->contratoId, 'formalizado');
        self::assertTrue($this->repository->existeAcordoAtivoNoContrato($this->contratoId));
    }

    public function testAcordoQuebradoOuCumpridoNaoContam(): void
    {
        $this->criarAcordo($this->contratoId, 'quebrado');
        self::assertFalse($this->repository->existeAcordoAtivoNoContrato($this->contratoId), 'acordo quebrado não impede nova negociação');
    }

    public function testAcordoDeOutroContratoNaoInterfere(): void
    {
        $this->criarAcordo($this->outroContratoId, 'formalizado');
        self::assertFalse($this->repository->existeAcordoAtivoNoContrato($this->contratoId));
        self::assertTrue($this->repository->existeAcordoAtivoNoContrato($this->outroContratoId));
    }
}
