<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\JsonHttpClient;
use CRM\Infrastructure\Redis\RedisConnections;

require_once __DIR__ . '/_bootstrap.php';

$attempts = 1;
$interval = 5;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--attempts=')) {
        $attempts = max(1, min(60, (int) substr($argument, 11)));
    }
    if (str_starts_with($argument, '--interval=')) {
        $interval = max(1, min(30, (int) substr($argument, 11)));
    }
}

$http = new JsonHttpClient();

/** @param array{status:int,body:string,json:mixed} $response */
$requireHttpSuccess = static function (array $response, string $service): void {
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $detail = trim((string) preg_replace('/\s+/', ' ', mb_substr($response['body'], 0, 500)));
        throw new RuntimeException(sprintf(
            '%s respondeu HTTP %d%s',
            $service,
            $response['status'],
            $detail !== '' ? ': ' . $detail : '.'
        ));
    }
};

$endpointHost = static function (string $endpoint): string {
    if (!str_contains($endpoint, '://')) {
        $endpoint = 'tcp://' . $endpoint;
    }
    $host = parse_url($endpoint, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        throw new RuntimeException('Endpoint invalido: ' . $endpoint);
    }
    return $host;
};

$clickHouseHost = (string) Database::env('CLICKHOUSE_HOST', 'clickhouse');
$clickHousePort = (int) Database::env('CLICKHOUSE_HTTP_INTERNAL_PORT', '8123');
$clickHouseUrl = str_contains($clickHouseHost, '://')
    ? rtrim($clickHouseHost, '/')
    : sprintf('http://%s:%d', $clickHouseHost, $clickHousePort);
$clickHouseDatabase = (string) Database::env('CLICKHOUSE_DB', 'crm_analytics_dev');
$openSearchUrl = rtrim((string) Database::env('OPENSEARCH_HOST', 'http://opensearch:9200'), '/');
$openSearchDashboardsUrl = rtrim((string) Database::env(
    'OPENSEARCH_DASHBOARDS_INTERNAL_URL',
    'http://opensearch-dashboards:5601'
), '/');
$debeziumUrl = rtrim((string) Database::env('DEBEZIUM_CONNECT_URL', 'http://debezium:8083'), '/');
$redpandaAdminUrl = rtrim((string) Database::env('REDPANDA_ADMIN_URL', 'http://redpanda:9644'), '/');
$kafkaBrokers = (string) Database::env('KAFKA_BROKERS', 'redpanda:9092');

/**
 * Cada verificacao retorna um detalhe seguro para exibicao.
 * "retry" fica falso apenas para erros locais que nao mudam enquanto o
 * container esta em execucao, como uma extensao PHP ausente.
 *
 * @var array<string,array{retry:bool,run:Closure():string}>
 */
