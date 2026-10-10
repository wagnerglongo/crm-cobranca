-- Índices para a aplicação de cargas grandes (snapshot diário).
--
-- Objetivos:
-- 1) evitar GROUP BY/varreduras completas repetidas no staging;
-- 2) acelerar leitura das linhas válidas por carga/contrato;
-- 3) acelerar diagnóstico/limpeza controlada por origem/última carga.

SET NAMES utf8mb4;
SET time_zone = '-03:00';

ALTER TABLE stg_carga_canonica
    ADD KEY idx_stg_apply_queue (carga_arquivo_id, status, codigo_contrato, id);

ALTER TABLE pessoas
    ADD KEY idx_pessoas_ultima_carga (ultima_carga_id);

ALTER TABLE pessoa_contatos
    ADD KEY idx_contatos_ultima_carga (ultima_carga_id);

ALTER TABLE pessoa_enderecos
    ADD KEY idx_enderecos_ultima_carga (ultima_carga_id);

ALTER TABLE contrato_parcelas
    ADD KEY idx_parcelas_ultima_carga (ultima_carga_id);
