-- Patch 075 - Carteira e ciclo de vida dos acordos
-- Cria acompanhamento persistente e movimentos manuais reversíveis.
-- Não apaga nem substitui acordos/parcelas existentes.

CREATE TABLE IF NOT EXISTS acordos_operacionais_acompanhamento (
    acordo_operacional_id BIGINT UNSIGNED NOT NULL,
    situacao_carteira VARCHAR(40) NOT NULL DEFAULT 'aguardando_primeira',
    parcela_atual_id BIGINT UNSIGNED NULL,
    proximo_vencimento DATE NULL,
    fim_carencia DATE NULL,
    total_parcelas INT UNSIGNED NOT NULL DEFAULT 0,
    parcelas_pagas INT UNSIGNED NOT NULL DEFAULT 0,
    valor_previsto DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    valor_pago DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    proxima_acao VARCHAR(255) NULL,
    ultima_verificacao_em DATETIME NULL,
    proxima_verificacao_em DATETIME NULL,
    ultima_origem VARCHAR(30) NOT NULL DEFAULT 'sistema',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (acordo_operacional_id),
    KEY idx_acompanhamento_situacao (situacao_carteira, proximo_vencimento),
    KEY idx_acompanhamento_proxima_verificacao (proxima_verificacao_em),
    KEY idx_acompanhamento_parcela_atual (parcela_atual_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acordos_operacionais_movimentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    acordo_operacional_id BIGINT UNSIGNED NOT NULL,
    parcela_id BIGINT UNSIGNED NULL,
    acao VARCHAR(80) NOT NULL,
    origem VARCHAR(30) NOT NULL DEFAULT 'manual',
    usuario_id BIGINT UNSIGNED NULL,
    usuario_nome VARCHAR(150) NULL,
    dados_antes JSON NULL,
    dados_depois JSON NULL,
    reversivel TINYINT(1) NOT NULL DEFAULT 0,
    desfeito_em DATETIME NULL,
    desfeito_por_usuario_id BIGINT UNSIGNED NULL,
    desfeito_por_nome VARCHAR(150) NULL,
    observacao TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_movimentos_acordo (acordo_operacional_id, criado_em),
    KEY idx_movimentos_parcela (parcela_id, criado_em),
    KEY idx_movimentos_reversivel (reversivel, desfeito_em),
    KEY idx_movimentos_usuario (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
