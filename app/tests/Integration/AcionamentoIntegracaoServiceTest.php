<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;
use CRM\Modules\Operacao\Services\AcionamentoIntegracaoService;
use PHPUnit\Framework\TestCase;

/**
 * AcionamentoIntegracaoService.php (163 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. Converte um atendimento
 * registrado no Pactua num evento canônico pra fila de saída (webhook pro
 * credor) - só quando existe integração ativa + de/para de tabulação com
 * "Enviar" marcado E código externo preenchido. Ponto de maior risco:
 * os 2 guards que decidem SE um acionamento vira notificação externa
 * (`mapeamento_enviar !== 1` e `codigo_externo === ''`) - se algum
 * quebrar, ou um credor nunca recebe notificações que deveria (silêncio
 * indevido), ou recebe notificação de tabulação que devia ficar interna.
 */
final class AcionamentoIntegracaoServiceTest extends TestCase
{
    private PDO $pdo;
    private CredorIntegracaoRepository $integracoes;
    private AcionamentoIntegracaoService $service;
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
    /** @var list<int> */
    private array $eventosCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->integracoes = new CredorIntegracaoRepository($this->pdo);
        $this->service = new AcionamentoIntegracaoService($this->pdo);

        if (!$this->integracoes->moduloDisponivel()) {
            self::markTestSkipped('Módulo de integrações por credor indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach ($this->eventosCriados as $id) {
            $this->pdo->prepare('DELETE FROM integracao_eventos_saida WHERE id = :id')->execute(['id' => $id]);
        }
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

    private function criarIntegracao(): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $id = $this->integracoes->salvarIntegracao($this->credorId, [
            'codigo' => 'teste_acion_' . $sufixo,
            'nome' => 'Integração Teste Acionamento',
            'evento_tipo' => 'acionamento.registrado',
            'tipo_transporte' => 'webhook',
            'adapter' => 'generico',
            'ativo' => 1,
        ], null);
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

    private function criarMapeamento(int $integracaoId, int $resultadoId, array $overrides = []): int
    {
        $dados = array_merge([
            'integracao_id' => $integracaoId,
            'produto' => '',
            'enviar' => 1,
            'codigo_externo' => 'EXT-' . bin2hex(random_bytes(3)),
            'descricao_externa' => 'Descrição externa teste',
            'ativo' => 1,
        ], $overrides);
        $id = $this->integracoes->salvarMapeamento($resultadoId, $dados, null);
        $this->mapeamentosCriados[] = $id;
        return $id;
    }

    private function criarContrato(): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Acionamento', 'documento' => '667' . random_int(10000000, 99999999)]);
        $pessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $pessoaId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-ACION-' . $sufixo]);
        $id = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $id;
        return $id;
    }

