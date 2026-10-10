CREATE TABLE IF NOT EXISTS usuarios_edicoes_meta (
    usuario_id BIGINT UNSIGNED NOT NULL,
    editado_por_usuario_id BIGINT UNSIGNED NULL,
    editado_por_nome VARCHAR(180) NULL,
    editado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tipo_ultima_alteracao VARCHAR(30) NOT NULL DEFAULT 'edicao',
    PRIMARY KEY (usuario_id),
    KEY idx_usuarios_edicoes_meta_editor (editado_por_usuario_id),
    KEY idx_usuarios_edicoes_meta_data (editado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
