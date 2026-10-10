-- Requisito (2026-10-06): o que o operador inclui/corrige na
-- Localizacao (telefone, e-mail, endereco) nao chegava na ACME - o CRM so
-- enviava acionamentos. A API da ACME tem o grupo WsContactDetails pra isso
-- (InsertPhoneRecord/InsertEmailRecord/InsertAddressRecord e
-- UpdateStatusPhone/Email/Address; Swagger em
-- docs/CREDOR_API.md).
--
-- Integracao separada da de acionamentos pra poder ser ligada/desligada
-- sozinha em Configuracoes > Integracoes, usando a mesma fila de saida
-- (integracao_eventos_saida), o mesmo worker e as mesmas credenciais.
SET NAMES utf8mb4;
SET time_zone = '-03:00';

INSERT INTO credor_integracoes (
    credor_id,
    codigo,
    nome,
    evento_tipo,
    tipo_transporte,
    adapter,
    segredo_env_prefixo,
    configuracao_json,
    timeout_segundos,
    max_tentativas,
    retry_inicial_segundos,
    retry_max_segundos,
    ativo
)
SELECT
    c.id,
    'CONTATOS',
    'ACME - Contatos (telefone, e-mail e endereço)',
    'contato.sincronizar',
    'api',
    'ACME_CONTATOS',
    'ACME_API',
    JSON_OBJECT(
        'endpoints', JSON_ARRAY(
            '/v1/devedores/{docNumber}/telefones|emails|enderecos',
            '/v1/contatos/telefones|emails|enderecos',
            '/v1/devedores/{docNumber}/telefones|emails|enderecos/{id}/status/...'
        )
    ),
    45,
    8,
    60,
    3600,
    1
FROM credores c
WHERE UPPER(c.codigo) = 'ACME'
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    evento_tipo = VALUES(evento_tipo),
    tipo_transporte = VALUES(tipo_transporte),
    adapter = VALUES(adapter),
    segredo_env_prefixo = VALUES(segredo_env_prefixo);
