# Checklist de entrega (cada mudança em produção)

Sem burocracia de Merge Request: o desenvolvedor responde estes pontos na mensagem do commit (ou no aviso ao TI) sempre que a mudança for para produção. Se a resposta for "não", basta escrever "não".

| # | Pergunta | Como saber / o que fazer |
|---|---|---|
| 1 | **O que mudou e por quê?** | Resumo em 1–2 frases (o commit já traz). |
| 2 | **Como testar?** | Passo a passo curto na tela, ou o teste automatizado que cobre. |
| 3 | **Mexeu no banco? Criou migration?** | Listar o arquivo (`app/database/migrations/NNN_...`). Aplicar com `sudo scripts/migrate.sh` **depois de um backup manual** (`sudo scripts/backup-mysql.sh`). Migration não tem "down". |
| 4 | **Mudou o `.env.local`?** | Listar variável nova/alterada. `.env.vm.example` deve trazer a chave nova. Recriar quem usa: `docker compose up -d --force-recreate php apache integration-worker`. |
| 5 | **Nova dependência (Composer/imagem)?** | Se sim: `docker compose up -d --build` e conferir `composer.lock` no commit. |
| 6 | **Precisa reiniciar worker?** | Se mexeu em código de worker/integração/CDC: `docker compose restart integration-worker cdc-worker cdc-retry-worker`. |
| 7 | **Precisa reiniciar container / mexeu no `docker-compose.yml` ou Apache?** | `docker compose config -q`, depois recriar serviço a serviço conferindo saúde. |
| 8 | **Precisa rodar script adicional?** | Ex.: reconciliação de dados. Descrever o comando e se é execução única. |
| 9 | **Risco conhecido / impacto esperado?** | Quem é afetado (operadores, cron, credor) e o que observar depois. |
| 10 | **Como voltar atrás (rollback)?** | `git revert <commit>` + reiniciar workers; se houve migration, restaurar o backup anterior. Ver `COMANDOS.md`. |
| 11 | **Documentação atualizada?** | README/docs quando o comportamento ou a operação mudar. |

## Rotina de deploy (resumo)

1. `git status` limpo → suíte de testes verde (o `pre-commit` e o GitHub Actions rodam a mesma suíte).
2. Backup manual antes de migration.
3. Commit + `git push origin main:main`; conferir o CI verde na aba **Actions**.
4. Aplicar migrations pendentes (`scripts/migrate.sh --status` → `scripts/migrate.sh`) e reiniciar o que o checklist pedir.
5. Conferir: `docker compose ps`, `scripts/validar-projeto.sh`, uma tela real do Pactua.
6. Quando a versão for para produção: atualizar `VERSION` e marcar `git tag prod-AAAA-MM-DD`.

Quem vai de fato à VM (desenvolvedor ou TI) segue o mesmo roteiro; para incidente, ver [`RUNBOOK_SUPORTE.md`](RUNBOOK_SUPORTE.md).
