# Runbook de suporte (primeiro atendimento pelo TI)

Objetivo: o TI conseguir diagnosticar e resolver os incidentes mais comuns sem depender do desenvolvedor, e saber a hora certa de escalar. Comandos de apoio: [`COMANDOS.md`](COMANDOS.md). Instalação: [`INSTALACAO_VM_LINUX.md`](INSTALACAO_VM_LINUX.md).

Todos os comandos abaixo rodam no diretório do projeto (`/opt/crm-dev-docker`). Em VM com Docker sem permissão direta, use `sudo`.

## 1. Primeiro passo em QUALQUER incidente (2 minutos)

```bash
docker compose ps                                   # algum container fora de "Up/healthy"?
df -h /                                             # disco acima de 85%?
free -h                                             # memória livre / swap em uso?
tail -n 20 /var/log/verificar-saude-cdc.log         # ultimo veredito do monitor de saude
curl -fsS http://127.0.0.1:8083/connectors/crm-mysql-connector/status | python3 -m json.tool
docker compose exec -T php php console/verificar_infra.php
```

Regra de ouro: **não derrube a stack inteira** (`down`). Recrie só o serviço com problema: `docker compose up -d --force-recreate <servico>`.

Alertas chegam por e-mail (`ALERTA_EMAIL_DESTINO` no `.env.local`): disco, saúde do CDC, falha de cron. Se o e-mail não chega, confira `SMTP_*` no `.env.local`.

## 2. Quando o TI resolve e quando escala para o desenvolvedor

**O TI resolve sozinho (pode agir sem perguntar):**
- Container parado/reiniciando: `docker compose up -d <servico>`.
- Reiniciar workers: `docker compose restart integration-worker cdc-worker cdc-retry-worker`.
- Disco acima de 85%: seguir o item 3.3.
- VM/serviço fora do ar por infraestrutura (Proxmox, rede, DNS, certificado).
- Restaurar a VM pelo backup do Proxmox.
- Reinstalar/atualizar com `sudo ./instalar-vm.sh` (idempotente).

**Escalar para o desenvolvedor quando:**
- O mesmo container cai de novo em menos de 1 hora depois de recriado.
- Erro de aplicação (tela em branco, "Não foi possível...", erro 500) com containers saudáveis.
- Debezium fora de `RUNNING/RUNNING` e o item 3.1 não resolveu.
- Dados divergentes (acordo/contrato errado, carga que aplicou errado). **Não** rode scripts de dados por conta própria.
- Qualquer coisa que peça `zerar-dados-operacionais.sh`, apagar tabela ou editar o banco à mão.
- Mensagem da credor que mude regra de negócio ou credencial.

**Ao escalar, mande:** horário do problema, o que apareceu na tela, saída de `docker compose ps`, as últimas 100 linhas do log do serviço (`docker compose logs --tail=100 <servico>`) e o que já foi tentado.

## 3. Incidentes conhecidos

### 3.1 Debezium / CDC parado (OpenSearch e dashboards param de atualizar)
Sintoma: e-mail "conector Debezium fora de RUNNING", dashboards desatualizados, binlog crescendo.
1. `curl -fsS http://127.0.0.1:8083/connectors/crm-mysql-connector/status` — esperado `RUNNING` e `RUNNING`.
2. Se o container estiver parado/sem memória: `docker compose up -d --force-recreate debezium` (o limite de memória foi ampliado para 1,5 GB em 2026-09-17; era a causa das quedas). O conector normalmente volta sozinho.
3. Se continuar `FAILED` (erro `EOFException ... binlog`): **escalar** — exige recriar o conector e zerar o offset (procedimento na seção 5).
Observação: `purge-binlog-seguro.sh` propositalmente **não** apaga binlog enquanto o conector não está saudável; o disco pode encher se ficar dias assim.

### 3.2 Worker parado ou fila crescendo
Sintoma: integrações/acionamentos não chegam ao credor; projeções atrasadas.
```bash
docker compose ps integration-worker cdc-worker cdc-retry-worker
docker compose logs --tail=100 integration-worker
docker compose restart integration-worker cdc-worker cdc-retry-worker
docker compose exec redis-queue sh -lc 'redis-cli -a "$REDIS_QUEUE_PASSWORD" info keyspace'
```
Se após o restart o worker volta a acumular e o log mostra o mesmo erro repetido, escalar com o log.

