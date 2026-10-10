-- CRM Call Center - Migration 033-037
-- Historico operacional completo, campanhas avancadas, supervisao avancada, cargas 2.0 e seguranca operacional inicial.
-- Nao altera importadores de novos credores nem infraestrutura.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS seguranca_configuracoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(80) NOT NULL,
    valor VARCHAR(255) NOT NULL,
    descricao TEXT NULL,
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_seguranca_config_codigo (codigo),
    KEY idx_seguranca_config_usuario (atualizado_por_usuario_id),
    CONSTRAINT fk_seguranca_config_usuario
        FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO seguranca_configuracoes (codigo, valor, descricao) VALUES
('max_tentativas_login', '5', 'Quantidade de tentativas invalidas antes de bloqueio temporario.'),
('minutos_bloqueio_login', '15', 'Tempo de bloqueio apos excesso de tentativas invalidas.'),
('min_tamanho_senha', '8', 'Tamanho minimo recomendado para novas senhas.'),
('avisar_admin_padrao', '1', 'Exibe alerta quando o usuario admin inicial ainda existe com login padrao.'),
('forcar_csrf_forms', '1', 'Mantem validacao CSRF ativa em formularios sensiveis da operacao.'),
('sessao_expira_minutos', '240', 'Referencia operacional para expiracao futura de sessao.')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

SET @col_tentativas_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'tentativas_login_falha'
);
SET @sql_add_tentativas = IF(@col_tentativas_exists = 0,
    'ALTER TABLE usuarios ADD COLUMN tentativas_login_falha INT UNSIGNED NOT NULL DEFAULT 0 AFTER ultimo_login_em',
    'SELECT 1'
);
PREPARE stmt_add_tentativas FROM @sql_add_tentativas;
EXECUTE stmt_add_tentativas;
DEALLOCATE PREPARE stmt_add_tentativas;

SET @col_bloqueado_ate_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'bloqueado_ate'
);
SET @sql_add_bloqueado = IF(@col_bloqueado_ate_exists = 0,
    'ALTER TABLE usuarios ADD COLUMN bloqueado_ate DATETIME NULL AFTER tentativas_login_falha',
    'SELECT 1'
);
PREPARE stmt_add_bloqueado FROM @sql_add_bloqueado;
EXECUTE stmt_add_bloqueado;
DEALLOCATE PREPARE stmt_add_bloqueado;

SET @col_senha_alt_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha_alterada_em'
);
SET @sql_add_senha_alt = IF(@col_senha_alt_exists = 0,
    'ALTER TABLE usuarios ADD COLUMN senha_alterada_em DATETIME NULL AFTER bloqueado_ate',
    'SELECT 1'
);
PREPARE stmt_add_senha_alt FROM @sql_add_senha_alt;
EXECUTE stmt_add_senha_alt;
DEALLOCATE PREPARE stmt_add_senha_alt;

SET @idx_login_bloqueio_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_usuarios_bloqueio'
);
SET @sql_idx_login_bloqueio = IF(@idx_login_bloqueio_exists = 0,
    'ALTER TABLE usuarios ADD KEY idx_usuarios_bloqueio (status, bloqueado_ate)',
    'SELECT 1'
);
PREPARE stmt_idx_login_bloqueio FROM @sql_idx_login_bloqueio;
EXECUTE stmt_idx_login_bloqueio;
DEALLOCATE PREPARE stmt_idx_login_bloqueio;

CREATE TABLE IF NOT EXISTS operacao_cliente_historico_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pessoa_id BIGINT UNSIGNED NOT NULL,
    contrato_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NULL,
    tipo VARCHAR(60) NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    descricao TEXT NULL,
    payload_extra JSON NULL,
    ocorrido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cliente_hist_pessoa_data (pessoa_id, ocorrido_em),
    KEY idx_cliente_hist_contrato (contrato_id, ocorrido_em),
    KEY idx_cliente_hist_usuario (usuario_id, ocorrido_em),
    KEY idx_cliente_hist_tipo (tipo, ocorrido_em),
    CONSTRAINT fk_cliente_hist_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cliente_hist_contrato FOREIGN KEY (contrato_id) REFERENCES contratos (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cliente_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('historico.visualizar', 'Visualizar historico completo do cliente', 'Clientes', 'Acessar timeline operacional consolidada de cliente, fila, campanha, retorno e acordo.', 1),
('campanhas.previsualizar', 'Previsualizar campanhas', 'Campanhas', 'Ver previa dos clientes antes de gerar fila da campanha.', 1),
('supervisao.acoes_massa', 'Acoes em massa da supervisao', 'Campanhas', 'Liberar/transferir reservas em massa pela supervisao.', 1),
('seguranca.configurar', 'Configurar seguranca operacional', 'Seguranca', 'Editar configuracoes iniciais de seguranca e bloqueio de login.', 1),
('seguranca.visualizar', 'Visualizar seguranca operacional', 'Seguranca', 'Visualizar painel inicial de seguranca operacional.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'historico.visualizar','campanhas.previsualizar','supervisao.acoes_massa',
    'seguranca.configurar','seguranca.visualizar'
)
WHERE pa.codigo IN ('admin','supervisor');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('historico.visualizar','campanhas.previsualizar','seguranca.visualizar')
WHERE pa.codigo IN ('lider');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN ('historico.visualizar')
WHERE pa.codigo IN ('operador','preventivo');
