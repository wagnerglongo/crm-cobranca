CREATE TABLE IF NOT EXISTS acordo_regras_carencia (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    produto VARCHAR(180) NOT NULL DEFAULT '',
    dias_carencia INT UNSIGNED NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_acordo_carencia_credor_produto (credor_id, produto),
    KEY idx_acordo_carencia_credor_ativo (credor_id, ativo),
    KEY idx_acordo_carencia_produto (produto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
