-- CRM Call Center - Migration 003
-- Controle de arquivos e linhas brutas

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS cargas_arquivos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    carga_pai_id BIGINT UNSIGNED NULL,
    duplicado_de_id BIGINT UNSIGNED NULL,
    reprocessamento_de_id BIGINT UNSIGNED NULL,
    credor_id BIGINT UNSIGNED NULL,
    carteira_id BIGINT UNSIGNED NULL,
    layout_id BIGINT UNSIGNED NULL,
    nome_original VARCHAR(255) NOT NULL,
    caminho_storage VARCHAR(700) NOT NULL,
    storage_driver VARCHAR(50) NOT NULL DEFAULT 'local',
    mime_type VARCHAR(120) NULL,
    encoding_detectado VARCHAR(40) NULL,
    hash_arquivo CHAR(64) NOT NULL,
    tamanho_bytes BIGINT UNSIGNED NULL,
    data_referencia DATE NULL,
    status ENUM(
        'recebido',
        'layout_nao_identificado',
        'identificando_layout',
        'aguardando_processamento',
        'processando_raw',
        'normalizando',
        'validando',
        'aplicando',
        'indexando',
        'concluido',
        'concluido_com_erros',
        'erro',
        'cancelado'
    ) NOT NULL DEFAULT 'recebido',
    total_linhas BIGINT UNSIGNED NOT NULL DEFAULT 0,
    linhas_raw_gravadas BIGINT UNSIGNED NOT NULL DEFAULT 0,
    linhas_processadas BIGINT UNSIGNED NOT NULL DEFAULT 0,
    linhas_staging BIGINT UNSIGNED NOT NULL DEFAULT 0,
    linhas_validas BIGINT UNSIGNED NOT NULL DEFAULT 0,
    linhas_com_erro BIGINT UNSIGNED NOT NULL DEFAULT 0,
    pessoas_criadas BIGINT UNSIGNED NOT NULL DEFAULT 0,
    pessoas_atualizadas BIGINT UNSIGNED NOT NULL DEFAULT 0,
    contratos_criados BIGINT UNSIGNED NOT NULL DEFAULT 0,
    contratos_atualizados BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tentativa INT UNSIGNED NOT NULL DEFAULT 1,
    erro_resumo TEXT NULL,
    payload_amostra JSON NULL,
    payload_extra JSON NULL,
    recebido_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_em TIMESTAMP NULL DEFAULT NULL,
    finalizado_em TIMESTAMP NULL DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cargas_arquivos_hash (hash_arquivo),
    KEY idx_cargas_arquivos_status (status),
    KEY idx_cargas_arquivos_credor_data (credor_id, data_referencia),
    KEY idx_cargas_arquivos_layout (layout_id),
    KEY idx_cargas_arquivos_reprocessamento (reprocessamento_de_id),
    CONSTRAINT fk_cargas_arquivos_pai
        FOREIGN KEY (carga_pai_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cargas_arquivos_duplicado
        FOREIGN KEY (duplicado_de_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cargas_arquivos_reprocessamento
        FOREIGN KEY (reprocessamento_de_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cargas_arquivos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cargas_arquivos_carteira
        FOREIGN KEY (carteira_id) REFERENCES carteiras (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cargas_arquivos_layout
        FOREIGN KEY (layout_id) REFERENCES carga_layouts (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cargas_linhas_raw (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    carga_arquivo_id BIGINT UNSIGNED NOT NULL,
    numero_linha BIGINT UNSIGNED NOT NULL,
    linha_bruta LONGTEXT NOT NULL,
    hash_linha CHAR(64) NOT NULL,
    status ENUM('recebida','parseada','normalizada','valida','erro','ignorada','aplicada') NOT NULL DEFAULT 'recebida',
    erro TEXT NULL,
    payload_parse JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_raw_carga_linha (carga_arquivo_id, numero_linha),
    KEY idx_raw_carga_status (carga_arquivo_id, status),
    KEY idx_raw_hash_linha (hash_linha),
    CONSTRAINT fk_raw_carga_arquivo
        FOREIGN KEY (carga_arquivo_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
