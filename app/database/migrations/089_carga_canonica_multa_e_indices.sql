-- Valor de multa por parcela na staging canônica e índices usados na aplicação das cargas.
SET NAMES utf8mb4;
SET time_zone = '-03:00';

ALTER TABLE stg_carga_canonica
    ADD COLUMN valor_multa_parcela DECIMAL(15,2) NULL AFTER valor_juros_parcela;

ALTER TABLE stg_carga_canonica
    ADD KEY idx_stg_carga_contrato_status (carga_arquivo_id, codigo_contrato, status);

ALTER TABLE contratos
    ADD KEY idx_contratos_credor_carteira_ultima_carga (credor_id, carteira_id, ultima_carga_id);
