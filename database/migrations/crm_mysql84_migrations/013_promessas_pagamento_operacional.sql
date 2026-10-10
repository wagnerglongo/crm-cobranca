-- CRM Call Center - Migration 013
-- Promessas operacionais de pagamento.
-- Escopo: registrar promessa ligada a cliente/contrato/atendimento sem alterar saldo, boleto, pagamento ou acordo financeiro definitivo.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS promessas_pagamento (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    atendimento_id BIGINT UNSIGNED NULL,
    campanha_item_id BIGINT UNSIGNED NULL,
    retorno_origem_id BIGINT UNSIGNED NULL,
    valor_promessa DECIMAL(15,2) NOT NULL,
    data_promessa DATE NOT NULL,
    status ENUM('pendente','cumprida','quebrada','cancelada','convertida_acordo') NOT NULL DEFAULT 'pendente',
    origem ENUM('manual','discador','fila_manual','retorno') NOT NULL DEFAULT 'manual',
    operador_nome VARCHAR(120) NULL,
    observacao TEXT NULL,
    status_atualizado_em DATETIME NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_promessas_pessoa_status (pessoa_id, status, data_promessa),
    KEY idx_promessas_contrato (contrato_id),
    KEY idx_promessas_atendimento (atendimento_id),
    KEY idx_promessas_campanha_item (campanha_item_id),
    KEY idx_promessas_retorno_origem (retorno_origem_id),
    KEY idx_promessas_status_data (status, data_promessa),
    KEY idx_promessas_criado_em (criado_em),
    CONSTRAINT fk_promessas_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_promessas_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_promessas_atendimento
        FOREIGN KEY (atendimento_id) REFERENCES atendimentos_historico (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_promessas_campanha_item
        FOREIGN KEY (campanha_item_id) REFERENCES campanha_itens (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_promessas_retorno_origem
        FOREIGN KEY (retorno_origem_id) REFERENCES atendimentos_historico (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
