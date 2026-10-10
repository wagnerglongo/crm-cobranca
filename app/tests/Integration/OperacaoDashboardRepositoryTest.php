<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\OperacaoDashboardRepository;
use PHPUnit\Framework\TestCase;

/**
 * OperacaoDashboardRepository.php (293 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. obterResumoBase() alimenta os
 * primeiros números que qualquer operador vê ao abrir o CRM
 * (total de pessoas/contratos/contatos) - respeita escopo de credor.
 * Zero teste cobria isso.
 */
final class OperacaoDashboardRepositoryTest extends TestCase
{
    private PDO $pdo;
    private OperacaoDashboardRepository $repository;
    private int $credorAcme;
    private int $credorOutro;
    private int $pessoaId;
    private int $contratoId;

    /** @var array<string,int> */
    private array $resumoAcmeAntes;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new OperacaoDashboardRepository($this->pdo);

        $this->credorAcme = $this->credorId('ACME');
        $this->credorOutro = $this->credorId('GLOBEX');

        $this->resumoAcmeAntes = $this->repository->obterResumoBase($this->escopoSo($this->credorAcme));

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Dashboard', 'documento' => '111' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorAcme, 'codigo' => 'TESTE-DASH-' . bin2hex(random_bytes(4))]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
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

    public function testResumoBaseComEscopoCertoContaOContratoSintetico(): void
    {
        $depois = $this->repository->obterResumoBase($this->escopoSo($this->credorAcme));

        self::assertSame($this->resumoAcmeAntes['total_contratos'] + 1, $depois['total_contratos']);
        self::assertSame($this->resumoAcmeAntes['total_pessoas'] + 1, $depois['total_pessoas']);
    }

    public function testResumoBaseComEscopoErradoNaoContaOContratoSintetico(): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM contratos WHERE credor_id = :id');
        $stmt->execute(['id' => $this->credorOutro]);
        $totalGlobexAntes = (int) $stmt->fetchColumn();

        $resumo = $this->repository->obterResumoBase($this->escopoSo($this->credorOutro));

        // O contrato sintetico foi criado com credor ACME, nao Globex - o
        // total no escopo Globex nao deve ter mudado por causa dele.
        self::assertSame($totalGlobexAntes, $resumo['total_contratos']);
    }

    public function testResumoBaseComEscopoTodosVeMaisOuIgualQueComEscopoRestrito(): void
    {
        $restrito = $this->repository->obterResumoBase($this->escopoSo($this->credorAcme));
        $todos = $this->repository->obterResumoBase(['todos' => true, 'credor_ids' => [], 'carteira_ids' => []]);

        self::assertGreaterThanOrEqual($restrito['total_contratos'], $todos['total_contratos']);
    }
}
