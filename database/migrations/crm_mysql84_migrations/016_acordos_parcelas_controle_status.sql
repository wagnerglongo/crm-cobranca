-- CRM Call Center - Migration 016
-- Otimiza o controle operacional de parcelas de acordos.
-- Nao altera dados existentes; apenas garante indice auxiliar para consultas por acordo/status/vencimento.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

DROP PROCEDURE IF EXISTS add_idx_acordos_parcelas_016;
DELIMITER //
CREATE PROCEDURE add_idx_acordos_parcelas_016(
    IN p_index_name VARCHAR(128),
    IN p_index_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'acordos_operacionais_parcelas'
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @sql = CONCAT('ALTER TABLE acordos_operacionais_parcelas ADD INDEX ', p_index_name, ' ', p_index_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL add_idx_acordos_parcelas_016('idx_acordo_parcelas_acordo_status', '(acordo_operacional_id, status, data_vencimento)');

DROP PROCEDURE IF EXISTS add_idx_acordos_parcelas_016;
