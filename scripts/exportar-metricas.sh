#!/usr/bin/env bash
# Saida pro Grafana (requisito de 2026-10-06): gera as metricas do CRM no
# formato Prometheus em storage/metrics/crm.prom, servido (com token) em
#   http(s)://<host>/metrics.php   (Authorization: Bearer <storage/metrics/token>)
# Ver docs/integracoes/grafana/README.md.
#
# Roda a cada minuto pelo crontab do usuario works (instalado em 2026-10-06,
# sem precisar de sudo):  * * * * * sg docker -c ".../exportar-metricas.sh"
# Como root le o backup direto da pasta; sem root, pelo log do backup.
# Cada bloco e independente: se um falhar (ex.: OpenSearch fora do ar), os
# outros saem normalmente e o bloco que falhou vira metrica "_ok 0".
set -u

# Uma execucao por vez (root via /etc/cron.d e/ou crontab do works): trava no
# proprio script, que os dois usuarios conseguem abrir.
exec 9<"$0"
flock -n 9 || exit 0

PROJECT_DIR="${PROJECT_DIR:-/opt/crm-dev-docker}"
DESTINO_DIR="${DESTINO_DIR:-$PROJECT_DIR/storage/metrics}"
CONECTOR="crm-mysql-connector"
INICIO=$(date +%s)

mkdir -p "$DESTINO_DIR"
TMP="$(mktemp "$DESTINO_DIR/.crm.prom.XXXXXX")"
trap 'rm -f "$TMP"' EXIT

# Token do endpoint: gerado uma vez; legivel pelo PHP (uid 1000).
if [ ! -s "$DESTINO_DIR/token" ]; then
    head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$DESTINO_DIR/token"
    chmod 644 "$DESTINO_DIR/token"
fi

declare -A DECLARADA=()
# metrica <nome> <tipo> <ajuda> <valor> [labels]
metrica() {
    local nome=$1 tipo=$2 ajuda=$3 valor=$4 labels=${5:-}
    if [ -z "${DECLARADA[$nome]:-}" ]; then
        printf '# HELP %s %s\n# TYPE %s %s\n' "$nome" "$ajuda" "$nome" "$tipo" >> "$TMP"
        DECLARADA[$nome]=1
    fi
    if [ -n "$labels" ]; then
        printf '%s{%s} %s\n' "$nome" "$labels" "$valor" >> "$TMP"
    else
        printf '%s %s\n' "$nome" "$valor" >> "$TMP"
    fi
}
bloco_ok() { metrica crm_coletor_bloco_ok gauge "1 = o bloco do coletor funcionou" "$2" "bloco=\"$1\""; }
sql() { docker exec -i crm_mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -uroot -N -B "$MYSQL_DATABASE"' 2>/dev/null; }

coletar_host() {
    local uso mem_disp swap_total swap_livre load
    uso=$(df -P / | awk 'NR==2 {gsub("%","",$5); print $5}')
    mem_disp=$(awk '/^MemAvailable:/ {print $2*1024}' /proc/meminfo)
    swap_total=$(awk '/^SwapTotal:/ {print $2*1024}' /proc/meminfo)
    swap_livre=$(awk '/^SwapFree:/ {print $2*1024}' /proc/meminfo)
    load=$(cut -d' ' -f1 /proc/loadavg)
    metrica crm_host_disco_uso_percentual gauge "Uso do disco / da VM (%)" "$uso"
    metrica crm_host_memoria_disponivel_bytes gauge "Memoria disponivel na VM" "$mem_disp"
    metrica crm_host_swap_usado_bytes gauge "Swap em uso na VM" "$((swap_total - swap_livre))"
    metrica crm_host_load1 gauge "Load average de 1 minuto" "$load"
}

coletar_containers() {
    local linhas nome estado status saude reinicios
    linhas=$(docker ps -a --filter name=crm_ --format '{{.Names}}|{{.State}}|{{.Status}}') || return 1
    while IFS='|' read -r nome estado status; do
        [ -z "$nome" ] || [ "$nome" = "crm_debezium_register" ] && continue
        metrica crm_container_rodando gauge "1 = container rodando" "$([ "$estado" = running ] && echo 1 || echo 0)" "container=\"$nome\""
        case "$status" in
            *"(healthy)"*) saude=1 ;;
            *"(unhealthy)"*|*"(health: starting)"*) saude=0 ;;
            *) saude="" ;;
        esac
        [ -n "$saude" ] && metrica crm_container_saudavel gauge "1 = healthcheck do container ok" "$saude" "container=\"$nome\""
        reinicios=$(docker inspect -f '{{.RestartCount}}' "$nome" 2>/dev/null || echo 0)
        metrica crm_container_reinicios gauge "Reinicios automaticos do container" "$reinicios" "container=\"$nome\""
    done <<< "$linhas"
}

