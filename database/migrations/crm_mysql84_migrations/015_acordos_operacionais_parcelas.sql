-- CRM Call Center - Migration 015
-- Evolui acordos operacionais para suportar entrada, quantidade de parcelas, forma de pagamento e parcelas do acordo.
-- Executar depois da migration 014_acordos_operacionais_integracao.sql.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

DROP PROCEDURE IF EXISTS add_col_acordos_operacionais_015;
DELIMITER //
CREATE PROCEDURE add_col_acordos_operacionais_015(
    IN p_coluna VARCHAR(64),
    IN p_definicao TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'acordos_operacionais'
          AND COLUMN_NAME = p_coluna
    ) THEN
        SET @sql = CONCAT('ALTER TABLE acordos_operacionais ADD COLUMN ', p_coluna, ' ', p_definicao);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL add_col_acordos_operacionais_015('valor_entrada', 'DECIMAL(15,2) NULL AFTER valor_acordo');
CALL add_col_acordos_operacionais_015('data_entrada', 'DATE NULL AFTER valor_entrada');
CALL add_col_acordos_operacionais_015('quantidade_parcelas', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER data_entrada');
CALL add_col_acordos_operacionais_015('valor_parcela', 'DECIMAL(15,2) NULL AFTER quantidade_parcelas');
CALL add_col_acordos_operacionais_015('data_primeira_parcela', 'DATE NULL AFTER valor_parcela');
CALL add_col_acordos_operacionais_015('periodicidade', 'ENUM(''unica'',''semanal'',''quinzenal'',''mensal'') NOT NULL DEFAULT ''mensal'' AFTER data_primeira_parcela');
CALL add_col_acordos_operacionais_015('forma_pagamento', 'VARCHAR(80) NULL AFTER periodicidade');
CALL add_col_acordos_operacionais_015('resumo_parcelamento', 'JSON NULL AFTER forma_pagamento');

DROP PROCEDURE IF EXISTS add_col_acordos_operacionais_015;

UPDATE acordos_operacionais
SET
    quantidade_parcelas = IF(quantidade_parcelas IS NULL OR quantidade_parcelas = 0, 1, quantidade_parcelas),
    valor_parcela = COALESCE(valor_parcela, valor_acordo),
    data_primeira_parcela = COALESCE(data_primeira_parcela, data_promessa_pagamento),
    periodicidade = COALESCE(periodicidade, 'mensal'),
    forma_pagamento = COALESCE(forma_pagamento, 'nao_informado'),
    resumo_parcelamento = COALESCE(
        resumo_parcelamento,
        JSON_OBJECT(
            'migrado_migration', '015',
            'tipo', 'parcela_unica',
            'observacao', 'Acordo criado antes do controle detalhado de parcelas; uma parcela foi gerada automaticamente.'
        )
    )
WHERE id > 0;

CREATE TABLE IF NOT EXISTS acordos_operacionais_parcelas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    acordo_operacional_id BIGINT UNSIGNED NOT NULL,
    numero_parcela INT UNSIGNED NOT NULL,
    tipo ENUM('entrada','parcela') NOT NULL DEFAULT 'parcela',
    data_vencimento DATE NOT NULL,
    valor_parcela DECIMAL(15,2) NOT NULL,
    status ENUM('pendente','vencida','paga','cancelada','renegociada') NOT NULL DEFAULT 'pendente',
    codigo_boleto VARCHAR(120) NULL,
    linha_digitavel VARCHAR(255) NULL,
    nosso_numero VARCHAR(120) NULL,
    data_pagamento DATE NULL,
    valor_pago DECIMAL(15,2) NULL,
    observacao TEXT NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_acordo_parcela_numero (acordo_operacional_id, numero_parcela),
    KEY idx_acordo_parcelas_status_vencimento (status, data_vencimento),
    KEY idx_acordo_parcelas_vencimento (data_vencimento),
    CONSTRAINT fk_acordo_op_parcelas_acordo
        FOREIGN KEY (acordo_operacional_id) REFERENCES acordos_operacionais (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Compatibilidade: acordos antigos ganham uma parcela única se ainda não tiverem parcelas detalhadas.
INSERT INTO acordos_operacionais_parcelas (
    acordo_operacional_id,
    numero_parcela,
    tipo,
    data_vencimento,
    valor_parcela,
    status,
    observacao,
    payload_extra,
    criado_em,
    atualizado_em
)
SELECT
    ao.id,
    1,
    'parcela',
    COALESCE(ao.data_primeira_parcela, ao.data_promessa_pagamento),
    COALESCE(ao.valor_parcela, ao.valor_acordo),
    CASE
        WHEN ao.status_operacional = 'cumprido' THEN 'paga'
        WHEN ao.status_operacional = 'cancelado' THEN 'cancelada'
        WHEN ao.status_operacional = 'quebrado' THEN 'vencida'
        ELSE 'pendente'
    END,
    'Parcela única criada automaticamente pela migration 015 para acordo já existente.',
    JSON_OBJECT('origem_migration', '015', 'gerado_por_compatibilidade', true),
    ao.criado_em,
    ao.atualizado_em
FROM acordos_operacionais ao
WHERE NOT EXISTS (
    SELECT 1
    FROM acordos_operacionais_parcelas aop
    WHERE aop.acordo_operacional_id = ao.id
);
