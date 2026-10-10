<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;
use PHPUnit\Framework\TestCase;

/**
 * CredorIntegracaoRepository.php - continuação da auditoria de cobertura
 * de 2026-09-17 (a regex de segredo já foi testada em
 * CredorIntegracaoSegredoTest.php). Cobre agora as regras de negócio de
 * roteamento: qual integração fica ativa por credor/adapter, e a
 * precedência produto-específico > geral no de/para de tabulações que
 * decide se um acionamento vira notificação pro credor.
 */
final class CredorIntegracaoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CredorIntegracaoRepository $repository;
    private int $credorId;
    /** @var list<int> */
    private array $integracoesCriadas = [];
    /** @var list<int> */
    private array $mapeamentosCriados = [];
    /** @var list<int> */
    private array $resultadosCriados = [];
    /** @var list<int> */
    private array $atendimentosCriados = [];
    /** @var list<int> */
    private array $contratosCriados = [];
    /** @var list<int> */
    private array $pessoasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CredorIntegracaoRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de integrações por credor indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = $stmt->fetchColumn();
        self::assertNotFalse($credorId, 'credor ACME não encontrado para o teste');
        $this->credorId = (int) $credorId;
    }

    protected function tearDown(): void
    {
        foreach ($this->mapeamentosCriados as $id) {
            $this->pdo->prepare('DELETE FROM integracao_tabulacao_mapeamentos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->atendimentosCriados as $id) {
            $this->pdo->prepare('DELETE FROM atendimentos_historico WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->resultadosCriados as $id) {
            $this->pdo->prepare('DELETE FROM atendimento_resultados WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->integracoesCriadas as $id) {
            $this->pdo->prepare('DELETE FROM credor_integracoes WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->contratosCriados as $id) {
            $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->pessoasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $id]);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function criarIntegracao(array $overrides = []): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $dados = array_merge([
            'codigo' => 'teste_' . $sufixo,
            'nome' => 'Integração Teste ' . $sufixo,
            'evento_tipo' => 'acionamento.registrado',
            'tipo_transporte' => 'webhook',
            'adapter' => 'generico',
            'ativo' => 1,
        ], $overrides);
        $id = $this->repository->salvarIntegracao($this->credorId, $dados, null);
        $this->integracoesCriadas[] = $id;
        return $id;
    }

    private function criarResultado(): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO atendimento_resultados (codigo, nome, categoria, ativo) VALUES (:codigo, :nome, :categoria, 1)')
            ->execute(['codigo' => 'teste_' . $sufixo, 'nome' => 'Resultado Teste ' . $sufixo, 'categoria' => 'teste']);
        $id = (int) $this->pdo->lastInsertId();
        $this->resultadosCriados[] = $id;
        return $id;
    }

    private function criarContrato(string $produto = ''): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Integ', 'documento' => '666' . random_int(10000000, 99999999)]);
        $pessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $pessoaId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato, produto) VALUES (:pessoa_id, :credor_id, :codigo, :produto)')
            ->execute(['pessoa_id' => $pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-INTEG-' . $sufixo, 'produto' => $produto]);
        $id = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $id;
        return $id;
    }

    private function criarAtendimento(int $contratoId, int $pessoaId, int $resultadoId): int
    {
        $this->pdo->prepare("INSERT INTO atendimentos_historico (pessoa_id, contrato_id, resultado_id, resultado) VALUES (:pessoa_id, :contrato_id, :resultado_id, 'atendeu')")
            ->execute(['pessoa_id' => $pessoaId, 'contrato_id' => $contratoId, 'resultado_id' => $resultadoId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->atendimentosCriados[] = $id;
        return $id;
    }

    public function testIntegracaoAtivaPorAdapterEncontraAtivaEhCaseInsensitive(): void
    {
        $this->criarIntegracao(['adapter' => 'ACME_Acionamentos', 'ativo' => 1]);

        $encontrada = $this->repository->integracaoAtivaPorAdapter($this->credorId, 'acme_acionamentos');

        self::assertNotNull($encontrada);
    }

    public function testIntegracaoAtivaPorAdapterIgnoraInativa(): void
    {
        $this->criarIntegracao(['adapter' => 'generico_inativo', 'ativo' => 0]);

        $encontrada = $this->repository->integracaoAtivaPorAdapter($this->credorId, 'generico_inativo');

        self::assertNull($encontrada);
    }

    /**
     * Achado (não corrigido, só documentado pelo teste): alternarIntegracao()
     * com credor errado NÃO lança exceção - o UPDATE não afeta nada porque o
     * WHERE já filtra por credor_id (protegido de verdade contra mutação
     * cross-credor), mas o guard de erro só checa "existe em algum credor",
     * não "existe para ESTE credor" - então falha em silêncio ao invés de
     * avisar. Não é falha de segurança (dado não é alterado), é um gap de
     * feedback pro admin. Este teste trava o comportamento ATUAL.
     */
    public function testAlternarIntegracaoDeCredorErradoNaoAlteraEFalhaEmSilencio(): void
    {
        $integracaoId = $this->criarIntegracao(['ativo' => 1]);
        $outroCredorId = $this->credorId === 1 ? 2 : 1;

        $this->repository->alternarIntegracao($outroCredorId, $integracaoId, false, null);

        $integracao = $this->repository->buscar($integracaoId);
        self::assertSame(1, (int) $integracao['ativo'], 'a integração do OUTRO credor não deveria ter sido alterada');
    }

    public function testAlternarIntegracaoDesativaCorretamente(): void
    {
        $integracaoId = $this->criarIntegracao(['ativo' => 1]);

        $this->repository->alternarIntegracao($this->credorId, $integracaoId, false, null);

        $integracao = $this->repository->buscar($integracaoId);
        self::assertSame(0, (int) $integracao['ativo']);
    }

    public function testSalvarMapeamentoAcmeExigeActionIdNumerico(): void
    {
        $integracaoId = $this->criarIntegracao(['adapter' => 'ACME_ACIONAMENTOS']);
        $resultadoId = $this->criarResultado();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ActionID numérico');
        $mapeamentoId = $this->repository->salvarMapeamento($resultadoId, [
            'integracao_id' => $integracaoId,
            'enviar' => 1,
            'codigo_externo' => 'ABC-nao-numerico',
        ], null);
        $this->mapeamentosCriados[] = $mapeamentoId;
    }

    public function testSalvarMapeamentoBloqueiaCodigoExternoDuplicado(): void
    {
        $integracaoId = $this->criarIntegracao();
        $resultado1 = $this->criarResultado();
        $resultado2 = $this->criarResultado();

        $id1 = $this->repository->salvarMapeamento($resultado1, [
            'integracao_id' => $integracaoId,
            'enviar' => 1,
            'codigo_externo' => '999',
        ], null);
        $this->mapeamentosCriados[] = $id1;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('já está associado');
        $id2 = $this->repository->salvarMapeamento($resultado2, [
            'integracao_id' => $integracaoId,
            'enviar' => 1,
            'codigo_externo' => '999',
        ], null);
        $this->mapeamentosCriados[] = $id2;
    }

    public function testContextosParaAtendimentoPrefereMapeamentoDoProdutoSobreOGeral(): void
    {
        $integracaoId = $this->criarIntegracao();
        $resultadoId = $this->criarResultado();
        $contratoId = $this->criarContrato('PRODUTO_X');

        $mapGeral = $this->repository->salvarMapeamento($resultadoId, [
            'integracao_id' => $integracaoId, 'enviar' => 1, 'codigo_externo' => 'GERAL',
        ], null);
        $this->mapeamentosCriados[] = $mapGeral;
        $mapProduto = $this->repository->salvarMapeamento($resultadoId, [
            'integracao_id' => $integracaoId, 'enviar' => 1, 'codigo_externo' => 'ESPECIFICO', 'produto' => 'PRODUTO_X',
        ], null);
        $this->mapeamentosCriados[] = $mapProduto;

        $stmt = $this->pdo->prepare('SELECT pessoa_id FROM contratos WHERE id = :id');
        $stmt->execute(['id' => $contratoId]);
        $pessoaId = (int) $stmt->fetchColumn();
        $atendimentoId = $this->criarAtendimento($contratoId, $pessoaId, $resultadoId);

        $contextos = $this->repository->contextosParaAtendimento($atendimentoId);

        self::assertCount(1, $contextos, 'só deve haver 1 contexto por integração, mesmo com 2 mapeamentos aplicáveis');
        self::assertSame('ESPECIFICO', $contextos[0]['codigo_externo']);
    }

    public function testContextosParaAtendimentoUsaMapeamentoGeralQuandoNaoHaEspecifico(): void
    {
        $integracaoId = $this->criarIntegracao();
        $resultadoId = $this->criarResultado();
        $contratoId = $this->criarContrato('OUTRO_PRODUTO');

        $mapGeral = $this->repository->salvarMapeamento($resultadoId, [
            'integracao_id' => $integracaoId, 'enviar' => 1, 'codigo_externo' => 'GERAL',
        ], null);
        $this->mapeamentosCriados[] = $mapGeral;

        $stmt = $this->pdo->prepare('SELECT pessoa_id FROM contratos WHERE id = :id');
        $stmt->execute(['id' => $contratoId]);
        $pessoaId = (int) $stmt->fetchColumn();
        $atendimentoId = $this->criarAtendimento($contratoId, $pessoaId, $resultadoId);

        $contextos = $this->repository->contextosParaAtendimento($atendimentoId);

        self::assertCount(1, $contextos);
        self::assertSame('GERAL', $contextos[0]['codigo_externo']);
    }
}
