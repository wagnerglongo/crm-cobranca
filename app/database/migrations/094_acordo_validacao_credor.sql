-- Achado 2026-09-22: a quebra automatica de acordo por carencia so
-- usava dado local/cacheado da ACME, as vezes com dias de defasagem, e
-- derrubava acordo que ja tinha sido pago de verdade (ninguem foi la marcar
-- como pago no CRM). Este campo guarda quando o acordo foi conferido AO VIVO
-- com o credor pela ultima vez, pra travar a quebra automatica nos dias
-- gatilho (vencimento, D+1, limite da carencia, D+1 do limite) ate a
-- validacao rodar no dia.
SET NAMES utf8mb4;
SET time_zone = '-03:00';

ALTER TABLE acordos_operacionais
    ADD COLUMN validado_credor_em DATETIME NULL DEFAULT NULL AFTER status_atualizado_em;
