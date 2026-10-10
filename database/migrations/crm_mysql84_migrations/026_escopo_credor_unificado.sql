-- CRM Call Center - Migration 026
-- Escopo por credor unificado: permissões por ação ficam no perfil/hierarquia.
-- Executar depois da migration 025_tela_auditoria_geral.sql.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- A partir deste patch, usuario_credores representa apenas quais credores o usuário pode acessar.
-- As colunas antigas de ação são preservadas para compatibilidade, mas qualquer credor selecionado passa a valer para o escopo inteiro.
UPDATE usuario_credores
SET pode_consultar = 1,
    pode_operar = 1,
    pode_fechar_acordo = 1,
    pode_criar_campanha = 1
WHERE pode_consultar = 1
   OR pode_operar = 1
   OR pode_fechar_acordo = 1
   OR pode_criar_campanha = 1;

DELETE FROM usuario_credores
WHERE pode_consultar = 0
  AND pode_operar = 0
  AND pode_fechar_acordo = 0
  AND pode_criar_campanha = 0;

UPDATE permissoes
SET nome = 'Gerenciar usuários, perfis e escopo',
    descricao = 'Cadastrar usuários, perfis/grupos, permissões de perfil e escopo simples por credor.'
WHERE codigo = 'usuarios.gerenciar';

UPDATE perfis_acesso
SET descricao = 'Acesso total. Permissões definidas no perfil e escopo total por padrão.'
WHERE codigo = 'admin';

UPDATE perfis_acesso
SET descricao = 'Gestão operacional conforme permissões do perfil. Credores são definidos no cadastro do usuário.'
WHERE codigo = 'supervisor';

UPDATE perfis_acesso
SET descricao = 'Acompanha equipe e opera conforme permissões do perfil. Credores são definidos no cadastro do usuário.'
WHERE codigo = 'lider';

UPDATE perfis_acesso
SET descricao = 'Foco em preventiva conforme permissões do perfil. Credores são definidos no cadastro do usuário.'
WHERE codigo = 'preventivo';

UPDATE perfis_acesso
SET descricao = 'Operação de atendimento conforme permissões do perfil. Credores são definidos no cadastro do usuário.'
WHERE codigo = 'operador';
