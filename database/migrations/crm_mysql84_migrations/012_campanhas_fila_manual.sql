-- CRM Call Center - Migration 012
-- Campanhas e fila manual materializada para operacao.
-- Escopo: permitir que o gestor gere listas de acionamento por filtros sem mexer no motor ACME, Docker ou acordo.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS campanhas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(160) NOT NULL,
    descricao VARCHAR(500) NULL,
    tipo ENUM('manual','discador','sistema') NOT NULL DEFAULT 'manual',
    status ENUM('rascunho','ativa','pausada','concluida','cancelada') NOT NULL DEFAULT 'rascunho',
    prioridade SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    filtros_json JSON NULL,
    total_itens INT UNSIGNED NOT NULL DEFAULT 0,
    itens_pendentes INT UNSIGNED NOT NULL DEFAULT 0,
    itens_trabalhados INT UNSIGNED NOT NULL DEFAULT 0,
    criado_por VARCHAR(120) NULL,
    gerada_em DATETIME NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_campanhas_status_prioridade (status, prioridade),
    KEY idx_campanhas_gerada_em (gerada_em),
    KEY idx_campanhas_criado_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS campanha_itens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campanha_id BIGINT UNSIGNED NOT NULL,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    chave_item VARCHAR(80) NOT NULL,
    status ENUM('pendente','em_atendimento','trabalhado','pulado','descartado') NOT NULL DEFAULT 'pendente',
    prioridade SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    atribuido_para VARCHAR(120) NULL,
    origem_filtro JSON NULL,
    observacao TEXT NULL,
    atendimento_id BIGINT UNSIGNED NULL,
    gerado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reservado_em DATETIME NULL,
    trabalhado_em DATETIME NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_campanha_item_chave (campanha_id, chave_item),
    KEY idx_campanha_itens_status (campanha_id, status, prioridade),
    KEY idx_campanha_itens_pessoa (pessoa_id),
    KEY idx_campanha_itens_contrato (contrato_id),
    KEY idx_campanha_itens_atendimento (atendimento_id),
    CONSTRAINT fk_campanha_itens_campanha
        FOREIGN KEY (campanha_id) REFERENCES campanhas (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_campanha_itens_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_campanha_itens_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS campanha_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campanha_id BIGINT UNSIGNED NOT NULL,
    campanha_item_id BIGINT UNSIGNED NULL,
    tipo VARCHAR(80) NOT NULL,
    descricao VARCHAR(500) NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_campanha_eventos_campanha (campanha_id, criado_em),
    KEY idx_campanha_eventos_item (campanha_item_id),
    KEY idx_campanha_eventos_tipo (tipo),
    CONSTRAINT fk_campanha_eventos_campanha
        FOREIGN KEY (campanha_id) REFERENCES campanhas (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_campanha_eventos_item
        FOREIGN KEY (campanha_item_id) REFERENCES campanha_itens (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET @col_campanha_item_id_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND COLUMN_NAME = 'campanha_item_id'
);

SET @sql_add_campanha_item_id = IF(
    @col_campanha_item_id_exists = 0,
    'ALTER TABLE atendimentos_historico ADD COLUMN campanha_item_id BIGINT UNSIGNED NULL AFTER contato_id',
    'SELECT 1'
);
PREPARE stmt_add_campanha_item_id FROM @sql_add_campanha_item_id;
EXECUTE stmt_add_campanha_item_id;
DEALLOCATE PREPARE stmt_add_campanha_item_id;

SET @idx_campanha_item_id_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND INDEX_NAME = 'idx_atend_hist_campanha_item'
);

SET @sql_add_idx_campanha_item_id = IF(
    @idx_campanha_item_id_exists = 0,
    'ALTER TABLE atendimentos_historico ADD KEY idx_atend_hist_campanha_item (campanha_item_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_campanha_item_id FROM @sql_add_idx_campanha_item_id;
EXECUTE stmt_add_idx_campanha_item_id;
DEALLOCATE PREPARE stmt_add_idx_campanha_item_id;

SET @fk_atend_campanha_item_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_atend_hist_campanha_item'
);

SET @sql_add_fk_atend_campanha_item = IF(
    @fk_atend_campanha_item_exists = 0,
    'ALTER TABLE atendimentos_historico ADD CONSTRAINT fk_atend_hist_campanha_item FOREIGN KEY (campanha_item_id) REFERENCES campanha_itens (id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_add_fk_atend_campanha_item FROM @sql_add_fk_atend_campanha_item;
EXECUTE stmt_add_fk_atend_campanha_item;
DEALLOCATE PREPARE stmt_add_fk_atend_campanha_item;