coletar_debezium() {
    local json conector task ts
    json=$(curl -fsS --max-time 5 "http://localhost:8083/connectors/$CONECTOR/status") || return 1
    conector=$(python3 -c "import sys,json; print(json.load(sys.stdin)['connector']['state'])" <<< "$json")
    task=$(python3 -c "import sys,json; t=json.load(sys.stdin)['tasks']; print(t[0]['state'] if t else 'SEM_TASK')" <<< "$json")
    metrica crm_debezium_conector_rodando gauge "1 = conector do Debezium RUNNING" "$([ "$conector" = RUNNING ] && echo 1 || echo 0)"
    metrica crm_debezium_task_rodando gauge "1 = task do Debezium RUNNING (CDC funcionando)" "$([ "$task" = RUNNING ] && echo 1 || echo 0)"
    ts=$(curl -fsS --max-time 5 "http://localhost:8083/connectors/$CONECTOR/offsets" \
        | python3 -c "import sys,json; print(json.load(sys.stdin)['offsets'][0]['offset']['ts_sec'])" 2>/dev/null) \
        && metrica crm_debezium_offset_timestamp_segundos gauge "Hora do ultimo evento confirmado pelo Debezium" "$ts"
    return 0
}

coletar_cdc_lag() {
    local lag
    lag=$(docker exec crm_redpanda rpk group describe crm-cdc-projection-v1 2>/dev/null \
        | awk '$1 ~ /^crm\.mysql\./ && $6 ~ /^[0-9]+$/ {s+=$6} END {print s+0}') || return 1
    metrica crm_cdc_lag_mensagens gauge "Mensagens do CDC ainda nao projetadas (OpenSearch/ClickHouse atrasado)" "$lag"
}

coletar_opensearch() {
    local status valor
    status=$(curl -fsS --max-time 5 http://localhost:9200/_cluster/health | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])") || return 1
    case "$status" in green) valor=2 ;; yellow) valor=1 ;; *) valor=0 ;; esac
    metrica crm_opensearch_status gauge "Status do OpenSearch (2 verde, 1 amarelo, 0 vermelho)" "$valor"
    curl -fsS --max-time 5 'http://localhost:9200/_cat/indices/crm_*?h=index,docs.count' 2>/dev/null | while read -r indice docs; do
        [ -n "$indice" ] && metrica crm_opensearch_documentos gauge "Documentos por indice do OpenSearch" "$docs" "indice=\"$indice\""
    done
    return 0
}

