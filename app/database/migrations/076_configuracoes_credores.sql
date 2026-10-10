CREATE TABLE IF NOT EXISTS credor_configuracoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    produto VARCHAR(180) NOT NULL DEFAULT '',
    chave VARCHAR(120) NOT NULL,
    valor LONGTEXT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'texto',
    descricao VARCHAR(500) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_credor_config_escopo_chave (credor_id, produto, chave),
    KEY idx_credor_config_credor_ativo (credor_id, ativo),
    KEY idx_credor_config_chave (chave),
    KEY idx_credor_config_produto (produto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
