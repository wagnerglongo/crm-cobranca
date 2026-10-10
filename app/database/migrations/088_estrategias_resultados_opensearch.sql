-- Patch 133 / Migration 088 - Vinculo deterministico de acordo com estrategia.
-- O OpenSearch continua sendo uma projecao; o MySQL preserva a relacao oficial
-- entre o item materializado da estrategia, seu acionamento principal e o acordo.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'estrategia_contratos'
          AND column_name = 'acordo_id'
    ),
    'SELECT 1',
    'ALTER TABLE estrategia_contratos ADD COLUMN acordo_id BIGINT UNSIGNED NULL AFTER atendimento_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'estrategia_contratos'
          AND index_name = 'idx_estrategia_contratos_acordo'
    ),
    'SELECT 1',
    'ALTER TABLE estrategia_contratos ADD KEY idx_estrategia_contratos_acordo (acordo_id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'estrategia_contratos'
          AND constraint_name = 'fk_estrategia_contratos_acordo'
          AND constraint_type = 'FOREIGN KEY'
    ),
    'SELECT 1',
    'ALTER TABLE estrategia_contratos ADD CONSTRAINT fk_estrategia_contratos_acordo FOREIGN KEY (acordo_id) REFERENCES acordos_operacionais (id) ON UPDATE CASCADE ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Recupera vínculos de acordos de testes anteriores quando a auditoria do
-- Pactua já registrou acordo_id, mas estrategia_contratos ainda não possuía a
-- coluna dedicada. Registros sem evidência determinística permanecem NULL.
UPDATE estrategia_contratos ec
INNER JOIN (
    SELECT a.entidade_id AS estrategia_contrato_id,
           MAX(a.id) AS auditoria_id
    FROM auditoria_sistema a
    WHERE a.evento = 'estrategia.contrato_trabalhado'
      AND a.entidade_tipo = 'estrategia_contrato'
      AND JSON_VALID(a.payload_depois)
      AND CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.payload_depois, '$.acordo_id')), 'null'), '0') AS UNSIGNED) > 0
    GROUP BY a.entidade_id
) ult ON ult.estrategia_contrato_id = ec.id
INNER JOIN auditoria_sistema a ON a.id = ult.auditoria_id
INNER JOIN acordos_operacionais ao
    ON ao.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.payload_depois, '$.acordo_id')) AS UNSIGNED)
SET ec.acordo_id = ao.id
WHERE ec.acordo_id IS NULL;
