#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

ENV_FILE="${ENV_FILE:-$PROJECT_DIR/.env.local}"
COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$PROJECT_DIR/docker-compose.yml")

echo "=== CRM Performance Snapshot ==="
date -Is || true
printf '\n[Host]\n'
echo "CPU(s): $(nproc 2>/dev/null || echo '?')"
uptime || true
free -h || true
printf '\nSwap:\n'
swapon --show || true

printf '\n[Containers]\n'
"${COMPOSE[@]}" ps || true
printf '\n[Docker stats]\n'
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.PIDs}}' || true

printf '\n[MySQL status]\n'
"${COMPOSE[@]}" exec -T mysql sh -lc '
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "
    SHOW GLOBAL STATUS WHERE Variable_name IN (
      '\''Threads_connected'\'',
      '\''Threads_running'\'',
      '\''Max_used_connections'\'',
      '\''Connections'\'',
      '\''Aborted_connects'\'',
      '\''Slow_queries'\'',
      '\''Questions'\'',
      '\''Innodb_buffer_pool_reads'\'',
      '\''Innodb_buffer_pool_read_requests'\''
    );
  "
' || true

printf '\n[Redis cache]\n'
"${COMPOSE[@]}" exec -T redis-cache sh -lc 'redis-cli -a "$REDIS_CACHE_PASSWORD" INFO memory 2>/dev/null | grep -E "^(used_memory_human|maxmemory_human|mem_fragmentation_ratio):"' || true

printf '\n[Redis queue]\n'
"${COMPOSE[@]}" exec -T redis-queue sh -lc 'redis-cli -a "$REDIS_QUEUE_PASSWORD" LLEN crm:cdc:retry 2>/dev/null; redis-cli -a "$REDIS_QUEUE_PASSWORD" LLEN crm:cdc:dead 2>/dev/null' || true

printf '\n[Integration Worker heartbeat]\n'
"${COMPOSE[@]}" exec -T integration-worker sh -lc 'cat /var/www/storage/runtime/integration-worker-heartbeat.json 2>/dev/null || true' || true

printf '\n[ACME recent latency]\n'
if [[ -f storage/logs/integracoes_acme.jsonl ]]; then
  tail -n 200 storage/logs/integracoes_acme.jsonl \
    | grep -o '"duracao_ms":[0-9]*' \
    | tail -n 30 \
    | sed 's/[^0-9]//g' \
    | awk 'BEGIN{n=0;s=0;max=0} {n++;s+=$1;if($1>max)max=$1} END{if(n>0) printf "amostras=%d media_ms=%.0f max_ms=%d\n",n,s/n,max; else print "sem amostras"}'
else
  echo "log ACME ainda nao encontrado"
fi
