#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
COMPOSE=(docker compose --env-file .env.local -f docker-compose.yml)

# Prioriza o mesmo PHP 8.4 que executa o CRM. O Ubuntu pode ter um PHP CLI
# antigo instalado para outras ferramentas e ele nao deve executar a suite.
if [[ -f .env.local ]] && command -v docker >/dev/null 2>&1 \
  && "${COMPOSE[@]}" exec -T php php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' >/dev/null 2>&1; then
  if "${COMPOSE[@]}" exec -T php test -x vendor/bin/phpunit >/dev/null 2>&1; then
    exec "${COMPOSE[@]}" exec -T php vendor/bin/phpunit -c phpunit.xml
  fi
  echo "PHPUnit ainda nao esta instalado no container PHP do DEV." >&2
  echo "Instale uma vez com:" >&2
  echo "  docker compose --env-file .env.local -f docker-compose.yml exec -T php composer install --no-interaction" >&2
  exit 2
fi

if command -v php >/dev/null 2>&1 \
  && php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' >/dev/null 2>&1 \
  && [[ -x app/vendor/bin/phpunit ]]; then
  exec app/vendor/bin/phpunit -c app/phpunit.xml
fi

echo "PHP 8.4+ com PHPUnit nao encontrado no ambiente de testes." >&2
echo "No DEV Docker, suba o servico php e instale as dependencias de desenvolvimento:" >&2
echo "  docker compose --env-file .env.local -f docker-compose.yml up -d php" >&2
echo "  docker compose --env-file .env.local -f docker-compose.yml exec -T php composer install --no-interaction" >&2
exit 2
