# Contrato da API do credor (ACME, fictício)

O CRM conversa com o sistema do credor por uma API REST com token OAuth2. Neste repositório o credor é a **ACME**, uma empresa fictícia, atendida pelo serviço `credor-simulado` do `docker-compose.yml` (código em [`docker/credor-simulado`](../docker/credor-simulado)).

O lado do CRM está em `app/src/Modules/Operacao/Services/AcmeApiClient.php`, atrás das interfaces `CredorAdapterInterface` (consultas e acordo) e `IntegracaoSaidaAdapterInterface` (envios assíncronos pela fila de saída). Para integrar um credor real, basta escrever um adapter novo e registrá-lo em `CredorAdapterFactory` / `IntegracaoSaidaAdapterFactory`; o resto do CRM não muda.

O teste `app/tests/Integration/CredorSimuladoContratoTest.php` roda o cliente de verdade contra o simulador, então uma mudança de rota ou de payload feita só de um lado quebra o CI.

## Autenticação

`POST /oauth/token` (form `grant_type=password&username=...&password=...`) devolve `{ "access_token", "token_type", "expires_in" }`.

As demais chamadas usam `Authorization: Bearer <token>`. Token recusado devolve `401` com `{"Message": "Authorization has been denied for this request."}`; o cliente renova o token uma vez e repete a chamada.

No CRM, o token fica em cache no Redis (com cópia em arquivo e lock para que dois workers não renovem ao mesmo tempo). Cada chamada passa por um circuit breaker e é registrada num log estruturado, com CPF/CNPJ mascarado.

## Rotas

| Método | Rota | Uso no CRM |
|---|---|---|
| GET | `/v1/devedores/{doc}` | Localização: telefones (`RecordsPhones`), e-mails (`RecordsMails`) e endereços (`Addresses`) |
| GET | `/v1/devedores/{doc}/telefones` · `/emails` · `/enderecos` | Listas de contato antes de incluir ou alterar um registro |
| POST | `/v1/contatos/telefones` · `/emails` · `/enderecos` | Envia um contato incluído/corrigido pelo operador (JSON com `DocNumber`) |
| PUT | `/v1/devedores/{doc}/telefones/{id}/status/{tipo}/{status}` | Altera tipo/status de telefone (endereço: mesmo formato; e-mail: só `/status/{status}`) |
| GET | `/v1/catalogos/tipos-contato/{1\|2\|3}` · `/v1/catalogos/status-contato` | Tabelas de códigos, consultadas só quando chega um código desconhecido |
| GET | `/v1/devedores/{doc}/contratos/{contrato}/propostas` | Valor da dívida, planos (`ListProposalType`) e datas possíveis (`ListInstallmentDate`) |
| POST | `/v1/acordos` | Fecha o acordo escolhido (`NegotiationID`, `InstallmentDate`, `OwnerDescription`...) |
| GET | `/v1/devedores/{doc}/contratos/{contrato}/acordo` | Acordo vigente e parcelas; `404` com `"Dados do acordo não encontrados."` quando não há |
| GET | `/v1/parcelas/{id}/boleto` | Linha digitável e PDF em base64 |
| POST | `/v1/acionamentos` | Envia o acionamento (JSON: `DocNumber`, `Contract`, `ActionID`, `Notes`, `CreationDate`, `ScheduleDateTime`) |
| GET | `/v1/devedores/{doc}/acionamentos` | Histórico de acionamentos registrado no credor |

## Regras de negócio que o CRM aplica em cima da API

- Antes de enviar uma proposta, o CRM consulta o acordo vigente e a situação da dívida; dívida liquidada ou acordo ativo bloqueiam o envio (`ACME_DEBT_SETTLED`, `ACME_ACTIVE_AGREEMENT_EXISTS`).
- Um lock por contrato impede dois operadores (ou a API e a tela) de fecharem acordo ao mesmo tempo.
- A situação da dívida tem precedência sobre o texto do acordo: parcela paga ou dívida liquidada nunca é rebaixada para "quebrado" por causa de carência local.
- Acionamentos e contatos não são enviados na hora: entram na fila `integracao_eventos_saida` e o `integration-worker` entrega com retry exponencial e trilha de auditoria.

## Simulador

- Credenciais: `ACME_API_USERNAME` / `ACME_API_PASSWORD` do `.env.local` (padrão `demo` / `demo`).
- Dados determinísticos: o mesmo CPF sempre gera o mesmo devedor e a mesma dívida.
- O que o CRM grava (acordos, acionamentos, contatos) fica em um volume Docker (`credor_simulado_data`).
- Para a demonstração andar sozinha, uma parcela vencida há mais de dois dias passa a constar como paga.
