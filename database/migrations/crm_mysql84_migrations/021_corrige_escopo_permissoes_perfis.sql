-- CRM Call Center - Migration 021
-- Correção de permissões por perfil/grupo e limpeza de permissões diretas por usuário.
-- Executar depois da migration 019 e do patch 020.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- A administração passa a ser por perfil/grupo. Permissões diretas ficam desabilitadas na tela.
DELETE FROM usuario_permissoes;

-- Garante que o admin continue com todas as permissões ativas.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
CROSS JOIN permissoes pe
WHERE pa.codigo = 'admin'
  AND pe.ativo = 1;

-- Ajuste inicial: supervisor, líder e operador não recebem preventiva por padrão.
-- O admin pode liberar novamente pela tela Configurações > Perfis.
DELETE pp
FROM perfil_permissoes pp
INNER JOIN perfis_acesso pa ON pa.id = pp.perfil_id
INNER JOIN permissoes pe ON pe.id = pp.permissao_id
WHERE pa.codigo IN ('supervisor', 'lider', 'operador')
  AND pe.codigo = 'preventiva.visualizar';

-- Atualiza descrições para deixar claro que permissões são de perfil.
UPDATE permissoes
SET nome = 'Gerenciar usuários e perfis',
    descricao = 'Cadastrar usuários, perfis/grupos, permissões e escopo por credor.'
WHERE codigo = 'usuarios.gerenciar';

UPDATE perfis_acesso
SET descricao = 'Gestão operacional conforme permissões configuradas no perfil e escopo de credores do usuário.'
WHERE codigo = 'supervisor';

UPDATE perfis_acesso
SET descricao = 'Acompanha equipe e opera conforme permissões configuradas no perfil e escopo de credores do usuário.'
WHERE codigo = 'lider';
