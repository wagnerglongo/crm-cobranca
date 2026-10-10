-- Requisito (2026-09-23): quando o acionamento tem CPC (contato com
-- a pessoa certa, considera_contato_efetivo=1), o operador precisa
-- informar o motivo da inadimplencia (ja teve contato, entao por que nao
-- fechou). Lista configuravel (Administracao > Motivos de Inadimplencia),
-- seed inicial com as 6 opcoes que ele mandou por imagem.
SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS motivos_inadimplencia (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_motivos_inadimplencia_nome (nome),
    KEY idx_motivos_inadimplencia_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO motivos_inadimplencia (nome, ordem) VALUES
    ('Perdeu a renda', 10),
    ('Discorda do valor', 20),
    ('Problema de saúde/familiar', 30),
    ('Alega pagamento', 40),
    ('Cliente não informa motivo', 50),
    ('Não reconhece a dívida', 60)
ON DUPLICATE KEY UPDATE ordem = VALUES(ordem);

ALTER TABLE atendimentos_historico
    ADD COLUMN motivo_inadimplencia_id SMALLINT UNSIGNED NULL DEFAULT NULL AFTER resultado_id,
    ADD CONSTRAINT fk_atend_hist_motivo_inadimplencia FOREIGN KEY (motivo_inadimplencia_id) REFERENCES motivos_inadimplencia(id) ON DELETE SET NULL,
    ADD INDEX idx_atend_hist_motivo_inadimplencia (motivo_inadimplencia_id);

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('motivos_inadimplencia.configurar', 'Configurar motivos de inadimplência', 'Administração', 'Cadastrar/editar as opções de "motivo da inadimplência" perguntadas no acionamento quando há CPC (contato com a pessoa certa).', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'motivos_inadimplencia.configurar'
WHERE pa.codigo = 'admin';