### 3.3 Disco cheio (> 85%)
O monitor `verificar-disco.sh` avisa por e-mail. Já houve incidente real em 2026-09-15.
```bash
df -h /
docker system df
sudo du -sh /var/lib/docker/volumes/* | sort -h | tail
```
Causas típicas, na ordem: (1) staging das cargas — a limpeza roda todo dia 04:15 (`limpar-staging-antigo.cron`, mantém 7 dias); (2) binlog do MySQL preso porque o Debezium está parado (item 3.1); (3) logs. Não apague arquivos de `storage/cargas` nem volumes Docker à mão. Se o disco continuar subindo, escalar.

### 3.4 Integração ACME com erro
Sintoma: financeiro/acionamento da ACME não carrega, "Não foi possível atualizar pela API ACME".
- O sistema tem circuit breaker: após falhas seguidas ele para de chamar a ACME por 30 s e tenta de novo sozinho.
- Conferir credenciais e hosts no `.env.local` (`ACME_API_BASE_URL`, `ACME_API_TOKEN_BASE_URL`, usuário/senha) e se o host da ACME responde: `curl -sS -o /dev/null -w '%{http_code}\n' "$ACME_API_TOKEN_BASE_URL"`.
- Mudou `.env.local`? Recriar quem usa: `docker compose up -d --force-recreate php apache integration-worker`.
- ACME fora do ar por lado deles: aguardar; nada a corrigir aqui. Se a ACME mudou contrato/credencial: escalar.

### 3.5 Carga (importação) parada ou não processou
Sintoma: carga do dia da ACME não apareceu.
1. Tela **Administração → Rotinas de Carga**: status da última execução (`sucesso/falha/processando/bloqueada`). `processando` por mais de 3 min = provável travamento.
2. Log do agendador: `tail -n 50 /var/log/executar-rotinas-carga.log` (roda a cada 5 min).
3. Arquivo chegou? Conferir `storage/cargas/inbox/acme/` e se a pasta SFTP do credor tem o arquivo do dia.
4. Não reimportar por conta própria uma carga que já aplicou parte (risco de duplicar). Escalar com o nome do arquivo e o log.

### 3.6 CRM não abre / lento
```bash
docker compose ps apache php mysql
docker compose logs --tail=80 apache php
sudo bash scripts/diagnostico-performance.sh
```
MySQL fora: `docker compose logs --tail=100 mysql`; recriar o MySQL derruba o sistema ~1 min (avisar os operadores antes). Lento com tudo "healthy": verificar memória/swap (`free -h`) e escalar com a saída do diagnóstico.

### 3.7 Certificado / DNS
O CRM roda em HTTP na rede interna (porta 8080). Se houver proxy/certificado na frente, o tratamento é da infraestrutura (fora do escopo do repositório). Problema de nome/IP que impeça o acesso: checar DNS/roteamento primeiro; só escalar se o `curl http://IP:8080` na própria VM funcionar e de fora não.

## 4. Backup e restauração (resumo)
- **VM inteira:** Proxmox + Duplicati (responsabilidade do TI), com a VM ligada.
- **Banco:** dump lógico diário às 03:30 em `backups/mysql/` (5 mais recentes). Não inclui o *conteúdo* do staging/raw (reprocessa-se pelos arquivos).
- **Restore do banco:** `COMANDOS.md`, seção "Deploy, rollback e restore".
- **Rollback de código:** `git revert` do commit + reiniciar workers; não existe "down" de migration (restaurar backup anterior).

## 5. Recriar o conector Debezium (offset de binlog inválido)

Sintoma: a task do `crm-mysql-connector` fica `FAILED` com `EOFException: Failed to read next byte from position X`, em geral depois de um lote muito grande de escrita. O `scripts/purge-binlog-seguro.sh` se recusa a purgar enquanto o conector não estiver `RUNNING/RUNNING`, então nenhum evento se perde enquanto isso.

Procedimento (força um snapshot novo a partir do estado atual do banco):

```bash
API=http://127.0.0.1:8083/connectors/crm-mysql-connector
curl -fsS -X DELETE "$API"                                   # remove o conector
docker compose run --rm debezium-register                    # recria com a mesma config
curl -fsS -X PUT "$API/stop"                                 # para antes de mexer no offset
curl -fsS -X DELETE "$API/offsets"                           # descarta a posição inválida
curl -fsS -X PUT "$API/resume"                               # volta a ler e faz o snapshot
curl -fsS "$API/status" | python3 -m json.tool               # esperado: RUNNING / RUNNING
```

Depois, conferir a fila do CDC em **Administração → Integrações** e, se necessário, reindexar o OpenSearch.
