#!/usr/bin/env bash
# Mantem o banco crm_test (usado SO pela suite PHPUnit - ver app/phpunit.xml).
#
# Achado 2026-10-05: os testes rodavam no crm_dev de producao. Ja tinham
# desmarcado config real (ACME_002 em 01/10), o worker de integracao vivo
# pegava eventos criados por teste, e a suite disputava memoria/IO com a
# operacao. O crm_test fica no mesmo MySQL, mas fora do Debezium (que so
# observa crm_dev) e fora dos workers.
#
# Recria o banco do zero (mesma ordem do scripts/migrate.sh, via
# scripts/ci/prepare-db.sh) somente quando o conjunto de migrations muda -
# nas outras vezes sai em ~1s. Chamado pelo pre-commit; pode rodar a mao.
#   --forcar   recria mesmo sem mudanca
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"
DB_TESTE=crm_test

# Container pelo nome fixo (nao "docker compose exec"): funciona tambem a
# partir da copia de homologacao (git worktree), que e outro projeto compose.
if docker info >/dev/null 2>&1; then dk() { docker "$@"; }; else dk() { sg docker -c "docker $(printf '%q ' "$@")"; }; fi
mysql_root() { dk exec -i crm_mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -uroot "$@"' mysql "$@"; }

assinatura="$(cat database/migrations/crm_mysql84_migrations/*.sql app/database/migrations/*.sql scripts/ci/prepare-db.sh | tr -d '\r' | sha256sum | cut -c1-64)"
atual="$(mysql_root -N -e "SELECT assinatura FROM \`$DB_TESTE\`.zz_banco_teste LIMIT 1" 2>/dev/null || true)"

if [[ "${1:-}" != "--forcar" && "$atual" == "$assinatura" ]]; then
    echo "[banco-teste] $DB_TESTE em dia."
    exit 0
fi

echo "[banco-teste] (re)criando $DB_TESTE..."
mysql_root -e "DROP DATABASE IF EXISTS \`$DB_TESTE\`; GRANT ALL PRIVILEGES ON \`$DB_TESTE\`.* TO 'crm_app'@'%';"

# prepare-db.sh roda dentro do container do MySQL (tem o cliente mysql e
# enxerga os arquivos via stdin de um tar).
tar -cf - scripts/ci/prepare-db.sh database/migrations/crm_mysql84_migrations app/database/migrations \
  | dk exec -i crm_mysql sh -c '
      set -e
      d=$(mktemp -d); cd "$d"; tar -xf -
      DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE='"$DB_TESTE"' DB_USERNAME=root DB_PASSWORD="$MYSQL_ROOT_PASSWORD" \
        bash scripts/ci/prepare-db.sh >/dev/null
      rm -rf "$d"'

mysql_root "$DB_TESTE" -e "CREATE TABLE zz_banco_teste (assinatura CHAR(64) NOT NULL); INSERT INTO zz_banco_teste VALUES ('$assinatura');"
echo "[banco-teste] $DB_TESTE pronto."
