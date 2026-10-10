<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\PromessaRepository;
use PHPUnit\Framework\TestCase;

/**
 * PromessaRepository.php (523 linhas) - continuação da auditoria de
 * cobertura de 2026-09-17. registrar() tem o mesmo guard
 * anti-cross-tenant já visto em AtendimentoRepository (contrato precisa
 * pertencer à pessoa da promessa). Zero teste cobria isso.
 */
final class PromessaRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PromessaRepository $repository;
    private int $pessoaId;
    private int $contratoId;
    /** @var list<int> */
    private array $promessasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new PromessaRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de promessas indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = (int) $stmt->fetchColumn();

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Promessa', 'documento' => '000' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-PROM-' . $sufixo]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->promessasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM promessas_pagamento WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    /** @param array<string,mixed> $overrides */
    private function dadosValidos(array $overrides = []): array
    {
        return array_merge([
            'contrato_id' => $this->contratoId,
            'valor_promessa' => '150,00',
            'data_promessa' => date('Y-m-d', strtotime('+3 days')),
        ], $overrides);
    }

    public function testRegistrarComDadosValidosFunciona(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos());
        $this->promessasCriadas[] = $id;

        self::assertGreaterThan(0, $id);
    }

    public function testRegistrarComContratoDeOutraPessoaLancaExcecao(): void
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Outra Pessoa Promessa', 'documento' => '123' . random_int(10000000, 99999999)]);
        $outraPessoaId = (int) $this->pdo->lastInsertId();

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('não pertence a este cliente');
            $id = $this->repository->registrar($outraPessoaId, $this->dadosValidos());
            $this->promessasCriadas[] = $id;
        } finally {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $outraPessoaId]);
        }
    }

    public function testRegistrarSemValorPromessaLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['valor_promessa' => null]));
        $this->promessasCriadas[] = $id;
    }

    public function testRegistrarSemDataPromessaLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['data_promessa' => null]));
        $this->promessasCriadas[] = $id;
    }

    public function testAtualizarStatusComStatusInvalidoLancaExcecao(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos());
        $this->promessasCriadas[] = $id;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status da promessa inválido.');
        $this->repository->atualizarStatus($id, 'status_que_nao_existe');
    }

    public function testAtualizarStatusParaCumpridaFunciona(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos());
        $this->promessasCriadas[] = $id;

        $this->repository->atualizarStatus($id, 'cumprida', 'Pago via boleto.');

        $status = $this->pdo->query("SELECT status FROM promessas_pagamento WHERE id = {$id}")->fetchColumn();
        self::assertSame('cumprida', $status);
    }
}
