<?php

declare(strict_types=1);

use CRM\Infrastructure\Redis\RedisCache;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use CRM\Modules\Operacao\Services\AcmeApiException;
use PHPUnit\Framework\TestCase;

/**
 * AcmeApiClient - o cliente do integrador de PRODUCAO da ACME (acordos, boletos,
 * acionamentos = dinheiro real). Antes desta rodada nao tinha teste nenhum porque
 * a chamada de rede era hardcoded. Agora o construtor aceita um transporte
 * injetavel (callable) e um nome de circuito proprio: aqui NENHUMA chamada real
 * sai pra ACME, e o circuit breaker usa um nome unico (nunca o 'acme' de producao,
 * senao um teste de falha abriria o circuito real dos operadores).
 *
 * Isolamento do que e' global: credenciais/URLs falsas via $_ENV (restauradas no
 * tearDown), logs e cache de token em diretorio temporario, chave de token no
 * Redis derivada do usuario falso (unico por teste) e apagada no tearDown.
 */
final class AcmeApiClientTest extends TestCase
{
    private const BASE = 'https://acme-teste.invalid';
    private const TOKEN_BASE = 'https://acme-token-teste.invalid';

    private string $dir;
    private string $usuarioFalso;
    private string $circuito;
    /** @var array<string,mixed> */
    private array $envOriginal = [];
    /** @var list<array{url:string,method:string,headers:list<string>,content:?string}> */
    private array $chamadas = [];
    /** @var list<array{0:mixed,1:int,2:string}> */
    private array $fila = [];
    /** @var list<AcmeApiClient> */
    private array $clientes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/acme_teste_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->usuarioFalso = 'usuario-teste-' . bin2hex(random_bytes(4));
        $this->circuito = 'acme-teste-' . bin2hex(random_bytes(4));

