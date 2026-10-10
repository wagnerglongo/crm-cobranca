<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use PHPUnit\Framework\TestCase;

/**
 * ConsultaClienteRepository.php (574 linhas) - continuação da auditoria
 * de cobertura de 2026-09-17. pessoaTemContratoNoEscopo() é o gate que
 * decide se um operador pode abrir a página de um cliente
 * (cliente_detalhe.php) - e buscarPessoas() é a busca geral do CRM.
 * Ambos dependem do escopo de credor pra não vazar cliente de outro
 * credor. Zero teste cobria isso.
 */
final class ConsultaClienteRepositoryEscopoTest extends TestCase
{
    private PDO $pdo;
    private ConsultaClienteRepository $repository;
    private int $credorAcme;
    private int $credorOutro;
    private int $pessoaId;
    private int $contratoId;
    private string $nomeUnico;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new ConsultaClienteRepository($this->pdo);

        $this->credorAcme = $this->credorId('ACME');
        $this->credorOutro = $this->credorId('GLOBEX');

        $this->nomeUnico = 'Zeferino Wblqxpfjnmzk Testesomenteletras';
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => $this->nomeUnico, 'documento' => '999' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorAcme, 'codigo' => 'TESTE-CONS-' . bin2hex(random_bytes(4))]);
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

    public function testPessoaTemContratoNoEscopoCorretoRetornaTrue(): void
    {
        self::assertTrue($this->repository->pessoaTemContratoNoEscopo($this->pessoaId, $this->escopoSo($this->credorAcme)));
    }

    public function testPessoaTemContratoNoEscopoErradoRetornaFalse(): void
    {
        self::assertFalse($this->repository->pessoaTemContratoNoEscopo($this->pessoaId, $this->escopoSo($this->credorOutro)));
    }

    public function testPessoaTemContratoComEscopoTodosRetornaTrue(): void
    {
        self::assertTrue($this->repository->pessoaTemContratoNoEscopo($this->pessoaId, ['todos' => true, 'credor_ids' => [], 'carteira_ids' => []]));
    }

    public function testBuscarPessoasNaoEncontraClienteForaDoEscopo(): void
    {
        $resultado = $this->repository->buscarPessoas($this->nomeUnico, 20, $this->escopoSo($this->credorOutro));

        self::assertSame([], $resultado);
    }

    public function testBuscarPessoasEncontraClienteDentroDoEscopo(): void
    {
        $resultado = $this->repository->buscarPessoas($this->nomeUnico, 20, $this->escopoSo($this->credorAcme));

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $resultado);
        self::assertContains($this->pessoaId, $ids);
    }
}
