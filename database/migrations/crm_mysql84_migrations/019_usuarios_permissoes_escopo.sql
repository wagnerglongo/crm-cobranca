-- CRM Call Center - Migration 019
-- Usuários, perfis, permissões e escopo por credor/carteira.
-- Executar depois da migration 018_auditoria_preventiva_acordos.sql.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS perfis_acesso (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(60) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    nivel INT NOT NULL DEFAULT 0,
    descricao TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_perfis_acesso_codigo (codigo),
    KEY idx_perfis_acesso_ativo (ativo, nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS permissoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(100) NOT NULL,
    nome VARCHAR(160) NOT NULL,
    grupo VARCHAR(80) NOT NULL,
    descricao TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_permissoes_codigo (codigo),
    KEY idx_permissoes_grupo (grupo, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS perfil_permissoes (
    perfil_id BIGINT UNSIGNED NOT NULL,
    permissao_id BIGINT UNSIGNED NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (perfil_id, permissao_id),
    KEY idx_perfil_permissoes_perm (permissao_id),
    CONSTRAINT fk_perfil_permissoes_perfil
        FOREIGN KEY (perfil_id) REFERENCES perfis_acesso (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_perfil_permissoes_permissao
        FOREIGN KEY (permissao_id) REFERENCES permissoes (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(160) NOT NULL,
    login VARCHAR(80) NOT NULL,
    email VARCHAR(160) NULL,
    senha_hash VARCHAR(255) NOT NULL,
    perfil_id BIGINT UNSIGNED NULL,
    status ENUM('ativo','inativo','bloqueado') NOT NULL DEFAULT 'ativo',
    admin_total TINYINT(1) NOT NULL DEFAULT 0,
    escopo_todos_credores TINYINT(1) NOT NULL DEFAULT 0,
    forcar_troca_senha TINYINT(1) NOT NULL DEFAULT 0,
    ultimo_login_em DATETIME NULL,
    criado_por_usuario_id BIGINT UNSIGNED NULL,
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_login (login),
    UNIQUE KEY uk_usuarios_email (email),
    KEY idx_usuarios_status_perfil (status, perfil_id),
    KEY idx_usuarios_perfil (perfil_id),
    CONSTRAINT fk_usuarios_perfil
        FOREIGN KEY (perfil_id) REFERENCES perfis_acesso (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS usuario_permissoes (
    usuario_id BIGINT UNSIGNED NOT NULL,
    permissao_id BIGINT UNSIGNED NOT NULL,
    permitido TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, permissao_id),
    KEY idx_usuario_permissoes_perm (permissao_id),
    CONSTRAINT fk_usuario_permissoes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_usuario_permissoes_permissao
        FOREIGN KEY (permissao_id) REFERENCES permissoes (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS usuario_credores (
    usuario_id BIGINT UNSIGNED NOT NULL,
    credor_id BIGINT UNSIGNED NOT NULL,
    pode_consultar TINYINT(1) NOT NULL DEFAULT 1,
    pode_operar TINYINT(1) NOT NULL DEFAULT 0,
    pode_fechar_acordo TINYINT(1) NOT NULL DEFAULT 0,
    pode_criar_campanha TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, credor_id),
    KEY idx_usuario_credores_credor (credor_id),
    CONSTRAINT fk_usuario_credores_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_usuario_credores_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS usuario_carteiras (
    usuario_id BIGINT UNSIGNED NOT NULL,
    carteira_id BIGINT UNSIGNED NOT NULL,
    pode_consultar TINYINT(1) NOT NULL DEFAULT 1,
    pode_operar TINYINT(1) NOT NULL DEFAULT 0,
    pode_fechar_acordo TINYINT(1) NOT NULL DEFAULT 0,
    pode_criar_campanha TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, carteira_id),
    KEY idx_usuario_carteiras_carteira (carteira_id),
    CONSTRAINT fk_usuario_carteiras_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_usuario_carteiras_carteira
        FOREIGN KEY (carteira_id) REFERENCES carteiras (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS usuario_auditoria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NULL,
    ator_usuario_id BIGINT UNSIGNED NULL,
    evento VARCHAR(80) NOT NULL,
    descricao TEXT NULL,
    payload_extra JSON NULL,
    ip_origem VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_usuario_auditoria_usuario_data (usuario_id, criado_em),
    KEY idx_usuario_auditoria_ator_data (ator_usuario_id, criado_em),
    KEY idx_usuario_auditoria_evento_data (evento, criado_em),
    CONSTRAINT fk_usuario_auditoria_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_usuario_auditoria_ator
        FOREIGN KEY (ator_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO perfis_acesso (codigo, nome, nivel, descricao, ativo) VALUES
('admin', 'Administrador', 100, 'Acesso total. Pode cadastrar usuários, perfis, permissões e operar todos os módulos.', 1),
('supervisor', 'Supervisor', 80, 'Gestão operacional, campanhas, acordos, preventiva e tabulações.', 1),
('lider', 'Líder', 60, 'Acompanha equipe, opera fila, acordos e preventiva conforme escopo de credores.', 1),
('preventivo', 'Preventivo', 45, 'Foco em preventiva de acordos, parcelas e retornos.', 1),
('operador', 'Operador', 20, 'Consulta cliente, registra atendimento e trabalha fila conforme escopo.', 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), nivel = VALUES(nivel), descricao = VALUES(descricao), ativo = VALUES(ativo);

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('dashboard.visualizar', 'Visualizar dashboard', 'Dashboard', 'Acessar o início da operação.', 1),
('clientes.consultar', 'Consultar clientes', 'Clientes', 'Pesquisar e abrir ficha do cliente conforme escopo.', 1),
('atendimentos.visualizar', 'Visualizar atendimentos', 'Atendimento', 'Acessar histórico e lista de atendimentos.', 1),
('atendimentos.registrar', 'Registrar acionamento', 'Atendimento', 'Criar atendimento/evento na ficha do cliente.', 1),
('retornos.gerenciar', 'Gerenciar retornos', 'Retornos', 'Visualizar, tratar e cancelar retornos.', 1),
('campanhas.gerenciar', 'Criar campanhas e filas', 'Campanhas', 'Criar campanhas, gerar filas e regerar listas.', 1),
('fila.trabalhar', 'Trabalhar fila manual', 'Campanhas', 'Abrir e trabalhar itens da fila manual.', 1),
('acordos.visualizar', 'Visualizar acordos', 'Acordos', 'Listar e abrir acordos operacionais.', 1),
('acordos.criar', 'Fechar acordo', 'Acordos', 'Registrar novo acordo operacional na ficha.', 1),
('acordos.status', 'Alterar status de acordo', 'Acordos', 'Alterar status operacional do acordo.', 1),
('acordos.parcelas', 'Controlar parcelas', 'Acordos', 'Marcar parcelas como pagas, vencidas, canceladas ou renegociadas.', 1),
('acordos.integracao', 'Preparar envio ao credor', 'Acordos', 'Marcar acordo para futura integração/API do credor.', 1),
('preventiva.visualizar', 'Visualizar preventiva', 'Preventiva', 'Acompanhar entradas, parcelas, vencidas e pagas.', 1),
('tabulacoes.configurar', 'Configurar tabulações', 'Configurações', 'Criar/editar resultados de atendimento.', 1),
('usuarios.gerenciar', 'Gerenciar usuários e permissões', 'Configurações', 'Cadastrar usuários, perfis, permissões e escopo por credor.', 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao), ativo = VALUES(ativo);

-- Permissões por perfil.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
CROSS JOIN permissoes pe
WHERE pa.codigo = 'admin';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','atendimentos.registrar',
    'retornos.gerenciar','campanhas.gerenciar','fila.trabalhar','acordos.visualizar','acordos.criar',
    'acordos.status','acordos.parcelas','acordos.integracao','preventiva.visualizar','tabulacoes.configurar'
)
WHERE pa.codigo = 'supervisor';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','atendimentos.registrar',
    'retornos.gerenciar','fila.trabalhar','acordos.visualizar','acordos.criar','acordos.parcelas','preventiva.visualizar'
)
WHERE pa.codigo = 'lider';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','retornos.gerenciar',
    'acordos.visualizar','acordos.parcelas','preventiva.visualizar'
)
WHERE pa.codigo = 'preventivo';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo IN (
    'dashboard.visualizar','clientes.consultar','atendimentos.visualizar','atendimentos.registrar','fila.trabalhar','acordos.visualizar','acordos.criar'
)
WHERE pa.codigo = 'operador';

-- Admin inicial. Login: admin | senha: admin123 (troca obrigatória no primeiro acesso)
INSERT INTO usuarios (
    nome, login, email, senha_hash, perfil_id, status, admin_total, escopo_todos_credores, forcar_troca_senha
)
SELECT
    'Administrador',
    'admin',
    'admin@local.crm',
    '$2y$12$WtTMJ8EsevpV6kZrD1A0YuMYGdhRdjty7tW/LQ4giixwZvDs7WCA6',
    pa.id,
    'ativo',
    1,
    1,
    1
FROM perfis_acesso pa
WHERE pa.codigo = 'admin'
ON DUPLICATE KEY UPDATE
    perfil_id = VALUES(perfil_id),
    status = 'ativo',
    admin_total = 1,
    escopo_todos_credores = 1;

-- Colunas de autoria para ações futuras e auditoria operacional.
SET @col_atend_usuario_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atendimentos_historico' AND COLUMN_NAME = 'usuario_id'
);
SET @sql_col_atend_usuario = IF(@col_atend_usuario_exists = 0,
    'ALTER TABLE atendimentos_historico ADD COLUMN usuario_id BIGINT UNSIGNED NULL AFTER pessoa_id, ADD KEY idx_atendimentos_usuario_data (usuario_id, criado_em), ADD CONSTRAINT fk_atendimentos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_col_atend_usuario FROM @sql_col_atend_usuario;
EXECUTE stmt_col_atend_usuario;
DEALLOCATE PREPARE stmt_col_atend_usuario;

SET @col_acordo_criado_por_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acordos_operacionais' AND COLUMN_NAME = 'criado_por_usuario_id'
);
SET @sql_col_acordo_criado_por = IF(@col_acordo_criado_por_exists = 0,
    'ALTER TABLE acordos_operacionais ADD COLUMN criado_por_usuario_id BIGINT UNSIGNED NULL AFTER pessoa_id, ADD COLUMN atualizado_por_usuario_id BIGINT UNSIGNED NULL AFTER criado_por_usuario_id, ADD KEY idx_acordos_op_criado_por (criado_por_usuario_id), ADD CONSTRAINT fk_acordos_op_criado_por FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL, ADD CONSTRAINT fk_acordos_op_atualizado_por FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_col_acordo_criado_por FROM @sql_col_acordo_criado_por;
EXECUTE stmt_col_acordo_criado_por;
DEALLOCATE PREPARE stmt_col_acordo_criado_por;

SET @col_campanha_criado_por_usuario_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campanhas' AND COLUMN_NAME = 'criado_por_usuario_id'
);
SET @sql_col_campanha_usuario = IF(@col_campanha_criado_por_usuario_exists = 0,
    'ALTER TABLE campanhas ADD COLUMN criado_por_usuario_id BIGINT UNSIGNED NULL AFTER criado_por, ADD COLUMN atualizado_por_usuario_id BIGINT UNSIGNED NULL AFTER criado_por_usuario_id, ADD KEY idx_campanhas_criado_por_usuario (criado_por_usuario_id), ADD CONSTRAINT fk_campanhas_criado_por_usuario FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL, ADD CONSTRAINT fk_campanhas_atualizado_por_usuario FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_col_campanha_usuario FROM @sql_col_campanha_usuario;
EXECUTE stmt_col_campanha_usuario;
DEALLOCATE PREPARE stmt_col_campanha_usuario;