        $novos = [
            'ACME_API_BASE_URL' => self::BASE,
            'ACME_API_TOKEN_BASE_URL' => self::TOKEN_BASE,
            'ACME_API_USERNAME' => $this->usuarioFalso,
            'ACME_API_PASSWORD' => 'senha-falsa',
            'ACME_API_TIMEOUT' => '5',
            'ACME_API_LOG_FILE' => $this->dir . '/acme_api.log',
            'ACME_API_STRUCTURED_LOG_FILE' => $this->dir . '/integracoes_acme.jsonl',
        ];
        foreach ($novos as $chave => $valor) {
            $this->envOriginal[$chave] = array_key_exists($chave, $_ENV) ? $_ENV[$chave] : null;
            $_ENV[$chave] = $valor;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->clientes as $cliente) {
            $metodo = new ReflectionMethod($cliente, 'redisTokenCacheKey');
            RedisCache::default()->delete((string) $metodo->invoke($cliente));
        }
        foreach ($this->envOriginal as $chave => $valor) {
            if ($valor === null) {
                unset($_ENV[$chave]);
            } else {
                $_ENV[$chave] = $valor;
            }
        }
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dir);
    }

    private function cliente(): AcmeApiClient
    {
        $transporte = function (string $url, string $method, array $headers, ?string $content): array {
            $this->chamadas[] = ['url' => $url, 'method' => $method, 'headers' => $headers, 'content' => $content];
            if ($this->fila === []) {
                throw new LogicException('transporte falso sem resposta na fila para ' . $method . ' ' . $url);
            }
            return array_shift($this->fila);
        };
        $cliente = new AcmeApiClient($this->dir . '/token.json', null, $transporte, $this->circuito);
        $this->clientes[] = $cliente;
        return $cliente;
    }

    /** @param array<string,mixed> $corpo */
    private function resposta(int $status, array $corpo): void
    {
        $this->fila[] = [json_encode($corpo, JSON_THROW_ON_ERROR), $status, ''];
    }

    private function tokenOk(string $token = 'TOKEN-1'): void
    {
        $this->resposta(200, ['access_token' => $token, 'expires_in' => 3600]);
    }

    private function bearer(int $indiceChamada): string
    {
        foreach ($this->chamadas[$indiceChamada]['headers'] as $h) {
            if (str_starts_with($h, 'Authorization: Bearer ')) {
                return substr($h, strlen('Authorization: Bearer '));
            }
        }
        return '';
    }

    public function testSemCredenciaisNaoEstaConfiguradoENaoChamaRede(): void
    {
        $_ENV['ACME_API_USERNAME'] = '';
        $cliente = $this->cliente();

        self::assertFalse($cliente->configurado());
        try {
            $cliente->baixarBoletoParcela(1);
            self::fail('deveria lançar exceção');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('não configurada', $e->getMessage());
        }
        self::assertSame([], $this->chamadas);
    }

    public function testTokenEPedidoNoHostDeTokenEChamadaUsaBearerNoHostDaApi(): void
    {
        $this->tokenOk('TOKEN-ABC');
        $this->resposta(200, ['Url' => 'https://boleto.invalid/1']);

        $resposta = $this->cliente()->baixarBoletoParcela(123);

        self::assertCount(2, $this->chamadas);
        self::assertSame('POST', $this->chamadas[0]['method']);
        self::assertSame(self::TOKEN_BASE . '/oauth/token', $this->chamadas[0]['url']);
        self::assertStringContainsString('grant_type=password', (string) $this->chamadas[0]['content']);
        self::assertStringContainsString('username=' . $this->usuarioFalso, (string) $this->chamadas[0]['content']);

        self::assertSame('GET', $this->chamadas[1]['method']);
        self::assertSame(self::BASE . '/v1/parcelas/123/boleto', $this->chamadas[1]['url']);
        self::assertSame('TOKEN-ABC', $this->bearer(1));
        self::assertSame(200, $resposta['http_status']);
        self::assertSame('https://boleto.invalid/1', $resposta['Url']);
    }

    public function testTokenEReaproveitadoEntreChamadasSeguidas(): void
    {
        $this->tokenOk();
        $this->resposta(200, ['ok' => 1]);
        $this->resposta(200, ['ok' => 2]);
        $cliente = $this->cliente();

        $cliente->baixarBoletoParcela(1);
        $cliente->baixarBoletoParcela(2);

        self::assertCount(3, $this->chamadas, '1 pedido de token + 2 chamadas de negócio');
    }

    public function testRespostaTokenInvalidoLancaExcecao(): void
    {
        $this->resposta(200, ['access_token' => '', 'expires_in' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token inválido retornado pela API ACME.');
        $this->cliente()->baixarBoletoParcela(1);
    }

    /**
     * Token recusado (401 "Authorization has been denied") na primeira tentativa:
     * o cliente renova o token e refaz A MESMA chamada uma unica vez.
     */
    public function testTokenRecusadoRenovaETentaDeNovo(): void
    {
        $this->tokenOk('TOKEN-VELHO');
        $this->resposta(401, ['Message' => 'Authorization has been denied for this request.']);
        $this->tokenOk('TOKEN-NOVO');
        $this->resposta(200, ['Url' => 'ok']);

        $resposta = $this->cliente()->baixarBoletoParcela(9);

        self::assertCount(4, $this->chamadas);
        self::assertSame('TOKEN-VELHO', $this->bearer(1));
        self::assertSame('TOKEN-NOVO', $this->bearer(3));
        self::assertSame('ok', $resposta['Url']);
    }

    public function testRecusaPersistenteAposRenovacaoLancaExcecaoSemLoopInfinito(): void
    {
        $this->tokenOk('T1');
        $this->resposta(401, ['Message' => 'Authorization has been denied for this request.']);
        $this->tokenOk('T2');
        $this->resposta(401, ['Message' => 'Authorization has been denied for this request.']);

        try {
            $this->cliente()->baixarBoletoParcela(9);
            self::fail('deveria lançar exceção');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('após uma renovação de token', $e->getMessage());
        }
        self::assertCount(4, $this->chamadas, 'no maximo 1 renovacao + 1 nova tentativa');
    }

    /** @return list<array{0:int,1:bool}> */
    public static function statusHttpERetentavel(): array
    {
        return [[500, true], [503, true], [429, true], [408, true], [400, false], [404, false], [422, false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusHttpERetentavel')]
    public function testErroHttpEClassificadoComoRetentavelOuNao(int $status, bool $retentavel): void
    {
        $this->tokenOk();
        $this->resposta($status, ['Message' => 'algo deu errado']);

        try {
            $this->cliente()->baixarBoletoParcela(1);
            self::fail('deveria lançar AcmeApiException');
        } catch (AcmeApiException $e) {
            self::assertSame($status, $e->httpStatus());
            self::assertSame($retentavel, $e->retryable(), "HTTP {$status}");
        }
    }

    public function testFalhaDeRedeELancadaComoRetentavel(): void
    {
        $this->tokenOk();
        $this->fila[] = [false, 0, 'Could not resolve host'];

        try {
            $this->cliente()->baixarBoletoParcela(1);
            self::fail('deveria lançar AcmeApiException');
        } catch (AcmeApiException $e) {
            self::assertTrue($e->retryable());
            self::assertStringContainsString('Falha de comunicação', $e->getMessage());
        }
    }

    public function testRespostaNaoJsonComStatus200EhInvalida(): void
    {
        $this->tokenOk();
        $this->fila[] = ['<html>manutencao</html>', 200, ''];

        $this->expectException(AcmeApiException::class);
        $this->expectExceptionMessage('Resposta inválida da API ACME');
        $this->cliente()->baixarBoletoParcela(1);
    }

    public function testInserirAcionamentoValidaEntradaAntesDeQualquerChamadaDeRede(): void
    {
        $cliente = $this->cliente();
        $base = ['DocNumber' => '12345678901', 'Contract' => 'CTR-1', 'ActionID' => 5, 'CreationDate' => '2026-09-18T10:00:00'];

        $casos = [
            'documento curto' => array_merge($base, ['DocNumber' => '123']),
            'contrato vazio' => array_merge($base, ['Contract' => '  ']),
            'ActionID invalido' => array_merge($base, ['ActionID' => 0]),
            'sem data' => array_merge($base, ['CreationDate' => '']),
            'contrato com caractere de controle' => array_merge($base, ['Contract' => "CTR\x01"]),
        ];
        foreach ($casos as $nome => $dados) {
            try {
                $cliente->inserirAcionamento($dados);
                self::fail("{$nome}: deveria lançar exceção");
            } catch (RuntimeException) {
                // esperado
            }
        }
        self::assertSame([], $this->chamadas, 'entrada invalida nunca pode gerar chamada pra ACME');
    }

    public function testInserirAcionamentoEnviaJsonComDadosNormalizados(): void
    {
        $this->tokenOk();
        $this->fila[] = ['', 200, ''];

        $resposta = $this->cliente()->inserirAcionamento([
            'DocNumber' => '123.456.789-01',
            'Contract' => ' CTR-1 ',
            'ActionID' => '7',
            'Notes' => 'Cliente atendeu',
            'CreationDate' => '2026-09-18T10:00:00',
        ]);

        self::assertSame('POST', $this->chamadas[1]['method']);
        self::assertSame(self::BASE . '/v1/acionamentos', $this->chamadas[1]['url']);
        self::assertContains('Content-Type: application/json; charset=utf-8', $this->chamadas[1]['headers']);
        $enviado = json_decode((string) $this->chamadas[1]['content'], true);
        self::assertSame('12345678901', $enviado['DocNumber']);
        self::assertSame('CTR-1', $enviado['Contract']);
        self::assertSame(7, $enviado['ActionID']);
        self::assertTrue($resposta['ok'], 'resposta vazia com sucesso HTTP é aceita neste endpoint');
    }

    public function testConsultarAcordoInexistenteRetornaRespostaEstruturadaEmVezDeErro(): void
    {
        $this->tokenOk();
        $this->resposta(404, ['Message' => 'Dados do acordo não encontrados.']);

        $resposta = $this->cliente()->consultarAcordo('123.456.789-01', "'CTR-9'");

        self::assertTrue($resposta['agreement_not_found']);
        self::assertFalse($resposta['HasAgreementHistory']);
        self::assertSame('12345678901', $resposta['DocNumber']);
        self::assertSame('CTR-9', $resposta['Debt']['Contract']);
        self::assertSame(self::BASE . '/v1/devedores/12345678901/contratos/CTR-9/acordo', $this->chamadas[1]['url']);
    }

    public function testConsultarAcordoComOutroErro404DaAcmeContinuaSendoExcecao(): void
    {
        $this->tokenOk();
        $this->resposta(404, ['Message' => 'Rota inexistente']);

        $this->expectException(AcmeApiException::class);
        $this->cliente()->consultarAcordo('12345678901', 'CTR-9');
    }

    public function testSalvarPropostaExigeCamposObrigatoriosEEnviaFormSanitizado(): void
    {
        $cliente = $this->cliente();
        try {
            $cliente->salvarPropostaGerada(['DocNumber' => '12345678901', 'Contract' => 'C1', 'OwnerDescription' => '', 'NegotiationID' => 3, 'InstallmentDate' => '2026-10-01']);
            self::fail('OwnerDescription vazio deveria falhar');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('OwnerDescription', $e->getMessage());
        }
        self::assertSame([], $this->chamadas);

        $this->tokenOk();
        $this->resposta(200, ['Success' => true]);
        $cliente->salvarPropostaGerada([
            'UserID' => 4, 'DocNumber' => '123.456.789-01', 'Contract' => "' C/T R-1 '", 'OwnerDescription' => 'Vertex',
            'NegotiationID' => '3', 'PhoneID' => 0, 'InstallmentDate' => '2026-10-01',
        ]);

        parse_str((string) $this->chamadas[1]['content'], $form);
        self::assertSame('12345678901', $form['DocNumber']);
        self::assertSame('CTR-1', $form['Contract'], 'contrato sanitizado (aspas/espacos/barra removidos)');
        self::assertSame('3', $form['NegotiationID']);
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $this->chamadas[1]['headers']);
    }

    public function testBuscarDevedorComHttp404VirapendenciaDeEndpointNaoRetentavel(): void
    {
        $this->tokenOk();
        $this->resposta(404, ['Message' => 'Not found']);

        try {
            $this->cliente()->buscarDevedorPorDocumento('12345678901');
            self::fail('deveria lançar exceção');
        } catch (AcmeApiException $e) {
            self::assertSame(404, $e->httpStatus());
            self::assertFalse($e->retryable());
            self::assertStringContainsString('Endpoint de localização ACME indisponível', $e->getMessage());
        }
    }

    public function testConsultarAcionamentosAceitaListaDireta(): void
    {
        $this->tokenOk();
        $this->fila[] = [json_encode([['TrackingID' => 1], 'lixo', ['TrackingID' => 2]]), 200, ''];

        $itens = $this->cliente()->consultarAcionamentos('12345678901');

        self::assertSame([['TrackingID' => 1], ['TrackingID' => 2]], $itens, 'itens nao-array sao descartados');
    }

    /**
     * Circuit breaker: depois de 5 falhas retentaveis seguidas o cliente passa a falhar
     * rapido SEM chamar a rede - protege o pool de workers dos operadores quando a ACME cai.
     * (Circuito de nome unico deste teste; o 'acme' real nao e tocado.)
     */
    public function testCircuitBreakerAbreAposFalhasSeguidasEParaDeChamarARede(): void
    {
        $this->tokenOk();
        $cliente = $this->cliente();

        for ($i = 0; $i < 5; $i++) {
            $this->resposta(500, ['Message' => 'erro interno']);
            try {
                $cliente->baixarBoletoParcela($i + 1);
            } catch (AcmeApiException) {
            }
        }
        $chamadasAntes = count($this->chamadas);

        try {
            $cliente->baixarBoletoParcela(99);
            self::fail('circuito aberto deveria lançar exceção');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('indisponível (muitas falhas recentes)', $e->getMessage());
        }
        self::assertCount($chamadasAntes, $this->chamadas, 'com o circuito aberto nenhuma chamada de rede acontece');
    }

    public function testErroNaoRetentavelNaoContaParaOCircuitBreaker(): void
    {
        $this->tokenOk();
        $cliente = $this->cliente();

        for ($i = 0; $i < 8; $i++) {
            $this->resposta(400, ['Message' => 'requisicao invalida']);
            try {
                $cliente->baixarBoletoParcela($i + 1);
            } catch (AcmeApiException) {
            }
        }
        $this->resposta(200, ['Url' => 'ainda funciona']);

        $resposta = $cliente->baixarBoletoParcela(50);

        self::assertSame('ainda funciona', $resposta['Url']);
    }
}
