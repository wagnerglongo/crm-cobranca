-- CRM Call Center - Migration 017
-- Regra operacional: um contrato pode ter apenas um acordo ativo por vez.
-- Ativos para esta regra: status_operacional IN ('pendente','formalizado').
-- Acordos quebrados, cancelados e cumpridos liberam novo acordo para o mesmo contrato.
--
-- Compatibilidade MySQL 8.4:
-- A coluna de controle e comum e mantida por triggers. Nao use coluna gerada
-- aqui, pois contrato_id participa de uma foreign key com acoes referenciais.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

DROP TRIGGER IF EXISTS trg_acordos_operacionais_bi_ativo;
DROP TRIGGER IF EXISTS trg_acordos_operacionais_bu_ativo;

-- Permite retomar com seguranca uma tentativa anterior desta migration.
SET @idx_acordo_ativo_contrato_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'acordos_operacionais'
      AND INDEX_NAME = 'uk_acordos_operacionais_contrato_ativo'
);

SET @sql_drop_idx_acordo_ativo_contrato = IF(
    @idx_acordo_ativo_contrato_exists > 0,
    'ALTER TABLE acordos_operacionais DROP INDEX uk_acordos_operacionais_contrato_ativo',
    'SELECT 1'
);
PREPARE stmt_drop_idx_acordo_ativo_contrato FROM @sql_drop_idx_acordo_ativo_contrato;
EXECUTE stmt_drop_idx_acordo_ativo_contrato;
DEALLOCATE PREPARE stmt_drop_idx_acordo_ativo_contrato;

SET @col_acordo_ativo_contrato_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'acordos_operacionais'
      AND COLUMN_NAME = 'acordo_ativo_contrato_id'
);

SET @sql_drop_col_acordo_ativo_contrato = IF(
    @col_acordo_ativo_contrato_exists > 0,
    'ALTER TABLE acordos_operacionais DROP COLUMN acordo_ativo_contrato_id',
    'SELECT 1'
);
PREPARE stmt_drop_col_acordo_ativo_contrato FROM @sql_drop_col_acordo_ativo_contrato;
EXECUTE stmt_drop_col_acordo_ativo_contrato;
DEALLOCATE PREPARE stmt_drop_col_acordo_ativo_contrato;

ALTER TABLE acordos_operacionais
    ADD COLUMN acordo_ativo_contrato_id BIGINT UNSIGNED NULL AFTER contrato_id;

UPDATE acordos_operacionais
SET acordo_ativo_contrato_id = CASE
    WHEN contrato_id IS NOT NULL
     AND status_operacional IN ('pendente','formalizado')
    THEN contrato_id
    ELSE NULL
END;

-- Interrompe com uma mensagem clara se um banco legado ja possuir duplicidade.
DROP PROCEDURE IF EXISTS validar_acordo_unico_ativo_017;
DELIMITER //
CREATE PROCEDURE validar_acordo_unico_ativo_017()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM acordos_operacionais
        WHERE acordo_ativo_contrato_id IS NOT NULL
        GROUP BY acordo_ativo_contrato_id
        HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existem dois ou mais acordos ativos para o mesmo contrato. Regularize os status antes de continuar.';
    END IF;
END //
DELIMITER ;

CALL validar_acordo_unico_ativo_017();
DROP PROCEDURE IF EXISTS validar_acordo_unico_ativo_017;

ALTER TABLE acordos_operacionais
    ADD UNIQUE KEY uk_acordos_operacionais_contrato_ativo (acordo_ativo_contrato_id);

SET @idx_acordos_contrato_status_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'acordos_operacionais'
      AND INDEX_NAME = 'idx_acordos_operacionais_contrato_status'
);

SET @sql_add_idx_acordos_contrato_status = IF(
    @idx_acordos_contrato_status_exists = 0,
    'ALTER TABLE acordos_operacionais ADD KEY idx_acordos_operacionais_contrato_status (contrato_id, status_operacional, criado_em)',
    'SELECT 1'
);
PREPARE stmt_add_idx_acordos_contrato_status FROM @sql_add_idx_acordos_contrato_status;
EXECUTE stmt_add_idx_acordos_contrato_status;
DEALLOCATE PREPARE stmt_add_idx_acordos_contrato_status;

DELIMITER //
CREATE TRIGGER trg_acordos_operacionais_bi_ativo
BEFORE INSERT ON acordos_operacionais
FOR EACH ROW
BEGIN
    SET NEW.acordo_ativo_contrato_id = CASE
        WHEN NEW.contrato_id IS NOT NULL
         AND NEW.status_operacional IN ('pendente','formalizado')
        THEN NEW.contrato_id
        ELSE NULL
    END;
END //

CREATE TRIGGER trg_acordos_operacionais_bu_ativo
BEFORE UPDATE ON acordos_operacionais
FOR EACH ROW
BEGIN
    SET NEW.acordo_ativo_contrato_id = CASE
        WHEN NEW.contrato_id IS NOT NULL
         AND NEW.status_operacional IN ('pendente','formalizado')
        THEN NEW.contrato_id
        ELSE NULL
    END;
END //
DELIMITER ;
