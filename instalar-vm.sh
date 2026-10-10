#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$PROJECT_DIR/.env.local"
ENV_TEMPLATE="$PROJECT_DIR/.env.vm.example"

log() {
    printf '\n[%s] %s\n' "$(date '+%H:%M:%S')" "$1"
}

fail() {
    echo "ERRO: $1" >&2
    exit 1
}

if [[ "$(uname -s)" != "Linux" ]]; then
    fail "Este instalador foi preparado para VM Linux."
fi

if (( EUID != 0 )); then
    command -v sudo >/dev/null 2>&1 || fail "Execute como root ou instale o sudo."
    exec sudo -E bash "$0" "$@"
fi

cd "$PROJECT_DIR"

required_nonempty_files=(
    "$PROJECT_DIR/VERSION"
    "$PROJECT_DIR/docker-compose.yml"
    "$PROJECT_DIR/.env.vm.example"
    "$PROJECT_DIR/docker/php/Dockerfile"
    "$PROJECT_DIR/docker/opensearch-dashboards/Dockerfile"
    "$PROJECT_DIR/docker/mysql/conf.d/my.cnf"
    "$PROJECT_DIR/docker/mysql/init/03-configure-debezium-user.sh"
    "$PROJECT_DIR/scripts/migrate.sh"
    "$PROJECT_DIR/scripts/verificar-migrations.sh"
    "$PROJECT_DIR/scripts/validar-projeto.sh"
    "$PROJECT_DIR/app/composer.json"
    "$PROJECT_DIR/app/console/integracao_worker.php"
    "$PROJECT_DIR/app/console/infra_validar.php"
    "$PROJECT_DIR/app/console/infra_inicializar.php"
)

for required_file in "${required_nonempty_files[@]}"; do
    [[ -s "$required_file" ]] \
        || fail "Arquivo obrigatorio ausente ou vazio: $required_file. Reextraia o pacote antes de continuar."
done

DATABASE_CLASS_FILE="$PROJECT_DIR/app/src/Config/Database.php"
DATABASE_LEGACY_FILE="$PROJECT_DIR/app/src/Config/database.php"

[[ -f "$DATABASE_CLASS_FILE" ]] \
    || fail "Classe principal ausente: $DATABASE_CLASS_FILE. Reextraia o pacote antes de continuar."

# Uma versao antiga levava dois nomes que diferiam apenas por maiuscula.
# Todos os requires atuais usam Database.php; o shim antigo deve sair para
# evitar colisao na extracao e avisos PSR-4 do Composer.
if [[ -f "$DATABASE_LEGACY_FILE" ]]; then
    rm -f -- "$DATABASE_LEGACY_FILE"
fi

# O antigo controle por header do pipeline da API nao e mais utilizado.
# Remove-lo evita manter codigo morto em upgrades.
LEGACY_QUEUE_RULES_PAGE="$PROJECT_DIR/app/public/operacao/configuracoes/fila_regras.php"
if [[ -e "$LEGACY_QUEUE_RULES_PAGE" || -L "$LEGACY_QUEUE_RULES_PAGE" ]]; then
    rm -f -- "$LEGACY_QUEUE_RULES_PAGE"
fi

LEGACY_API_REPEAT_FILE="$PROJECT_DIR/app/src/Modules/Api/Idempotency/ApiIdempotencyService.php"
if [[ -f "$LEGACY_API_REPEAT_FILE" ]]; then
    rm -f -- "$LEGACY_API_REPEAT_FILE"
fi

if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
else
    fail "Nao foi possivel identificar a distribuicao Linux."
fi

install_host_tools() {
    local missing=()

    command -v curl >/dev/null 2>&1 || missing+=(curl)
    command -v jq >/dev/null 2>&1 || missing+=(jq)
    command -v unzip >/dev/null 2>&1 || missing+=(unzip)
    command -v zip >/dev/null 2>&1 || missing+=(zip)

    if (( ${#missing[@]} == 0 )); then
        return 0
    fi

    case "${ID:-}" in
        ubuntu|debian)
            log "Instalando ferramentas auxiliares da VM: ${missing[*]}"
            apt-get update
            DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates "${missing[@]}"
            ;;
        *)
            fail "Instale as ferramentas obrigatorias ausentes antes de continuar: ${missing[*]}."
            ;;
    esac
}

# Os testes e a administracao usam curl/jq/zip no host. O PHP nao e exigido
# na VM: validacoes e comandos PHP sempre executam dentro do container crm_php.
install_host_tools

install_docker() {
    case "${ID:-}" in
        ubuntu|debian) ;;
        *) fail "Instalacao automatica do Docker suportada em Ubuntu e Debian. Instale Docker Engine + Compose Plugin e rode novamente." ;;
    esac

    log "Instalando Docker Engine e Docker Compose Plugin"
    apt-get update
    apt-get install -y ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL "https://download.docker.com/linux/$ID/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    codename="${VERSION_CODENAME:-}"
    [[ -n "$codename" ]] || fail "VERSION_CODENAME ausente em /etc/os-release."
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/%s %s stable\n' \
        "$(dpkg --print-architecture)" "$ID" "$codename" > /etc/apt/sources.list.d/docker.list

    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

