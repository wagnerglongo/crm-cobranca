-- CRM Call Center - Migration 030
-- Ficha do cliente 2.0: permissao para gestao de contatos.
-- Escopo: adiciona permissao operacional para criar/atualizar status de contatos na ficha do cliente.
-- Nao altera motor de cargas, Docker ou credores.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

INSERT INTO permissoes (codigo, nome, grupo, descricao, ativo) VALUES
('contatos.gerenciar', 'Gerenciar contatos do cliente', 'Clientes', 'Adicionar contato manual e atualizar status/prioridade dos contatos na ficha do cliente.', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ativo = VALUES(ativo);

-- Perfis operacionais recebem a permissao porque o operador precisa marcar telefone correto/incorreto durante o acionamento.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pa.id, pe.id
FROM perfis_acesso pa
INNER JOIN permissoes pe ON pe.codigo = 'contatos.gerenciar'
WHERE pa.codigo IN ('admin', 'supervisor', 'lider', 'operador');
