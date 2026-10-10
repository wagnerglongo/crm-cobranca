<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoResultadoRepository;
use PHPUnit\Framework\TestCase;

/**
 * AtendimentoResultadoRepository.php (314 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. salvar() é o CRUD das
 * "tabulações" - os flags aqui (considera_sucesso, considera_contato_efetivo,
 * considera_telefone_ruim) controlam contagens em todo o resto do
 * sistema (dashboard, relatórios). Zero teste cobria a validação.
 */
final class AtendimentoResultadoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AtendimentoResultadoRepository $repository;
    /** @var list<int> */
    private array $resultadosCriados = [];
    /** @var list<int> */
    private array $credoresCriados = [];
    /**
     * Tabulações reais marcadas como padrão antes do teste. Achado
     * 2026-10-01: o teste marcava uma tabulação de teste como padrão da ACME
     * real, o que desmarcava a ACME_002 de produção (e o fechamento voltava a
     * cair na "Exceção"). Agora o teste usa um credor próprio e, por
     * garantia, restaura essas marcações no tearDown.
     *
     * @var list<int>
     */
    private array $padroesOriginais = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AtendimentoResultadoRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de tabulações indisponível nesta instância.');
        }

        $this->padroesOriginais = array_map('intval', $this->pdo
            ->query('SELECT id FROM atendimento_resultados WHERE padrao_fechamento_acordo = 1')
            ->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    protected function tearDown(): void
    {
        foreach ($this->resultadosCriados as $id) {
            $this->pdo->prepare('DELETE FROM atendimento_resultados WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->credoresCriados as $id) {
            $this->pdo->prepare('DELETE FROM credores WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->padroesOriginais as $id) {
            $this->pdo->prepare('UPDATE atendimento_resultados SET padrao_fechamento_acordo = 1 WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function criarCredorDeTeste(): int
    {
        $sufixo = strtoupper(bin2hex(random_bytes(4)));
        $this->pdo->prepare('INSERT INTO credores (codigo, nome, ativo) VALUES (:codigo, :nome, 1)')
            ->execute(['codigo' => 'TAB_' . $sufixo, 'nome' => 'Credor Teste Tabulação ' . $sufixo]);
        $id = (int) $this->pdo->lastInsertId();
        $this->credoresCriados[] = $id;
        return $id;
    }

    /** @param array<string,mixed> $overrides */
    private function dadosValidos(array $overrides = []): array
    {
        $sufixo = bin2hex(random_bytes(4));
        return array_merge([
            'codigo' => 'teste_' . $sufixo,
            'nome' => 'Tabulação Teste ' . $sufixo,
            'categoria' => 'Teste',
            'resultado_legacy' => 'atendeu',
            'considera_sucesso' => '1',
            'considera_contato_efetivo' => '1',
        ], $overrides);
    }

    public function testSalvarCriaEBuscarRetornaOsMesmosFlags(): void
    {
        $id = $this->repository->salvar($this->dadosValidos());
        $this->resultadosCriados[] = $id;

        $resultado = $this->repository->buscar($id);
        self::assertSame(1, (int) $resultado['considera_sucesso']);
        self::assertSame(1, (int) $resultado['considera_contato_efetivo']);
        self::assertSame(0, (int) $resultado['considera_telefone_ruim']);
    }

    public function testSalvarComCodigoDuplicadoLancaMensagemAmigavel(): void
    {
        $dados = $this->dadosValidos();
        $id = $this->repository->salvar($dados);
        $this->resultadosCriados[] = $id;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Já existe uma tabulação com esse código');
        $novoId = $this->repository->salvar(array_merge($this->dadosValidos(), ['codigo' => $dados['codigo']]));
        $this->resultadosCriados[] = $novoId;
    }

    public function testSalvarComResultadoLegacyInvalidoLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Selecione um resultado legado válido');
        $id = $this->repository->salvar($this->dadosValidos(['resultado_legacy' => 'valor_que_nao_existe']));
        $this->resultadosCriados[] = $id;
    }

    public function testSalvarComIdInexistenteLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tabulação de atendimento não encontrada');
        $this->repository->salvar($this->dadosValidos(['id' => 999999999]));
    }

    public function testSalvarComIdExistenteAtualizaEmVezDeCriarNova(): void
    {
        $id = $this->repository->salvar($this->dadosValidos(['nome' => 'Nome Original']));
        $this->resultadosCriados[] = $id;

        $idAtualizado = $this->repository->salvar(array_merge($this->dadosValidos(), [
            'id' => $id,
            'codigo' => $this->repository->buscar($id)['codigo'],
            'nome' => 'Nome Atualizado',
        ]));

        self::assertSame($id, $idAtualizado);
        self::assertSame('Nome Atualizado', $this->repository->buscar($id)['nome']);
    }

    public function testAlternarAtivoDesativaEReativa(): void
    {
        $id = $this->repository->salvar($this->dadosValidos());
        $this->resultadosCriados[] = $id;

        $this->repository->alternarAtivo($id, false);
        self::assertSame(0, (int) $this->repository->buscar($id)['ativo']);

        $this->repository->alternarAtivo($id, true);
        self::assertSame(1, (int) $this->repository->buscar($id)['ativo']);
    }

    /**
     * Achado (2026-10-01): "padrão pra acordo fechado" só faz
     * sentido como único por credor - marcar uma nova desmarca a anterior
     * do mesmo escopo (mesmo credor_id, ou ambas globais/credor_id nulo).
     */
    public function testSalvarComPadraoFechamentoAcordoDesmarcaOutroDoMesmoCredor(): void
    {
        $credorId = $this->criarCredorDeTeste();

        $idAntigo = $this->repository->salvar($this->dadosValidos(['credor_id' => $credorId, 'padrao_fechamento_acordo' => '1']));
        $this->resultadosCriados[] = $idAntigo;
        self::assertSame(1, (int) $this->repository->buscar($idAntigo)['padrao_fechamento_acordo']);

        $idNovo = $this->repository->salvar($this->dadosValidos(['credor_id' => $credorId, 'padrao_fechamento_acordo' => '1']));
        $this->resultadosCriados[] = $idNovo;

        self::assertSame(1, (int) $this->repository->buscar($idNovo)['padrao_fechamento_acordo']);
        self::assertSame(0, (int) $this->repository->buscar($idAntigo)['padrao_fechamento_acordo'], 'marcar o novo como padrão deve desmarcar o antigo do mesmo credor');
    }

    public function testSalvarComPadraoFechamentoAcordoNaoAfetaOutroCredorNemGlobal(): void
    {
        $credorId = $this->criarCredorDeTeste();

        $idGlobal = $this->repository->salvar($this->dadosValidos(['padrao_fechamento_acordo' => '1']));
        $this->resultadosCriados[] = $idGlobal;

        $idDoCredor = $this->repository->salvar($this->dadosValidos(['credor_id' => $credorId, 'padrao_fechamento_acordo' => '1']));
        $this->resultadosCriados[] = $idDoCredor;

        self::assertSame(1, (int) $this->repository->buscar($idGlobal)['padrao_fechamento_acordo'], 'padrão global (sem credor) não deve ser afetado por um padrão de credor específico');
        self::assertSame(1, (int) $this->repository->buscar($idDoCredor)['padrao_fechamento_acordo']);
    }
}
