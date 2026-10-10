#!/usr/bin/env bash
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

errors=0
warnings=0
run_tests=0

for arg in "$@"; do
    case "$arg" in
        --with-tests) run_tests=1 ;;
        -h|--help)
            echo "Uso: scripts/validar-projeto.sh [--with-tests]"
            exit 0
            ;;
        *)
            echo "Opcao invalida: $arg" >&2
            exit 2
            ;;
    esac
done

ok(){ echo "OK: $*"; }
warn(){ echo "AVISO: $*"; warnings=$((warnings + 1)); }
fail(){ echo "ERRO: $*"; errors=$((errors + 1)); }

required_files=(
    VERSION
    README.md
    docker-compose.yml
    instalar-vm.sh
    .env.example
    .env.vm.example
    scripts/migrate.sh
    scripts/verificar-migrations.sh
    scripts/testes.sh
    scripts/validar-arquitetura-works.php
    scripts/diagnostico-performance.sh
    app/composer.json
    app/phpunit.xml
    app/public/operacao/works.php
    app/public/operacao/dados_gerais.php
    app/public/operacao/dados_gerais_exportar.php
    app/src/Modules/Operacao/Controllers/WorksController.php
    app/src/Modules/Operacao/Services/WorksQueryService.php
    app/src/Modules/Operacao/Services/CsvStreamExporter.php
    app/database/migrations/088_estrategias_resultados_opensearch.sql
    database/migrations/crm_mysql84_migrations/crm_schema_inicial_mysql84.sql
    docs/INSTALACAO_VM_LINUX.md
    docs/COMANDOS.md
    docs/GUIA_API.md
    docker/credor-simulado/src/Simulador.php
    docs/CREDOR_API.md
    app/console/gerar_carga_demo.php
    app/tests/Integration/CredorSimuladoContratoTest.php
    docs/RUNBOOK_SUPORTE.md
)

for file in "${required_files[@]}"; do
    [[ -s "$file" ]] && ok "arquivo presente: $file" || fail "arquivo ausente ou vazio: $file"
done

historical_root=0
for pattern in README_PATCH_*.md README_HOTFIX_*.md PATCH_*_MANIFEST.txt; do
    for file in $pattern; do
        [[ -e "$file" ]] || continue
        echo "ERRO: artefato historico na raiz: $file"
        historical_root=1
    done
done
for file in aplicar-*.sh; do
    [[ -e "$file" ]] || continue
    echo "ERRO: script de patch solto na raiz: $file (patches antigos ficam em docs/arquivo-historico)"
    historical_root=1
done
[[ "$historical_root" -eq 0 ]] && ok "raiz sem READMEs/manifests historicos" || fail "raiz ainda contem artefatos historicos"

historical_scripts=0
for pattern in scripts/aplicar-patch-*.sh scripts/aplicar-hotfix-*.sh scripts/validar-patch-*.sh; do
    for file in $pattern; do
        [[ -e "$file" ]] || continue
        echo "ERRO: script historico presente: $file"
        historical_scripts=1
    done
done
[[ "$historical_scripts" -eq 0 ]] && ok "scripts permanentes separados de scripts historicos" || fail "scripts historicos ainda presentes"

if grep -Fq "README_PATCH_" README.md || grep -Fq "README_HOTFIX_" README.md; then
    fail "README principal ainda referencia documentacao historica de patch"
else
    ok "README principal descreve o baseline consolidado"
fi

COMPOSE=(docker compose --env-file .env.local -f docker-compose.yml)
PHP_RUNTIME=""
PHP_VERSION_TEXT=""

if [[ -f .env.local ]] && command -v docker >/dev/null 2>&1 \
    && "${COMPOSE[@]}" exec -T php php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' >/dev/null 2>&1; then
    PHP_RUNTIME="container"
    PHP_VERSION_TEXT="$("${COMPOSE[@]}" exec -T php php -r 'echo PHP_VERSION;' 2>/dev/null | tr -d '\r\n')"
