-- Patch 078 - Relatorios CSV e acesso read-only para Excel/Power Query
-- Token em texto puro nunca e salvo. Apenas SHA-256 e prefixo de identificacao.

CREATE TABLE IF NOT EXISTS relatorios_api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_prefixo VARCHAR(20) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NULL,
    ultimo_uso_em DATETIME NULL,
    revogado_em DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_relatorios_api_token_hash (token_hash),
    KEY idx_relatorios_api_usuario (usuario_id, ativo),
    KEY idx_relatorios_api_expira (ativo, expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
