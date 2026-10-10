<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\CampanhaRepository;
use PHPUnit\Framework\TestCase;

/**
 * CampanhaRepository.php (1.409 linhas) - continuação da auditoria de
 * cobertura de 2026-09-17. atualizarStatusCampanha()/excluirCampanhaLogica()/
 * regerarFila() foram corrigidos na auditoria de 2026-09-16 por um IDOR
 * real (não respeitavam escopo de credor) - corrigido usando
 * buscarCampanha($id, $escopo) como guard antes de agir. Zero teste
 * cobria isso desde então; uma regressão no guard voltaria a permitir
 * um operador de um credor apagar/pausar campanha de outro.
 */
final class CampanhaRepositoryEscopoTest extends TestCase
{
    private PDO $pdo;
    private CampanhaRepository $repository;
    private int $credorAcme;
    private int $credorOutro;
    private int $campanhaId;
    /** @var list<int> */
    private array $itensCriados = [];
    /** @var list<int> */
    private array $contratosCriados = [];
    /** @var list<int> */
    private array $pessoasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CampanhaRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de campanhas indisponível nesta instância.');
        }

        $this->credorAcme = $this->credorId('ACME');
        $this->credorOutro = $this->credorId('GLOBEX');

        $this->pdo->prepare("INSERT INTO campanhas (nome, status) VALUES (:nome, 'ativa')")
            ->execute(['nome' => 'Campanha Teste Escopo ' . bin2hex(random_bytes(4))]);
        $this->campanhaId = (int) $this->pdo->lastInsertId();

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Campanha', 'documento' => '555' . random_int(10000000, 99999999)]);
        $pessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $pessoaId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $pessoaId, 'credor_id' => $this->credorAcme, 'codigo' => 'TESTE-CAMP-' . $sufixo]);
        $contratoId = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $contratoId;

        $this->pdo->prepare('INSERT INTO campanha_itens (campanha_id, pessoa_id, contrato_id, chave_item, status) VALUES (:campanha_id, :pessoa_id, :contrato_id, :chave, :status)')
            ->execute([
                'campanha_id' => $this->campanhaId,
                'pessoa_id' => $pessoaId,
                'contrato_id' => $contratoId,
                'chave' => 'teste-' . $sufixo,
                'status' => 'pendente',
            ]);
        $this->itensCriados[] = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->itensCriados as $id) {
            $this->pdo->prepare('DELETE FROM campanha_itens WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM campanhas WHERE id = :id')->execute(['id' => $this->campanhaId]);
        foreach ($this->contratosCriados as $id) {
            $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->pessoasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $id]);
        }
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

    public function testAtualizarStatusCampanhaForaDoEscopoLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Campanha não encontrada no seu escopo.');
        $this->repository->atualizarStatusCampanha($this->campanhaId, 'pausada', $this->escopoSo($this->credorOutro));
    }

    public function testAtualizarStatusCampanhaDentroDoEscopoFunciona(): void
    {
        $this->repository->atualizarStatusCampanha($this->campanhaId, 'pausada', $this->escopoSo($this->credorAcme));

        $campanha = $this->repository->buscarCampanha($this->campanhaId, null);
        self::assertSame('pausada', $campanha['status']);
    }

    public function testExcluirCampanhaLogicaForaDoEscopoLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Campanha não encontrada no seu escopo.');
        $this->repository->excluirCampanhaLogica($this->campanhaId, 1, 'Operador Teste', null, $this->escopoSo($this->credorOutro));
    }

    public function testExcluirCampanhaLogicaDentroDoEscopoCancelaEDescartaItens(): void
    {
        $resultado = $this->repository->excluirCampanhaLogica($this->campanhaId, 1, 'Operador Teste', 'motivo teste', $this->escopoSo($this->credorAcme));

        self::assertSame(1, $resultado['itens_descartados']);
        $campanha = $this->repository->buscarCampanha($this->campanhaId, null);
        self::assertSame('cancelada', $campanha['status']);
    }

    public function testRegerarFilaForaDoEscopoLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Campanha não encontrada no seu escopo.');
        $this->repository->regerarFila($this->campanhaId, 100, $this->escopoSo($this->credorOutro));
    }

    public function testListarItensRespeitaEscopo(): void
    {
        $itensNoEscopoCerto = $this->repository->listarItens($this->campanhaId, 'todos', '', 80, $this->escopoSo($this->credorAcme));
        $itensNoEscopoErrado = $this->repository->listarItens($this->campanhaId, 'todos', '', 80, $this->escopoSo($this->credorOutro));

        self::assertCount(1, $itensNoEscopoCerto);
        self::assertCount(0, $itensNoEscopoErrado);
    }
}
