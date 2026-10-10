<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Cdc;

use CRM\Config\Database;
use CRM\Infrastructure\Analytics\ClickHouseEventStore;
use CRM\Infrastructure\Redis\RedisConnections;
use CRM\Infrastructure\Search\OpenSearchContratoProjection;
use CRM\Infrastructure\Search\OpenSearchOperationalProjection;
use PDO;

final class CdcProcessor
{
    private readonly OpenSearchContratoProjection $search;
    private readonly OpenSearchOperationalProjection $operational;
    private readonly ClickHouseEventStore $analytics;

    public function __construct(private readonly PDO $pdo)
    {
        $this->search = new OpenSearchContratoProjection($pdo);
        $this->operational = new OpenSearchOperationalProjection($pdo);
        $this->analytics = new ClickHouseEventStore();
    }

    public function initialize(): void
    {
        $this->search->ensureIndex();
        $this->operational->ensureIndices();
        $this->analytics->ensureSchema();
    }

    /** @param array<string,mixed> $event */
    public function process(string $topic, int $partition, int $offset, array $event): void
    {
        if (!$this->eventAllowed($topic, $event)) {
            return;
        }

        // ClickHouse recebe apenas dimensoes/metricas selecionadas e o hash do
        // evento. O payload completo, senhas e tokens nunca sao replicados.
        $this->analytics->append($topic, $partition, $offset, $event);
        $this->processProjection($topic, $partition, $offset, $event);
    }

    /**
     * @param list<array{topic:string,partition:int,offset:int,event:array<string,mixed>}> $items
     */
    public function appendAnalyticsBatch(array $items): void
    {
        $allowed = [];
        foreach ($items as $item) {
            if ($this->eventAllowed($item['topic'], $item['event'])) {
                $allowed[] = $item;
            }
        }
        $this->analytics->appendBatch($allowed);
    }

    /** @param array<string,mixed> $event */
    public function processProjection(string $topic, int $partition, int $offset, array $event): void
    {
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $table = (string) ($source['table'] ?? $this->tableFromTopic($topic));
        $after = is_array($event['after'] ?? null) ? $event['after'] : [];
        $before = is_array($event['before'] ?? null) ? $event['before'] : [];
        $row = $after !== [] ? $after : $before;

        if (!$this->tableAllowed($table)) {
            return;
        }

        if ($table === 'contratos') {
            $contractId = (int) ($row['id'] ?? 0);
            if ($contractId > 0) {
                if (($event['op'] ?? '') === 'd') {
                    $this->search->deleteContract($contractId);
                } else {
                    $this->search->indexContract($contractId);
                    $this->operational->reindexContract($contractId);
                }
            }
            $this->reindexPeopleFromRows($before, $after);
        } elseif ($table === 'pessoas') {
            $personId = (int) ($row['id'] ?? 0);
            if ($personId > 0) {
                if (($event['op'] ?? '') === 'd') {
                    $this->operational->deletePerson($personId);
                } else {
                    $this->search->reindexPerson($personId);
                    $this->operational->reindexPerson($personId);
                }
            }
        } elseif (in_array($table, ['pessoa_contatos', 'pessoa_enderecos'], true)) {
            $personId = (int) ($row['pessoa_id'] ?? 0);
            if ($personId > 0) {
                $this->search->reindexPerson($personId);
                $this->operational->indexPerson($personId);
            }
        } elseif ($table === 'contrato_parcelas') {
            $contractIds = $this->idsFromRows($before, $after, 'contrato_id');
            foreach ($contractIds as $contractId) {
                $this->search->indexContract($contractId);
                $this->operational->reindexContract($contractId);
            }
        } elseif ($table === 'acordos_operacionais') {
            $contractIds = array_values(array_unique(array_filter([
                (int) ($before['contrato_id'] ?? 0),
                (int) ($after['contrato_id'] ?? 0),
            ], static fn (int $id): bool => $id > 0)));
            foreach ($contractIds as $contractId) {
                $this->search->indexContract($contractId);
            }
            $agreementId = (int) ($row['id'] ?? 0);
            if ($agreementId > 0) {
                if (($event['op'] ?? '') === 'd') {
                    $this->operational->deleteAgreement($agreementId);
                    $this->operational->deleteInstallmentsByAgreement($agreementId);
                } else {
                    $this->operational->reindexAgreement($agreementId);
                }
            }
            $this->reindexPeopleFromRows($before, $after);
        } elseif ($table === 'acordos_operacionais_parcelas') {
            $installmentId = (int) ($row['id'] ?? 0);
            if ($installmentId > 0 && ($event['op'] ?? '') === 'd') {
                $this->operational->deleteInstallment($installmentId);
            }
            $agreementIds = $this->idsFromRows($before, $after, 'acordo_operacional_id');
            foreach ($agreementIds as $agreementId) {
                $this->operational->reindexAgreement($agreementId);
                $contractId = $this->relatedId('SELECT contrato_id FROM acordos_operacionais WHERE id = :id LIMIT 1', $agreementId);
                if ($contractId !== null) {
                    $this->search->indexContract($contractId);
                }
            }
        } elseif ($table === 'acordos_operacionais_acompanhamento') {
            $agreementId = (int) ($row['acordo_operacional_id'] ?? 0);
            if ($agreementId > 0) {
                $this->operational->reindexAgreement($agreementId);
            }
        } elseif ($table === 'atendimentos_historico') {
            $actionId = (int) ($row['id'] ?? 0);
            if ($actionId > 0) {
                if (($event['op'] ?? '') === 'd') {
                    $this->operational->deleteAction($actionId);
                } else {
                    $this->operational->indexAction($actionId);
                }
                $this->operational->reindexStrategyResultsByAction($actionId);
            }
            foreach ($this->idsFromRows($before, $after, 'pessoa_id') as $personId) {
                $this->operational->indexPerson($personId);
            }
            foreach ($this->idsFromRows($before, $after, 'contrato_id') as $contractId) {
                $this->search->indexContract($contractId);
            }
        } elseif ($table === 'atendimento_resultados') {
            $resultId = (int) ($row['id'] ?? 0);
            if ($resultId > 0) {
                $this->operational->reindexActionsByResult($resultId);
            }
        } elseif ($table === 'campanha_itens') {
            $itemId = (int) ($row['id'] ?? 0);
            if ($itemId > 0) {
                $this->operational->reindexActionsByCampaignItem($itemId);
            }
        } elseif ($table === 'campanhas') {
            $campaignId = (int) ($row['id'] ?? 0);
            if ($campaignId > 0) {
                $this->operational->reindexActionsByCampaign($campaignId);
            }
        } elseif ($table === 'estrategias') {
            $strategyId = (int) ($row['id'] ?? 0);
            if ($strategyId > 0) {
                if (($event['op'] ?? '') === 'd') {
                    $this->operational->deleteStrategyResultsByStrategy($strategyId);
                } else {
                    $this->operational->reindexStrategy($strategyId);
                }
            }
        } elseif ($table === 'estrategia_contratos') {
            $strategyContractId = (int) ($row['id'] ?? 0);
            if ($strategyContractId > 0) {
                if (($event['op'] ?? '') === 'd') {
                    $this->operational->deleteStrategyResult($strategyContractId);
                } else {
                    $this->operational->indexStrategyResult($strategyContractId);
                }
            }
        } elseif ($table === 'usuarios') {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId > 0) {
                $this->operational->reindexByUser($userId);
            }
        } elseif ($table === 'acordo_regras_carencia') {
            foreach ($this->idsFromRows($before, $after, 'credor_id') as $creditorId) {
                $this->operational->reindexPreventiveByCreditor($creditorId);
            }
        }