if ! command -v docker >/dev/null 2>&1; then
    install_docker
fi

if ! docker compose version >/dev/null 2>&1; then
    log "Instalando Docker Compose Plugin"
    case "${ID:-}" in
        ubuntu|debian)
            apt-get update
            apt-get install -y docker-compose-plugin
            ;;
        *) fail "Docker encontrado, mas o comando 'docker compose' nao esta disponivel." ;;
    esac
fi

if command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now docker
fi

docker info >/dev/null 2>&1 || fail "Docker instalado, mas o daemon nao esta acessivel."

log "Preparando requisito do OpenSearch"
printf 'vm.max_map_count=262144\n' > /etc/sysctl.d/99-crm-opensearch.conf
sysctl -q -w vm.max_map_count=262144 >/dev/null \
    || fail "Nao foi possivel configurar vm.max_map_count para o OpenSearch."

if [[ ! -f "$ENV_FILE" ]]; then
    [[ -f "$ENV_TEMPLATE" ]] || fail "Modelo .env.vm.example nao encontrado."
    cp "$ENV_TEMPLATE" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    log "Arquivo .env.local criado a partir do modelo"
    echo "Preencha as senhas e credenciais em: $ENV_FILE"
    echo "Depois execute novamente: sudo ./instalar-vm.sh"
    exit 0
fi

set_env_value() {
    local key="$1"
    local value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

remove_env_key() {
    local key="$1"
    if grep -Eq "^[[:space:]]*${key}=" "$ENV_FILE"; then
        sed -i -E "/^[[:space:]]*${key}=/d" "$ENV_FILE"
        log "Variavel obsoleta removida do .env.local: $key"
    fi
}

sync_missing_env_keys() {
    local line=""
    local key=""
    local added=0

    [[ -f "$ENV_TEMPLATE" ]] || return 0
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] || continue
        key="${line%%=*}"
        if ! grep -q "^${key}=" "$ENV_FILE"; then
            printf '%s\n' "$line" >> "$ENV_FILE"
            added=$((added + 1))
        fi
    done < "$ENV_TEMPLATE"

    if (( added > 0 )); then
        log "$added variavel(is) nova(s) adicionada(s) ao .env.local sem alterar valores existentes"
    fi
}


sync_missing_env_keys
remove_env_key ACME_API_ACTION_INSERT_PATH
remove_env_key ACME_API_ACTION_HISTORY_PATH

host_uid="${SUDO_UID:-1000}"
host_gid="${SUDO_GID:-1000}"
if [[ "$host_uid" == "0" ]]; then host_uid=1000; fi
if [[ "$host_gid" == "0" ]]; then host_gid=1000; fi
set_env_value HOST_UID "$host_uid"
set_env_value HOST_GID "$host_gid"

mkdir -p \
    storage/cargas \
    storage/cargas/inbox/acme \
    storage/cargas/inbox/globex \
    storage/anexos \
    storage/cache \
    storage/logs \
    storage/runtime \
    storage/integracoes/saida/temporarios \
    storage/integracoes/saida/prontos \
    storage/integracoes/saida/enviados \
    storage/integracoes/saida/erros
chown -R "$host_uid:$host_gid" app storage
chmod -R u+rwX,g+rwX storage

available_kb="$(awk '/MemAvailable:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
if [[ "$available_kb" =~ ^[0-9]+$ ]] && (( available_kb > 0 && available_kb < 6000000 )); then
    echo "AVISO: a VM possui menos de 6 GB de memoria disponivel. A stack completa pode ficar lenta."
fi

check_disk_space() {
    local label="$1"
    local path="$2"
    local free_kb

    free_kb="$(df -Pk -- "$path" 2>/dev/null | awk 'END {print $4}')"
    [[ "$free_kb" =~ ^[0-9]+$ ]] || return 0
    if (( free_kb < 3145728 )); then
        fail "Ha menos de 3 GB livres no disco de $label ($path). Libere espaco antes de continuar."
    fi
    if (( free_kb < 10485760 )); then
        echo "AVISO: ha menos de 10 GB livres no disco de $label ($path)."
    fi
}

