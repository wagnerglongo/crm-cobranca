-- CRM Call Center - Migration 001
-- Base de credores e carteiras
-- Compatível com MySQL 8.4 / InnoDB / utf8mb4

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS credores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(50) NOT NULL,
    nome VARCHAR(150) NOT NULL,
    descricao VARCHAR(500) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_credores_codigo (codigo),
    KEY idx_credores_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS carteiras (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(80) NOT NULL,
    nome VARCHAR(180) NOT NULL,
    descricao VARCHAR(500) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_carteiras_credor_codigo (credor_id, codigo),
    KEY idx_carteiras_credor_ativo (credor_id, ativo),
    CONSTRAINT fk_carteiras_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
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
-- CRM Call Center - Migration 004
-- Entidades canônicas de pessoas, contatos e endereços

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS pessoas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    documento VARCHAR(20) NOT NULL,
    tipo_documento ENUM('CPF','CNPJ','OUTRO') NOT NULL DEFAULT 'CPF',
    nome VARCHAR(255) NOT NULL,
    nome_mae VARCHAR(255) NULL,
    nome_pai VARCHAR(255) NULL,
    data_nascimento DATE NULL,
    sexo VARCHAR(30) NULL,
    estado_civil VARCHAR(60) NULL,
    rg VARCHAR(50) NULL,
    renda_mensal DECIMAL(15,2) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pessoas_documento (documento),
    KEY idx_pessoas_nome (nome),
    KEY idx_pessoas_tipo_documento (tipo_documento),
    KEY idx_pessoas_origem_carga (origem_carga_id),
    CONSTRAINT fk_pessoas_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pessoas_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS pessoa_contatos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('telefone','email','whatsapp','outro') NOT NULL,
    subtipo VARCHAR(60) NULL,
    valor VARCHAR(255) NOT NULL,
    valor_normalizado VARCHAR(255) NOT NULL,
    ddd VARCHAR(5) NULL,
    telefone VARCHAR(30) NULL,
    email VARCHAR(255) NULL,
    status ENUM('novo','hot','correto','incorreto','desconhecido','nao_confirmado','bloqueado') NOT NULL DEFAULT 'novo',
    principal TINYINT(1) NOT NULL DEFAULT 0,
    origem VARCHAR(80) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pessoa_contato_valor (pessoa_id, tipo, valor_normalizado),
    KEY idx_contatos_valor_normalizado (valor_normalizado),
    KEY idx_contatos_tipo_status (tipo, status),
    KEY idx_contatos_origem_carga (origem_carga_id),
    CONSTRAINT fk_contatos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_contatos_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_contatos_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS pessoa_enderecos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(60) NULL,
    cep VARCHAR(20) NULL,
    uf CHAR(2) NULL,
    cidade VARCHAR(120) NULL,
    bairro VARCHAR(150) NULL,
    logradouro VARCHAR(255) NULL,
    numero VARCHAR(30) NULL,
    complemento VARCHAR(255) NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    origem VARCHAR(80) NULL,
    origem_carga_id BIGINT UNSIGNED NULL,
    ultima_carga_id BIGINT UNSIGNED NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_enderecos_pessoa (pessoa_id),
    KEY idx_enderecos_cep (cep),
    KEY idx_enderecos_cidade_uf (cidade, uf),
    KEY idx_enderecos_origem_carga (origem_carga_id),
    CONSTRAINT fk_enderecos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_enderecos_origem_carga
        FOREIGN KEY (origem_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_enderecos_ultima_carga
        FOREIGN KEY (ultima_carga_id) REFERENCES cargas_arquivos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
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
-- CRM Call Center - Migration 008
-- Seeds iniciais: credores, layouts, campos canônicos e mapeamento ACME

SET NAMES utf8mb4;
SET time_zone = '-03:00';

INSERT INTO credores (codigo, nome, descricao, ativo, payload_extra)
VALUES
    ('ACME', 'ACME', 'Credor ACME (fictício) / cargas ACMECOB delimitadas por pipe', 1, JSON_OBJECT('prioridade_importacao', 1)),
    ('GLOBEX', 'GLOBEX', 'Credor GLOBEX (fictício) com múltiplos layouts de carga/evento', 1, JSON_OBJECT('prioridade_importacao', 2)),
    ('INITECH', 'INITECH', 'Credor INITECH (exemplo sem layout de carga próprio)', 1, JSON_OBJECT('prioridade_importacao', 3))
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo),
    payload_extra = VALUES(payload_extra),
    atualizado_em = CURRENT_TIMESTAMP;

INSERT INTO carteiras (credor_id, codigo, nome, descricao, ativo)
SELECT id, 'PADRAO', CONCAT(nome, ' - Padrão'), 'Carteira padrão inicial para cargas sem carteira identificada', 1
FROM credores
WHERE codigo IN ('ACME','GLOBEX','INITECH')
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo),
    atualizado_em = CURRENT_TIMESTAMP;

