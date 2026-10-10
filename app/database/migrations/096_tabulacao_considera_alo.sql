-- Requisito (2026-09-24): funil de acionamento da ACME com
-- Acionamentos > Tentativas > Alo > CPC > Acordo. "Alo" (alguem atendeu a
-- ligacao) deixa de ser deduzido do resultado_legacy e passa a ser um marcador
-- explicito de cada tabulacao (editavel em Administracao > Tabulacoes), igual
-- aos marcadores de contato efetivo/sucesso.
SET NAMES utf8mb4;
SET time_zone = '-03:00';

ALTER TABLE atendimento_resultados
    ADD COLUMN considera_alo TINYINT(1) NOT NULL DEFAULT 0 AFTER considera_contato_efetivo;

-- Semente: todo contato efetivo e alo (falou com alguem), mais recado com
-- terceiros e agendamento (houve conversa). Discador, URA, nao atende,
-- ocupado, caixa postal, numero invalido e envios (e-mail/SMS/WhatsApp) ficam 0.
UPDATE atendimento_resultados
   SET considera_alo = 1
 WHERE considera_contato_efetivo = 1
    OR codigo IN ('ACME_004', 'ACME_189');