check_disk_space "projeto" "$PROJECT_DIR"
docker_root_dir="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
if [[ -n "$docker_root_dir" && -d "$docker_root_dir" ]]; then
    project_device="$(stat -c '%d' "$PROJECT_DIR" 2>/dev/null || true)"
    docker_device="$(stat -c '%d' "$docker_root_dir" 2>/dev/null || true)"
    if [[ -z "$project_device" || "$project_device" != "$docker_device" ]]; then
        check_disk_space "Docker" "$docker_root_dir"
    fi
fi

MYSQL_CONFIG_FILE="$PROJECT_DIR/docker/mysql/conf.d/my.cnf"
[[ -f "$MYSQL_CONFIG_FILE" ]] || fail "Configuracao do MySQL nao encontrada: $MYSQL_CONFIG_FILE"

mysql_was_running="$(docker inspect --format '{{.State.Running}}' crm_mysql 2>/dev/null || echo false)"
clickhouse_was_running="$(docker inspect --format '{{.State.Running}}' crm_clickhouse 2>/dev/null || echo false)"
chmod 0644 "$MYSQL_CONFIG_FILE"

COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$PROJECT_DIR/docker-compose.yml")

container_status() {
    local container="$1"
    docker inspect \
        --format '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.ExitCode}}' \
        "$container" 2>/dev/null || true
}

show_container_diagnostics() {
    local container="$1"
    local image=""

    docker inspect "$container" \
        --format 'container={{.Name}} estado={{.State.Status}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}sem-healthcheck{{end}} codigo={{.State.ExitCode}}' \
        >&2 2>/dev/null || true
    docker logs --tail 150 "$container" >&2 || true

    # O ClickHouse envia apenas mensagens resumidas ao stdout; a excecao que
    # encerra o servidor fica no volume /var/log/clickhouse-server. Le o volume
    # em um container temporario, sem rede e sem alterar os dados.
    if [[ "$container" == "crm_clickhouse" ]]; then
        image="$(docker inspect --format '{{.Config.Image}}' "$container" 2>/dev/null || true)"
        if [[ -n "$image" ]]; then
            echo "ULTIMO ERRO INTERNO DO CLICKHOUSE:" >&2
            docker run --rm --network none \
                --volumes-from "$container" \
                --entrypoint sh "$image" \
                -c 'if [ -s /var/log/clickhouse-server/clickhouse-server.err.log ]; then tail -n 100 /var/log/clickhouse-server/clickhouse-server.err.log; else echo "Arquivo de erro interno ainda nao foi criado."; fi' \
                >&2 2>/dev/null || true
        fi
    fi
}

wait_for_container() {
    local service="$1"
    local container="$2"
    local expected="$3"
    local timeout="$4"
    local deadline=$((SECONDS + timeout))
    local state=""
    local runtime_status=""
    local health_status=""
    local exit_code=""

    while (( SECONDS < deadline )); do
        state="$(container_status "$container")"
        IFS='|' read -r runtime_status health_status exit_code <<< "$state"

        case "$expected" in
            healthy)
                if [[ "$runtime_status" == "running" && "$health_status" == "healthy" ]]; then
                    printf 'OK        %-24s healthy\n' "$service"
                    return 0
                fi
                ;;
            running)
                if [[ "$runtime_status" == "running" ]]; then
                    printf 'OK        %-24s running\n' "$service"
                    return 0
                fi
                ;;
            completed)
                if [[ "$runtime_status" == "exited" ]]; then
                    if [[ "$exit_code" == "0" ]]; then
                        printf 'OK        %-24s concluido\n' "$service"
                        return 0
                    fi
                    show_container_diagnostics "$container"
                    fail "$service terminou com codigo $exit_code."
                fi
                ;;
            *) fail "Estado esperado invalido para $service: $expected" ;;
        esac

        if [[ "$runtime_status" == "exited" || "$runtime_status" == "dead" ]]; then
            show_container_diagnostics "$container"
            fail "$service parou antes de ficar pronto (estado=$runtime_status, codigo=${exit_code:-desconhecido})."
        fi

        sleep 3
    done

    show_container_diagnostics "$container"
    fail "Tempo limite de ${timeout}s aguardando $service ficar $expected."
}

