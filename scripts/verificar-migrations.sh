#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env.local"

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

if ! "${COMPOSE[@]}" ps --status running --services | grep -qx 'mysql'; then
    echo "O servico MySQL nao esta em execucao." >&2
    exit 1
fi

validation_db="crm_migration_check_$(date +%s)_$$"
validation_created=0

mysql_root() {
    "${COMPOSE[@]}" exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot'
}

mysql_validation_db() {
    "${COMPOSE[@]}" exec -T -e "MIGRATION_VALIDATION_DB=$validation_db" mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot "$MIGRATION_VALIDATION_DB"'
}

show_innodb_diagnostics() {
    "${COMPOSE[@]}" exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot -e "SHOW ENGINE INNODB STATUS\\G"' \
        | sed -n '/LATEST FOREIGN KEY ERROR/,+45p' || true
}

cleanup() {
    if (( validation_created == 1 )); then
        printf 'DROP DATABASE IF EXISTS `%s`;\n' "$validation_db" | mysql_root >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

printf 'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;\n' "$validation_db" \
    | mysql_root >/dev/null
validation_created=1

BASE_DIR="$PROJECT_DIR/database/migrations/crm_mysql84_migrations"
APP_MIGRATIONS_DIR="$PROJECT_DIR/app/database/migrations"
BASE_SCHEMA="$BASE_DIR/crm_schema_inicial_mysql84.sql"

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

for file in "${MIGRATIONS[@]}"; do
    relative="${file#"$PROJECT_DIR"/}"
    echo "VALIDANDO  $relative"
    if ! mysql_validation_db < "$file"; then
        echo "ERRO: a validacao falhou em $relative" >&2
        show_innodb_diagnostics >&2
        exit 5
    fi
done

echo "VALIDANDO  regra de acordo unico ativo por contrato"
if ! mysql_validation_db <<'SQL'
DROP PROCEDURE IF EXISTS validar_regra_acordo_unico_ativo;
DELIMITER //
CREATE PROCEDURE validar_regra_acordo_unico_ativo()
BEGIN
    DECLARE v_credor_id BIGINT UNSIGNED;
    DECLARE v_pessoa_id BIGINT UNSIGNED;
    DECLARE v_contrato_id BIGINT UNSIGNED;
    DECLARE v_primeiro_acordo_id BIGINT UNSIGNED;
    DECLARE v_bloqueou_duplicidade TINYINT DEFAULT 0;

    SELECT MIN(id) INTO v_credor_id FROM credores;

    INSERT INTO pessoas (documento, nome)
    VALUES ('99999999999', 'Validacao temporaria de migrations');
    SET v_pessoa_id = LAST_INSERT_ID();

    INSERT INTO contratos (credor_id, pessoa_id, codigo_contrato)
    VALUES (v_credor_id, v_pessoa_id, 'VALIDACAO-MIGRATIONS');
    SET v_contrato_id = LAST_INSERT_ID();

    INSERT INTO acordos_operacionais (
        pessoa_id, contrato_id, credor_id, valor_acordo,
        data_promessa_pagamento, status_operacional
    ) VALUES (
        v_pessoa_id, v_contrato_id, v_credor_id, 100.00,
        CURRENT_DATE, 'pendente'
    );
    SET v_primeiro_acordo_id = LAST_INSERT_ID();

    IF NOT ((SELECT acordo_ativo_contrato_id
        FROM acordos_operacionais
        WHERE id = v_primeiro_acordo_id) <=> v_contrato_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Trigger da migration 017 nao preencheu o contrato ativo.';
    END IF;

    BEGIN
        DECLARE CONTINUE HANDLER FOR 1062 SET v_bloqueou_duplicidade = 1;
        INSERT INTO acordos_operacionais (
            pessoa_id, contrato_id, credor_id, valor_acordo,
            data_promessa_pagamento, status_operacional
        ) VALUES (
            v_pessoa_id, v_contrato_id, v_credor_id, 110.00,
            CURRENT_DATE, 'formalizado'
        );
    END;

    IF v_bloqueou_duplicidade = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A migration 017 permitiu dois acordos ativos para o mesmo contrato.';
    END IF;

    UPDATE acordos_operacionais
    SET status_operacional = 'cancelado'
    WHERE id = v_primeiro_acordo_id;

    INSERT INTO acordos_operacionais (
        pessoa_id, contrato_id, credor_id, valor_acordo,
        data_promessa_pagamento, status_operacional
    ) VALUES (
        v_pessoa_id, v_contrato_id, v_credor_id, 120.00,
        CURRENT_DATE, 'pendente'
    );
END //
DELIMITER ;
CALL validar_regra_acordo_unico_ativo();
DROP PROCEDURE IF EXISTS validar_regra_acordo_unico_ativo;
SQL
then
    echo "ERRO: a regra de acordo unico ativo nao passou no teste funcional." >&2
    show_innodb_diagnostics >&2
    exit 6
fi

echo "Validacao concluida: ${#MIGRATIONS[@]} migrations executadas em banco temporario."
