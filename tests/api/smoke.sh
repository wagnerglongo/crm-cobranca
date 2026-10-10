#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

BASE_URL="${1:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf -- "$WORK_DIR"' EXIT

read -r -p "Login CRM para o smoke test: " API_TEST_LOGIN
read -r -s -p "Senha CRM: " API_TEST_PASSWORD
printf '\n'

request() {
    local output="$1"
    shift
    curl -sS -o "$output" -w '%{http_code}' "$@"
}

assert_status() {
    local actual="$1" expected="$2" label="$3"
    if [[ "$actual" != "$expected" ]]; then
        echo "ERRO: $label retornou HTTP $actual; esperado $expected." >&2
        return 1
    fi
    echo "OK: $label -> HTTP $actual"
}

status="$(request "$WORK_DIR/health.json" "$BASE_URL/api/v1/health")"
assert_status "$status" 200 "health"

status="$(request "$WORK_DIR/missing.json" -H 'Accept: application/json' "$BASE_URL/api/v1/auth/me")"
assert_status "$status" 401 "token ausente"

jq -n '{login:"__crm_api_invalid__",senha:"credencial-invalida"}' > "$WORK_DIR/invalid-payload.json"
status="$(request "$WORK_DIR/invalid.json" -H 'Content-Type: application/json' --data-binary "@$WORK_DIR/invalid-payload.json" "$BASE_URL/api/v1/auth/token")"
assert_status "$status" 401 "autenticação inválida"

jq -n --arg login "$API_TEST_LOGIN" --arg senha "$API_TEST_PASSWORD" \
  '{login:$login,senha:$senha,nome:"CRM API smoke test"}' > "$WORK_DIR/token-payload.json"
status="$(request "$WORK_DIR/token.json" -H 'Content-Type: application/json' --data-binary "@$WORK_DIR/token-payload.json" "$BASE_URL/api/v1/auth/token")"
assert_status "$status" 200 "emissão de token"
ACCESS_TOKEN="$(jq -er '.access_token' "$WORK_DIR/token.json")"
jq -e 'has("refresh_token") | not' "$WORK_DIR/token.json" >/dev/null
jq -e 'has("refresh_expires_in") | not' "$WORK_DIR/token.json" >/dev/null
echo "OK: resposta contém somente o access token"

status="$(request "$WORK_DIR/me.json" -H "Authorization: Bearer $ACCESS_TOKEN" "$BASE_URL/api/v1/auth/me")"
assert_status "$status" 200 "auth/me"

status="$(request "$WORK_DIR/refresh-removed.json" -H 'Content-Type: application/json' --data '{}' "$BASE_URL/api/v1/auth/refresh")"
assert_status "$status" 404 "refresh removido"

status="$(request "$WORK_DIR/revoke-removed.json" -X POST -H "Authorization: Bearer $ACCESS_TOKEN" "$BASE_URL/api/v1/auth/revoke")"
assert_status "$status" 404 "revoke removido"

jq -n --arg login "$API_TEST_LOGIN" --arg senha "$API_TEST_PASSWORD" \
  '{login:$login,senha:$senha,nome:"CRM API smoke test expiry"}' > "$WORK_DIR/expiry-payload.json"
status="$(request "$WORK_DIR/expiry-token.json" -H 'Content-Type: application/json' --data-binary "@$WORK_DIR/expiry-payload.json" "$BASE_URL/api/v1/auth/token")"
assert_status "$status" 200 "emissão do token de expiração"
EXPIRED_TOKEN="$(jq -er '.access_token' "$WORK_DIR/expiry-token.json")"
printf '%s' "$EXPIRED_TOKEN" | docker exec -i crm_php php console/expirar_token_api_teste.php >/dev/null
status="$(request "$WORK_DIR/expired.json" -H "Authorization: Bearer $EXPIRED_TOKEN" "$BASE_URL/api/v1/auth/me")"
assert_status "$status" 401 "token expirado"

unset API_TEST_PASSWORD ACCESS_TOKEN EXPIRED_TOKEN
echo "OK: smoke test da API concluído com token único e sem imprimir credenciais."
