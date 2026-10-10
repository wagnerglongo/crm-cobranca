<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Infrastructure\Redis\RedisCache;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Api\Controllers\OperacoesController;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoResultadoRepository;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use PHPUnit\Framework\TestCase;

/**
 * OperacoesController (616 linhas) - a API que cria acionamentos e FECHA ACORDOS na
 * ACME (dinheiro real). Ficava sem teste porque o AcmeApiClient nao era injetavel;
 * agora recebe um transporte falso (nenhuma chamada real pra ACME) e um circuito de
 * nome unico. Foco de seguranca: (1) escopo por credor no nivel da API, (2) o lote
 * e' atomico, (3) NADA e' enviado pra ACME quando uma regra local bloqueia o acordo
 * (contrato retirado, divida quitada, ACME informa quitacao) - so o caminho valido
 * chega no /v1/acordos, e so depois de consultar acordo e simulacoes.
 */
final class OperacoesControllerTest extends TestCase
{
    private PDO $pdo;
    private OperacoesController $controller;
    private string $dir;
    private string $usuarioFalso;
    /** @var array<string,mixed> */
    private array $envOriginal = [];
    /** @var list<array{url:string,method:string,content:?string}> */
    private array $chamadasAcme = [];
    /** @var list<array{0:mixed,1:int,2:string}> */
    private array $filaAcme = [];
    private AcmeApiClient $acme;

    private int $credorAcme;
    private int $credorOutro;
    private int $usuarioId;
    private int $resultadoId;
    private int $pessoaId;
    private int $contratoId;
    private string $documento;
    private string $codigoContrato;
    /** @var list<int> */
    private array $atendimentosExtras = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->dir = sys_get_temp_dir() . '/ops_ctrl_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->usuarioFalso = 'usuario-teste-' . bin2hex(random_bytes(4));
        foreach ([
            'ACME_API_BASE_URL' => 'https://acme-teste.invalid', 'ACME_API_TOKEN_BASE_URL' => 'https://acme-token-teste.invalid',
            'ACME_API_USERNAME' => $this->usuarioFalso, 'ACME_API_PASSWORD' => 'senha-falsa', 'ACME_API_TIMEOUT' => '5',
            'ACME_API_LOG_FILE' => $this->dir . '/acme.log', 'ACME_API_STRUCTURED_LOG_FILE' => $this->dir . '/acme.jsonl',
        ] as $k => $v) {
            $this->envOriginal[$k] = array_key_exists($k, $_ENV) ? $_ENV[$k] : null;
            $_ENV[$k] = $v;
        }

        $transporte = function (string $url, string $method, array $headers, ?string $content): array {
            $this->chamadasAcme[] = ['url' => $url, 'method' => $method, 'content' => $content];
            if ($this->filaAcme === []) {
                throw new LogicException('ACME falsa sem resposta na fila: ' . $method . ' ' . $url);
            }
            return array_shift($this->filaAcme);
        };
        $this->acme = new AcmeApiClient($this->dir . '/token.json', null, $transporte, 'acme-teste-' . bin2hex(random_bytes(4)));

        $this->controller = new OperacoesController(
            $this->pdo,
            new AtendimentoAdaptorRepository($this->pdo),
            new ConsultaClienteRepository($this->pdo),
            new AtendimentoRepository($this->pdo),
            new AtendimentoResultadoRepository($this->pdo),
            new AcordoOperacionalRepository($this->pdo),
            new AuditoriaSistemaRepository($this->pdo),
            $this->acme
        );

