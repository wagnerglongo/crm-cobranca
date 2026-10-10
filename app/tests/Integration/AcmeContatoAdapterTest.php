<?php

declare(strict_types=1);

use CRM\Infrastructure\Redis\RedisCache;
use CRM\Modules\Operacao\Adapters\AcmeContatoAdapter;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Envio de contato pra ACME (WsContactDetails, 2026-10-06) e o ScheduleDateTime
 * do acionamento. Mesmo isolamento do AcmeApiClientTest: NENHUMA chamada real
 * sai (transporte falso, URLs .invalid, circuito com nome único).
 */
final class AcmeContatoAdapterTest extends TestCase
{
    private const BASE = 'https://acme-teste.invalid';
    private const DOC = '12345678909';

    private string $dir;
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
        $this->dir = sys_get_temp_dir() . '/acme_contato_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->circuito = 'acme-teste-contato-' . bin2hex(random_bytes(4));
        $novos = [
            'ACME_API_BASE_URL' => self::BASE,
            'ACME_API_TOKEN_BASE_URL' => 'https://acme-token-teste.invalid',
            'ACME_API_USERNAME' => 'usuario-teste-' . bin2hex(random_bytes(4)),
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
            RedisCache::default()->delete((string) (new ReflectionMethod($cliente, 'redisTokenCacheKey'))->invoke($cliente));
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

    private function responde(int $status, mixed $corpo): void
    {
        $this->fila[] = [json_encode($corpo, JSON_THROW_ON_ERROR), $status, ''];
    }

    /** Chamadas à API de contatos (ignora o pedido de token). @return list<array{url:string,method:string,headers:list<string>,content:?string}> */
    private function chamadasApi(): array
    {
        return array_values(array_filter($this->chamadas, static fn (array $c): bool => str_starts_with($c['url'], self::BASE)));
    }

    /** @param array<string,mixed> $contato @param array<string,bool> $alteracoes */
    private function enviar(array $contato, array $alteracoes = ['status' => true, 'tipo' => true]): array
    {
        // O token é a 1ª chamada do 1º cliente do teste (os seguintes usam o
        // token em cache): entra na frente das respostas já enfileiradas.
        if ($this->clientes === []) {
            array_unshift($this->fila, [json_encode(['access_token' => 'TOKEN', 'expires_in' => 3600]), 200, '']);
        }
        $adapter = new AcmeContatoAdapter($this->cliente());
        $requisicao = $adapter->preparar(['payload_array' => [
            'pessoa' => ['documento' => self::DOC],
            'contato' => $contato,
            'alteracoes' => $alteracoes,
        ]]);
        return $adapter->enviar($requisicao);
    }

    /** @return array<string,mixed> */
    private function telefoneManual(array $extra = []): array
    {
        return $extra + [
            'entidade' => 'telefone', 'ddd' => '11', 'numero' => '987654321',
            'tipo_acme' => 15, 'tipo_inclusao' => 15, 'status_acme' => 'P', 'status_inclusao' => 'P',
            'permitir_inclusao' => true,
        ];
    }

    public function testTelefoneNovoDoOperadorEIncluidoComDddSeparadoERecordIdDaAcme(): void
    {
        $this->responde(200, [['RecordID' => 5156355, 'PhoneID' => 1, 'DDD' => 21, 'PhoneNumber' => '33334444', 'PhoneType' => 1, 'Status' => 'N']]);
        $this->responde(200, ['PhoneID' => 999]);

        $r = $this->enviar($this->telefoneManual());

        $api = $this->chamadasApi();
        self::assertCount(2, $api);
        self::assertSame('GET', $api[0]['method']);
        self::assertSame(self::BASE . '/v1/devedores/' . self::DOC . '/telefones', $api[0]['url']);
        self::assertSame('POST', $api[1]['method']);
        self::assertSame(self::BASE . '/v1/contatos/telefones', $api[1]['url']);
        self::assertContains('Content-Type: application/json; charset=utf-8', $api[1]['headers']);
        self::assertSame([
            'DocNumber' => self::DOC, 'DDI' => 55, 'DDD' => 11, 'PhoneNumber' => '987654321',
            'PhoneType' => 15, 'Status' => 'P', 'RecordID' => 5156355,
        ], json_decode((string) $api[1]['content'], true));
        self::assertSame('incluido', $r['response']['acao']);
        self::assertSame('incluido #999', $r['protocolo']);
    }

    public function testTelefoneQueJaEstaNaAcmeNaoDuplicaSoAtualizaOStatus(): void
    {
        // A ACME devolve o número já com o DDD dentro: tem que reconhecer igual.
        $this->responde(200, [['RecordID' => 7, 'PhoneID' => 321, 'DDD' => 11, 'PhoneNumber' => '11987654321', 'PhoneType' => 4, 'Status' => 'N']]);
        $this->responde(200, ['ok' => true]);

        $r = $this->enviar($this->telefoneManual(['status_acme' => 'I']), ['status' => true, 'tipo' => false]);

        $api = $this->chamadasApi();
        self::assertCount(2, $api, 'nenhuma inclusão');
        self::assertSame('PUT', $api[1]['method']);
        // Só o status muda; o tipo vai com o valor atual da ACME (4), como o Swagger pede.
        self::assertSame(self::BASE . '/v1/devedores/' . self::DOC . '/telefones/321/status/4/I', $api[1]['url']);
        self::assertSame('', $api[1]['content'], 'PUT vai com corpo vazio explícito (Content-Length: 0)');
        self::assertSame('status_atualizado', $r['response']['acao']);
    }

    public function testTelefoneIgualNaAcmeNaoFazNada(): void
    {
        $this->responde(200, [['PhoneID' => 321, 'DDD' => 11, 'PhoneNumber' => '987654321', 'PhoneType' => 15, 'Status' => 'P']]);

        $r = $this->enviar($this->telefoneManual());

        self::assertCount(1, $this->chamadasApi(), 'só a consulta');
        self::assertSame('sem_mudanca', $r['response']['acao']);
    }

    public function testContatoQueVeioDaAcmeEJaNaoEstaLaNaoEIncluido(): void
    {
        $this->responde(200, []);

        $r = $this->enviar($this->telefoneManual(['permitir_inclusao' => false]));

        self::assertCount(1, $this->chamadasApi());
        self::assertSame('ignorado', $r['response']['acao']);
    }

    public function testDevedorSemTelefoneNaAcme404ViraListaVaziaEIncluiMesmoAssim(): void
    {
        $this->responde(404, ['Message' => 'Nenhum registro encontrado']);
        $this->responde(200, ['PhoneID' => 1]);

        $r = $this->enviar($this->telefoneManual());

        self::assertSame('incluido', $r['response']['acao']);
        self::assertArrayNotHasKey('RecordID', json_decode((string) $this->chamadasApi()[1]['content'], true));
    }

    public function testEmailNovoEStatusDeEmailExistente(): void
    {
        $this->responde(200, [['RecordID' => 9, 'MailID' => 50, 'MailAddress' => 'outro@x.com', 'Status' => 'N']]);
        $this->responde(200, ['MailID' => 51]);
        $r = $this->enviar(['entidade' => 'email', 'email' => 'cliente@x.com', 'status_acme' => 'P', 'status_inclusao' => 'P', 'tipo_inclusao' => 1, 'permitir_inclusao' => true]);
        self::assertSame('incluido', $r['response']['acao']);
        self::assertSame(
            ['DocNumber' => self::DOC, 'MailAddress' => 'cliente@x.com', 'Status' => 'P', 'EmailType' => 1, 'RecordID' => 9],
            json_decode((string) $this->chamadasApi()[1]['content'], true)
        );

        $this->chamadas = [];
        $this->responde(200, [['MailID' => 50, 'MailAddress' => 'Cliente@X.com', 'Status' => 'N']]);
        $this->responde(200, ['ok' => true]);
        $r = $this->enviar(['entidade' => 'email', 'email' => 'cliente@x.com', 'status_acme' => 'I', 'permitir_inclusao' => false], ['status' => true, 'tipo' => false]);
        self::assertSame(self::BASE . '/v1/devedores/' . self::DOC . '/emails/50/status/I', $this->chamadasApi()[1]['url']);
        self::assertSame('status_atualizado', $r['response']['acao']);
    }

    public function testEnderecoNovoComNumeroTextoVaiNoComplemento(): void
    {
        $this->responde(200, []);
        $this->responde(200, ['AddressID' => 77]);

        $r = $this->enviar([
            'entidade' => 'endereco', 'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'numero' => 100,
            'complemento' => 'Apto 1 - Nº 100A', 'bairro' => 'Sé', 'cidade' => 'São Paulo', 'uf' => 'SP',
            'tipo_acme' => 'R', 'tipo_inclusao' => 'R', 'status_inclusao' => 'P', 'permitir_inclusao' => true,
        ]);

        self::assertSame('incluido', $r['response']['acao']);
        self::assertSame([
            'DocNumber' => self::DOC, 'AddressType' => 'R', 'Locale' => 'Praça da Sé', 'Number' => 100,
            'Complement' => 'Apto 1 - Nº 100A', 'Region' => 'Sé', 'City' => 'São Paulo', 'State' => 'SP',
            'CEP' => '01001000', 'AddressesStatus' => 'P',
        ], json_decode((string) $this->chamadasApi()[1]['content'], true));
    }

    public function testEnderecoExistenteSoMudaOTipoMantendoOStatusDaAcme(): void
    {
        $this->responde(200, [['AddressID' => 77, 'CEP' => '01001000', 'Number' => 100, 'Locale' => 'PRACA DA SE', 'AddressType' => 'N', 'AddressesStatus' => 'O']]);
        $this->responde(200, ['ok' => true]);

        $r = $this->enviar(['entidade' => 'endereco', 'cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => 100, 'tipo_acme' => 'E', 'permitir_inclusao' => false], ['status' => false, 'tipo' => true]);

        self::assertSame(self::BASE . '/v1/devedores/' . self::DOC . '/enderecos/77/status/E/O', $this->chamadasApi()[1]['url']);
        self::assertSame('status_atualizado', $r['response']['acao']);
    }

    public function testPrepararRecusaTelefoneSemDdd(): void
    {
        $adapter = new AcmeContatoAdapter($this->cliente());
        $this->expectException(InvalidArgumentException::class);
        $adapter->preparar(['payload_array' => ['pessoa' => ['documento' => self::DOC], 'contato' => ['entidade' => 'telefone', 'numero' => '987654321']]]);
    }

    public function testTabelasDeCodigosDaAcme(): void
    {
        $this->responde(200, ['access_token' => 'TOKEN', 'expires_in' => 3600]);
        $this->responde(200, [['Type' => '15', 'TypeAlias' => 'Celular / Whats']]);
        $this->responde(200, [['Status' => 'H', 'StatusAlias' => 'Hot']]);
        $cliente = $this->cliente();

        self::assertSame([['Type' => '15', 'TypeAlias' => 'Celular / Whats']], $cliente->listarTiposContato(1));
        self::assertSame([['Status' => 'H', 'StatusAlias' => 'Hot']], $cliente->listarStatusContato());
        $api = $this->chamadasApi();
        self::assertSame(self::BASE . '/v1/catalogos/tipos-contato/1', $api[0]['url']);
        self::assertSame(self::BASE . '/v1/catalogos/status-contato', $api[1]['url']);
    }

    /** Achado 2026-10-06: a data de reagendamento era descartada antes de sair pra ACME. */
    public function testAcionamentoLevaOScheduleDateTime(): void
    {
        $this->responde(200, ['access_token' => 'TOKEN', 'expires_in' => 3600]);
        $this->responde(200, ['TrackingID' => 1]);

        $this->cliente()->inserirAcionamento([
            'DocNumber' => self::DOC, 'Contract' => '900004434', 'ActionID' => 4, 'Notes' => 'Recado',
            'CreationDate' => '2026-10-06T13:00:00.000Z', 'ScheduleDateTime' => '2026-10-07T12:00:00.000Z',
        ]);

        $corpo = json_decode((string) $this->chamadasApi()[0]['content'], true);
        self::assertSame('2026-10-07T12:00:00.000Z', $corpo['ScheduleDateTime']);
    }
}
