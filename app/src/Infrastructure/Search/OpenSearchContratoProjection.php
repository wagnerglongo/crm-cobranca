<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Search;

use CRM\Config\Database;
use CRM\Infrastructure\Http\JsonHttpClient;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use PDO;
use RuntimeException;

final class OpenSearchContratoProjection
{
    private readonly string $baseUrl;
    private readonly string $index;

    public function __construct(
        private readonly PDO $pdo,
        private readonly JsonHttpClient $http = new JsonHttpClient()
    ) {
        $this->baseUrl = rtrim((string) Database::env('OPENSEARCH_HOST', 'http://opensearch:9200'), '/');
        $this->index = (string) Database::env('OPENSEARCH_CONTRATOS_INDEX', 'crm_contratos_v1');
    }

    public function ensureIndex(): void
    {
        $head = $this->http->request('HEAD', $this->baseUrl . '/' . rawurlencode($this->index), null, 30);
        if ($head['status'] === 200) {
            $this->ensureOperationalAgreementMapping();
            return;
        }
        if ($head['status'] !== 404) {
            $this->http->requireSuccess($head, 'Consulta do indice OpenSearch');
        }

        $response = $this->http->request('PUT', $this->baseUrl . '/' . rawurlencode($this->index), [
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
            'mappings' => [
                'dynamic' => 'strict',
                'properties' => [
                    'contrato_id' => ['type' => 'long'],
                    'pessoa_id' => ['type' => 'long'],
                    'credor_id' => ['type' => 'long'],
                    'carteira_id' => ['type' => 'long'],
                    'codigo_contrato' => ['type' => 'keyword'],
                    'conta_id' => ['type' => 'keyword'],
                    'devedor_origem_id' => ['type' => 'keyword'],
                    'produto' => ['type' => 'keyword'],
                    'subproduto' => ['type' => 'keyword'],
                    'lote' => ['type' => 'keyword'],
                    'fila' => ['type' => 'keyword'],
                    'status_devedor' => ['type' => 'keyword'],
                    'status_contrato' => ['type' => 'keyword'],
                    'status_contrato_credor' => ['type' => 'keyword'],
                    'data_vencimento_inicial' => ['type' => 'date', 'format' => 'yyyy-MM-dd||strict_date_optional_time'],
                    'dias_atraso' => ['type' => 'integer'],
                    'saldo_atual' => ['type' => 'double'],
                    'saldo_contabil' => ['type' => 'double'],
                    'valor_total_pago' => ['type' => 'double'],
                    'valor_principal' => ['type' => 'double'],
                    'valor_financiado' => ['type' => 'double'],
                    'valor_contratado' => ['type' => 'double'],
                    'pessoa_nome' => ['type' => 'text', 'analyzer' => 'crm_texto'],
                    'pessoa_documento' => ['type' => 'keyword'],
                    'contatos' => ['type' => 'keyword'],
                    'telefone_principal' => ['type' => 'keyword'],
                    'whatsapp_principal' => ['type' => 'keyword'],
                    'email_principal' => ['type' => 'keyword'],
                    'cidade' => ['type' => 'keyword'],
                    'uf' => ['type' => 'keyword'],
                    'credor_codigo' => ['type' => 'keyword'],
                    'credor_nome' => ['type' => 'keyword'],
                    'carteira_codigo' => ['type' => 'keyword'],
                    'carteira_nome' => ['type' => 'keyword'],
                    'tem_acordo_ativo' => ['type' => 'boolean'],
                    'situacao_acordo' => ['type' => 'keyword'],
                    'acordo_ativo_id' => ['type' => 'long'],
                    'acordo_ativo_status' => ['type' => 'keyword'],
                    'ultimo_acordo_id' => ['type' => 'long'],
                    'ultimo_acordo_status' => ['type' => 'keyword'],
                    'ultimo_acordo_em' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss||strict_date_optional_time'],
                    'responsavel_negociacao_grupo' => ['type' => 'keyword'],
                    'parcelas_total' => ['type' => 'integer'],
                    'parcelas_abertas' => ['type' => 'integer'],
                    'parcelas_vencidas' => ['type' => 'integer'],
                    'parcelas_liquidadas' => ['type' => 'integer'],
                    'proximo_vencimento_parcela' => ['type' => 'date', 'format' => 'yyyy-MM-dd||strict_date_optional_time'],
                    'total_acionamentos' => ['type' => 'integer'],
                    'ultimo_acionamento_em' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss||strict_date_optional_time'],
                    'ultimo_resultado' => ['type' => 'keyword'],
                    'ultimo_canal' => ['type' => 'keyword'],
                    'disponivel_negociacao' => ['type' => 'boolean'],
                    'atualizado_em' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss||strict_date_optional_time'],
                ],
            ],
        ], 60);
        $this->http->requireSuccess($response, 'Criacao do indice OpenSearch');
    }

