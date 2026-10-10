-- Patch 132 - Sessao operacional com duracao absoluta em horas.
-- A chave antiga em minutos nunca foi aplicada como timeout real. O novo valor
-- passa a ser utilizado pelo bootstrap do Pactua e o padrao solicitado e 10h.

INSERT INTO seguranca_configuracoes (codigo, valor, descricao, atualizado_por_usuario_id)
VALUES (
    'sessao_expira_horas',
    '10',
    'Duracao maxima absoluta da sessao operacional, em horas. A sessao tambem exige novo login na virada do dia.',
    NULL
)
ON DUPLICATE KEY UPDATE
    descricao = VALUES(descricao);

DELETE FROM seguranca_configuracoes
WHERE codigo = 'sessao_expira_minutos';
