<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Adapters\AcmeAcionamentoAdapter;
use CRM\Modules\Operacao\Adapters\AcmeCredorAdapter;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;
use CRM\Modules\Operacao\Services\IntegracaoSaidaAdapterFactory;
use PHPUnit\Framework\TestCase;

/**
 * CredorAdapterFactory / IntegracaoSaidaAdapterFactory / AcmeCredorAdapter
 * - continuação da auditoria de cobertura 2026-09-17/18. É o roteamento
 * que decide QUAL integração atende um credor: um alias errado ou um
 * adapter desconhecido silenciosamente aceito mandaria chamada de API
 * pro credor errado (ou nenhuma). Só testa o roteamento - nada aqui faz
 * chamada HTTP (construir o adapter não chama a ACME).
 */
final class AdapterFactoriesTest extends TestCase
{
    private CredorAdapterFactory $credorFactory;

    protected function setUp(): void
    {
        $this->credorFactory = new CredorAdapterFactory(Database::pdo());
    }

    public function testPorCodigoResolveAliasesDaAcmeIgnorandoCaixaEEspacos(): void
    {
        foreach (['ACME', 'acme', ' AcmeCredor ', 'ACME_API'] as $codigo) {
            self::assertInstanceOf(AcmeCredorAdapter::class, $this->credorFactory->porCodigo($codigo), "alias '{$codigo}'");
        }
    }

    public function testPorCodigoDeCredorSemAdapterRetornaNull(): void
    {
        self::assertNull($this->credorFactory->porCodigo('GLOBEX'));
        self::assertNull($this->credorFactory->porCodigo(''));
    }

    public function testParaContratoUsaCredorCodigoOuCaiPraCredorNome(): void
    {
        self::assertInstanceOf(AcmeCredorAdapter::class, $this->credorFactory->paraContrato(['credor_codigo' => 'ACME']));
        self::assertInstanceOf(AcmeCredorAdapter::class, $this->credorFactory->paraContrato(['credor_nome' => 'acme']));
        self::assertNull($this->credorFactory->paraContrato(['credor_codigo' => 'GLOBEX']));
        self::assertNull($this->credorFactory->paraContrato([]));
    }

    public function testAcmeCredorAdapterExpoeCodigoAcme(): void
    {
        self::assertSame('ACME', (new AcmeCredorAdapter())->codigo());
    }

    public function testIntegracaoSaidaCriaAdapterDeAcionamentosDaAcme(): void
    {
        $adapter = (new IntegracaoSaidaAdapterFactory())->criar(' acme_acionamentos ', 30);

        self::assertInstanceOf(AcmeAcionamentoAdapter::class, $adapter);
    }

    public function testIntegracaoSaidaComAdapterDesconhecidoLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Adapter de integração não implementado: generico');
        (new IntegracaoSaidaAdapterFactory())->criar('generico');
    }
}
