# Pactua CRM → Grafana

Saída de monitoramento do CRM para o Grafana da empresa (requisito de 2026-10-06).

> **O TI da empresa usa Zabbix (2026-10-07):** template e painel prontos em `../zabbix/README.md`. Esta pasta continua valendo para o coletor (seção 1), o token (seção 2) e os logs (seção 5); as seções 3 e 4 são para quem usa Prometheus.
Objetivo principal: **alerta de fora da VM**. Hoje o monitor (`verificar-saude-cdc.sh`) roda na própria VM; se ela cair, ninguém é avisado. Com o Grafana lendo o CRM a cada minuto, se a VM parar de responder o alerta `CrmForaDoAr` dispara.

## O que tem aqui

| Arquivo | Para quê |
|---|---|
| `../../../scripts/exportar-metricas.sh` | Coletor (cron, a cada minuto) → `storage/metrics/crm.prom` |
| `../../../scripts/cron/exportar-metricas.cron` | Agendamento do coletor |
| `app/public/metrics.php` | Entrega as métricas (formato Prometheus) com token |
| `alertas-crm.yml` | 15 regras de alerta prontas |
| `dashboard-works-crm.json` | Dashboard para importar no Grafana |
| `alloy-crm.alloy` | Grafana Alloy: logs → Loki (e métricas por push, opcional) |

## 1. Coletor (já ligado nesta VM)

Instalado em 2026-10-06 no crontab do usuário `works` (sem sudo):
```
* * * * * sg docker -c "/opt/crm-dev-docker/scripts/exportar-metricas.sh" >> /opt/crm-dev-docker/storage/logs/exportar-metricas.log 2>&1
```
Numa VM nova, `sudo ./instalar-vm.sh` instala a versão do sistema (`scripts/cron/exportar-metricas.cron`, como root). Se as duas existirem, só uma roda por vez (trava no script).
Validado de ponta a ponta em 2026-10-06 com um Prometheus usando a configuração da seção 3: coleta com token OK e as 15 regras carregadas sem erro.

## 2. Token

Gerado automaticamente em `/opt/crm-dev-docker/storage/metrics/token` (fora da pasta pública).
Para trocar: apague o arquivo; o coletor gera outro no minuto seguinte (atualize no Prometheus/Alloy).

## 3. Coleta pelo Prometheus da empresa (modo "pull", recomendado)

```yaml
scrape_configs:
  - job_name: crm
    scrape_interval: 60s
    metrics_path: /metrics.php
    scheme: https           # pelo proxy da empresa (HTTPS); ou http + 10.0.0.74:8080 direto
    authorization:
      type: Bearer
      credentials: "<conteúdo de storage/metrics/token>"
    static_configs:
      - targets: ["crm.exemplo.com.br"]   # ou "10.0.0.74:8080" com scheme http
```
O nome do job **precisa ser `crm`** (as regras e o dashboard usam `up{job="crm"}`).

Se o Prometheus da empresa não alcançar a VM, use o modo "push" pelo Alloy (bloco comentado no fim de `alloy-crm.alloy`).

## 4. Alertas e dashboard

- `alertas-crm.yml`: importar como regras do Prometheus/Mimir (ou recriar como "Alert rules" no Grafana com as mesmas consultas).
- `dashboard-works-crm.json`: Grafana → Dashboards → Import → escolher o datasource Prometheus.

Alertas principais: **CRM fora do ar**, coletor parado, container parado, CDC (Debezium) parado, OpenSearch desincronizado, disco > 85%, MySQL em swap, backup atrasado, carga diária da ACME não aplicada (seg-sáb até 9h), erro definitivo/fila acumulando no envio para o credor, rotina agendada parada.

## 5. Logs (Loki)

Com o Grafana Alloy instalado na VM e `alloy-crm.alloy` (trocar `LOKI_URL`):
- rotinas agendadas: `/var/log/{backup-mysql,purge-binlog-seguro,verificar-saude-cdc,verificar-disco,limpar-staging-antigo,executar-rotinas-carga,atualizar-carteira-acordos,exportar-metricas}.log`
- aplicação (API da ACME, documento já mascarado): `storage/logs/acme_api.log`, `storage/logs/integracoes_acme.jsonl`
- todos os containers `crm_*` (Apache com status e tempo de cada requisição, PHP, workers, MySQL, Debezium).

Consultas úteis no Loki: `{job="crm-containers", container="crm_apache"} |~ "\" 5[0-9][0-9] "` (erros 500), `{job="crm-rotinas"} |= "ERRO"`, `{job="crm-containers", container="crm_integration_worker"} |= "falhou"`.

## Métricas

| Métrica | O que é |
|---|---|
| `crm_coletor_execucao_timestamp_segundos`, `crm_metricas_idade_segundos` | Coletor vivo |
| `crm_coletor_bloco_ok{bloco}` | 1 = cada parte da coleta funcionou |
| `crm_container_rodando/saudavel/reinicios{container}` | Containers `crm_*` |
| `crm_debezium_conector_rodando`, `crm_debezium_task_rodando`, `crm_debezium_offset_timestamp_segundos` | CDC |
| `crm_cdc_lag_mensagens` | Mensagens do CDC ainda não projetadas |
| `crm_opensearch_status`, `crm_opensearch_documentos{indice}` | OpenSearch |
| `crm_mysql_rodando`, `crm_mysql_conexoes`, `crm_mysql_registros{entidade}`, `crm_mysql_memoria_rss_bytes`, `crm_mysql_swap_bytes` | MySQL |
| `crm_host_disco_uso_percentual`, `crm_host_memoria_disponivel_bytes`, `crm_host_swap_usado_bytes`, `crm_host_load1` | VM |
| `crm_backup_ultimo_timestamp_segundos`, `crm_backup_ultimo_tamanho_bytes` | Backup do MySQL |
| `crm_carga_ultima_aplicada_timestamp_segundos` | Carga diária (ACME) |
| `crm_integracao_eventos{integracao,status}`, `crm_integracao_ultimo_envio_timestamp_segundos{integracao}` | Fila de envio para o credor |
| `crm_acionamentos_hoje`, `crm_acordos_criados_hoje` | Operação do dia |
| `crm_rotina_ultimo_log_timestamp_segundos{rotina}` | Rotinas agendadas (cron) |
