-- Patch 131 / Migration 092 - Status "processando" pras rotinas de carga
-- A execucao da rotina roda em segundo plano (2026-09-16, corrigindo um
-- timeout na tela) - sem um status intermediario, o operador clica em
-- "Executar" e nao tem nenhum sinal visual de que algo esta acontecendo
-- ate a tabela ser atualizada com o resultado final.
SET NAMES utf8mb4;
SET time_zone = '-03:00';

ALTER TABLE rotinas_carga_sftp
    MODIFY COLUMN ultima_execucao_status ENUM('processando','sucesso','falha','bloqueada') NULL;
