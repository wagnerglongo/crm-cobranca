<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Config\Database;
use CRM\Infrastructure\Analytics\ClickHouseEventStore;
use CRM\Infrastructure\Cdc\CdcWorkerSupport;
use CRM\Infrastructure\Http\JsonHttpClient;
use CRM\Infrastructure\Redis\RedisConnections;
use CRM\Infrastructure\Search\OpenSearchContratoProjection;
use DateTimeImmutable;
use Throwable;

final class IntegracaoSaudeService
{
    /** @return array{pending:int,dead:int,mysql_disconnects:int,auto_recoverable:int,eventos:list<array<string,mixed>>} */
    public function filaCdc(int $limite = 12): array
    {
        $queue = RedisConnections::queue();
        $limite = max(1, min(100, $limite));
        $pending = (int) $queue->lLen('crm:cdc:retry');
        $dead = (int) $queue->lLen('crm:cdc:dead');
        $rawItems = $dead > 0 ? $queue->lRange('crm:cdc:dead', -$limite, -1) : [];
        $allDeadItems = $dead > 0 ? $queue->lRange('crm:cdc:dead', 0, -1) : [];
        $eventos = [];
        $mysqlDisconnects = 0;
        $autoRecoverable = 0;

        foreach (is_array($allDeadItems) ? $allDeadItems : [] as $rawDead) {
            $jobDead = json_decode((string) $rawDead, true);
            if (is_array($jobDead) && CdcWorkerSupport::isAutomaticallyRecoverableJob($jobDead)) {
                $autoRecoverable++;
            }
        }

        foreach (array_reverse(is_array($rawItems) ? $rawItems : []) as $raw) {
            $job = json_decode((string) $raw, true);
            if (!is_array($job)) {
                $eventos[] = [
                    'topic' => 'registro inválido',
                    'attempts' => 0,
                    'last_error' => 'O item da fila não contém um JSON válido.',
                    'last_attempt_at' => null,
                ];
                continue;
            }

            $lastError = (string) ($job['last_error'] ?? 'Falha não informada.');
            $lastErrorLower = strtolower($lastError);
            if (str_contains($lastErrorLower, 'mysql server has gone away')
                || str_contains($lastErrorLower, 'lost connection to mysql server')
                || preg_match('/(?:^|\D)(2006|2013)(?:\D|$)/', $lastErrorLower) === 1
            ) {
                $mysqlDisconnects++;
            }

            $eventos[] = [
                'topic' => (string) ($job['topic'] ?? '-'),
                'attempts' => (int) ($job['attempts'] ?? 0),
                'last_error' => mb_substr($lastError, 0, 500),
                'last_attempt_at' => $job['last_attempt_at'] ?? $job['queued_at'] ?? null,
            ];
        }

        return ['pending' => $pending, 'dead' => $dead, 'mysql_disconnects' => $mysqlDisconnects, 'auto_recoverable' => $autoRecoverable, 'eventos' => $eventos];
    }

