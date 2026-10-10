# Comandos operacionais

Execute os comandos a partir da raiz do projeto.

## Stack

Subir/atualizar serviços:

```bash
docker compose --env-file .env.local -f docker-compose.yml up -d --build
```

Status:

```bash
docker compose --env-file .env.local -f docker-compose.yml ps
```

Logs gerais:

```bash
docker compose --env-file .env.local -f docker-compose.yml logs -f
```

Logs de um serviço:

```bash
docker compose --env-file .env.local -f docker-compose.yml logs -f mysql
```

Parar sem apagar volumes:

```bash
docker compose --env-file .env.local -f docker-compose.yml down
```

**Destrutivo — apaga os volumes de dados:**

```bash
docker compose --env-file .env.local -f docker-compose.yml down -v --remove-orphans
```

## Banco e migrations

Status das migrations:

```bash
sudo scripts/migrate.sh --status
```

Aplicar migrations pendentes:

```bash
sudo scripts/migrate.sh
```

Validar a cadeia completa em banco temporário:

```bash
sudo scripts/verificar-migrations.sh
```

Abrir cliente MySQL usando as variáveis do container:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec mysql \
  sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot "$MYSQL_DATABASE"'
```

## Infraestrutura

Validação geral do projeto:

```bash
sudo bash scripts/validar-projeto.sh
```

Diagnóstico dos serviços pela aplicação:

```bash
docker compose --env-file .env.local -f docker-compose.yml \
  exec -T php php console/verificar_infra.php
```

Diagnóstico de performance:

```bash
sudo bash scripts/diagnostico-performance.sh
```

Redis Cache:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec redis-cache \
  sh -lc 'redis-cli -a "$REDIS_CACHE_PASSWORD" ping'
```

Redis Queue:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec redis-queue \
  sh -lc 'redis-cli -a "$REDIS_QUEUE_PASSWORD" ping'
```

OpenSearch:

```bash
curl -fsS http://127.0.0.1:9200/_cluster/health?pretty
```

Redpanda:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec redpanda \
  rpk cluster info --brokers redpanda:9092
```

Debezium:

```bash
curl -fsS http://127.0.0.1:8083/connectors/crm-mysql-connector/status | python3 -m json.tool
```

## Testes DEV

Instalar dependências de desenvolvimento:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  composer install --no-interaction
```

Executar PHPUnit:

```bash
sudo bash scripts/testes.sh
```

## Cargas ACME

Processamento diário completo:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  php console/processar_acme_diario.php --max=50 --batch=1000
```

Somente aplicar cargas já importadas:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  php console/processar_acme_diario.php --sem-importar --batch=1000
```

Somente importar, sem aplicar:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  php console/processar_acme_diario.php --sem-aplicar --max=50
```

## Ambiente de demonstração

Gerar uma carga fictícia (CPFs válidos gerados, nada real) direto no inbox ACME e aplicar:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  php console/gerar_carga_demo.php --quantidade=300
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  php console/processar_acme_diario.php
```

Cadastrar as tabulações do credor e popular acionamentos/acordos usando a própria API REST:

```bash
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  php console/seed_tabulacoes_acme.php --confirmar
docker compose --env-file .env.local -f docker-compose.yml exec -T php \
  php console/popular_demo_api.php --url=http://apache --senha=SENHA_DO_ADMIN
```

O credor ACME é atendido pelo serviço `credor-simulado` (contrato em [`CREDOR_API.md`](CREDOR_API.md)).

## Deploy, rollback e restore (runbook)

Esta VM é a fonte de verdade; o GitHub é cópia + CI. Antes de qualquer mudança em produção:

```bash
git status                                   # árvore limpa?
docker compose exec -T php vendor/bin/phpunit   # 446+ testes (o pre-commit e o CI também rodam)
```

**Deploy (código PHP/app):** commitar (o pre-commit roda a suíte), `git push origin main:main` e conferir o CI verde na aba Actions. O código de `app/` é montado nos containers, então já vale; só workers de longa duração precisam reiniciar para recarregar código:

```bash
docker compose restart integration-worker cdc-worker cdc-retry-worker
```

**Deploy com migration:** `scripts/migrate.sh --status` (mostra pendentes), depois `scripts/migrate.sh`. Faça um backup manual antes (`sudo scripts/backup-mysql.sh`). O script recusa rodar se uma migration já aplicada foi alterada.

**Mudança de `docker-compose.yml`/Apache:** `docker compose config -q` valida; recrie serviço a serviço (`docker compose up -d <servico>`), conferindo saúde entre um e outro. Recriar o MySQL derruba o sistema por ~1 min; Debezium/Redpanda precisam voltar `RUNNING/healthy` (`curl localhost:8083/connectors/crm-mysql-connector/status`).

**Rollback de código:** `git revert <commit>` (nunca `reset --hard` em `main`), commit + push. Se for só um worker: reverta e reinicie os workers.

**Rollback de migration:** não existe "down". Restaure o backup anterior à migration (abaixo) e reverta o código. Por isso o backup manual antes de migrar.

**Restore do banco** (backups em `/opt/crm-dev-docker/backups/mysql`, diário 03:30, 5 dias; conteúdo de `stg_carga_canonica`/`cargas_linhas_raw` NÃO está no dump - reprocessar as cargas se precisar):

```bash
sudo gunzip -c /opt/crm-dev-docker/backups/mysql/crm-AAAAMMDD-HHMMSS.sql.gz | \
  docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP -h127.0.0.1 -uroot "$MYSQL_DATABASE"'
```

Depois do restore: reindexar OpenSearch se necessário e conferir o Debezium (pode precisar do reset de offset - RUNBOOK_SUPORTE.md, seção 5).

## Segurança de rede e acessos

- Portas de infra (MySQL 3307, Redis, OpenSearch, ClickHouse, Redpanda, Debezium) só em `127.0.0.1`; acesso remoto via túnel SSH (`ssh -L 3307:127.0.0.1:3307 usuario@10.0.0.74`).
- Externas: 22 (SSH), 8080 (app), 5601 (OpenSearch Dashboards).
- Firewall: `sudo ufw status verbose` (default deny incoming; 22/8080/5601 só de redes privadas). Se algo parar de responder por causa dele: `sudo ufw disable` e investigar. Portas publicadas pelo Docker não passam pelo `ufw` - a proteção delas é o bind em localhost.
- **Dashboards (5601):** login e senha do Pactua; só quem tem a permissão `dashboards.acessar` (por padrão só o perfil admin - conceda a outros na tela de perfis). Revogar vale em até 60 s.
- Monitor de saúde (a cada 5 min): e-mail se o Debezium ou algum container `crm_*` cair (`scripts/verificar-saude-cdc.sh`, log em `/var/log/verificar-saude-cdc.log`).
- Crons instalados: `ls /etc/cron.d` (cópias versionadas em `scripts/cron/`).

## Segredos e rotação

Segredos ficam em `.env.local` (fora do git). Trocar senha de MySQL/Redis exige alterar o `.env.local` **e** recriar os containers (`docker compose up -d`); a chave `ROTINAS_CARGA_CHAVE_CRIPTO` criptografa as senhas SFTP salvas no banco - trocá-la sem re-cadastrar as rotinas as torna ilegíveis. Nunca cole senhas em chat/issue; se colar, troque em seguida (senha do usuário da VM: `passwd`).
