-- Requisito (2026-10-06): copiar o banco de producao para a
-- homologacao toda semana (domingo) e ter um lugar na Administracao para
-- copiar na hora ou mudar o agendamento.
--
-- O CRM so registra a agenda e os pedidos. Quem copia e o
-- scripts/homologacao-agendador.sh, no host, a cada minuto (precisa do root
-- do MySQL e do docker, que o PHP nao tem): reserva o pedido via
-- console/homologacao_copia.php, roda "homologacao.sh banco --confirmar" e
-- devolve o resultado.
--
-- Fora do Debezium (table.include.list nao inclui estas tabelas).
SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS homologacao_agenda (
    id TINYINT UNSIGNED NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    dia_semana TINYINT UNSIGNED NOT NULL DEFAULT 7 COMMENT '1=segunda .. 7=domingo',
    horario TIME NOT NULL DEFAULT '05:00:00',
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_homologacao_agenda_unica CHECK (id = 1),
    CONSTRAINT chk_homologacao_agenda_dia CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT fk_homologacao_agenda_usuario
        FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Domingo 05:00: depois do backup (03:30) e da limpeza de staging (04:15),
-- e domingo nao tem carga da ACME.
INSERT IGNORE INTO homologacao_agenda (id, ativo, dia_semana, horario) VALUES (1, 1, 7, '05:00:00');

CREATE TABLE IF NOT EXISTS homologacao_copias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    origem ENUM('manual','agendada') NOT NULL,
    status ENUM('pendente','executando','concluida','erro','cancelada') NOT NULL DEFAULT 'pendente',
    solicitado_por_usuario_id BIGINT UNSIGNED NULL,
    solicitado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_em DATETIME NULL,
    finalizado_em DATETIME NULL,
    mensagem VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY idx_homologacao_copias_status (status, id),
    KEY idx_homologacao_copias_solicitado (solicitado_em),
    CONSTRAINT fk_homologacao_copias_usuario
        FOREIGN KEY (solicitado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
