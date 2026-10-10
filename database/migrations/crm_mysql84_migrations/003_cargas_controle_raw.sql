-- CRM Call Center - Migration 002
-- Dicionário canônico e layouts versionados de carga

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS campos_canonicos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entidade VARCHAR(80) NOT NULL,
    campo VARCHAR(120) NOT NULL,
    caminho_canonico VARCHAR(220) GENERATED ALWAYS AS (CONCAT(entidade, '.', campo)) STORED,
    tipo_dado ENUM('string','int','decimal','date','datetime','bool','json','text') NOT NULL DEFAULT 'string',
    obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
    descricao VARCHAR(700) NULL,
    exemplo_origem VARCHAR(255) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_campos_canonicos_entidade_campo (entidade, campo),
    UNIQUE KEY uk_campos_canonicos_caminho (caminho_canonico),
    KEY idx_campos_canonicos_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS carga_layouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(100) NOT NULL,
    nome VARCHAR(180) NOT NULL,
    versao INT UNSIGNED NOT NULL DEFAULT 1,
    tipo_arquivo ENUM('delimitado','csv','posicional','desconhecido') NOT NULL DEFAULT 'delimitado',
    separador VARCHAR(10) NULL,
    encoding_padrao VARCHAR(40) NULL,
    possui_cabecalho TINYINT(1) NOT NULL DEFAULT 1,
    qtd_colunas INT UNSIGNED NULL,
    tamanho_linha INT UNSIGNED NULL,
    padrao_nome_arquivo VARCHAR(255) NULL,
    hash_cabecalho CHAR(64) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    vigente_de DATE NULL,
    vigente_ate DATE NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_carga_layouts_credor_codigo_versao (credor_id, codigo, versao),
    KEY idx_carga_layouts_credor_ativo (credor_id, ativo),
    KEY idx_carga_layouts_tipo (tipo_arquivo),
    CONSTRAINT fk_carga_layouts_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS carga_layout_campos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    layout_id BIGINT UNSIGNED NOT NULL,
    campo_canonico_id BIGINT UNSIGNED NULL,
    campo_destino VARCHAR(220) NULL,
    nome_origem VARCHAR(180) NULL,
    ordem INT UNSIGNED NULL,
    posicao_inicial INT UNSIGNED NULL,
    posicao_final INT UNSIGNED NULL,
    tamanho INT UNSIGNED NULL,
    tipo_dado ENUM('string','int','decimal','date','datetime','bool','json','text') NOT NULL DEFAULT 'string',
    obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
    permitir_vazio TINYINT(1) NOT NULL DEFAULT 1,
    trim_valor TINYINT(1) NOT NULL DEFAULT 1,
    escala_decimal INT UNSIGNED NULL,
    formato_data VARCHAR(40) NULL,
    valor_default VARCHAR(255) NULL,
    regra_normalizacao JSON NULL,
    descricao VARCHAR(700) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_layout_campos_ordem (layout_id, ordem),
    UNIQUE KEY uk_layout_campos_nome_origem (layout_id, nome_origem),
    KEY idx_layout_campos_canonico (campo_canonico_id),
    KEY idx_layout_campos_destino (campo_destino),
    CONSTRAINT fk_layout_campos_layout
        FOREIGN KEY (layout_id) REFERENCES carga_layouts (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_layout_campos_canonico
        FOREIGN KEY (campo_canonico_id) REFERENCES campos_canonicos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
