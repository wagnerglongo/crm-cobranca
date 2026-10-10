-- CRM Call Center - Migration 027
-- Relatorios operacionais por carteira.
-- Escopo: adiciona permissao de visualizacao dos relatorios gerenciais.
-- Nao altera dados de producao, motor de cargas, Docker ou integracoes.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('relatorios.visualizar', 'Visualizar relatórios operacionais', 'Relatórios', 'Acessar dashboard gerencial por carteira, produção, ligações, eventos, tentativas e acordos.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

-- Admin recebe todas as permissoes novas automaticamente.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'relatorios.visualizar'
WHERE pa.codigo IN ('admin', 'supervisor', 'lider');
