<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Config\Database;
use CRM\Modules\Cargas\Parsers\AcmePipeParser;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Validators\ValidadorLinhaAcme;
use RuntimeException;
use SplFileObject;
use Throwable;

final class ProcessarCargaService
{
    public function __construct(
        private readonly CargaRepository $repository,
        private readonly AcmePipeParser $parser,
        private readonly NormalizarLinhaAcmeService $normalizer,
        private readonly ValidadorLinhaAcme $validator,
    ) {
    }

    /**
     * @param list<string> $header
     * @return array{total_linhas:int,linhas_raw_gravadas:int,linhas_processadas:int,linhas_staging:int,linhas_validas:int,linhas_com_erro:int,status_final:string}
     */
    public function processarAcme(
        int $cargaId,
        string $path,
        int $credorId,
        ?int $carteiraId,
        int $layoutId,
        array $header,
    ): array {
        $batchSize = max(1, (int) (Database::env('CARGAS_BATCH_SIZE', '5000') ?? '5000'));
        $counters = [
            'total_linhas' => 0,
            'linhas_raw_gravadas' => 0,
            'linhas_processadas' => 0,
            'linhas_staging' => 0,
            'linhas_validas' => 0,
            'linhas_com_erro' => 0,
        ];

        $this->repository->updateCargaArquivo($cargaId, [
            'status' => 'processando_raw',
            'iniciado_em' => date('Y-m-d H:i:s'),
        ]);

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::DROP_NEW_LINE);

        // Pula o cabeçalho. O cabeçalho já foi validado no DetectarLayoutService.
        $lineNumber = 1;
        if (!$file->eof()) {
            $file->fgets();
        }

        $batch = [];

        while (!$file->eof()) {
            $line = $file->fgets();
            if ($line === false) {
                break;
            }

            $lineNumber++;
            $line = rtrim((string) $line, "\r\n");
            if ($line === '') {
                continue;
            }
            $batch[] = [
                'numero_linha' => $lineNumber,
                'linha_bruta' => $line,
                'hash_linha' => hash('sha256', $line),
            ];

            if (count($batch) >= $batchSize) {
                $this->processBatch($cargaId, $batch, $header, $credorId, $carteiraId, $layoutId, $counters);
                $batch = [];
                $this->persistCounters($cargaId, $counters, 'processando_raw');
                $this->printProgress($counters);
            }
        }

        if ($batch !== []) {
            $this->processBatch($cargaId, $batch, $header, $credorId, $carteiraId, $layoutId, $counters);
            $this->persistCounters($cargaId, $counters, 'processando_raw');
            $this->printProgress($counters);
        }

        $statusFinal = $counters['linhas_com_erro'] > 0 ? 'concluido_com_erros' : 'concluido';
        $erroResumo = $counters['linhas_com_erro'] > 0
            ? sprintf('Carga concluída com %d linha(s) com erro/alerta. Veja cargas_erros.', $counters['linhas_com_erro'])
            : null;

        $this->repository->updateCargaArquivo($cargaId, [
            ...$counters,
            'status' => $statusFinal,
            'erro_resumo' => $erroResumo,
            'finalizado_em' => date('Y-m-d H:i:s'),
        ]);

