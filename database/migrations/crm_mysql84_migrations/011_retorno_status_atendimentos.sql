-- CRM Call Center - Migration 011
-- Controle formal de status dos retornos da agenda operacional.
-- Escopo: transformar proximo_acionamento em fila controlada sem quebrar atendimentos antigos.
-- Compatibilidade: registros com proximo_acionamento preenchido e retorno_status NULL devem ser tratados como pendentes pela aplicacao.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

SET @col_retorno_status_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND COLUMN_NAME = 'retorno_status'
);

SET @sql_add_retorno_status = IF(
    @col_retorno_status_exists = 0,
    "ALTER TABLE atendimentos_historico ADD COLUMN retorno_status ENUM('pendente','tratado','reagendado','cancelado') NULL AFTER proximo_acionamento",
    'SELECT 1'
);
PREPARE stmt_add_retorno_status FROM @sql_add_retorno_status;
EXECUTE stmt_add_retorno_status;
DEALLOCATE PREPARE stmt_add_retorno_status;

SET @col_retorno_tratado_em_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND COLUMN_NAME = 'retorno_tratado_em'
);

SET @sql_add_retorno_tratado_em = IF(
    @col_retorno_tratado_em_exists = 0,
    'ALTER TABLE atendimentos_historico ADD COLUMN retorno_tratado_em DATETIME NULL AFTER retorno_status',
    'SELECT 1'
);
PREPARE stmt_add_retorno_tratado_em FROM @sql_add_retorno_tratado_em;
EXECUTE stmt_add_retorno_tratado_em;
DEALLOCATE PREPARE stmt_add_retorno_tratado_em;

SET @col_retorno_observacao_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND COLUMN_NAME = 'retorno_observacao'
);

SET @sql_add_retorno_observacao = IF(
    @col_retorno_observacao_exists = 0,
    'ALTER TABLE atendimentos_historico ADD COLUMN retorno_observacao TEXT NULL AFTER retorno_tratado_em',
    'SELECT 1'
);
PREPARE stmt_add_retorno_observacao FROM @sql_add_retorno_observacao;
EXECUTE stmt_add_retorno_observacao;
DEALLOCATE PREPARE stmt_add_retorno_observacao;

SET @idx_retorno_status_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND INDEX_NAME = 'idx_atend_hist_retorno_status'
);

SET @sql_add_idx_retorno_status = IF(
    @idx_retorno_status_exists = 0,
    'ALTER TABLE atendimentos_historico ADD KEY idx_atend_hist_retorno_status (retorno_status, proximo_acionamento)',
    'SELECT 1'
);
PREPARE stmt_add_idx_retorno_status FROM @sql_add_idx_retorno_status;
EXECUTE stmt_add_idx_retorno_status;
DEALLOCATE PREPARE stmt_add_idx_retorno_status;
