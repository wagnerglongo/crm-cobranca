<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Search;

use CRM\Config\Database;
use CRM\Infrastructure\Http\JsonHttpClient;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\DashboardOperacionalRepository;
use PDO;
use RuntimeException;

/**
 * Projecoes operacionais para dashboards e exportacoes no OpenSearch.
 *
 * Cada indice possui um unico grao. Isso evita que um acordo com varias
 * parcelas multiplique valores de cliente/contrato nas agregacoes.
 */
final class OpenSearchOperationalProjection
{
    private const DATE_FORMAT = 'yyyy-MM-dd||yyyy-MM-dd HH:mm:ss||strict_date_optional_time';

    private readonly string $baseUrl;

    /** @var array<string,string> */
    private readonly array $indices;

    public function __construct(
        private readonly PDO $pdo,
        private readonly JsonHttpClient $http = new JsonHttpClient()
    ) {
        $this->baseUrl = rtrim((string) Database::env('OPENSEARCH_HOST', 'http://opensearch:9200'), '/');
        $this->indices = [
            'clientes' => (string) Database::env('OPENSEARCH_CLIENTES_INDEX', 'crm_clientes_v1'),
            'acordos' => (string) Database::env('OPENSEARCH_ACORDOS_INDEX', 'crm_acordos_v1'),
            'preventiva' => (string) Database::env('OPENSEARCH_PREVENTIVA_INDEX', 'crm_preventiva_v1'),
            'acionamentos' => (string) Database::env('OPENSEARCH_ACIONAMENTOS_INDEX', 'crm_acionamentos_v1'),
            'estrategias' => (string) Database::env('OPENSEARCH_ESTRATEGIAS_INDEX', 'crm_estrategias_resultados_v1'),
        ];
    }

    public function ensureIndices(): void
    {
        foreach ($this->mappings() as $kind => $properties) {
            $this->ensureIndex($this->indices[$kind], $properties);
        }
    }

    public function indexPerson(int $personId): void
    {
        $this->indexOne('clientes', $personId, $this->loadPeople([$personId]));
    }

    public function deletePerson(int $personId): void
    {
        $this->deleteDocument('clientes', $personId);
    }

    /** Documentos de acordo no índice de acionamentos usam id = offset + id do acordo. */
    private const FUNIL_ACORDO_ID_OFFSET = 900000000;

    public function indexAgreement(int $agreementId): void
    {
        $this->indexOne('acordos', $agreementId, $this->loadAgreements([$agreementId]));
        $this->indexAgreementFunnelDoc($agreementId);
    }

    public function deleteAgreement(int $agreementId): void
    {
        $this->deleteDocument('acordos', $agreementId);
        $this->deleteDocument('acionamentos', self::FUNIL_ACORDO_ID_OFFSET + $agreementId);
    }

    /** Mantém no índice de acionamentos o registro "acordo" usado pela etapa Acordo do funil (mesma regra do dashboard do CRM). */
    private function indexAgreementFunnelDoc(int $agreementId): void
    {
        if ($agreementId <= 0) {
            return;
        }
        $this->indexOne('acionamentos', self::FUNIL_ACORDO_ID_OFFSET + $agreementId, $this->loadAgreementFunnelDocs([$agreementId]));
    }

    /** @return array<int,array<string,mixed>> indexado por FUNIL_ACORDO_ID_OFFSET + id do acordo; só acordos que contam no dashboard */
    private function loadAgreementFunnelDocs(array $ids): array
    {
        [$ids, $holders] = $this->idsAndPlaceholders($ids);
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT
                ao.id, ao.pessoa_id, ao.contrato_id, ao.origem, ao.payload_extra, ao.operador_nome, ao.criado_em, ao.atualizado_em,
                pe.nome AS cliente_nome, pe.documento, c.codigo_contrato, c.produto, c.lote, c.carteira_id,
                COALESCE(ao.credor_id, c.credor_id) AS credor_id, cr.codigo AS credor_codigo, cr.nome AS credor_nome,
                ca.codigo AS carteira_codigo, ca.nome AS carteira_nome, u.nome AS criado_por_usuario
            FROM acordos_operacionais ao
            INNER JOIN pessoas pe ON pe.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, c.credor_id)
            LEFT JOIN carteiras ca ON ca.id = c.carteira_id
            LEFT JOIN usuarios u ON u.id = ao.criado_por_usuario_id
            WHERE ao.id IN ($holders)
            ORDER BY ao.id");
        $stmt->execute($ids);

