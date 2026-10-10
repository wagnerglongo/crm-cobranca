-- CRM Call Center - Migration 005
-- Entidades canônicas de contratos, parcelas e veículos

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS contratos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    carteira_id BIGINT UNSIGNED NULL,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    codigo_contrato VARCHAR(120) NOT NULL,
    conta_id VARCHAR(120) NULL,
    devedor_origem_id VARCHAR(120) NULL,
    produto VARCHAR(180) NULL,
    subproduto VARCHAR(180) NULL,
    lote VARCHAR(180) NULL,
    fila VARCHAR(180) NULL,
    status_devedor VARCHAR(120) NULL,
    status_contrato VARCHAR(120) NULL,
    filial_codigo VARCHAR(80) NULL,
    filial_nome VARCHAR(180) NULL,
    unidade_cobranca VARCHAR(120) NULL,
    moeda CHAR(3) NULL,
    indice VARCHAR(80) NULL,
    numero_cartao_mascarado VARCHAR(80) NULL,
    data_corte DATE NULL,
    data_bordero DATE NULL,
    data_liberacao DATE NULL,
    data_vencimento_inicial DATE NULL,
    dias_atraso INT NULL,
    saldo_atual DECIMAL(15,2) NULL,
    saldo_contabil DECIMAL(15,2) NULL,
    valor_total_pago DECIMAL(15,2) NULL,
    valor_principal DECIMAL(15,2) NULL,
    valor_financiado DECIMAL(15,2) NULL,
    valor_contratado DECIMAL(15,2) NULL,
    valor_iof DECIMAL(15,2) NULL,
    valor_tac DECIMAL(15,2) NULL,
    valor_encargos DECIMAL(15,2) NULL,
    valor_mensal DECIMAL(15,2) NULL,
    taxa_pactuada DECIMAL(12,6) NULL,
    taxa_tir DECIMAL(12,6) NULL,
    cet_anual DECIMAL(12,6) NULL,
    cet_mensal DECIMAL(12,6) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_contratos_credor_codigo (credor_id, codigo_contrato),
    KEY idx_contratos_pessoa (pessoa_id),
    KEY idx_contratos_carteira (carteira_id),
    KEY idx_contratos_status (status_contrato),
    KEY idx_contratos_produto (produto),
    KEY idx_contratos_origem_carga (origem_carga_id),
    CONSTRAINT fk_contratos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_contratos_carteira
        FOREIGN KEY (carteira_id) REFERENCES carteiras (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_contratos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_contratos_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_contratos_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS contrato_parcelas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contrato_id BIGINT UNSIGNED NOT NULL,
    numero_parcela VARCHAR(40) NULL,
    data_vencimento DATE NULL,
    saldo_parcela DECIMAL(15,2) NULL,
    valor_original DECIMAL(15,2) NULL,
    valor_juros DECIMAL(15,2) NULL,
    valor_multa DECIMAL(15,2) NULL,
    valor_desconto DECIMAL(15,2) NULL,
    status_parcela VARCHAR(120) NULL,
    flag_liquidado TINYINT(1) NULL,
    data_liquidacao DATE NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_parcela_contrato_numero_venc (contrato_id, numero_parcela, data_vencimento),
    KEY idx_parcelas_vencimento (data_vencimento),
    KEY idx_parcelas_status (status_parcela),
    KEY idx_parcelas_origem_carga (origem_carga_id),
    CONSTRAINT fk_parcelas_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_parcelas_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_parcelas_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS contrato_veiculos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contrato_id BIGINT UNSIGNED NOT NULL,
    descricao VARCHAR(500) NULL,
    marca VARCHAR(120) NULL,
    modelo VARCHAR(160) NULL,
    ano_fabricacao SMALLINT UNSIGNED NULL,
    ano_modelo SMALLINT UNSIGNED NULL,
    categoria VARCHAR(120) NULL,
    placa VARCHAR(20) NULL,
    chassi VARCHAR(50) NULL,
    renavam VARCHAR(50) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_veiculos_contrato (contrato_id),
    KEY idx_veiculos_marca_modelo (marca, modelo),
    KEY idx_veiculos_placa (placa),
    CONSTRAINT fk_veiculos_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_veiculos_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_veiculos_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
