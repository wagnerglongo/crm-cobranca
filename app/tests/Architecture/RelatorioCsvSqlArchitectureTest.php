<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RelatorioCsvSqlArchitectureTest extends TestCase
{
    public function testSqlDosRelatoriosNaoContemQuebraDeLinhaLiteralEmStringSingleQuoted(): void
    {
        $file = realpath(__DIR__ . '/../../src/Modules/Operacao/Repositories/RelatorioOperacionalRepository.php');
        self::assertNotFalse($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        foreach (["'\\nFROM", "'\\nWHERE", "'\\nORDER", "'\\nLIMIT", "'\\nLEFT", "'\\nINNER"] as $pattern) {
            self::assertStringNotContainsString($pattern, $source, 'SQL possui \\n literal e pode gerar erro de sintaxe no MySQL: ' . $pattern);
        }
    }

    public function testRelatoriosEDadosGeraisCompartilhamExportadorStreaming(): void
    {
        $service = file_get_contents(__DIR__ . '/../../src/Modules/Operacao/Services/RelatorioCsvExporter.php');
        $endpoint = file_get_contents(__DIR__ . '/../../public/operacao/dados_gerais_exportar.php');

        self::assertIsString($service);
        self::assertIsString($endpoint);
        self::assertStringContainsString('CsvStreamExporter', $service);
        self::assertStringContainsString('CsvStreamExporter', $endpoint);
    }
}
