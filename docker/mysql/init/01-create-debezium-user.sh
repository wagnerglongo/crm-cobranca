#!/bin/sh
set -eu

user="${MYSQL_DEBEZIUM_USER:-debezium}"
password="${MYSQL_DEBEZIUM_PASSWORD:-}"

case "$user" in
  ''|*[!A-Za-z0-9_]* )
    echo "MYSQL_DEBEZIUM_USER invalido. Use apenas letras, numeros e underscore." >&2
    exit 1
    ;;
esac

if [ -z "$password" ]; then
  echo "MYSQL_DEBEZIUM_PASSWORD nao configurado." >&2
  exit 1
fi

escaped_password=$(printf '%s' "$password" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g")

MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql -uroot <<SQL
CREATE USER IF NOT EXISTS '${user}'@'%' IDENTIFIED BY '${escaped_password}';
ALTER USER '${user}'@'%' IDENTIFIED BY '${escaped_password}';
GRANT SELECT, RELOAD, SHOW DATABASES, REPLICATION SLAVE, REPLICATION CLIENT, LOCK TABLES ON *.* TO '${user}'@'%';
FLUSH PRIVILEGES;
SQL