        $this->credorAcme = $this->scalar("SELECT id FROM credores WHERE codigo = 'ACME' LIMIT 1");
        $this->credorOutro = $this->scalar("SELECT id FROM credores WHERE codigo = 'GLOBEX' LIMIT 1");

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:l, :n, :h)')
            ->execute(['l' => 'teste.opctrl.' . $sufixo, 'n' => 'Operador API Teste', 'h' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->usuarioId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO atendimento_resultados (codigo, nome, categoria, ativo) VALUES (:c, :n, :cat, 1)')
            ->execute(['c' => 'teste_' . $sufixo, 'n' => 'Resultado API Teste', 'cat' => 'teste']);
        $this->resultadoId = (int) $this->pdo->lastInsertId();

        $this->documento = '9' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:n, :d)')
            ->execute(['n' => 'Pessoa Teste OperacoesController', 'd' => $this->documento]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->codigoContrato = 'TESTE-OPCTRL-' . strtoupper($sufixo);
        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:p, :c, :cod)')
            ->execute(['p' => $this->pessoaId, 'c' => $this->credorAcme, 'cod' => $this->codigoContrato]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE contrato_id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM atendimentos_historico WHERE contrato_id = :id OR pessoa_id = :p')->execute(['id' => $this->contratoId, 'p' => $this->pessoaId]);
        foreach ($this->atendimentosExtras as $id) {
            $this->pdo->prepare('DELETE FROM atendimentos_historico WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM auditoria_sistema WHERE usuario_id = :u')->execute(['u' => $this->usuarioId]);
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
        $this->pdo->prepare('DELETE FROM atendimento_resultados WHERE id = :id')->execute(['id' => $this->resultadoId]);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $this->usuarioId]);

        $metodo = new ReflectionMethod($this->acme, 'redisTokenCacheKey');
        RedisCache::default()->delete((string) $metodo->invoke($this->acme));
        foreach ($this->envOriginal as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /** @param list<int> $credorIds */
    private function contexto(array $credorIds): AuthContext
    {
        return new AuthContext(0, null, 'usuario', ['id' => $this->usuarioId, 'nome' => 'Operador API Teste'], ['*'], ['todos' => false, 'credor_ids' => $credorIds, 'carteira_ids' => []], ['api']);
    }

    /** @param array<string,mixed> $body */
    private function post(string $path, array $body = []): ApiRequest
    {
        return new ApiRequest('POST', $path, [], ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), '127.0.0.1');
    }

    private function get(string $path, array $query = []): ApiRequest
    {
        return new ApiRequest('GET', $path, $query, [], '', '127.0.0.1');
    }

    private function statusHttp(callable $acao): int
    {
        try {
            $acao();
        } catch (ApiException $e) {
            return $e->status;
        }
        return 0;
    }

    private function codigoErro(callable $acao): string
    {
        try {
            $acao();
        } catch (ApiException $e) {
            return $e->errorCode;
        }
        return '';
    }

    private function contarAtendimentos(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM atendimentos_historico WHERE pessoa_id = :p');
        $stmt->execute(['p' => $this->pessoaId]);
        return (int) $stmt->fetchColumn();
    }

    // ---- autenticacao / leitura -------------------------------------------------

    public function testTodosOsEndpointsExigemAutenticacao(): void
    {
        $req = $this->get('/x');
        $p = ['documento' => $this->documento, 'contrato' => $this->codigoContrato];
        $acoes = [
            'tabulacoes' => fn () => $this->controller->tabulacoes($req, null, [], 'r'),
            'acionamento contrato' => fn () => $this->controller->criarAcionamentoPorContrato($req, null, $p, 'r'),
            'acionamento documento' => fn () => $this->controller->criarAcionamentoPorDocumento($req, null, $p, 'r'),
            'simulacoes' => fn () => $this->controller->simulacoesAcme($req, null, $p, 'r'),
            'fechar acordo' => fn () => $this->controller->fecharAcordoAcme($req, null, $p, 'r'),
            'lote' => fn () => $this->controller->criarAcionamentosLote($req, null, [], 'r'),
            'listar acordos' => fn () => $this->controller->listarAcordos($req, null, [], 'r'),
            'acordos por contrato' => fn () => $this->controller->acordosPorContrato($req, null, $p, 'r'),
            'acordos por documento' => fn () => $this->controller->acordosPorDocumento($req, null, $p, 'r'),
        ];
        foreach ($acoes as $nome => $acao) {
            self::assertSame(401, $this->statusHttp($acao), $nome);
        }
        self::assertSame([], $this->chamadasAcme, 'sem autenticacao nada chega na ACME');
    }

    public function testTabulacoesRetornaSoAtivasPorPadrao(): void
    {
        $this->pdo->prepare("UPDATE atendimento_resultados SET ativo = 0 WHERE id = :id")->execute(['id' => $this->resultadoId]);
        $ctx = $this->contexto([$this->credorAcme]);

        $padrao = $this->controller->tabulacoes($this->get('/tabulacoes'), $ctx, [], 'r')->payload();
        $todas = $this->controller->tabulacoes($this->get('/tabulacoes', ['ativas' => 'false']), $ctx, [], 'r')->payload();

        $idsPadrao = array_map(static fn (array $r): int => (int) $r['id'], $padrao['data']);
        $idsTodas = array_map(static fn (array $r): int => (int) $r['id'], $todas['data']);
        self::assertNotContains($this->resultadoId, $idsPadrao);
        self::assertContains($this->resultadoId, $idsTodas);
        self::assertTrue($padrao['meta']['somente_ativas']);
    }

    // ---- acionamentos --------------------------------------------------------------

    public function testCriarAcionamentoPorContratoCriaAtendimentoEAuditoria(): void
    {
        $resposta = $this->controller->criarAcionamentoPorContrato(
            $this->post('/acionamentos', ['resultado_id' => $this->resultadoId, 'observacao' => 'via API', 'contato_utilizado' => '11999998888']),
            $this->contexto([$this->credorAcme]),
            ['contrato' => $this->codigoContrato],
            'req-acion-1'
        );

        self::assertSame(201, $resposta->status());
        $atendimentoId = (int) $resposta->payload()['data']['acionamento'];
        self::assertSame(1, $this->contarAtendimentos());
        $linha = $this->pdo->query("SELECT contrato_id, resultado_id, usuario_id FROM atendimentos_historico WHERE id = {$atendimentoId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->contratoId, (int) $linha['contrato_id']);
        self::assertSame($this->usuarioId, (int) $linha['usuario_id']);
    }

    public function testTabulacaoInativaOuInexistenteRetorna422SemCriarAtendimento(): void
    {
        $this->pdo->prepare("UPDATE atendimento_resultados SET ativo = 0 WHERE id = :id")->execute(['id' => $this->resultadoId]);
        $ctx = $this->contexto([$this->credorAcme]);

        $status = $this->statusHttp(fn () => $this->controller->criarAcionamentoPorContrato($this->post('/a', ['resultado_id' => $this->resultadoId]), $ctx, ['contrato' => $this->codigoContrato], 'r'));

        self::assertSame(422, $status);
        self::assertSame(0, $this->contarAtendimentos());
    }

    /** Defesa em profundidade: contrato de OUTRO credor e' 404 na API, nunca 201. */
    public function testContratoForaDoEscopoDoTokenRetorna404(): void
    {
        $status = $this->statusHttp(fn () => $this->controller->criarAcionamentoPorContrato(
            $this->post('/a', ['resultado_id' => $this->resultadoId]),
            $this->contexto([$this->credorOutro]),
            ['contrato' => $this->codigoContrato],
            'r'
        ));

        self::assertSame(404, $status);
        self::assertSame(0, $this->contarAtendimentos());
    }

    public function testCriarAcionamentoPorDocumentoExigeDocumentoValidoENoEscopo(): void
    {
        $ctx = $this->contexto([$this->credorAcme]);
        self::assertSame(422, $this->statusHttp(fn () => $this->controller->criarAcionamentoPorDocumento($this->post('/a', ['resultado_id' => $this->resultadoId, 'observacao' => 'via API', 'contato_utilizado' => '11999998888']), $ctx, ['documento' => '123'], 'r')));

        $ok = $this->controller->criarAcionamentoPorDocumento($this->post('/a', ['resultado_id' => $this->resultadoId, 'observacao' => 'via API', 'contato_utilizado' => '11999998888']), $ctx, ['documento' => $this->documento], 'r');
        self::assertSame(201, $ok->status());
        self::assertSame(1, $this->contarAtendimentos());

        $fora = $this->contexto([$this->credorOutro]);
        self::assertSame(404, $this->statusHttp(fn () => $this->controller->criarAcionamentoPorDocumento($this->post('/a', ['resultado_id' => $this->resultadoId, 'observacao' => 'via API', 'contato_utilizado' => '11999998888']), $fora, ['documento' => $this->documento], 'r')));
    }

    // ---- lote atomico ----------------------------------------------------------------

    public function testLoteValidaFormatoDosItens(): void
    {
        $ctx = $this->contexto([$this->credorAcme]);
        $casos = [
            'sem itens' => ['resultado_id' => $this->resultadoId, 'itens' => []],
            'mais de 100' => ['resultado_id' => $this->resultadoId, 'itens' => array_fill(0, 101, ['contrato' => 'X'])],
            'item com os dois campos' => ['resultado_id' => $this->resultadoId, 'itens' => [['contrato' => 'X', 'documento' => '12345678901']]],
            'item sem nenhum campo' => ['resultado_id' => $this->resultadoId, 'itens' => [[]]],
        ];
        foreach ($casos as $nome => $body) {
            self::assertSame(422, $this->statusHttp(fn () => $this->controller->criarAcionamentosLote($this->post('/lote', $body), $ctx, [], 'r')), $nome);
        }
        self::assertSame(0, $this->contarAtendimentos());
    }

    public function testLoteEAtomicoUmItemInvalidoDesfazTodos(): void
    {
        $ctx = $this->contexto([$this->credorAcme]);
        $body = ['resultado_id' => $this->resultadoId, 'observacao' => 'via API', 'contato_utilizado' => '11999998888', 'itens' => [
            ['contrato' => $this->codigoContrato],
            ['contrato' => 'CONTRATO-QUE-NAO-EXISTE-' . bin2hex(random_bytes(3))],
        ]];

        $status = $this->statusHttp(fn () => $this->controller->criarAcionamentosLote($this->post('/lote', $body), $ctx, [], 'r'));

        self::assertSame(404, $status);
        self::assertSame(0, $this->contarAtendimentos(), 'o 1o item valido nao pode ter sido gravado (rollback)');
    }

    public function testLoteValidoCriaTodosOsAcionamentos(): void
    {
        $ctx = $this->contexto([$this->credorAcme]);
        $body = ['resultado_id' => $this->resultadoId, 'observacao' => 'via API', 'contato_utilizado' => '11999998888', 'itens' => [['contrato' => $this->codigoContrato], ['documento' => $this->documento]]];

        $resposta = $this->controller->criarAcionamentosLote($this->post('/lote', $body), $ctx, [], 'r-lote');

        self::assertSame(201, $resposta->status());
        self::assertSame(2, $resposta->payload()['meta']['created']);
        self::assertSame(2, $this->contarAtendimentos());
    }

    // ---- fechamento de acordo na ACME (dinheiro) -----------------------------------------

    private function acmeResponde(int $status, array $corpo): void
    {
        $this->filaAcme[] = [json_encode($corpo, JSON_THROW_ON_ERROR), $status, ''];
    }

    private function acmeToken(): void
    {
        $this->acmeResponde(200, ['access_token' => 'TOKEN-TESTE', 'expires_in' => 3600]);
    }

    private function acmeSemAcordo(): void
    {
        $this->acmeResponde(404, ['Message' => 'Dados do acordo não encontrados.']);
    }

    /** @return array<string,mixed> */
    private function simulacaoAcme(): array
    {
        return [
            'Amount' => 1500.00,
            'OwnerDescription' => 'Vertex Teste',
            'ListProposalType' => [['ProposalTypeID' => 11, 'ProposalTypeDescription' => '3x de R$ 500,00', 'CalculationType' => 'x']],
            'ListInstallmentDate' => ['2026-10-05T00:00:00'],
        ];
    }

    private function bodyFechar(): array
    {
        return ['negotiation_id' => 11, 'data_primeira_parcela' => '2026-10-05'];
    }

    private function fechar(?AuthContext $ctx = null): ApiResponse
    {
        return $this->controller->fecharAcordoAcme(
            $this->post('/acordos', $this->bodyFechar()),
            $ctx ?? $this->contexto([$this->credorAcme]),
            ['documento' => $this->documento, 'contrato' => $this->codigoContrato],
            'req-fechar-1'
        );
    }

    private function enviouProposta(): bool
    {
        foreach ($this->chamadasAcme as $c) {
            if (str_contains($c['url'], '/v1/acordos')) {
                return true;
            }
        }
        return false;
    }

    public function testFecharAcordoFluxoCompletoConsultaSimulaEEnviaAProposta(): void
    {
        $this->acmeToken();
        $this->acmeSemAcordo();
        $this->acmeResponde(200, $this->simulacaoAcme());
        $this->acmeResponde(200, ['Success' => true]);

        $resposta = $this->fechar();

        self::assertSame(201, $resposta->status());
        self::assertSame('enviado', $resposta->payload()['data']['status_integracao']);
        $urls = array_map(static fn (array $c): string => $c['method'] . ' ' . preg_replace('#^https://[^/]+#', '', $c['url']), $this->chamadasAcme);
        self::assertSame([
            'POST /oauth/token',
            'GET /v1/devedores/' . $this->documento . '/contratos/' . $this->codigoContrato . '/acordo',
            'GET /v1/devedores/' . $this->documento . '/contratos/' . $this->codigoContrato . '/propostas',
            'POST /v1/acordos',
        ], $urls, 'ordem: token, consulta de acordo, simulacoes, so entao envia a proposta');

        parse_str((string) $this->chamadasAcme[3]['content'], $enviado);
        self::assertSame('11', $enviado['NegotiationID']);
        self::assertSame('Vertex Teste', $enviado['OwnerDescription']);
        self::assertSame('2026-10-05T00:00:00', $enviado['InstallmentDate']);

        $acordo = $this->pdo->query("SELECT status_integracao, quantidade_parcelas FROM acordos_operacionais WHERE contrato_id = {$this->contratoId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('enviado', $acordo['status_integracao']);
        self::assertSame(3, (int) $acordo['quantidade_parcelas']);
    }

    public function testContratoRetiradoPeloCredorBloqueiaAntesDeQualquerChamadaAAcme(): void
    {
        $this->pdo->prepare("UPDATE contratos SET status_contrato = 'retirado_pelo_credor' WHERE id = :id")->execute(['id' => $this->contratoId]);

        self::assertSame('CONTRACT_WITHDRAWN', $this->codigoErro(fn () => $this->fechar()));
        self::assertSame([], $this->chamadasAcme);
    }

    public function testContratoJaQuitadoLocalmenteBloqueiaAntesDeQualquerChamadaAAcme(): void
    {
        $this->pdo->prepare("UPDATE contratos SET status_contrato = 'Quitado' WHERE id = :id")->execute(['id' => $this->contratoId]);

        self::assertSame('ACME_DEBT_SETTLED', $this->codigoErro(fn () => $this->fechar()));
        self::assertSame([], $this->chamadasAcme);
    }

    public function testAcmeInformandoDividaLiquidadaBloqueiaEnvioDaProposta(): void
    {
        $this->acmeToken();
        $this->acmeResponde(200, ['HasAgreementHistory' => false, 'Debt' => ['Contract' => $this->codigoContrato, 'StatusDescription' => 'Liquidada'], 'Agreement' => []]);

        self::assertSame('ACME_DEBT_SETTLED', $this->codigoErro(fn () => $this->fechar()));
        self::assertFalse($this->enviouProposta());
    }

    public function testFalhaAoConsultarSituacaoNaAcmeRetorna502ENaoEnviaProposta(): void
    {
        $this->acmeToken();
        $this->filaAcme[] = [false, 0, 'connection refused'];

        self::assertSame('ACME_STATUS_CHECK_ERROR', $this->codigoErro(fn () => $this->fechar()));
        self::assertFalse($this->enviouProposta());
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM acordos_operacionais WHERE contrato_id = {$this->contratoId}"));
    }

    /** @return list<array{0:array<string,mixed>}> */
    public static function selecoesForaDaSimulacao(): array
    {
        return [
            'proposta inexistente' => [['negotiation_id' => 999, 'data_primeira_parcela' => '2026-10-05']],
            'data fora da simulacao' => [['negotiation_id' => 11, 'data_primeira_parcela' => '2027-01-01']],
        ];
    }

    /** @param array<string,mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('selecoesForaDaSimulacao')]
    public function testPropostaOuDataForaDaSimulacaoRetorna422ENaoEnviaNada(array $body): void
    {
        $this->acmeToken();
        $this->acmeSemAcordo();
        $this->acmeResponde(200, $this->simulacaoAcme());

        $status = $this->statusHttp(fn () => $this->controller->fecharAcordoAcme(
            $this->post('/acordos', $body),
            $this->contexto([$this->credorAcme]),
            ['documento' => $this->documento, 'contrato' => $this->codigoContrato],
            'r'
        ));

        self::assertSame(422, $status);
        self::assertFalse($this->enviouProposta());
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM acordos_operacionais WHERE contrato_id = {$this->contratoId}"));
    }

    public function testFalhaNoEnvioDaPropostaNaoCriaAcordoLocal(): void
    {
        $this->acmeToken();
        $this->acmeSemAcordo();
        $this->acmeResponde(200, $this->simulacaoAcme());
        $this->acmeResponde(500, ['Message' => 'erro interno da ACME']);

        self::assertSame('ACME_AGREEMENT_ERROR', $this->codigoErro(fn () => $this->fechar()));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM acordos_operacionais WHERE contrato_id = {$this->contratoId}"), 'ACME recusou: nao pode existir acordo "enviado" localmente');
    }

    public function testFecharAcordoDeContratoDeOutroCredorRetorna404SemChamarAcme(): void
    {
        self::assertSame('ACME_CONTRACT_NOT_FOUND', $this->codigoErro(fn () => $this->fechar($this->contexto([$this->credorOutro]))));
        self::assertSame([], $this->chamadasAcme);
    }

    public function testDocumentoErradoParaOContratoRetorna404(): void
    {
        $status = $this->statusHttp(fn () => $this->controller->fecharAcordoAcme(
            $this->post('/acordos', $this->bodyFechar()),
            $this->contexto([$this->credorAcme]),
            ['documento' => '00000000191', 'contrato' => $this->codigoContrato],
            'r'
        ));

        self::assertSame(404, $status);
        self::assertSame([], $this->chamadasAcme);
    }

    public function testListarAcordosValidaLimite(): void
    {
        $ctx = $this->contexto([$this->credorAcme]);

        self::assertSame(422, $this->statusHttp(fn () => $this->controller->listarAcordos($this->get('/acordos', ['limit' => '500']), $ctx, [], 'r')));
        self::assertSame(200, $this->controller->listarAcordos($this->get('/acordos', ['limit' => '5']), $ctx, [], 'r')->status());
    }
}

