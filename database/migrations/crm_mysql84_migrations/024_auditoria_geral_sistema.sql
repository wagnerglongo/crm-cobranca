-- CRM Call Center - Migration 024
-- Auditoria geral por usuario para a area operacional.
-- Executar depois das migrations de usuarios/permissoes e acordos operacionais.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS auditoria_sistema (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NULL,
    evento VARCHAR(100) NOT NULL,
    entidade_tipo VARCHAR(80) NULL,
    entidade_id BIGINT UNSIGNED NULL,
    descricao TEXT NULL,
    payload_antes JSON NULL,
    payload_depois JSON NULL,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auditoria_sistema_usuario_data (usuario_id, criado_em),
    KEY idx_auditoria_sistema_evento_data (evento, criado_em),
    KEY idx_auditoria_sistema_entidade (entidade_tipo, entidade_id, criado_em),
    KEY idx_auditoria_sistema_criado_em (criado_em),
    CONSTRAINT fk_auditoria_sistema_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
