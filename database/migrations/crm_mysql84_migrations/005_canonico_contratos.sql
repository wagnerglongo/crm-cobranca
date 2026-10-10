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
