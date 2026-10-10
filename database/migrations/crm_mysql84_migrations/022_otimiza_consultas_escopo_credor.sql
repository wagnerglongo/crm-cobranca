-- CRM Call Center - Migration 022
-- Otimizacao de consultas com escopo por credor/carteira.
-- Executar depois do patch 021.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

DROP PROCEDURE IF EXISTS crm_add_index_if_not_exists;
DELIMITER $$
CREATE PROCEDURE crm_add_index_if_not_exists(
    IN p_table_name VARCHAR(128),
    IN p_index_name VARCHAR(128),
    IN p_index_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
        LIMIT 1
    ) THEN
        SET @crm_add_index_sql = p_index_sql;
        PREPARE crm_add_index_stmt FROM @crm_add_index_sql;
        EXECUTE crm_add_index_stmt;
        DEALLOCATE PREPARE crm_add_index_stmt;
    END IF;
END$$
DELIMITER ;

CALL crm_add_index_if_not_exists(
    'contratos',
    'idx_contratos_credor_pessoa_atualizado',
    'CREATE INDEX idx_contratos_credor_pessoa_atualizado ON contratos (credor_id, pessoa_id, atualizado_em)'
);

CALL crm_add_index_if_not_exists(
    'contratos',
    'idx_contratos_carteira_pessoa_atualizado',
    'CREATE INDEX idx_contratos_carteira_pessoa_atualizado ON contratos (carteira_id, pessoa_id, atualizado_em)'
);

CALL crm_add_index_if_not_exists(
    'contratos',
    'idx_contratos_pessoa_credor_carteira',
    'CREATE INDEX idx_contratos_pessoa_credor_carteira ON contratos (pessoa_id, credor_id, carteira_id)'
);

CALL crm_add_index_if_not_exists(
    'contratos',
    'idx_contratos_credor_codigo_pessoa',
    'CREATE INDEX idx_contratos_credor_codigo_pessoa ON contratos (credor_id, codigo_contrato, pessoa_id)'
);

CALL crm_add_index_if_not_exists(
    'pessoa_contatos',
    'idx_contatos_pessoa_valor_normalizado',
    'CREATE INDEX idx_contatos_pessoa_valor_normalizado ON pessoa_contatos (pessoa_id, valor_normalizado)'
);

CALL crm_add_index_if_not_exists(
    'usuario_credores',
    'idx_usuario_credores_usuario_consultar',
    'CREATE INDEX idx_usuario_credores_usuario_consultar ON usuario_credores (usuario_id, pode_consultar, credor_id)'
);

CALL crm_add_index_if_not_exists(
    'usuario_credores',
    'idx_usuario_credores_usuario_operar',
    'CREATE INDEX idx_usuario_credores_usuario_operar ON usuario_credores (usuario_id, pode_operar, credor_id)'
);

CALL crm_add_index_if_not_exists(
    'usuario_credores',
    'idx_usuario_credores_usuario_acordo',
    'CREATE INDEX idx_usuario_credores_usuario_acordo ON usuario_credores (usuario_id, pode_fechar_acordo, credor_id)'
);

CALL crm_add_index_if_not_exists(
    'usuario_carteiras',
    'idx_usuario_carteiras_usuario_consultar',
    'CREATE INDEX idx_usuario_carteiras_usuario_consultar ON usuario_carteiras (usuario_id, pode_consultar, carteira_id)'
);

CALL crm_add_index_if_not_exists(
    'usuario_carteiras',
    'idx_usuario_carteiras_usuario_operar',
    'CREATE INDEX idx_usuario_carteiras_usuario_operar ON usuario_carteiras (usuario_id, pode_operar, carteira_id)'
);

CALL crm_add_index_if_not_exists(
    'usuario_carteiras',
    'idx_usuario_carteiras_usuario_acordo',
    'CREATE INDEX idx_usuario_carteiras_usuario_acordo ON usuario_carteiras (usuario_id, pode_fechar_acordo, carteira_id)'
);

DROP PROCEDURE IF EXISTS crm_add_index_if_not_exists;