INSERT INTO campos_canonicos (entidade, campo, tipo_dado, obrigatorio, descricao)
VALUES
    ('pessoas', 'documento', 'string', 1, 'Documento normalizado da pessoa, somente dígitos CPF/CNPJ'),
    ('pessoas', 'tipo_documento', 'string', 0, 'CPF, CNPJ ou OUTRO'),
    ('pessoas', 'nome', 'string', 1, 'Nome do devedor/cliente'),
    ('pessoas', 'nome_mae', 'string', 0, 'Nome da mãe'),
    ('pessoas', 'nome_pai', 'string', 0, 'Nome do pai'),
    ('pessoas', 'data_nascimento', 'date', 0, 'Data de nascimento'),
    ('pessoas', 'sexo', 'string', 0, 'Sexo/gênero cadastral quando enviado pelo credor'),
    ('pessoas', 'estado_civil', 'string', 0, 'Estado civil'),
    ('pessoas', 'rg', 'string', 0, 'RG/documento secundário'),
    ('pessoas', 'renda_mensal', 'decimal', 0, 'Renda mensal'),
    ('pessoa_contatos', 'telefone', 'string', 0, 'Telefone normalizado'),
    ('pessoa_contatos', 'email', 'string', 0, 'E-mail normalizado'),
    ('pessoa_contatos', 'status', 'string', 0, 'Status do contato: hot, correto, incorreto, desconhecido etc.'),
    ('pessoa_enderecos', 'cep', 'string', 0, 'CEP normalizado'),
    ('pessoa_enderecos', 'uf', 'string', 0, 'UF'),
    ('pessoa_enderecos', 'cidade', 'string', 0, 'Cidade'),
    ('pessoa_enderecos', 'bairro', 'string', 0, 'Bairro'),
    ('pessoa_enderecos', 'logradouro', 'string', 0, 'Logradouro'),
    ('pessoa_enderecos', 'complemento', 'string', 0, 'Complemento'),
    ('contratos', 'codigo_contrato', 'string', 1, 'Código do contrato/operação no credor'),
    ('contratos', 'conta_id', 'string', 0, 'ID interno de conta quando diferente do contrato'),
    ('contratos', 'devedor_origem_id', 'string', 0, 'ID interno do devedor na origem'),
    ('contratos', 'produto', 'string', 0, 'Produto/tipo de contrato'),
    ('contratos', 'subproduto', 'string', 0, 'Subproduto'),
    ('contratos', 'lote', 'string', 0, 'Lote/carteira operacional'),
    ('contratos', 'fila', 'string', 0, 'Fila de cobrança'),
    ('contratos', 'status_devedor', 'string', 0, 'Status do devedor na origem'),
    ('contratos', 'status_contrato', 'string', 0, 'Status do contrato na origem'),
    ('contratos', 'filial_codigo', 'string', 0, 'Código da filial'),
    ('contratos', 'filial_nome', 'string', 0, 'Nome da filial'),
    ('contratos', 'unidade_cobranca', 'string', 0, 'Unidade de cobrança'),
    ('contratos', 'moeda', 'string', 0, 'Moeda'),
    ('contratos', 'indice', 'string', 0, 'Índice financeiro'),
    ('contratos', 'numero_cartao_mascarado', 'string', 0, 'Número de cartão mascarado quando houver'),
    ('contratos', 'data_corte', 'date', 0, 'Data de corte/base'),
    ('contratos', 'data_bordero', 'date', 0, 'Data de borderô/inclusão'),
    ('contratos', 'data_liberacao', 'date', 0, 'Data de liberação'),
    ('contratos', 'data_vencimento_inicial', 'date', 0, 'Vencimento inicial/fatura principal'),
    ('contratos', 'dias_atraso', 'int', 0, 'Dias de atraso'),
    ('contratos', 'saldo_atual', 'decimal', 0, 'Saldo atual da dívida'),
    ('contratos', 'saldo_contabil', 'decimal', 0, 'Saldo contábil'),
    ('contratos', 'valor_total_pago', 'decimal', 0, 'Total já pago/coletado'),
    ('contratos', 'valor_principal', 'decimal', 0, 'Valor principal'),
    ('contratos', 'valor_financiado', 'decimal', 0, 'Valor financiado'),
    ('contratos', 'valor_contratado', 'decimal', 0, 'Valor contratado'),
    ('contratos', 'valor_iof', 'decimal', 0, 'IOF'),
    ('contratos', 'valor_tac', 'decimal', 0, 'TAC'),
    ('contratos', 'valor_encargos', 'decimal', 0, 'Encargos/juros do contrato'),
    ('contratos', 'valor_mensal', 'decimal', 0, 'Valor mensal'),
    ('contratos', 'taxa_pactuada', 'decimal', 0, 'Taxa pactuada'),
    ('contratos', 'taxa_tir', 'decimal', 0, 'Taxa TIR'),
    ('contratos', 'cet_anual', 'decimal', 0, 'CET anual'),
    ('contratos', 'cet_mensal', 'decimal', 0, 'CET mensal'),
    ('contrato_parcelas', 'numero_parcela', 'string', 0, 'Número da parcela'),
    ('contrato_parcelas', 'data_vencimento', 'date', 0, 'Data de vencimento da parcela'),
    ('contrato_parcelas', 'saldo_parcela', 'decimal', 0, 'Saldo/valor da parcela'),
    ('contrato_parcelas', 'valor_original', 'decimal', 0, 'Valor original da parcela'),
    ('contrato_parcelas', 'valor_juros', 'decimal', 0, 'Juros da parcela'),
    ('contrato_parcelas', 'status_parcela', 'string', 0, 'Status da parcela'),
    ('contrato_parcelas', 'flag_liquidado', 'bool', 0, 'Indicação de parcela liquidada'),
    ('contrato_parcelas', 'data_liquidacao', 'date', 0, 'Data de liquidação'),
    ('contrato_veiculos', 'descricao', 'string', 0, 'Descrição do veículo'),
    ('contrato_veiculos', 'marca', 'string', 0, 'Marca do veículo'),
    ('contrato_veiculos', 'modelo', 'string', 0, 'Modelo do veículo'),
    ('contrato_veiculos', 'ano_fabricacao', 'int', 0, 'Ano de fabricação'),
    ('contrato_veiculos', 'ano_modelo', 'int', 0, 'Ano modelo'),
    ('contrato_veiculos', 'categoria', 'string', 0, 'Categoria do veículo'),
    ('acordos', 'codigo_acordo', 'string', 0, 'Código do acordo'),
    ('acordos', 'data_acordo', 'date', 0, 'Data do acordo'),
    ('acordos', 'valor_total_acordo', 'decimal', 0, 'Valor total do acordo'),
    ('acordos', 'quantidade_parcelas', 'int', 0, 'Quantidade de parcelas do acordo'),
    ('acordos', 'status_acordo', 'string', 0, 'Status do acordo'),
    ('acordo_parcelas', 'numero_parcela', 'int', 0, 'Número da parcela do acordo'),
    ('acordo_parcelas', 'data_vencimento', 'date', 0, 'Vencimento da parcela do acordo'),
    ('acordo_parcelas', 'valor_parcela', 'decimal', 0, 'Valor da parcela do acordo'),
    ('acordo_parcelas', 'status_parcela', 'string', 0, 'Status da parcela do acordo'),
    ('pagamentos', 'data_pagamento', 'date', 0, 'Data de pagamento'),
    ('pagamentos', 'data_baixa', 'date', 0, 'Data de baixa do pagamento'),
    ('pagamentos', 'valor_pago', 'decimal', 0, 'Valor pago'),
    ('pagamentos', 'valor_recebido', 'decimal', 0, 'Valor recebido'),
    ('pagamentos', 'valor_despesas', 'decimal', 0, 'Despesas'),
    ('pagamentos', 'valor_tarifa', 'decimal', 0, 'Tarifa'),
    ('boletos', 'nosso_numero', 'string', 0, 'Nosso número'),
    ('boletos', 'codigo_barras', 'string', 0, 'Código de barras'),
    ('boletos', 'linha_digitavel', 'string', 0, 'Linha digitável'),
    ('boletos', 'data_vencimento', 'date', 0, 'Vencimento do boleto'),
    ('boletos', 'valor_vencimento', 'decimal', 0, 'Valor de vencimento do boleto'),
    ('baixas', 'codigo_operacao', 'string', 0, 'Código de operação de baixa'),
    ('baixas', 'motivo_codigo', 'string', 0, 'Código do motivo da baixa'),
    ('baixas', 'motivo_descricao', 'string', 0, 'Descrição do motivo da baixa'),
    ('baixas', 'data_baixa', 'date', 0, 'Data da baixa'),
    ('quebras_acordo', 'codigo_operacao', 'string', 0, 'Código de operação da quebra'),
    ('quebras_acordo', 'motivo', 'string', 0, 'Motivo da quebra'),
    ('quebras_acordo', 'data_quebra', 'date', 0, 'Data da quebra'),
    ('bloqueios_debito_conta', 'unidade_cobranca', 'string', 0, 'Unidade de cobrança'),
    ('bloqueios_debito_conta', 'contrato_codigo', 'string', 0, 'Contrato informado no arquivo'),
    ('bloqueios_debito_conta', 'id_controle_origem', 'string', 0, 'ID de controle na origem'),
    ('bloqueios_debito_conta', 'data_status', 'date', 0, 'Data do status do bloqueio'),
    ('bloqueios_debito_conta', 'status_descricao', 'string', 0, 'Descrição do status do bloqueio'),
    ('bloqueios_debito_conta', 'bloqueado', 'bool', 0, 'Indica se está bloqueado para débito em conta')
