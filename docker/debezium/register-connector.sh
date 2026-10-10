#!/bin/sh

set -eu

CONNECT_URL="http://debezium:8083"
CONNECTOR_NAME="crm-mysql-connector"
DB_USER="${MYSQL_DEBEZIUM_USER:-debezium}"
DB_PASSWORD="${MYSQL_DEBEZIUM_PASSWORD:-debezium_dev_secret}"
DB_NAME="${MYSQL_DATABASE:-crm_dev}"
TOPIC_PREFIX="${DEBEZIUM_TOPIC_PREFIX:-crm.mysql}"
TABLES="${DEBEZIUM_TABLE_INCLUDE_LIST:-pessoas,pessoa_contatos,pessoa_enderecos,contratos,contrato_parcelas,atendimentos_historico,atendimento_resultados,campanhas,campanha_itens,estrategias,estrategia_contratos,acordos_operacionais,acordos_operacionais_parcelas,acordos_operacionais_acompanhamento,acordo_regras_carencia,usuarios,pagamentos,boletos,baixas,cargas_arquivos}"
RESPONSE_FILE="/tmp/debezium-response.json"
CONFIG_FILE="/tmp/crm-mysql-connector-config.json"
CREATE_FILE="/tmp/crm-mysql-connector-create.json"

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

show_safe_response() {
    if [ ! -s "$RESPONSE_FILE" ]; then
        echo "Debezium nao retornou corpo na resposta." >&2
        return
    fi

    # O REST do Kafka Connect pode devolver a configuracao no erro. Nunca
    # escreve database.password nos logs do instalador.
    sed -E 's/("database\.password"[[:space:]]*:[[:space:]]*)"([^"\\]|\\.)*"/\1"***"/g' \
        "$RESPONSE_FILE" >&2
}

request_status() {
    method="$1"
    url="$2"
    payload="${3:-}"

    if [ -n "$payload" ]; then
        curl -sS --connect-timeout 5 --max-time 30 \
            -o "$RESPONSE_FILE" -w '%{http_code}' \
            -X "$method" "$url" \
            -H 'Content-Type: application/json' \
            --data "@$payload"
    else
        curl -sS --connect-timeout 5 --max-time 30 \
            -o "$RESPONSE_FILE" -w '%{http_code}' \
            -X "$method" "$url"
    fi
}

DB_USER_JSON="$(json_escape "$DB_USER")"
DB_PASSWORD_JSON="$(json_escape "$DB_PASSWORD")"
DB_NAME_JSON="$(json_escape "$DB_NAME")"
TOPIC_PREFIX_JSON="$(json_escape "$TOPIC_PREFIX")"

TABLE_INCLUDE_LIST=""
old_ifs="$IFS"
IFS=','
for table in $TABLES; do
    table="$(printf '%s' "$table" | tr -d '[:space:]')"
    case "$table" in
        ''|*[!A-Za-z0-9_]*)
            echo "Tabela invalida em DEBEZIUM_TABLE_INCLUDE_LIST: $table" >&2
            exit 1
            ;;
    esac
    if [ -n "$TABLE_INCLUDE_LIST" ]; then
        TABLE_INCLUDE_LIST="$TABLE_INCLUDE_LIST,"
    fi
    TABLE_INCLUDE_LIST="$TABLE_INCLUDE_LIST$DB_NAME.$table"
done
IFS="$old_ifs"
TABLE_INCLUDE_LIST_JSON="$(json_escape "$TABLE_INCLUDE_LIST")"

echo "Aguardando Debezium Connect..."
ready_attempt=1
while :; do
    if curl -fsS --connect-timeout 5 --max-time 10 "$CONNECT_URL/connectors" >/dev/null; then
        break
    fi
    if [ "$ready_attempt" -ge 60 ]; then
        echo "Debezium Connect nao ficou disponivel em 300 segundos." >&2
        exit 1
    fi
    ready_attempt=$((ready_attempt + 1))
    sleep 5
done

