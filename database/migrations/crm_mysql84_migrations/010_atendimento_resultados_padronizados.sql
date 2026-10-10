-- CRM Call Center - Migration 010
-- Tabulacao padronizada de resultados de atendimento.
-- Escopo: criar base configuravel para resultados e vincular atendimentos_historico por resultado_id.
-- Mantem compatibilidade com o campo antigo atendimentos_historico.resultado.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS atendimento_resultados (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(60) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    categoria VARCHAR(80) NOT NULL,
    resultado_legacy VARCHAR(40) NOT NULL DEFAULT 'outro',
    descricao VARCHAR(255) NULL,
    gera_reagendamento TINYINT(1) NOT NULL DEFAULT 0,
    considera_sucesso TINYINT(1) NOT NULL DEFAULT 0,
    considera_contato_efetivo TINYINT(1) NOT NULL DEFAULT 0,
    considera_telefone_ruim TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_atendimento_resultados_codigo (codigo),
    KEY idx_atendimento_resultados_ativo_ordem (ativo, ordem),
    KEY idx_atendimento_resultados_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO atendimento_resultados (
    codigo,
    nome,
    categoria,
    resultado_legacy,
    descricao,
    gera_reagendamento,
    considera_sucesso,
    considera_contato_efetivo,
    considera_telefone_ruim,
    ativo,
    ordem
) VALUES
    ('SEM_CONTATO', 'Sem contato', 'Sem contato', 'sem_contato', 'Tentativa sem contato efetivo e sem detalhe mais especifico.', 0, 0, 0, 0, 1, 10),
    ('NAO_ATENDE', 'Não atende', 'Sem contato', 'nao_atende', 'Chamada não atendida pelo cliente.', 0, 0, 0, 0, 1, 20),
    ('OCUPADO', 'Ocupado', 'Sem contato', 'ocupado', 'Linha ocupada no momento da tentativa.', 0, 0, 0, 0, 1, 30),
    ('CAIXA_POSTAL', 'Caixa postal', 'Sem contato', 'caixa_postal', 'Ligação caiu em caixa postal ou secretária eletrônica.', 0, 0, 0, 0, 1, 40),
    ('NUMERO_INVALIDO', 'Número inválido', 'Telefone ruim', 'numero_invalido', 'Telefone inválido, inexistente ou fora de serviço.', 0, 0, 0, 1, 1, 50),
    ('DESCONHECE_CLIENTE', 'Desconhece cliente', 'Telefone ruim', 'outro', 'Atendeu, mas informou desconhecer o cliente/devedor.', 0, 0, 1, 1, 1, 60),
    ('RECADO', 'Recado', 'Contato indireto', 'recado', 'Recado deixado com terceiro ou pessoa relacionada.', 0, 0, 0, 0, 1, 70),
    ('ATENDEU', 'Atendeu', 'Contato efetivo', 'atendeu', 'Cliente ou responsável atendeu, sem negociação registrada.', 0, 1, 1, 0, 1, 80),
    ('EM_NEGOCIACAO', 'Em negociação', 'Negociação', 'em_negociacao', 'Cliente em negociação, sem promessa ou acordo fechado nesta etapa.', 0, 1, 1, 0, 1, 90),
    ('PROMESSA_PAGAMENTO', 'Promessa de pagamento', 'Negociação', 'promessa_pagamento', 'Cliente indicou intenção/promessa de pagamento.', 0, 1, 1, 0, 1, 100),
    ('RETORNAR_DEPOIS', 'Retornar depois', 'Reagendamento', 'outro', 'Cliente pediu retorno ou a operação decidiu reagendar contato.', 1, 0, 1, 0, 1, 110),
    ('OUTRO', 'Outro', 'Outros', 'outro', 'Resultado excepcional ainda não classificado.', 0, 0, 0, 0, 1, 900)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    categoria = VALUES(categoria),
    resultado_legacy = VALUES(resultado_legacy),
    descricao = VALUES(descricao),
    gera_reagendamento = VALUES(gera_reagendamento),
    considera_sucesso = VALUES(considera_sucesso),
    considera_contato_efetivo = VALUES(considera_contato_efetivo),
    considera_telefone_ruim = VALUES(considera_telefone_ruim),
    ativo = VALUES(ativo),
    ordem = VALUES(ordem);

SET @col_resultado_id_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND COLUMN_NAME = 'resultado_id'
);

SET @sql_add_resultado_id = IF(
    @col_resultado_id_exists = 0,
    'ALTER TABLE atendimentos_historico ADD COLUMN resultado_id SMALLINT UNSIGNED NULL AFTER resultado',
    'SELECT 1'
);
PREPARE stmt_add_resultado_id FROM @sql_add_resultado_id;
EXECUTE stmt_add_resultado_id;
DEALLOCATE PREPARE stmt_add_resultado_id;

UPDATE atendimentos_historico ah
JOIN atendimento_resultados ar
  ON ar.codigo = CASE ah.resultado
      WHEN 'sem_contato' THEN 'SEM_CONTATO'
      WHEN 'nao_atende' THEN 'NAO_ATENDE'
      WHEN 'atendeu' THEN 'ATENDEU'
      WHEN 'ocupado' THEN 'OCUPADO'
      WHEN 'caixa_postal' THEN 'CAIXA_POSTAL'
      WHEN 'numero_invalido' THEN 'NUMERO_INVALIDO'
      WHEN 'recado' THEN 'RECADO'
      WHEN 'promessa_pagamento' THEN 'PROMESSA_PAGAMENTO'
      WHEN 'em_negociacao' THEN 'EM_NEGOCIACAO'
      ELSE 'OUTRO'
  END
SET ah.resultado_id = ar.id
WHERE ah.resultado_id IS NULL;

SET @idx_resultado_id_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'atendimentos_historico'
      AND INDEX_NAME = 'idx_atend_hist_resultado_id'
);

SET @sql_add_idx_resultado_id = IF(
    @idx_resultado_id_exists = 0,
    'ALTER TABLE atendimentos_historico ADD KEY idx_atend_hist_resultado_id (resultado_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_resultado_id FROM @sql_add_idx_resultado_id;
EXECUTE stmt_add_idx_resultado_id;
DEALLOCATE PREPARE stmt_add_idx_resultado_id;

SET @fk_resultado_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_atend_hist_resultado'
);

SET @sql_add_fk_resultado = IF(
    @fk_resultado_exists = 0,
    'ALTER TABLE atendimentos_historico ADD CONSTRAINT fk_atend_hist_resultado FOREIGN KEY (resultado_id) REFERENCES atendimento_resultados (id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_add_fk_resultado FROM @sql_add_fk_resultado;
EXECUTE stmt_add_fk_resultado;
DEALLOCATE PREPARE stmt_add_fk_resultado;
