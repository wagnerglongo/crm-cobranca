#!/usr/bin/env bash
# Aplica o schema num MySQL vazio na MESMA ordem de scripts/migrate.sh
# (baseline + 009+ de database/migrations + app/database/migrations).
# Usado pelo CI; nao registra crm_schema_migrations (banco descartavel).
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE_DIR="$PROJECT_DIR/database/migrations/crm_mysql84_migrations"
APP_DIR="$PROJECT_DIR/app/database/migrations"

: "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}" "${DB_DATABASE:=crm_dev}" "${DB_USERNAME:=root}"
export MYSQL_PWD="${DB_PASSWORD:-}"

run_sql() { mysql --protocol=TCP -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" "$@"; }

run_sql -e "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

files=("$BASE_DIR/crm_schema_inicial_mysql84.sql")
for f in "$BASE_DIR"/[0-9][0-9][0-9]_*.sql; do
    p="$(basename "$f")"; p="${p%%_*}"
    (( 10#$p >= 9 )) && files+=("$f")
done
for f in "$APP_DIR"/[0-9][0-9][0-9]_*.sql; do files+=("$f"); done

for f in "${files[@]}"; do
    echo "APLICANDO ${f#"$PROJECT_DIR"/}"
    run_sql "$DB_DATABASE" < "$f"
done
echo "Schema pronto: ${#files[@]} arquivos aplicados."