# Incidente 2026-10-06 (CDC parado ~9h):
# - heartbeat.interval.ms: sem ele o offset salvo so avanca com evento nas
#   tabelas capturadas; parado num binlog antigo, o arquivo foi purgado e o
#   restart da task nao tinha mais de onde retomar.
# - store.only.captured.databases.ddl: o MySQL tambem tem o banco crm_test
#   (suite PHPUnit); o historico de schema guarda so o DDL do banco capturado.
# - O topico de historico (schema-changes.<db>) PRECISA de retencao infinita
#   (retention.ms=-1). Ele tinha sido criado com o padrao do Redpanda (7 dias)
#   e perdeu os CREATE TABLE. Em instalacao nova, conferir com:
#   docker compose exec redpanda rpk topic describe schema-changes.crm_dev -c
cat > "$CONFIG_FILE" <<EOF
{
  "connector.class": "io.debezium.connector.mysql.MySqlConnector",
  "database.hostname": "mysql",
  "database.port": "3306",
  "database.user": "$DB_USER_JSON",
  "database.password": "$DB_PASSWORD_JSON",
  "database.server.id": "184054",
  "topic.prefix": "$TOPIC_PREFIX_JSON",
  "database.include.list": "$DB_NAME_JSON",
  "table.include.list": "$TABLE_INCLUDE_LIST_JSON",
  "schema.history.internal.kafka.bootstrap.servers": "redpanda:9092",
  "schema.history.internal.kafka.topic": "schema-changes.$DB_NAME_JSON",
  "schema.history.internal.store.only.captured.databases.ddl": "true",
  "heartbeat.interval.ms": "30000",
  "include.schema.changes": "false",
  "snapshot.mode": "when_needed",
  "decimal.handling.mode": "string",
  "time.precision.mode": "connect",
  "tombstones.on.delete": "false"
}
EOF

existing_status="$(request_status GET "$CONNECT_URL/connectors/$CONNECTOR_NAME" || true)"
existing_status="${existing_status:-000}"
case "$existing_status" in
    200)
        method="PUT"
        endpoint="$CONNECT_URL/connectors/$CONNECTOR_NAME/config"
        payload="$CONFIG_FILE"
        action="Atualizando"
        ;;
    404)
        {
            printf '{"name":"%s","config":' "$CONNECTOR_NAME"
            cat "$CONFIG_FILE"
            printf '}'
        } > "$CREATE_FILE"
        method="POST"
        endpoint="$CONNECT_URL/connectors"
        payload="$CREATE_FILE"
        action="Registrando"
        ;;
    *)
        echo "Falha ao consultar connector $CONNECTOR_NAME (HTTP $existing_status)." >&2
        show_safe_response
        exit 1
        ;;
esac

submit_attempt=1
while :; do
    echo "$action connector $CONNECTOR_NAME (tentativa $submit_attempt/12)..."
    submit_status="$(request_status "$method" "$endpoint" "$payload" || true)"
    submit_status="${submit_status:-000}"
    case "$submit_status" in
        2??)
            break
            ;;
        409)
            # Pode haver rebalanceamento do Kafka Connect ou uma criacao
            # concorrente. Na segunda situacao, passa a atualizar o existente.
            check_status="$(request_status GET "$CONNECT_URL/connectors/$CONNECTOR_NAME" || true)"
            check_status="${check_status:-000}"
            if [ "$check_status" = "200" ]; then
                method="PUT"
                endpoint="$CONNECT_URL/connectors/$CONNECTOR_NAME/config"
                payload="$CONFIG_FILE"
                action="Atualizando"
            fi
            ;;
        000|500|502|503)
            ;;
        *)
            echo "Debezium recusou a configuracao (HTTP $submit_status)." >&2
            show_safe_response
            exit 1
            ;;
    esac

    if [ "$submit_attempt" -ge 12 ]; then
        echo "Debezium nao aceitou a configuracao apos 12 tentativas (ultimo HTTP $submit_status)." >&2
        show_safe_response
        exit 1
    fi
    submit_attempt=$((submit_attempt + 1))
    sleep 5
done

