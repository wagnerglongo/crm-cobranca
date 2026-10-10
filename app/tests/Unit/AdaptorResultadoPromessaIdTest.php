<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../public/operacao/includes/works_helpers.php';

/**
 * Achado (2026-10-01): fechar acordo normal pelo Pactua estava
 * sendo tabulado/enviado pra ACME como "ACORDO - EXCEÇÃO" (ACME_032) em vez
 * de "ACORDO" (ACME_002) - três tabulações reais da ACME compartilham
 * resultado_legacy='promessa_pagamento' (ACME_032, ACME_185 "Pagamento
 * Efetuado", ACME_002), e a função pegava só a primeira por ordem, que por
 * coincidência é a de exceção.
 *
 * Correção definitiva (migration 097): marcação explícita
 * padrao_fechamento_acordo, escopada por credor_id - cada credor pode ter
 * um ActionID padrão diferente. adaptorResultadoPromessaId() (em
 * public/operacao/includes/works_helpers.php) passa a usar essa marcação.
 */
final class AdaptorResultadoPromessaIdTest extends TestCase
{
    private const ACME = 1;
    private const GLOBEX = 2;

    public function testPrefereOPadraoDoCredorDoContrato(): void
    {
        $resultados = [
            ['id' => 1879, 'codigo' => 'ACME_032', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 0, 'resultado_legacy' => 'promessa_pagamento'],
            ['id' => 1886, 'codigo' => 'ACME_185', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 0, 'resultado_legacy' => 'promessa_pagamento'],
            ['id' => 1888, 'codigo' => 'ACME_002', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 1, 'resultado_legacy' => 'promessa_pagamento'],
        ];

        self::assertSame(1888, adaptorResultadoPromessaId($resultados, self::ACME));
    }

    public function testCadaCredorUsaOProprioPadrao(): void
    {
        $resultados = [
            ['id' => 100, 'codigo' => 'ACME_ACORDO', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 1, 'resultado_legacy' => 'promessa_pagamento'],
            ['id' => 200, 'codigo' => 'GLOBEX_ACORDO', 'credor_id' => self::GLOBEX, 'padrao_fechamento_acordo' => 1, 'resultado_legacy' => 'promessa_pagamento'],
        ];

        self::assertSame(100, adaptorResultadoPromessaId($resultados, self::ACME));
        self::assertSame(200, adaptorResultadoPromessaId($resultados, self::GLOBEX));
    }

    public function testSemPadraoParaOCredorCaiPraPadraoGlobalComCredorNulo(): void
    {
        $resultados = [
            ['id' => 300, 'codigo' => 'ACME_X', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 0, 'resultado_legacy' => 'promessa_pagamento'],
            ['id' => 400, 'codigo' => 'GLOBAL', 'credor_id' => null, 'padrao_fechamento_acordo' => 1, 'resultado_legacy' => 'promessa_pagamento'],
        ];

        self::assertSame(400, adaptorResultadoPromessaId($resultados, self::ACME));
    }

    public function testSemNenhumPadraoMarcadoCaiNoPrimeiroPorOrdem(): void
    {
        $resultados = [
            ['id' => 10, 'codigo' => 'OUTRO_A', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 0, 'resultado_legacy' => 'promessa_pagamento'],
            ['id' => 20, 'codigo' => 'OUTRO_B', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 0, 'resultado_legacy' => 'promessa_pagamento'],
        ];

        self::assertSame(10, adaptorResultadoPromessaId($resultados, self::ACME));
    }

    public function testSemCredorInformadoIgnoraPadraoEspecificoDeCredor(): void
    {
        $resultados = [
            ['id' => 1, 'codigo' => 'ACME_X', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 1, 'resultado_legacy' => 'promessa_pagamento'],
            ['id' => 2, 'codigo' => 'GLOBAL', 'credor_id' => null, 'padrao_fechamento_acordo' => 1, 'resultado_legacy' => 'promessa_pagamento'],
        ];

        self::assertSame(2, adaptorResultadoPromessaId($resultados, null));
    }

    public function testSemNenhumCandidatoRetornaNull(): void
    {
        $resultados = [
            ['id' => 1, 'codigo' => 'ACME_100', 'credor_id' => self::ACME, 'padrao_fechamento_acordo' => 0, 'resultado_legacy' => 'atendeu'],
        ];

        self::assertNull(adaptorResultadoPromessaId($resultados, self::ACME));
    }

    public function testListaVaziaRetornaNull(): void
    {
        self::assertNull(adaptorResultadoPromessaId([]));
    }
}
