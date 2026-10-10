#!/bin/bash
# Backup diario do MySQL do CRM (dump logico compactado, mantem os 5 mais recentes).
#
# O que entra: TODO o schema + dados, EXCETO o conteudo de stg_carga_canonica e
# cargas_linhas_raw (~5GB de staging/raw que se recria reprocessando os arquivos
# originais em storage/). A ESTRUTURA dessas duas tabelas entra no dump, entao
# um restore sobe o banco completo, so sem o historico de staging.
#
# --single-transaction: dump consistente sem travar escrita. Nao gera binlog,
# entao nao pressiona o Debezium. Rodar de madrugada mesmo assim.
#
# ATENCAO: o dump fica so NESTA VM (protege de erro/migration ruim, nao da perda
# da VM/disco). Copia pra fora depende do servidor SFTP dedicado - ver docs/RUNBOOK_SUPORTE.md.
#
# Restore: gunzip -c ARQUIVO.sql.gz | docker compose exec -T mysql sh -c \
#   'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -uroot "$MYSQL_DATABASE"'

set -Eeuo pipefail

PROJECT_DIR="/opt/crm-dev-docker"
BACKUP_DIR="${BACKUP_DIR:-/opt/crm-dev-docker/backups/mysql}"
KEEP="${KEEP:-5}"
LOG_PREFIX="[backup-mysql]"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX $*"; }

alertar_falha() {
    local linha=$1
    log "ERRO fatal na linha $linha - disparando alerta por email."
    rm -f "${PARCIAL:-/nonexistent}"
    cd "$PROJECT_DIR" && docker compose exec -T php php console/alertar_falha_cron.php \
        "[CRM] backup-mysql falhou" \
        "O backup diario do MySQL (backup-mysql.sh) falhou na linha ${linha}. Ver /var/log/backup-mysql.log. Backups anteriores foram mantidos." \
        >>/var/log/backup-mysql.log 2>&1 || true
}
trap 'alertar_falha $LINENO' ERR

cd "$PROJECT_DIR"
umask 077
mkdir -p "$BACKUP_DIR"

STAMP="$(date '+%Y%m%d-%H%M%S')"
FINAL="$BACKUP_DIR/crm-$STAMP.sql.gz"
PARCIAL="$FINAL.partial"

DUMP_BASE='MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --protocol=TCP -h127.0.0.1 -uroot --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4'
TABELAS_PESADAS='stg_carga_canonica cargas_linhas_raw'

log "iniciando dump -> $FINAL"
{
    docker compose exec -T mysql sh -c "$DUMP_BASE --single-transaction --routines --triggers --events --hex-blob \
        --ignore-table=\$MYSQL_DATABASE.stg_carga_canonica --ignore-table=\$MYSQL_DATABASE.cargas_linhas_raw \"\$MYSQL_DATABASE\""
    docker compose exec -T mysql sh -c "$DUMP_BASE --no-data \"\$MYSQL_DATABASE\" $TABELAS_PESADAS"
} | gzip -6 > "$PARCIAL"

gzip -t "$PARCIAL"
TAMANHO=$(stat -c %s "$PARCIAL")
if [ "$TAMANHO" -lt 1000000 ]; then
    log "ERRO: dump suspeitamente pequeno ($TAMANHO bytes)"
    false
fi
mv "$PARCIAL" "$FINAL"
log "OK $(basename "$FINAL") $((TAMANHO / 1024 / 1024))MB"

# Retencao: mantem so os $KEEP mais recentes.
ls -1t "$BACKUP_DIR"/crm-*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r antigo; do
    rm -f "$antigo"
    log "removido backup antigo $(basename "$antigo")"
done