elif command -v php >/dev/null 2>&1 \
    && php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' >/dev/null 2>&1; then
    PHP_RUNTIME="host"
    PHP_VERSION_TEXT="$(php -r 'echo PHP_VERSION;' 2>/dev/null | tr -d '\r\n')"
fi

if [[ "$PHP_RUNTIME" == "container" ]]; then
    ok "runtime PHP: container ${PHP_VERSION_TEXT}"
    if "${COMPOSE[@]}" exec -T php sh -lc 'find . -type f -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l >/dev/null'; then
        ok "sintaxe PHP da aplicacao"
    else
        fail "erro de sintaxe PHP"
    fi

    if "${COMPOSE[@]}" exec -T php test -f vendor/autoload.php >/dev/null 2>&1; then
        if "${COMPOSE[@]}" exec -T -e CRM_APP_ROOT=/var/www/html php php < scripts/validar-arquitetura-works.php >/dev/null 2>&1; then
            ok "smoke arquitetural do Pactua"
        else
            fail "smoke arquitetural do Pactua falhou"
        fi
    else
        warn "vendor/autoload.php nao encontrado no container; smoke arquitetural ignorado"
    fi
elif [[ "$PHP_RUNTIME" == "host" ]]; then
    ok "runtime PHP: host ${PHP_VERSION_TEXT}"
    php_failed=0
    while IFS= read -r -d '' file; do
        php -l "$file" >/dev/null 2>&1 || { echo "ERRO PHP: $file"; php_failed=1; }
    done < <(find app -type f -name '*.php' -not -path 'app/vendor/*' -print0)
    [[ "$php_failed" -eq 0 ]] && ok "sintaxe PHP da aplicacao" || fail "erro de sintaxe PHP"

    if [[ -f app/vendor/autoload.php ]]; then
        CRM_APP_ROOT="$ROOT_DIR/app" php scripts/validar-arquitetura-works.php >/dev/null 2>&1 \
            && ok "smoke arquitetural do Pactua" \
            || fail "smoke arquitetural do Pactua falhou"
    else
        warn "app/vendor/autoload.php nao encontrado; smoke arquitetural ignorado"
    fi
else
    fail "PHP 8.4+ nao encontrado no container nem no host"
fi

bash_failed=0
while IFS= read -r -d '' file; do
    bash -n "$file" >/dev/null 2>&1 || { echo "ERRO BASH: $file"; bash_failed=1; }
done < <(find scripts tests -type f -name '*.sh' -print0)
bash -n instalar-vm.sh >/dev/null 2>&1 || { echo "ERRO BASH: instalar-vm.sh"; bash_failed=1; }
[[ "$bash_failed" -eq 0 ]] && ok "sintaxe Bash" || fail "erro de sintaxe Bash"

if [[ -f .env.local ]] && command -v docker >/dev/null 2>&1; then
    if "${COMPOSE[@]}" config --quiet >/dev/null 2>&1; then
        ok "docker-compose.yml valido com .env.local"
    else
        fail "docker compose config falhou"
    fi
else
    warn ".env.local ou Docker indisponivel; docker compose config nao executado"
fi

if [[ -f app/composer.lock ]]; then
    ok "composer.lock presente"
else
    warn "composer.lock ausente no pacote; gere e versione o lock no repositorio de desenvolvimento para builds totalmente reproduziveis"
fi

if [[ "$run_tests" -eq 1 ]]; then
    if bash scripts/testes.sh; then
        ok "PHPUnit"
    else
        fail "PHPUnit falhou ou nao esta instalado"
    fi
fi

if [[ "$errors" -gt 0 ]]; then
    echo
    echo "Baseline reprovado: $errors erro(s), $warnings aviso(s)."
    exit 1
fi

echo
echo "Baseline validado: 0 erro(s), $warnings aviso(s)."
