<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Exportador CSV streaming comum aos relatorios e aos mailings.
 *
 * Mantem memoria constante mesmo em arquivos grandes: nenhuma colecao de
 * resultados e carregada inteira antes do envio.
 */
final class CsvStreamExporter
{
    /**
     * @param array{
     *   stmt:PDOStatement,
     *   colunas:array<string,string>,
     *   arquivo:string,
     *   limite?:int
     * } $prepared
     */
    public function enviar(array $prepared, ?string $downloadToken = null): never
    {
        $stmt = $prepared['stmt'] ?? null;
        $colunas = $prepared['colunas'] ?? null;
        if (!$stmt instanceof PDOStatement || !is_array($colunas) || $colunas === []) {
            throw new RuntimeException('Exportacao CSV invalida: consulta ou colunas ausentes.');
        }

        $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) ($prepared['arquivo'] ?? 'exportacao.csv')) ?: 'exportacao.csv';

        // O navegador permanece na pagina durante um Content-Disposition attachment.
        // Este cookie avisa o JS que os headers do arquivo foram liberados, permitindo
        // remover o overlay sem esperar o streaming inteiro terminar.
        $this->sinalizarDownload($downloadToken, 'ready');

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('X-Accel-Buffering: no');
            if (isset($prepared['limite'])) {
                header('X-Pactua-Max-Rows: ' . max(0, (int) $prepared['limite']));
            }
        }

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Nao foi possivel abrir a saida CSV.');
        }

        // BOM ajuda Excel/Windows a reconhecer UTF-8 sem corromper acentos.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($colunas), ';', '"', '\\');

        $rows = 0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $line = [];
            foreach ($colunas as $key => $_label) {
                $line[] = $this->csvValue($row[$key] ?? null);
            }
            fputcsv($out, $line, ';', '"', '\\');
            $rows++;
            if (($rows % 1000) === 0) {
                fflush($out);
            }
        }

        fflush($out);
        fclose($out);
        exit;
    }

    public function sinalizarDownload(?string $downloadToken, string $status = 'ready'): void
    {
        $token = $this->normalizarDownloadToken($downloadToken);
        if ($token === null || headers_sent()) {
            return;
        }

        $status = $status === 'error' ? 'error' : 'ready';
        $secure = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && (string) $_SERVER['HTTPS'] !== '';

        setcookie('works_download_' . $token, $status, [
            'expires' => time() + 120,
            'path' => '/operacao',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    public function csvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = str_replace("\0", '', (string) $value);

        // Evita CSV/Formula Injection sem alterar numeros negativos legitimos.
        if ($text !== '' && preg_match('/^[=+@]/', $text) === 1) {
            return "'" . $text;
        }
        if ($text !== '' && str_starts_with($text, '-') && !is_numeric($text)) {
            return "'" . $text;
        }

        return $text;
    }

    private function normalizarDownloadToken(?string $downloadToken): ?string
    {
        $token = trim((string) $downloadToken);
        if ($token === '' || preg_match('/^[A-Za-z0-9_-]{8,80}$/', $token) !== 1) {
            return null;
        }

        return $token;
    }
}
