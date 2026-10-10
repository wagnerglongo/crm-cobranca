#!/bin/sh
# RewriteMap "prg" do Apache: recebe (uma por linha) o valor base64 do Basic Auth
# e responde OK ou DENY. Valida chamando o proprio CRM (dashboards_auth.php), que
# confere login/senha do Pactua + permissao 'dashboards.acessar'.
# Cache de 60s so pra resultados OK (a pagina do Dashboards faz dezenas de
# requisicoes); negativas nunca sao cacheadas. Revogar permissao vale em ate 60s.
CACHE=/tmp/works-auth-cache
TTL=60
mkdir -p "$CACHE"
chmod 700 "$CACHE"
while IFS= read -r key; do
    [ -z "$key" ] && { echo DENY; continue; }
    h=$(printf '%s' "$key" | sha256sum | cut -d' ' -f1)
    f="$CACHE/$h"
    now=$(date +%s)
    if [ -f "$f" ] && [ $((now - $(stat -c %Y "$f"))) -lt $TTL ]; then
        echo OK; continue
    fi
    if wget -q -T 5 -O /dev/null --header "Authorization: Basic $key" http://127.0.0.1/dashboards_auth.php 2>/dev/null; then
        : > "$f"
        echo OK
    else
        rm -f "$f"
        echo DENY
    fi
done
