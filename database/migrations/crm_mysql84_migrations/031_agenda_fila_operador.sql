-- CRM Call Center - Migration 031
-- Agenda/fila individual por operador, reserva e transferencia de cliente.
-- Escopo: cria camada operacional sobre retornos, fila manual e reservas sem mexer em cargas/credores.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS operacao_fila_regras (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(80) NOT NULL,
    valor VARCHAR(255) NOT NULL,
    descricao TEXT NULL,
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_operacao_fila_regras_codigo (codigo),
    KEY idx_operacao_fila_regras_usuario (atualizado_por_usuario_id),
    CONSTRAINT fk_operacao_fila_regras_usuario
        FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS operacao_cliente_reservas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    status ENUM('ativa','liberada','expirada','cancelada','transferida') NOT NULL DEFAULT 'ativa',
    origem ENUM('manual','transferencia','fila_geral','campanha','retorno','atendimento') NOT NULL DEFAULT 'manual',
    campanha_item_id BIGINT UNSIGNED NULL,
    retorno_origem_id BIGINT UNSIGNED NULL,
    observacao TEXT NULL,
    reservado_por_usuario_id BIGINT UNSIGNED NULL,
    reservado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NULL,
    liberado_em DATETIME NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reservas_pessoa_status (pessoa_id, status, expira_em),
    KEY idx_reservas_usuario_status (usuario_id, status, expira_em),
    KEY idx_reservas_contrato (contrato_id),
    KEY idx_reservas_campanha_item (campanha_item_id),
    KEY idx_reservas_retorno (retorno_origem_id),
    KEY idx_reservas_reservado_por (reservado_por_usuario_id),
    CONSTRAINT fk_reservas_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_reservas_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_reservas_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_reservas_reservado_por
        FOREIGN KEY (reservado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_reservas_campanha_item
        FOREIGN KEY (campanha_item_id) REFERENCES campanha_itens (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_reservas_retorno
        FOREIGN KEY (retorno_origem_id) REFERENCES atendimentos_historico (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO operacao_fila_regras (codigo, valor, descricao) VALUES
('retorno_vencido_vai_geral', '1', 'Quando um retorno agendado vencer sem tratamento, ele aparece tambem na fila geral.'),
('horas_reserva_padrao', '24', 'Tempo padrao de reserva quando um operador puxa um cliente da fila geral.'),
('horas_reserva_manual', '8', 'Tempo padrao de reserva manual/transferencia feita pela tela de agenda.'),
('permitir_operador_puxar_geral', '1', 'Permite que usuario com fila.trabalhar reserve um cliente disponivel da fila geral para si.')
ON DUPLICATE KEY UPDATE
    descricao = VALUES(descricao);

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('fila.configurar', 'Configurar regras de fila', 'Campanhas', 'Editar regras da agenda/fila individual e comportamento da fila geral.', 1),
('fila.transferir', 'Transferir/reservar cliente', 'Campanhas', 'Transferir ou reservar cliente para operador especifico.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('fila.configurar','fila.transferir')
WHERE pa.codigo IN ('admin','supervisor');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('fila.transferir')
WHERE pa.codigo IN ('lider');
