#!/usr/bin/env bash
# Diagnostico do MySQL crescendo ate o swap (plano de 2026-10-05). So leitura.
#   bash scripts/medir-memoria-mysql.sh
#
# Compara a memoria REAL do processo (RSS + swap) com o que o proprio MySQL
# contabiliza (sys.memory_global_total). Se a real passa muito da
# contabilizada, a sobra esta fora do MySQL = fragmentacao do malloc da glibc
# (padrao conhecido com cargas grandes) -> correcao: MALLOC_ARENA_MAX=2 no
# environment do mysql no docker-compose.yml + recriar o container.
# Baseline logo apos o restart (05/10 17:00): RSS 635MB, swap 0, contabilizado 1,42GiB.
set -Eeuo pipefail
cd "$(dirname "$0")/.."

if docker info >/dev/null 2>&1; then dc() { docker compose "$@"; }; else dc() { sg docker -c "docker compose $(printf '%q ' "$@")"; }; fi
sql() { dc exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -uroot -N -B -e "$1"' sh "$1" | tr -d '\r'; }

rss_kb=$(dc exec -T mysql sh -c "awk '/^VmRSS/{print \$2}' /proc/1/status" | tr -d '\r')
swap_kb=$(dc exec -T mysql sh -c "awk '/^VmSwap/{print \$2}' /proc/1/status" | tr -d '\r')
alocado=$(sql 'SELECT total_allocated FROM sys.x$memory_global_total')
uptime_s=$(sql "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Uptime'")

echo "MySQL no ar ha $(( uptime_s / 3600 ))h"
awk -v r="$rss_kb" -v s="$swap_kb" -v a="$alocado" 'BEGIN {
    real = (r + s) / 1024; cont = a / 1024 / 1024; razao = real / cont
    printf "Memoria real (RAM %.0fMB + swap %.0fMB) = %.0fMB\n", r/1024, s/1024, real
    printf "Contabilizada pelo MySQL                = %.0fMB\n", cont
    printf "Razao real/contabilizada                = %.2f\n\n", razao
    if (razao > 1.3) print "DIAGNOSTICO: sobra fora do MySQL -> fragmentacao do malloc. Correcao: MALLOC_ARENA_MAX=2."
    else             print "DIAGNOSTICO: crescimento contabilizado pelo proprio MySQL -> ver maiores consumidores abaixo."
}'
echo
echo "Maiores consumidores (sys.memory_global_by_current_bytes):"
sql 'SELECT event_name, current_alloc FROM sys.memory_global_by_current_bytes LIMIT 6'
