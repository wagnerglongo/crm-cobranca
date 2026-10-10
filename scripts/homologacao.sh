#!/usr/bin/env bash
# Ambiente de HOMOLOGACAO do CRM (requisito de 2026-10-06).
# Antes, editar um arquivo em /opt/crm-dev-docker ja era producao na hora.
# Agora: muda e testa na homologacao (http://<vm>:8081), depois publica.
#
#   bash scripts/homologacao.sh preparar        # copia do codigo (git worktree, branch homolog) + configs
#   bash scripts/homologacao.sh banco --confirmar  # (re)cria crm_homolog copiando o crm_dev (rodar fora do expediente)
#   bash scripts/homologacao.sh subir           # sobe php-homolog + apache-homolog (porta 8081)
#   bash scripts/homologacao.sh testes          # suite PHPUnit com o codigo da homologacao
#   bash scripts/homologacao.sh publicar --confirmar  # testes + leva o que foi commitado na homolog pra producao
#   bash scripts/homologacao.sh parar | status
#
# A copia do banco tambem roda sozinha toda semana (padrao domingo 05:00) e
# pode ser pedida na tela Administracao > Homologacao: scripts/homologacao-agendador.sh.
#
# Seguranca: a homologacao tem o PROPRIO .env (gerado a partir do de producao)
# com banco crm_homolog, Redis base 2, e SEM credenciais de credores, SMTP
# e da chave das rotinas SFTP - nada sai pra fora. No banco copiado, as
# integracoes e rotinas SFTP sao desativadas. Nao ha workers nem CDC na
# homologacao. Subir/parar nunca mexe nos containers de producao (--no-deps).
set -Eeuo pipefail

PROD="$(cd "$(dirname "$0")/.." && pwd)"
HOMOLOG="${CRM_HOMOLOG_DIR:-/home/works/crm-homolog}"
export CRM_HOMOLOG_DIR="$HOMOLOG"
PORTA=8081

