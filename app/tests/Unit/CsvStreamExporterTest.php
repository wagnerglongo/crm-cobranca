<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Services\CsvStreamExporter;
use PHPUnit\Framework\TestCase;

final class CsvStreamExporterTest extends TestCase
{
    public function testProtegeFormulaCsvSemAlterarNumeroNegativo(): void
    {
        $exporter = new CsvStreamExporter();

        self::assertSame("'=2+2", $exporter->csvValue('=2+2'));
        self::assertSame("'+5517999999999", $exporter->csvValue('+5517999999999'));
        self::assertSame("'-cmd|calc", $exporter->csvValue('-cmd|calc'));
        self::assertSame('-123.45', $exporter->csvValue('-123.45'));
    }

    public function testNormalizaNulosEBoleanos(): void
    {
        $exporter = new CsvStreamExporter();

        self::assertSame('', $exporter->csvValue(null));
        self::assertSame('1', $exporter->csvValue(true));
        self::assertSame('0', $exporter->csvValue(false));
    }
}
