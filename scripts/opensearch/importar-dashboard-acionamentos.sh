#!/usr/bin/env bash
# Atualiza os campos do index-pattern crm_acionamentos_v1 e importa o dashboard
# "Acionamentos ACME - Funil completo" (idempotente: sobrescreve os mesmos ids).
# Roda dentro do container opensearch-dashboards (a porta 5601 externa exige login).
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
NDJSON=docker/opensearch-dashboards/saved-objects/acme-acionamentos-funil.ndjson
IDX=1e1c10a0-9a46-11f1-a634-1b05adb037ac
if docker info >/dev/null 2>&1; then dc() { docker compose "$@"; }; else dc() { sg docker -c "docker compose $*"; }; fi
run() { dc exec -T opensearch-dashboards "$@"; }
python3 scripts/opensearch/gerar_dashboard_acionamentos.py

# 1) refresca a lista de campos dos index-patterns de acionamentos e acordos
refresca() { # $1 = indice, $2 = id do index-pattern
  run curl -fsS -H 'osd-xsrf: true' "http://127.0.0.1:5601/api/index_patterns/_fields_for_wildcard?pattern=$1&meta_fields=_source&meta_fields=_id&meta_fields=_type&meta_fields=_index&meta_fields=_score" > /tmp/fields.json
  python3 - <<PY > /tmp/ip_body.json
import json
f=json.load(open('/tmp/fields.json'))['fields']
print(json.dumps({"attributes":{"fields":json.dumps(f)}}))
PY
  run sh -c "curl -fsS -X PUT -H 'osd-xsrf: true' -H 'content-type: application/json' --data-binary @- http://127.0.0.1:5601/api/saved_objects/index-pattern/$2 >/dev/null" < /tmp/ip_body.json
  echo "index-pattern $1 atualizado"
}
refresca crm_acionamentos_v1 "$IDX"
refresca crm_acordos_v1 0ee912e0-9a46-11f1-a634-1b05adb037ac

# 2) importa os objetos
dc cp "$NDJSON" opensearch-dashboards:/tmp/acme-acionamentos-funil.ndjson
run curl -fsS -X POST -H 'osd-xsrf: true' -F file=@/tmp/acme-acionamentos-funil.ndjson "http://127.0.0.1:5601/api/saved_objects/_import?overwrite=true"
echo
