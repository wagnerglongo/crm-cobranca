-- CRM Call Center - Migration 025
-- Tela administrativa da Auditoria Geral por Usuario.
-- Executar depois da migration 024_auditoria_geral_sistema.sql.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('auditoria.visualizar', 'Visualizar auditoria geral', 'Configurações', 'Consultar a auditoria geral de ações sensíveis dos usuários no CRM.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

-- Admin recebe a nova permissão explicitamente para aparecer marcada na tela de Perfis.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'auditoria.visualizar'
WHERE pa.codigo = 'admin';

-- Supervisor também recebe por padrão para acompanhar ações sensíveis da operação.
-- Se desejar restringir, remova pela tela Configurações > Perfis.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'auditoria.visualizar'
WHERE pa.codigo = 'supervisor';