coletar_mysql() {
    local saida rss swap
    saida=$(sql <<'SQL'
SELECT 'conexoes', VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Threads_connected';
SELECT 'contratos', COUNT(*) FROM contratos;
SELECT 'pessoas', COUNT(*) FROM pessoas;
SELECT 'acordos', COUNT(*) FROM acordos_operacionais;
SELECT 'carga_ultima_aplicada', COALESCE(UNIX_TIMESTAMP(MAX(aplicacao_finalizada_em)), 0) FROM cargas_arquivos;
SELECT 'acionamentos_hoje', COUNT(*) FROM atendimentos_historico WHERE criado_em >= CURDATE();
SELECT 'acordos_hoje', COUNT(*) FROM acordos_operacionais WHERE criado_em >= CURDATE();
SELECT CONCAT('integracao|', ci.codigo, '|', ies.status), COUNT(*) FROM integracao_eventos_saida ies INNER JOIN credor_integracoes ci ON ci.id = ies.integracao_id GROUP BY ci.codigo, ies.status;
SELECT CONCAT('integracao_ultimo_envio|', ci.codigo), COALESCE(UNIX_TIMESTAMP(MAX(ies.enviado_em)), 0) FROM integracao_eventos_saida ies INNER JOIN credor_integracoes ci ON ci.id = ies.integracao_id GROUP BY ci.codigo;
SQL
) || return 1
    [ -n "$saida" ] || return 1
    metrica crm_mysql_rodando gauge "1 = MySQL respondendo" 1
    while IFS=$'\t' read -r chave valor; do
        case "$chave" in
            conexoes) metrica crm_mysql_conexoes gauge "Conexoes abertas no MySQL" "$valor" ;;
            contratos|pessoas|acordos) metrica crm_mysql_registros gauge "Registros no MySQL (comparar com crm_opensearch_documentos)" "$valor" "entidade=\"$chave\"" ;;
            carga_ultima_aplicada) metrica crm_carga_ultima_aplicada_timestamp_segundos gauge "Fim da ultima carga aplicada (ACME diaria)" "$valor" ;;
            acionamentos_hoje) metrica crm_acionamentos_hoje gauge "Acionamentos registrados hoje" "$valor" ;;
            acordos_hoje) metrica crm_acordos_criados_hoje gauge "Acordos criados hoje" "$valor" ;;
            integracao\|*) IFS='|' read -r _ codigo status <<< "$chave"
                metrica crm_integracao_eventos gauge "Eventos da fila de saida pro credor por status" "$valor" "integracao=\"$codigo\",status=\"$status\"" ;;
            integracao_ultimo_envio\|*) metrica crm_integracao_ultimo_envio_timestamp_segundos gauge "Ultimo envio com sucesso pro credor" "$valor" "integracao=\"${chave#*|}\"" ;;
        esac
    done <<< "$saida"
    rss=$(docker exec crm_mysql awk '/^VmRSS/ {print $2*1024}' /proc/1/status 2>/dev/null || echo 0)
    swap=$(docker exec crm_mysql awk '/^VmSwap/ {print $2*1024}' /proc/1/status 2>/dev/null || echo 0)
    metrica crm_mysql_memoria_rss_bytes gauge "Memoria RAM do processo do MySQL" "$rss"
    metrica crm_mysql_swap_bytes gauge "Memoria do MySQL em swap (alerta acima de ~800MB)" "$swap"
}

coletar_backup() {
    local ultimo linha quando tamanho
    ultimo=$(ls -1t "$PROJECT_DIR"/backups/mysql/*.sql.gz 2>/dev/null | head -1)
    if [ -n "$ultimo" ]; then
        metrica crm_backup_ultimo_timestamp_segundos gauge "Hora do backup mais recente do MySQL" "$(stat -c %Y "$ultimo")"
        metrica crm_backup_ultimo_tamanho_bytes gauge "Tamanho do backup mais recente" "$(stat -c %s "$ultimo")"
        return 0
    fi
    # Sem root a pasta de backups (700) nao abre: usa a ultima linha de
    # sucesso do log do backup ("2026-10-06 03:30:35 [backup-mysql] OK crm-....sql.gz 42MB").
    linha=$(grep ' OK crm-.*\.sql\.gz' /var/log/backup-mysql.log 2>/dev/null | tail -1)
    [ -n "$linha" ] || return 1
    quando=$(date -d "$(cut -c1-19 <<< "$linha")" +%s) || return 1
    tamanho=$(grep -oE '[0-9]+MB$' <<< "$linha" | tr -d 'MB')
    metrica crm_backup_ultimo_timestamp_segundos gauge "Hora do backup mais recente do MySQL" "$quando"
    [ -n "$tamanho" ] && metrica crm_backup_ultimo_tamanho_bytes gauge "Tamanho do backup mais recente" "$((tamanho * 1024 * 1024))"
    return 0
}

coletar_rotinas() {
    local rotina arquivo
    for rotina in backup-mysql purge-binlog-seguro verificar-disco limpar-staging-antigo executar-rotinas-carga atualizar-carteira-acordos; do
        arquivo="/var/log/$rotina.log"
        [ -f "$arquivo" ] && metrica crm_rotina_ultimo_log_timestamp_segundos gauge "Ultima escrita no log de cada rotina agendada (cron)" "$(stat -c %Y "$arquivo")" "rotina=\"$rotina\""
    done
    return 0
}

for bloco in host containers debezium cdc_lag opensearch mysql backup rotinas; do
    if "coletar_$bloco"; then bloco_ok "$bloco" 1; else bloco_ok "$bloco" 0; fi
done

metrica crm_coletor_duracao_segundos gauge "Duracao da coleta" "$(( $(date +%s) - INICIO ))"
metrica crm_coletor_execucao_timestamp_segundos gauge "Hora da ultima coleta (coletor parado se ficar velho)" "$(date +%s)"

chmod 644 "$TMP"
mv -f "$TMP" "$DESTINO_DIR/crm.prom"
trap - EXIT
