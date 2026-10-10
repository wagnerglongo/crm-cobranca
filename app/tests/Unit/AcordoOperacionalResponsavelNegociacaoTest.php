<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Classificacao do "Responsavel pela negociacao" (achado 2026-09-17: o
 * A operação pediu pra trocar o filtro de fila do Controle de Acordos, que
 * usava contratos.fila, pelo texto "Responsavel: X" que a propria API da
 * ACME retorna em Agreement.NegotiationNotes). Nenhum teste cobria essa
 * logica antes - so validacao manual contra dado real.
 */
final class AcordoOperacionalResponsavelNegociacaoTest extends TestCase
{
    /** @return list<array{0:string,1:string}> */
    public static function casosDeGrupo(): array
    {
        return [
            ['Vertex - VarejoCred 01 - EXTERNO', 'Vertex'],
            ['VERTEX BD-6 Multiproduto Lote 6 - EXTERNO', 'Vertex'],
            ['Portal Renegocia - BD-Multiproduto 8', 'Portal'],
            ['PortalRenegocia', 'Portal'],
            ['Hyperion - Carteira X', 'Hyperion'],
            ['Fortis', 'Fortis'],
            ['Acesso Publico - INTERNO', 'Outras'],
            ['', 'Outras'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casosDeGrupo')]
    public function testGrupoFilaClassificaPorPrefixoCaseInsensitive(string $valor, string $grupoEsperado): void
    {
        self::assertSame($grupoEsperado, AcordoOperacionalRepository::grupoFila($valor));
    }

    public function testExtrairResponsavelNegociacaoRemovePrefixo(): void
    {
        self::assertSame(
            'Portal Renegocia - BD-Multiproduto 8',
            AcordoOperacionalRepository::extrairResponsavelNegociacao('Responsável: Portal Renegocia - BD-Multiproduto 8')
        );
    }

    public function testExtrairResponsavelNegociacaoSemPrefixoRetornaTextoOriginal(): void
    {
        self::assertSame('texto qualquer', AcordoOperacionalRepository::extrairResponsavelNegociacao('texto qualquer'));
    }

    public function testExtrairResponsavelNegociacaoNuloOuVazioRetornaVazio(): void
    {
        self::assertSame('', AcordoOperacionalRepository::extrairResponsavelNegociacao(null));
        self::assertSame('', AcordoOperacionalRepository::extrairResponsavelNegociacao(''));
    }

    public function testCombinacaoNotaAcmeCaiNoGrupoCorreto(): void
    {
        $nota = 'Responsável: Vertex - MMP IV';
        $grupo = AcordoOperacionalRepository::grupoFila(AcordoOperacionalRepository::extrairResponsavelNegociacao($nota));
        self::assertSame('Vertex', $grupo);
    }
}
