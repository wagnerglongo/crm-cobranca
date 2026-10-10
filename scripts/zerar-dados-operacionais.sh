#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$PROJECT_DIR/.env.local}"
COMPOSE_FILE="${COMPOSE_FILE:-$PROJECT_DIR/docker-compose.yml}"
CONFIRMATION="${1:-}"

cd "$PROJECT_DIR"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "ERRO: arquivo de ambiente nao encontrado: $ENV_FILE" >&2
    exit 1
fi

if [[ ! -f "$COMPOSE_FILE" ]]; then
    echo "ERRO: arquivo Compose nao encontrado: $COMPOSE_FILE" >&2
    exit 1
fi

COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")
COMPOSE_WORKERS=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" --profile workers)

log() {
    printf '\n[%s] %s\n' "$(date '+%H:%M:%S')" "$*"
}

fail() {
    echo "ERRO: $*" >&2
    exit 1
}

env_name() {
    local key="$1"
    local fallback="$2"
    local value

    value="$(sed -n -E "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*(.*)[[:space:]]*$/\\1/p" "$ENV_FILE" | tail -n 1 | tr -d '\r')"
    value="${value#\"}"
    value="${value%\"}"
    value="${value#\'}"
    value="${value%\'}"
    value="${value:-$fallback}"

    [[ "$value" =~ ^[A-Za-z_][A-Za-z0-9_.-]*$ ]] || fail "valor invalido para $key."
    printf '%s' "$value"
}

env_csv() {
    local key="$1"
    local fallback="$2"
    local value

    value="$(sed -n -E "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*(.*)[[:space:]]*$/\\1/p" "$ENV_FILE" | tail -n 1 | tr -d '\r')"
    value="${value#\"}"
    value="${value%\"}"
    value="${value#\'}"
    value="${value%\'}"
    printf '%s' "${value:-$fallback}"
}

mysql_exec() {
    "${COMPOSE[@]}" exec -T mysql sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysql --protocol=TCP -h 127.0.0.1 -P 3306 -uroot \
            --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"
    '
}

on_error() {
    local line="$1"
    echo >&2
    echo "RESET INTERROMPIDO na linha $line." >&2
    echo "Por seguranca, confira o erro acima antes de religar Debezium e os workers." >&2
}

MYSQL_RESET_TABLES=(
    acordo_operacional_integracoes
    acordo_parcelas
    acordos
    acordos_operacionais
    acordos_operacionais_acompanhamento
    acordos_operacionais_auditoria
    acordos_operacionais_movimentos
    acordos_operacionais_parcelas
    acordos_operacionais_sincronizacoes
    atendimentos_historico
    auditoria_sistema
    baixas
    bloqueios_debito_conta
    boletos
    campanha_eventos
    campanha_itens
    campanhas
    estrategia_eventos
    estrategia_execucoes
    estrategia_usuarios
    estrategia_contratos
    estrategias
    cargas_erros
    cargas_linhas_raw
    cargas_arquivos
    contrato_parcelas
    contrato_veiculos
    contratos
    integracao_acionamentos_externos
    integracao_arquivos_itens
    integracao_arquivos_lotes
    integracao_consultas_estado
    integracao_eventos_tentativas
    integracao_eventos_saida
    operacao_cliente_historico_eventos
    operacao_cliente_reservas
    operacao_fila_movimentos
    pagamentos
    pessoa_contatos
    pessoa_enderecos
    pessoas
    promessas_pagamento
    quebras_acordo
    stg_carga_canonica
    usuario_auditoria
)

echo "RESET OPERACIONAL DO CRM"
echo
echo "Sera removido:"
echo "  - cargas, linhas raw, staging e erros de importacao;"
echo "  - pessoas, contatos, enderecos, contratos e parcelas;"
echo "  - atendimentos, campanhas, acordos e historicos operacionais;"
echo "  - projecoes do OpenSearch e ClickHouse;"
echo "  - cache/filas Redis, topicos CDC da aplicacao e arquivos de carga."
echo
echo "Sera preservado:"
echo "  - migrations e estrutura do banco;"
echo "  - usuarios, perfis e permissoes;"
echo "  - credores, carteiras, layouts, regras e configuracoes;"
echo "  - volumes e configuracao dos containers."
echo
echo "ATENCAO: esta operacao e irreversivel e nao cria backup."

if [[ "$CONFIRMATION" != "--confirmar-zerar" ]]; then
    if [[ ! -t 0 ]]; then
        fail "execute em um terminal ou informe --confirmar-zerar."
    fi
    read -r -p "Digite exatamente ZERAR para continuar: " answer
    [[ "$answer" == "ZERAR" ]] || fail "confirmacao nao recebida; nada foi removido."
fi

trap 'on_error "$LINENO"' ERR

