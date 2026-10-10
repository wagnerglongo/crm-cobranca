<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Health;

use CRM\Config\Database;
use CRM\Infrastructure\Redis\RedisConnection;
use PDO;
use Throwable;

final class InfrastructureHealthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function verificarTudo(): array
    {
        return [
            $this->mysql(),
            $this->redisCache(),
            $this->redisQueue(),
            $this->redpanda(),
            $this->debezium(),
            $this->clickHouse(),
            $this->openSearch(),
        ];
    }

    /** @return array<string,mixed> */
    private function mysql(): array
    {
        return $this->medir('MySQL', 'Verdade operacional', 'em_uso', function (): array {
            $value = $this->pdo->query('SELECT 1')->fetchColumn();
            return ['ok' => (string) $value === '1', 'status' => 'online', 'detalhe' => 'Banco transacional oficial do Pactua.'];
        });
    }

    /** @return array<string,mixed> */
    private function redisCache(): array
    {
        return $this->medir('Redis Cache', 'Cache compartilhado', 'em_uso', function (): array {
            $redis = RedisConnection::cache();
            if ($redis === null) {
                return ['ok' => false, 'status' => 'indisponivel', 'detalhe' => 'Extensão/conexão Redis não disponível.'];
            }
            $pong = $redis->ping();
            return ['ok' => $pong === true || $pong === '+PONG', 'status' => 'online', 'detalhe' => 'Usado por configurações de credor e cache de token ACME, com fallback seguro.'];
        });
    }

    /** @return array<string,mixed> */
    private function redisQueue(): array
    {
        return $this->medir('Redis Queue', 'Fila de jobs', 'preparado', function (): array {
            $redis = RedisConnection::queue();
            if ($redis === null) {
                return ['ok' => false, 'status' => 'indisponivel', 'detalhe' => 'Serviço preparado, ainda sem worker obrigatório do Call Center.'];
            }
            $pong = $redis->ping();
            return ['ok' => $pong === true || $pong === '+PONG', 'status' => 'online', 'detalhe' => 'Infraestrutura disponível; ainda não é dependência funcional da operação.'];
        });
    }

    /** @return array<string,mixed> */
    private function redpanda(): array
    {
        $host = (string) Database::env('REDPANDA_ADMIN_HOST', 'redpanda');
        $port = (int) Database::env('REDPANDA_ADMIN_INTERNAL_PORT', '9644');
        return $this->medir('Redpanda', 'Barramento Kafka', 'cdc_sem_consumidor', function () use ($host, $port): array {
            [$status, $raw, $error] = $this->http('http://' . $host . ':' . $port . '/v1/status/ready', 2.5);
            $ok = $status >= 200 && $status < 300;
            return [
                'ok' => $ok,
                'status' => $ok ? 'online' : 'erro',
                'detalhe' => $ok ? 'Broker disponível para CDC/eventos; ainda não há consumer de domínio obrigatório.' : ($error !== '' ? $error : trim($raw)),
            ];
        });
    }

    /** @return array<string,mixed> */
    private function debezium(): array
    {
        $host = (string) Database::env('DEBEZIUM_HOST', 'debezium');
        $port = (int) Database::env('DEBEZIUM_PORT', '8083');
        return $this->medir('Debezium', 'CDC MySQL -> Redpanda', 'pipeline', function () use ($host, $port): array {
            [$status, $raw, $error] = $this->http('http://' . $host . ':' . $port . '/connectors/crm-mysql-connector/status', 3.0);
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'status' => 'erro', 'detalhe' => $error !== '' ? $error : ('HTTP ' . $status . ' ' . trim($raw))];
            }
            $json = json_decode($raw, true);
            $connectorState = strtoupper((string) ($json['connector']['state'] ?? 'DESCONHECIDO'));
            $tasks = is_array($json['tasks'] ?? null) ? $json['tasks'] : [];
            $taskStates = [];
            foreach ($tasks as $task) {
                if (is_array($task)) {
                    $taskStates[] = strtoupper((string) ($task['state'] ?? 'DESCONHECIDO'));
                }
            }
            $ok = $connectorState === 'RUNNING' && ($taskStates === [] || !in_array('FAILED', $taskStates, true));
            return [
                'ok' => $ok,
                'status' => $connectorState,
                'detalhe' => 'Connector crm-mysql-connector; tasks: ' . ($taskStates === [] ? 'nenhuma' : implode(', ', $taskStates)) . '.',
            ];
        });
    }

    /** @return array<string,mixed> */
    private function clickHouse(): array
    {
        $host = (string) Database::env('CLICKHOUSE_HOST', 'clickhouse');
        $port = (int) Database::env('CLICKHOUSE_HTTP_PORT', '8123');
        $user = (string) Database::env('CLICKHOUSE_USER', 'crm_app');
        $password = (string) Database::env('CLICKHOUSE_PASSWORD', '');
        $headers = ['X-ClickHouse-User: ' . $user];
        if ($password !== '') {
            $headers[] = 'X-ClickHouse-Key: ' . $password;
        }
        return $this->medir('ClickHouse', 'Analytics', 'preparado', function () use ($host, $port, $headers): array {
            [$status, $raw, $error] = $this->http('http://' . $host . ':' . $port . '/?query=SELECT%201', 3.0, $headers);
            $ok = $status >= 200 && $status < 300 && trim($raw) === '1';
            return [
                'ok' => $ok,
                'status' => $ok ? 'online' : 'erro',
                'detalhe' => $ok ? 'Servidor disponível; relatórios atuais ainda consultam MySQL.' : ($error !== '' ? $error : trim($raw)),
            ];
        });
    }

    /** @return array<string,mixed> */
    private function openSearch(): array
    {
        $base = rtrim((string) Database::env('OPENSEARCH_HOST', 'http://opensearch:9200'), '/');
        return $this->medir('OpenSearch', 'Busca indexada', 'preparado', function () use ($base): array {
            [$status, $raw, $error] = $this->http($base . '/_cluster/health', 3.0);
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'status' => 'erro', 'detalhe' => $error !== '' ? $error : trim($raw)];
            }
            $json = json_decode($raw, true);
            $clusterStatus = strtolower((string) ($json['status'] ?? 'desconhecido'));
            $ok = in_array($clusterStatus, ['green', 'yellow'], true);
            return [
                'ok' => $ok,
                'status' => $clusterStatus,
                'detalhe' => 'Cluster disponível; busca do Call Center ainda usa MySQL até o indexador ser ativado.',
            ];
        });
    }

    /** @param callable():array{ok:bool,status:string,detalhe:string} $callback @return array<string,mixed> */
    private function medir(string $servico, string $papel, string $uso, callable $callback): array
    {
        $started = microtime(true);
        try {
            $result = $callback();
            return [
                'servico' => $servico,
                'papel' => $papel,
                'uso' => $uso,
                'ok' => (bool) ($result['ok'] ?? false),
                'status' => (string) ($result['status'] ?? 'desconhecido'),
                'detalhe' => (string) ($result['detalhe'] ?? ''),
                'latencia_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $e) {
            return [
                'servico' => $servico,
                'papel' => $papel,
                'uso' => $uso,
                'ok' => false,
                'status' => 'erro',
                'detalhe' => $e->getMessage(),
                'latencia_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    /** @param list<string> $headers @return array{0:int,1:string,2:string} */
    private function http(string $url, float $timeout, array $headers = []): array
    {
        $headerLines = array_merge(['Accept: application/json'], $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
        $error = '';
        if ($raw === false) {
            $last = error_get_last();
            $error = trim((string) ($last['message'] ?? 'Falha de comunicação.'));
            $raw = '';
        }
        return [$status, (string) $raw, $error];
    }
}
