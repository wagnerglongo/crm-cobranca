-- CRM Call Center - Migration 018
-- Auditoria operacional de acordos e suporte a area preventiva de entradas/parcelas.
-- Executar depois da migration 017_acordo_unico_ativo_por_contrato.sql.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS acordos_operacionais_auditoria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    acordo_operacional_id BIGINT UNSIGNED NOT NULL,
    parcela_id BIGINT UNSIGNED NULL,
    evento VARCHAR(80) NOT NULL,
    descricao TEXT NULL,
    usuario_nome VARCHAR(120) NULL,
    origem VARCHAR(80) NOT NULL DEFAULT 'sistema',
    dados_antes JSON NULL,
    dados_depois JSON NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_acordo_auditoria_acordo_data (acordo_operacional_id, criado_em),
    KEY idx_acordo_auditoria_evento_data (evento, criado_em),
    KEY idx_acordo_auditoria_parcela (parcela_id),
    CONSTRAINT fk_acordo_op_auditoria_acordo
        FOREIGN KEY (acordo_operacional_id) REFERENCES acordos_operacionais (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_acordo_op_auditoria_parcela
        FOREIGN KEY (parcela_id) REFERENCES acordos_operacionais_parcelas (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Compatibilidade: cria um evento inicial para acordos já existentes que ainda não possuem auditoria.
INSERT INTO acordos_operacionais_auditoria (
    acordo_operacional_id,
    evento,
    descricao,
    origem,
    dados_depois,
    payload_extra,
    criado_em
)
SELECT
    ao.id,
    'auditoria_inicial',
    'Registro inicial criado pela migration 018 para preservar trilha histórica dos acordos existentes.',
    'migration_018',
    JSON_OBJECT(
        'status_operacional', ao.status_operacional,
        'status_integracao', ao.status_integracao,
        'valor_acordo', ao.valor_acordo,
        'data_promessa_pagamento', ao.data_promessa_pagamento,
        'quantidade_parcelas', ao.quantidade_parcelas,
        'forma_pagamento', ao.forma_pagamento
    ),
    JSON_OBJECT('origem_migration', '018', 'gerado_por_compatibilidade', true),
    ao.criado_em
FROM acordos_operacionais ao
WHERE NOT EXISTS (
    SELECT 1
    FROM acordos_operacionais_auditoria aud
    WHERE aud.acordo_operacional_id = ao.id
);

SET @idx_parcelas_preventiva_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'acordos_operacionais_parcelas'
      AND INDEX_NAME = 'idx_acordo_parcelas_preventiva'
);

SET @sql_add_idx_parcelas_preventiva = IF(
    @idx_parcelas_preventiva_exists = 0,
    'ALTER TABLE acordos_operacionais_parcelas ADD KEY idx_acordo_parcelas_preventiva (data_vencimento, status, tipo, acordo_operacional_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_parcelas_preventiva FROM @sql_add_idx_parcelas_preventiva;
EXECUTE stmt_add_idx_parcelas_preventiva;
DEALLOCATE PREPARE stmt_add_idx_parcelas_preventiva;

SET @idx_parcelas_pagamento_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'acordos_operacionais_parcelas'
      AND INDEX_NAME = 'idx_acordo_parcelas_pagamento'
);

SET @sql_add_idx_parcelas_pagamento = IF(
    @idx_parcelas_pagamento_exists = 0,
    'ALTER TABLE acordos_operacionais_parcelas ADD KEY idx_acordo_parcelas_pagamento (data_pagamento, status, acordo_operacional_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_parcelas_pagamento FROM @sql_add_idx_parcelas_pagamento;
EXECUTE stmt_add_idx_parcelas_pagamento;
DEALLOCATE PREPARE stmt_add_idx_parcelas_pagamento;
