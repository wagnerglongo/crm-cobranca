-- Patch 126 / Migration 083 - Estrategias operacionais e supervisao
-- Materializa conjuntos de contratos para a operacao sem alterar campanhas existentes.
-- Mantem o MySQL como fonte oficial e preserva o historico por exclusao logica.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS estrategias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(160) NOT NULL,
    descricao VARCHAR(700) NULL,
    status ENUM('rascunho','ativa','pausada','encerrada','arquivada') NOT NULL DEFAULT 'ativa',
    prioridade SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    visibilidade ENUM('todos','usuarios') NOT NULL DEFAULT 'todos',
    filtros_json JSON NULL,
    ordenacao_json JSON NULL,
    limite_contratos INT UNSIGNED NOT NULL DEFAULT 5000,
    total_contratos INT UNSIGNED NOT NULL DEFAULT 0,
    total_pendentes INT UNSIGNED NOT NULL DEFAULT 0,
    total_em_atendimento INT UNSIGNED NOT NULL DEFAULT 0,
    total_trabalhados INT UNSIGNED NOT NULL DEFAULT 0,
    total_removidos INT UNSIGNED NOT NULL DEFAULT 0,
    saldo_total DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    valor_pago_total DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    criado_por_usuario_id BIGINT UNSIGNED NULL,
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    gerada_em DATETIME NULL,
    ativada_em DATETIME NULL,
    pausada_em DATETIME NULL,
    encerrada_em DATETIME NULL,
    arquivada_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_estrategias_status_prioridade (status, prioridade, atualizado_em),
    KEY idx_estrategias_criador (criado_por_usuario_id, criado_em),
    KEY idx_estrategias_visibilidade (visibilidade, status),
    CONSTRAINT fk_estrategias_criador
        FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_estrategias_atualizador
        FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS estrategia_usuarios (
    estrategia_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (estrategia_id, usuario_id),
    KEY idx_estrategia_usuarios_usuario (usuario_id, estrategia_id),
    CONSTRAINT fk_estrategia_usuarios_estrategia
        FOREIGN KEY (estrategia_id) REFERENCES estrategias (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_estrategia_usuarios_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS estrategia_contratos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estrategia_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NOT NULL,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    status_operacional ENUM('pendente','em_atendimento','trabalhado','removido') NOT NULL DEFAULT 'pendente',
    ordem_prioridade INT UNSIGNED NOT NULL DEFAULT 0,
    reservado_por_usuario_id BIGINT UNSIGNED NULL,
    reserva_expira_em DATETIME NULL,
    atendimento_id BIGINT UNSIGNED NULL,
    saldo_snapshot DECIMAL(15,2) NULL,
    valor_original_snapshot DECIMAL(15,2) NULL,
    valor_pago_snapshot DECIMAL(15,2) NULL,
    valor_faltante_snapshot DECIMAL(15,2) NULL,
    aging_snapshot INT NULL,
    data_entrada_base_snapshot DATETIME NULL,
    data_vencimento_original_snapshot DATE NULL,
    ultima_tentativa_snapshot DATETIME NULL,
    quantidade_tentativas_snapshot INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_resultado_id_snapshot SMALLINT UNSIGNED NULL,
    payload_snapshot JSON NULL,
    incluido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aberto_em DATETIME NULL,
    ultimo_acesso_em DATETIME NULL,
    trabalhado_em DATETIME NULL,
    removido_em DATETIME NULL,
    motivo_remocao VARCHAR(500) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_estrategia_contrato (estrategia_id, contrato_id),
    KEY idx_estrategia_contratos_fila (estrategia_id, status_operacional, ordem_prioridade, id),
    KEY idx_estrategia_contratos_contrato (contrato_id, status_operacional),
    KEY idx_estrategia_contratos_pessoa (pessoa_id, status_operacional),
    KEY idx_estrategia_contratos_reserva (reservado_por_usuario_id, reserva_expira_em, status_operacional),
    KEY idx_estrategia_contratos_atendimento (atendimento_id),
    KEY idx_estrategia_contratos_trabalhado (estrategia_id, trabalhado_em),
    CONSTRAINT fk_estrategia_contratos_estrategia
        FOREIGN KEY (estrategia_id) REFERENCES estrategias (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_estrategia_contratos_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_estrategia_contratos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_estrategia_contratos_reservado_por
        FOREIGN KEY (reservado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_estrategia_contratos_atendimento
        FOREIGN KEY (atendimento_id) REFERENCES atendimentos_historico (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_estrategia_contratos_resultado
        FOREIGN KEY (ultimo_resultado_id_snapshot) REFERENCES atendimento_resultados (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS estrategia_execucoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estrategia_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('criacao','previsualizacao','regeneracao','ativacao','pausa','encerramento','arquivamento') NOT NULL,
    status ENUM('iniciada','concluida','erro') NOT NULL DEFAULT 'iniciada',
    total_encontrado INT UNSIGNED NOT NULL DEFAULT 0,
    total_materializado INT UNSIGNED NOT NULL DEFAULT 0,
    total_conflitos INT UNSIGNED NOT NULL DEFAULT 0,
    total_removido INT UNSIGNED NOT NULL DEFAULT 0,
    executado_por_usuario_id BIGINT UNSIGNED NULL,
    erro_detalhes TEXT NULL,
    payload_extra JSON NULL,
    iniciado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalizado_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_estrategia_execucoes_estrategia (estrategia_id, criado_em),
    KEY idx_estrategia_execucoes_status (status, iniciado_em),
    CONSTRAINT fk_estrategia_execucoes_estrategia
        FOREIGN KEY (estrategia_id) REFERENCES estrategias (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_estrategia_execucoes_usuario
        FOREIGN KEY (executado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS estrategia_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estrategia_id BIGINT UNSIGNED NOT NULL,
    estrategia_contrato_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NULL,
    tipo VARCHAR(80) NOT NULL,
    descricao VARCHAR(700) NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_estrategia_eventos_estrategia (estrategia_id, criado_em),
    KEY idx_estrategia_eventos_item (estrategia_contrato_id, criado_em),
    KEY idx_estrategia_eventos_usuario (usuario_id, criado_em),
    CONSTRAINT fk_estrategia_eventos_estrategia
        FOREIGN KEY (estrategia_id) REFERENCES estrategias (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_estrategia_eventos_item
        FOREIGN KEY (estrategia_contrato_id) REFERENCES estrategia_contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_estrategia_eventos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('estrategias.visualizar', 'Trabalhar contratos de estrategia', 'Estrategias', 'Acessar estrategias ativas e abrir contratos no Pactua conforme escopo.', 1),
('estrategias.gerenciar', 'Gerenciar estrategias', 'Estrategias', 'Criar, previsualizar, ativar, pausar, regenerar e arquivar estrategias.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('estrategias.visualizar','estrategias.gerenciar')
WHERE pa.codigo IN ('admin','supervisor','planejamento');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'estrategias.visualizar'
WHERE pa.codigo IN ('lider','operador','preventivo');
