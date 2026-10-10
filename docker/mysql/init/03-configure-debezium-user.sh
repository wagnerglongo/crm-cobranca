#!/bin/bash

set -Eeuo pipefail

db_user="${MYSQL_DEBEZIUM_USER:-debezium}"
db_password="${MYSQL_DEBEZIUM_PASSWORD:-debezium_dev_secret}"

if [[ ! "$db_user" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "MYSQL_DEBEZIUM_USER contem caracteres invalidos." >&2
    exit 1
fi

escaped_password="${db_password//\\/\\\\}"
escaped_password="${escaped_password//\'/\'\'}"

MYSQL_TRANSPORT=()

# Durante a instalacao normal o servidor esta disponivel por TCP. Durante a
# primeira criacao do volume, o entrypoint oficial inicia um MySQL temporario
# com rede desativada e somente o socket fica disponivel.
if MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin \
    --protocol=TCP -h127.0.0.1 -P3306 -uroot ping --silent >/dev/null 2>&1; then
    MYSQL_TRANSPORT=(--protocol=TCP -h127.0.0.1 -P3306)
    echo "Conexao MySQL CDC: TCP 127.0.0.1:3306."
else
    socket_path=""
    for candidate in \
        "${MYSQL_UNIX_PORT:-}" \
        /var/run/mysqld/mysqld.sock \
        /var/lib/mysql/mysql.sock \
        /tmp/mysql.sock; do
        if [[ -n "$candidate" && -S "$candidate" ]]; then
            socket_path="$candidate"
            break
        fi
    done

    if [[ -z "$socket_path" ]]; then
        echo "MySQL nao esta acessivel por TCP e nenhum socket ativo foi encontrado." >&2
        exit 1
    fi

    MYSQL_TRANSPORT=(--protocol=SOCKET --socket="$socket_path")
    echo "Conexao MySQL CDC: socket $socket_path."
fi

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql "${MYSQL_TRANSPORT[@]}" -uroot <<SQL
CREATE USER IF NOT EXISTS '$db_user'@'%' IDENTIFIED BY '$escaped_password';
ALTER USER '$db_user'@'%' IDENTIFIED BY '$escaped_password';
GRANT SELECT, RELOAD, SHOW DATABASES, REPLICATION SLAVE, REPLICATION CLIENT, LOCK TABLES ON *.* TO '$db_user'@'%';
FLUSH PRIVILEGES;
SQL

cdc_settings="$(MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql "${MYSQL_TRANSPORT[@]}" -uroot \
    --batch --skip-column-names \
    -e "SELECT CONCAT(@@GLOBAL.log_bin, '|', @@GLOBAL.binlog_format, '|', @@GLOBAL.binlog_row_image, '|', @@GLOBAL.enforce_gtid_consistency, '|', @@GLOBAL.gtid_mode);")"

if [[ "$cdc_settings" != "1|ROW|FULL|ON|ON" ]]; then
    echo "Configuracao MySQL incompativel com CDC: $cdc_settings (esperado: 1|ROW|FULL|ON|ON)." >&2
    echo "Ordem: log_bin|binlog_format|binlog_row_image|enforce_gtid_consistency|gtid_mode." >&2
    echo "Comando efetivo do container:" >&2
    tr '\0' ' ' </proc/1/cmdline >&2 || true
    echo >&2
    exit 1
fi

MYSQL_PWD="$db_password" mysql "${MYSQL_TRANSPORT[@]}" -u"$db_user" \
    --batch --skip-column-names -e 'SELECT 1;' >/dev/null

echo "Usuario MySQL do Debezium e binlog CDC validados."
