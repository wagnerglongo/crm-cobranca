# Instalação completa em VM Linux

O `instalar-vm.sh` prepara uma VM Ubuntu ou Debian para executar toda a stack do CRM.

## Pré-requisitos

- VM Linux Ubuntu ou Debian.
- Acesso root ou usuário com `sudo`.
- Acesso à internet para instalação do Docker e download das imagens/dependências.
- Recursos compatíveis com a stack completa. Para produção, prefira a arquitetura distribuída definida para banco, backend e aplicação.

## Primeira execução

No diretório do projeto:

```bash
chmod +x instalar-vm.sh
sudo ./instalar-vm.sh
```

Se `.env.local` não existir, o instalador cria o arquivo a partir de `.env.vm.example` e interrompe a instalação para configuração segura.

Edite:

```bash
nano .env.local
```

Preencha principalmente:

- senhas MySQL e usuário Debezium;
- senhas Redis;
- credenciais ClickHouse/OpenSearch quando aplicável;
- URL e credenciais ACME;
- `APP_URL` e demais parâmetros do ambiente.

Execute novamente:

```bash
sudo ./instalar-vm.sh
```

## O que o instalador executa

1. Valida o pacote e arquivos obrigatórios.
2. Instala ferramentas auxiliares e Docker Engine/Compose quando necessário.
3. Configura requisitos do OpenSearch no host.
4. Cria e ajusta permissões dos diretórios persistentes em `storage/`.
5. Valida `docker-compose.yml`.
6. Sobe MySQL, Redis, OpenSearch, ClickHouse e Redpanda.
7. Provisiona o usuário MySQL do Debezium.
8. Valida toda a cadeia de migrations em banco temporário.
9. Aplica migrations pendentes e exige `Pendentes: 0`.
10. Sobe PHP-FPM, Apache, Debezium, Dashboards e workers.
11. Registra/atualiza o connector Debezium.
12. Instala dependências PHP de produção com `--no-dev`.
13. Valida sintaxe, conexões e autenticação interna da infraestrutura.
14. Inicializa Redis, OpenSearch, ClickHouse e projeções CDC.
15. Confirma os serviços e workers essenciais.
16. Configura o fuso `America/Sao_Paulo` e instala os agendamentos (`scripts/cron/*.cron` em `/etc/cron.d`, rotação de logs em `/etc/logrotate.d`) e a pasta `backups/mysql`.
17. Roda `scripts/validar-projeto.sh` e mostra as URLs finais.

O instalador é idempotente: pode ser reexecutado a qualquer momento (inclusive para atualizar uma VM existente depois de um `git pull`). Ele assume o projeto em `/opt/crm-dev-docker` para instalar os agendamentos (os scripts operacionais usam esse caminho).

## Portas

| Serviço | Publicação |
| --- | --- |
| CRM / Apache | `8080` (rede) |
| OpenSearch Dashboards | `5601` (rede, protegido por login do Pactua + permissão `dashboards.acessar`) |
| MySQL 3307, Redis 6379/6380, OpenSearch 9200/9600, ClickHouse 8123/9000, Redpanda 19092, Debezium 8083 | somente `127.0.0.1` da VM |

Regras de firewall (ex.: `ufw`) e restrição de origem são decididas pela infraestrutura.

## Agendamentos instalados

| Job | Frequência |
| --- | --- |
| Reconciliação da carteira de acordos (valida pagamento com a ACME nos dias-gatilho antes de quebrar por carência) | a cada 15 min |
| Rotinas de carga SFTP | a cada 5 min |
| Backup do MySQL (dump em `backups/mysql`, 5 mais recentes) | diário 03:30 |
| Limpeza de staging antigo | diário 04:15 |
| Purge seguro de binlog / monitor de disco / monitor de saúde do CDC | 15 min / 30 min / 5 min |

Para conferir: `ls /etc/cron.d` e os logs em `/var/log/*.log`.

## Migrations

Status:

```bash
sudo scripts/migrate.sh --status
```

Aplicação:

```bash
sudo scripts/migrate.sh
```

O controle `crm_schema_migrations` registra checksum de cada arquivo. Uma migration já aplicada **não deve ser editada**. Alterações de schema devem ser feitas em uma nova migration.

Para um banco legado que já contém toda a estrutura, mas ainda não possui o controle automático, use `--adopt-existing` somente depois de confirmar que o schema corresponde ao pacote:

```bash
sudo scripts/migrate.sh --adopt-existing
sudo scripts/migrate.sh
```

## Conferência após instalação

```bash
docker compose --env-file .env.local -f docker-compose.yml ps
sudo bash scripts/validar-projeto.sh
```

Infraestrutura detalhada:

```bash
docker compose --env-file .env.local -f docker-compose.yml \
  exec -T php php console/verificar_infra.php
```

Diagnóstico de desempenho:

```bash
sudo bash scripts/diagnostico-performance.sh
```

## Atualização de ambiente

Evite `docker compose down` quando a intenção for recriar apenas um serviço. Para alterações de variáveis de uma integração, recrie somente os serviços consumidores.

Exemplo ACME:

```bash
docker compose --env-file .env.local -f docker-compose.yml \
  up -d --force-recreate php apache integration-worker
```

Isso evita derrubar MySQL e interromper desnecessariamente o CDC.

## Migração com dados reais

O instalador cria a estrutura, mas não transfere dados de outra VM. Uma migração completa exige pelo menos:

- dump/restore do MySQL;
- cópia do `storage/anexos` e demais arquivos persistentes necessários;
- `.env.local` do ambiente, tratado como segredo e nunca versionado.

OpenSearch e ClickHouse são projeções e podem ser reconstruídos pelos mecanismos do CRM quando aplicável.