echo "Aguardando connector e task ficarem RUNNING..."
status_attempt=1
recovery_stage=0
while :; do
    connector_status="$(request_status GET "$CONNECT_URL/connectors/$CONNECTOR_NAME/status" || true)"
    connector_status="${connector_status:-000}"
    if [ "$connector_status" = "200" ]; then
        running_states="$(grep -o '"state"[[:space:]]*:[[:space:]]*"RUNNING"' "$RESPONSE_FILE" | wc -l | tr -d '[:space:]')"
        if [ "${running_states:-0}" -ge 2 ] \
            && ! grep -q '"state"[[:space:]]*:[[:space:]]*"FAILED"' "$RESPONSE_FILE"; then
            echo "Connector $CONNECTOR_NAME pronto: connector=RUNNING, task 0=RUNNING."
            exit 0
        fi

        if grep -q '"state"[[:space:]]*:[[:space:]]*"FAILED"' "$RESPONSE_FILE"; then
            if grep -Eq 'EOFException|Failed to read next byte|binlog position.*not available|Could not find first log file' "$RESPONSE_FILE"; then
                if [ "$recovery_stage" -eq 0 ]; then
                    echo "Leitura do binlog interrompida; reiniciando somente a task com falha..."
                    restart_status="$(request_status POST "$CONNECT_URL/connectors/$CONNECTOR_NAME/restart?includeTasks=true&onlyFailed=true" || true)"
                    restart_status="${restart_status:-000}"
                    case "$restart_status" in
                        2??) ;;
                        *)
                            echo "Kafka Connect recusou o reinicio da task (HTTP $restart_status)." >&2
                            show_safe_response
                            exit 1
                            ;;
                    esac

                    recovery_stage=1
                    status_attempt=1
                    sleep 5
                    continue
                fi

                if [ "$recovery_stage" -eq 1 ]; then
                    echo "Offset CDC invalido detectado; recuperando somente o connector $CONNECTOR_NAME..."

                    stop_status="$(request_status PUT "$CONNECT_URL/connectors/$CONNECTOR_NAME/stop" || true)"
                    stop_status="${stop_status:-000}"
                    case "$stop_status" in
                        2??) ;;
                        *)
                            echo "Nao foi possivel parar o connector para recuperar o offset (HTTP $stop_status)." >&2
                            show_safe_response
                            exit 1
                            ;;
                    esac

                    stopped_attempt=1
                    while :; do
                        stopped_status="$(request_status GET "$CONNECT_URL/connectors/$CONNECTOR_NAME/status" || true)"
                        stopped_status="${stopped_status:-000}"
                        if [ "$stopped_status" = "200" ] \
                            && grep -q '"state"[[:space:]]*:[[:space:]]*"STOPPED"' "$RESPONSE_FILE"; then
                            break
                        fi
                        if [ "$stopped_attempt" -ge 30 ]; then
                            echo "Connector nao confirmou o estado STOPPED para recuperar o offset." >&2
                            show_safe_response
                            exit 1
                        fi
                        stopped_attempt=$((stopped_attempt + 1))
                        sleep 2
                    done

                    reset_attempt=1
                    while :; do
                        reset_status="$(request_status DELETE "$CONNECT_URL/connectors/$CONNECTOR_NAME/offsets" || true)"
                        reset_status="${reset_status:-000}"
                        case "$reset_status" in
                            2??) break ;;
                            409|500|502|503) ;;
                            *)
                                echo "Kafka Connect recusou o reset do offset CDC (HTTP $reset_status)." >&2
                                show_safe_response
                                exit 1
                                ;;
                        esac
                        if [ "$reset_attempt" -ge 10 ]; then
                            echo "Nao foi possivel resetar o offset CDC apos 10 tentativas." >&2
                            show_safe_response
                            exit 1
                        fi
                        reset_attempt=$((reset_attempt + 1))
                        sleep 3
                    done

                    resume_status="$(request_status PUT "$CONNECT_URL/connectors/$CONNECTOR_NAME/resume" || true)"
                    resume_status="${resume_status:-000}"
                    case "$resume_status" in
                        2??) ;;
                        *)
                            echo "Nao foi possivel retomar o connector apos recuperar o offset (HTTP $resume_status)." >&2
                            show_safe_response
                            exit 1
                            ;;
                    esac

                    echo "Offset CDC recuperado. Debezium iniciara um snapshot consistente quando necessario."
                    recovery_stage=2
                    status_attempt=1
                    sleep 5
                    continue
                fi
            fi

            echo "Connector $CONNECTOR_NAME entrou em FAILED." >&2
            show_safe_response
            exit 1
        fi
    fi

    if [ "$status_attempt" -ge 180 ]; then
        echo "Connector $CONNECTOR_NAME nao ficou RUNNING em 900 segundos." >&2
        show_safe_response
        exit 1
    fi
    status_attempt=$((status_attempt + 1))
    sleep 5
done
