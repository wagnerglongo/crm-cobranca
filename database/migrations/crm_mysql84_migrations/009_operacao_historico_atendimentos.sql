-- CRM Call Center - Migration 009
-- Histórico operacional inicial de atendimento
-- Escopo: registrar tentativas manuais dentro da ficha do cliente.
-- Não cria login, não cria acordo, não mexe no motor de cargas.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS atendimentos_historico (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    contato_id BIGINT UNSIGNED NULL,
    operador_nome VARCHAR(120) NULL,
    canal ENUM('ligacao','whatsapp','sms','email','outro') NOT NULL DEFAULT 'ligacao',
    resultado ENUM(
        'sem_contato',
        'nao_atende',
        'atendeu',
        'ocupado',
        'caixa_postal',
        'numero_invalido',
        'recado',
        'promessa_pagamento',
        'em_negociacao',
        'outro'
    ) NOT NULL,
    contato_utilizado VARCHAR(255) NULL,
    observacao TEXT NULL,
    proximo_acionamento DATETIME NULL,
    origem ENUM('manual','sistema','importacao') NOT NULL DEFAULT 'manual',
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_atend_hist_pessoa_criado (pessoa_id, criado_em),
    KEY idx_atend_hist_contrato (contrato_id),
    KEY idx_atend_hist_contato (contato_id),
    KEY idx_atend_hist_resultado (resultado),
    KEY idx_atend_hist_proximo (proximo_acionamento),
    CONSTRAINT fk_atend_hist_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_atend_hist_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_atend_hist_contato
        FOREIGN KEY (contato_id) REFERENCES pessoa_contatos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
