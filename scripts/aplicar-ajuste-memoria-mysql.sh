#!/usr/bin/env bash
# Aplica o ajuste de memoria do MySQL (2026-10-06) - rodar quando o administrador
# liberar a janela (o MySQL fica ~1 min fora do ar):
#   bash scripts/aplicar-ajuste-memoria-mysql.sh
#
# O que muda (ja esta no repositorio, so passa a valer ao recriar o container):
#  - docker-compose.yml: MALLOC_ARENA_MAX=2 (fragmentacao do malloc da glibc,
#    que fazia o mysqld crescer sem parar ate o swap);
#  - docker/mysql/conf.d/my.cnf: max_connections 80->40, table_open_cache
#    2000->512, innodb_log_buffer_size 64M->16M e historicos menores no
#    performance_schema. Buffer pool continua 1GB.
#
# Passos (para no primeiro problema):
#  1. valida o my.cnf num MySQL descartavel (sem rede) - config invalida nao
#     derruba o de producao;
#  2. recria SO o mysql (--no-deps) e espera ficar healthy;
#  3. reinicia a task do Debezium (todo restart do MySQL a derruba) e confere
#     RUNNING/RUNNING;
#  4. mede a memoria (scripts/medir-memoria-mysql.sh).
set -Eeuo pipefail
cd "$(dirname "$0")/.."

C=http://localhost:8083/connectors/crm-mysql-connector
if docker info >/dev/null 2>&1; then dc() { docker compose "$@"; }; dk() { docker "$@"; }; else dc() { sg docker -c "docker compose $(printf '%q ' "$@")"; }; dk() { sg docker -c "docker $(printf '%q ' "$@")"; }; fi
passo() { echo; echo "== $*"; }
falha() { echo; echo "PAROU: $*" >&2; exit 1; }
estado_debezium() { curl -fsS --max-time 10 "$C/status" | python3 -c "import sys,json; d=json.load(sys.stdin); t=d['tasks']; print(d['connector']['state']+':'+(t[0]['state'] if t else 'SEM_TASK'))"; }

passo "1. Validando a configuracao nova (MySQL descartavel, sem rede)"
dk run --rm --network none --entrypoint mysqld \
    -v "$PWD/docker/mysql/conf.d/my.cnf:/etc/mysql/my.cnf:ro" "mysql:${MYSQL_VERSION:-8.4}" \
    --defaults-file=/etc/mysql/my.cnf --enforce-gtid-consistency=ON --gtid-mode=ON --validate-config \
    || falha "my.cnf invalido - nada foi alterado no MySQL de producao."
echo "config OK"

passo "2. Recriando so o MySQL"
dc up -d --no-deps mysql
for _ in $(seq 1 60); do
    s=$(dk inspect -f '{{.State.Health.Status}}' crm_mysql 2>/dev/null || echo "?")
    [ "$s" = "healthy" ] && break
    sleep 3
done
[ "$s" = "healthy" ] || falha "MySQL nao ficou healthy (estado=$s). Ver: docker logs crm_mysql"
# Pelo docker inspect: o container nao deixa ler /proc/1/environ do mysqld
# (falso alarme na 1a aplicacao, 2026-10-06 - o ajuste tinha entrado).
dk inspect -f '{{range .Config.Env}}{{println .}}{{end}}' crm_mysql | grep -qx "MALLOC_ARENA_MAX=2" \
    && echo "MySQL healthy, MALLOC_ARENA_MAX=2 ativo" || falha "MySQL subiu sem MALLOC_ARENA_MAX=2"

passo "3. Debezium (o restart do MySQL derruba a task)"
sleep 10
curl -fsS --max-time 30 -X POST "$C/tasks/0/restart" >/dev/null || true
ok=0
for _ in $(seq 1 24); do
    sleep 5
    e=$(estado_debezium || echo "?:?")
    if [ "$e" = "RUNNING:RUNNING" ]; then ok=$((ok + 1)); else ok=0; fi
    [ "$ok" -ge 3 ] && break
done
[ "$ok" -ge 3 ] || falha "Debezium nao voltou a RUNNING/RUNNING (estado=$e). Ver: curl -s $C/status"
echo "Debezium RUNNING/RUNNING"

passo "4. Memoria do MySQL logo apos o ajuste"
bash scripts/medir-memoria-mysql.sh || true

echo
echo "OK - ajuste aplicado. Medir de novo em 1-2 dias (bash scripts/medir-memoria-mysql.sh)."
