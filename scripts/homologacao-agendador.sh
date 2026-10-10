#!/usr/bin/env bash
# Copia do banco de producao para a homologacao: toda semana (padrao domingo
# 05:00) e sob demanda. Requisito de 2026-10-06.
#
# Quem decide SE e QUANDO copiar e o CRM (Administracao > Homologacao,
# tabelas homologacao_agenda/homologacao_copias). Este script so executa,
# a cada minuto pelo cron (crontab do works ou scripts/cron/homologacao-agendador.cron):
#   1. pergunta ao CRM se tem copia a fazer agora (console/homologacao_copia.php reservar);
#   2. roda scripts/homologacao.sh banco --confirmar (~2 min, ver o proprio script);
#   3. devolve o resultado (aparece na tela) e, se falhar, manda o alerta por e-mail.
# Log completo de cada copia: storage/logs/homologacao-copia.log.
set -Euo pipefail
cd "$(dirname "$0")/.."

# Uma execucao por vez (root e works podem ter o cron instalado).
exec 9<"$0"
flock -n 9 || exit 0

if docker info >/dev/null 2>&1; then dk() { docker "$@"; }; else dk() { sg docker -c "docker $(printf '%q ' "$@")"; }; fi
LOG=storage/logs/homologacao-copia.log
[ -e "$LOG" ] || install -m 0666 /dev/null "$LOG"

id=$(dk exec crm_php php console/homologacao_copia.php reservar) || exit 1
[ -n "$id" ] || exit 0
[[ "$id" =~ ^[0-9]+$ ]] || { echo "resposta inesperada do CRM: $id" >&2; exit 1; }

saida=$(mktemp)
trap 'rm -f "$saida"' EXIT
inicio=$(date +%s)
echo "== $(date '+%F %T') copia #$id" >> "$LOG"
if bash scripts/homologacao.sh banco --confirmar > "$saida" 2>&1; then resultado=ok; else resultado=erro; fi
cat "$saida" >> "$LOG"
duracao=$(( $(date +%s) - inicio ))

{
    echo "Duração: $((duracao / 60)) min $((duracao % 60)) s."
    grep -E '^(contratos|integracoes ativas|rotinas sftp ativas):' "$saida" || true
    [ "$resultado" = ok ] || tail -n 8 "$saida"
} | dk exec -i crm_php php console/homologacao_copia.php finalizar "$id" "$resultado"

if [ "$resultado" != ok ]; then
    dk exec crm_php php console/alertar_falha_cron.php "[CRM] copia do banco da homologacao falhou" \
        "A copia #$id do banco de producao para a homologacao falhou. A producao nao e afetada. Detalhes em Administracao > Homologacao e em /opt/crm-dev-docker/storage/logs/homologacao-copia.log." >/dev/null 2>&1 || true
fi
echo "== $(date '+%F %T') copia #$id: $resultado ($duracao s)" >> "$LOG"
