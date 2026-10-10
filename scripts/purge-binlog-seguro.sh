#!/bin/bash
# Purga binlogs do MySQL ja replicados com seguranca pro Debezium.
#
# Diferente de confiar so em binlog_expire_logs_seconds (que so age por
# tempo, ex: "apague depois de N dias"), este script purga com base no
# estado REAL de consumo do conector Debezium. Isso importa porque uma
# unica carga grande pode gerar 10-20GB de binlog em poucas horas -
# tempo demais pra esperar uma expiracao por dias, e foi exatamente o que
# encheu o disco da VM em 2026-09-15 durante a aplicacao da carga #28.
#
# So purga quando:
#   1. O connector Debezium (crm-mysql-connector) esta com state=RUNNING
#      e a task tambem RUNNING.
#   2. A thread "Binlog Dump" do MySQL (a replicacao do Debezium) esta no
#      estado "Source has sent all binlog to replica; waiting for more
#      updates" - ou seja, 100% em dia, sem nada pendente de entrega.
# So entao purga tudo ANTES do arquivo onde esta o OFFSET SALVO do Debezium
# (GET /connectors/<conector>/offsets) - esse arquivo e os seguintes ficam.
#
# Incidente 2026-10-06: antes purgava tudo antes do binlog mais recente.
# Mas "Debezium em dia" (thread de dump esperando) NAO quer dizer que o
# offset salvo passou do arquivo: sem evento nas tabelas capturadas, o offset
# fica parado num binlog antigo. O purge apagou esse arquivo (mysql-bin.000195)
# e, no restart seguinte da task, o Debezium nao tinha mais de onde retomar.
#
# Pensado pra rodar via cron a cada 15-30 min. Idempotente: se nao houver
# nada seguro pra purgar, nao faz nada.

set -euo pipefail

PROJECT_DIR="/opt/crm-dev-docker"
ENV_FILE="$PROJECT_DIR/.env.local"
LOG_PREFIX="[purge-binlog-seguro]"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX $*"; }

# Achado 2026-09-17: os 3 crons do CRM so logavam falha no proprio log,
# ninguem era avisado. Este e' a protecao contra o incidente de disco de
# 2026-09-15 - se ele comecar a falhar (bug, permissao, etc.) e' critico
# saber na hora, nao so descobrir quando o disco enche de novo.
alertar_falha() {
    local linha=$1
    log "ERRO fatal na linha $linha - disparando alerta por email."
    cd "$PROJECT_DIR" && docker compose exec -T php php console/alertar_falha_cron.php \
        "[CRM] purge-binlog-seguro falhou" \
        "O script purge-binlog-seguro.sh (protecao contra disco cheio, cron a cada 15min) falhou na linha ${linha}. Ver /var/log/purge-binlog-seguro.log pra detalhes." \
        >>/var/log/purge-binlog-seguro.log 2>&1 || true
}
trap 'alertar_falha $LINENO' ERR

if [ ! -r "$ENV_FILE" ]; then
    log "ERRO: nao foi possivel ler $ENV_FILE"
    exit 1
fi

MYSQL_ROOT_PASSWORD=$(grep -m1 '^MYSQL_ROOT_PASSWORD=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '\r\n')
if [ -z "$MYSQL_ROOT_PASSWORD" ]; then
    log "ERRO: MYSQL_ROOT_PASSWORD nao encontrado em $ENV_FILE"
    exit 1
fi

cd "$PROJECT_DIR"

CONNECTOR_STATE=$(curl -fsS --max-time 5 http://localhost:8083/connectors/crm-mysql-connector/status 2>/dev/null \
    | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['connector']['state']+':'+d['tasks'][0]['state'])" 2>/dev/null || echo "erro:erro")

if [ "$CONNECTOR_STATE" != "RUNNING:RUNNING" ]; then
    log "Debezium nao esta RUNNING/RUNNING (estado=$CONNECTOR_STATE) - abortando, nao e seguro purgar."
    exit 0
fi

DUMP_STATE=$(docker compose exec -T mysql mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B \
    -e "SELECT state FROM information_schema.PROCESSLIST WHERE command LIKE '%Binlog%' LIMIT 1;" 2>/dev/null || echo "")

if [ "$DUMP_STATE" != "Source has sent all binlog to replica; waiting for more updates" ]; then
    log "Debezium ainda nao esta 100% em dia (estado='$DUMP_STATE') - abortando, nao e seguro purgar."
    exit 0
fi

BINLOGS=$(docker compose exec -T mysql mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B \
    -e "SHOW BINARY LOGS;" 2>/dev/null | awk '{print $1}')

if [ -z "$BINLOGS" ]; then
    log "ERRO: nao foi possivel listar os binlogs."
    exit 1
fi

OFFSET_BINLOG=$(curl -fsS --max-time 5 http://localhost:8083/connectors/crm-mysql-connector/offsets 2>/dev/null \
    | python3 -c "import sys,json; print(json.load(sys.stdin)['offsets'][0]['offset']['file'])" 2>/dev/null || echo "")

if [ -z "$OFFSET_BINLOG" ]; then
    log "Nao foi possivel ler o offset salvo do Debezium - abortando, nao e seguro purgar."
    exit 0
fi

if ! grep -qxF "$OFFSET_BINLOG" <<< "$BINLOGS"; then
    log "ATENCAO: o binlog do offset salvo do Debezium ($OFFSET_BINLOG) nao existe mais no servidor - abortando, nada a purgar com seguranca."
    exit 0
fi

BEFORE=$(df -B1 --output=avail / | tail -1)

docker compose exec -T mysql mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot -p"$MYSQL_ROOT_PASSWORD" \
    -e "PURGE BINARY LOGS TO '${OFFSET_BINLOG}';" 2>/dev/null

AFTER=$(df -B1 --output=avail / | tail -1)
FREED_MB=$(( (AFTER - BEFORE) / 1024 / 1024 ))

log "Purge OK. Binlogs anteriores a $OFFSET_BINLOG (arquivo do offset salvo do Debezium) removidos. Espaco liberado nesta execucao: ${FREED_MB}MB."
