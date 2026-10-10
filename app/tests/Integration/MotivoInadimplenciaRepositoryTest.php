<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\MotivoInadimplenciaRepository;
use PHPUnit\Framework\TestCase;

/**
 * Motivo da inadimplência (migration 095, requisito de 2026-09-23):
 * lista configurável perguntada no acionamento quando há CPC. CRUD simples,
 * mesmo padrão do AtendimentoResultadoRepository (Tabulações).
 */
final class MotivoInadimplenciaRepositoryTest extends TestCase
{
    private PDO $pdo;
    private MotivoInadimplenciaRepository $repository;
    /** @var list<int> */
    private array $criados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new MotivoInadimplenciaRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->criados as $id) {
            $this->pdo->prepare('DELETE FROM motivos_inadimplencia WHERE id = :id')->execute(['id' => $id]);
        }
    }

    public function testSalvarCriaEBuscar(): void
    {
        $nome = 'Motivo Teste ' . bin2hex(random_bytes(4));
        $id = $this->repository->salvar(['nome' => $nome, 'ordem' => 15, 'ativo' => 1]);
        $this->criados[] = $id;

        $motivo = $this->repository->buscar($id);

        self::assertNotNull($motivo);
        self::assertSame($nome, $motivo['nome']);
        self::assertSame(15, (int) $motivo['ordem']);
        self::assertSame(1, (int) $motivo['ativo']);
    }

    public function testSalvarSemNomeLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->salvar(['nome' => '']);
    }

    public function testNomeDuplicadoLancaExcecao(): void
    {
        $nome = 'Motivo Duplicado ' . bin2hex(random_bytes(4));
        $id = $this->repository->salvar(['nome' => $nome]);
        $this->criados[] = $id;

        $this->expectException(InvalidArgumentException::class);
        $this->repository->salvar(['nome' => $nome]);
    }

    public function testAlternarAtivoMudaOStatus(): void
    {
        $id = $this->repository->salvar(['nome' => 'Motivo Toggle ' . bin2hex(random_bytes(4))]);
        $this->criados[] = $id;

        $this->repository->alternarAtivo($id, false);
        self::assertSame(0, (int) $this->repository->buscar($id)['ativo']);

        $this->repository->alternarAtivo($id, true);
        self::assertSame(1, (int) $this->repository->buscar($id)['ativo']);
    }

    public function testListarAtivosNaoTrazInativos(): void
    {
        $ativo = $this->repository->salvar(['nome' => 'Motivo Ativo ' . bin2hex(random_bytes(4)), 'ativo' => 1]);
        $this->criados[] = $ativo;
        $inativo = $this->repository->salvar(['nome' => 'Motivo Inativo ' . bin2hex(random_bytes(4)), 'ativo' => 0]);
        $this->criados[] = $inativo;

        $ids = array_column($this->repository->listarAtivos(), 'id');

        self::assertContains($ativo, $ids);
        self::assertNotContains($inativo, $ids);
    }

    public function testSeedDaMigration095TemAs6OpcoesPadrao(): void
    {
        $nomes = array_column($this->repository->listarAtivos(), 'nome');

        foreach (['Perdeu a renda', 'Discorda do valor', 'Problema de saúde/familiar', 'Alega pagamento', 'Cliente não informa motivo', 'Não reconhece a dívida'] as $esperado) {
            self::assertContains($esperado, $nomes, 'seed da migration 095 deveria ter "' . $esperado . '"');
        }
    }
}
