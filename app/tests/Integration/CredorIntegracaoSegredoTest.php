<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;
use PHPUnit\Framework\TestCase;

/**
 * CredorIntegracaoRepository::jsonContemSegredo() (privado, achado
 * 2026-09-17 na auditoria de cobertura) bloqueia salvar senha/token/
 * segredo/chave privada dentro do configuracao_json de uma integração -
 * a regra é "segredo só no .env.local", nunca em config persistida no
 * banco. Zero teste cobria essa regex antes; se ela quebrar (ex.: um
 * refactor que mude o normalizarChave), credenciais passam a poder ser
 * gravadas em texto no banco sem ningum notar.
 *
 * Testado pelo metodo publico salvarIntegracao() (nao via reflection),
 * porque e' o caminho real que um operador usaria.
 */
final class CredorIntegracaoSegredoTest extends TestCase
{
    private PDO $pdo;
    private CredorIntegracaoRepository $repository;
    private int $credorId;
    /** @var list<int> */
    private array $integracoesCriadas = [];

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
        foreach ($this->integracoesCriadas as $id) {
            $this->pdo->prepare('DELETE FROM credor_integracoes WHERE id = :id')->execute(['id' => $id]);
        }
    }

    /** @param array<string,mixed> $configuracao */
    private function salvar(array $configuracao): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $id = $this->repository->salvarIntegracao($this->credorId, [
            'codigo' => 'teste_segredo_' . $sufixo,
            'nome' => 'Teste segredo ' . $sufixo,
            'evento_tipo' => 'acionamento.registrado',
            'tipo_transporte' => 'webhook',
            'adapter' => 'generico',
            'configuracao_json' => $configuracao,
        ], null);
        $this->integracoesCriadas[] = $id;
        return $id;
    }

    /** @return list<array{0:string}> */
    public static function chavesDeSegredo(): array
    {
        return [
            ['password'],
            ['api_password'],
            ['clientSecret'],
            ['api-token'],
            ['chave_privada'],
            ['authorization'],
            ['credentials'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chavesDeSegredo')]
    public function testBloqueiaConfiguracaoComChaveDeSegredoNoTopo(string $chave): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Não grave senha, token, segredo ou chave privada no JSON');
        $this->salvar([$chave => 'valor-qualquer']);
    }

    public function testBloqueiaChaveDeSegredoAninhada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->salvar(['nivel1' => ['nivel2' => ['api_key' => 'valor-qualquer']]]);
    }

    public function testPermiteConfiguracaoSemChaveDeSegredo(): void
    {
        $id = $this->salvar(['endpoint' => 'https://exemplo.test/webhook', 'ativo_desde' => '2026-01-01']);

        self::assertGreaterThan(0, $id);
    }

    public function testNaoBloqueiaChaveComSubstringSemelhanteMasNaoIgual(): void
    {
        // "passwordless_flow" nao deve ser bloqueado - a regex exige limite de palavra,
        // nao so "contem a substring password".
        $id = $this->salvar(['passwordless_flow' => true]);

        self::assertGreaterThan(0, $id);
    }
}
