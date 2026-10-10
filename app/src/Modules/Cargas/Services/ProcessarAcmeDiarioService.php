<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Modules\Cargas\Repositories\CargaRepository;
use Throwable;

final class ProcessarAcmeDiarioService
{
    public function __construct(
        private readonly CargaRepository $repository,
        private readonly ScannerCargasPendentesService $scannerCargasPendentesService,
    ) {
    }

    /**
     * @param array{max?:int,batch?:int,dry_run?:bool,sem_importar?:bool,sem_aplicar?:bool,usar_lock?:bool,parar_no_erro?:bool} $options
     * @return array<string,mixed>
     */
    public function executar(array $options = []): array
    {
        $startedAt = microtime(true);

        $max = max(1, (int) ($options['max'] ?? 50));
        $batchSize = max(1, min((int) ($options['batch'] ?? 1000), 5000));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $semImportar = (bool) ($options['sem_importar'] ?? false);
        $semAplicar = (bool) ($options['sem_aplicar'] ?? false);
        $usarLock = (bool) ($options['usar_lock'] ?? true);
        $pararNoErro = (bool) ($options['parar_no_erro'] ?? false);

        $lockName = 'crm:processar_acme_diario';
        $lockObtido = false;

        $resultado = [
            'status_final' => 'pendente',
            'dry_run' => $dryRun,
            'max' => $max,
            'batch' => $batchSize,
            'lock' => [
                'habilitado' => $usarLock,
                'nome' => $lockName,
                'obtido' => false,
            ],
            'importacao' => $this->novoResumoImportacao($semImportar),
            'aplicacao' => $this->novoResumoAplicacao($semAplicar),
            'tempo_total' => 0.0,
        ];

        try {
            if ($usarLock) {
                $lockObtido = $this->repository->acquireNamedLock($lockName, 0);
                $resultado['lock']['obtido'] = $lockObtido;

                if (!$lockObtido) {
                    $resultado['status_final'] = 'bloqueado';
                    $resultado['mensagem'] = 'Já existe outro processamento diário ACME em execução.';
                    return $this->finalizarResultado($resultado, $startedAt);
                }
            }

            if (!$semImportar) {
                $resultado['importacao'] = $this->executarImportacao($max, $dryRun);
            }

            if (!$semAplicar) {
                $resultado['aplicacao'] = $this->executarAplicacao($max, $batchSize, $dryRun, $pararNoErro);
            }

            $resultado['status_final'] = $this->definirStatusFinal($resultado);
            return $this->finalizarResultado($resultado, $startedAt);
        } finally {
            if ($usarLock && $lockObtido) {
                $this->repository->releaseNamedLock($lockName);
            }
        }
    }

