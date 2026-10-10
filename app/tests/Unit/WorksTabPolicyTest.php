<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Services\WorksTabPolicy;
use PHPUnit\Framework\TestCase;

final class WorksTabPolicyTest extends TestCase
{
    public function testNormalizaAbaInvalidaParaAcionamento(): void
    {
        $policy = new WorksTabPolicy();
        self::assertSame('acionamento', $policy->normalizar('qualquer', true));
    }

    public function testSemContratoPermaneceNaBusca(): void
    {
        $policy = new WorksTabPolicy();
        self::assertSame('busca', $policy->normalizar('financeiro', false));
    }

    public function testClassificaBlocosPesadosPorAba(): void
    {
        $policy = new WorksTabPolicy();
        self::assertTrue($policy->usaHistorico('acionamento'));
        self::assertTrue($policy->usaLocalizacao('localizacao'));
        self::assertTrue($policy->usaFinanceiro('financeiro'));
    }
}
