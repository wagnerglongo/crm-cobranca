<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Support\DiskSpaceGuard;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Purga em lotes o RAW (`cargas_linhas_raw`) e o staging canônico
 * (`stg_carga_canonica`) de cargas ANTIGAS e já totalmente processadas -
 * dado que só serve de auditoria/depuração depois que a carga foi aplicada,
 * e cresce sem limite (achado 2026-09-22: ~178 mil linhas/dia só da ACME,
 * ~1,7GB/dia combinado, 93% do tamanho do banco em 6 dias de operação real).
 *
 * Escopo de segurança (NUNCA relaxar sem entender o motivo de cada guard):
 * - só `cargas_arquivos.status IN ('concluido','concluido_com_erros')` -
 *   nunca uma carga em 'recebido'/'identificando_layout'/'aguardando_processamento'/
 *   'processando_raw'/'aplicando'/'erro'.
 * - só linhas com status "final" (staging: aplicado/erro; raw: aplicada/erro) -
 *   NUNCA staging 'valido' (ainda não aplicado - é o que
 *   aplicar_cargas_acme_pendentes.php usa pra
 *   saber o que falta aplicar) nem raw 'recebida'/'valida'/'processando'.
 * - só cargas mais antigas que a retenção (dias), pela data de criação -
 *   preserva uma janela recente pra depuração via tela de Gestão de Cargas
 *   (`configuracoes/cargas.php`, que só lê as 20 primeiras linhas de cada
 *   tabela pra preview - segue funcionando normalmente pra cargas dentro
 *   da janela, e mostra vazio (sem erro) pra cargas já purgadas).
 *
 * Nunca toca em dado canônico (contratos/pessoas/acordos) - não desfaz
 * nenhuma aplicação. Aqui a aplicação
 * já aconteceu e fica intacta; só o rastro intermediário é removido.
 *
 * Achado ao escrever este serviço: um primeiro rascunho usava
 * `DELETE ... WHERE carga_arquivo_id IN (SELECT id FROM cargas_arquivos WHERE ...)`
 * - contra a tabela real (1 milhão+ de linhas), o otimizador do MySQL não
 * empurra o índice de `carga_arquivo_id` pra dentro dessa subconsulta
 * dependente, e a query trava (confirmado: 63s+ sem terminar, `EXPLAIN`
 * não roda em DELETE, precisou `KILL` a conexão). A correção foi buscar
 * os poucos IDs de carga elegíveis primeiro (tabela pequena, ~dezenas de
 * linhas) e apagar POR ID LITERAL - `EXPLAIN` confirma uso de
 * `idx_stg_carga_status`/`idx_raw_carga_status` (type=range, 100% filtrado).
 */
final class LimparStagingAntigoService
{
    private const DEFAULT_BATCH = 5000;
    private const DEFAULT_MIN_FREE_MB = 4096;
    private const DEFAULT_RETENCAO_DIAS = 7;
    private const LOCK_NAME = 'crm:limpar_staging_antigo';

    public function __construct(private readonly CargaRepository $repository, private readonly PDO $pdo)
    {
    }

    /**
     * @param null|callable(string,int,int,int):void $progress
     * @return array{cargas_elegiveis:int,staging_removido:int,raw_removido:int,espaco_livre_mb_final:int,cutoff:string}
     */
    public function executar(
        int $retencaoDias = self::DEFAULT_RETENCAO_DIAS,
        int $batch = self::DEFAULT_BATCH,
        int $minFreeMb = self::DEFAULT_MIN_FREE_MB,
        ?callable $progress = null
    ): array {
        if ($retencaoDias < 1) {
            throw new RuntimeException('Retenção deve ser de pelo menos 1 dia.');
        }
        if ($batch < 100 || $batch > 50000) {
            throw new RuntimeException('Batch de limpeza deve ficar entre 100 e 50000.');
        }
        if ($minFreeMb < 512) {
            throw new RuntimeException('Margem mínima de disco deve ser de pelo menos 512 MB.');
        }

        if (!$this->repository->acquireNamedLock(self::LOCK_NAME, 0)) {
            throw new RuntimeException('Já existe uma limpeza de staging antigo em execução.');
        }

        try {
            // Checado aqui também (não só dentro de processarEmLotes) pra falhar rápido
            // e de forma previsível mesmo quando não há nenhuma carga elegível pra apagar.
            DiskSpaceGuard::assertFreeSpace($minFreeMb);

            $cutoff = (new \DateTimeImmutable("-{$retencaoDias} days"))->format('Y-m-d H:i:s');

            $idsElegiveis = $this->idsCargasElegiveis($cutoff);

            $stagingRemovido = 0;
            $rawRemovido = 0;
            foreach ($idsElegiveis as $cargaId) {
                $stagingRemovido += $this->processarEmLotes(
                    'staging_removido',
                    "DELETE FROM stg_carga_canonica WHERE carga_arquivo_id = :carga_id AND status IN ('aplicado','erro') LIMIT " . $batch,
                    ['carga_id' => $cargaId],
                    $minFreeMb,
                    $progress
                );
                $rawRemovido += $this->processarEmLotes(
                    'raw_removido',
                    "DELETE FROM cargas_linhas_raw WHERE carga_arquivo_id = :carga_id AND status IN ('aplicada','erro') LIMIT " . $batch,
                    ['carga_id' => $cargaId],
                    $minFreeMb,
                    $progress
                );
            }

            return [
                'cargas_elegiveis' => count($idsElegiveis),
                'staging_removido' => $stagingRemovido,
                'raw_removido' => $rawRemovido,
                'espaco_livre_mb_final' => DiskSpaceGuard::freeSpaceMb(),
                'cutoff' => $cutoff,
            ];
        } finally {
            $this->repository->releaseNamedLock(self::LOCK_NAME);
        }
    }

    /** @return list<int> */
    private function idsCargasElegiveis(string $cutoff): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM cargas_arquivos
             WHERE status IN ('concluido','concluido_com_erros') AND criado_em < :cutoff
             ORDER BY id"
        );
        $stmt->execute(['cutoff' => $cutoff]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $params @param null|callable(string,int,int,int):void $progress */
    private function processarEmLotes(string $etapa, string $sql, array $params, int $minFreeMb, ?callable $progress): int
    {
        $total = 0;
        while (true) {
            DiskSpaceGuard::assertFreeSpace($minFreeMb);
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $afetadas = $stmt->rowCount();
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            if ($afetadas === 0) {
                break;
            }
            $total += $afetadas;
            if ($progress !== null) {
                $progress($etapa, $total, $afetadas, DiskSpaceGuard::freeSpaceMb());
            }
        }
        return $total;
    }
}
