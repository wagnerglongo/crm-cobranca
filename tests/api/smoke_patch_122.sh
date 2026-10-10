#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
read -r -p "Login CRM para o smoke do Patch 122: " LOGIN
read -r -s -p "Senha CRM: " SENHA
printf '\n'
read -r -p "Documento existente (CPF ou CNPJ): " DOCUMENTO
read -r -p "Código de contrato existente: " CONTRATO

payload="$(jq -n --arg login "$LOGIN" --arg senha "$SENHA" '{login:$login,senha:$senha,nome:"Smoke Patch 122"}')"
tokens="$(curl -fsS -H 'Content-Type: application/json' -d "$payload" "$BASE_URL/api/v1/auth/token")"
access_token="$(jq -er '.access_token' <<<"$tokens")"

documento_limpo="$(tr -cd '0-9' <<<"$DOCUMENTO")"
if [[ ${#documento_limpo} -ne 11 && ${#documento_limpo} -ne 14 ]]; then
  echo "ERRO: informe CPF com 11 dígitos ou CNPJ com 14 dígitos." >&2
  exit 1
fi
documento_url="$(jq -rn --arg value "$documento_limpo" '$value|@uri')"
contrato_url="$(jq -rn --arg value "$CONTRATO" '$value|@uri')"

cliente="$(curl -fsS -H "Authorization: Bearer $access_token" "$BASE_URL/api/v1/clientes/documento/$documento_url")"
jq -e '.data.cliente.documento' >/dev/null <<<"$cliente"
echo "OK: cliente por documento"

contratos="$(curl -fsS -H "Authorization: Bearer $access_token" "$BASE_URL/api/v1/contratos/codigo/$contrato_url")"
jq -e '.data | type == "array"' >/dev/null <<<"$contratos"
echo "OK: contrato por código"

echo "Smoke de consultas exatas concluído."