cleanup_clickhouse_system_logs() {
    local cleanup_sql="$PROJECT_DIR/docker/clickhouse/cleanup-low-memory.sql"
    local attempt

    [[ -f "$cleanup_sql" ]] || return 0
    log "Limpando logs internos antigos do perfil ClickHouse de baixa memoria"
    for attempt in {1..12}; do
        if "${COMPOSE[@]}" exec -T clickhouse sh -lc \
            'clickhouse-client --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" --multiquery' \
            < "$cleanup_sql"; then
            echo "OK        Logs ClickHouse          perfil de baixa memoria aplicado"
            return 0
        fi
        sleep 5
    done

    show_container_diagnostics crm_clickhouse
    fail "Nao foi possivel limpar os logs internos antigos do ClickHouse."
}

log "Validando configuracao Docker Compose"
"${COMPOSE[@]}" config --quiet

if [[ "$mysql_was_running" == "true" || "$clickhouse_was_running" == "true" ]]; then
    # Faz isso antes do primeiro `compose up`: se alguma alteracao do Compose
    # exigir a recriacao do MySQL, o Debezium ja nao estara lendo o binlog.
    log "Pausando workers e CDC antes de atualizar MySQL e ClickHouse"
    for cdc_container in crm_integration_worker crm_cdc_worker crm_cdc_retry_worker crm_debezium; do
        if [[ "$(docker inspect --format '{{.State.Running}}' "$cdc_container" 2>/dev/null || echo false)" == "true" ]]; then
            docker stop --time 60 "$cdc_container" >/dev/null
        fi
    done
fi

log "Subindo infraestrutura e banco de dados"
"${COMPOSE[@]}" up -d mysql redis-cache redis-queue opensearch opensearch-dashboards clickhouse redpanda

if [[ "$mysql_was_running" == "true" ]]; then
    log "Reiniciando o MySQL para carregar a configuracao validada"
    "${COMPOSE[@]}" restart mysql
fi

if [[ "$clickhouse_was_running" == "true" ]]; then
    log "Reiniciando o ClickHouse para carregar os limites de memoria validados"
    "${COMPOSE[@]}" restart clickhouse
fi

log "Aguardando o MySQL ficar saudavel"
wait_for_container "MySQL" "crm_mysql" "healthy" 300

log "Provisionando e validando o acesso MySQL do Debezium"
"${COMPOSE[@]}" exec -T mysql bash /docker-entrypoint-initdb.d/03-configure-debezium-user.sh

log "Validando todas as migrations em banco temporario"
bash "$PROJECT_DIR/scripts/verificar-migrations.sh"

log "Criando e atualizando toda a estrutura do banco"
bash "$PROJECT_DIR/scripts/migrate.sh"

log "Confirmando que nenhuma migration ficou pendente"
migration_status="$(bash "$PROJECT_DIR/scripts/migrate.sh" --status)"
printf '%s\n' "$migration_status"
if ! grep -q 'Pendentes: 0' <<< "$migration_status"; then
    fail "A instalacao terminou a etapa de banco com migrations pendentes."
fi

# O registrador e um job descartavel. Remove a execucao anterior para que ele
# sempre use o script atual e seus logs contenham somente a tentativa corrente.
if docker inspect crm_debezium_register >/dev/null 2>&1; then
    docker rm -f crm_debezium_register >/dev/null
fi

log "Construindo e subindo a aplicacao e os demais servicos"
"${COMPOSE[@]}" up -d --build

log "Aguardando todos os servicos essenciais ficarem prontos"
wait_for_container "MySQL" "crm_mysql" "healthy" 300
wait_for_container "Redis Cache" "crm_redis_cache" "healthy" 180
wait_for_container "Redis Queue" "crm_redis_queue" "healthy" 180
wait_for_container "OpenSearch" "crm_opensearch" "healthy" 600
wait_for_container "ClickHouse" "crm_clickhouse" "healthy" 300
cleanup_clickhouse_system_logs
wait_for_container "Redpanda" "crm_redpanda" "healthy" 300
wait_for_container "Debezium" "crm_debezium" "healthy" 420
wait_for_container "Debezium Register" "crm_debezium_register" "completed" 900
wait_for_container "PHP-FPM" "crm_php" "healthy" 240
wait_for_container "Apache" "crm_apache" "running" 120
wait_for_container "OpenSearch Dashboards" "crm_opensearch_dashboards" "healthy" 300

log "Gerando autoload otimizado do PHP"
"${COMPOSE[@]}" exec -T php composer install --no-interaction --no-dev --optimize-autoloader

log "Validando sintaxe PHP antes de ativar as integracoes"
"${COMPOSE[@]}" exec -T php sh -c 'find src console public -type f -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
"${COMPOSE[@]}" exec -T php php -r 'require "/var/www/html/src/Config/Database.php"; exit(class_exists("CRM\\Config\\Database", false) ? 0 : 1);'