    /** @return array{movidos:int,ignorados:int} */
    public function reprocessarFalhasCdc(int $limite = 100): array
    {
        $queue = RedisConnections::queue();
        $totalInicial = (int) $queue->lLen('crm:cdc:dead');
        $limite = max(1, min(1000, $limite));
        $quantidade = min($totalInicial, $limite);
        $movidos = 0;
        $ignorados = 0;

        for ($i = 0; $i < $quantidade; $i++) {
            $raw = $queue->lPop('crm:cdc:dead');
            if (!is_string($raw) || $raw === '') {
                break;
            }

            $job = json_decode($raw, true);
            if (!is_array($job) || !is_array($job['event'] ?? null)) {
                $queue->rPush('crm:cdc:dead', $raw);
                $ignorados++;
                continue;
            }

            try {
                $job['previous_error'] = (string) ($job['last_error'] ?? '');
                $previousErrorLower = strtolower($job['previous_error']);
                if (!array_key_exists('analytics_done', $job)
                    && (str_contains($previousErrorLower, 'mysql server has gone away')
                        || str_contains($previousErrorLower, 'lost connection to mysql server')
                        || preg_match('/(?:^|\D)(2006|2013)(?:\D|$)/', $previousErrorLower) === 1)
                ) {
                    $job['analytics_done'] = true;
                }
                $job['attempts'] = 0;
                $job['last_error'] = null;
                $job['requeued_at'] = date(DATE_ATOM);
                $queue->rPush(
                    'crm:cdc:retry',
                    json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
                $movidos++;
            } catch (Throwable $e) {
                $queue->lPush('crm:cdc:dead', $raw);
                throw $e;
            }
        }

        return compact('movidos', 'ignorados');
    }

    /** @return list<array{codigo:string,nome:string,ok:bool,resumo:string,detalhe:string,metrica:string}> */
    public function infraestrutura(): array
    {
        $items = [];

        $server = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Apache + PHP-FPM'));
        $items[] = $this->infraItem(
            'aplicacao',
            'Aplicação PHP',
            true,
            'Interface web e PHP-FPM respondendo.',
            ($server !== '' ? $server . '; ' : '') . 'PHP ' . PHP_VERSION . '.',
            'online'
        );

        try {
            $pdo = Database::pdo();
            $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $migrations = (int) $pdo->query('SELECT COUNT(*) FROM crm_schema_migrations')->fetchColumn();
            $items[] = $this->infraItem(
                'mysql',
                'MySQL',
                true,
                'Banco transacional principal do CRM.',
                'Database: ' . $database . '; servidor ' . $version . '.',
                number_format($migrations, 0, ',', '.') . ' migrations'
            );
        } catch (Throwable $e) {
            $items[] = $this->infraError('mysql', 'MySQL', $e);
        }

        try {
            $redis = RedisConnections::cache();
            $redis->ping();
            $cdc = $redis->hGetAll('crm:infra:cdc');
            $items[] = $this->infraItem(
                'redis-cache',
                'Redis Cache',
                true,
                'Sessões e cache técnico ativos.',
                isset($cdc['last_processed_at']) ? 'Último CDC: ' . $cdc['last_processed_at'] : 'Aguardando o primeiro evento CDC.',
                number_format((int) ($cdc['processed_total'] ?? 0), 0, ',', '.') . ' eventos processados'
            );
        } catch (Throwable $e) {
            $items[] = $this->infraError('redis-cache', 'Redis Cache', $e);
        }

        try {
            $queue = RedisConnections::queue();
            $queue->ping();
            $pending = (int) $queue->lLen('crm:cdc:retry');
            $dead = (int) $queue->lLen('crm:cdc:dead');
            $items[] = $this->infraItem(
                'redis-queue',
                'Redis Queue',
                $dead === 0,
                'Fila de reprocessamento do CDC acessível.',
                $dead === 0 ? 'Nenhum evento na fila de falhas definitivas.' : $dead . ' evento(s) exigem análise.',
                $pending . ' pendente(s)'
            );
        } catch (Throwable $e) {
            $items[] = $this->infraError('redis-queue', 'Redis Queue', $e);
        }

        $items[] = $this->workerHeartbeat(
            'cdc-worker',
            'CDC Worker',
            'crm:infra:cdc_worker_heartbeat',
            'Consome eventos do Redpanda e atualiza ClickHouse/OpenSearch.'
        );
        $items[] = $this->workerHeartbeat(
            'cdc-retry-worker',
            'CDC Retry Worker',
            'crm:infra:cdc_retry_worker_heartbeat',
            'Reprocessa eventos temporariamente rejeitados.'
        );
        $items[] = $this->workerHeartbeat(
            'integration-worker',
            'Integration Worker',
            'crm:infra:integration_worker_heartbeat',
            'Entrega acionamentos e demais eventos aos credores sem bloquear o Pactua.',
            '/var/www/storage/runtime/integration-worker-heartbeat.json'
        );

        try {
            $search = (new OpenSearchContratoProjection(Database::pdo()))->status();
            $items[] = $this->infraItem(
                'opensearch',
                'OpenSearch',
                $search['ok'],
                'Índice de pesquisa de pessoas e contratos.',
                'Índice: ' . $search['index'],
                number_format($search['documents'], 0, ',', '.') . ' documentos'
            );
        } catch (Throwable $e) {
            $items[] = $this->infraError('opensearch', 'OpenSearch', $e);
        }

        $http = new JsonHttpClient();
        try {
            $dashboardsUrl = rtrim((string) Database::env(
                'OPENSEARCH_DASHBOARDS_INTERNAL_URL',
                'http://opensearch-dashboards:5601'
            ), '/');
            $response = $http->request('GET', $dashboardsUrl . '/api/status', null, 3);
            $overall = is_array($response['json']['status']['overall'] ?? null)
                ? $response['json']['status']['overall']
                : [];
            $level = strtolower((string) ($overall['level'] ?? $overall['state'] ?? ''));
            $ok = $response['status'] === 200
                && ($level === '' || in_array($level, ['available', 'green'], true));
            $version = (string) ($response['json']['version']['number'] ?? '');
            $detail = trim((string) ($overall['summary'] ?? ''));
            if ($detail === '') {
                $detail = $ok
                    ? 'Interface HTTP pronta para acesso.'
                    : 'Interface respondeu, mas ainda não ficou disponível.';
            }
            $items[] = $this->infraItem(
                'opensearch-dashboards',
                'OpenSearch Dashboards',
                $ok,
                'Interface visual de pesquisa e observabilidade.',
                $detail,
                $ok ? ($version !== '' ? 'v' . $version : 'online') : 'HTTP ' . $response['status']
            );
        } catch (Throwable $e) {
            $items[] = $this->infraError('opensearch-dashboards', 'OpenSearch Dashboards', $e);
        }

        try {
            $analytics = (new ClickHouseEventStore())->status();
            $items[] = $this->infraItem(
                'clickhouse',
                'ClickHouse',
                $analytics['ok'],
                'Camada analítica recebendo eventos operacionais.',
                $analytics['events'] > 0 ? 'Tabela cdc_eventos disponível.' : 'Estrutura pronta; aguardando o primeiro evento.',
                number_format($analytics['events'], 0, ',', '.') . ' eventos'
            );
        } catch (Throwable $e) {
            $items[] = $this->infraError('clickhouse', 'ClickHouse', $e);
        }

        try {
            $response = $http->request('GET', 'http://redpanda:9644/v1/status/ready', null, 3);
            $ok = $response['status'] >= 200 && $response['status'] < 300;
            $items[] = $this->infraItem('redpanda', 'Redpanda', $ok, 'Barramento de eventos do CRM.', $ok ? 'Broker pronto para consumo.' : 'Broker não respondeu como pronto.', $ok ? 'online' : 'indisponível');
        } catch (Throwable $e) {
            $items[] = $this->infraError('redpanda', 'Redpanda', $e);
        }

        try {
            $response = $http->request('GET', 'http://debezium:8083/connectors/crm-mysql-connector/status', null, 3);
            $connector = (string) ($response['json']['connector']['state'] ?? 'UNKNOWN');
            $tasks = is_array($response['json']['tasks'] ?? null) ? $response['json']['tasks'] : [];
            $tasksOk = $tasks !== [];
            foreach ($tasks as $task) {
                $tasksOk = $tasksOk && (string) ($task['state'] ?? '') === 'RUNNING';
            }
            $ok = $response['status'] === 200 && $connector === 'RUNNING' && $tasksOk;
            $items[] = $this->infraItem('debezium', 'Debezium', $ok, 'Captura contínua das alterações do MySQL.', 'Connector: ' . $connector . '; tasks: ' . count($tasks) . '.', $ok ? 'capturando' : 'atenção');
        } catch (Throwable $e) {
            $items[] = $this->infraError('debezium', 'Debezium', $e);
        }

        return $items;
    }

    /** @return array{codigo:string,nome:string,ok:bool,resumo:string,detalhe:string,metrica:string} */
    private function workerHeartbeat(string $codigo, string $nome, string $key, string $resumo, ?string $fallbackFile = null): array
    {
        $heartbeatAt = '';
        $source = '';
        $redisError = null;

        try {
            $value = RedisConnections::cache()->get($key);
            $heartbeatAt = is_string($value) ? trim($value) : '';
            if ($heartbeatAt !== '') {
                $source = 'Redis';
            }
        } catch (Throwable $e) {
            $redisError = $e->getMessage();
        }

        if ($heartbeatAt === '' && $fallbackFile !== null && is_file($fallbackFile)) {
            $raw = trim((string) @file_get_contents($fallbackFile));
            if ($raw !== '') {
                $heartbeatAt = $raw;
                $source = 'arquivo local';
            } else {
                $mtime = @filemtime($fallbackFile);
                if (is_int($mtime) && $mtime > 0) {
                    $heartbeatAt = date(DATE_ATOM, $mtime);
                    $source = 'arquivo local';
                }
            }
        }

        if ($heartbeatAt !== '' && str_starts_with($heartbeatAt, '{')) {
            $decoded = json_decode($heartbeatAt, true);
            if (is_array($decoded)) {
                $heartbeatAt = (string) ($decoded['at'] ?? $decoded['timestamp'] ?? $decoded['heartbeat_at'] ?? '');
            }
        }

        $timestamp = $heartbeatAt !== '' ? strtotime($heartbeatAt) : false;
        $age = $timestamp === false ? null : max(0, time() - $timestamp);
        $ok = $age !== null && $age <= 180;

        if ($age === null) {
            $detail = 'Heartbeat ainda não registrado.';
            if ($redisError !== null) {
                $detail .= ' Redis: ' . $redisError;
            }
        } else {
            $detail = 'Último heartbeat há ' . $age . ' segundo(s)';
            if ($source !== '') {
                $detail .= ' via ' . $source;
            }
            $detail .= '.';
            if ($redisError !== null && $source !== 'Redis') {
                $detail .= ' Redis indisponível para observabilidade, usando fallback local.';
            }
        }

        return $this->infraItem(
            $codigo,
            $nome,
            $ok,
            $resumo,
            $detail,
            $ok ? 'ativo' : 'sem heartbeat'
        );
    }

    /** @return array<string,mixed> */
    public function acme(): array
    {
        $baseUrl = rtrim((string) Database::env('ACME_API_BASE_URL', 'http://credor-simulado:8080'), '/');
        $timeout = max(3, min(120, (int) Database::env('ACME_API_TIMEOUT', '45')));
        $usernameConfigured = trim((string) Database::env('ACME_API_USERNAME', '')) !== '';
        $passwordConfigured = trim((string) Database::env('ACME_API_PASSWORD', '')) !== '';
        $cache = $this->tokenCache();
        $logs = $this->lerLogIntegracao('acme', 250);

        $ultimo = $logs[0] ?? null;
        $ultimoSucesso = null;
        $ultimaFalha = null;
        $falhas24h = 0;
        $latencias = [];
        $corte24h = (new DateTimeImmutable('-24 hours'))->getTimestamp();

        foreach ($logs as $item) {
            $status = (string) ($item['resultado'] ?? '');
            $ts = isset($item['timestamp']) ? strtotime((string) $item['timestamp']) : false;
            if ($status === 'ok' && $ultimoSucesso === null) {
                $ultimoSucesso = $item;
            }
            if ($status === 'erro' && $ultimaFalha === null) {
                $ultimaFalha = $item;
            }
            if ($status === 'erro' && $ts !== false && $ts >= $corte24h) {
                $falhas24h++;
            }
            if (isset($item['duracao_ms']) && is_numeric($item['duracao_ms'])) {
                $latencias[] = (int) $item['duracao_ms'];
            }
        }

        $latenciaMedia = $latencias === [] ? null : (int) round(array_sum($latencias) / count($latencias));

        return [
            'codigo' => 'ACME',
            'nome' => 'ACME',
            'configurado' => $usernameConfigured && $passwordConfigured && $baseUrl !== '',
            'base_url' => $baseUrl,
            'timeout_segundos' => $timeout,
            'token_cache' => $cache,
            'ultimo' => $ultimo,
            'ultimo_sucesso' => $ultimoSucesso,
            'ultima_falha' => $ultimaFalha,
            'falhas_24h' => $falhas24h,
            'latencia_media_ms' => $latenciaMedia,
            'logs' => array_slice($logs, 0, 30),
        ];
    }

    /** @return array<string,mixed> */
    private function tokenCache(): array
    {
        $candidates = [
            dirname(__DIR__, 5) . '/storage/cache/acme_api_token.json',
            dirname(__DIR__, 4) . '/storage/cache/acme_api_token.json',
        ];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }

            try {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $expiresAt = (int) ($decoded['expires_at'] ?? 0);
                return [
                    'existe' => true,
                    'valido' => $expiresAt > time() + 30,
                    'expira_em' => $expiresAt > 0 ? date('Y-m-d H:i:s', $expiresAt) : null,
                    'arquivo' => basename($file),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return ['existe' => false, 'valido' => false, 'expira_em' => null, 'arquivo' => null];
    }

    /** @return list<array<string,mixed>> */
    private function lerLogIntegracao(string $codigo, int $limite): array
    {
        $codigo = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $codigo) ?? '');
        $candidates = [
            dirname(__DIR__, 5) . '/storage/logs/integracoes_' . $codigo . '.jsonl',
            dirname(__DIR__, 4) . '/storage/logs/integracoes_' . $codigo . '.jsonl',
        ];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }

            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }
            $lines = array_slice(array_reverse($lines), 0, max(1, $limite));
            $ret = [];
            foreach ($lines as $line) {
                $decoded = json_decode((string) $line, true);
                if (is_array($decoded)) {
                    $ret[] = $decoded;
                }
            }
            return $ret;
        }

        return [];
    }

    /** @return array{codigo:string,nome:string,ok:bool,resumo:string,detalhe:string,metrica:string} */
    private function infraItem(string $codigo, string $nome, bool $ok, string $resumo, string $detalhe, string $metrica): array
    {
        return compact('codigo', 'nome', 'ok', 'resumo', 'detalhe', 'metrica');
    }

    /** @return array{codigo:string,nome:string,ok:bool,resumo:string,detalhe:string,metrica:string} */
    private function infraError(string $codigo, string $nome, Throwable $e): array
    {
        return $this->infraItem($codigo, $nome, false, 'Integração indisponível.', mb_substr($e->getMessage(), 0, 240), 'erro');
    }
}
