<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsvDownloadLifecycleTest extends TestCase
{
    public function testCsvButtonsDeclareDownloadLifecycle(): void
    {
        $dadosGerais = file_get_contents(__DIR__ . '/../../public/operacao/dados_gerais.php');
        $relatorios = file_get_contents(__DIR__ . '/../../public/operacao/relatorios.php');

        self::assertIsString($dadosGerais);
        self::assertIsString($relatorios);
        self::assertStringContainsString('data-download-request', $dadosGerais);
        self::assertStringContainsString('data-download-request', $relatorios);
    }

    public function testRequestLoadingUsesPerDownloadCookieHandshake(): void
    {
        $script = file_get_contents(__DIR__ . '/../../public/operacao/assets/request-loading.js');

        self::assertIsString($script);
        self::assertStringContainsString('_download_token', $script);
        self::assertStringContainsString('works_download_', $script);
        self::assertStringContainsString("status === 'ready' || status === 'error'", $script);
        self::assertStringContainsString('resetSubmitButtons(form)', $script);
    }

    public function testCsvEndpointsForwardTokenToStreamingExporter(): void
    {
        $dadosGerais = file_get_contents(__DIR__ . '/../../public/operacao/dados_gerais_exportar.php');
        $relatorios = file_get_contents(__DIR__ . '/../../public/operacao/relatorios_exportar.php');
        $stream = file_get_contents(__DIR__ . '/../../src/Modules/Operacao/Services/CsvStreamExporter.php');

        self::assertIsString($dadosGerais);
        self::assertIsString($relatorios);
        self::assertIsString($stream);
        self::assertStringContainsString("\$_POST['_download_token']", $dadosGerais);
        self::assertStringContainsString("\$_GET['_download_token']", $relatorios);
        self::assertStringContainsString('sinalizarDownload($downloadToken', $stream);
        self::assertStringContainsString("'path' => '/operacao'", $stream);
    }
}