if docker info >/dev/null 2>&1; then dc() { (cd "$PROD" && docker compose --profile homolog "$@"); }; dk() { docker "$@"; }
else dc() { (cd "$PROD" && sg docker -c "CRM_HOMOLOG_DIR=$(printf '%q' "$HOMOLOG") docker compose --profile homolog $(printf '%q ' "$@")"); }; dk() { sg docker -c "docker $(printf '%q ' "$@")"; }; fi
passo() { echo; echo "== $*"; }
falha() { echo "PAROU: $*" >&2; exit 1; }
mysql_root() { dk exec -i crm_mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -uroot "$@"' mysql "$@"; }

# Chaves trocadas no .env da homologacao (o resto vem igual ao de producao).
gerar_env() {
    python3 - "$PROD/.env.local" "$HOMOLOG/.env.homolog" "$PORTA" <<'PY'
import re, sys
origem, destino, porta = sys.argv[1], sys.argv[2], sys.argv[3]
sobrescrever = {
    'APP_ENV': 'homolog', 'APP_DEBUG': 'true', 'APP_URL': 'http://10.0.0.74:' + porta,
    'DB_DATABASE': 'crm_homolog',
    'REDIS_CACHE_DB': '2', 'REDIS_CACHE_DATABASE': '2', 'REDIS_QUEUE_DB': '2', 'REDIS_QUEUE_DATABASE': '2',
    'REDIS_SESSION_DATABASE': '2',
    'SMTP_HOST': '', 'SMTP_USERNAME': '', 'SMTP_PASSWORD': '', 'ALERTA_EMAIL_DESTINO': '',
    'ROTINAS_CARGA_CHAVE_CRIPTO': '',
    'ACME_API_USERNAME': '', 'ACME_API_PASSWORD': '',
}
linhas = []
for linha in open(origem, encoding='utf-8').read().replace('\r', '').split('\n'):
    chave = linha.split('=', 1)[0].strip()
    if chave in sobrescrever:
        continue
    linhas.append(linha)
linhas.append('')
linhas.append('# --- HOMOLOGACAO (gerado por scripts/homologacao.sh - nao editar a mao) ---')
linhas += [k + '=' + v for k, v in sobrescrever.items()]
open(destino, 'w', encoding='utf-8').write('\n'.join(linhas) + '\n')
PY
    chmod 600 "$HOMOLOG/.env.homolog"
}

# Apache da homologacao = o de producao apontando pro php-homolog, sem a porta 5601 (Dashboards).
gerar_apache() {
    python3 - "$PROD/docker/apache/httpd.conf" "$HOMOLOG/httpd-homolog.conf" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read().replace('\r', '')
s = s.replace('proxy:fcgi://php:9000', 'proxy:fcgi://php-homolog:9000')
s = re.sub(r'(?m)^Listen 5601\s*\n', '', s)
s = re.sub(r'(?s)<VirtualHost \*:5601>.*?</VirtualHost>\s*', '', s)
assert 'php-homolog:9000' in s and '5601' not in s
open(sys.argv[2], 'w', encoding='utf-8').write(s)
PY
}

preparar() {
    passo "Copia do codigo (git worktree em $HOMOLOG, branch homolog)"
    if [ -e "$HOMOLOG/.git" ]; then
        echo "ja existe: $(git -C "$HOMOLOG" log --oneline -1)"
    elif git -C "$PROD" show-ref --verify --quiet refs/heads/homolog; then
        git -C "$PROD" worktree add "$HOMOLOG" homolog
    else
        git -C "$PROD" worktree add -b homolog "$HOMOLOG" main
    fi

    passo "Dependencias PHP (vendor fora do git: copia da producao)"
    if [ ! -f "$HOMOLOG/app/vendor/autoload.php" ] || [ "${1:-}" = "--atualizar-vendor" ]; then
        rm -rf "$HOMOLOG/app/vendor"
        cp -a "$PROD/app/vendor" "$HOMOLOG/app/vendor"
    fi
    echo "ok"

    passo "Pastas de storage proprias da homologacao"
    mkdir -p "$HOMOLOG"/storage/{cargas,anexos,cache,logs,runtime,integracoes,phpunit-cache}
    chmod -R a+rwX "$HOMOLOG/storage"

    passo "Configuracao propria (.env.homolog e Apache)"
    gerar_env
    gerar_apache
    grep -E '^(DB_DATABASE|APP_ENV|ACME_API_USERNAME|SMTP_HOST)=' "$HOMOLOG/.env.homolog" | tail -4
    echo "pronto. Proximo: banco --confirmar (fora do expediente) e subir."
}

banco() {
    [ "${1:-}" = "--confirmar" ] || falha "isso apaga e recria o banco crm_homolog com a copia de producao; rode com --confirmar."
    passo "Recriando crm_homolog (copia do crm_dev sem o conteudo de staging, sem gerar binlog)"
    mysql_root -e "DROP DATABASE IF EXISTS crm_homolog; CREATE DATABASE crm_homolog CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; GRANT ALL PRIVILEGES ON crm_homolog.* TO 'crm_app'@'%';"
    local dump='MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --protocol=TCP -h127.0.0.1 -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF --routines --triggers --events --hex-blob'
    local importar='MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -uroot --init-command="SET SESSION sql_log_bin=0" crm_homolog'
    dk exec crm_mysql sh -c "$dump --ignore-table=crm_dev.stg_carga_canonica --ignore-table=crm_dev.cargas_linhas_raw crm_dev" \
        | dk exec -i crm_mysql sh -c "$importar"
    dk exec crm_mysql sh -c "$dump --no-data crm_dev stg_carga_canonica cargas_linhas_raw" \
        | dk exec -i crm_mysql sh -c "$importar"

    passo "Desligando na copia tudo que poderia agir fora (defesa extra)"
    # rotinas_carga_sftp usa status ativa/pausada (a 1a versao usava "ativo" e falhava aqui, 2026-10-08).
    mysql_root crm_homolog --init-command="SET SESSION sql_log_bin=0" -e "UPDATE credor_integracoes SET ativo = 0; UPDATE rotinas_carga_sftp SET status = 'pausada';"
    mysql_root -N -e "SELECT CONCAT('contratos: ', COUNT(*)) FROM crm_homolog.contratos; SELECT CONCAT('integracoes ativas: ', COALESCE(SUM(ativo), 0)) FROM crm_homolog.credor_integracoes; SELECT CONCAT('rotinas sftp ativas: ', COUNT(*)) FROM crm_homolog.rotinas_carga_sftp WHERE status = 'ativa';"
}

subir() {
    [ -f "$HOMOLOG/.env.homolog" ] && [ -f "$HOMOLOG/httpd-homolog.conf" ] || falha "rode 'preparar' antes."
    gerar_env
    gerar_apache
    passo "Subindo php-homolog e apache-homolog (sem tocar na producao)"
    dc up -d --no-deps php-homolog apache-homolog
    for _ in $(seq 1 20); do
        codigo=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORTA/operacao/login.php" || true)
        [ "$codigo" = "200" ] && break
        sleep 2
    done
    [ "${codigo:-}" = "200" ] || falha "homologacao nao respondeu (HTTP ${codigo:-?}). Ver: docker logs crm_php_homolog / crm_apache_homolog"
    echo "OK: http://10.0.0.74:$PORTA/operacao/  (codigo da branch homolog: $(git -C "$HOMOLOG" log --oneline -1))"
}

parar() {
    dc stop php-homolog apache-homolog
    dc rm -f php-homolog apache-homolog
}

testes() {
    dk ps --format '{{.Names}}' | grep -qx crm_php_homolog || falha "homologacao fora do ar - rode 'subir'."
    bash "$HOMOLOG/scripts/preparar-banco-teste.sh"
    dk exec crm_php_homolog vendor/bin/phpunit
}

publicar() {
    [ "${1:-}" = "--confirmar" ] || falha "isso leva os commits da branch homolog para a producao; rode com --confirmar."
    [ -z "$(git -C "$HOMOLOG" status --porcelain)" ] || falha "ha alteracoes nao commitadas na homologacao."
    passo "Testes com o codigo da homologacao"
    testes
    passo "Producao: merge da branch homolog (so avanco direto, sem conflito)"
    git -C "$PROD" merge --ff-only homolog || falha "a producao (main) tem commits que a homologacao nao tem: rode 'git -C $HOMOLOG merge main', teste e publique de novo."
    git -C "$PROD" push -q
    echo "Publicado: $(git -C "$PROD" log --oneline -1)"
    echo "Se mudou migration: bash scripts/migrate.sh. Se mudou codigo usado pelos workers: reiniciar cdc-worker/cdc-retry-worker/integration-worker."
}

status() {
    echo "codigo homolog: $(git -C "$HOMOLOG" log --oneline -1 2>/dev/null || echo 'nao preparado')"
    echo "producao:       $(git -C "$PROD" log --oneline -1)"
    dk ps -a --filter name=_homolog --format '{{.Names}}\t{{.Status}}' || true
    mysql_root -N -e "SELECT CONCAT('banco crm_homolog: ', ROUND(SUM(data_length+index_length)/1024/1024), ' MB') FROM information_schema.tables WHERE table_schema='crm_homolog';" 2>/dev/null || true
}

case "${1:-}" in
    preparar) shift; preparar "$@" ;;
    banco) shift; banco "$@" ;;
    subir) subir ;;
    parar) parar ;;
    testes) testes ;;
    publicar) shift; publicar "$@" ;;
    status) status ;;
    *) sed -n '2,20p' "$0"; exit 1 ;;
esac
