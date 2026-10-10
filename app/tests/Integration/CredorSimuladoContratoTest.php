<?php

declare(strict_types=1);

use CRM\Infrastructure\Redis\RedisCache;
use CRM\Modules\Operacao\Adapters\AcmeCredorAdapter;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use CredorSimulado\Simulador;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/docker/credor-simulado/src/Simulador.php';

/**
 * Teste de contrato: o AcmeApiClient de verdade conversa com o simulador do
 * credor (docker/credor-simulado), sem rede - o transporte HTTP do cliente é
 * trocado por uma chamada direta ao Simulador. Se alguém mudar uma rota ou o
 * formato de um payload de um lado só, este teste quebra.
 */
final class CredorSimuladoContratoTest extends TestCase
{
    private const BASE = 'http://credor-simulado.test';
    private const CPF = '52998224725';
    private const CONTRATO = 'CTR-2026-0001';

    private string $dir;
    private Simulador $simulador;
    private AcmeApiClient $client;
    /** @var array<string,?string> */
    private array $envOriginal = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/credor_simulado_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);

        $env = [
            'ACME_API_BASE_URL' => self::BASE,
            'ACME_API_TOKEN_BASE_URL' => self::BASE,
            'ACME_API_USERNAME' => 'demo',
            'ACME_API_PASSWORD' => 'demo-' . bin2hex(random_bytes(3)),
            'ACME_API_LOG_FILE' => $this->dir . '/acme_api.log',
            'ACME_API_STRUCTURED_LOG_FILE' => $this->dir . '/integracoes.jsonl',
        ];
        foreach ($env as $chave => $valor) {
            $this->envOriginal[$chave] = $_ENV[$chave] ?? null;
            $_ENV[$chave] = $valor;
        }

        $this->simulador = new Simulador($this->dir . '/estado.json', 'demo', $env['ACME_API_PASSWORD'], new DateTimeImmutable('2026-10-07'));
        $transporte = function (string $url, string $method, array $headers, ?string $content): array {
            $tipo = '';
            $autorizacao = '';
            foreach ($headers as $header) {
                [$nome, $valor] = array_map('trim', explode(':', $header, 2)) + [1 => ''];
                if (strcasecmp($nome, 'Content-Type') === 0) {
                    $tipo = $valor;
                } elseif (strcasecmp($nome, 'Authorization') === 0) {
                    $autorizacao = $valor;
                }
            }
            [$status, $corpo] = $this->simulador->atender($method, (string) parse_url($url, PHP_URL_PATH), (string) $content, $tipo, $autorizacao);
            return [json_encode($corpo, JSON_THROW_ON_ERROR), $status, ''];
        };
        $this->client = new AcmeApiClient($this->dir . '/token.json', 5, $transporte, 'simulador-' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        $chave = (new ReflectionMethod($this->client, 'redisTokenCacheKey'))->invoke($this->client);
        RedisCache::default()->delete((string) $chave);
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

    public function testSemTokenValidoOSimuladorRecusa(): void
    {
        [$status, $corpo] = $this->simulador->atender('GET', '/v1/devedores/' . self::CPF, '', '', 'Bearer qualquer');

        self::assertSame(401, $status);
        self::assertSame('Authorization has been denied for this request.', $corpo['Message']);
    }

    public function testConsultaDevedorDevolveContatosNoFormatoEsperadoPeloCrm(): void
    {
        $devedor = $this->client->buscarDevedorPorDocumento(self::CPF);

        self::assertSame(self::CPF, $devedor['DocNumber']);
        self::assertNotEmpty($devedor['RecordsPhones']);
        self::assertArrayHasKey('PhoneID', $devedor['RecordsPhones'][0]);
        self::assertMatchesRegularExpression('/^\d{2}$/', $devedor['RecordsPhones'][0]['DDD']);
        self::assertStringEndsWith('@exemplo.com.br', $devedor['RecordsMails'][0]['MailAddress']);
        self::assertSame($devedor, $this->client->buscarDevedorPorDocumento(self::CPF), 'mesmo documento, mesmo devedor');
    }

    public function testFluxoCompletoDePropostaAcordoEBoleto(): void
    {
        $semAcordo = $this->client->consultarAcordo(self::CPF, self::CONTRATO);
        self::assertTrue($semAcordo['agreement_not_found']);

        $propostas = $this->client->gerarPropostasPortal(self::CPF, self::CONTRATO);
        self::assertCount(4, $propostas['ListProposalType']);
        self::assertStringStartsWith('À vista de R$ ', $propostas['ListProposalType'][0]['ProposalTypeDescription']);
        self::assertSame('2026-10-08T00:00:00', $propostas['ListInstallmentDate'][0], 'primeiro dia útil depois de 07/10/2026');

        $adapter = new AcmeCredorAdapter($this->client);
        $resposta = $adapter->criarAcordo([
            'UserID' => 1,
            'DocNumber' => self::CPF,
            'Contract' => self::CONTRATO,
            'OwnerDescription' => $propostas['OwnerDescription'],
            'NegotiationID' => 2,
            'InstallmentDate' => '2026-10-08',
        ]);
        self::assertNotSame('', $resposta['AgreementID']);

        $acordo = $this->client->consultarAcordo(self::CPF, self::CONTRATO);
        self::assertSame('Ativo', $acordo['Agreement']['AgreementStatus']);
        self::assertCount(3, $acordo['Agreement']['ListInstallments']);
        self::assertSame('0/3', $acordo['Agreement']['TotalInstalments']);

        $info = \CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository::interpretarAcordoAcme($acordo);
        self::assertNotNull($info, 'o CRM precisa conseguir interpretar o acordo devolvido pelo simulador');
        self::assertCount(3, $info['parcelas']);

        $parcelaId = (int) $acordo['Agreement']['ListInstallments'][0]['InstallmentID'];
        $boleto = $adapter->consultarBoleto($parcelaId);
        self::assertSame(47, strlen($boleto['DigitableLine']));
        self::assertStringStartsWith('%PDF-1.4', (string) base64_decode($boleto['Base64'], true));
    }

    public function testAcionamentoEnviadoApareceNaConsulta(): void
    {
        $this->client->inserirAcionamento([
            'DocNumber' => self::CPF,
            'Contract' => self::CONTRATO,
            'ActionID' => 6,
            'Notes' => 'Cliente vai estudar a proposta',
            'CreationDate' => '2026-10-07T10:30:00',
        ]);

        $historico = $this->client->consultarAcionamentos(self::CPF);
        self::assertCount(1, $historico);
        self::assertSame(6, $historico[0]['ActionID']);
        self::assertSame('Cliente vai estudar a proposta', $historico[0]['Notes']);
    }

    public function testInclusaoEStatusDeTelefone(): void
    {
        $this->client->inserirTelefone(['DocNumber' => self::CPF, 'DDD' => '11', 'PhoneNumber' => '988887777', 'PhoneType' => '4', 'Status' => 'P']);
        $telefones = $this->client->listarTelefones(self::CPF);
        $novo = end($telefones);
        self::assertSame('988887777', $novo['PhoneNumber']);

        $this->client->atualizarStatusTelefone(self::CPF, (int) $novo['PhoneID'], 4, 'I');
        $atualizado = array_values(array_filter($this->client->listarTelefones(self::CPF), static fn (array $t): bool => $t['PhoneID'] === $novo['PhoneID']));
        self::assertSame('I', $atualizado[0]['Status']);
    }
}
