#!/bin/bash
# Alerta se o uso de disco da VM passar de um limite seguro. Ate 2026-09-17
# so existia proteção reativa especifica pro binlog (purge-binlog-seguro.sh)
# e checagem de espaço DENTRO das cargas grandes (DiskSpaceGuard) - nada
# monitorava o disco da VM de forma geral. Ja houve um incidente real de
# disco cheio em 2026-09-15.
set -euo pipefail

PROJECT_DIR="/opt/crm-dev-docker"
LIMITE_PERCENTUAL="${LIMITE_DISCO_PERCENTUAL:-85}"
LOG_PREFIX="[verificar-disco]"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX $*"; }

USO=$(df -B1 --output=pcent / | tail -1 | tr -dc '0-9')

if [ -z "$USO" ]; then
    log "ERRO: nao foi possivel ler o uso de disco de /."
    exit 1
fi

if [ "$USO" -ge "$LIMITE_PERCENTUAL" ]; then
    DISPONIVEL=$(df -h / | tail -1 | awk '{print $4}')
    log "ALERTA: disco em ${USO}% (limite ${LIMITE_PERCENTUAL}%) - ${DISPONIVEL} livre. Disparando alerta por email."
    cd "$PROJECT_DIR" && docker compose exec -T php php console/alertar_falha_cron.php \
        "[CRM] Disco da VM em ${USO}%" \
        "O disco da VM (partição /) está em ${USO}% de uso (limite configurado: ${LIMITE_PERCENTUAL}%), com ${DISPONIVEL} livres. Verificar antes que encha - já houve um incidente de disco cheio em 2026-09-15." \
        >>/var/log/verificar-disco.log 2>&1 || true
else
    log "OK - disco em ${USO}% (limite ${LIMITE_PERCENTUAL}%)."
fi
