# Pactua CRM → Zabbix → Grafana

O TI da empresa usa **Zabbix** (o Grafana lê do Zabbix). Requisito de 2026-10-07.

O Zabbix lê o mesmo endereço de métricas do CRM (`/metrics.php`, protegido por token) com um item **HTTP agent**, e separa cada métrica com o pré-processamento "Prometheus pattern". **Não precisa instalar agente na VM.** O coletor na VM já roda a cada minuto (ver `../grafana/README.md`, seção 1).

| Arquivo | Para quê |
|---|---|
| `template-works-crm.yaml` / `.json` | Template do Zabbix (formato 6.0, importa no 6.0 ou mais novo); os dois têm o mesmo conteúdo |
| `dashboard-works-crm-zabbix.json` | Painel do Grafana usando o plugin Zabbix |
| `gerar_template.py`, `gerar_dashboard_grafana.py` | Geram os dois arquivos acima (métrica nova → editar e rodar de novo) |

## 1. Zabbix

1. **Importar o template:** Data collection → Templates → Import → `template-works-crm.yaml` ou `.json` (no 6.0: Configuration → Templates → Import).
2. **Criar o host** `Pactua CRM` (qualquer grupo; não precisa de interface) e vincular o template **Pactua CRM by HTTP**.
3. **Macros do host:**
   - `{$CRM.TOKEN}` = o token (tipo *Secret text*). É enviado separadamente; na VM: `cat /opt/crm-dev-docker/storage/metrics/token`.
   - `{$CRM.URL}` já vem `https://crm.exemplo.com.br/metrics.php`. Se o servidor Zabbix não resolver/alcançar esse nome, use `http://10.0.0.74:8080/metrics.php`.
4. Em 1–2 minutos aparece tudo em *Latest data*. As descobertas criam sozinhas os itens de cada container, integração com credor, rotina, índice do OpenSearch e parte da coleta.

## 2. Alertas (triggers)

| Alerta | Quando | Severidade |
|---|---|---|
| CRM fora do ar | 3 min sem resposta do `/metrics.php` (VM/Apache/PHP/rede caiu ou token recusado) | Disaster |
| Coletor de métricas parado | métricas com mais de 5 min por 5 min | High |
| Container X parado | 5 min | High |
| Container X com healthcheck falhando | 10 min | Average |
| CDC: fila > 50 mil e não diminui | 1 h | Average |
| OpenSearch amarelo/vermelho | 15 min | Average |
| OpenSearch desincronizado do MySQL | > 100 contratos de diferença por 2 h | Average |
| Disco da VM > 85% | 10 min | High |
| MySQL > 800M em swap | 30 min | Warning |
| Backup do MySQL atrasado | > 26 h | High |
| Carga diária da ACME não aplicada | > 30 h, seg–sáb depois das 9h | High |
| Integração X: evento com erro definitivo | 5 min | High |
| Integração X: fila acumulando | > 20 por 30 min | Average |
| Rotina X parada | > 1 h (executar-rotinas-carga, purge-binlog-seguro, verificar-disco) | Average |
| Parte X da coleta falhando | 10 min | Warning |

Os limites são macros do template (`{$CRM.DISCO.MAX}`, `{$CRM.BACKUP.ATRASO.MAX}` etc.): dá para mudar no host sem editar o template.
Rotinas: só são vigiadas as que têm a macro `{$CRM.ROTINA.ATRASO.MAX:"nome-da-rotina"}` maior que 0.
"Carga da ACME" usa o horário do **servidor Zabbix** (`dayofweek()`/`time()`): se ele estiver em UTC, trocar `090000` por `120000` no trigger.

## 3. Grafana

Com o plugin **Zabbix** (`alexanderzobnin-zabbix-app`) já ligado ao Zabbix da empresa:
Dashboards → New → Import → `dashboard-works-crm-zabbix.json`. No topo do painel, escolher o datasource Zabbix na lista **Zabbix**; o host já vem `Pactua CRM`.

20 quadros: CRM no ar, fila do CDC, OpenSearch, disco, último backup, última carga da ACME, acionamentos/acordos do dia, Debezium, MySQL em swap, MySQL × OpenSearch, erros no envio ao credor, containers, alertas ativos e gráficos (CDC, fila de envio, memória do MySQL e da VM, disco/carga, operação do dia).

## 4. Validação (2026-10-07)

Testado na VM com containers descartáveis (já removidos): **Zabbix 6.0.48** + **Grafana 13.0.2** + plugin Zabbix **6.9.1**.
- Import do template sem erro (YAML e JSON); host coletando `http://10.0.0.74:8080/metrics.php` com o token: 88 itens (61 descobertos), todos com valor e sem erro; 50 triggers carregados, nenhum com erro nem disparado.
- Token errado de propósito: o item mestre mostra "Response code 401" e o alerta **CRM fora do ar** disparou em ~3,5 min; com o token certo, resolveu sozinho.
- Grafana: import do painel e as consultas dos 19 quadros de métricas trouxeram dados. O quadro "Alertas ativos" consulta pelo navegador (não dá para testar por API).
- Não testado daqui: o endereço HTTPS pelo proxy (a VM não resolve `crm.exemplo.com.br`).