        try {
            $cache = RedisConnections::cache();
            $cache->hMSet('crm:infra:cdc', [
                'last_topic' => $topic,
                'last_table' => $table,
                'last_offset' => (string) $offset,
                'last_processed_at' => date(DATE_ATOM),
            ]);
            $cache->hIncrBy('crm:infra:cdc', 'processed_total', 1);
            $cache->expire('crm:infra:cdc', 86400 * 30);
        } catch (\Throwable) {
            // Metricas de cache nao podem invalidar um evento ja processado.
        }
    }

    public function search(): OpenSearchContratoProjection
    {
        return $this->search;
    }

    public function analytics(): ClickHouseEventStore
    {
        return $this->analytics;
    }

    public function operational(): OpenSearchOperationalProjection
    {
        return $this->operational;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function reindexPeopleFromRows(array $before, array $after): void
    {
        foreach ($this->idsFromRows($before, $after, 'pessoa_id') as $personId) {
            $this->operational->indexPerson($personId);
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<int> */
    private function idsFromRows(array $before, array $after, string $field): array
    {
        return array_values(array_unique(array_filter([
            (int) ($before[$field] ?? 0),
            (int) ($after[$field] ?? 0),
        ], static fn (int $id): bool => $id > 0)));
    }

    private function relatedId(string $sql, int $id): ?int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    private function tableFromTopic(string $topic): string
    {
        $parts = explode('.', $topic);
        return (string) end($parts);
    }

    /** @param array<string,mixed> $event */
    private function eventAllowed(string $topic, array $event): bool
    {
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $table = (string) ($source['table'] ?? $this->tableFromTopic($topic));
        return $this->tableAllowed($table);
    }

    private function tableAllowed(string $table): bool
    {
        $raw = (string) Database::env(
            'DEBEZIUM_TABLE_INCLUDE_LIST',
            'pessoas,pessoa_contatos,pessoa_enderecos,contratos,contrato_parcelas,atendimentos_historico,atendimento_resultados,campanhas,campanha_itens,estrategias,estrategia_contratos,acordos_operacionais,acordos_operacionais_parcelas,acordos_operacionais_acompanhamento,acordo_regras_carencia,usuarios,pagamentos,boletos,baixas,cargas_arquivos'
        );
        $tables = array_values(array_filter(array_map('trim', explode(',', $raw))));
        return in_array($table, $tables, true);
    }
}
