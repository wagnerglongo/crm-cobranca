# Guia completo do Swagger e da autorização da Pactua CRM API

O Swagger da Pactua está disponível em `/api/docs/`. Ele apresenta os métodos, parâmetros, modelos JSON, exemplos de resposta, permissões exigidas e um botão para executar chamadas de teste.

A documentação é pública por padrão (`API_DOCS_REQUIRE_LOGIN=false`). Isso libera somente a página do Swagger: os dados e as operações continuam protegidos por Bearer token, permissões do usuário e escopo de credores/carteiras. Para exigir login também na página, configure `API_DOCS_REQUIRE_LOGIN=true`.

## 1. Página do Swagger e API são autorizações diferentes

Existem duas camadas independentes:

1. **Acesso à página `/api/docs/`:** pode ser público ou exigir a sessão normal do CRM, conforme `API_DOCS_REQUIRE_LOGIN`.
2. **Acesso aos métodos da API:** exige o header `Authorization: Bearer ...`, exceto a saúde pública e a emissão do token.

Estar logado no CRM não autoriza automaticamente os botões de execução do Swagger. Da mesma forma, um Bearer token válido não cria uma sessão na interface normal do CRM. Essa separação permite que sistemas externos usem a API sem depender de cookie de navegador.

## 2. Como emitir um token

Abra `POST /auth/token`, clique em **Try it out / Testar**, informe as credenciais e execute:

```json
{
  "login": "seu_usuario",
  "senha": "sua_senha",
  "nome": "Integração do ERP"
}
```

Campos principais da resposta:

- `access_token`: única credencial usada nas chamadas da API;
- `expires_in`: validade do access token em segundos;
- `expires_at`: data e hora exatas da expiração no fuso do CRM;
- `scopes`: capacidades técnicas concedidas ao token;
- `usuario`: usuário do CRM que será considerado autor das operações.

O valor integral do token aparece somente na emissão. O banco guarda hash e prefixo de identificação, nunca a credencial completa. Quando o tempo de `expires_in` terminar, gere outro token com usuário e senha; não existem métodos públicos de refresh ou revoke.

## 3. Como usar o botão Authorize

1. Copie somente o valor de `access_token`, que começa com `wrk_api_at_`.
2. Clique em **Authorize** no topo do Swagger.
3. Cole o token sem acrescentar a palavra `Bearer`.
4. Confirme em **Autorizar**.

A interface enviará automaticamente:

```http
Authorization: Bearer wrk_api_at_...
```

O token fica no `sessionStorage` da aba atual. Fechar a aba ou clicar em **Remover token desta aba** elimina esse valor do navegador.

Em uma integração fora do Swagger, o header completo deve ser montado pelo sistema cliente:

```bash
curl -H 'Authorization: Bearer wrk_api_at_SEU_TOKEN' \
  -H 'Accept: application/json' \
  http://localhost:8080/api/v1/auth/me
```

## 4. Scope, permissão e escopo de dados

Uma chamada protegida passa por três verificações:

| Verificação | Exemplo | Finalidade |
|---|---|---|
| Scope do token | `api` | Autoriza a finalidade técnica do token. |
| Permissão do usuário | `acordos.criar` | Autoriza a ação dentro do CRM. |
| Escopo de dados | credor/carteira | Limita quais clientes e contratos o usuário pode acessar. |

O token nunca concede mais poder que o usuário que o emitiu. Alterações posteriores no perfil, permissões ou escopo do usuário passam a valer nas próximas autenticações.

## 5. Autoria das operações

Em toda escrita, o CRM identifica o autor pelo Bearer token. O cliente da API não escolhe o operador e não consegue substituir essa identidade enviando um campo no JSON.

Acionamentos e acordos registram, conforme a operação:

- ID, nome e login do usuário autenticado;
- ID interno do token utilizado;
- `X-Request-ID` da requisição;
- data/hora e evento na auditoria;
- vínculo direto de autoria no acordo.

Assim, mesmo que uma integração envie duas chamadas iguais de acionamento, cada registro continua rastreável até o usuário e o token responsáveis.

## 6. Códigos HTTP mais importantes

| HTTP | Significado |
|---|---|
| `200` | Consulta executada. |
| `201` | Registro criado. |
| `401` | Bearer token ausente, inválido, expirado ou invalidado por alteração de credencial. |
| `403` | Scope, permissão ou acesso ao credor/carteira insuficiente. |
| `404` | Registro inexistente ou fora do escopo autorizado. |
| `409` | Conflito de regra de negócio, como acordo ativo ou dívida quitada. |
| `422` | Parâmetro ou JSON inválido. |
| `429` | Limite de requisições excedido; respeite `Retry-After`. |
| `502` | Falha na comunicação com a API ACME. |

Erros retornam um modelo padronizado:

```json
{
  "error": {
    "code": "ACME_ACTIVE_AGREEMENT_EXISTS",
    "message": "A ACME informa que já existe acordo ativo para este contrato.",
    "details": {},
    "request_id": "req_..."
  }
}
```

## 7. Identificadores públicos

A API de negócio usa identificadores compreensíveis para a integração:

- CPF ou CNPJ em `{documento}`: 11 dígitos para CPF ou 14 para CNPJ; a API remove pontuação;
- código do contrato em `{contrato}`, exatamente como usado pelo credor.