log "Validando Docker Compose e servicos essenciais"
"${COMPOSE[@]}" config -q
for service in mysql redis-cache redis-queue opensearch clickhouse redpanda; do
    container_id="$("${COMPOSE[@]}" ps -q "$service")"
    [[ -n "$container_id" ]] || fail "servico $service nao esta criado. Rode ./instalar-vm.sh antes do reset."
done

log "Liberando somente cache de build e imagens Docker sem uso"
docker builder prune -af
docker image prune -f

log "Bloqueando novas gravacoes e pausando o fluxo CDC"
"${COMPOSE[@]}" stop -t 30 apache
"${COMPOSE[@]}" stop -t 60 integration-worker cdc-worker cdc-retry-worker || true
"${COMPOSE[@]}" stop -t 60 debezium

log "Removendo os arquivos recebidos/processados em storage/cargas"
CARGAS_DIR="$PROJECT_DIR/storage/cargas"
mkdir -p "$CARGAS_DIR"
find "$CARGAS_DIR" -mindepth 1 -type f ! -name '.gitkeep' -delete
find "$CARGAS_DIR" -mindepth 1 -depth -type d -empty -delete

log "Removendo arquivos operacionais de integracoes externas"
INTEGRACOES_SAIDA_DIR="$PROJECT_DIR/storage/integracoes/saida"
mkdir -p "$INTEGRACOES_SAIDA_DIR"
find "$INTEGRACOES_SAIDA_DIR" -mindepth 1 -type f ! -name '.gitkeep' -delete
find "$INTEGRACOES_SAIDA_DIR" -mindepth 1 -depth -type d -empty -delete

