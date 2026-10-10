<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use RuntimeException;

final class ReceberCargaService
{
    public function __construct(private readonly CargaRepository $repository)
    {
    }

    /**
     * Recebe um arquivo, calcula hash, copia para o storage definitivo e registra em cargas_arquivos.
     *
     * O segundo argumento existe para o scanner salvar o original no subdiretorio do credor detectado/candidato,
     * sem depender do nome do arquivo. Para compatibilidade com importar_acme.php, o padrao continua sendo acme.
     *
     * @return array{
     *     id:int,
     *     caminho_storage:string,
     *     hash_arquivo:string,
     *     nome_original:string,
     *     duplicado:bool,
     *     duplicado_de_id:?int
     * }
     */
    public function receber(string $sourcePath, string $storageSubdir = 'acme'): array
    {
        $realSource = realpath($sourcePath);
        if ($realSource === false || !is_file($realSource) || !is_readable($realSource)) {
            throw new RuntimeException('Arquivo não encontrado ou sem permissão de leitura: ' . $sourcePath);
        }

        $hash = hash_file(Database::env('CARGAS_HASH_ALGO', 'sha256') ?? 'sha256', $realSource);
        if ($hash === false) {
            throw new RuntimeException('Não foi possível calcular o hash do arquivo.');
        }

        $storageBase = rtrim(Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas') ?? '/var/www/storage/cargas', '/');
        $safeSubdir = $this->safeStorageSubdir($storageSubdir);
        $today = date('Y/m/d');
        $targetDir = $storageBase . '/' . $safeSubdir . '/' . $today;

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Não foi possível criar diretório de storage: ' . $targetDir);
        }

        $originalName = basename($realSource);
        $safeName = $this->safeFilename($originalName, 'arquivo_carga.txt');
        $targetPath = $targetDir . '/' . $hash . '_' . $safeName;

        if (realpath($targetPath) !== $realSource && !is_file($targetPath)) {
            if (!copy($realSource, $targetPath)) {
                throw new RuntimeException('Não foi possível copiar arquivo original para storage: ' . $targetPath);
            }
        }

        $existing = $this->repository->findCargaByHash($hash);
        $mimeType = $this->detectMimeType($targetPath);
        $duplicadoDeId = isset($existing['id']) ? (int) $existing['id'] : null;

        $cargaId = $this->repository->createCargaArquivo([
            'duplicado_de_id' => $duplicadoDeId,
            'nome_original' => $originalName,
            'caminho_storage' => $targetPath,
            'storage_driver' => Database::env('STORAGE_DISK', 'local') ?? 'local',
            'mime_type' => $mimeType,
            'encoding_detectado' => Database::env('CARGAS_DEFAULT_ENCODING', 'utf-8-sig') ?? 'utf-8-sig',
            'hash_arquivo' => $hash,
            'tamanho_bytes' => filesize($targetPath) ?: null,
            'data_referencia' => $this->extractDateFromFilename($originalName),
            'status' => 'recebido',
            'payload_extra' => [
                'source_path' => $realSource,
                'stored_at' => date('c'),
                'storage_subdir' => $safeSubdir,
                'duplicado' => $duplicadoDeId !== null,
                'duplicado_de_id' => $duplicadoDeId,
            ],
        ]);

        return [
            'id' => $cargaId,
            'caminho_storage' => $targetPath,
            'hash_arquivo' => $hash,
            'nome_original' => $originalName,
            'duplicado' => $duplicadoDeId !== null,
            'duplicado_de_id' => $duplicadoDeId,
        ];
    }

    /**
     * Recebe um stream (por exemplo, membro de ZIP) sem extrair uma copia temporaria completa.
     * @param resource $stream
     * @return array{id:int,caminho_storage:string,hash_arquivo:string,nome_original:string,duplicado:bool,duplicado_de_id:?int}
     */
    public function receberStream($stream, string $originalName, string $storageSubdir = 'geral'): array
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Stream de carga invalido.');
        }

        $algo = Database::env('CARGAS_HASH_ALGO', 'sha256') ?? 'sha256';
        $storageBase = rtrim(Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas') ?? '/var/www/storage/cargas', '/');
        $safeSubdir = $this->safeStorageSubdir($storageSubdir);
        $today = date('Y/m/d');
        $targetDir = $storageBase . '/' . $safeSubdir . '/' . $today;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Nao foi possivel criar diretorio de storage: ' . $targetDir);
        }

        $safeName = $this->safeFilename($originalName, 'arquivo_carga.dat');
        $tmpPath = $targetDir . '/.recebendo_' . bin2hex(random_bytes(8)) . '_' . $safeName;
        $out = fopen($tmpPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Nao foi possivel criar arquivo temporario de recepcao.');
        }

        $hashContext = hash_init($algo);
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Falha ao ler stream de carga.');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                hash_update($hashContext, $chunk);
                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException('Falha ao gravar stream no storage.');
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmpPath);
            throw $e;
        }
        fclose($out);

        $hash = hash_final($hashContext);
        $targetPath = $targetDir . '/' . $hash . '_' . $safeName;
        if (is_file($targetPath)) {
            @unlink($tmpPath);
        } elseif (!rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Nao foi possivel finalizar arquivo recebido no storage.');
        }

        $existing = $this->repository->findCargaByHash($hash);
        $duplicadoDeId = isset($existing['id']) ? (int) $existing['id'] : null;
        $cargaId = $this->repository->createCargaArquivo([
            'duplicado_de_id' => $duplicadoDeId,
            'nome_original' => $originalName,
            'caminho_storage' => $targetPath,
            'storage_driver' => Database::env('STORAGE_DISK', 'local') ?? 'local',
            'mime_type' => $this->detectMimeType($targetPath),
            'encoding_detectado' => Database::env('CARGAS_DEFAULT_ENCODING', 'utf-8-sig') ?? 'utf-8-sig',
            'hash_arquivo' => $hash,
            'tamanho_bytes' => $bytes,
            'data_referencia' => $this->extractDateFromFilename($originalName),
            'status' => 'recebido',
            'payload_extra' => [
                'source' => 'stream',
                'stored_at' => date('c'),
                'storage_subdir' => $safeSubdir,
                'duplicado' => $duplicadoDeId !== null,
                'duplicado_de_id' => $duplicadoDeId,
            ],
        ]);

        return [
            'id' => $cargaId,
            'caminho_storage' => $targetPath,
            'hash_arquivo' => $hash,
            'nome_original' => $originalName,
            'duplicado' => $duplicadoDeId !== null,
            'duplicado_de_id' => $duplicadoDeId,
        ];
    }

    private function detectMimeType(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return 'text/plain';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return $mime === false ? 'text/plain' : $mime;
    }

    private function extractDateFromFilename(string $filename): ?string
    {
        if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $filename, $m) === 1) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }

        if (preg_match('/(\d{2})(\d{2})(20\d{2})/', $filename, $m) === 1) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    private function safeStorageSubdir(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '_', $value) ?: 'geral';
        return trim($value, '._-/') ?: 'geral';
    }

    private function safeFilename(string $value, string $fallback): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?: $fallback;
        return trim($value, '._-') ?: $fallback;
    }
}
