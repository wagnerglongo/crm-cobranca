-- CRM Call Center - Migration 007
-- Staging canônico e erros de validação/processamento

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS stg_carga_canonica (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    carga_arquivo_id BIGINT UNSIGNED NOT NULL,
    linha_raw_id BIGINT UNSIGNED NOT NULL,
    credor_id BIGINT UNSIGNED NOT NULL,
    carteira_id BIGINT UNSIGNED NULL,
    layout_id BIGINT UNSIGNED NULL,

    documento VARCHAR(20) NULL,
    tipo_documento ENUM('CPF','CNPJ','OUTRO') NULL,
    nome VARCHAR(255) NULL,
    nome_mae VARCHAR(255) NULL,
    nome_pai VARCHAR(255) NULL,
    data_nascimento DATE NULL,
    sexo VARCHAR(30) NULL,
    estado_civil VARCHAR(60) NULL,
    rg VARCHAR(50) NULL,
    renda_mensal DECIMAL(15,2) NULL,

    codigo_contrato VARCHAR(120) NULL,
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

    numero_parcela VARCHAR(40) NULL,
    data_vencimento_parcela DATE NULL,
    saldo_parcela DECIMAL(15,2) NULL,
    valor_original_parcela DECIMAL(15,2) NULL,
    valor_juros_parcela DECIMAL(15,2) NULL,
    status_parcela VARCHAR(120) NULL,
    flag_liquidado TINYINT(1) NULL,
    data_liquidacao DATE NULL,

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

    telefone_1 VARCHAR(60) NULL,
    telefone_2 VARCHAR(60) NULL,
    telefone_3 VARCHAR(60) NULL,
    email_1 VARCHAR(255) NULL,
    email_2 VARCHAR(255) NULL,
    cep VARCHAR(20) NULL,
    uf CHAR(2) NULL,
    cidade VARCHAR(120) NULL,
    bairro VARCHAR(150) NULL,
    logradouro VARCHAR(255) NULL,
    numero_endereco VARCHAR(30) NULL,
    complemento VARCHAR(255) NULL,

    descricao_veiculo VARCHAR(500) NULL,
    marca_veiculo VARCHAR(120) NULL,
    modelo_veiculo VARCHAR(160) NULL,
    ano_fabricacao SMALLINT UNSIGNED NULL,
    ano_modelo SMALLINT UNSIGNED NULL,
    categoria_veiculo VARCHAR(120) NULL,

    codigo_acordo VARCHAR(120) NULL,
    numero_parcela_acordo INT UNSIGNED NULL,
    data_acordo DATE NULL,
    data_movimento_acordo DATE NULL,
    data_vencimento_acordo DATE NULL,
    valor_total_acordo DECIMAL(15,2) NULL,
    valor_parcela_acordo DECIMAL(15,2) NULL,
    quantidade_parcelas_acordo INT UNSIGNED NULL,
    status_acordo VARCHAR(120) NULL,
    modo_pagamento VARCHAR(120) NULL,
    tipo_pagamento VARCHAR(120) NULL,

    data_pagamento DATE NULL,
    data_baixa DATE NULL,
    valor_pago DECIMAL(15,2) NULL,
    valor_recebido DECIMAL(15,2) NULL,
    valor_despesas DECIMAL(15,2) NULL,
    valor_tarifa DECIMAL(15,2) NULL,
    recibo VARCHAR(120) NULL,
    controle_origem VARCHAR(120) NULL,

    nosso_numero VARCHAR(120) NULL,
    codigo_barras VARCHAR(160) NULL,
    linha_digitavel VARCHAR(160) NULL,
    data_vencimento_boleto DATE NULL,
    valor_vencimento_boleto DECIMAL(15,2) NULL,

    codigo_operacao VARCHAR(80) NULL,
    motivo_codigo VARCHAR(80) NULL,
    motivo_descricao VARCHAR(500) NULL,
    data_quebra DATE NULL,
    bloqueio_deb_conta TINYINT(1) NULL,
    data_status_bloqueio DATE NULL,

    pessoa_id BIGINT UNSIGNED NULL,
    contrato_id BIGINT UNSIGNED NULL,
    acordo_id BIGINT UNSIGNED NULL,
    hash_negocio CHAR(64) NULL,
    status ENUM('normalizado','valido','erro','aplicado','ignorado') NOT NULL DEFAULT 'normalizado',
    erro TEXT NULL,
    payload_origem JSON NULL,
    payload_normalizado JSON NULL,
    payload_extra JSON NULL,
    aplicado_em TIMESTAMP NULL DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_stg_raw (carga_arquivo_id, linha_raw_id),
    KEY idx_stg_carga_status (carga_arquivo_id, status),
    KEY idx_stg_documento (documento),
    KEY idx_stg_contrato (credor_id, codigo_contrato),
    KEY idx_stg_hash_negocio (hash_negocio),
    KEY idx_stg_pessoa (pessoa_id),
    KEY idx_stg_contrato_id (contrato_id),
    CONSTRAINT fk_stg_carga
        FOREIGN KEY (carga_arquivo_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_stg_raw
        FOREIGN KEY (linha_raw_id) REFERENCES cargas_linhas_raw (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_stg_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_stg_carteira
        FOREIGN KEY (carteira_id) REFERENCES carteiras (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_stg_layout
        FOREIGN KEY (layout_id) REFERENCES carga_layouts (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_stg_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_stg_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_stg_acordo
        FOREIGN KEY (acordo_id) REFERENCES acordos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cargas_erros (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    carga_arquivo_id BIGINT UNSIGNED NOT NULL,
    linha_raw_id BIGINT UNSIGNED NULL,
    staging_id BIGINT UNSIGNED NULL,
    etapa ENUM('recepcao','layout','raw','parse','normalizacao','validacao','merge','indexacao','sistema') NOT NULL,
    codigo_erro VARCHAR(120) NOT NULL,
    mensagem TEXT NOT NULL,
    campo_origem VARCHAR(180) NULL,
    campo_canonico VARCHAR(220) NULL,
    severidade ENUM('info','alerta','erro','critico') NOT NULL DEFAULT 'erro',
    valor_original TEXT NULL,
    payload_contexto JSON NULL,
    resolvido TINYINT(1) NOT NULL DEFAULT 0,
    resolvido_em TIMESTAMP NULL DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_erros_carga_etapa (carga_arquivo_id, etapa),
    KEY idx_erros_linha (linha_raw_id),
    KEY idx_erros_staging (staging_id),
    KEY idx_erros_codigo (codigo_erro),
    KEY idx_erros_resolvido (resolvido),
    CONSTRAINT fk_erros_carga
        FOREIGN KEY (carga_arquivo_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_erros_raw
        FOREIGN KEY (linha_raw_id) REFERENCES cargas_linhas_raw (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_erros_staging
        FOREIGN KEY (staging_id) REFERENCES stg_carga_canonica (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
