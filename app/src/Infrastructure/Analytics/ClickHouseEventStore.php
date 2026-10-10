<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Analytics;

use CRM\Config\Database;
use CRM\Infrastructure\Http\JsonHttpClient;
use InvalidArgumentException;

final class ClickHouseEventStore
{
    private readonly string $baseUrl;
    private readonly string $database;
    /** @var list<string> */
    private readonly array $headers;

    public function __construct(private readonly JsonHttpClient $http = new JsonHttpClient())
    {
        $host = (string) Database::env('CLICKHOUSE_HOST', 'clickhouse');
        $port = (int) Database::env('CLICKHOUSE_HTTP_INTERNAL_PORT', '8123');
        $this->baseUrl = str_contains($host, '://') ? rtrim($host, '/') : sprintf('http://%s:%d', $host, $port);
        $this->database = $this->identifier((string) Database::env('CLICKHOUSE_DB', 'crm_analytics_dev'));
        $this->headers = [
            'X-ClickHouse-User: ' . (string) Database::env('CLICKHOUSE_USER', 'crm_app'),
            'X-ClickHouse-Key: ' . (string) Database::env('CLICKHOUSE_PASSWORD', ''),
        ];
    }

    public function ensureSchema(): void
    {
        $createDatabase = sprintf('CREATE DATABASE IF NOT EXISTS %s', $this->database);
        $databaseResponse = $this->http->request('POST', $this->baseUrl, $createDatabase, 60, $this->headers);
        $this->http->requireSuccess($databaseResponse, 'Criacao do database ClickHouse');

        $sql = sprintf(<<<'SQL'
CREATE TABLE IF NOT EXISTS %s.cdc_eventos
(
    event_id String,
    topico String,
    tabela LowCardinality(String),
    operacao LowCardinality(String),
    evento_em DateTime64(3, 'UTC'),
    registro_id UInt64,
    pessoa_id UInt64,
    contrato_id UInt64,
    credor_id UInt64,
    status String,
    resultado String,
    canal String,
    valor Decimal(18, 2),
    payload_hash FixedString(64),
    processado_em DateTime DEFAULT now()
)
ENGINE = ReplacingMergeTree(processado_em)
PARTITION BY toYYYYMM(evento_em)
ORDER BY (tabela, event_id)
SQL, $this->database);

        $response = $this->http->request('POST', $this->baseUrl, $sql, 60, $this->headers);
        $this->http->requireSuccess($response, 'Criacao da estrutura ClickHouse');
    }

    /** @param array<string,mixed> $event */
    public function append(string $topic, int $partition, int $offset, array $event): void
    {
        $this->appendBatch([[
            'topic' => $topic,
            'partition' => $partition,
            'offset' => $offset,
            'event' => $event,
        ]]);
    }

    /**
     * @param list<array{topic:string,partition:int,offset:int,event:array<string,mixed>}> $items
     */
    public function appendBatch(array $items): void
    {
        if ($items === []) {
            return;
        }

        $lines = [];
        foreach ($items as $item) {
            $document = $this->document(
                (string) $item['topic'],
                (int) $item['partition'],
                (int) $item['offset'],
                $item['event']
            );
            $lines[] = json_encode(
                $document,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        $sql = sprintf('INSERT INTO %s.cdc_eventos FORMAT JSONEachRow', $this->database);
        $url = $this->baseUrl
            . '/?query=' . rawurlencode($sql)
            . '&max_threads=1&max_insert_threads=1';
        $payload = implode("\n", $lines) . "\n";
        $response = $this->http->request(
            'POST',
            $url,
            $payload,
            120,
            array_merge($this->headers, ['Content-Type: application/x-ndjson'])
        );
        $this->http->requireSuccess($response, 'Gravacao do lote de eventos no ClickHouse');
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function document(string $topic, int $partition, int $offset, array $event): array
    {
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $table = (string) ($source['table'] ?? $this->tableFromTopic($topic));
        $operation = (string) ($event['op'] ?? 'unknown');
        $row = is_array($event['after'] ?? null) ? $event['after'] : (is_array($event['before'] ?? null) ? $event['before'] : []);
        $timestampMs = (int) ($event['ts_ms'] ?? $source['ts_ms'] ?? (int) (microtime(true) * 1000));
        $eventId = hash('sha256', $topic . '|' . $partition . '|' . $offset);
        $status = $this->firstString($row, ['status_operacional', 'status_contrato', 'status_acordo', 'status', 'status_parcela']);
        $value = $this->firstNumeric($row, ['valor_acordo', 'valor_total_acordo', 'valor_pago', 'saldo_atual', 'saldo_parcela', 'valor_vencimento', 'valor_parcela']);

        return [
            'event_id' => $eventId,
            'topico' => $topic,
            'tabela' => $table,
            'operacao' => $operation,
            'evento_em' => $this->timestamp($timestampMs),
            'registro_id' => max(0, (int) ($row['id'] ?? 0)),
            'pessoa_id' => max(0, (int) ($row['pessoa_id'] ?? ($table === 'pessoas' ? ($row['id'] ?? 0) : 0))),
            'contrato_id' => max(0, (int) ($row['contrato_id'] ?? ($table === 'contratos' ? ($row['id'] ?? 0) : 0))),
            'credor_id' => max(0, (int) ($row['credor_id'] ?? 0)),
            'status' => $status,
            'resultado' => (string) ($row['resultado'] ?? ''),
            'canal' => (string) ($row['canal'] ?? ''),
            'valor' => number_format($value, 2, '.', ''),
            // Somente o hash e armazenado; o payload CDC pode conter dados pessoais.
            'payload_hash' => hash('sha256', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array{ok:bool,events:int,last_event:?string} */
    public function status(int $timeout = 10): array
    {
        // total_rows vem dos metadados da tabela e evita varrer/ agregar todos
        // os eventos apenas para exibir uma metrica de saude.
        $sql = sprintf(
            "SELECT ifNull(total_rows, 0) AS total FROM system.tables WHERE database = '%s' AND name = 'cdc_eventos' LIMIT 1 FORMAT JSON",
            $this->database,
        );
        $timeout = max(5, min(120, $timeout));
        $response = $this->http->request('POST', $this->baseUrl . '/?query=' . rawurlencode($sql), '', $timeout, $this->headers);
        $this->http->requireSuccess($response, 'Consulta de eventos ClickHouse');
        $data = $response['json']['data'][0] ?? null;
        return [
            'ok' => is_array($data),
            'events' => is_array($data) ? (int) ($data['total'] ?? 0) : 0,
            'last_event' => null,
        ];
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException('Identificador ClickHouse invalido.');
        }
        return $value;
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return (string) $row[$key];
            }
        }
        return '';
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function firstNumeric(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }
        return 0.0;
    }

    private function timestamp(int $milliseconds): string
    {
        $seconds = intdiv($milliseconds, 1000);
        $millis = abs($milliseconds % 1000);
        return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%03d', $millis);
    }

    private function tableFromTopic(string $topic): string
    {
        $parts = explode('.', $topic);
        return (string) end($parts);
    }
}
