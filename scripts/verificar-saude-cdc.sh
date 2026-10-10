#!/bin/bash
# Monitor de saude do pipeline (roda a cada 5 min via cron).
# Alerta por e-mail quando: o conector Debezium nao esta RUNNING/RUNNING, ou algum
# container crm_* nao esta "Up"/saudavel. O Debezium ja caiu 2x (2026-09-17) e o
# purge-binlog-seguro.sh so se protege - nao avisava ninguem.
#
# Anti-ruido: so alerta apos 2 verificacoes seguidas com problema (restart normal
# nao dispara), repete no maximo a cada 6h, e avisa quando volta ao normal.
# Variaveis pra teste: DRY_RUN=1 (so imprime), STATE_FILE, CONNECTOR.
set -uo pipefail

PROJECT_DIR="/opt/crm-dev-docker"
CONNECTOR="${CONNECTOR:-crm-mysql-connector}"
STATE_FILE="${STATE_FILE:-/var/tmp/crm-saude-cdc.state}"
COOLDOWN=21600
LOG_PREFIX="[saude-cdc]"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX $*"; }

problemas=""

status_json="$(curl -fsS --max-time 5 "http://127.0.0.1:8083/connectors/$CONNECTOR/status" 2>/dev/null || true)"
if [ -z "$status_json" ]; then
    problemas+="- Debezium: sem resposta da API (conector '$CONNECTOR' inexistente ou container fora do ar)."$'\n'
else
    estado="$(printf '%s' "$status_json" | python3 -c "import sys,json
d=json.load(sys.stdin); t=[x.get('state') for x in d.get('tasks',[])]
print(d['connector']['state']+'|'+','.join(t) if t else d['connector']['state']+'|SEM_TASKS')" 2>/dev/null || echo "ilegivel|")"
    if [ "$estado" != "RUNNING|RUNNING" ]; then
        problemas+="- Debezium: connector|tasks = ${estado} (esperado RUNNING|RUNNING). Ver docs/RUNBOOK_SUPORTE.md, secao 5, pro procedimento de recuperacao."$'\n'
    fi
fi

while IFS='|' read -r nome status; do
    [ -z "$nome" ] && continue
    case "$nome" in crm_debezium_register) continue ;; esac
    case "$status" in
        Up*unhealthy*|Up*starting*) problemas+="- Container $nome: $status"$'\n' ;;
        Up*) ;;
        *) problemas+="- Container $nome fora do ar: $status"$'\n' ;;
    esac
done < <(docker ps -a --filter 'name=crm_' --format '{{.Names}}|{{.Status}}' 2>/dev/null)

# Achado 2026-10-05: o MySQL chegou a 1,6GB em swap sem ninguem perceber
# (suite 7x mais lenta, I/O travado ~23% do tempo). Avisa antes de ficar
# ruim. Paliativo: reiniciar o MySQL fora do expediente e DEPOIS reiniciar a
# task do Debezium (POST localhost:8083/connectors/<conector>/tasks/0/restart).
SWAP_MYSQL_LIMITE_MB="${SWAP_MYSQL_LIMITE_MB:-800}"
swap_kb="$(docker exec crm_mysql sh -c "awk '/VmSwap/{print \$2}' /proc/1/status" 2>/dev/null || true)"
if [ -n "$swap_kb" ] && [ "$swap_kb" -gt $(( SWAP_MYSQL_LIMITE_MB * 1024 )) ]; then
    problemas+="- MySQL com $(( swap_kb / 1024 ))MB em swap (limite ${SWAP_MYSQL_LIMITE_MB}MB): reiniciar o MySQL fora do expediente e depois a task do Debezium."$'\n'
fi

consecutivos=0; estado_anterior="ok"; ultimo_alerta=0
[ -r "$STATE_FILE" ] && . "$STATE_FILE"
agora="$(date +%s)"

enviar() {
    if [ "${DRY_RUN:-0}" = "1" ]; then
        log "DRY_RUN -> assunto='$1'"; printf '%s\n' "$2"
    else
        (cd "$PROJECT_DIR" && docker compose exec -T php php console/alertar_falha_cron.php "$1" "$2") >/dev/null 2>&1 || log "aviso: envio de e-mail falhou"
    fi
}

if [ -n "$problemas" ]; then
    consecutivos=$((consecutivos + 1))
    log "PROBLEMA ($consecutivos seguidas): $(printf '%s' "$problemas" | tr '\n' ' ')"
    if [ "$consecutivos" -ge 2 ] && { [ "$estado_anterior" != "problema" ] || [ $((agora - ultimo_alerta)) -ge "$COOLDOWN" ]; }; then
        enviar "[CRM] Problema no pipeline (Debezium/containers)" "O monitor de saude detectou problema em $consecutivos verificacoes seguidas:"$'\n\n'"$problemas"$'\n'"Verifique na VM: docker compose ps  e  curl localhost:8083/connectors/$CONNECTOR/status"
        ultimo_alerta="$agora"
    fi
    estado_anterior="problema"
else
    if [ "$estado_anterior" = "problema" ]; then
        log "recuperado."
        enviar "[CRM] Pipeline normalizado" "Debezium e containers voltaram ao normal."
    fi
    consecutivos=0; estado_anterior="ok"
fi

printf 'consecutivos=%s\nestado_anterior=%s\nultimo_alerta=%s\n' "$consecutivos" "$estado_anterior" "$ultimo_alerta" > "$STATE_FILE"
