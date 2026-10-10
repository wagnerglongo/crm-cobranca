# Inventário de integrações externas

O que o CRM consome fora da VM, para o TI saber o que depende de rede, credencial ou liberação de firewall. Valores reais ficam só no `.env.local` (nunca versionado); nomes das variáveis em `.env.example` / `.env.vm.example`.

| Integração | Situação | Para quê | Config | Saída de rede (a liberar) |
|---|---|---|---|---|
| **ACME** (API, credor fictício) | Simulador local (`credor-simulado`) | Consulta de acordo/dívida/simulações, fechamento de acordo, boletos, histórico de acionamentos, envio de acionamento (ActionID da tabulação) | `ACME_API_BASE_URL`, `ACME_API_TOKEN_BASE_URL`, `ACME_API_USERNAME`, `ACME_API_PASSWORD`, `ACME_API_TIMEOUT` e caches `ACME_API_*_CACHE_SECONDS` | Em produção, HTTPS para o host do credor; localmente, o container `credor-simulado` |
| **ACME** (arquivos) | Rotina agendada | Carga diária de contratos (rotina agendada 06:05) | Cadastrada no banco em **Administração → Rotinas de Carga** (host/porta/pasta/usuário/senha SFTP criptografados com `ROTINAS_CARGA_CHAVE_CRIPTO`) | SFTP (porta configurada na rotina) para o servidor da ACME |
| **SMTP** | Produção | Alertas por e-mail (falha de carga, disco, saúde do CDC, cron) | `SMTP_HOST/PORT/USERNAME/PASSWORD/FROM`, `ALERTA_EMAIL_DESTINO` (senha vazia = alerta desativado) | SMTP (587) para o servidor de e-mail |
| **GitHub** | Cópia do código + CI | `git push` e testes automáticos (GitHub Actions) | Chave SSH da VM | SSH/HTTPS para github.com |
| **Docker Hub / registries** | Build e instalação | Baixar imagens (MySQL, Redis, OpenSearch, ClickHouse, Redpanda, Debezium, PHP base) | — | HTTPS para os registries (necessário em instalação/atualização) |

## Integrações internas (mesma VM, para referência)
MySQL → Debezium → Redpanda → CDC workers → OpenSearch/ClickHouse; Redis Cache/Queue; Integration Worker (entrega assíncrona aos credores). Portas somente em `127.0.0.1`, exceto `8080` (CRM) e `5601` (Dashboards). Se o MySQL for movido para outra VM, esta tabela ganha a linha de conexão MySQL (app e Debezium → VM do banco).

## Regras práticas
- Credencial de credor mudou → atualizar `.env.local` e recriar `php apache integration-worker`.
- API de credor instável → ver o item 3.4 de [`RUNBOOK_SUPORTE.md`](RUNBOOK_SUPORTE.md).
