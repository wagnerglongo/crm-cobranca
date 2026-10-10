-- CRM Call Center - Migration 032
-- Regras avancadas de agenda/fila, supervisao, campanhas, relatorios e gestao de cargas.
-- Nao adiciona novos credores nem altera infraestrutura.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS operacao_fila_movimentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    reserva_id BIGINT UNSIGNED NULL,
    usuario_origem_id BIGINT UNSIGNED NULL,
    usuario_destino_id BIGINT UNSIGNED NULL,
    tipo VARCHAR(60) NOT NULL,
    descricao VARCHAR(500) NULL,
    payload_extra JSON NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fila_mov_pessoa_data (pessoa_id, criado_em),
    KEY idx_fila_mov_contrato (contrato_id),
    KEY idx_fila_mov_reserva (reserva_id),
    KEY idx_fila_mov_origem (usuario_origem_id, criado_em),
    KEY idx_fila_mov_destino (usuario_destino_id, criado_em),
    KEY idx_fila_mov_tipo (tipo, criado_em),
    CONSTRAINT fk_fila_mov_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_fila_mov_contrato FOREIGN KEY (contrato_id) REFERENCES contratos (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fila_mov_reserva FOREIGN KEY (reserva_id) REFERENCES operacao_cliente_reservas (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fila_mov_usuario_origem FOREIGN KEY (usuario_origem_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_fila_mov_usuario_destino FOREIGN KEY (usuario_destino_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO operacao_fila_regras (codigo, valor, descricao) VALUES
('permitir_operador_liberar_reserva', '1', 'Permite que operadores liberem reservas proprias antes do vencimento.'),
('horas_reserva_admin', '72', 'Prazo de reserva para perfil admin.'),
('horas_reserva_supervisor', '48', 'Prazo de reserva para perfil supervisor.'),
('horas_reserva_lider', '36', 'Prazo de reserva para perfil lider.'),
('horas_reserva_operador', '24', 'Prazo de reserva para perfil operador.'),
('supervisao_ver_todos', '1', 'Permite visao ampla da fila para perfis com permissao de supervisao.')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('fila.supervisionar', 'Supervisionar fila/equipe', 'Campanhas', 'Ver painel de supervisao com equipe, reservas, retornos vencidos e movimentacoes de fila.', 1),
('campanhas.distribuir', 'Distribuir campanha', 'Campanhas', 'Distribuir itens de campanha para operador especifico e reservar clientes automaticamente.', 1),
('relatorios.exportar', 'Exportar relatorios', 'Relatorios', 'Exportar relatorios operacionais em CSV.', 1),
('cargas.visualizar', 'Visualizar cargas', 'Cargas', 'Acessar tela de acompanhamento de cargas, status e erros.', 1),
('cargas.reprocessar', 'Preparar reprocessamento de cargas', 'Cargas', 'Marcar carga para reprocessamento e registrar auditoria da acao.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('fila.supervisionar','campanhas.distribuir','relatorios.exportar','cargas.visualizar','cargas.reprocessar')
WHERE pa.codigo IN ('admin','supervisor');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('fila.supervisionar','campanhas.distribuir','relatorios.exportar','cargas.visualizar')
WHERE pa.codigo IN ('lider');
