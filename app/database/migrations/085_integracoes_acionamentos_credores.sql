-- CRM Call Center - Migration 085
-- Integracoes padronizadas por credor, de/para de tabulacoes, outbox de saida,
-- historico externo de acionamentos e fundacao para entregas por arquivo.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS credor_integracoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credor_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(80) NOT NULL,
    nome VARCHAR(160) NOT NULL,
    evento_tipo VARCHAR(100) NOT NULL DEFAULT 'acionamento.registrado',
    tipo_transporte ENUM('api','arquivo','sftp','pasta_compartilhada','webhook') NOT NULL DEFAULT 'api',
    adapter VARCHAR(100) NOT NULL,
    segredo_env_prefixo VARCHAR(80) NULL,
    configuracao_json JSON NULL,
    timeout_segundos SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    max_tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    retry_inicial_segundos INT UNSIGNED NOT NULL DEFAULT 60,
    retry_max_segundos INT UNSIGNED NOT NULL DEFAULT 3600,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por_usuario_id BIGINT UNSIGNED NULL,
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credor_integracoes_codigo (credor_id, codigo),
    KEY idx_credor_integracoes_evento (credor_id, evento_tipo, ativo),
    KEY idx_credor_integracoes_adapter (adapter, ativo),
    CONSTRAINT fk_credor_integracoes_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_credor_integracoes_criado_por
        FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_credor_integracoes_atualizado_por
        FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_credor_integracoes_timeout
        CHECK (timeout_segundos BETWEEN 3 AND 120),
    CONSTRAINT chk_credor_integracoes_retry
        CHECK (retry_max_segundos >= retry_inicial_segundos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integracao_tabulacao_mapeamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integracao_id BIGINT UNSIGNED NOT NULL,
    resultado_id SMALLINT UNSIGNED NOT NULL,
    produto VARCHAR(180) NOT NULL DEFAULT '',
    enviar TINYINT(1) NOT NULL DEFAULT 1,
    codigo_externo VARCHAR(120) NULL,
    descricao_externa VARCHAR(255) NULL,
    payload_extra JSON NULL,
    versao INT UNSIGNED NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por_usuario_id BIGINT UNSIGNED NULL,
    atualizado_por_usuario_id BIGINT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_tabulacao_produto (integracao_id, resultado_id, produto),
    KEY idx_integracao_tabulacao_resultado (resultado_id, ativo),
    KEY idx_integracao_tabulacao_envio (integracao_id, enviar, ativo),
    CONSTRAINT fk_integracao_tabulacao_integracao
        FOREIGN KEY (integracao_id) REFERENCES credor_integracoes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_integracao_tabulacao_resultado
        FOREIGN KEY (resultado_id) REFERENCES atendimento_resultados (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_integracao_tabulacao_criado_por
        FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_integracao_tabulacao_atualizado_por
        FOREIGN KEY (atualizado_por_usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_integracao_tabulacao_codigo
        CHECK (enviar = 0 OR (codigo_externo IS NOT NULL AND CHAR_LENGTH(TRIM(codigo_externo)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integracao_eventos_saida (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evento_uuid CHAR(36) NOT NULL,
    integracao_id BIGINT UNSIGNED NOT NULL,
    credor_id BIGINT UNSIGNED NOT NULL,
    evento_tipo VARCHAR(100) NOT NULL,
    origem_tabela VARCHAR(80) NOT NULL,
    origem_id BIGINT UNSIGNED NOT NULL,
    pessoa_id BIGINT UNSIGNED NULL,
    contrato_id BIGINT UNSIGNED NULL,
    resultado_id SMALLINT UNSIGNED NULL,
    mapeamento_id BIGINT UNSIGNED NULL,
    mapeamento_versao INT UNSIGNED NULL,
    status ENUM('pendente','processando','enviado','confirmado','erro_retry','erro_definitivo','cancelado') NOT NULL DEFAULT 'pendente',
    prioridade SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    payload JSON NOT NULL,
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    disponivel_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    bloqueado_em DATETIME(3) NULL,
    bloqueado_por VARCHAR(120) NULL,
    ultimo_http_status SMALLINT UNSIGNED NULL,
    protocolo_externo VARCHAR(180) NULL,
    resposta_payload JSON NULL,
    ultimo_erro TEXT NULL,
    enviado_em DATETIME(3) NULL,
    confirmado_em DATETIME(3) NULL,
    criado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    atualizado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_evento_uuid (evento_uuid),
    UNIQUE KEY uq_integracao_evento_origem (integracao_id, evento_tipo, origem_tabela, origem_id),
    KEY idx_integracao_eventos_fila (status, disponivel_em, prioridade, id),
    KEY idx_integracao_eventos_bloqueio (status, bloqueado_em),
    KEY idx_integracao_eventos_contrato (contrato_id, criado_em),
    KEY idx_integracao_eventos_credor (credor_id, evento_tipo, criado_em),
    CONSTRAINT fk_integracao_eventos_integracao
        FOREIGN KEY (integracao_id) REFERENCES credor_integracoes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_integracao_eventos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_integracao_eventos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_integracao_eventos_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_integracao_eventos_resultado
        FOREIGN KEY (resultado_id) REFERENCES atendimento_resultados (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_integracao_eventos_mapeamento
        FOREIGN KEY (mapeamento_id) REFERENCES integracao_tabulacao_mapeamentos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integracao_eventos_tentativas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evento_saida_id BIGINT UNSIGNED NOT NULL,
    numero_tentativa SMALLINT UNSIGNED NOT NULL,
    worker_id VARCHAR(120) NULL,
    status ENUM('iniciada','sucesso','erro_retry','erro_definitivo') NOT NULL DEFAULT 'iniciada',
    http_status SMALLINT UNSIGNED NULL,
    duracao_ms INT UNSIGNED NULL,
    requisicao_payload JSON NULL,
    resposta_payload JSON NULL,
    erro TEXT NULL,
    iniciado_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finalizado_em DATETIME(3) NULL,
    criado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_tentativa_numero (evento_saida_id, numero_tentativa),
    KEY idx_integracao_tentativas_status (status, criado_em),
    CONSTRAINT fk_integracao_tentativas_evento
        FOREIGN KEY (evento_saida_id) REFERENCES integracao_eventos_saida (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integracao_acionamentos_externos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integracao_id BIGINT UNSIGNED NOT NULL,
    credor_id BIGINT UNSIGNED NOT NULL,
    pessoa_id BIGINT UNSIGNED NULL,
    contrato_id BIGINT UNSIGNED NULL,
    identificador_externo VARCHAR(160) NOT NULL,
    registro_externo VARCHAR(160) NULL,
    documento VARCHAR(20) NOT NULL,
    codigo_contrato VARCHAR(120) NULL,
    observacao TEXT NULL,
    codigo_acao_externa VARCHAR(120) NULL,
    descricao_acao_externa VARCHAR(255) NULL,
    agendado_em DATETIME(3) NULL,
    ocorrido_em DATETIME(3) NULL,
    origem ENUM('api_consulta','api_retorno','arquivo') NOT NULL DEFAULT 'api_consulta',
    payload_original JSON NOT NULL,
    sincronizado_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    criado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    atualizado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_acionamento_externo (integracao_id, identificador_externo),
    KEY idx_integracao_acionamentos_documento (credor_id, documento, ocorrido_em),
    KEY idx_integracao_acionamentos_contrato (contrato_id, ocorrido_em),
    KEY idx_integracao_acionamentos_codigo (credor_id, codigo_contrato, ocorrido_em),
    CONSTRAINT fk_integracao_acionamentos_integracao
        FOREIGN KEY (integracao_id) REFERENCES credor_integracoes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_integracao_acionamentos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_integracao_acionamentos_pessoa
        FOREIGN KEY (pessoa_id) REFERENCES pessoas (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_integracao_acionamentos_contrato
        FOREIGN KEY (contrato_id) REFERENCES contratos (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integracao_consultas_estado (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integracao_id BIGINT UNSIGNED NOT NULL,
    credor_id BIGINT UNSIGNED NOT NULL,
    recurso VARCHAR(100) NOT NULL,
    chave_consulta VARCHAR(190) NOT NULL,
    status ENUM('sucesso','erro') NOT NULL DEFAULT 'sucesso',
    total_itens INT UNSIGNED NOT NULL DEFAULT 0,
    ultima_sincronizacao DATETIME(3) NULL,
    ultima_tentativa_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    ultimo_erro TEXT NULL,
    criado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    atualizado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_consulta_estado (integracao_id, recurso, chave_consulta),
    KEY idx_integracao_consulta_credor (credor_id, recurso, ultima_sincronizacao),
    CONSTRAINT fk_integracao_consulta_integracao
        FOREIGN KEY (integracao_id) REFERENCES credor_integracoes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_integracao_consulta_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integracao_arquivos_lotes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integracao_id BIGINT UNSIGNED NOT NULL,
    credor_id BIGINT UNSIGNED NOT NULL,
    evento_tipo VARCHAR(100) NOT NULL,
    data_referencia DATE NOT NULL,
    sequencia INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('aberto','gerando','pronto','entregue','erro','cancelado') NOT NULL DEFAULT 'aberto',
    nome_arquivo VARCHAR(255) NULL,
    caminho_temporario VARCHAR(500) NULL,
    caminho_final VARCHAR(500) NULL,
    checksum_sha256 CHAR(64) NULL,
    total_itens INT UNSIGNED NOT NULL DEFAULT 0,
    total_linhas INT UNSIGNED NOT NULL DEFAULT 0,
    erro TEXT NULL,
    gerado_em DATETIME(3) NULL,
    entregue_em DATETIME(3) NULL,
    criado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    atualizado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_arquivo_lote (integracao_id, evento_tipo, data_referencia, sequencia),
    KEY idx_integracao_arquivos_status (status, data_referencia, id),
    CONSTRAINT fk_integracao_arquivos_integracao
        FOREIGN KEY (integracao_id) REFERENCES credor_integracoes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_integracao_arquivos_credor
        FOREIGN KEY (credor_id) REFERENCES credores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integracao_arquivos_itens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lote_id BIGINT UNSIGNED NOT NULL,
    evento_saida_id BIGINT UNSIGNED NOT NULL,
    numero_linha INT UNSIGNED NULL,
    payload_linha JSON NULL,
    criado_em TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_arquivo_evento (evento_saida_id),
    UNIQUE KEY uq_integracao_arquivo_linha (lote_id, numero_linha),
    CONSTRAINT fk_integracao_arquivos_itens_lote
        FOREIGN KEY (lote_id) REFERENCES integracao_arquivos_lotes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_integracao_arquivos_itens_evento
        FOREIGN KEY (evento_saida_id) REFERENCES integracao_eventos_saida (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Integracao ACME de acionamentos. Credenciais permanecem exclusivamente no .env.local.
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
    'ACIONAMENTOS',
    'ACME - Acionamentos',
    'acionamento.registrado',
    'api',
    'ACME_ACIONAMENTOS',
    'ACME_API',
    JSON_OBJECT(
        'endpoint_envio', '/v1/acionamentos',
        'endpoint_consulta', '/v1/devedores/{docNumber}/acionamentos',
        'ambiente', 'homologacao'
    ),
    45,
    8,
    60,
    3600,
    1
FROM credores c
WHERE UPPER(c.codigo) IN ('ACME', 'ACMECREDOR', 'ACME_API')
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    evento_tipo = VALUES(evento_tipo),
    tipo_transporte = VALUES(tipo_transporte),
    adapter = VALUES(adapter),
    segredo_env_prefixo = VALUES(segredo_env_prefixo),
    configuracao_json = VALUES(configuracao_json),
    timeout_segundos = VALUES(timeout_segundos),
    ativo = VALUES(ativo),
    atualizado_em = CURRENT_TIMESTAMP;