    public function indexContract(int $contractId): void
    {
        $document = $this->loadContract($contractId);
        if ($document === null) {
            $this->deleteContract($contractId);
            return;
        }

        $response = $this->http->request(
            'PUT',
            $this->baseUrl . '/' . rawurlencode($this->index) . '/_doc/' . $contractId,
            $document,
            30
        );
        $this->http->requireSuccess($response, 'Indexacao do contrato #' . $contractId);
    }

    public function deleteContract(int $contractId): void
    {
        $response = $this->http->request('DELETE', $this->baseUrl . '/' . rawurlencode($this->index) . '/_doc/' . $contractId, null, 30);
        if ($response['status'] !== 404) {
            $this->http->requireSuccess($response, 'Remocao do contrato #' . $contractId . ' do OpenSearch');
        }
    }

    public function reindexPerson(int $personId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM contratos WHERE pessoa_id = :pessoa_id ORDER BY id');
        $stmt->execute(['pessoa_id' => $personId]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        $documents = $this->loadContracts($ids);
        $this->bulkIndex($documents);
        return count($documents);
    }

    /** @return array{indexed:int,last_id:int} */
    public function reindexAll(int $batchSize = 500): array
    {
        $this->ensureIndex();
        $batchSize = max(50, min(2000, $batchSize));
        $lastId = 0;
        $indexed = 0;

        while (true) {
            $stmt = $this->pdo->prepare('SELECT id FROM contratos WHERE id > :last_id ORDER BY id ASC LIMIT :limite');
            $stmt->bindValue('last_id', $lastId, PDO::PARAM_INT);
            $stmt->bindValue('limite', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
            if ($ids === []) {
                break;
            }

            $documents = $this->loadContracts($ids);
            $lastId = max($ids);
            $this->bulkIndex($documents);
            $indexed += count($documents);
        }

        $refresh = $this->http->request('POST', $this->baseUrl . '/' . rawurlencode($this->index) . '/_refresh', '', 60);
        $this->http->requireSuccess($refresh, 'Atualizacao do indice OpenSearch');
        return ['indexed' => $indexed, 'last_id' => $lastId];
    }

    /** @return array{ok:bool,index:string,documents:int} */
    public function status(int $timeout = 10): array
    {
        $timeout = max(5, min(120, $timeout));
        $response = $this->http->request('GET', $this->baseUrl . '/' . rawurlencode($this->index) . '/_count', null, $timeout);
        if ($response['status'] === 404) {
            return ['ok' => false, 'index' => $this->index, 'documents' => 0];
        }
        $this->http->requireSuccess($response, 'Contagem do OpenSearch');
        return [
            'ok' => true,
            'index' => $this->index,
            'documents' => (int) (($response['json']['count'] ?? 0)),
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadContract(int $contractId): ?array
    {
        $documents = $this->loadContracts([$contractId]);
        return $documents[$contractId] ?? null;
    }

    /** @param list<int> $contractIds @return array<int,array<string,mixed>> */
    private function loadContracts(array $contractIds): array
    {
        $contractIds = array_values(array_unique(array_filter(array_map('intval', $contractIds), static fn (int $id): bool => $id > 0)));
        if ($contractIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
        $stmt = $this->pdo->prepare("SELECT
                c.id AS contrato_id,
                c.pessoa_id,
                c.credor_id,
                c.carteira_id,
                c.codigo_contrato,
                c.conta_id,
                c.devedor_origem_id,
                c.produto,
                c.subproduto,
                c.lote,
                c.fila,
                c.status_devedor,
                c.status_contrato,
                c.data_vencimento_inicial,
                c.dias_atraso,
                c.saldo_atual,
                c.saldo_contabil,
                c.valor_total_pago,
                c.valor_principal,
                c.valor_financiado,
                c.valor_contratado,
                c.atualizado_em,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                cr.codigo AS credor_codigo,
                cr.nome AS credor_nome,
                car.codigo AS carteira_codigo,
                car.nome AS carteira_nome,
                (SELECT pc.valor_normalizado
                   FROM pessoa_contatos pc
                  WHERE pc.pessoa_id = p.id
                    AND pc.tipo = 'telefone'
                    AND pc.status NOT IN ('incorreto', 'bloqueado')
                  ORDER BY pc.principal DESC, pc.id DESC LIMIT 1) AS telefone_principal,
                (SELECT pc.valor_normalizado
                   FROM pessoa_contatos pc
                  WHERE pc.pessoa_id = p.id
                    AND pc.tipo = 'whatsapp'
                    AND pc.status NOT IN ('incorreto', 'bloqueado')
                  ORDER BY pc.principal DESC, pc.id DESC LIMIT 1) AS whatsapp_principal,
                (SELECT pc.valor_normalizado
                   FROM pessoa_contatos pc
                  WHERE pc.pessoa_id = p.id
                    AND pc.tipo = 'email'
                    AND pc.status NOT IN ('incorreto', 'bloqueado')
                  ORDER BY pc.principal DESC, pc.id DESC LIMIT 1) AS email_principal,
                (SELECT pe.cidade FROM pessoa_enderecos pe WHERE pe.pessoa_id = p.id ORDER BY pe.principal DESC, pe.id DESC LIMIT 1) AS cidade,
                (SELECT pe.uf FROM pessoa_enderecos pe WHERE pe.pessoa_id = p.id ORDER BY pe.principal DESC, pe.id DESC LIMIT 1) AS uf,
                (SELECT ao.id
                   FROM acordos_operacionais ao
                  WHERE ao.contrato_id = c.id
                    AND ao.status_operacional IN ('pendente', 'formalizado')
                  ORDER BY ao.id DESC
                  LIMIT 1) AS acordo_ativo_id,
                (SELECT ao.status_operacional
                   FROM acordos_operacionais ao
                  WHERE ao.contrato_id = c.id
                    AND ao.status_operacional IN ('pendente', 'formalizado')
                  ORDER BY ao.id DESC
                  LIMIT 1) AS acordo_ativo_status,
                (SELECT ao.id FROM acordos_operacionais ao WHERE ao.contrato_id = c.id ORDER BY ao.id DESC LIMIT 1) AS ultimo_acordo_id,
                (SELECT ao.status_operacional FROM acordos_operacionais ao WHERE ao.contrato_id = c.id ORDER BY ao.id DESC LIMIT 1) AS ultimo_acordo_status,
                (SELECT ao.criado_em FROM acordos_operacionais ao WHERE ao.contrato_id = c.id ORDER BY ao.id DESC LIMIT 1) AS ultimo_acordo_em,
                (SELECT ao.payload_extra FROM acordos_operacionais ao WHERE ao.contrato_id = c.id AND JSON_VALID(ao.payload_extra) ORDER BY ao.id DESC LIMIT 1) AS ultimo_acordo_payload_extra,
                (SELECT COUNT(*) FROM contrato_parcelas cp WHERE cp.contrato_id = c.id) AS parcelas_total,
                (SELECT COUNT(*) FROM contrato_parcelas cp WHERE cp.contrato_id = c.id AND COALESCE(cp.flag_liquidado, 0) = 0) AS parcelas_abertas,
                (SELECT COUNT(*) FROM contrato_parcelas cp WHERE cp.contrato_id = c.id AND COALESCE(cp.flag_liquidado, 0) = 0 AND cp.data_vencimento < CURRENT_DATE) AS parcelas_vencidas,
                (SELECT COUNT(*) FROM contrato_parcelas cp WHERE cp.contrato_id = c.id AND COALESCE(cp.flag_liquidado, 0) = 1) AS parcelas_liquidadas,
                (SELECT MIN(cp.data_vencimento) FROM contrato_parcelas cp WHERE cp.contrato_id = c.id AND COALESCE(cp.flag_liquidado, 0) = 0) AS proximo_vencimento_parcela,
                (SELECT COUNT(*) FROM atendimentos_historico ah WHERE ah.contrato_id = c.id) AS total_acionamentos,
                (SELECT ah.criado_em FROM atendimentos_historico ah WHERE ah.contrato_id = c.id ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 1) AS ultimo_acionamento_em,
                (SELECT COALESCE(ar.nome, ah.resultado) FROM atendimentos_historico ah LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id WHERE ah.contrato_id = c.id ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 1) AS ultimo_resultado,
                (SELECT ah.canal FROM atendimentos_historico ah WHERE ah.contrato_id = c.id ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 1) AS ultimo_canal,
                (SELECT GROUP_CONCAT(DISTINCT pc.valor_normalizado SEPARATOR ',')
                   FROM pessoa_contatos pc
                  WHERE pc.pessoa_id = p.id) AS contatos
            FROM contratos c
            INNER JOIN pessoas p ON p.id = c.pessoa_id
            INNER JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            WHERE c.id IN ($placeholders)
            ORDER BY c.id");
        $stmt->execute($contractIds);

        $documents = [];
        while (($row = $stmt->fetch()) !== false) {
            $id = (int) $row['contrato_id'];
            // status_contrato vem da carga do credor. A situacao de acordo do
            // CRM depende exclusivamente de um acordo operacional ativo real.
            $temAcordoAtivo = $row['acordo_ativo_id'] !== null;
            $statusContrato = mb_strtolower((string) ($row['status_contrato'] ?? ''));
            $statusEncerrado = preg_match('/liquid|pag|quit|cancel|encerr|baix/', $statusContrato) === 1;
            $disponivelNegociacao = (float) ($row['saldo_atual'] ?? 0) > 0 && !$statusEncerrado && !$temAcordoAtivo;
            $payloadUltimoAcordo = json_decode((string) ($row['ultimo_acordo_payload_extra'] ?? ''), true);
            $payloadUltimoAcordo = is_array($payloadUltimoAcordo) ? $payloadUltimoAcordo : [];
            $snapshotAcordo = $payloadUltimoAcordo['acme_agreement_snapshot'] ?? $payloadUltimoAcordo['acme_ultima_liquidacao_snapshot'] ?? [];
            $notaNegociacao = is_array($snapshotAcordo) ? (string) ($snapshotAcordo['negotiation_notes'] ?? '') : '';
            $responsavelGrupo = AcordoOperacionalRepository::grupoFila(AcordoOperacionalRepository::extrairResponsavelNegociacao($notaNegociacao));
            $documents[$id] = [
                'contrato_id' => $id,
                'pessoa_id' => (int) $row['pessoa_id'],
                'credor_id' => (int) $row['credor_id'],
                'carteira_id' => $row['carteira_id'] === null ? null : (int) $row['carteira_id'],
                'codigo_contrato' => (string) $row['codigo_contrato'],
                'conta_id' => (string) ($row['conta_id'] ?? ''),
                'devedor_origem_id' => (string) ($row['devedor_origem_id'] ?? ''),
                'produto' => (string) ($row['produto'] ?? ''),
                'subproduto' => (string) ($row['subproduto'] ?? ''),
                'lote' => (string) ($row['lote'] ?? ''),
                'fila' => (string) ($row['fila'] ?? ''),
                'status_devedor' => (string) ($row['status_devedor'] ?? ''),
                'status_contrato' => (string) ($row['status_contrato'] ?? ''),
                'status_contrato_credor' => (string) ($row['status_contrato'] ?? ''),
                'data_vencimento_inicial' => $row['data_vencimento_inicial'] === null ? null : (string) $row['data_vencimento_inicial'],
                'dias_atraso' => (int) ($row['dias_atraso'] ?? 0),
                'saldo_atual' => (float) ($row['saldo_atual'] ?? 0),
                'saldo_contabil' => (float) ($row['saldo_contabil'] ?? 0),
                'valor_total_pago' => (float) ($row['valor_total_pago'] ?? 0),
                'valor_principal' => (float) ($row['valor_principal'] ?? 0),
                'valor_financiado' => (float) ($row['valor_financiado'] ?? 0),
                'valor_contratado' => (float) ($row['valor_contratado'] ?? 0),
                'pessoa_nome' => (string) $row['pessoa_nome'],
                'pessoa_documento' => preg_replace('/\D+/', '', (string) ($row['pessoa_documento'] ?? '')) ?? '',
                'contatos' => $row['contatos'] === null ? [] : array_values(array_filter(explode(',', (string) $row['contatos']))),
                'telefone_principal' => (string) ($row['telefone_principal'] ?? ''),
                'whatsapp_principal' => (string) ($row['whatsapp_principal'] ?? ''),
                'email_principal' => (string) ($row['email_principal'] ?? ''),
                'cidade' => (string) ($row['cidade'] ?? ''),
                'uf' => (string) ($row['uf'] ?? ''),
                'credor_codigo' => (string) ($row['credor_codigo'] ?? ''),
                'credor_nome' => (string) ($row['credor_nome'] ?? ''),
                'carteira_codigo' => (string) ($row['carteira_codigo'] ?? ''),
                'carteira_nome' => (string) ($row['carteira_nome'] ?? ''),
                'tem_acordo_ativo' => $temAcordoAtivo,
                'situacao_acordo' => $temAcordoAtivo ? 'Com acordo ativo' : 'Sem acordo ativo',
                'acordo_ativo_id' => $temAcordoAtivo ? (int) $row['acordo_ativo_id'] : null,
                'acordo_ativo_status' => $temAcordoAtivo ? (string) $row['acordo_ativo_status'] : '',
                'ultimo_acordo_id' => $row['ultimo_acordo_id'] === null ? null : (int) $row['ultimo_acordo_id'],
                'ultimo_acordo_status' => (string) ($row['ultimo_acordo_status'] ?? ''),
                'ultimo_acordo_em' => $row['ultimo_acordo_em'] === null ? null : (string) $row['ultimo_acordo_em'],
                'responsavel_negociacao_grupo' => $responsavelGrupo,
                'parcelas_total' => (int) ($row['parcelas_total'] ?? 0),
                'parcelas_abertas' => (int) ($row['parcelas_abertas'] ?? 0),
                'parcelas_vencidas' => (int) ($row['parcelas_vencidas'] ?? 0),
                'parcelas_liquidadas' => (int) ($row['parcelas_liquidadas'] ?? 0),
                'proximo_vencimento_parcela' => $row['proximo_vencimento_parcela'] === null ? null : (string) $row['proximo_vencimento_parcela'],
                'total_acionamentos' => (int) ($row['total_acionamentos'] ?? 0),
                'ultimo_acionamento_em' => $row['ultimo_acionamento_em'] === null ? null : (string) $row['ultimo_acionamento_em'],
                'ultimo_resultado' => (string) ($row['ultimo_resultado'] ?? ''),
                'ultimo_canal' => (string) ($row['ultimo_canal'] ?? ''),
                'disponivel_negociacao' => $disponivelNegociacao,
                'atualizado_em' => (string) $row['atualizado_em'],
            ];
        }
        return $documents;
    }

    private function ensureOperationalAgreementMapping(): void
    {
        $response = $this->http->request(
            'PUT',
            $this->baseUrl . '/' . rawurlencode($this->index) . '/_mapping',
            [
                'properties' => [
                    'status_contrato_credor' => ['type' => 'keyword'],
                    'tem_acordo_ativo' => ['type' => 'boolean'],
                    'situacao_acordo' => ['type' => 'keyword'],
                    'acordo_ativo_id' => ['type' => 'long'],
                    'acordo_ativo_status' => ['type' => 'keyword'],
                    'subproduto' => ['type' => 'keyword'],
                    'lote' => ['type' => 'keyword'],
                    'fila' => ['type' => 'keyword'],
                    'status_devedor' => ['type' => 'keyword'],
                    'data_vencimento_inicial' => ['type' => 'date', 'format' => 'yyyy-MM-dd||strict_date_optional_time'],
                    'dias_atraso' => ['type' => 'integer'],
                    'saldo_contabil' => ['type' => 'double'],
                    'valor_total_pago' => ['type' => 'double'],
                    'valor_principal' => ['type' => 'double'],
                    'valor_financiado' => ['type' => 'double'],
                    'valor_contratado' => ['type' => 'double'],
                    'telefone_principal' => ['type' => 'keyword'],
                    'whatsapp_principal' => ['type' => 'keyword'],
                    'email_principal' => ['type' => 'keyword'],
                    'cidade' => ['type' => 'keyword'],
                    'uf' => ['type' => 'keyword'],
                    'ultimo_acordo_id' => ['type' => 'long'],
                    'ultimo_acordo_status' => ['type' => 'keyword'],
                    'ultimo_acordo_em' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss||strict_date_optional_time'],
                    'responsavel_negociacao_grupo' => ['type' => 'keyword'],
                    'parcelas_total' => ['type' => 'integer'],
                    'parcelas_abertas' => ['type' => 'integer'],
                    'parcelas_vencidas' => ['type' => 'integer'],
                    'parcelas_liquidadas' => ['type' => 'integer'],
                    'proximo_vencimento_parcela' => ['type' => 'date', 'format' => 'yyyy-MM-dd||strict_date_optional_time'],
                    'total_acionamentos' => ['type' => 'integer'],
                    'ultimo_acionamento_em' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss||strict_date_optional_time'],
                    'ultimo_resultado' => ['type' => 'keyword'],
                    'ultimo_canal' => ['type' => 'keyword'],
                    'disponivel_negociacao' => ['type' => 'boolean'],
                ],
            ],
            60
        );
        $this->http->requireSuccess($response, 'Atualizacao do mapeamento de acordos no OpenSearch');
    }

    /** @param array<int,array<string,mixed>> $documents */
    private function bulkIndex(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $lines = [];
        foreach ($documents as $id => $document) {
            $lines[] = json_encode(['index' => ['_index' => $this->index, '_id' => (string) $id]], JSON_THROW_ON_ERROR);
            $lines[] = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $payload = implode("\n", $lines) . "\n";
        $response = $this->http->request('POST', $this->baseUrl . '/_bulk', $payload, 120, ['Content-Type: application/x-ndjson']);
        $this->http->requireSuccess($response, 'Carga em lote no OpenSearch');
        if (($response['json']['errors'] ?? false) === true) {
            throw new RuntimeException('OpenSearch informou erro em um ou mais documentos do lote.');
        }
    }
}