$checks = [
    'Extensoes PHP' => [
        'retry' => false,
        'run' => static function (): string {
            $required = ['curl', 'mbstring', 'pdo_mysql', 'redis', 'rdkafka'];
            $missing = array_values(array_filter(
                $required,
                static fn (string $extension): bool => !extension_loaded($extension)
            ));
            if ($missing !== []) {
                throw new RuntimeException('Ausentes: ' . implode(', ', $missing));
            }
            return implode(', ', $required);
        },
    ],
    'DNS interno' => [
        'retry' => true,
        'run' => static function () use ($endpointHost, $openSearchUrl, $openSearchDashboardsUrl, $clickHouseUrl, $redpandaAdminUrl, $debeziumUrl, $kafkaBrokers): string {
            $hosts = [
                (string) Database::env('DB_HOST', 'mysql'),
                (string) Database::env('REDIS_CACHE_HOST', 'redis-cache'),
                (string) Database::env('REDIS_QUEUE_HOST', 'redis-queue'),
                $endpointHost($openSearchUrl),
                $endpointHost($openSearchDashboardsUrl),
                $endpointHost($clickHouseUrl),
                $endpointHost($redpandaAdminUrl),
                $endpointHost($debeziumUrl),
                'apache',
            ];
            foreach (explode(',', $kafkaBrokers) as $broker) {
                $broker = trim($broker);
                if ($broker !== '') {
                    $hosts[] = $endpointHost($broker);
                }
            }

            $hosts = array_values(array_unique($hosts));
            $unresolved = [];
            foreach ($hosts as $host) {
                if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    continue;
                }
                $addresses = @gethostbynamel($host);
                if ($addresses === false || $addresses === []) {
                    $unresolved[] = $host;
                }
            }
            if ($unresolved !== []) {
                throw new RuntimeException('Nao resolvidos: ' . implode(', ', $unresolved));
            }
            return count($hosts) . ' hosts resolvidos';
        },
    ],
    'Storage' => [
        'retry' => false,
        'run' => static function (): string {
            $paths = ['/var/www/storage', '/var/www/storage/cargas', '/var/www/storage/anexos', '/var/www/storage/cache'];
            $invalid = [];
            foreach ($paths as $path) {
                if (!is_dir($path) || !is_writable($path)) {
                    $invalid[] = $path;
                }
            }
            if ($invalid !== []) {
                throw new RuntimeException('Diretorios ausentes ou sem escrita: ' . implode(', ', $invalid));
            }
            return count($paths) . ' diretorios gravaveis';
        },
    ],
    'MySQL' => [
        'retry' => true,
        'run' => static function (): string {
            $statement = Database::pdo()->query(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM crm_schema_migrations) AS migrations,
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'contratos') AS core_ok
SQL);
            $result = $statement->fetch();
            if (!is_array($result) || (int) ($result['core_ok'] ?? 0) !== 1) {
                throw new RuntimeException('Tabela principal contratos nao encontrada.');
            }
            $migrations = (int) ($result['migrations'] ?? 0);
            if ($migrations < 1) {
                throw new RuntimeException('Nenhuma migration registrada em crm_schema_migrations.');
            }
            return $migrations . ' migration(s) registrada(s)';
        },
    ],
    'Schema CRM' => [
        'retry' => false,
        'run' => static function (): string {
            $required = [
                'usuarios', 'perfis_acesso', 'permissoes', 'perfil_permissoes',
                'usuario_permissoes', 'usuario_credores',
                'pessoas', 'pessoa_contatos', 'pessoa_enderecos',
                'contratos', 'contrato_parcelas',
                'atendimentos_historico', 'atendimento_resultados',
                'acordos_operacionais', 'acordos_operacionais_parcelas',
                'acordos_operacionais_acompanhamento', 'acordos_operacionais_sincronizacoes',
                'campanhas', 'campanha_itens',
                'estrategias', 'estrategia_contratos', 'estrategia_usuarios',
                'estrategia_execucoes', 'estrategia_eventos',
                'relatorios_api_tokens', 'auditoria_sistema',
            ];
            $placeholders = implode(',', array_fill(0, count($required), '?'));
            $statement = Database::pdo()->prepare(
                'SELECT table_name FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''
                . ' AND table_name IN (' . $placeholders . ')'
            );
            $statement->execute($required);
            $found = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $missing = array_values(array_diff($required, $found));
            if ($missing !== []) {
                throw new RuntimeException('Tabelas ausentes: ' . implode(', ', $missing));
            }
            return count($required) . ' tabelas funcionais presentes';
        },
    ],
    'Perfis/permissoes' => [
        'retry' => false,
        'run' => static function (): string {
            $pdo = Database::pdo();
            $requiredColumns = ['perfil_id', 'permissao_id'];
            $statement = $pdo->prepare(<<<'SQL'
SELECT column_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'perfil_permissoes'
  AND column_name IN (?, ?)
SQL);
            $statement->execute($requiredColumns);
            $foundColumns = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $missingColumns = array_values(array_diff($requiredColumns, $foundColumns));
            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Estrutura perfil_permissoes incompleta; colunas ausentes: ' . implode(', ', $missingColumns)
                );
            }

            $statement = $pdo->query(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM perfis_acesso) AS perfis,
    (SELECT COUNT(*) FROM permissoes) AS permissoes,
    (SELECT COUNT(*) FROM perfil_permissoes) AS vinculos,
    (SELECT COUNT(*)
       FROM perfil_permissoes pp
       LEFT JOIN perfis_acesso pa ON pa.id = pp.perfil_id
       LEFT JOIN permissoes pe ON pe.id = pp.permissao_id
      WHERE pa.id IS NULL OR pe.id IS NULL) AS orfaos