    /** @return array<string,mixed> */
    private function executarImportacao(int $max, bool $dryRun): array
    {
        $result = $this->scannerCargasPendentesService->executar([
            'credor' => 'acme',
            'max_files' => $max,
            'force' => false,
            'dry_run' => $dryRun,
        ]);

        return [
            'pulada' => false,
            'inbox_base' => $result['inbox_base'] ?? null,
            'arquivos_encontrados' => count($result['arquivos'] ?? []),
            'arquivos_importados' => (int) ($result['processados'] ?? 0),
            'arquivos_duplicados' => (int) ($result['duplicados'] ?? 0),
            'arquivos_com_erro' => (int) ($result['erros'] ?? 0),
            'arquivos_ignorados' => (int) ($result['ignorados'] ?? 0),
            'arquivos' => $result['arquivos'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    private function executarAplicacao(int $max, int $batchSize, bool $dryRun, bool $pararNoErro): array
    {
        $cargas = $this->repository->buscarCargasAcmePendentesAplicacao($max);
        $resumo = $this->novoResumoAplicacao(false);
        $resumo['cargas_encontradas'] = count($cargas);
        $resumo['cargas'] = $cargas;

        if ($dryRun || $cargas === []) {
            return $resumo;
        }

        foreach ($cargas as $carga) {
            $cargaId = (int) $carga['id'];
            $inicioCarga = microtime(true);

            try {
                // Nova instância por carga para não reaproveitar contadores internos do service.
                $aplicarCargaAcmeService = new AplicarCargaAcmeService($this->repository);
                $result = $aplicarCargaAcmeService->aplicar($cargaId, $batchSize);

                $resumo['cargas_processadas']++;
                $resumo['linhas_aplicadas'] += (int) $result['linhas_aplicadas'];
                $resumo['pessoas_criadas'] += (int) $result['pessoas_criadas'];
                $resumo['pessoas_atualizadas'] += (int) $result['pessoas_atualizadas'];
                $resumo['contratos_criados'] += (int) $result['contratos_criados'];
                $resumo['contratos_atualizados'] += (int) $result['contratos_atualizados'];
                $resumo['contatos_criados'] += (int) $result['contatos_criados'];
                $resumo['contatos_atualizados'] += (int) $result['contatos_atualizados'];
                $resumo['enderecos_criados'] += (int) $result['enderecos_criados'];
                $resumo['enderecos_atualizados'] += (int) $result['enderecos_atualizados'];
                $resumo['veiculos_criados'] += (int) $result['veiculos_criados'];
                $resumo['veiculos_atualizados'] += (int) $result['veiculos_atualizados'];
                $resumo['erros'] += (int) $result['erros'];

                if (in_array($result['status'], ['aplicado', 'sem_linhas_validas'], true)) {
                    $resumo['cargas_aplicadas_com_sucesso']++;
                } else {
                    $resumo['cargas_com_erro']++;
                }

                $resumo['resultados'][] = [
                    'carga_id' => $cargaId,
                    'nome_original' => (string) ($carga['nome_original'] ?? ''),
                    'status' => (string) $result['status'],
                    'staging_validos_encontrados' => (int) $result['staging_validos_encontrados'],
                    'linhas_aplicadas' => (int) $result['linhas_aplicadas'],
                    'pessoas_criadas' => (int) $result['pessoas_criadas'],
                    'pessoas_atualizadas' => (int) $result['pessoas_atualizadas'],
                    'contratos_criados' => (int) $result['contratos_criados'],
                    'contratos_atualizados' => (int) $result['contratos_atualizados'],
                    'contatos_criados' => (int) $result['contatos_criados'],
                    'contatos_atualizados' => (int) $result['contatos_atualizados'],
                    'enderecos_criados' => (int) $result['enderecos_criados'],
                    'enderecos_atualizados' => (int) $result['enderecos_atualizados'],
                    'veiculos_criados' => (int) $result['veiculos_criados'],
                    'veiculos_atualizados' => (int) $result['veiculos_atualizados'],
                    'erros' => (int) $result['erros'],
                    'tempo' => round(microtime(true) - $inicioCarga, 2),
                ];

                if (!in_array($result['status'], ['aplicado', 'sem_linhas_validas'], true) && $pararNoErro) {
                    $resumo['interrompido_por_erro'] = true;
                    break;
                }
            } catch (Throwable $e) {
                $resumo['cargas_processadas']++;
                $resumo['cargas_com_erro']++;
                $resumo['erros']++;
                $resumo['resultados'][] = [
                    'carga_id' => $cargaId,
                    'nome_original' => (string) ($carga['nome_original'] ?? ''),
                    'status' => 'erro',
                    'erro' => $e->getMessage(),
                    'tempo' => round(microtime(true) - $inicioCarga, 2),
                ];

                if ($pararNoErro) {
                    $resumo['interrompido_por_erro'] = true;
                    break;
                }
            }
        }

        return $resumo;
    }

    /** @return array<string,mixed> */
    private function novoResumoImportacao(bool $pulada): array
    {
        return [
            'pulada' => $pulada,
            'inbox_base' => null,
            'arquivos_encontrados' => 0,
            'arquivos_importados' => 0,
            'arquivos_duplicados' => 0,
            'arquivos_com_erro' => 0,
            'arquivos_ignorados' => 0,
            'arquivos' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function novoResumoAplicacao(bool $pulada): array
    {
        return [
            'pulada' => $pulada,
            'cargas_encontradas' => 0,
            'cargas_processadas' => 0,
            'cargas_aplicadas_com_sucesso' => 0,
            'cargas_com_erro' => 0,
            'linhas_aplicadas' => 0,
            'pessoas_criadas' => 0,
            'pessoas_atualizadas' => 0,
            'contratos_criados' => 0,
            'contratos_atualizados' => 0,
            'contatos_criados' => 0,
            'contatos_atualizados' => 0,
            'enderecos_criados' => 0,
            'enderecos_atualizados' => 0,
            'veiculos_criados' => 0,
            'veiculos_atualizados' => 0,
            'erros' => 0,
            'interrompido_por_erro' => false,
            'cargas' => [],
            'resultados' => [],
        ];
    }

    /** @param array<string,mixed> $resultado */
    private function definirStatusFinal(array $resultado): string
    {
        if (($resultado['importacao']['arquivos_com_erro'] ?? 0) > 0 || ($resultado['aplicacao']['cargas_com_erro'] ?? 0) > 0) {
            return 'concluido_com_erros';
        }

        return 'concluido';
    }

    /**
     * @param array<string,mixed> $resultado
     * @return array<string,mixed>
     */
    private function finalizarResultado(array $resultado, float $startedAt): array
    {
        $resultado['tempo_total'] = round(microtime(true) - $startedAt, 2);
        return $resultado;
    }
}
