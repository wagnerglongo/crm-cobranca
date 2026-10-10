-- Acesso ao OpenSearch Dashboards (porta 5601) com o login do Pactua, so pra quem tiver
-- esta permissao. Por padrao apenas o perfil admin (admin_total tambem passa via '*').
-- Conceder a outros perfis: tela de perfis/permissoes do Pactua.
INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('dashboards.acessar', 'Acessar OpenSearch Dashboards', 'Analytics', 'Entrar no OpenSearch Dashboards (porta 5601) com o login do Pactua. Mostra dados de todos os credores - conceder so a quem pode ver tudo.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'dashboards.acessar'
WHERE pa.codigo = 'admin';
