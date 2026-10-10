<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use DirectoryIterator;
use RuntimeException;
use Throwable;

final class ScannerCargasPendentesService
{
    public function __construct(
        private readonly CargaRepository $repository,
        private readonly ReceberCargaService $receberCargaService,
        private readonly DetectarLayoutService $detectarLayoutService,
        private readonly ProcessarCargaService $processarCargaService,
    ) {
    }

    /**
     * @param array{credor?:string,max_files?:int,force?:bool,dry_run?:bool} $options
     * @return array<string,mixed>
     */
    public function executar(array $options = []): array
    {
        $credorFiltro = strtolower((string) ($options['credor'] ?? 'acme'));
        $maxFiles = max(1, (int) ($options['max_files'] ?? 50));
        $force = (bool) ($options['force'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $storageBase = rtrim(Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas') ?? '/var/www/storage/cargas', '/');
        $inboxBase = rtrim(Database::env('CARGAS_INBOX_PATH', $storageBase . '/inbox') ?? $storageBase . '/inbox', '/');

        $credores = $credorFiltro === 'todos' ? ['acme'] : [$credorFiltro];
        $resultado = [
            'inbox_base' => $inboxBase,
            'processados' => 0,
            'duplicados' => 0,
            'erros' => 0,
            'ignorados' => 0,
            'arquivos' => [],
        ];

        foreach ($credores as $credor) {
            if ($credor !== 'acme') {
                $resultado['ignorados']++;
                $resultado['arquivos'][] = [
                    'credor' => $credor,
                    'status' => 'ignorado',
                    'mensagem' => 'Scanner automático implementado apenas para ACME nesta etapa.',
                ];
                continue;
            }

            $dir = $inboxBase . '/acme';
            if (!is_dir($dir)) {
                $resultado['arquivos'][] = [
                    'credor' => 'acme',
                    'status' => 'sem_pasta',
                    'path' => $dir,
                    'mensagem' => 'Pasta de inbox ACME ainda não existe.',
                ];
                continue;
            }

            foreach ($this->listarArquivos($dir, $maxFiles - $resultado['processados'] - $resultado['duplicados'] - $resultado['erros']) as $path) {
                if ($dryRun) {
                    $resultado['arquivos'][] = [
                        'credor' => 'acme',
                        'arquivo' => $path,
                        'status' => 'dry_run',
                        'mensagem' => 'Arquivo encontrado, mas não processado por causa do --dry-run.',
                    ];
                    continue;
                }

                $item = $this->processarArquivoAcme($path, $force);
                $resultado['arquivos'][] = $item;

                if ($item['status'] === 'duplicado') {
                    $resultado['duplicados']++;
                } elseif ($item['status'] === 'erro') {
                    $resultado['erros']++;
                } elseif (in_array($item['status'], ['concluido', 'concluido_com_erros'], true)) {
                    $resultado['processados']++;
                } else {
                    $resultado['ignorados']++;
                }
            }
        }

        return $resultado;
    }

    /**
     * @return list<string>
     */
    private function listarArquivos(string $dir, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $files = [];
        foreach (new DirectoryIterator($dir) as $item) {
            if ($item->isDot() || !$item->isFile()) {
                continue;
            }

            $filename = $item->getFilename();
            if (str_starts_with($filename, '.') || str_ends_with($filename, '.part') || str_ends_with($filename, '.tmp')) {
                continue;
            }

            $files[] = $item->getPathname();
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return array_slice($files, 0, $limit);
    }

    /**
     * @return array<string,mixed>
     */
    private function processarArquivoAcme(string $path, bool $force): array
    {
        $cargaId = null;

        try {
            $carga = $this->receberCargaService->receber($path, 'acme');
            $cargaId = (int) $carga['id'];

            if ($carga['duplicado'] && !$force) {
                $this->repository->updateCargaArquivo($cargaId, [
                    'status' => 'cancelado',
                    'erro_resumo' => 'Arquivo duplicado. Hash já importado na carga #' . $carga['duplicado_de_id'] . '. Processamento automático ignorado.',
                    'finalizado_em' => date('Y-m-d H:i:s'),
                ]);

                $movedTo = $this->moverArquivo($path, 'duplicados', 'acme');

                return [
                    'credor' => 'acme',
                    'arquivo' => $path,
                    'movido_para' => $movedTo,
                    'carga_id' => $cargaId,
                    'duplicado_de_id' => $carga['duplicado_de_id'],
                    'hash_arquivo' => $carga['hash_arquivo'],
                    'status' => 'duplicado',
                    'mensagem' => 'Arquivo duplicado ignorado automaticamente.',
                ];
            }

            $layout = $this->detectarLayoutService->detectarAcme($cargaId, $carga['caminho_storage']);
            $result = $this->processarCargaService->processarAcme(
                $cargaId,
                $carga['caminho_storage'],
                $layout['credor_id'],
                $layout['carteira_id'],
                $layout['layout_id'],
                $layout['header'],
            );

            $movedTo = $this->moverArquivo($path, 'processados', 'acme');

            return [
                'credor' => 'acme',
                'arquivo' => $path,
                'movido_para' => $movedTo,
                'carga_id' => $cargaId,
                'hash_arquivo' => $carga['hash_arquivo'],
                'status' => $result['status_final'],
                'total_linhas' => $result['total_linhas'],
                'linhas_validas' => $result['linhas_validas'],
                'linhas_com_erro' => $result['linhas_com_erro'],
            ];
        } catch (Throwable $e) {
            if ($cargaId !== null) {
                try {
                    $this->repository->updateCargaArquivo($cargaId, [
                        'status' => 'erro',
                        'erro_resumo' => $e->getMessage(),
                        'finalizado_em' => date('Y-m-d H:i:s'),
                    ]);
                } catch (Throwable) {
                    // Não mascara o erro original.
                }
            }

            $movedTo = $this->moverArquivo($path, 'erros', 'acme');

            return [
                'credor' => 'acme',
                'arquivo' => $path,
                'movido_para' => $movedTo,
                'carga_id' => $cargaId,
                'status' => 'erro',
                'mensagem' => $e->getMessage(),
            ];
        }
    }

    private function moverArquivo(string $sourcePath, string $destino, string $credor): string
    {
        $realSource = realpath($sourcePath);
        if ($realSource === false || !is_file($realSource)) {
            return $sourcePath;
        }

        $storageBase = rtrim(Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas') ?? '/var/www/storage/cargas', '/');
        $targetDir = $storageBase . '/' . $destino . '/' . $credor . '/' . date('Y/m/d');

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Não foi possível criar diretório para mover arquivo: ' . $targetDir);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($realSource)) ?: 'arquivo_carga.txt';
        $target = $targetDir . '/' . date('His') . '_' . $safeName;
        $suffix = 1;
        while (is_file($target)) {
            $target = $targetDir . '/' . date('His') . '_' . $suffix . '_' . $safeName;
            $suffix++;
        }

        if (!rename($realSource, $target)) {
            throw new RuntimeException('Não foi possível mover arquivo de inbox: ' . $realSource . ' -> ' . $target);
        }

        return $target;
    }
}
