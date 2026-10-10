-- CRM Call Center - Migration 014
-- Renomeia o conceito operacional de promessa para acordo operacional e prepara pontos de integração futura com APIs de credores.
-- Executar depois da migration 013, caso já existam registros em promessas_pagamento.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS acordos_operacionais (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    credor_id BIGINT UNSIGNED NULL,
    atendimento_id BIGINT UNSIGNED NULL,
    campanha_item_id BIGINT UNSIGNED NULL,
    retorno_origem_id BIGINT UNSIGNED NULL,
    valor_acordo DECIMAL(15,2) NOT NULL,
    data_promessa_pagamento DATE NOT NULL,
    status_operacional ENUM('pendente','cumprido','quebrado','cancelado','formalizado') NOT NULL DEFAULT 'pendente',
    status_integracao ENUM('nao_enviado','pendente_envio','enviado','confirmado_credor','recusado_credor','erro') NOT NULL DEFAULT 'nao_enviado',
    origem ENUM('manual','discador','fila_manual','retorno') NOT NULL DEFAULT 'manual',
    operador_nome VARCHAR(120) NULL,
    observacao TEXT NULL,
    status_atualizado_em DATETIME NULL,
    codigo_acordo_credor VARCHAR(120) NULL,
    protocolo_credor VARCHAR(120) NULL,
    enviado_credor_em DATETIME NULL,
    confirmado_credor_em DATETIME NULL,
    erro_integracao TEXT NULL,
    payload_integracao JSON NULL,
    payload_extra JSON NULL,
    origem_legacy_tabela VARCHAR(64) NULL,
    origem_legacy_id BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_acordos_legacy (origem_legacy_tabela, origem_legacy_id),
    KEY idx_acordos_pessoa_status (pessoa_id, status_operacional, data_promessa_pagamento),
    KEY idx_acordos_contrato (contrato_id),
    KEY idx_acordos_credor_integracao (credor_id, status_integracao),
    KEY idx_acordos_atendimento (atendimento_id),
    KEY idx_acordos_campanha_item (campanha_item_id),
    KEY idx_acordos_retorno_origem (retorno_origem_id),
    KEY idx_acordos_status_data (status_operacional, data_promessa_pagamento),
    KEY idx_acordos_integracao_data (status_integracao, atualizado_em),
    KEY idx_acordos_criado_em (criado_em),
    CONSTRAINT fk_acordos_op_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_acordos_op_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordos_op_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordos_op_atendimento
        FOREIGN KEY (atendimento_id) REFERENCES atendimentos_historico (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordos_op_campanha_item
        FOREIGN KEY (campanha_item_id) REFERENCES campanha_itens (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_acordos_op_retorno_origem
        FOREIGN KEY (retorno_origem_id) REFERENCES atendimentos_historico (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS migrar_promessas_pagamento_para_acordos_operacionais;
DELIMITER //
CREATE PROCEDURE migrar_promessas_pagamento_para_acordos_operacionais()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'promessas_pagamento'
    ) THEN
        INSERT INTO acordos_operacionais (
            pessoa_id,
            contrato_id,
            credor_id,
            atendimento_id,
            campanha_item_id,
            retorno_origem_id,
            valor_acordo,
            data_promessa_pagamento,
            status_operacional,
            status_integracao,
            origem,
            operador_nome,
            observacao,
            status_atualizado_em,
            payload_extra,
            origem_legacy_tabela,
            origem_legacy_id,
            criado_em,
            atualizado_em
        )
        SELECT
            pp.pessoa_id,
            pp.contrato_id,
            c.credor_id,
            pp.atendimento_id,
            pp.campanha_item_id,
            pp.retorno_origem_id,
            pp.valor_promessa,
            pp.data_promessa,
            CASE pp.status
                WHEN 'cumprida' THEN 'cumprido'
                WHEN 'quebrada' THEN 'quebrado'
                WHEN 'convertida_acordo' THEN 'formalizado'
                ELSE pp.status
            END AS status_operacional,
            'nao_enviado' AS status_integracao,
            pp.origem,
            pp.operador_nome,
            pp.observacao,
            pp.status_atualizado_em,
            JSON_OBJECT(
                'migrado_de', 'promessas_pagamento',
                'promessa_id', pp.id,
                'status_anterior', pp.status,
                'observacao_arquitetural', 'Registro migrado para acordos_operacionais; promessa operacional passa a ser acordo operacional.'
            ) AS payload_extra,
            'promessas_pagamento' AS origem_legacy_tabela,
            pp.id AS origem_legacy_id,
            pp.criado_em,
            pp.atualizado_em
        FROM promessas_pagamento pp
        LEFT JOIN contratos c ON c.id = pp.contrato_id
        WHERE NOT EXISTS (
            SELECT 1
            FROM acordos_operacionais ao
            WHERE ao.origem_legacy_tabela = 'promessas_pagamento'
              AND ao.origem_legacy_id = pp.id
        );

    END IF;
END //
DELIMITER ;
CALL migrar_promessas_pagamento_para_acordos_operacionais();
DROP PROCEDURE IF EXISTS migrar_promessas_pagamento_para_acordos_operacionais;

CREATE TABLE IF NOT EXISTS acordo_operacional_integracoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    acordo_operacional_id BIGINT UNSIGNED NOT NULL,
    credor_id BIGINT UNSIGNED NULL,
    provedor VARCHAR(80) NULL,
    acao ENUM('criar_acordo','consultar_status','cancelar_acordo') NOT NULL DEFAULT 'criar_acordo',
    status ENUM('pendente','processando','sucesso','erro','cancelado') NOT NULL DEFAULT 'pendente',
    tentativas INT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NULL,
    enviado_em DATETIME NULL,
    finalizado_em DATETIME NULL,
    request_payload JSON NULL,
    response_payload JSON NULL,
    erro_mensagem TEXT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_acordo_integracao_acordo (acordo_operacional_id),
    KEY idx_acordo_integracao_credor_status (credor_id, status),
    KEY idx_acordo_integracao_status_proxima (status, proxima_tentativa_em),
    CONSTRAINT fk_acordo_integracao_acordo
        FOREIGN KEY (acordo_operacional_id) REFERENCES acordos_operacionais (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_acordo_integracao_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