        return [
            ...$counters,
            'status_final' => $statusFinal,
        ];
    }

    /**
     * @param list<array{numero_linha:int,linha_bruta:string,hash_linha:string}> $batch
     * @param list<string> $header
     * @param array{total_linhas:int,linhas_raw_gravadas:int,linhas_processadas:int,linhas_staging:int,linhas_validas:int,linhas_com_erro:int} $counters
     */
    private function processBatch(
        int $cargaId,
        array $batch,
        array $header,
        int $credorId,
        ?int $carteiraId,
        int $layoutId,
        array &$counters,
    ): void {
        $rawRows = [];
        foreach ($batch as $row) {
            $rawRows[] = [
                'numero_linha' => $row['numero_linha'],
                'linha_bruta' => $row['linha_bruta'],
                'hash_linha' => $row['hash_linha'],
                'status' => 'recebida',
                'erro' => null,
                'payload_parse' => null,
            ];
        }

        $this->repository->beginTransaction();

        try {
            $rawIds = $this->repository->insertRawBatch($cargaId, $rawRows);

            $stagingRows = [];
            $rawUpdates = [];
            $errors = [];

            foreach ($batch as $row) {
                $lineNumber = $row['numero_linha'];
                $rawId = $rawIds[$lineNumber] ?? null;
                if ($rawId === null) {
                    throw new RuntimeException('Não foi possível localizar raw_id da linha ' . $lineNumber);
                }

                $counters['total_linhas']++;
                $counters['linhas_raw_gravadas']++;

                try {
                    $parsed = $this->parser->parseDataLine($row['linha_bruta'], $header, $lineNumber);
                    $normalized = $this->normalizer->normalize($parsed, $credorId, $carteiraId, $layoutId);
                    $issues = $this->validator->validate($normalized);
                    $hasBlockingError = $this->validator->hasBlockingError($issues);

                    $erroLinha = $issues === [] ? null : implode(' | ', array_map(
                        static fn (array $issue): string => '[' . $issue['codigo'] . '] ' . $issue['mensagem'],
                        $issues
                    ));

                    $stagingRows[] = [
                        'carga_arquivo_id' => $cargaId,
                        'linha_raw_id' => $rawId,
                        ...$normalized,
                        'status' => $hasBlockingError ? 'erro' : 'valido',
                        'erro' => $erroLinha,
                    ];

                    $rawUpdates[] = [
                        'raw_id' => $rawId,
                        'status' => $hasBlockingError ? 'erro' : 'valida',
                        'erro' => $erroLinha,
                        'payload_parse' => $parsed,
                    ];

                    if ($hasBlockingError) {
                        $counters['linhas_com_erro']++;
                    } else {
                        $counters['linhas_validas']++;
                    }

                    foreach ($issues as $issue) {
                        $errors[] = [
                            'carga_arquivo_id' => $cargaId,
                            'linha_raw_id' => $rawId,
                            'etapa' => 'validacao',
                            'codigo_erro' => $issue['codigo'],
                            'mensagem' => $issue['mensagem'],
                            'campo_origem' => $issue['campo_origem'],
                            'campo_canonico' => $issue['campo_canonico'],
                            'severidade' => $issue['severidade'],
                            'valor_original' => $issue['valor_original'],
                            'payload_contexto' => [
                                'numero_linha' => $lineNumber,
                                'documento' => $normalized['documento'] ?? null,
                                'codigo_contrato' => $normalized['codigo_contrato'] ?? null,
                            ],
                        ];
                    }
                } catch (Throwable $e) {
                    $message = $e->getMessage();

                    $rawUpdates[] = [
                        'raw_id' => $rawId,
                        'status' => 'erro',
                        'erro' => $message,
                        'payload_parse' => null,
                    ];

                    $errors[] = [
                        'carga_arquivo_id' => $cargaId,
                        'linha_raw_id' => $rawId,
                        'etapa' => 'parse',
                        'codigo_erro' => 'ACME_PARSE_LINHA_INVALIDA',
                        'mensagem' => $message,
                        'severidade' => 'erro',
                        'valor_original' => $row['linha_bruta'],
                        'payload_contexto' => ['numero_linha' => $lineNumber],
                    ];

                    $counters['linhas_com_erro']++;
                }

                $counters['linhas_processadas']++;
            }

            $insertedStaging = $this->repository->insertStagingBatch($stagingRows);
            $this->repository->updateRawBatch($rawUpdates);
            $this->repository->insertErrorsBatch($errors);
            $counters['linhas_staging'] += $insertedStaging;

            $this->repository->commit();
        } catch (Throwable $e) {
            $this->repository->rollBack();
            throw $e;
        }
    }

    /** @param array<string,int> $counters */
    private function persistCounters(int $cargaId, array $counters, string $status): void
    {
        $this->repository->updateCargaArquivo($cargaId, [
            ...$counters,
            'status' => $status,
        ]);
    }

    /** @param array<string,int> $counters */
    private function printProgress(array $counters): void
    {
        echo sprintf(
            "Processadas: %d | Raw: %d | Staging: %d | Válidas: %d | Erros/alertas bloqueantes: %d\n",
            $counters['linhas_processadas'],
            $counters['linhas_raw_gravadas'],
            $counters['linhas_staging'],
            $counters['linhas_validas'],
            $counters['linhas_com_erro'],
        );
    }
}
