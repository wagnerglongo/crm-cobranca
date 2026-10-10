#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env.local"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.yml"
CLEANUP_SQL="$PROJECT_DIR/docker/clickhouse/cleanup-low-memory.sql"

[[ -f "$ENV_FILE" ]] || { echo "ERRO: $ENV_FILE nao encontrado." >&2; exit 1; }
[[ -f "$CLEANUP_SQL" ]] || { echo "ERRO: $CLEANUP_SQL nao encontrado." >&2; exit 1; }

cd "$PROJECT_DIR"
COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")

echo "Pausando workers CDC..."
for container in crm_cdc_worker crm_cdc_retry_worker; do
    if [[ "$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null || echo false)" == "true" ]]; then
        docker stop --time 60 "$container" >/dev/null
    fi
done

echo "Recriando ClickHouse com o perfil de baixa memoria..."
"${COMPOSE[@]}" up -d --force-recreate clickhouse

deadline=$((SECONDS + 300))
while (( SECONDS < deadline )); do
    state="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' crm_clickhouse 2>/dev/null || true)"
    if [[ "$state" == "healthy" ]]; then
        break
    fi
    if [[ "$state" == "exited" || "$state" == "dead" ]]; then
        docker logs --tail 150 crm_clickhouse >&2 || true
        echo "ERRO: ClickHouse parou durante a recuperacao." >&2
        exit 1
    fi
    sleep 3
done

[[ "${state:-}" == "healthy" ]] || { echo "ERRO: ClickHouse nao ficou healthy em 300 segundos." >&2; exit 1; }

echo "Removendo somente tabelas antigas de logs internos..."
cleanup_ok=false
for attempt in {1..12}; do
    if "${COMPOSE[@]}" exec -T clickhouse sh -lc \
        'clickhouse-client --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" --multiquery' \
        < "$CLEANUP_SQL"; then
        cleanup_ok=true
        break
    fi
    if (( attempt < 12 )); then
        echo "Aguardando memoria do ClickHouse para limpeza (tentativa $((attempt + 1))/12)..."
    fi
    sleep 5
done

[[ "$cleanup_ok" == "true" ]] || { echo "ERRO: nao foi possivel remover os logs internos antigos." >&2; exit 1; }

echo "Validando ClickHouse..."
"${COMPOSE[@]}" exec -T clickhouse sh -lc \
    'clickhouse-client --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" --query "SELECT 1"' \
    >/dev/null

echo "Recriando workers com processamento em lotes..."
"${COMPOSE[@]}" up -d --force-recreate cdc-worker cdc-retry-worker

for container in crm_cdc_worker crm_cdc_retry_worker; do
    deadline=$((SECONDS + 240))
    state=""
    while (( SECONDS < deadline )); do
        state="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || true)"
        [[ "$state" == "healthy" ]] && break
        if [[ "$state" == "exited" || "$state" == "dead" ]]; then
            docker logs --tail 150 "$container" >&2 || true
            echo "ERRO: $container parou durante a recuperacao." >&2
            exit 1
        fi
        sleep 3
    done
    [[ "$state" == "healthy" ]] || { echo "ERRO: $container nao ficou healthy em 240 segundos." >&2; exit 1; }
done

echo "Recuperacao concluida. Nenhuma tabela do CRM ou volume foi apagado."
