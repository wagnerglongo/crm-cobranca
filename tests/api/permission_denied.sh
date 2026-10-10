#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

BASE_URL="${1:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
TEMP_FILE="$(mktemp)"
trap 'rm -f -- "$TEMP_FILE"' EXIT

read -r -p "Login CRM sem a permissão integracoes.visualizar: " LOGIN
read -r -s -p "Senha CRM: " PASSWORD
printf '\n'

jq -n --arg login "$LOGIN" --arg senha "$PASSWORD" '{login:$login,senha:$senha,nome:"CRM API permission test"}' > "$TEMP_FILE"
response="$(curl -sS -H 'Content-Type: application/json' --data-binary "@$TEMP_FILE" "$BASE_URL/api/v1/auth/token")"
token="$(jq -er '.access_token' <<< "$response")"
status="$(curl -sS -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $token" "$BASE_URL/api/v1/health/components")"
unset PASSWORD token response

if [[ "$status" != "403" ]]; then
    echo "ERRO: usuário sem permissão recebeu HTTP $status; esperado 403." >&2
    exit 1
fi

echo "OK: middleware de permissão retornou HTTP 403."