        $rows = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            static fn (array $row): bool => AcordoOperacionalRepository::contabilizaNoDashboard($row)
        ));
        // Acordo sem acionamento de acordo no dia conta ele mesmo como
        // tentativa/alô/CPC (mesma regra do DashboardOperacionalRepository).
        $semAcionamento = [];
        foreach ((new DashboardOperacionalRepository($this->pdo))->acordosSemAcionamentoDeAcordo($rows) as $row) {
            $semAcionamento[(int) $row['id']] = true;
        }

        $documents = [];
        foreach ($rows as $row) {
            $contaContato = isset($semAcionamento[(int) $row['id']]);
            $flagContato = $contaContato ? 1 : 0;
            $atribuido = AcordoOperacionalRepository::responsavelAtribuido($row);
            $operador = $atribuido['nome'] ?? trim((string) ($row['operador_nome'] ?? '')) ?: (string) ($row['criado_por_usuario'] ?? '');
            $id = self::FUNIL_ACORDO_ID_OFFSET + (int) $row['id'];
            $documents[$id] = [
                'acionamento_id' => $id,
                'tipo_registro' => 'acordo',
                'acordo_id' => (int) $row['id'],
                'pessoa_id' => (int) $row['pessoa_id'],
                'contrato_id' => $this->nullableInt($row['contrato_id'] ?? null),
                'cliente_nome' => (string) $row['cliente_nome'],
                'documento' => $this->digits($row['documento'] ?? ''),
                'codigo_contrato' => (string) ($row['codigo_contrato'] ?? ''),
                'produto' => (string) ($row['produto'] ?? ''),
                'credor_id' => $this->nullableInt($row['credor_id'] ?? null),
                'credor_codigo' => (string) ($row['credor_codigo'] ?? ''),
                'credor_nome' => (string) ($row['credor_nome'] ?? ''),
                'carteira_id' => $this->nullableInt($row['carteira_id'] ?? null),
                'carteira_codigo' => (string) ($row['carteira_codigo'] ?? ''),
                'carteira_nome' => (string) ($row['carteira_nome'] ?? ''),
                'operador_nome' => $operador,
                'lote' => (string) ($row['lote'] ?? ''),
                'origem' => (string) ($row['origem'] ?? ''),
                'tentativa' => $contaContato, 'alo' => $contaContato, 'cpc' => $contaContato,
                'flag_tentativa' => $flagContato, 'flag_alo' => $flagContato, 'flag_cpc' => $flagContato, 'flag_acordo' => 0, 'flag_acordo_fechado' => 1,
                'funil_fluxo' => 'acordo fechado',
                'criado_em' => (string) $row['criado_em'],
                'atualizado_em' => (string) $row['atualizado_em'],
            ];
        }
        return $documents;
    }

    private function reindexAgreementFunnelDocs(int $batchSize): void
    {
        $lastId = 0;
        while (true) {
            $stmt = $this->pdo->prepare('SELECT id FROM acordos_operacionais WHERE id > :last_id ORDER BY id ASC LIMIT :limite');
            $stmt->bindValue('last_id', $lastId, PDO::PARAM_INT);
            $stmt->bindValue('limite', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
            if ($ids === []) {
                break;
            }
            $lastId = max($ids);
            $this->bulkIndex('acionamentos', $this->loadAgreementFunnelDocs($ids));
        }
    }

    public function indexInstallment(int $installmentId): void
    {
        $this->indexOne('preventiva', $installmentId, $this->loadInstallments([$installmentId]));
    }

    public function deleteInstallment(int $installmentId): void
    {
        $this->deleteDocument('preventiva', $installmentId);
    }

    public function deleteInstallmentsByAgreement(int $agreementId): void
    {
        $this->deleteByTerm('preventiva', 'acordo_id', $agreementId);
    }

    public function indexAction(int $actionId): void
    {
        $this->indexOne('acionamentos', $actionId, $this->loadActions([$actionId]));
        $this->reindexAgreementFunnelDocsOfSameDay($actionId);
    }

    /**
     * O acionamento de acordo costuma ser gravado segundos depois do acordo:
     * reprojeta os acordos da mesma pessoa/dia pra eles deixarem de contar
     * como alô/CPC próprio (senão o evento contaria duas vezes). Vale pra
     * qualquer tabulação, cobrindo também a retabulação de um acionamento.
     */
    private function reindexAgreementFunnelDocsOfSameDay(int $actionId): void
    {
        $stmt = $this->pdo->prepare("SELECT ao.id
            FROM atendimentos_historico ah
            INNER JOIN acordos_operacionais ao ON ao.pessoa_id = ah.pessoa_id
                AND ao.criado_em >= DATE(ah.criado_em) AND ao.criado_em < DATE(ah.criado_em) + INTERVAL 1 DAY
            WHERE ah.id = :id");
        $stmt->execute(['id' => $actionId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $agreementId) {
            $this->indexAgreementFunnelDoc((int) $agreementId);
        }
    }

    public function deleteAction(int $actionId): void
    {
        $this->deleteDocument('acionamentos', $actionId);
    }

    public function indexStrategyResult(int $strategyContractId): void
    {
        $this->indexOne('estrategias', $strategyContractId, $this->loadStrategyResults([$strategyContractId]));
    }

    public function deleteStrategyResult(int $strategyContractId): void
    {
        $this->deleteDocument('estrategias', $strategyContractId);
    }

    /** Reindexa todas as dimensoes operacionais relacionadas a um cliente. */
    public function reindexPerson(int $personId): void
    {
        if ($personId <= 0) {
            return;
        }
        $this->indexPerson($personId);
        $this->bulkByQuery('acordos', 'SELECT id FROM acordos_operacionais WHERE pessoa_id = :id', $personId);
        $this->bulkByQuery(
            'preventiva',
            'SELECT ap.id FROM acordos_operacionais_parcelas ap INNER JOIN acordos_operacionais ao ON ao.id = ap.acordo_operacional_id WHERE ao.pessoa_id = :id',
            $personId
        );
        $this->bulkByQuery('acionamentos', 'SELECT id FROM atendimentos_historico WHERE pessoa_id = :id', $personId);
        $this->bulkByQuery('estrategias', 'SELECT id FROM estrategia_contratos WHERE pessoa_id = :id', $personId);
    }

    /** Reindexa dados derivados de contrato sem duplicar o indice de contratos. */
    public function reindexContract(int $contractId): void
    {
        if ($contractId <= 0) {
            return;
        }
        $this->bulkByQuery('acordos', 'SELECT id FROM acordos_operacionais WHERE contrato_id = :id', $contractId);
        $this->bulkByQuery(
            'preventiva',
            'SELECT ap.id FROM acordos_operacionais_parcelas ap INNER JOIN acordos_operacionais ao ON ao.id = ap.acordo_operacional_id WHERE ao.contrato_id = :id',
            $contractId
        );
        $this->bulkByQuery('acionamentos', 'SELECT id FROM atendimentos_historico WHERE contrato_id = :id', $contractId);
        $this->bulkByQuery('estrategias', 'SELECT id FROM estrategia_contratos WHERE contrato_id = :id', $contractId);
        $personId = $this->scalarId('SELECT pessoa_id FROM contratos WHERE id = :id LIMIT 1', $contractId);
        if ($personId !== null) {
            $this->indexPerson($personId);
        }
    }

    public function reindexAgreement(int $agreementId): void
    {
        if ($agreementId <= 0) {
            return;
        }
        $this->indexAgreement($agreementId);
        $this->reindexStrategyResultsByAgreement($agreementId);
        $this->bulkByQuery('preventiva', 'SELECT id FROM acordos_operacionais_parcelas WHERE acordo_operacional_id = :id', $agreementId);
        $personId = $this->scalarId('SELECT pessoa_id FROM acordos_operacionais WHERE id = :id LIMIT 1', $agreementId);
        if ($personId !== null) {
            $this->indexPerson($personId);
        }
    }

    public function reindexStrategy(int $strategyId): void
    {
        $this->bulkByQuery('estrategias', 'SELECT id FROM estrategia_contratos WHERE estrategia_id = :id', $strategyId);
    }

    public function deleteStrategyResultsByStrategy(int $strategyId): void
    {
        $this->deleteByTerm('estrategias', 'estrategia_id', $strategyId);
    }

    public function reindexStrategyResultsByAction(int $actionId): void
    {
        $this->bulkByQuery('estrategias', 'SELECT id FROM estrategia_contratos WHERE atendimento_id = :id', $actionId);
    }

    public function reindexStrategyResultsByAgreement(int $agreementId): void
    {
        if (!$this->columnExists('estrategia_contratos', 'acordo_id')) {
            return;
        }
        $this->bulkByQuery('estrategias', 'SELECT id FROM estrategia_contratos WHERE acordo_id = :id', $agreementId);
    }

    public function reindexActionsByResult(int $resultId): void
    {
        $this->bulkByQuery('acionamentos', 'SELECT id FROM atendimentos_historico WHERE resultado_id = :id', $resultId);
        $this->bulkByQuery(
            'estrategias',
            'SELECT ec.id FROM estrategia_contratos ec INNER JOIN atendimentos_historico ah ON ah.id = ec.atendimento_id WHERE ah.resultado_id = :id',
            $resultId
        );
    }

    public function reindexActionsByCampaignItem(int $itemId): void
    {
        $this->bulkByQuery('acionamentos', 'SELECT id FROM atendimentos_historico WHERE campanha_item_id = :id', $itemId);
    }

    public function reindexActionsByCampaign(int $campaignId): void
    {
        $this->bulkByQuery(
            'acionamentos',
            'SELECT ah.id FROM atendimentos_historico ah INNER JOIN campanha_itens ci ON ci.id = ah.campanha_item_id WHERE ci.campanha_id = :id',
            $campaignId
        );
    }

    public function reindexByUser(int $userId): void
    {
        $this->bulkByQuery('acionamentos', 'SELECT id FROM atendimentos_historico WHERE usuario_id = :id', $userId);

        // PDO usa prepared statements nativos. Reutilizar o mesmo placeholder
        // nomeado duas vezes gera SQLSTATE[HY093], por isso cada caminho e
        // reindexado separadamente.
        $this->bulkByQuery('acordos', 'SELECT id FROM acordos_operacionais WHERE criado_por_usuario_id = :id', $userId);
        $this->bulkByQuery('acordos', 'SELECT id FROM acordos_operacionais WHERE atualizado_por_usuario_id = :id', $userId);

        $this->bulkByQuery('estrategias', 'SELECT id FROM estrategia_contratos WHERE reservado_por_usuario_id = :id', $userId);
        $this->bulkByQuery(
            'estrategias',
            'SELECT ec.id FROM estrategia_contratos ec INNER JOIN atendimentos_historico ah ON ah.id = ec.atendimento_id WHERE ah.usuario_id = :id',
            $userId
        );
        if ($this->columnExists('estrategia_contratos', 'acordo_id')) {
            $this->bulkByQuery(
                'estrategias',
                'SELECT ec.id FROM estrategia_contratos ec INNER JOIN acordos_operacionais ao ON ao.id = ec.acordo_id WHERE ao.criado_por_usuario_id = :id',
                $userId
            );
        }
    }

    public function reindexPreventiveByCreditor(int $creditorId): void
    {
        $this->bulkByQuery(
            'preventiva',
            'SELECT ap.id FROM acordos_operacionais_parcelas ap INNER JOIN acordos_operacionais ao ON ao.id = ap.acordo_operacional_id LEFT JOIN contratos c ON c.id = ao.contrato_id WHERE COALESCE(ao.credor_id, c.credor_id) = :id',
            $creditorId
        );
    }

    /**
     * @param list<string>|null $onlyKinds
     * @param null|callable(string,array{indexed:int,last_id:int}):void $onComplete
     * @return array<string,array{indexed:int,last_id:int}>
     */
    public function reindexAll(int $batchSize = 500, ?array $onlyKinds = null, ?callable $onComplete = null): array
    {
        $this->ensureIndices();
        $batchSize = max(50, min(2000, $batchSize));
        $definitions = [
            'clientes' => 'pessoas',
            'acordos' => 'acordos_operacionais',
            'preventiva' => 'acordos_operacionais_parcelas',
            'acionamentos' => 'atendimentos_historico',
            'estrategias' => 'estrategia_contratos',
        ];
        if ($onlyKinds !== null) {
            $onlyKinds = array_values(array_unique(array_map('strval', $onlyKinds)));
            $unknown = array_values(array_diff($onlyKinds, array_keys($definitions)));
            if ($unknown !== []) {
                throw new RuntimeException('Indice operacional desconhecido: ' . implode(', ', $unknown));
            }
            $definitions = array_intersect_key($definitions, array_flip($onlyKinds));
        }

        $results = [];
        foreach ($definitions as $kind => $table) {
            $results[$kind] = $this->reindexKind($kind, $table, $batchSize);
            if ($onComplete !== null) {
                $onComplete($kind, $results[$kind]);
            }
        }
        return $results;
    }

    /** @return array<string,array{ok:bool,index:string,documents:int}> */
    public function status(int $timeout = 10): array
    {
        $timeout = max(5, min(120, $timeout));
        $result = [];
        foreach ($this->indices as $kind => $index) {
            $response = $this->http->request('GET', $this->baseUrl . '/' . rawurlencode($index) . '/_count', null, $timeout);
            if ($response['status'] === 404) {
                $result[$kind] = ['ok' => false, 'index' => $index, 'documents' => 0];
                continue;
            }
            $this->http->requireSuccess($response, 'Contagem do indice ' . $index);
            $result[$kind] = [
                'ok' => true,
                'index' => $index,
                'documents' => (int) ($response['json']['count'] ?? 0),
            ];
        }
        return $result;
    }

    /** @return array<string,array<string,array<string,mixed>>> */
    private function mappings(): array
    {
        $keyword = static fn (): array => ['type' => 'keyword'];
        $long = static fn (): array => ['type' => 'long'];
        $integer = static fn (): array => ['type' => 'integer'];
        $double = static fn (): array => ['type' => 'double'];
        $boolean = static fn (): array => ['type' => 'boolean'];
        $date = static fn (): array => ['type' => 'date', 'format' => self::DATE_FORMAT];
        $text = static fn (): array => ['type' => 'text', 'analyzer' => 'crm_texto', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]]];

        return [
            'clientes' => [
                'cliente_id' => $long(), 'cliente_nome' => $text(), 'documento' => $keyword(), 'tipo_documento' => $keyword(),
                'data_nascimento' => $date(), 'sexo' => $keyword(), 'estado_civil' => $keyword(),
                'telefone_principal' => $keyword(), 'whatsapp_principal' => $keyword(), 'email_principal' => $keyword(),
                'total_contatos' => $integer(), 'telefones_validos' => $integer(), 'emails_validos' => $integer(),
                'cep' => $keyword(), 'uf' => $keyword(), 'cidade' => $keyword(), 'bairro' => $keyword(),
                'total_contratos' => $integer(), 'contratos_ativos' => $integer(), 'contratos_disponiveis_negociacao' => $integer(),
                'saldo_total' => $double(), 'maior_dias_atraso' => $integer(), 'disponivel_negociacao' => $boolean(),
                'credores' => $keyword(), 'carteiras' => $keyword(), 'produtos' => $keyword(),
                'tem_acordo_ativo' => $boolean(), 'acordos_ativos' => $integer(), 'acordos_cumpridos' => $integer(),
                'acordos_quebrados' => $integer(), 'ultimo_acordo_id' => $long(), 'ultimo_acordo_status' => $keyword(),
                'ultimo_acordo_em' => $date(), 'total_acionamentos' => $integer(), 'ultimo_acionamento_em' => $date(),
                'ultimo_resultado' => $keyword(), 'ultimo_canal' => $keyword(), 'ultimo_operador' => $keyword(),
                'proximo_acionamento' => $date(), 'criado_em' => $date(), 'atualizado_em' => $date(),
            ],
            'acordos' => [
                'acordo_id' => $long(), 'pessoa_id' => $long(), 'contrato_id' => $long(), 'credor_id' => $long(), 'carteira_id' => $long(),
                'cliente_nome' => $text(), 'documento' => $keyword(), 'codigo_contrato' => $keyword(), 'produto' => $keyword(),
                'credor_codigo' => $keyword(), 'credor_nome' => $keyword(), 'carteira_codigo' => $keyword(), 'carteira_nome' => $keyword(),
                'codigo_acordo_credor' => $keyword(), 'protocolo_credor' => $keyword(), 'valor_acordo' => $double(),
                'valor_entrada' => $double(), 'data_entrada' => $date(), 'data_acordo' => $date(), 'quantidade_parcelas' => $integer(),
                'valor_parcela' => $double(), 'data_primeira_parcela' => $date(), 'periodicidade' => $keyword(), 'forma_pagamento' => $keyword(),
                'status_operacional' => $keyword(), 'status_integracao' => $keyword(), 'situacao_carteira' => $keyword(),
                'status_acordo_credor' => $keyword(), 'status_divida_credor' => $keyword(), 'origem' => $keyword(),
                'responsavel_negociacao_grupo' => $keyword(),
                'operador_nome' => $keyword(), 'criado_por_usuario_id' => $long(), 'criado_por_usuario' => $keyword(),
                'responsavel_atribuido_id' => $long(), 'responsavel_atribuido_nome' => $keyword(),
                'responsavel_efetivo' => $keyword(), 'contabiliza_dashboard' => $boolean(),
                'ativo' => $boolean(), 'quitado' => $boolean(), 'quebrado' => $boolean(),
                'total_parcelas' => $integer(), 'parcelas_pagas' => $integer(), 'parcelas_abertas' => $integer(),
                'parcelas_vencidas' => $integer(), 'parcelas_canceladas' => $integer(), 'parcelas_renegociadas' => $integer(),
                'valor_previsto' => $double(), 'valor_pago' => $double(), 'valor_aberto' => $double(),
                'proximo_vencimento' => $date(), 'fim_carencia' => $date(), 'proxima_acao' => $keyword(),
                'ultima_verificacao_em' => $date(), 'confirmado_credor_em' => $date(), 'status_atualizado_em' => $date(),
                'criado_em' => $date(), 'atualizado_em' => $date(),
            ],
            'preventiva' => [
                'parcela_id' => $long(), 'acordo_id' => $long(), 'pessoa_id' => $long(), 'contrato_id' => $long(),
                'credor_id' => $long(), 'carteira_id' => $long(), 'cliente_nome' => $text(), 'documento' => $keyword(),
                'codigo_contrato' => $keyword(), 'produto' => $keyword(), 'credor_codigo' => $keyword(), 'credor_nome' => $keyword(),
                'carteira_codigo' => $keyword(), 'carteira_nome' => $keyword(), 'numero_parcela' => $integer(), 'tipo_parcela' => $keyword(),
                'data_vencimento' => $date(), 'valor_parcela' => $double(), 'status_parcela' => $keyword(),
                'data_pagamento' => $date(), 'valor_pago' => $double(), 'deadline_credor' => $date(), 'fim_carencia' => $date(),
                'dias_para_vencimento' => $integer(), 'dias_em_atraso' => $integer(), 'situacao_preventiva' => $keyword(),
                'status_acordo' => $keyword(), 'situacao_carteira' => $keyword(), 'responsavel' => $keyword(),
                'proxima_acao' => $keyword(), 'installment_id_credor' => $keyword(), 'status_parcela_credor' => $keyword(),
                'paga' => $boolean(), 'aberta' => $boolean(), 'criado_em' => $date(), 'atualizado_em' => $date(),
            ],
            'acionamentos' => [
                'acionamento_id' => $long(), 'pessoa_id' => $long(), 'contrato_id' => $long(), 'contato_id' => $long(),
                'usuario_id' => $long(), 'cliente_nome' => $text(), 'documento' => $keyword(), 'codigo_contrato' => $keyword(),
                'produto' => $keyword(), 'credor_id' => $long(), 'credor_codigo' => $keyword(), 'credor_nome' => $keyword(),
                'carteira_id' => $long(), 'carteira_codigo' => $keyword(), 'carteira_nome' => $keyword(),
                'operador_nome' => $keyword(), 'canal' => $keyword(), 'resultado_legacy' => $keyword(), 'resultado_id' => $long(),
                'resultado_codigo' => $keyword(), 'resultado_nome' => $keyword(), 'resultado_categoria' => $keyword(),
                'considera_sucesso' => $boolean(), 'contato_efetivo' => $boolean(), 'telefone_ruim' => $boolean(),
                'contato_utilizado' => $keyword(), 'proximo_acionamento' => $date(), 'retorno_status' => $keyword(),
                'retorno_tratado_em' => $date(), 'origem' => $keyword(), 'campanha_item_id' => $long(),
                'campanha_id' => $long(), 'campanha_nome' => $keyword(), 'campanha_status' => $keyword(),
                'campanha_item_status' => $keyword(), 'lote' => $keyword(),
                'motivo_inadimplencia_id' => $long(), 'motivo_inadimplencia' => $keyword(),
                'tentativa' => $boolean(), 'alo' => $boolean(), 'cpc' => $boolean(), 'acordo' => $boolean(),
                'flag_tentativa' => $integer(), 'flag_alo' => $integer(), 'flag_cpc' => $integer(), 'flag_acordo' => $integer(),
                'funil_fluxo' => $keyword(), 'tipo_registro' => $keyword(), 'acordo_id' => $long(), 'flag_acordo_fechado' => $integer(),
                'criado_em' => $date(), 'atualizado_em' => $date(),
            ],
            'estrategias' => [
                'estrategia_contrato_id' => $long(), 'estrategia_id' => $long(), 'estrategia_nome' => $text(),
                'estrategia_status' => $keyword(), 'estrategia_prioridade' => $integer(), 'status_operacional' => $keyword(),
                'ordem_prioridade' => $integer(), 'pessoa_id' => $long(), 'contrato_id' => $long(),
                'cliente_nome' => $text(), 'documento' => $keyword(), 'codigo_contrato' => $keyword(), 'produto' => $keyword(),
                'credor_id' => $long(), 'credor_codigo' => $keyword(), 'credor_nome' => $keyword(),
                'carteira_id' => $long(), 'carteira_codigo' => $keyword(), 'carteira_nome' => $keyword(),
                'operador_id' => $long(), 'operador_nome' => $keyword(), 'reservado_por_usuario_id' => $long(),
                'reservado_por_nome' => $keyword(), 'teve_acionamento' => $boolean(), 'tentou_contato' => $boolean(),
                'acionamento_id' => $long(), 'acionamento_em' => $date(), 'canal' => $keyword(),
                'resultado_id' => $long(), 'resultado_codigo' => $keyword(), 'resultado_nome' => $keyword(),
                'resultado_categoria' => $keyword(), 'contato_efetivo' => $boolean(), 'considera_sucesso' => $boolean(),
                'fechou_acordo' => $boolean(), 'acordo_id' => $long(), 'acordo_status' => $keyword(),
                'acordo_valor' => $double(), 'acordo_valor_pago' => $double(), 'acordo_valor_aberto' => $double(),
                'acordo_parcelas_pagas' => $integer(), 'acordo_quitado' => $boolean(), 'acordo_quebrado' => $boolean(),
                'teve_pagamento_acordo' => $boolean(), 'acordo_em' => $date(), 'acordo_operador_id' => $long(),
                'acordo_operador_nome' => $keyword(), 'saldo_snapshot' => $double(), 'valor_original_snapshot' => $double(),
                'valor_pago_snapshot' => $double(), 'valor_faltante_snapshot' => $double(), 'aging_snapshot' => $integer(),
                'data_entrada_base_snapshot' => $date(), 'data_vencimento_original_snapshot' => $date(),
                'quantidade_tentativas_snapshot' => $integer(), 'incluido_em' => $date(), 'aberto_em' => $date(),
                'trabalhado_em' => $date(), 'removido_em' => $date(), 'criado_em' => $date(), 'atualizado_em' => $date(),
            ],
        ];
    }

    /** @param array<string,array<string,mixed>> $properties */
    private function ensureIndex(string $index, array $properties): void
    {
        $url = $this->baseUrl . '/' . rawurlencode($index);
        $head = $this->http->request('HEAD', $url, null, 30);
        if ($head['status'] === 200) {
            $mapping = $this->http->request('PUT', $url . '/_mapping', ['properties' => $properties], 60);
            $this->http->requireSuccess($mapping, 'Atualizacao do mapeamento do indice ' . $index);
            return;
        }
        if ($head['status'] !== 404) {
            $this->http->requireSuccess($head, 'Consulta do indice ' . $index);
        }

        $response = $this->http->request('PUT', $url, [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'analysis' => [
                    'analyzer' => [
                        'crm_texto' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'asciifolding'],
                        ],
                    ],
                ],
            ],
            'mappings' => ['dynamic' => 'strict', 'properties' => $properties],
        ], 60);
        $this->http->requireSuccess($response, 'Criacao do indice ' . $index);
    }

    /** @return array<int,array<string,mixed>> */
    private function loadPeople(array $ids): array
    {
        [$ids, $holders] = $this->idsAndPlaceholders($ids);
        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT
                p.id AS cliente_id, p.nome AS cliente_nome, p.documento, p.tipo_documento,
                p.data_nascimento, p.sexo, p.estado_civil, p.criado_em, p.atualizado_em,
                (SELECT pc.valor_normalizado FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id AND pc.tipo = 'telefone' AND pc.status NOT IN ('incorreto','bloqueado') ORDER BY pc.principal DESC, pc.id DESC LIMIT 1) AS telefone_principal,
                (SELECT pc.valor_normalizado FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id AND pc.tipo = 'whatsapp' AND pc.status NOT IN ('incorreto','bloqueado') ORDER BY pc.principal DESC, pc.id DESC LIMIT 1) AS whatsapp_principal,
                (SELECT pc.valor_normalizado FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id AND pc.tipo = 'email' AND pc.status NOT IN ('incorreto','bloqueado') ORDER BY pc.principal DESC, pc.id DESC LIMIT 1) AS email_principal,
                (SELECT COUNT(*) FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id) AS total_contatos,
                (SELECT COUNT(*) FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id AND pc.tipo IN ('telefone','whatsapp') AND pc.status NOT IN ('incorreto','bloqueado')) AS telefones_validos,
                (SELECT COUNT(*) FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id AND pc.tipo = 'email' AND pc.status NOT IN ('incorreto','bloqueado')) AS emails_validos,
                (SELECT pe.cep FROM pessoa_enderecos pe WHERE pe.pessoa_id = p.id ORDER BY pe.principal DESC, pe.id DESC LIMIT 1) AS cep,
                (SELECT pe.uf FROM pessoa_enderecos pe WHERE pe.pessoa_id = p.id ORDER BY pe.principal DESC, pe.id DESC LIMIT 1) AS uf,
                (SELECT pe.cidade FROM pessoa_enderecos pe WHERE pe.pessoa_id = p.id ORDER BY pe.principal DESC, pe.id DESC LIMIT 1) AS cidade,
                (SELECT pe.bairro FROM pessoa_enderecos pe WHERE pe.pessoa_id = p.id ORDER BY pe.principal DESC, pe.id DESC LIMIT 1) AS bairro,
                (SELECT COUNT(*) FROM contratos c WHERE c.pessoa_id = p.id) AS total_contratos,
                (SELECT COUNT(*) FROM contratos c WHERE c.pessoa_id = p.id AND " . $this->activeContractSql('c') . ") AS contratos_ativos,
                (SELECT COUNT(*) FROM contratos c WHERE c.pessoa_id = p.id AND " . $this->activeContractSql('c') . " AND NOT EXISTS (SELECT 1 FROM acordos_operacionais aa WHERE aa.contrato_id = c.id AND aa.status_operacional IN ('pendente','formalizado'))) AS contratos_disponiveis_negociacao,
                (SELECT COALESCE(SUM(GREATEST(COALESCE(c.saldo_atual, 0), 0)), 0) FROM contratos c WHERE c.pessoa_id = p.id) AS saldo_total,
                (SELECT COALESCE(MAX(c.dias_atraso), 0) FROM contratos c WHERE c.pessoa_id = p.id) AS maior_dias_atraso,
                (SELECT GROUP_CONCAT(DISTINCT cr.nome ORDER BY cr.nome SEPARATOR '|||') FROM contratos c INNER JOIN credores cr ON cr.id = c.credor_id WHERE c.pessoa_id = p.id) AS credores,
                (SELECT GROUP_CONCAT(DISTINCT ca.nome ORDER BY ca.nome SEPARATOR '|||') FROM contratos c INNER JOIN carteiras ca ON ca.id = c.carteira_id WHERE c.pessoa_id = p.id) AS carteiras,
                (SELECT GROUP_CONCAT(DISTINCT c.produto ORDER BY c.produto SEPARATOR '|||') FROM contratos c WHERE c.pessoa_id = p.id AND COALESCE(c.produto, '') <> '') AS produtos,
                (SELECT COUNT(*) FROM acordos_operacionais ao WHERE ao.pessoa_id = p.id AND ao.status_operacional IN ('pendente','formalizado')) AS acordos_ativos,
                (SELECT COUNT(*) FROM acordos_operacionais ao WHERE ao.pessoa_id = p.id AND ao.status_operacional = 'cumprido') AS acordos_cumpridos,
                (SELECT COUNT(*) FROM acordos_operacionais ao WHERE ao.pessoa_id = p.id AND ao.status_operacional = 'quebrado') AS acordos_quebrados,
                (SELECT ao.id FROM acordos_operacionais ao WHERE ao.pessoa_id = p.id ORDER BY ao.id DESC LIMIT 1) AS ultimo_acordo_id,
                (SELECT ao.status_operacional FROM acordos_operacionais ao WHERE ao.pessoa_id = p.id ORDER BY ao.id DESC LIMIT 1) AS ultimo_acordo_status,
                (SELECT ao.criado_em FROM acordos_operacionais ao WHERE ao.pessoa_id = p.id ORDER BY ao.id DESC LIMIT 1) AS ultimo_acordo_em,
                (SELECT COUNT(*) FROM atendimentos_historico ah WHERE ah.pessoa_id = p.id) AS total_acionamentos,
                (SELECT ah.criado_em FROM atendimentos_historico ah WHERE ah.pessoa_id = p.id ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 1) AS ultimo_acionamento_em,
                (SELECT COALESCE(ar.nome, ah.resultado) FROM atendimentos_historico ah LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id WHERE ah.pessoa_id = p.id ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 1) AS ultimo_resultado,
                (SELECT ah.canal FROM atendimentos_historico ah WHERE ah.pessoa_id = p.id ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 1) AS ultimo_canal,
                (SELECT COALESCE(u.nome, ah.operador_nome) FROM atendimentos_historico ah LEFT JOIN usuarios u ON u.id = ah.usuario_id WHERE ah.pessoa_id = p.id ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 1) AS ultimo_operador,
                (SELECT MIN(ah.proximo_acionamento) FROM atendimentos_historico ah WHERE ah.pessoa_id = p.id AND ah.proximo_acionamento IS NOT NULL AND COALESCE(ah.retorno_status, 'pendente') IN ('pendente','reagendado')) AS proximo_acionamento
            FROM pessoas p
            WHERE p.id IN ($holders)
            ORDER BY p.id");
        $stmt->execute($ids);

        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['cliente_id'];
            $disponiveis = (int) ($row['contratos_disponiveis_negociacao'] ?? 0);
            $documents[$id] = [
                'cliente_id' => $id,
                'cliente_nome' => (string) $row['cliente_nome'],
                'documento' => $this->digits($row['documento'] ?? ''),
                'tipo_documento' => (string) ($row['tipo_documento'] ?? ''),
                'data_nascimento' => $this->nullableString($row['data_nascimento'] ?? null),
                'sexo' => (string) ($row['sexo'] ?? ''),
                'estado_civil' => (string) ($row['estado_civil'] ?? ''),
                'telefone_principal' => (string) ($row['telefone_principal'] ?? ''),
                'whatsapp_principal' => (string) ($row['whatsapp_principal'] ?? ''),
                'email_principal' => (string) ($row['email_principal'] ?? ''),
                'total_contatos' => (int) $row['total_contatos'],
                'telefones_validos' => (int) $row['telefones_validos'],
                'emails_validos' => (int) $row['emails_validos'],
                'cep' => (string) ($row['cep'] ?? ''),
                'uf' => (string) ($row['uf'] ?? ''),
                'cidade' => (string) ($row['cidade'] ?? ''),
                'bairro' => (string) ($row['bairro'] ?? ''),
                'total_contratos' => (int) $row['total_contratos'],
                'contratos_ativos' => (int) $row['contratos_ativos'],
                'contratos_disponiveis_negociacao' => $disponiveis,
                'saldo_total' => (float) $row['saldo_total'],
                'maior_dias_atraso' => (int) $row['maior_dias_atraso'],
                'disponivel_negociacao' => $disponiveis > 0,
                'credores' => $this->splitList($row['credores'] ?? null),
                'carteiras' => $this->splitList($row['carteiras'] ?? null),
                'produtos' => $this->splitList($row['produtos'] ?? null),
                'tem_acordo_ativo' => (int) $row['acordos_ativos'] > 0,
                'acordos_ativos' => (int) $row['acordos_ativos'],
                'acordos_cumpridos' => (int) $row['acordos_cumpridos'],
                'acordos_quebrados' => (int) $row['acordos_quebrados'],
                'ultimo_acordo_id' => $this->nullableInt($row['ultimo_acordo_id'] ?? null),
                'ultimo_acordo_status' => (string) ($row['ultimo_acordo_status'] ?? ''),
                'ultimo_acordo_em' => $this->nullableString($row['ultimo_acordo_em'] ?? null),
                'total_acionamentos' => (int) $row['total_acionamentos'],
                'ultimo_acionamento_em' => $this->nullableString($row['ultimo_acionamento_em'] ?? null),
                'ultimo_resultado' => (string) ($row['ultimo_resultado'] ?? ''),
                'ultimo_canal' => (string) ($row['ultimo_canal'] ?? ''),
                'ultimo_operador' => (string) ($row['ultimo_operador'] ?? ''),
                'proximo_acionamento' => $this->nullableString($row['proximo_acionamento'] ?? null),
                'criado_em' => (string) $row['criado_em'],
                'atualizado_em' => (string) $row['atualizado_em'],
            ];
        }
        return $documents;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadAgreements(array $ids): array
    {
        [$ids, $holders] = $this->idsAndPlaceholders($ids);
        if ($ids === []) {
            return [];
        }

        $hasTracking = $this->tableExists('acordos_operacionais_acompanhamento');
        $trackingJoin = $hasTracking ? 'LEFT JOIN acordos_operacionais_acompanhamento ac ON ac.acordo_operacional_id = ao.id' : '';
        $tracking = $hasTracking
            ? 'ac.situacao_carteira, ac.total_parcelas AS total_parcelas_acompanhamento, ac.parcelas_pagas AS parcelas_pagas_acompanhamento, ac.valor_previsto AS valor_previsto_acompanhamento, ac.valor_pago AS valor_pago_acompanhamento, ac.proximo_vencimento, ac.fim_carencia, ac.proxima_acao, ac.ultima_verificacao_em,'
            : 'NULL AS situacao_carteira, NULL AS total_parcelas_acompanhamento, NULL AS parcelas_pagas_acompanhamento, NULL AS valor_previsto_acompanhamento, NULL AS valor_pago_acompanhamento, NULL AS proximo_vencimento, NULL AS fim_carencia, NULL AS proxima_acao, NULL AS ultima_verificacao_em,';

        $stmt = $this->pdo->prepare("SELECT
                ao.id AS acordo_id, ao.pessoa_id, ao.contrato_id, COALESCE(ao.credor_id, c.credor_id) AS credor_id,
                c.carteira_id, pe.nome AS cliente_nome, pe.documento, c.codigo_contrato, c.produto,
                cr.codigo AS credor_codigo, cr.nome AS credor_nome, ca.codigo AS carteira_codigo, ca.nome AS carteira_nome,
                ao.codigo_acordo_credor, ao.protocolo_credor, ao.valor_acordo, ao.valor_entrada, ao.data_entrada,
                ao.data_promessa_pagamento AS data_acordo, ao.quantidade_parcelas, ao.valor_parcela,
                ao.data_primeira_parcela, ao.periodicidade, ao.forma_pagamento, ao.status_operacional,
                ao.status_integracao, ao.origem, ao.operador_nome, ao.criado_por_usuario_id, u.nome AS criado_por_usuario,
                ao.confirmado_credor_em, ao.status_atualizado_em, ao.criado_em, ao.atualizado_em, ao.payload_extra,
                CASE WHEN JSON_VALID(ao.payload_extra) THEN COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ao.payload_extra, '$.agreement_status')), 'null'),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ao.payload_extra, '$.acme_agreement_snapshot.status')), 'null')
                ) ELSE NULL END AS status_acordo_credor,
                CASE WHEN JSON_VALID(ao.payload_extra) THEN COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ao.payload_extra, '$.debt_status')), 'null'),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ao.payload_extra, '$.acme_agreement_snapshot.debt.status')), 'null'),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ao.payload_extra, '$.acme_ultima_liquidacao_snapshot.debt.status')), 'null')
                ) ELSE NULL END AS status_divida_credor,
                $tracking
                COALESCE(pr.total_parcelas_calculado, 0) AS total_parcelas_calculado,
                COALESCE(pr.parcelas_pagas_calculado, 0) AS parcelas_pagas_calculado,
                COALESCE(pr.parcelas_abertas, 0) AS parcelas_abertas,
                COALESCE(pr.parcelas_vencidas, 0) AS parcelas_vencidas,
                COALESCE(pr.parcelas_canceladas, 0) AS parcelas_canceladas,
                COALESCE(pr.parcelas_renegociadas, 0) AS parcelas_renegociadas,
                COALESCE(pr.valor_previsto_calculado, 0) AS valor_previsto_calculado,
                COALESCE(pr.valor_pago_calculado, 0) AS valor_pago_calculado
            FROM acordos_operacionais ao
            INNER JOIN pessoas pe ON pe.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, c.credor_id)
            LEFT JOIN carteiras ca ON ca.id = c.carteira_id
            LEFT JOIN usuarios u ON u.id = ao.criado_por_usuario_id
            LEFT JOIN (
                SELECT
                    ap.acordo_operacional_id,
                    COUNT(*) AS total_parcelas_calculado,
                    SUM(CASE WHEN ap.status = 'paga' THEN 1 ELSE 0 END) AS parcelas_pagas_calculado,
                    SUM(CASE WHEN ap.status IN ('pendente','vencida') THEN 1 ELSE 0 END) AS parcelas_abertas,
                    SUM(CASE WHEN ap.status = 'vencida' OR (ap.status = 'pendente' AND ap.data_vencimento < CURRENT_DATE) THEN 1 ELSE 0 END) AS parcelas_vencidas,
                    SUM(CASE WHEN ap.status = 'cancelada' THEN 1 ELSE 0 END) AS parcelas_canceladas,
                    SUM(CASE WHEN ap.status = 'renegociada' THEN 1 ELSE 0 END) AS parcelas_renegociadas,
                    COALESCE(SUM(ap.valor_parcela), 0) AS valor_previsto_calculado,
                    COALESCE(SUM(CASE WHEN ap.status = 'paga' THEN COALESCE(ap.valor_pago, ap.valor_parcela) ELSE 0 END), 0) AS valor_pago_calculado
                FROM acordos_operacionais_parcelas ap
                WHERE ap.acordo_operacional_id IN ($holders)
                GROUP BY ap.acordo_operacional_id
            ) pr ON pr.acordo_operacional_id = ao.id
            $trackingJoin
            WHERE ao.id IN ($holders)
            ORDER BY ao.id");
        $stmt->execute(array_merge($ids, $ids));

        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['acordo_id'];
            $total = $row['total_parcelas_acompanhamento'] !== null ? (int) $row['total_parcelas_acompanhamento'] : (int) $row['total_parcelas_calculado'];
            $pagas = $row['parcelas_pagas_acompanhamento'] !== null ? (int) $row['parcelas_pagas_acompanhamento'] : (int) $row['parcelas_pagas_calculado'];
            $previsto = $row['valor_previsto_acompanhamento'] !== null ? (float) $row['valor_previsto_acompanhamento'] : (float) $row['valor_previsto_calculado'];
            $pago = $row['valor_pago_acompanhamento'] !== null ? (float) $row['valor_pago_acompanhamento'] : (float) $row['valor_pago_calculado'];
            $status = (string) $row['status_operacional'];
            $payloadExtra = json_decode((string) ($row['payload_extra'] ?? ''), true);
            $payloadExtra = is_array($payloadExtra) ? $payloadExtra : [];
            $atribuido = AcordoOperacionalRepository::responsavelAtribuido($row);
            $snapshotAcordo = $payloadExtra['acme_agreement_snapshot'] ?? $payloadExtra['acme_ultima_liquidacao_snapshot'] ?? [];
            $notaNegociacao = is_array($snapshotAcordo) ? (string) ($snapshotAcordo['negotiation_notes'] ?? '') : '';
            $responsavelGrupo = AcordoOperacionalRepository::grupoFila(AcordoOperacionalRepository::extrairResponsavelNegociacao($notaNegociacao));
            $documents[$id] = [
                'acordo_id' => $id,
                'pessoa_id' => (int) $row['pessoa_id'],
                'contrato_id' => $this->nullableInt($row['contrato_id'] ?? null),
                'credor_id' => $this->nullableInt($row['credor_id'] ?? null),
                'carteira_id' => $this->nullableInt($row['carteira_id'] ?? null),
                'cliente_nome' => (string) $row['cliente_nome'],
                'documento' => $this->digits($row['documento'] ?? ''),
                'codigo_contrato' => (string) ($row['codigo_contrato'] ?? ''),
                'produto' => (string) ($row['produto'] ?? ''),
                'credor_codigo' => (string) ($row['credor_codigo'] ?? ''),
                'credor_nome' => (string) ($row['credor_nome'] ?? ''),
                'carteira_codigo' => (string) ($row['carteira_codigo'] ?? ''),
                'carteira_nome' => (string) ($row['carteira_nome'] ?? ''),
                'codigo_acordo_credor' => (string) ($row['codigo_acordo_credor'] ?? ''),
                'protocolo_credor' => (string) ($row['protocolo_credor'] ?? ''),
                'valor_acordo' => (float) $row['valor_acordo'],
                'valor_entrada' => (float) ($row['valor_entrada'] ?? 0),
                'data_entrada' => $this->nullableString($row['data_entrada'] ?? null),
                'data_acordo' => $this->nullableString($row['data_acordo'] ?? null),
                'quantidade_parcelas' => (int) $row['quantidade_parcelas'],
                'valor_parcela' => (float) ($row['valor_parcela'] ?? 0),
                'data_primeira_parcela' => $this->nullableString($row['data_primeira_parcela'] ?? null),
                'periodicidade' => (string) ($row['periodicidade'] ?? ''),
                'forma_pagamento' => (string) ($row['forma_pagamento'] ?? ''),
                'status_operacional' => $status,
                'status_integracao' => (string) ($row['status_integracao'] ?? ''),
                'situacao_carteira' => (string) ($row['situacao_carteira'] ?? ''),
                'status_acordo_credor' => (string) ($row['status_acordo_credor'] ?? ''),
                'status_divida_credor' => (string) ($row['status_divida_credor'] ?? ''),
                'responsavel_negociacao_grupo' => $responsavelGrupo,
                'origem' => (string) ($row['origem'] ?? ''),
                'operador_nome' => (string) ($row['operador_nome'] ?? ''),
                'criado_por_usuario_id' => $this->nullableInt($row['criado_por_usuario_id'] ?? null),
                'criado_por_usuario' => (string) ($row['criado_por_usuario'] ?? ''),
                'responsavel_atribuido_id' => $atribuido['usuario_id'] ?? null,
                'responsavel_atribuido_nome' => $atribuido['nome'] ?? '',
                'responsavel_efetivo' => $atribuido['nome'] ?? (string) ($row['operador_nome'] ?? ''),
                'contabiliza_dashboard' => AcordoOperacionalRepository::contabilizaNoDashboard($row),
                'ativo' => in_array($status, ['pendente', 'formalizado'], true),
                'quitado' => $status === 'cumprido',
                'quebrado' => $status === 'quebrado',
                'total_parcelas' => $total,
                'parcelas_pagas' => $pagas,
                'parcelas_abertas' => (int) ($row['parcelas_abertas'] ?? 0),
                'parcelas_vencidas' => (int) ($row['parcelas_vencidas'] ?? 0),
                'parcelas_canceladas' => (int) ($row['parcelas_canceladas'] ?? 0),
                'parcelas_renegociadas' => (int) ($row['parcelas_renegociadas'] ?? 0),
                'valor_previsto' => $previsto,
                'valor_pago' => $pago,
                'valor_aberto' => max(0, $previsto - $pago),
                'proximo_vencimento' => $this->nullableString($row['proximo_vencimento'] ?? null),
                'fim_carencia' => $this->nullableString($row['fim_carencia'] ?? null),
                'proxima_acao' => (string) ($row['proxima_acao'] ?? ''),
                'ultima_verificacao_em' => $this->nullableString($row['ultima_verificacao_em'] ?? null),
                'confirmado_credor_em' => $this->nullableString($row['confirmado_credor_em'] ?? null),
                'status_atualizado_em' => $this->nullableString($row['status_atualizado_em'] ?? null),
                'criado_em' => (string) $row['criado_em'],
                'atualizado_em' => (string) $row['atualizado_em'],
            ];
        }
        return $documents;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadInstallments(array $ids): array
    {
        [$ids, $holders] = $this->idsAndPlaceholders($ids);
        if ($ids === []) {
            return [];
        }

        $hasTracking = $this->tableExists('acordos_operacionais_acompanhamento');
        $trackingJoin = $hasTracking ? 'LEFT JOIN acordos_operacionais_acompanhamento ac ON ac.acordo_operacional_id = ao.id' : '';
        $tracking = $hasTracking
            ? 'ac.situacao_carteira, ac.parcela_atual_id, ac.fim_carencia AS fim_carencia_acompanhamento, ac.proxima_acao,'
            : 'NULL AS situacao_carteira, NULL AS parcela_atual_id, NULL AS fim_carencia_acompanhamento, NULL AS proxima_acao,';
        $hasRules = $this->tableExists('acordo_regras_carencia');
        $ruleJoins = $hasRules
            ? "LEFT JOIN acordo_regras_carencia rp ON rp.credor_id = COALESCE(ao.credor_id, c.credor_id) AND rp.produto COLLATE utf8mb4_unicode_ci = COALESCE(c.produto, '') COLLATE utf8mb4_unicode_ci AND rp.produto <> '' AND rp.ativo = 1
               LEFT JOIN acordo_regras_carencia rd ON rd.credor_id = COALESCE(ao.credor_id, c.credor_id) AND rd.produto = '' AND rd.ativo = 1"
            : '';
        $ruleDays = $hasRules ? 'COALESCE(rp.dias_carencia, rd.dias_carencia, 0)' : '0';
        $deadline = "CASE WHEN JSON_VALID(ap.payload_extra) THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ap.payload_extra, '$.acme.deadline_date')), ''), 'null') ELSE NULL END";
        $fimCarencia = "COALESCE($deadline, CASE WHEN " . ($hasTracking ? 'ac.parcela_atual_id = ap.id' : '0=1') . " THEN " . ($hasTracking ? 'ac.fim_carencia' : 'NULL') . " ELSE NULL END, DATE_ADD(ap.data_vencimento, INTERVAL $ruleDays DAY))";

        $stmt = $this->pdo->prepare("SELECT
                ap.id AS parcela_id, ap.acordo_operacional_id AS acordo_id, ao.pessoa_id, ao.contrato_id,
                COALESCE(ao.credor_id, c.credor_id) AS credor_id, c.carteira_id, pe.nome AS cliente_nome,
                pe.documento, c.codigo_contrato, c.produto, cr.codigo AS credor_codigo, cr.nome AS credor_nome,
                ca.codigo AS carteira_codigo, ca.nome AS carteira_nome, ap.numero_parcela, ap.tipo AS tipo_parcela,
                ap.data_vencimento, ap.valor_parcela, ap.status AS status_parcela, ap.data_pagamento, ap.valor_pago,
                ao.status_operacional AS status_acordo, ao.payload_extra AS acordo_payload_extra,
                $tracking
                $deadline AS deadline_credor,
                $fimCarencia AS fim_carencia,
                CASE WHEN JSON_VALID(ap.payload_extra) THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ap.payload_extra, '$.acme.installment_id')), ''), 'null') ELSE NULL END AS installment_id_credor,
                CASE WHEN JSON_VALID(ap.payload_extra) THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ap.payload_extra, '$.acme.status_raw')), ''), 'null') ELSE NULL END AS status_parcela_credor,
                DATEDIFF(ap.data_vencimento, CURRENT_DATE) AS dias_para_vencimento,
                GREATEST(DATEDIFF(CURRENT_DATE, ap.data_vencimento), 0) AS dias_em_atraso,
                CASE
                    WHEN ap.status = 'paga' THEN 'paga'
                    WHEN ap.status = 'cancelada' THEN 'cancelada'
                    WHEN ap.status = 'renegociada' THEN 'renegociada'
                    WHEN ap.data_vencimento > CURRENT_DATE THEN 'a_vencer'
                    WHEN ap.data_vencimento = CURRENT_DATE THEN 'vence_hoje'
                    WHEN $fimCarencia >= CURRENT_DATE THEN 'em_carencia'
                    ELSE 'carencia_expirada'
                END AS situacao_preventiva,
                ap.criado_em, ap.atualizado_em
            FROM acordos_operacionais_parcelas ap
            INNER JOIN acordos_operacionais ao ON ao.id = ap.acordo_operacional_id
            INNER JOIN pessoas pe ON pe.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, c.credor_id)
            LEFT JOIN carteiras ca ON ca.id = c.carteira_id
            $trackingJoin
            $ruleJoins
            WHERE ap.id IN ($holders)
            ORDER BY ap.id");
        $stmt->execute($ids);

        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['parcela_id'];
            $status = (string) $row['status_parcela'];
            $responsavel = '';
            $agreementExtra = json_decode((string) ($row['acordo_payload_extra'] ?? ''), true);
            if (is_array($agreementExtra)) {
                $responsavel = (string) ($agreementExtra['responsavel_nome'] ?? $agreementExtra['responsavel']['nome'] ?? $agreementExtra['responsavel']['usuario_nome'] ?? '');
            }
            $documents[$id] = [
                'parcela_id' => $id,
                'acordo_id' => (int) $row['acordo_id'],
                'pessoa_id' => (int) $row['pessoa_id'],
                'contrato_id' => $this->nullableInt($row['contrato_id'] ?? null),
                'credor_id' => $this->nullableInt($row['credor_id'] ?? null),
                'carteira_id' => $this->nullableInt($row['carteira_id'] ?? null),
                'cliente_nome' => (string) $row['cliente_nome'],
                'documento' => $this->digits($row['documento'] ?? ''),
                'codigo_contrato' => (string) ($row['codigo_contrato'] ?? ''),
                'produto' => (string) ($row['produto'] ?? ''),
                'credor_codigo' => (string) ($row['credor_codigo'] ?? ''),
                'credor_nome' => (string) ($row['credor_nome'] ?? ''),
                'carteira_codigo' => (string) ($row['carteira_codigo'] ?? ''),
                'carteira_nome' => (string) ($row['carteira_nome'] ?? ''),
                'numero_parcela' => (int) $row['numero_parcela'],
                'tipo_parcela' => (string) $row['tipo_parcela'],
                'data_vencimento' => (string) $row['data_vencimento'],
                'valor_parcela' => (float) $row['valor_parcela'],
                'status_parcela' => $status,
                'data_pagamento' => $this->nullableString($row['data_pagamento'] ?? null),
                'valor_pago' => (float) ($row['valor_pago'] ?? 0),
                'deadline_credor' => $this->nullableString($row['deadline_credor'] ?? null),
                'fim_carencia' => $this->nullableString($row['fim_carencia'] ?? null),
                'dias_para_vencimento' => (int) $row['dias_para_vencimento'],
                'dias_em_atraso' => (int) $row['dias_em_atraso'],
                'situacao_preventiva' => (string) $row['situacao_preventiva'],
                'status_acordo' => (string) $row['status_acordo'],
                'situacao_carteira' => (string) ($row['situacao_carteira'] ?? ''),
                'responsavel' => $responsavel,
                'proxima_acao' => (string) ($row['proxima_acao'] ?? ''),
                'installment_id_credor' => (string) ($row['installment_id_credor'] ?? ''),
                'status_parcela_credor' => (string) ($row['status_parcela_credor'] ?? ''),
                'paga' => $status === 'paga',
                'aberta' => in_array($status, ['pendente', 'vencida'], true),
                'criado_em' => (string) $row['criado_em'],
                'atualizado_em' => (string) $row['atualizado_em'],
            ];
        }
        return $documents;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadActions(array $ids): array
    {
        [$ids, $holders] = $this->idsAndPlaceholders($ids);
        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT
                ah.id AS acionamento_id, ah.pessoa_id, ah.contrato_id, ah.contato_id, ah.usuario_id,
                pe.nome AS cliente_nome, pe.documento, c.codigo_contrato, c.produto, c.credor_id, c.carteira_id,
                cr.codigo AS credor_codigo, cr.nome AS credor_nome, ca.codigo AS carteira_codigo, ca.nome AS carteira_nome,
                COALESCE(u.nome, ah.operador_nome) AS operador_nome, ah.canal, ah.resultado AS resultado_legacy,
                ah.resultado_id, ar.codigo AS resultado_codigo, ar.nome AS resultado_nome, ar.categoria AS resultado_categoria,
                COALESCE(ar.considera_sucesso, 0) AS considera_sucesso,
                COALESCE(ar.considera_contato_efetivo, 0) AS contato_efetivo,
                COALESCE(ar.considera_telefone_ruim, 0) AS telefone_ruim,
                COALESCE(ar.considera_alo, 0) AS considera_alo, c.lote,
                COALESCE(ar.resultado_legacy, ah.resultado) AS tabulacao_legacy,
                ah.motivo_inadimplencia_id, mi.nome AS motivo_inadimplencia,
                ah.contato_utilizado, ah.proximo_acionamento, ah.retorno_status, ah.retorno_tratado_em,
                ah.origem, ah.campanha_item_id, ci.campanha_id, cp.nome AS campanha_nome,
                cp.status AS campanha_status, ci.status AS campanha_item_status, ah.criado_em, ah.atualizado_em
            FROM atendimentos_historico ah
            INNER JOIN pessoas pe ON pe.id = ah.pessoa_id
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            LEFT JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras ca ON ca.id = c.carteira_id
            LEFT JOIN usuarios u ON u.id = ah.usuario_id
            LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
            LEFT JOIN motivos_inadimplencia mi ON mi.id = ah.motivo_inadimplencia_id
            LEFT JOIN campanha_itens ci ON ci.id = ah.campanha_item_id
            LEFT JOIN campanhas cp ON cp.id = ci.campanha_id
            WHERE ah.id IN ($holders)
            ORDER BY ah.id");
        $stmt->execute($ids);

        // Acordo fechado no CRM a partir deste acionamento (mesma regra do
        // dashboard operacional: acordos_operacionais.atendimento_id com
        // origem classificada como 'works').
        $acordoStmt = $this->pdo->prepare("SELECT atendimento_id, origem, payload_extra
            FROM acordos_operacionais WHERE atendimento_id IN ($holders)");
        $acordoStmt->execute($ids);
        $comAcordo = [];
        foreach ($acordoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $acordoRow) {
            if (AcordoOperacionalRepository::contabilizaNoDashboard($acordoRow)) {
                $comAcordo[(int) $acordoRow['atendimento_id']] = true;
            }
        }

        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['acionamento_id'];
            $ligacao = (string) $row['canal'] === 'ligacao';
            // Tabulação de acordo = falou com o cliente: alô e CPC em qualquer canal.
            $tabulacaoAcordo = (string) $row['tabulacao_legacy'] === 'promessa_pagamento';
            $cpc = ($ligacao && (bool) $row['contato_efetivo']) || $tabulacaoAcordo;
            $alo = $cpc || ($ligacao && (bool) $row['considera_alo']);
            $acordo = isset($comAcordo[$id]);
            $documents[$id] = [
                'acionamento_id' => $id,
                'pessoa_id' => (int) $row['pessoa_id'],
                'contrato_id' => $this->nullableInt($row['contrato_id'] ?? null),
                'contato_id' => $this->nullableInt($row['contato_id'] ?? null),
                'usuario_id' => $this->nullableInt($row['usuario_id'] ?? null),
                'cliente_nome' => (string) $row['cliente_nome'],
                'documento' => $this->digits($row['documento'] ?? ''),
                'codigo_contrato' => (string) ($row['codigo_contrato'] ?? ''),
                'produto' => (string) ($row['produto'] ?? ''),
                'credor_id' => $this->nullableInt($row['credor_id'] ?? null),
                'credor_codigo' => (string) ($row['credor_codigo'] ?? ''),
                'credor_nome' => (string) ($row['credor_nome'] ?? ''),
                'carteira_id' => $this->nullableInt($row['carteira_id'] ?? null),
                'carteira_codigo' => (string) ($row['carteira_codigo'] ?? ''),
                'carteira_nome' => (string) ($row['carteira_nome'] ?? ''),
                'operador_nome' => (string) ($row['operador_nome'] ?? ''),
                'canal' => (string) $row['canal'],
                'resultado_legacy' => (string) $row['resultado_legacy'],
                'resultado_id' => $this->nullableInt($row['resultado_id'] ?? null),
                'resultado_codigo' => (string) ($row['resultado_codigo'] ?? ''),
                'resultado_nome' => (string) ($row['resultado_nome'] ?? $row['resultado_legacy']),
                'resultado_categoria' => (string) ($row['resultado_categoria'] ?? ''),
                'considera_sucesso' => (bool) $row['considera_sucesso'],
                'contato_efetivo' => (bool) $row['contato_efetivo'],
                'telefone_ruim' => (bool) $row['telefone_ruim'],
                'contato_utilizado' => (string) ($row['contato_utilizado'] ?? ''),
                'proximo_acionamento' => $this->nullableString($row['proximo_acionamento'] ?? null),
                'retorno_status' => (string) ($row['retorno_status'] ?? ''),
                'retorno_tratado_em' => $this->nullableString($row['retorno_tratado_em'] ?? null),
                'origem' => (string) $row['origem'],
                'campanha_item_id' => $this->nullableInt($row['campanha_item_id'] ?? null),
                'campanha_id' => $this->nullableInt($row['campanha_id'] ?? null),
                'campanha_nome' => (string) ($row['campanha_nome'] ?? ''),
                'campanha_status' => (string) ($row['campanha_status'] ?? ''),
                'campanha_item_status' => (string) ($row['campanha_item_status'] ?? ''),
                'lote' => (string) ($row['lote'] ?? ''),
                'motivo_inadimplencia_id' => $this->nullableInt($row['motivo_inadimplencia_id'] ?? null),
                'motivo_inadimplencia' => (string) ($row['motivo_inadimplencia'] ?? ''),
                'tentativa' => true,
                'alo' => $alo,
                'cpc' => $cpc,
                'acordo' => $acordo,
                'flag_tentativa' => 1,
                'flag_alo' => $alo ? 1 : 0,
                'flag_cpc' => $cpc ? 1 : 0,
                'flag_acordo' => $acordo ? 1 : 0,
                'tipo_registro' => 'acionamento',
                'flag_acordo_fechado' => 0,
                'funil_fluxo' => 'alo ' . ($alo ? 1 : 0) . ' cpc ' . ($cpc ? 1 : 0) . ' acordo ' . ($acordo ? 1 : 0),
                'criado_em' => (string) $row['criado_em'],
                'atualizado_em' => (string) $row['atualizado_em'],
            ];
        }
        return $documents;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadStrategyResults(array $ids): array
    {
        [$ids, $holders] = $this->idsAndPlaceholders($ids);
        if ($ids === []) {
            return [];
        }

        $hasAgreementLink = $this->columnExists('estrategia_contratos', 'acordo_id');
        $agreementSelect = $hasAgreementLink
            ? "ec.acordo_id, ao.status_operacional AS acordo_status, ao.valor_acordo AS acordo_valor,
                COALESCE((SELECT SUM(CASE WHEN ap.status = 'paga' THEN COALESCE(ap.valor_pago, ap.valor_parcela) ELSE 0 END) FROM acordos_operacionais_parcelas ap WHERE ap.acordo_operacional_id = ao.id), 0) AS acordo_valor_pago,
                COALESCE((SELECT SUM(CASE WHEN ap.status = 'paga' THEN 1 ELSE 0 END) FROM acordos_operacionais_parcelas ap WHERE ap.acordo_operacional_id = ao.id), 0) AS acordo_parcelas_pagas,
                ao.criado_em AS acordo_em, ao.criado_por_usuario_id AS acordo_operador_id, COALESCE(uac.nome, ao.operador_nome) AS acordo_operador_nome,"
            : 'NULL AS acordo_id, NULL AS acordo_status, NULL AS acordo_valor, 0 AS acordo_valor_pago, 0 AS acordo_parcelas_pagas, NULL AS acordo_em, NULL AS acordo_operador_id, NULL AS acordo_operador_nome,';
        $agreementJoin = $hasAgreementLink
            ? 'LEFT JOIN acordos_operacionais ao ON ao.id = ec.acordo_id LEFT JOIN usuarios uac ON uac.id = ao.criado_por_usuario_id'
            : '';

        $stmt = $this->pdo->prepare("SELECT
                ec.id AS estrategia_contrato_id, ec.estrategia_id, e.nome AS estrategia_nome,
                e.status AS estrategia_status, e.prioridade AS estrategia_prioridade,
                ec.status_operacional, ec.ordem_prioridade,
                ec.pessoa_id, ec.contrato_id, p.nome AS cliente_nome, p.documento,
                c.codigo_contrato, c.produto, c.credor_id, c.carteira_id,
                cr.codigo AS credor_codigo, cr.nome AS credor_nome,
                ca.codigo AS carteira_codigo, ca.nome AS carteira_nome,
                ec.reservado_por_usuario_id, ur.nome AS reservado_por_nome,
                ah.id AS acionamento_id, ah.usuario_id AS acionamento_usuario_id,
                COALESCE(ua.nome, ah.operador_nome) AS acionamento_operador_nome,
                ah.canal, ah.resultado_id, ar.codigo AS resultado_codigo,
                COALESCE(ar.nome, ah.resultado) AS resultado_nome,
                ar.categoria AS resultado_categoria,
                COALESCE(ar.considera_contato_efetivo, 0) AS contato_efetivo,
                COALESCE(ar.considera_sucesso, 0) AS considera_sucesso,
                ah.criado_em AS acionamento_em,
                $agreementSelect
                ec.saldo_snapshot, ec.valor_original_snapshot, ec.valor_pago_snapshot,
                ec.valor_faltante_snapshot, ec.aging_snapshot,
                ec.data_entrada_base_snapshot, ec.data_vencimento_original_snapshot,
                ec.quantidade_tentativas_snapshot,
                ec.incluido_em, ec.aberto_em, ec.trabalhado_em, ec.removido_em,
                ec.criado_em, ec.atualizado_em
            FROM estrategia_contratos ec
            INNER JOIN estrategias e ON e.id = ec.estrategia_id
            INNER JOIN pessoas p ON p.id = ec.pessoa_id
            INNER JOIN contratos c ON c.id = ec.contrato_id
            LEFT JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras ca ON ca.id = c.carteira_id
            LEFT JOIN usuarios ur ON ur.id = ec.reservado_por_usuario_id
            LEFT JOIN atendimentos_historico ah ON ah.id = ec.atendimento_id
            LEFT JOIN usuarios ua ON ua.id = ah.usuario_id
            LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
            $agreementJoin
            WHERE ec.id IN ($holders)
            ORDER BY ec.id");
        $stmt->execute($ids);

        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['estrategia_contrato_id'];
            $actionId = $this->nullableInt($row['acionamento_id'] ?? null);
            $agreementId = $this->nullableInt($row['acordo_id'] ?? null);
            $operatorId = $this->nullableInt($row['acionamento_usuario_id'] ?? null)
                ?? $this->nullableInt($row['reservado_por_usuario_id'] ?? null);
            $operatorName = trim((string) ($row['acionamento_operador_nome'] ?? ''));
            if ($operatorName === '') {
                $operatorName = (string) ($row['reservado_por_nome'] ?? '');
            }

            $documents[$id] = [
                'estrategia_contrato_id' => $id,
                'estrategia_id' => (int) $row['estrategia_id'],
                'estrategia_nome' => (string) ($row['estrategia_nome'] ?? ''),
                'estrategia_status' => (string) ($row['estrategia_status'] ?? ''),
                'estrategia_prioridade' => (int) ($row['estrategia_prioridade'] ?? 0),
                'status_operacional' => (string) ($row['status_operacional'] ?? ''),
                'ordem_prioridade' => (int) ($row['ordem_prioridade'] ?? 0),
                'pessoa_id' => (int) $row['pessoa_id'],
                'contrato_id' => (int) $row['contrato_id'],
                'cliente_nome' => (string) ($row['cliente_nome'] ?? ''),
                'documento' => $this->digits($row['documento'] ?? ''),
                'codigo_contrato' => (string) ($row['codigo_contrato'] ?? ''),
                'produto' => (string) ($row['produto'] ?? ''),
                'credor_id' => $this->nullableInt($row['credor_id'] ?? null),
                'credor_codigo' => (string) ($row['credor_codigo'] ?? ''),
                'credor_nome' => (string) ($row['credor_nome'] ?? ''),
                'carteira_id' => $this->nullableInt($row['carteira_id'] ?? null),
                'carteira_codigo' => (string) ($row['carteira_codigo'] ?? ''),
                'carteira_nome' => (string) ($row['carteira_nome'] ?? ''),
                'operador_id' => $operatorId,
                'operador_nome' => $operatorName,
                'reservado_por_usuario_id' => $this->nullableInt($row['reservado_por_usuario_id'] ?? null),
                'reservado_por_nome' => (string) ($row['reservado_por_nome'] ?? ''),
                'teve_acionamento' => $actionId !== null,
                'tentou_contato' => $actionId !== null,
                'acionamento_id' => $actionId,
                'acionamento_em' => $this->nullableString($row['acionamento_em'] ?? null),
                'canal' => (string) ($row['canal'] ?? ''),
                'resultado_id' => $this->nullableInt($row['resultado_id'] ?? null),
                'resultado_codigo' => (string) ($row['resultado_codigo'] ?? ''),
                'resultado_nome' => (string) ($row['resultado_nome'] ?? ''),
                'resultado_categoria' => (string) ($row['resultado_categoria'] ?? ''),
                'contato_efetivo' => (bool) ($row['contato_efetivo'] ?? false),
                'considera_sucesso' => (bool) ($row['considera_sucesso'] ?? false),
                'fechou_acordo' => $agreementId !== null,
                'acordo_id' => $agreementId,
                'acordo_status' => (string) ($row['acordo_status'] ?? ''),
                'acordo_valor' => (float) ($row['acordo_valor'] ?? 0),
                'acordo_valor_pago' => (float) ($row['acordo_valor_pago'] ?? 0),
                'acordo_valor_aberto' => max(0.0, (float) ($row['acordo_valor'] ?? 0) - (float) ($row['acordo_valor_pago'] ?? 0)),
                'acordo_parcelas_pagas' => (int) ($row['acordo_parcelas_pagas'] ?? 0),
                'acordo_quitado' => (string) ($row['acordo_status'] ?? '') === 'cumprido',
                'acordo_quebrado' => (string) ($row['acordo_status'] ?? '') === 'quebrado',
                'teve_pagamento_acordo' => (float) ($row['acordo_valor_pago'] ?? 0) > 0,
                'acordo_em' => $this->nullableString($row['acordo_em'] ?? null),
                'acordo_operador_id' => $this->nullableInt($row['acordo_operador_id'] ?? null),
                'acordo_operador_nome' => (string) ($row['acordo_operador_nome'] ?? ''),
                'saldo_snapshot' => (float) ($row['saldo_snapshot'] ?? 0),
                'valor_original_snapshot' => (float) ($row['valor_original_snapshot'] ?? 0),
                'valor_pago_snapshot' => (float) ($row['valor_pago_snapshot'] ?? 0),
                'valor_faltante_snapshot' => (float) ($row['valor_faltante_snapshot'] ?? 0),
                'aging_snapshot' => (int) ($row['aging_snapshot'] ?? 0),
                'data_entrada_base_snapshot' => $this->nullableString($row['data_entrada_base_snapshot'] ?? null),
                'data_vencimento_original_snapshot' => $this->nullableString($row['data_vencimento_original_snapshot'] ?? null),
                'quantidade_tentativas_snapshot' => (int) ($row['quantidade_tentativas_snapshot'] ?? 0),
                'incluido_em' => (string) ($row['incluido_em'] ?? ''),
                'aberto_em' => $this->nullableString($row['aberto_em'] ?? null),
                'trabalhado_em' => $this->nullableString($row['trabalhado_em'] ?? null),
                'removido_em' => $this->nullableString($row['removido_em'] ?? null),
                'criado_em' => (string) ($row['criado_em'] ?? ''),
                'atualizado_em' => (string) ($row['atualizado_em'] ?? ''),
            ];
        }
        return $documents;
    }

    /** @return array{indexed:int,last_id:int} */
    private function reindexKind(string $kind, string $table, int $batchSize): array
    {
        $lastId = 0;
        $indexed = 0;
        while (true) {
            $stmt = $this->pdo->prepare("SELECT id FROM `$table` WHERE id > :last_id ORDER BY id ASC LIMIT :limite");
            $stmt->bindValue('last_id', $lastId, PDO::PARAM_INT);
            $stmt->bindValue('limite', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
            if ($ids === []) {
                break;
            }
            $documents = $this->loadByKind($kind, $ids);
            $lastId = max($ids);
            $this->bulkIndex($kind, $documents);
            $indexed += count($documents);
        }

        if ($kind === 'acionamentos') {
            $this->reindexAgreementFunnelDocs($batchSize);
        }

        $refresh = $this->http->request('POST', $this->baseUrl . '/' . rawurlencode($this->indices[$kind]) . '/_refresh', '', 60);
        $this->http->requireSuccess($refresh, 'Atualizacao do indice ' . $this->indices[$kind]);
        return ['indexed' => $indexed, 'last_id' => $lastId];
    }

    private function bulkByQuery(string $kind, string $sql, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->bulkIndex($kind, $this->loadByKind($kind, $chunk));
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function loadByKind(string $kind, array $ids): array
    {
        return match ($kind) {
            'clientes' => $this->loadPeople($ids),
            'acordos' => $this->loadAgreements($ids),
            'preventiva' => $this->loadInstallments($ids),
            'acionamentos' => $this->loadActions($ids),
            'estrategias' => $this->loadStrategyResults($ids),
            default => throw new RuntimeException('Projecao operacional desconhecida: ' . $kind),
        };
    }

    /** @param array<int,array<string,mixed>> $documents */
    private function indexOne(string $kind, int $id, array $documents): void
    {
        if ($id <= 0) {
            return;
        }
        if (!isset($documents[$id])) {
            $this->deleteDocument($kind, $id);
            return;
        }
        $response = $this->http->request(
            'PUT',
            $this->baseUrl . '/' . rawurlencode($this->indices[$kind]) . '/_doc/' . $id,
            $documents[$id],
            30
        );
        $this->http->requireSuccess($response, 'Indexacao em ' . $this->indices[$kind] . ' #' . $id);
    }

    private function deleteDocument(string $kind, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $response = $this->http->request('DELETE', $this->baseUrl . '/' . rawurlencode($this->indices[$kind]) . '/_doc/' . $id, null, 30);
        if ($response['status'] !== 404) {
            $this->http->requireSuccess($response, 'Remocao em ' . $this->indices[$kind] . ' #' . $id);
        }
    }

    private function deleteByTerm(string $kind, string $field, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $response = $this->http->request(
            'POST',
            $this->baseUrl . '/' . rawurlencode($this->indices[$kind]) . '/_delete_by_query?conflicts=proceed&refresh=true',
            ['query' => ['term' => [$field => $id]]],
            60
        );
        if ($response['status'] !== 404) {
            $this->http->requireSuccess($response, 'Remocao derivada em ' . $this->indices[$kind]);
        }
    }

    /** @param array<int,array<string,mixed>> $documents */
    private function bulkIndex(string $kind, array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $lines = [];
        foreach ($documents as $id => $document) {
            $lines[] = json_encode(['index' => ['_index' => $this->indices[$kind], '_id' => (string) $id]], JSON_THROW_ON_ERROR);
            $lines[] = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $response = $this->http->request('POST', $this->baseUrl . '/_bulk', implode("\n", $lines) . "\n", 120, ['Content-Type: application/x-ndjson']);
        $this->http->requireSuccess($response, 'Carga em lote do indice ' . $this->indices[$kind]);
        if (($response['json']['errors'] ?? false) === true) {
            throw new RuntimeException('OpenSearch informou erro em um ou mais documentos de ' . $this->indices[$kind] . '.');
        }
    }

    private function scalarId(string $sql, int $id): ?int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    /** @return array{0:list<int>,1:string} */
    private function idsAndPlaceholders(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        return [$ids, implode(',', array_fill(0, count($ids), '?'))];
    }

    private function activeContractSql(string $alias): string
    {
        return "COALESCE($alias.saldo_atual, 0) > 0
            AND LOWER(COALESCE($alias.status_contrato, '')) NOT LIKE '%liquid%'
            AND LOWER(COALESCE($alias.status_contrato, '')) NOT LIKE '%pag%'
            AND LOWER(COALESCE($alias.status_contrato, '')) NOT LIKE '%quit%'
            AND LOWER(COALESCE($alias.status_contrato, '')) NOT LIKE '%cancel%'
            AND LOWER(COALESCE($alias.status_contrato, '')) NOT LIKE '%encerr%'
            AND LOWER(COALESCE($alias.status_contrato, '')) NOT LIKE '%baix%'";
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /** @return list<string> */
    private function splitList(mixed $value): array
    {
        return $value === null ? [] : array_values(array_filter(explode('|||', (string) $value), static fn (string $item): bool => $item !== ''));
    }
}
