<?php

declare(strict_types=1);

use CRM\Modules\Cargas\Support\CargaEncoding;
use PHPUnit\Framework\TestCase;

final class CargaRawEncodingTest extends TestCase
{
    public function testWindows1252EConvertidoSomenteParaPersistenciaRaw(): void
    {
        $raw = 'CART' . chr(0xC3) . 'O VISA'; // CARTAO com A-til em Windows-1252.

        self::assertFalse(preg_match('//u', $raw));

        $stored = CargaEncoding::toUtf8ForStorage($raw);

        self::assertSame('CARTÃO VISA', $stored);
        self::assertSame(1, preg_match('//u', $stored));
        self::assertNotSame(hash('sha256', $raw), hash('sha256', $stored));
    }
}
