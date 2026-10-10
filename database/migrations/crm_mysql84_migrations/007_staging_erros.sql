-- CRM Call Center - Migration 006
-- Acordos, pagamentos, boletos, baixas, quebras e bloqueios

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS acordos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    carteira_id BIGINT UNSIGNED NULL,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    codigo_acordo VARCHAR(120) NOT NULL,
    data_acordo DATE NULL,
    data_movimento DATE NULL,
    data_vencimento DATE NULL,
    valor_total_acordo DECIMAL(15,2) NULL,
    quantidade_parcelas INT UNSIGNED NULL,
    modo_pagamento VARCHAR(120) NULL,
    tipo_pagamento VARCHAR(120) NULL,
    ranking VARCHAR(120) NULL,
    cobradora VARCHAR(180) NULL,
    gh VARCHAR(120) NULL,
    escob_atual VARCHAR(180) NULL,
    escob_momento_acordo VARCHAR(180) NULL,
    status_acordo VARCHAR(120) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_acordos_credor_codigo (credor_id, codigo_acordo),
    KEY idx_acordos_pessoa (pessoa_id),
    KEY idx_acordos_contrato (contrato_id),
    KEY idx_acordos_status (status_acordo),
    KEY idx_acordos_data (data_acordo),
    CONSTRAINT fk_acordos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_acordos_carteira
        FOREIGN KEY (carteira_id) REFERENCES carteiras (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_acordos_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordos_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordos_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS acordo_parcelas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    acordo_id BIGINT UNSIGNED NOT NULL,
    numero_parcela INT UNSIGNED NOT NULL,
    data_vencimento DATE NULL,
    valor_parcela DECIMAL(15,2) NULL,
    data_ultimo_pagamento DATE NULL,
    status_parcela VARCHAR(120) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_acordo_parcela (acordo_id, numero_parcela),
    KEY idx_acordo_parcelas_venc (data_vencimento),
    KEY idx_acordo_parcelas_status (status_parcela),
    CONSTRAINT fk_acordo_parcelas_acordo
        FOREIGN KEY (acordo_id) REFERENCES acordos (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_acordo_parcelas_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordo_parcelas_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS pagamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    carteira_id BIGINT UNSIGNED NULL,
    pessoa_id BIGINT UNSIGNED NULL,
    contrato_id BIGINT UNSIGNED NULL,
    acordo_id BIGINT UNSIGNED NULL,
    linha_raw_id BIGINT UNSIGNED NULL,
    recibo VARCHAR(120) NULL,
    controle_origem VARCHAR(120) NULL,
    operacao VARCHAR(80) NULL,
    data_pagamento DATE NULL,
    data_baixa DATE NULL,
    data_inclusao DATE NULL,
    data_prestacao_contas DATE NULL,
    valor_pago DECIMAL(15,2) NULL,
    valor_recebido DECIMAL(15,2) NULL,
    valor_despesas DECIMAL(15,2) NULL,
    valor_tarifa DECIMAL(15,2) NULL,
    dedup_hash CHAR(64) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pagamentos_credor_dedup (credor_id, dedup_hash),
    KEY idx_pagamentos_pessoa (pessoa_id),
    KEY idx_pagamentos_contrato (contrato_id),
    KEY idx_pagamentos_acordo (acordo_id),
    KEY idx_pagamentos_data (data_pagamento),
    KEY idx_pagamentos_origem_carga (origem_carga_id),
    CONSTRAINT fk_pagamentos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_pagamentos_carteira
        FOREIGN KEY (carteira_id) REFERENCES carteiras (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pagamentos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pagamentos_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pagamentos_acordo
        FOREIGN KEY (acordo_id) REFERENCES acordos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pagamentos_raw
        FOREIGN KEY (linha_raw_id) REFERENCES cargas_linhas_raw (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pagamentos_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS boletos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    carteira_id BIGINT UNSIGNED NULL,
    pessoa_id BIGINT UNSIGNED NULL,
    contrato_id BIGINT UNSIGNED NULL,
    acordo_id BIGINT UNSIGNED NULL,
    linha_raw_id BIGINT UNSIGNED NULL,
    nosso_numero VARCHAR(120) NULL,
    codigo_barras VARCHAR(160) NULL,
    linha_digitavel VARCHAR(160) NULL,
    data_vencimento DATE NULL,
    valor_vencimento DECIMAL(15,2) NULL,
    parcela_inicial VARCHAR(40) NULL,
    parcela_final VARCHAR(40) NULL,
    campanha VARCHAR(120) NULL,
    instrucoes TEXT NULL,
    status_boleto VARCHAR(120) NULL,
    dedup_hash CHAR(64) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_boletos_credor_dedup (credor_id, dedup_hash),
    KEY idx_boletos_linha_digitavel (linha_digitavel),
    KEY idx_boletos_contrato (contrato_id),
    KEY idx_boletos_vencimento (data_vencimento),
    KEY idx_boletos_origem_carga (origem_carga_id),
    CONSTRAINT fk_boletos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_boletos_carteira
        FOREIGN KEY (carteira_id) REFERENCES carteiras (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_boletos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_boletos_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_boletos_acordo
        FOREIGN KEY (acordo_id) REFERENCES acordos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_boletos_raw
        FOREIGN KEY (linha_raw_id) REFERENCES cargas_linhas_raw (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_boletos_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS baixas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    pessoa_id BIGINT UNSIGNED NULL,
    contrato_id BIGINT UNSIGNED NULL,
    contrato_parcela_id BIGINT UNSIGNED NULL,
    linha_raw_id BIGINT UNSIGNED NULL,
    numero_parcela VARCHAR(40) NULL,
    codigo_operacao VARCHAR(80) NULL,
    motivo_codigo VARCHAR(80) NULL,
    motivo_descricao VARCHAR(500) NULL,
    data_baixa DATE NULL,
    dedup_hash CHAR(64) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_baixas_credor_dedup (credor_id, dedup_hash),
    KEY idx_baixas_contrato (contrato_id),
    KEY idx_baixas_data (data_baixa),
    CONSTRAINT fk_baixas_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_baixas_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_baixas_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_baixas_parcela
        FOREIGN KEY (contrato_parcela_id) REFERENCES contrato_parcelas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_baixas_raw
        FOREIGN KEY (linha_raw_id) REFERENCES cargas_linhas_raw (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_baixas_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS quebras_acordo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    pessoa_id BIGINT UNSIGNED NULL,
    contrato_id BIGINT UNSIGNED NULL,
    acordo_id BIGINT UNSIGNED NULL,
    linha_raw_id BIGINT UNSIGNED NULL,
    codigo_operacao VARCHAR(80) NULL,
    motivo VARCHAR(500) NULL,
    data_quebra DATE NULL,
    dedup_hash CHAR(64) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_quebras_credor_dedup (credor_id, dedup_hash),
    KEY idx_quebras_contrato (contrato_id),
    KEY idx_quebras_acordo (acordo_id),
    KEY idx_quebras_data (data_quebra),
    CONSTRAINT fk_quebras_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_quebras_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_quebras_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_quebras_acordo
        FOREIGN KEY (acordo_id) REFERENCES acordos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_quebras_raw
        FOREIGN KEY (linha_raw_id) REFERENCES cargas_linhas_raw (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_quebras_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS bloqueios_debito_conta (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    linha_raw_id BIGINT UNSIGNED NULL,
    unidade_cobranca VARCHAR(120) NULL,
    contrato_codigo VARCHAR(120) NULL,
    id_controle_origem VARCHAR(120) NULL,
    codigo_operacao VARCHAR(80) NULL,
    data_status DATE NULL,
    status_descricao VARCHAR(255) NULL,
    bloqueado TINYINT(1) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bloqueios_controle_data (credor_id, id_controle_origem, data_status),
    KEY idx_bloqueios_contrato (contrato_id),
    KEY idx_bloqueios_contrato_codigo (credor_id, contrato_codigo),
    KEY idx_bloqueios_status (bloqueado, data_status),
    CONSTRAINT fk_bloqueios_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bloqueios_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bloqueios_raw
        FOREIGN KEY (linha_raw_id) REFERENCES cargas_linhas_raw (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bloqueios_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
