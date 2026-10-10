#!/usr/bin/env bash

set -Eeuo pipefail

BASE_URL="${1:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
payload='{"login":"__crm_api_rate_limit__","senha":"invalida"}'
limited=0

for _ in $(seq 1 16); do
    status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data-binary "$payload" "$BASE_URL/api/v1/auth/token")"
    if [[ "$status" == "429" ]]; then
        limited=1
        break
    fi
done

if [[ "$limited" != "1" ]]; then
    echo "ERRO: o endpoint de token não retornou HTTP 429." >&2
    exit 1
fi

echo "OK: rate limit retornou HTTP 429. Aguarde a janela configurada antes de repetir testes de login."