    /** @param array<string,mixed> $overrides */
    private function criarAtendimento(int $contratoId, int $resultadoId, array $overrides = []): int
    {
        $stmt = $this->pdo->prepare('SELECT pessoa_id FROM contratos WHERE id = :id');
        $stmt->execute(['id' => $contratoId]);
        $pessoaId = (int) $stmt->fetchColumn();

        $dados = array_merge([
            'canal' => 'ligacao',
            'contato_utilizado' => null,
            'observacao' => null,
        ], $overrides);

        $this->pdo->prepare("INSERT INTO atendimentos_historico (pessoa_id, contrato_id, resultado_id, resultado, canal, contato_utilizado, observacao)
            VALUES (:pessoa_id, :contrato_id, :resultado_id, 'atendeu', :canal, :contato_utilizado, :observacao)")
            ->execute([
                'pessoa_id' => $pessoaId,
                'contrato_id' => $contratoId,
                'resultado_id' => $resultadoId,
                'canal' => $dados['canal'],
                'contato_utilizado' => $dados['contato_utilizado'],
                'observacao' => $dados['observacao'],
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->atendimentosCriados[] = $id;
        return $id;
    }

    public function testAtendimentoInexistenteNaoEnfileiraNada(): void
    {
        $ids = $this->service->enfileirarAtendimento(999999999);

        self::assertSame([], $ids);
    }

    public function testAtendimentoSemMapeamentoConfiguradoNaoEnfileiraNada(): void
    {
        $integracaoId = $this->criarIntegracao();
        $resultadoId = $this->criarResultado();
        $contratoId = $this->criarContrato();
        $atendimentoId = $this->criarAtendimento($contratoId, $resultadoId);
        // Sem chamar criarMapeamento(): não há de/para pra essa tabulação.

        $ids = $this->service->enfileirarAtendimento($atendimentoId);

        self::assertSame([], $ids);
        unset($integracaoId);
    }

    public function testMapeamentoComEnviarDesmarcadoNaoEnfileira(): void
    {
        $integracaoId = $this->criarIntegracao();
        $resultadoId = $this->criarResultado();
        $this->criarMapeamento($integracaoId, $resultadoId, ['enviar' => 0, 'codigo_externo' => null]);
        $contratoId = $this->criarContrato();
        $atendimentoId = $this->criarAtendimento($contratoId, $resultadoId);

        $ids = $this->service->enfileirarAtendimento($atendimentoId);

        self::assertSame([], $ids);
    }

    /**
     * Achado ao escrever este teste (não é bug, é documentação): não é
     * possível construir "enviar=1 com codigo_externo vazio" nem
     * contornando o repositório (UPDATE direto) - existe uma CHECK
     * constraint real no banco (`chk_integracao_tabulacao_codigo`:
     * enviar=0 OR codigo_externo não vazio) que rejeita esse estado
     * antes mesmo de chegar no PHP. O guard equivalente dentro de
     * `AcionamentoIntegracaoService::enfileirarAtendimento()`
     * (`$codigoExterno === ''`) é hoje redundante - dupla proteção
     * (repositório + banco), não uma lacuna.
     */
    public function testAtendimentoComMapeamentoValidoEnfileiraEventoComPayloadCanonico(): void
    {
        $integracaoId = $this->criarIntegracao();
        $resultadoId = $this->criarResultado();
        $this->criarMapeamento($integracaoId, $resultadoId);
        $contratoId = $this->criarContrato();
        $atendimentoId = $this->criarAtendimento($contratoId, $resultadoId, ['canal' => 'whatsapp', 'contato_utilizado' => '11999999999']);

        $ids = $this->service->enfileirarAtendimento($atendimentoId);
        $this->eventosCriados = array_merge($this->eventosCriados, $ids);

        self::assertCount(1, $ids);

        $stmt = $this->pdo->prepare('SELECT payload FROM integracao_eventos_saida WHERE id = :id');
        $stmt->execute(['id' => $ids[0]]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);

        self::assertSame('acionamento.registrado', $payload['evento_tipo']);
        self::assertSame($atendimentoId, $payload['origem']['id']);
        self::assertSame('whatsapp', $payload['acionamento']['canal']);
        self::assertMatchesRegularExpression('/^\d+$/', $payload['pessoa']['documento']);
        self::assertNotEmpty($payload['event_id']);
    }

    /**
     * Sem observação explícita, o payload precisa montar uma observação
     * padrão a partir de canal/contato/resultado - nunca mandar campo
     * vazio pro credor quando há informação disponível pra descrever o
     * acionamento.
     */
    public function testSemObservacaoExplicitaMontaObservacaoPadraoComCanalEContato(): void
    {
        $integracaoId = $this->criarIntegracao();
        $resultadoId = $this->criarResultado();
        $this->criarMapeamento($integracaoId, $resultadoId);
        $contratoId = $this->criarContrato();
        $atendimentoId = $this->criarAtendimento($contratoId, $resultadoId, ['canal' => 'ligacao', 'contato_utilizado' => '1133334444', 'observacao' => null]);

        $ids = $this->service->enfileirarAtendimento($atendimentoId);
        $this->eventosCriados = array_merge($this->eventosCriados, $ids);

        $stmt = $this->pdo->prepare('SELECT payload FROM integracao_eventos_saida WHERE id = :id');
        $stmt->execute(['id' => $ids[0]]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);

        self::assertStringContainsString('Canal: ligacao', $payload['acionamento']['observacao']);
        self::assertStringContainsString('Contato: 1133334444', $payload['acionamento']['observacao']);
    }
}