log "Zerando somente as tabelas operacionais no MySQL"
table_filter=""
for table in "${MYSQL_RESET_TABLES[@]}"; do
    [[ "$table" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || fail "nome de tabela invalido: $table"
    if [[ -n "$table_filter" ]]; then
        table_filter+=","
    fi
    table_filter+="'$table'"
done

existing_tables="$(
    printf "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s) ORDER BY TABLE_NAME;\n" "$table_filter" \
        | mysql_exec
)"
[[ -n "$existing_tables" ]] || fail "nenhuma tabela operacional foi encontrada no banco configurado."

{
    echo 'SET FOREIGN_KEY_CHECKS=0;'
    while IFS= read -r table; do
        [[ "$table" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || fail "tabela retornada pelo MySQL e invalida: $table"
        printf 'TRUNCATE TABLE `%s`;\n' "$table"
    done <<< "$existing_tables"
    echo 'SET FOREIGN_KEY_CHECKS=1;'
} | mysql_exec

OPENSEARCH_INDICES=(
    "$(env_name OPENSEARCH_CONTRATOS_INDEX crm_contratos_v1)"
    "$(env_name OPENSEARCH_CLIENTES_INDEX crm_clientes_v1)"
    "$(env_name OPENSEARCH_ACORDOS_INDEX crm_acordos_v1)"
    "$(env_name OPENSEARCH_PREVENTIVA_INDEX crm_preventiva_v1)"
    "$(env_name OPENSEARCH_ACIONAMENTOS_INDEX crm_acionamentos_v1)"
    "$(env_name OPENSEARCH_ESTRATEGIAS_INDEX crm_estrategias_resultados_v1)"
)
CLICKHOUSE_DB_NAME="$(env_name CLICKHOUSE_DB crm_analytics_dev)"
MYSQL_DB_NAME="$(env_name MYSQL_DATABASE crm_dev)"
TOPIC_PREFIX="$(env_name DEBEZIUM_TOPIC_PREFIX crm.mysql)"
CDC_GROUP="$(env_name CDC_CONSUMER_GROUP crm-cdc-projection-v1)"
CDC_TABLES="$(env_csv DEBEZIUM_TABLE_INCLUDE_LIST 'pessoas,pessoa_contatos,pessoa_enderecos,contratos,contrato_parcelas,atendimentos_historico,atendimento_resultados,campanhas,campanha_itens,estrategias,estrategia_contratos,acordos_operacionais,acordos_operacionais_parcelas,acordos_operacionais_acompanhamento,acordo_regras_carencia,usuarios,pagamentos,boletos,baixas,cargas_arquivos')"

log "Zerando os indices operacionais no OpenSearch"
for opensearch_index in "${OPENSEARCH_INDICES[@]}"; do
    [[ "$opensearch_index" =~ ^[A-Za-z0-9_.-]+$ ]] || fail "indice OpenSearch invalido: $opensearch_index"
    http_code="$("${COMPOSE[@]}" exec -T opensearch curl -sS -o /dev/null -w '%{http_code}' -X DELETE "http://127.0.0.1:9200/$opensearch_index")"
    [[ "$http_code" == "200" || "$http_code" == "404" ]] \
        || fail "OpenSearch recusou a remocao do indice $opensearch_index (HTTP $http_code)."
done

log "Zerando os eventos analiticos no ClickHouse"
"${COMPOSE[@]}" exec -T clickhouse sh -ec '
    exec clickhouse-client --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" \
        --max_threads 1 --max_memory_usage 134217728 \
        --query "TRUNCATE TABLE IF EXISTS '"$CLICKHOUSE_DB_NAME"'.cdc_eventos"
'

log "Zerando cache e filas Redis sem tocar no database de sessoes"
"${COMPOSE[@]}" exec -T redis-cache sh -ec '
    export REDISCLI_AUTH="$REDIS_CACHE_PASSWORD"
    exec redis-cli -n 0 FLUSHDB
'
"${COMPOSE[@]}" exec -T redis-queue sh -ec '
    export REDISCLI_AUTH="$REDIS_QUEUE_PASSWORD"
    exec redis-cli -n 0 FLUSHDB
'

log "Removendo apenas os topicos CDC da aplicacao e o offset do worker"
"${COMPOSE[@]}" exec -T redpanda rpk cluster info --brokers redpanda:9092 >/dev/null
existing_topics="$("${COMPOSE[@]}" exec -T redpanda rpk topic list --brokers redpanda:9092 | awk 'NR > 1 {print $1}')"
old_ifs="$IFS"
IFS=','
for table in $CDC_TABLES; do
    table="$(printf '%s' "$table" | tr -d '[:space:]')"
    [[ "$table" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || fail "tabela invalida em DEBEZIUM_TABLE_INCLUDE_LIST: $table"
    topic="$TOPIC_PREFIX.$MYSQL_DB_NAME.$table"
    if grep -Fqx "$topic" <<< "$existing_topics"; then
        "${COMPOSE[@]}" exec -T redpanda rpk topic delete "$topic" --brokers redpanda:9092
    fi
done
IFS="$old_ifs"

existing_groups="$("${COMPOSE[@]}" exec -T redpanda rpk group list --brokers redpanda:9092 | awk 'NR > 1 {print $1}')"
if grep -Fqx "$CDC_GROUP" <<< "$existing_groups"; then
    "${COMPOSE[@]}" exec -T redpanda rpk group delete "$CDC_GROUP" --brokers redpanda:9092
fi

log "Recriando estruturas vazias e religando a aplicacao"
"${COMPOSE[@]}" up -d --wait --wait-timeout 300 mysql redis-cache redis-queue opensearch clickhouse redpanda php debezium
"${COMPOSE[@]}" run --rm --no-deps debezium-register
"${COMPOSE[@]}" exec -T php php console/infra_inicializar.php --reindex --batch=100 --attempts=12 --interval=5
"${COMPOSE[@]}" up -d --force-recreate --wait --wait-timeout 300 integration-worker cdc-worker cdc-retry-worker
"${COMPOSE[@]}" up -d --wait --wait-timeout 300 apache opensearch-dashboards

log "Validando que os dados operacionais ficaram zerados"
mysql_exec <<'SQL'
SELECT CONCAT(
    'MySQL: cargas=', (SELECT COUNT(*) FROM cargas_arquivos),
    ' pessoas=', (SELECT COUNT(*) FROM pessoas),
    ' contratos=', (SELECT COUNT(*) FROM contratos),
    ' atendimentos=', (SELECT COUNT(*) FROM atendimentos_historico),
    ' acordos=', (SELECT COUNT(*) FROM acordos_operacionais)
);
SELECT CONCAT(
    'Preservados: usuarios=', (SELECT COUNT(*) FROM usuarios),
    ' credores=', (SELECT COUNT(*) FROM credores),
    ' layouts=', (SELECT COUNT(*) FROM carga_layouts),
    ' migrations=', (SELECT COUNT(*) FROM crm_schema_migrations)
);
SQL

for opensearch_index in "${OPENSEARCH_INDICES[@]}"; do
    "${COMPOSE[@]}" exec -T opensearch curl -fsS "http://127.0.0.1:9200/$opensearch_index/_count"
    echo
done
"${COMPOSE[@]}" exec -T clickhouse sh -ec '
    exec clickhouse-client --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" \
        --max_threads 1 --max_memory_usage 134217728 \
        --query "SELECT concat('\''ClickHouse: eventos='\'', toString(ifNull(total_rows, 0))) FROM system.tables WHERE database = '\''"$CLICKHOUSE_DB_NAME"'\'' AND name = '\''cdc_eventos'\'' LIMIT 1"
'
"${COMPOSE[@]}" exec -T redis-cache sh -ec 'REDISCLI_AUTH="$REDIS_CACHE_PASSWORD" redis-cli -n 0 DBSIZE'
"${COMPOSE[@]}" exec -T redis-queue sh -ec 'REDISCLI_AUTH="$REDIS_QUEUE_PASSWORD" redis-cli -n 0 DBSIZE'

log "Reset concluido: estrutura e configuracoes preservadas; sistema vazio e pronto"
df -h "$PROJECT_DIR"
"${COMPOSE_WORKERS[@]}" ps -a