SQL);
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($result)) {
                throw new RuntimeException('Nao foi possivel contabilizar perfis e permissoes.');
            }
            $perfis = (int) ($result['perfis'] ?? 0);
            $permissoes = (int) ($result['permissoes'] ?? 0);
            $vinculos = (int) ($result['vinculos'] ?? 0);
            $orfaos = (int) ($result['orfaos'] ?? 0);
            if ($perfis === 0 || $permissoes === 0) {
                throw new RuntimeException(sprintf(
                    'Catalogo vazio: %d perfil(is), %d permissao(oes).',
                    $perfis,
                    $permissoes
                ));
            }
            if ($vinculos === 0) {
                throw new RuntimeException('Nenhum vinculo entre perfil e permissao foi encontrado.');
            }
            if ($orfaos > 0) {
                throw new RuntimeException($orfaos . ' vinculo(s) orfao(s) em perfil_permissoes.');
            }
            return sprintf('%d perfis, %d permissoes, %d vinculos validos', $perfis, $permissoes, $vinculos);
        },
    ],
    'Redis Cache' => [
        'retry' => true,
        'run' => static function (): string {
            $response = RedisConnections::cache()->ping();
            if ($response !== true && strtoupper(ltrim((string) $response, '+')) !== 'PONG') {
                throw new RuntimeException('PING nao retornou PONG.');
            }
            return 'autenticacao e PING OK';
        },
    ],
    'Redis Queue' => [
        'retry' => true,
        'run' => static function (): string {
            $response = RedisConnections::queue()->ping();
            if ($response !== true && strtoupper(ltrim((string) $response, '+')) !== 'PONG') {
                throw new RuntimeException('PING nao retornou PONG.');
            }
            return 'autenticacao e PING OK';
        },
    ],
    'OpenSearch' => [
        'retry' => true,
        'run' => static function () use ($http, $requireHttpSuccess, $openSearchUrl): string {
            $response = $http->request('GET', $openSearchUrl . '/_cluster/health?wait_for_status=yellow&timeout=5s', null, 10);
            $requireHttpSuccess($response, 'OpenSearch');
            $status = strtolower((string) ($response['json']['status'] ?? ''));
            if (!in_array($status, ['yellow', 'green'], true)) {
                throw new RuntimeException('Cluster ainda nao esta pronto; status=' . ($status !== '' ? $status : 'desconhecido'));
            }
            return 'cluster ' . $status;
        },
    ],
    'OpenSearch Dashboards' => [
        'retry' => true,
        'run' => static function () use ($http, $requireHttpSuccess, $openSearchDashboardsUrl): string {
            $response = $http->request('GET', $openSearchDashboardsUrl . '/api/status', null, 10);
            $requireHttpSuccess($response, 'OpenSearch Dashboards');
            $overall = is_array($response['json']['status']['overall'] ?? null)
                ? $response['json']['status']['overall']
                : [];
            $level = strtolower((string) ($overall['level'] ?? $overall['state'] ?? ''));
            if ($level !== '' && !in_array($level, ['available', 'green'], true)) {
                throw new RuntimeException('Status geral ainda nao disponivel: ' . $level . '.');
            }
            $version = (string) ($response['json']['version']['number'] ?? 'desconhecida');
            return 'interface HTTP pronta; versao ' . $version;
        },
    ],
    'ClickHouse' => [
        'retry' => true,
        'run' => static function () use ($http, $requireHttpSuccess, $clickHouseUrl, $clickHouseDatabase): string {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $clickHouseDatabase)) {
                throw new RuntimeException('Nome do database ClickHouse invalido.');
            }
            $headers = [
                'X-ClickHouse-User: ' . (string) Database::env('CLICKHOUSE_USER', 'crm_app'),
                'X-ClickHouse-Key: ' . (string) Database::env('CLICKHOUSE_PASSWORD', ''),
            ];
            $response = $http->request('GET', $clickHouseUrl . '/ping', null, 10, $headers);
            $requireHttpSuccess($response, 'ClickHouse');
            if (!str_starts_with(trim($response['body']), 'Ok.')) {
                throw new RuntimeException('Resposta inesperada ao /ping.');
            }
            // Neste ponto validamos transporte e autenticacao. O database da
            // aplicacao e criado de forma idempotente em ensureSchema(). Isso
            // tambem recupera volumes antigos, anteriores ao CLICKHOUSE_DB.
            $query = $http->request(
                'POST',
                $clickHouseUrl,
                'SELECT 1 FORMAT TabSeparated',
                10,
                $headers
            );
            $requireHttpSuccess($query, 'Consulta ClickHouse');
            if (trim($query['body']) !== '1') {
                throw new RuntimeException('SELECT 1 retornou resposta inesperada.');
            }
            return 'autenticacao e consulta HTTP OK';
        },
    ],
    'Redpanda Admin' => [
        'retry' => true,
        'run' => static function () use ($http, $requireHttpSuccess, $redpandaAdminUrl): string {
            $response = $http->request('GET', $redpandaAdminUrl . '/v1/status/ready', null, 10);
            $requireHttpSuccess($response, 'Redpanda Admin');
            return 'API pronta';
        },
    ],
    'Kafka/Redpanda' => [
        'retry' => true,
        'run' => static function () use ($kafkaBrokers): string {
            if (!class_exists(RdKafka\Producer::class)) {
                throw new RuntimeException('Classe RdKafka\\Producer indisponivel.');
            }
            $configuration = new RdKafka\Conf();
            $configuration->set('metadata.broker.list', $kafkaBrokers);
            $producer = new RdKafka\Producer($configuration);
            $metadata = $producer->getMetadata(true, null, 5000);
            $brokers = $metadata->getBrokers();
            if (count($brokers) === 0) {
                throw new RuntimeException('Broker nao retornou metadados.');
            }
            return count($brokers) . ' broker(s) acessivel(is)';
        },
    ],
    'Debezium Connector' => [
        'retry' => true,
        'run' => static function () use ($http, $requireHttpSuccess, $debeziumUrl): string {
            $response = $http->request('GET', $debeziumUrl . '/connectors/crm-mysql-connector/status', null, 10);
            $requireHttpSuccess($response, 'Debezium');
            $connectorState = strtoupper((string) ($response['json']['connector']['state'] ?? ''));
            $tasks = $response['json']['tasks'] ?? [];
            if ($connectorState !== 'RUNNING') {
                throw new RuntimeException('Connector em estado ' . ($connectorState !== '' ? $connectorState : 'desconhecido') . '.');
            }
            if (!is_array($tasks) || $tasks === []) {
                throw new RuntimeException('Connector ainda nao possui task ativa.');
            }
            $failedTasks = [];
            foreach ($tasks as $task) {
                $state = strtoupper((string) ($task['state'] ?? ''));
                if ($state !== 'RUNNING') {
                    $failedTasks[] = sprintf('%s=%s', (string) ($task['id'] ?? '?'), $state !== '' ? $state : 'desconhecido');
                }
            }
            if ($failedTasks !== []) {
                throw new RuntimeException('Tasks fora de RUNNING: ' . implode(', ', $failedTasks));
            }
            return count($tasks) . ' task(s) RUNNING';
        },
    ],
    'Apache/PHP-FPM' => [
        'retry' => true,
        'run' => static function () use ($http): string {
            $response = $http->request('GET', 'http://apache/', null, 10);
            if ($response['status'] !== 200) {
                throw new RuntimeException(sprintf('Aplicacao respondeu HTTP %d.', $response['status']));
            }
            if (($response['json']['status'] ?? null) !== 'ok') {
                throw new RuntimeException('Aplicacao nao retornou status=ok.');
            }
            return 'aplicacao HTTP ' . $response['status'];
        },
    ],
];

