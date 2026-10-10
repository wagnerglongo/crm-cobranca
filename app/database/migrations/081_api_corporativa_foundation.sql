-- Patch 121 / Migration 081 - Fundacao da API corporativa Pactua
-- Tokens opacos: o valor integral nunca e persistido, somente SHA-256 e prefixo.

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    tipo ENUM('usuario','integracao') NOT NULL DEFAULT 'usuario',
    token_hash CHAR(64) NOT NULL,
    token_prefixo VARCHAR(24) NOT NULL,
    escopos JSON NOT NULL,
    familia_id CHAR(36) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    ultimo_uso_em DATETIME NULL,
    criado_ip VARCHAR(64) NULL,
    criado_user_agent VARCHAR(255) NULL,
    ultimo_ip VARCHAR(64) NULL,
    ultimo_user_agent VARCHAR(255) NULL,
    revogado_em DATETIME NULL,
    revogado_por_usuario_id BIGINT UNSIGNED NULL,
    motivo_revogacao VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_usuario_ativo (usuario_id, revogado_em, expira_em),
    KEY idx_api_tokens_familia (familia_id, revogado_em),
    KEY idx_api_tokens_tipo_expira (tipo, expira_em),
    CONSTRAINT fk_api_tokens_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_api_tokens_revogado_por
        FOREIGN KEY (revogado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS api_refresh_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    access_token_id BIGINT UNSIGNED NOT NULL,
    familia_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_prefixo VARCHAR(24) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME NULL,
    revogado_em DATETIME NULL,
    substituido_por_id BIGINT UNSIGNED NULL,
    criado_ip VARCHAR(64) NULL,
    criado_user_agent VARCHAR(255) NULL,
    ultimo_ip VARCHAR(64) NULL,
    ultimo_user_agent VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_refresh_tokens_hash (token_hash),
    KEY idx_api_refresh_usuario_ativo (usuario_id, revogado_em, expira_em),
    KEY idx_api_refresh_familia (familia_id, revogado_em),
    KEY idx_api_refresh_access (access_token_id),
    KEY idx_api_refresh_substituido (substituido_por_id),
    CONSTRAINT fk_api_refresh_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_api_refresh_access
        FOREIGN KEY (access_token_id) REFERENCES api_tokens (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_api_refresh_substituido
        FOREIGN KEY (substituido_por_id) REFERENCES api_refresh_tokens (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS api_idempotencias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    metodo VARCHAR(12) NOT NULL,
    rota VARCHAR(190) NOT NULL,
    chave_hash CHAR(64) NOT NULL,
    requisicao_hash CHAR(64) NOT NULL,
    status ENUM('processando','concluido','falhou') NOT NULL DEFAULT 'processando',
    resposta_status SMALLINT UNSIGNED NULL,
    resposta_json JSON NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_idempotencia (usuario_id, metodo, rota, chave_hash),
    KEY idx_api_idempotencia_expira (expira_em),
    CONSTRAINT fk_api_idempotencia_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('api.documentacao.visualizar', 'Visualizar documentacao da API', 'API', 'Acessar a especificacao OpenAPI e a interface Swagger da API corporativa.', 1),
('api.tokens.gerenciar', 'Gerenciar tokens de integracao', 'API', 'Criar, listar e revogar tokens especificos de integracao.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('api.documentacao.visualizar', 'api.tokens.gerenciar')
WHERE pa.codigo = 'admin';
