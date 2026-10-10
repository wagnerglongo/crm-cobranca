-- Requisito (2026-10-01): o CRM escolhia sozinho qual tabulacao usar
-- quando um acordo e fechado pelo Pactua (pra registrar no historico e
-- enviar o ActionID certo pra ACME) sem nenhuma logica de verdade - so
-- pegava a primeira por ordem, que por coincidencia era "ACORDO - EXCECAO"
-- em vez de "ACORDO". Passa a existir uma marcacao explicita de "padrao
-- pra acordo fechado", editavel em Administracao > Tabulacoes, e escopada
-- por credor (cada credor tem seu proprio catalogo de tabulacoes e pode
-- usar um ActionID diferente como padrao).
SET NAMES utf8mb4;
SET time_zone = '-03:00';

ALTER TABLE atendimento_resultados
    ADD COLUMN credor_id BIGINT UNSIGNED NULL AFTER categoria,
    ADD COLUMN padrao_fechamento_acordo TINYINT(1) NOT NULL DEFAULT 0 AFTER gera_reagendamento,
    ADD CONSTRAINT fk_atendimento_resultados_credor FOREIGN KEY (credor_id) REFERENCES credores(id) ON DELETE SET NULL,
    ADD INDEX idx_atendimento_resultados_credor (credor_id),
    ADD INDEX idx_atendimento_resultados_padrao_acordo (credor_id, padrao_fechamento_acordo);

-- As 33 tabulacoes reais hoje cadastradas (prefixo ACME_) pertencem todas a
-- ACME - credor_id NULL continua existindo como "vale pra qualquer credor"
-- (fallback), mas nenhuma tabulacao real usa isso hoje.
UPDATE atendimento_resultados ar
  JOIN credores c ON c.codigo = 'ACME'
   SET ar.credor_id = c.id
 WHERE ar.codigo LIKE 'ACME\_%';

-- ACME_002 ("ACORDO" / "Promessa de Acordo", ActionID 2) vira o padrao da
-- ACME pra acordo fechado pelo Pactua - era a correta por significado, so
-- nunca foi escolhida porque nao era a primeira por ordem.
UPDATE atendimento_resultados
   SET padrao_fechamento_acordo = 1
 WHERE codigo = 'ACME_002';
