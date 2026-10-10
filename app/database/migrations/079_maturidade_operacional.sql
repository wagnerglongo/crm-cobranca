-- Patch 079-084 - Maturidade operacional do Pactua
-- Registro de sincronizacoes externas de acordos.
-- Nao altera pagamentos nem quita/quebra acordos automaticamente com base em API externa.

CREATE TABLE IF NOT EXISTS acordos_operacionais_sincronizacoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    acordo_operacional_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    credor_id BIGINT UNSIGNED NULL,
    integracao VARCHAR(60) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'ok',
    mensagem VARCHAR(500) NULL,
    snapshot JSON NULL,
    usuario_id BIGINT UNSIGNED NULL,
    usuario_nome VARCHAR(150) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_acordo_sync_acordo (acordo_operacional_id, criado_em),
    KEY idx_acordo_sync_contrato (contrato_id, criado_em),
    KEY idx_acordo_sync_status (status, criado_em),
    KEY idx_acordo_sync_credor (credor_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
