<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use PHPUnit\Framework\TestCase;

/**
 * CredorConfiguracaoRepository.php (340 linhas) - continuação da
 * auditoria de cobertura de 2026-09-17. Usa um cache Redis real com
 * invalidação por versão (salvarConfiguracao incrementa a versão,
 * valor() inclui a versão na chave de cache) - zero teste cobria se
 * essa invalidação funciona de verdade (uma quebra aqui faria o CRM
 * continuar usando um valor de configuração desatualizado por até
 * REDIS_CREDOR_CONFIG_TTL segundos depois de alguém mudar).
 */
final class CredorConfiguracaoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CredorConfiguracaoRepository $repository;
    private int $credorId;
    private string $chave;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CredorConfiguracaoRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de configurações de credor indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();
        $this->chave = 'teste_config_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM credor_configuracoes WHERE credor_id = :credor_id AND chave = :chave')
            ->execute(['credor_id' => $this->credorId, 'chave' => $this->chave]);
    }

    public function testSalvarEDepoisLerRetornaOMesmoValor(): void
    {
        $this->repository->salvarConfiguracao($this->credorId, null, $this->chave, 'valor-teste-1');

        self::assertSame('valor-teste-1', $this->repository->valor($this->credorId, $this->chave));
    }

    public function testValorInexistenteRetornaNull(): void
    {
        self::assertNull($this->repository->valor($this->credorId, 'chave_que_nao_existe_' . bin2hex(random_bytes(4))));
    }

    public function testAtualizarValorInvalidaCacheEDevolveValorNovo(): void
    {
        $this->repository->salvarConfiguracao($this->credorId, null, $this->chave, 'valor-original');
        self::assertSame('valor-original', $this->repository->valor($this->credorId, $this->chave));

        // A leitura anterior populou o cache - se a invalidacao por versao
        // nao funcionar, esta segunda leitura devolveria o valor antigo.
        $this->repository->salvarConfiguracao($this->credorId, null, $this->chave, 'valor-atualizado');
        self::assertSame('valor-atualizado', $this->repository->valor($this->credorId, $this->chave));
    }

    public function testValorEspecificoDeProdutoTemPrecedenciaSobreOGeral(): void
    {
        $this->repository->salvarConfiguracao($this->credorId, null, $this->chave, 'valor-geral');
        $this->repository->salvarConfiguracao($this->credorId, 'PRODUTO_X', $this->chave, 'valor-produto-x');

        self::assertSame('valor-produto-x', $this->repository->valor($this->credorId, $this->chave, 'PRODUTO_X'));
        self::assertSame('valor-geral', $this->repository->valor($this->credorId, $this->chave, 'OUTRO_PRODUTO'));
        self::assertSame('valor-geral', $this->repository->valor($this->credorId, $this->chave));
    }

    public function testChaveComFormatoInvalidoLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->salvarConfiguracao($this->credorId, null, 'Chave Com Espaço E Maiúscula', 'valor');
    }

    public function testBooleanoRetornaPadraoQuandoNaoConfigurado(): void
    {
        self::assertTrue($this->repository->booleano($this->credorId, $this->chave, true));
        self::assertFalse($this->repository->booleano($this->credorId, $this->chave, false));
    }

    public function testBooleanoInterpretaValorSalvoCorretamente(): void
    {
        $this->repository->salvarConfiguracao($this->credorId, null, $this->chave, true, 'booleano');

        self::assertTrue($this->repository->booleano($this->credorId, $this->chave, false));
    }
}
