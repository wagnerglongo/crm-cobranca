<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Services\DadosGeraisFiltroService;
use PHPUnit\Framework\TestCase;

final class DadosGeraisFiltroServiceTest extends TestCase
{
    public function testExigeConfirmacaoQuandoNenhumFiltroEstaAtivo(): void
    {
        $service = new DadosGeraisFiltroService();

        $this->expectException(InvalidArgumentException::class);
        $service->normalizar(['situacao_base' => 'ativos'], true);
    }

    public function testPermiteTodaBaseDoEscopoComConfirmacaoExplicita(): void
    {
        $service = new DadosGeraisFiltroService();
        $filtros = $service->normalizar([
            'situacao_base' => 'ativos',
            'confirmar_todos_escopo' => '1',
        ], true);

        self::assertSame('ativos', $filtros['situacao_base']);
        self::assertFalse($filtros['_segmentacao_ativa']);
    }

    public function testNormalizaAgingEValoresNosFormatosBrasileiroEDecimal(): void
    {
        $service = new DadosGeraisFiltroService();
        $filtros = $service->normalizar([
            'situacao_base' => 'ativos',
            'usar_aging' => '1',
            'aging_min' => '30',
            'aging_max' => '90',
            'usar_valor_faltante' => '1',
            'valor_faltante_min' => 'R$ 1.234,56',
            'valor_faltante_max' => '2500.00',
        ], true);

        self::assertSame(['min' => 30, 'max' => 90], $filtros['aging']);
        self::assertSame(1234.56, $filtros['valor_faltante']['min']);
        self::assertSame(2500.0, $filtros['valor_faltante']['max']);
        self::assertTrue($filtros['_segmentacao_ativa']);
    }

    public function testRejeitaFaixaInvertida(): void
    {
        $service = new DadosGeraisFiltroService();

        $this->expectException(InvalidArgumentException::class);
        $service->normalizar([
            'usar_aging' => '1',
            'aging_min' => '100',
            'aging_max' => '30',
        ], true);
    }

    public function testNormalizaLocalidadeEFormato(): void
    {
        $service = new DadosGeraisFiltroService();
        $filtros = $service->normalizar([
            'usar_localidade' => '1',
            'uf' => 'sp',
            'cidade' => 'São José do Rio Preto',
        ], true);

        self::assertSame('SP', $filtros['uf']);
        self::assertSame('São José do Rio Preto', $filtros['cidade']);
        self::assertSame('base', $service->formato(['formato' => 'base']));
        self::assertSame('mailing', $service->formato(['formato' => 'invalido']));
    }
}
