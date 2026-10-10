#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env.local"
MODE="apply"

# O cliente MySQL ignora arquivos de configuração graváveis por todos. Corrige
# automaticamente a permissão que pode ter sido alterada durante uma cópia.
MYSQL_CONFIG_FILE="$PROJECT_DIR/docker/mysql/conf.d/my.cnf"
if [[ -f "$MYSQL_CONFIG_FILE" ]]; then
    chmod 0644 "$MYSQL_CONFIG_FILE" 2>/dev/null || true
fi

case "${1:-}" in
    "") ;;
    --adopt-existing) MODE="adopt" ;;
    --status) MODE="status" ;;
    -h|--help)
        echo "Uso: scripts/migrate.sh [--status|--adopt-existing]"
        echo "  sem opcao          aplica apenas migrations ainda nao registradas"
        echo "  --status            mostra migrations aplicadas e pendentes"
        echo "  --adopt-existing    registra o banco legado como baseline sem executar SQL"
        exit 0
        ;;
    *)
        echo "Opcao invalida: $1" >&2
        exit 2
        ;;
esac

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Arquivo .env.local nao encontrado em $PROJECT_DIR" >&2
    exit 1
fi

if docker info >/dev/null 2>&1; then
    DOCKER=(docker)
elif command -v sudo >/dev/null 2>&1 && sudo -n docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
else
    echo "Docker indisponivel ou usuario sem permissao para acessa-lo." >&2
    exit 1
fi

COMPOSE=("${DOCKER[@]}" compose --env-file "$ENV_FILE" -f "$PROJECT_DIR/docker-compose.yml")

mysql_stdin() {
    "${COMPOSE[@]}" exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot "$MYSQL_DATABASE"'
}

mysql_query() {
    printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}

show_innodb_diagnostics() {
    "${COMPOSE[@]}" exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot -e "SHOW ENGINE INNODB STATUS\\G"' \
        | sed -n '/LATEST FOREIGN KEY ERROR/,+45p' || true
}

if ! "${COMPOSE[@]}" ps --status running --services | grep -qx 'mysql'; then
    echo "O servico MySQL nao esta em execucao. Suba-o antes de aplicar migrations." >&2
    exit 1
fi

BASE_DIR="$PROJECT_DIR/database/migrations/crm_mysql84_migrations"
APP_MIGRATIONS_DIR="$PROJECT_DIR/app/database/migrations"
BASE_SCHEMA="$BASE_DIR/crm_schema_inicial_mysql84.sql"

if [[ ! -f "$BASE_SCHEMA" ]]; then
    echo "Schema inicial nao encontrado: $BASE_SCHEMA" >&2
    exit 1
fi

MIGRATIONS=("$BASE_SCHEMA")

for file in "$BASE_DIR"/[0-9][0-9][0-9]_*.sql; do
    [[ -e "$file" ]] || continue
    prefix="$(basename "$file")"
    prefix="${prefix%%_*}"
    if (( 10#$prefix >= 9 )); then
        MIGRATIONS+=("$file")
    fi
done

for file in "$APP_MIGRATIONS_DIR"/[0-9][0-9][0-9]_*.sql; do
    [[ -e "$file" ]] || continue
    MIGRATIONS+=("$file")
done

TRACKING_EXISTS="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'crm_schema_migrations';")"
CORE_EXISTS="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'credores';")"

mysql_query "
CREATE TABLE IF NOT EXISTS crm_schema_migrations (
    migration VARCHAR(255) NOT NULL,
    checksum CHAR(64) NOT NULL,
    adopted TINYINT(1) NOT NULL DEFAULT 0,
    execution_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
" >/dev/null

if [[ "$MODE" == "adopt" && "$CORE_EXISTS" != "1" ]]; then
    echo "Nao ha schema legado para registrar como baseline." >&2
    exit 3
fi

if [[ "$CORE_EXISTS" == "1" && "$TRACKING_EXISTS" == "0" && "$MODE" != "adopt" ]]; then
    cat >&2 <<'EOF'
Banco existente detectado sem historico automatico de migrations.
Por seguranca, nenhuma migration antiga foi reaplicada.

Se todas as migrations atuais ja foram executadas manualmente, rode:
  scripts/migrate.sh --adopt-existing

Depois, execute novamente:
  scripts/migrate.sh
EOF
    exit 3
fi

applied=0
pending=0

for file in "${MIGRATIONS[@]}"; do
    relative="${file#"$PROJECT_DIR"/}"
    # Ignora \r: as migrations 074-090 foram copiadas com CRLF (Windows) mas o checksum
    # registrado no banco e' do conteudo com LF - sem isso o script travava em "migration alterada".
    checksum="$(tr -d '\r' < "$file" | sha256sum | awk '{print $1}')"
    recorded="$(mysql_query "SELECT checksum FROM crm_schema_migrations WHERE migration = '$relative' LIMIT 1;")"

    if [[ -n "$recorded" ]]; then
        if [[ "$recorded" != "$checksum" ]]; then
            echo "A migration ja aplicada foi alterada: $relative" >&2
            echo "Checksum registrado: $recorded" >&2
            echo "Checksum atual:      $checksum" >&2
            exit 4
        fi
        ((applied += 1))
        continue
    fi

    ((pending += 1))

    if [[ "$MODE" == "status" ]]; then
        echo "PENDENTE  $relative"
        continue
    fi

    if [[ "$MODE" == "adopt" ]]; then
        mysql_query "INSERT INTO crm_schema_migrations (migration, checksum, adopted) VALUES ('$relative', '$checksum', 1);" >/dev/null
        echo "BASELINE  $relative"
        ((applied += 1))
        continue
    fi

    echo "APLICANDO $relative"
    started_at="$(date +%s)"
    if ! mysql_stdin < "$file"; then
        echo "ERRO: falha ao aplicar $relative" >&2
        show_innodb_diagnostics >&2
        exit 5
    fi
    finished_at="$(date +%s)"
    elapsed="$((finished_at - started_at))"
    mysql_query "INSERT INTO crm_schema_migrations (migration, checksum, adopted, execution_seconds) VALUES ('$relative', '$checksum', 0, $elapsed);" >/dev/null
    echo "OK        $relative (${elapsed}s)"
    ((applied += 1))
done

if [[ "$MODE" == "status" ]]; then
    echo "Aplicadas: $applied | Pendentes: $pending | Total: ${#MIGRATIONS[@]}"
elif [[ "$MODE" == "adopt" ]]; then
    echo "Baseline concluido. Migrations registradas: $applied"
else
    echo "Banco atualizado. Migrations registradas: $applied | Novas nesta execucao: $pending"
fi