log "Executando validacao geral de DNS, extensoes, conexoes e autenticacao"
"${COMPOSE[@]}" exec -T php php console/infra_validar.php --attempts=12 --interval=5

log "Inicializando Redis, OpenSearch, ClickHouse e fluxo CDC"
"${COMPOSE[@]}" exec -T php php console/infra_inicializar.php --reindex --batch=500

log "Garantindo workers de integracao, CDC e reprocessamento ativos"
"${COMPOSE[@]}" up -d integration-worker cdc-worker cdc-retry-worker
wait_for_container "Integration Worker" "crm_integration_worker" "healthy" 240
wait_for_container "CDC Worker" "crm_cdc_worker" "healthy" 240
wait_for_container "CDC Retry Worker" "crm_cdc_retry_worker" "healthy" 240

configure_timezone() {
    # O cron do host dispara no fuso do sistema. Um cron iniciado antes de o fuso
    # ser configurado continua com o fuso antigo em cache (achado 2026-09-22:
    # jobs de horario fixo disparando 3h adiantados), entao reinicia o cron.
    command -v timedatectl >/dev/null 2>&1 || return 0
    if [[ "$(timedatectl show -p Timezone --value 2>/dev/null)" != "America/Sao_Paulo" ]]; then
        log "Configurando fuso horario da VM: America/Sao_Paulo"
        timedatectl set-timezone America/Sao_Paulo
    fi
}

install_cron_jobs() {
    local cron_dir="$PROJECT_DIR/scripts/cron"
    local file name

    [[ -d "$cron_dir" ]] || return 0
    if [[ "$PROJECT_DIR" != "/opt/crm-dev-docker" ]]; then
        echo "AVISO: os crons e scripts operacionais assumem /opt/crm-dev-docker; agendamentos NAO foram instalados (projeto em $PROJECT_DIR)."
        return 0
    fi

    log "Instalando agendamentos (cron) e rotacao de logs"
    for file in "$cron_dir"/*.cron; do
        [[ -f "$file" ]] || continue
        name="$(basename "$file" .cron)"
        install -m 0644 -o root -g root "$file" "/etc/cron.d/$name"
    done
    for file in "$cron_dir"/*.logrotate; do
        [[ -f "$file" ]] || continue
        name="$(basename "$file" .logrotate)"
        install -m 0644 -o root -g root "$file" "/etc/logrotate.d/$name"
    done
    chmod +x "$PROJECT_DIR"/scripts/*.sh 2>/dev/null || true
    mkdir -p "$PROJECT_DIR/backups/mysql"
    chmod 700 "$PROJECT_DIR/backups"
    if command -v systemctl >/dev/null 2>&1; then
        systemctl restart cron 2>/dev/null || systemctl restart crond 2>/dev/null || true
    fi
    echo "OK        Agendamentos            $(ls "$cron_dir"/*.cron | wc -l) job(s) em /etc/cron.d"
}

configure_timezone
install_cron_jobs

log "Conferindo containers"
"${COMPOSE[@]}" --profile workers ps -a

log "Validando ferramentas de administracao e testes"
command -v curl >/dev/null 2>&1 || fail "curl nao foi instalado."
command -v jq >/dev/null 2>&1 || fail "jq nao foi instalado."
jq --version
"${COMPOSE[@]}" exec -T php php -v | head -n 1

log "Executando validacao final do baseline"
bash "$PROJECT_DIR/scripts/validar-projeto.sh"

crm_version="$(tr -d '\r\n' < "$PROJECT_DIR/VERSION")"
vm_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
vm_ip="${vm_ip:-IP_DA_VM}"

cat <<EOF

Instalacao concluida.
Versao:                 $crm_version

CRM:                    http://$vm_ip:8080
OpenSearch Dashboards:  http://$vm_ip:5601
ClickHouse HTTP:        http://$vm_ip:8123
ClickHouse nativo:      $vm_ip:9000
Debezium:               http://$vm_ip:8083
Integration Worker:      ativo no container crm_integration_worker
Agendamentos (cron):     carteira de acordos, rotinas de carga, backup MySQL 03:30,
                         limpeza de staging, monitor de disco e de saude do CDC

Portas de infraestrutura (MySQL, Redis, OpenSearch, ClickHouse, Redpanda, Debezium)
ficam publicadas somente em 127.0.0.1; expostas na rede: 8080 (CRM) e 5601 (Dashboards, com login).
Firewall (ufw) e restricao de rede continuam sendo decisao da infraestrutura.
Antes de liberar a VM em producao, revise o .env.local e as regras de firewall.
EOF