ON DUPLICATE KEY UPDATE
    tipo_dado = VALUES(tipo_dado),
    obrigatorio = VALUES(obrigatorio),
    descricao = VALUES(descricao),
    ativo = 1,
    atualizado_em = CURRENT_TIMESTAMP;

INSERT INTO carga_layouts (credor_id, codigo, nome, versao, tipo_arquivo, separador, encoding_padrao, possui_cabecalho, qtd_colunas, tamanho_linha, padrao_nome_arquivo, ativo, payload_extra)
VALUES
    ((SELECT id FROM credores WHERE codigo = 'ACME'), 'ACME_COBRANCA_PIPE', 'ACME cobrança pipe', 1, 'delimitado', '|', 'utf-8-sig', 1, 55, NULL, 'ACMECOB_%', 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_10', 'GLOBEX layout 10 - carga/parcelas', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_17', 'GLOBEX layout 17 - pagamentos', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_170', 'GLOBEX layout 170 - detalhes de pagamentos', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_19', 'GLOBEX layout 19 - boleto principal', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_190', 'GLOBEX layout 190 - parcelas do boleto', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_191', 'GLOBEX layout 191 - composição do boleto', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_129', 'GLOBEX layout 129 - quebra de acordo', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_LAYOUT_907', 'GLOBEX layout 907 - bloqueio débito em conta', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_DEV', 'GLOBEX DEV - dados pessoais/localização', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, 'GLX_DEV%', 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_CONT', 'GLOBEX CONT - contratos e parcelas', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, 'GLX_CONT%', 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_B', 'GLOBEX B - baixas', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_C', 'GLOBEX C - carga', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_D', 'GLOBEX D - boletos', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_Q', 'GLOBEX Q - quebras', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_RET', 'GLOBEX RET - retorno posicional', 1, 'posicional', NULL, 'cp1252', 0, NULL, NULL, 'CREF%.RET', 1, JSON_OBJECT('seed', true)),
    ((SELECT id FROM credores WHERE codigo = 'GLOBEX'), 'GLOBEX_CTL018', 'GLOBEX CTL018 - cartão/dívida', 1, 'delimitado', ';', 'utf-8-sig', 1, NULL, NULL, NULL, 1, JSON_OBJECT('seed', true))
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    tipo_arquivo = VALUES(tipo_arquivo),
    separador = VALUES(separador),
    encoding_padrao = VALUES(encoding_padrao),
    possui_cabecalho = VALUES(possui_cabecalho),
    qtd_colunas = VALUES(qtd_colunas),
    tamanho_linha = VALUES(tamanho_linha),
    padrao_nome_arquivo = VALUES(padrao_nome_arquivo),
    ativo = 1,
    atualizado_em = CURRENT_TIMESTAMP;

-- Mapeamento inicial do layout ACME. Campos sem destino canônico ficam preservados em payload_extra/payload_origem.
INSERT INTO carga_layout_campos (layout_id, campo_canonico_id, campo_destino, nome_origem, ordem, tipo_dado, obrigatorio, permitir_vazio, regra_normalizacao, descricao)
VALUES
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'DebtorProviderDescription', 1, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.DebtorProviderDescription -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'lote'), 'contratos.lote', 'ProviderName', 2, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.ProviderName -> contratos.lote'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'produto'), 'contratos.produto', 'ProductDescription', 3, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.ProductDescription -> contratos.produto'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'fila'), 'contratos.fila', 'Queuename', 4, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Queuename -> contratos.fila'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoas' AND campo = 'documento'), 'pessoas.documento', 'Number', 5, 'string', 1, 0, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Number -> pessoas.documento'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoas' AND campo = 'nome'), 'pessoas.nome', 'Name', 6, 'string', 1, 0, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Name -> pessoas.nome'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'LastActioningDate', 7, 'date', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.LastActioningDate -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'status_devedor'), 'contratos.status_devedor', 'DebtorStatus', 8, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.DebtorStatus -> contratos.status_devedor'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'conta_id'), 'contratos.conta_id', 'Accountid', 9, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Accountid -> contratos.conta_id'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'devedor_origem_id'), 'contratos.devedor_origem_id', 'DebtorId', 10, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.DebtorId -> contratos.devedor_origem_id'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'codigo_contrato'), 'contratos.codigo_contrato', 'AccountNumber', 11, 'string', 1, 0, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.AccountNumber -> contratos.codigo_contrato'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'status_contrato'), 'contratos.status_contrato', 'AccountStatus', 12, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.AccountStatus -> contratos.status_contrato'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'RecompraStatus', 13, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.RecompraStatus -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'data_corte'), 'contratos.data_corte', 'CutOfdate', 14, 'date', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.CutOfdate -> contratos.data_corte'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'dias_atraso'), 'contratos.dias_atraso', 'Age', 15, 'int', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Age -> contratos.dias_atraso'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'saldo_atual'), 'contratos.saldo_atual', 'CurrentBalance', 16, 'decimal', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.CurrentBalance -> contratos.saldo_atual'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'Description', 17, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.Description -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contratos' AND campo = 'valor_total_pago'), 'contratos.valor_total_pago', 'TotalCollected', 18, 'decimal', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.TotalCollected -> contratos.valor_total_pago'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_enderecos' AND campo = 'uf'), 'pessoa_enderecos.uf', 'RegiOn', 19, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.RegiOn -> pessoa_enderecos.uf'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_enderecos' AND campo = 'cidade'), 'pessoa_enderecos.cidade', 'City', 20, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.City -> pessoa_enderecos.cidade'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'IngressoPatio', 21, 'date', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.IngressoPatio -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contrato_veiculos' AND campo = 'descricao'), 'contrato_veiculos.descricao', 'DescricaoVeiculos', 22, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.DescricaoVeiculos -> contrato_veiculos.descricao'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contrato_veiculos' AND campo = 'marca'), 'contrato_veiculos.marca', 'Marca', 23, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Marca -> contrato_veiculos.marca'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contrato_veiculos' AND campo = 'modelo'), 'contrato_veiculos.modelo', 'Modelo', 24, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Modelo -> contrato_veiculos.modelo'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contrato_veiculos' AND campo = 'ano_fabricacao'), 'contrato_veiculos.ano_fabricacao', 'AnoFabricacao', 25, 'int', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.AnoFabricacao -> contrato_veiculos.ano_fabricacao'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contrato_veiculos' AND campo = 'ano_modelo'), 'contrato_veiculos.ano_modelo', 'AnoModelo', 26, 'int', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.AnoModelo -> contrato_veiculos.ano_modelo'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contrato_veiculos' AND campo = 'categoria'), 'contrato_veiculos.categoria', 'CategoriaVeiculo', 27, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.CategoriaVeiculo -> contrato_veiculos.categoria'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'AcordoPromessa', 28, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.AcordoPromessa -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'contrato_parcelas' AND campo = 'data_vencimento'), 'contrato_parcelas.data_vencimento', 'Vencimento', 29, 'date', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Vencimento -> contrato_parcelas.data_vencimento'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'DataFila', 30, 'date', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.DataFila -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'DiasFila', 31, 'int', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.DiasFila -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'telefone'), 'pessoa_contatos.telefone', 'CelularHot', 32, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.CelularHot -> pessoa_contatos.telefone'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'telefone'), 'pessoa_contatos.telefone', 'FixoHot', 33, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.FixoHot -> pessoa_contatos.telefone'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'Hot', 34, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.Hot -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'status'), 'pessoa_contatos.status', 'Incorreto', 35, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Incorreto -> pessoa_contatos.status'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'SMS', 36, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.SMS -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'status'), 'pessoa_contatos.status', 'Desconhecido', 37, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Desconhecido -> pessoa_contatos.status'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'status'), 'pessoa_contatos.status', 'Correto', 38, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.Correto -> pessoa_contatos.status'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'status'), 'pessoa_contatos.status', 'NãoConfirmado', 39, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.NãoConfirmado -> pessoa_contatos.status'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'DataAgendamento', 40, 'date', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.DataAgendamento -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'Mailing', 41, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.Mailing -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'IdadeDevedor', 42, 'int', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.IdadeDevedor -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'QuantidadeParcelasPagas', 43, 'int', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.QuantidadeParcelasPagas -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'email'), 'pessoa_contatos.email', 'EmailHot', 44, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.EmailHot -> pessoa_contatos.email'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoa_contatos' AND campo = 'status'), 'pessoa_contatos.status', 'EmailCorreto', 45, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.EmailCorreto -> pessoa_contatos.status'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'WarningDescription', 46, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.WarningDescription -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'PrecatoryType', 47, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.PrecatoryType -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoas' AND campo = 'tipo_documento'), 'pessoas.tipo_documento', 'PersonType', 48, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.PersonType -> pessoas.tipo_documento'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'BudgetOrder', 49, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.BudgetOrder -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'OpenSeem', 50, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.OpenSeem -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'OpenCalculation', 51, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.OpenCalculation -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'StartCollectionBaseDate', 52, 'date', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.StartCollectionBaseDate -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'Adv', 53, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.Adv -> payload_extra'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), (SELECT id FROM campos_canonicos WHERE entidade = 'pessoas' AND campo = 'nome'), 'pessoas.nome', 'AccountDebtorName', 54, 'string', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE'), 'ACME.AccountDebtorName -> pessoas.nome'),
    ((SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' AND versao = 1), NULL, NULL, 'Notas', 55, 'text', 0, 1, JSON_OBJECT('origem','ACME','layout','ACME_COBRANCA_PIPE','preservar_em_payload', true), 'ACME.Notas -> payload_extra')
ON DUPLICATE KEY UPDATE
    campo_canonico_id = VALUES(campo_canonico_id),
    campo_destino = VALUES(campo_destino),
    nome_origem = VALUES(nome_origem),
    tipo_dado = VALUES(tipo_dado),
    obrigatorio = VALUES(obrigatorio),
    permitir_vazio = VALUES(permitir_vazio),
    regra_normalizacao = VALUES(regra_normalizacao),
    descricao = VALUES(descricao),
    ativo = 1,
    atualizado_em = CURRENT_TIMESTAMP;