Não é necessário descobrir `pessoa_id` ou `contrato_id` do banco do CRM.

Consultas exatas recomendadas:

- `GET /clientes/documento/{documento}`;
- `GET /contratos/codigo/{contrato}`;
- `GET /acordos/documento/{documento}`;
- `GET /acordos/contrato/{contrato}`.

## 8. Tabulações e acionamentos

Primeiro consulte `GET /tabulacoes`. O campo `id` da tabulação escolhida deve ser enviado como `resultado_id`.

Métodos de escrita:

- `POST /acionamentos/contrato/{contrato}`: registra no contrato exato;
- `POST /acionamentos/documento/{documento}`: registra para a pessoa ou empresa identificada por CPF/CNPJ;
- `POST /acionamentos/lote`: registra de 1 a 100 itens, cada um com `contrato` ou `documento`.

Exemplo individual:

```json
{
  "resultado_id": 7,
  "canal": "whatsapp",
  "contato_utilizado": "11999999999",
  "observacao": "Mensagem enviada ao cliente.",
  "proximo_acionamento": "2026-08-20 10:00:00"
}
```

Exemplo em massa:

```json
{
  "resultado_id": 7,
  "canal": "ligacao",
  "observacao": "Campanha externa.",
  "itens": [
    {"contrato": "CONTRATO-123"},
    {"documento": "12345678000190"}
  ]
}
```

O lote é atômico: se um item for inválido, nenhum acionamento do lote é gravado. Não há header adicional de controle de repetição. Repetir uma chamada de acionamento cria um novo histórico, sempre com autoria e auditoria.

## 9. Único fluxo de criação de acordo: ACME

A API não oferece fechamento genérico de acordo. O único método de criação é:

`POST /acme/documento/{documento}/contrato/{contrato}/acordos`

O fluxo segue o Financeiro Pactua:

1. Consulte `GET /acme/documento/{documento}/contrato/{contrato}/simulacoes`.
2. Escolha uma proposta em `data.propostas` e uma data em `data.datas_primeira_parcela`.
3. Copie apenas o `negotiation_id` e a `data_primeira_parcela` escolhidos.
4. Envie o POST de fechamento.
5. O CRM consulta novamente a situação, envia a proposta para `/v1/acordos` da ACME e registra o acordo local com status de integração `enviado`.

Exemplo:

```json
{
  "negotiation_id": 321,
  "phone_id": 0,
  "data_primeira_parcela": "2026-09-20",
  "forma_pagamento": "boleto",
  "observacao": "Proposta escolhida pelo cliente."
}
```

O integrador não envia valor, quantidade de parcelas nem `OwnerDescription`. No fechamento, o CRM gera novamente as propostas, confirma que o ID e a data continuam disponíveis e usa os valores oficiais retornados pela ACME para registrar o acordo.

Antes de chamar o fechamento externo, a API bloqueia:

- contrato retirado pelo credor;
- fechamento simultâneo já em andamento no mesmo contrato;
- acordo local ativo (`pendente` ou `formalizado`);
- acordo ativo identificado pela ACME;
- acordo cumprido/quitado no CRM;
- dívida ou acordo liquidado/pago na ACME.

Essas são regras de negócio do próprio acordo. Uma repetição do fechamento não cria outro acordo porque o estado atual do contrato é conferido no CRM e na ACME.

## 10. Expiração do token

A API retorna `expires_in` em segundos. O padrão é 1800 segundos (30 minutos), configurável por `API_ACCESS_TOKEN_TTL`. Depois da expiração, o sistema cliente chama novamente `POST /auth/token` com usuário e senha. O Swagger usa somente o `access_token` no botão **Authorize**.

## 11. Request ID e diagnóstico

Cada resposta contém `X-Request-ID`; erros também trazem `error.request_id`. Guarde esse valor ao investigar uma falha. Ele permite localizar a operação sem registrar senha ou access token em log.

## 12. Uso seguro em produção

- Use HTTPS; não transmita tokens por HTTP fora de ambiente local controlado.
- Nunca coloque token em URL ou query string.
- Nunca grave senha ou access token no código-fonte.
- Use cofre de segredos ou variável protegida.
- Gere um novo access token quando o anterior expirar.
- Em `429`, aguarde o período indicado em `Retry-After`.
- Defina timeout de rede e registre o `X-Request-ID`, nunca o token integral.
- Trate `409` como regra de negócio e não repita o fechamento de acordo em loop.

## 13. Como ler cada método no Swagger

Em cada bloco:

- o resumo informa o objetivo da operação;
- os parâmetros de rota aparecem como `{documento}` e `{contrato}`;
- **Explicar entrada** detalha parâmetros e campos enviados;
- **Explicar resposta** detalha os campos retornados no modelo JSON;
- **Modelo do JSON** mostra a estrutura de entrada;
- **Modelo de resposta** mostra como a API responde;
- a permissão exigida aparece junto ao método;
- **Executar** envia a chamada com o Bearer token autorizado na aba.

Os exemplos usam dados fictícios. Substitua documento, contrato, tabulação e proposta pelos valores reais obtidos nas consultas anteriores.
