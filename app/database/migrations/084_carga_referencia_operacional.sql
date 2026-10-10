-- Patch 127 / Migration 084 - Referencia operacional segura das cargas
-- Uma carga so pode retirar contratos do credor depois que o merge canonico
-- terminar sem erros. O fim da normalizacao nao e mais usado como referencia.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'cargas_arquivos'
          AND column_name = 'aplicacao_finalizada_em'
    ),
    'SELECT 1',
    'ALTER TABLE cargas_arquivos ADD COLUMN aplicacao_finalizada_em TIMESTAMP NULL DEFAULT NULL AFTER finalizado_em'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Compatibilidade com cargas ACME aplicadas antes deste patch. Somente cargas
-- cujo payload registra sucesso integral podem virar referencia automaticamente.
UPDATE cargas_arquivos
SET aplicacao_finalizada_em = COALESCE(
        STR_TO_DATE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_extra, '$.aplicacao_acme_ultima.aplicado_em')), 'null'),
            '%Y-%m-%d %H:%i:%s'
        ),
        finalizado_em,
        atualizado_em
    )
WHERE aplicacao_finalizada_em IS NULL
  AND status = 'concluido'
  AND COALESCE(linhas_com_erro, 0) = 0
  AND JSON_UNQUOTE(JSON_EXTRACT(payload_extra, '$.aplicacao_acme_ultima.status')) = 'aplicado';

-- Segundo criterio de recuperacao: staging totalmente consumido, com ao menos
-- uma linha aplicada e nenhuma linha valida/normalizada/erro pendente.
UPDATE cargas_arquivos ca
INNER JOIN (
    SELECT
        carga_arquivo_id,
        MAX(COALESCE(aplicado_em, atualizado_em)) AS aplicado_em
    FROM stg_carga_canonica
    GROUP BY carga_arquivo_id
    HAVING SUM(status = 'aplicado') > 0
       AND SUM(status IN ('normalizado', 'valido', 'erro')) = 0
) stg_ok ON stg_ok.carga_arquivo_id = ca.id
SET ca.aplicacao_finalizada_em = COALESCE(stg_ok.aplicado_em, ca.finalizado_em, ca.atualizado_em)
WHERE ca.aplicacao_finalizada_em IS NULL
  AND ca.status = 'concluido'
  AND COALESCE(ca.linhas_com_erro, 0) = 0;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'cargas_arquivos'
          AND index_name = 'idx_cargas_referencia_operacional'
    ),
    'SELECT 1',
    'ALTER TABLE cargas_arquivos ADD KEY idx_cargas_referencia_operacional (credor_id, carteira_id, layout_id, aplicacao_finalizada_em, data_referencia, id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
