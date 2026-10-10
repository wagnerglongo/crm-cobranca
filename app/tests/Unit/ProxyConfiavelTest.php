<?php

declare(strict_types=1);

use CRM\Infrastructure\Http\ProxyConfiavel as P;
use PHPUnit\Framework\TestCase;

/**
 * HTTPS do CRM é feito pelo proxy da empresa (10.0.0.3, conferido no log
 * em 2026-10-06): X-Forwarded-For/Proto só valem quando vêm dele.
 */
final class ProxyConfiavelTest extends TestCase
{
    private const PROXY = ['10.0.0.3'];

    public function testViaProxyUsaOIpRealEReconheceHttps(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.3', 'HTTP_X_FORWARDED_FOR' => '10.0.4.75', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        self::assertSame('10.0.4.75', P::ipCliente($server, self::PROXY));
        self::assertTrue(P::viaHttps($server, self::PROXY));
    }

    public function testAcessoDiretoForjandoCabecalhosEIgnorado(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.149', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        self::assertSame('10.0.0.149', P::ipCliente($server, self::PROXY));
        self::assertFalse(P::viaHttps($server, self::PROXY), 'http://IP:8080 continua sem cookie Secure');
    }

    public function testIpForjadoPeloClienteNoInicioDaCadeiaNaoVale(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.3', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 10.0.4.75'];
        self::assertSame('10.0.4.75', P::ipCliente($server, self::PROXY));
    }

    public function testProxyMandandoHttpNaoEHttps(): void
    {
        self::assertFalse(P::viaHttps(['REMOTE_ADDR' => '10.0.0.3', 'HTTP_X_FORWARDED_PROTO' => 'http'], self::PROXY));
        self::assertTrue(P::viaHttps(['REMOTE_ADDR' => '10.0.0.9', 'HTTPS' => 'on'], self::PROXY), 'HTTPS direto também conta');
    }
}
