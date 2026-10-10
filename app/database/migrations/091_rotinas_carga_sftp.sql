-- Patch 130 / Migration 091 - Rotinas de coleta automatica de carga via SFTP
-- Cadastro de origens SFTP por credor (host, usuario, senha, pasta,
-- padrao de arquivo e agendamento) para automatizar o que hoje e' feito
-- manualmente. Usuario e senha ficam criptografados (nao e' o mesmo caso
-- das credenciais de API, que continuam exclusivamente no .env.local -
-- aqui a fonte e' um servidor de arquivos, nao a API do credor).
SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS rotinas_carga_sftp (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(160) NOT NULL,
    descricao VARCHAR(500) NULL,
    protocolo VARCHAR(20) NOT NULL DEFAULT 'sftp',
    origem_host VARCHAR(255) NOT NULL,
    origem_porta SMALLINT UNSIGNED NOT NULL DEFAULT 22,
    origem_caminho VARCHAR(500) NOT NULL DEFAULT '/',
    usuario VARCHAR(150) NOT NULL,
    senha_criptografada TEXT NOT NULL,
    padrao_arquivo VARCHAR(255) NULL,
    periodicidade ENUM('diario','seg_sex','manual') NOT NULL DEFAULT 'diario',
    horario TIME NULL,
    status ENUM('ativa','pausada') NOT NULL DEFAULT 'pausada',
    ultima_execucao_em DATETIME NULL,
    ultima_execucao_status ENUM('sucesso','falha','bloqueada') NULL,
    ultima_execucao_mensagem VARCHAR(500) NULL,
    total_arquivos_ultima_execucao INT UNSIGNED NOT NULL DEFAULT 0,
    criado_por_usuario_id BIGINT UNSIGNED NULL,
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rotinas_carga_sftp_credor (credor_id),
    KEY idx_rotinas_carga_sftp_status_periodicidade (status, periodicidade, horario),
    CONSTRAINT fk_rotinas_carga_sftp_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_rotinas_carga_sftp_criador
        FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_rotinas_carga_sftp_atualizador
        FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS rotinas_carga_sftp_execucoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rotina_id BIGINT UNSIGNED NOT NULL,
    status ENUM('sucesso','falha','bloqueada') NOT NULL,
    total_arquivos_baixados INT UNSIGNED NOT NULL DEFAULT 0,
    mensagem VARCHAR(500) NULL,
    disparado_por_usuario_id BIGINT UNSIGNED NULL,
    iniciado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalizado_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rotinas_carga_sftp_execucoes_rotina (rotina_id, criado_em),
    CONSTRAINT fk_rotinas_carga_sftp_execucoes_rotina
        FOREIGN KEY (rotina_id) REFERENCES rotinas_carga_sftp (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_rotinas_carga_sftp_execucoes_usuario
        FOREIGN KEY (disparado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('cargas.gerenciar_rotinas', 'Gerenciar rotinas de coleta de carga', 'Cargas', 'Cadastrar, editar, ativar/pausar e executar rotinas de coleta automatica de carga via SFTP, incluindo credenciais.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'cargas.gerenciar_rotinas'
WHERE pa.codigo = 'admin';