echo sprintf("Validacao geral da infraestrutura (%d verificacoes)...\n", count($checks));

$pending = array_keys($checks);
$failures = [];
$passed = 0;

for ($attempt = 1; $attempt <= $attempts && $pending !== []; $attempt++) {
    $retry = [];
    foreach ($pending as $name) {
        try {
            $detail = ($checks[$name]['run'])();
            printf("OK    %-20s %s\n", $name, $detail);
            $passed++;
            unset($failures[$name]);
        } catch (Throwable $error) {
            $failures[$name] = $error->getMessage();
            if ($checks[$name]['retry'] && $attempt < $attempts) {
                $retry[] = $name;
            }
        }
    }

    $pending = $retry;
    if ($pending !== [] && $attempt < $attempts) {
        printf(
            "AGUARDANDO tentativa %d/%d; pendentes: %s\n",
            $attempt + 1,
            $attempts,
            implode(', ', $pending)
        );
        sleep($interval);
    }
}

if ($failures !== []) {
    foreach ($failures as $name => $message) {
        printf("ERRO  %-20s %s\n", $name, $message);
    }
    fwrite(STDERR, sprintf(
        "Validacao geral falhou: %d OK | %d erro(s). Nenhuma integracao foi inicializada.\n",
        $passed,
        count($failures)
    ));
    exit(1);
}

printf("Validacao geral concluida: %d/%d OK.\n", $passed, count($checks));
