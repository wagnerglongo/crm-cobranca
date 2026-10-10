# API corporativa Pactua CRM

A API corporativa reutiliza a autenticação, permissões, escopo por credor/carteira, repositories, services e adapters do CRM. O MySQL permanece como fonte oficial das operações transacionais.

## Princípios

- API versionada em `/api/v1`.
- Bearer access token opaco com expiração.
- O banco persiste somente o hash do token, não o token em texto puro.
- Não há refresh/revoke público no fluxo atual; quando o token expira, um novo token deve ser emitido.
- Permissões e escopo são herdados do usuário autenticado.
- Controllers não devem duplicar regras já existentes em services/repositories/adapters.
- Respostas usam campos explicitamente permitidos; novos endpoints não devem expor `SELECT *` diretamente.
- Operações ACME reutilizam o adapter/client do credor.
- Acordos respeitam a regra de um acordo operacional ativo por contrato.

## Endpoints implementados

| Método | Endpoint | Permissão / acesso | Finalidade |
|---|---|---|---|
| GET | `/api/v1/health` | público | Saúde mínima da API |
| GET | `/api/v1/health/components` | `integracoes.visualizar` | Diagnóstico de componentes |
| POST | `/api/v1/auth/token` | credenciais válidas | Emitir Bearer access token |
| GET | `/api/v1/auth/me` | token API | Dados e escopo do usuário autenticado |
| GET | `/api/v1/clientes/documento/{documento}` | `clientes.consultar` | Consultar cliente por CPF/CNPJ |
| GET | `/api/v1/contratos/codigo/{contrato}` | `clientes.consultar` | Consultar contrato por código |
| GET | `/api/v1/tabulacoes` | `atendimentos.visualizar` | Listar tabulações disponíveis |
| POST | `/api/v1/acionamentos/contrato/{contrato}` | `atendimentos.registrar` | Registrar acionamento por contrato |
| POST | `/api/v1/acionamentos/documento/{documento}` | `atendimentos.registrar` | Registrar acionamento por documento |
| POST | `/api/v1/acionamentos/lote` | `atendimentos.registrar` | Registrar acionamentos em lote |
| GET | `/api/v1/acordos` | `acordos.visualizar` | Consultar acordos |
| GET | `/api/v1/acordos/contrato/{contrato}` | `acordos.visualizar` | Consultar acordos do contrato |
| GET | `/api/v1/acordos/documento/{documento}` | `acordos.visualizar` | Consultar acordos por CPF/CNPJ |
| GET | `/api/v1/acme/documento/{documento}/contrato/{contrato}/simulacoes` | `acordos.criar` | Consultar simulações ACME |
| POST | `/api/v1/acme/documento/{documento}/contrato/{contrato}/acordos` | `acordos.criar` | Fechar acordo ACME |

A especificação executável está em:

```text
/api/openapi.json
```

A interface Swagger está em:

```text
/api/docs/
```

## Autenticação

O endpoint de token recebe login e senha de um usuário válido do CRM e devolve um access token com tempo de vida definido por `API_ACCESS_TOKEN_TTL`.

Exemplo de uso:

```http
Authorization: Bearer wrk_api_at_...
```

O token não substitui permissões. Cada endpoint continua validando o direito operacional necessário e o escopo do usuário.

## Testes

O projeto possui PHPUnit para testes unitários/arquiteturais e scripts de integração via `curl` em `tests/api/`.

Executar PHPUnit no DEV:

```bash
sudo bash scripts/testes.sh
```

Smoke da API:

```bash
bash tests/api/smoke.sh http://localhost:8080
```

Há testes separados para rate limit e negação de permissão.

## Evolução

Novos endpoints devem ser adicionados apenas quando a regra correspondente estiver suficientemente encapsulada em service/repository/adapter reutilizável. Isso evita criar uma regra na API e outra diferente no Pactua.

Áreas ainda candidatas a expansão incluem contatos, endereços, pagamentos, boletos, cargas, administração e consultas analíticas, conforme necessidade das integrações futuras.
