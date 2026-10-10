<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Redis\RedisConnections;
use CRM\Modules\Operacao\Repositories\IntegracaoEventoSaidaRepository;
use CRM\Modules\Operacao\Services\AcmeApiException;
use CRM\Modules\Operacao\Services\IntegracaoSaidaAdapterFactory;

require_once __DIR__ . '/_bootstrap.php';

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$options = getopt('', ['once']);
$once = array_key_exists('once', is_array($options) ? $options : []);
$batchSize = max(1, min(500, (int) Database::env('INTEGRATION_WORKER_BATCH', '50')));
$pollSeconds = max(1, min(30, (int) Database::env('INTEGRATION_WORKER_POLL_SECONDS', '2')));
$pollMilliseconds = $pollSeconds * 1000;
$lockSeconds = max(30, min(3600, (int) Database::env('INTEGRATION_WORKER_LOCK_SECONDS', '300')));
$heartbeatTtl = max(60, min(900, (int) Database::env('INTEGRATION_WORKER_HEARTBEAT_TTL', '300')));
$heartbeatInterval = max(5, min(60, (int) Database::env('INTEGRATION_WORKER_HEARTBEAT_INTERVAL', '15')));
$heartbeatFile = '/var/www/storage/runtime/integration-worker-heartbeat.json';
$hostname = gethostname() ?: 'container';
$workerId = mb_substr($hostname . ':' . getmypid(), 0, 120);

$pdo = Database::pdo();
$repository = new IntegracaoEventoSaidaRepository($pdo);
$factory = new IntegracaoSaidaAdapterFactory();

if (!$repository->moduloDisponivel()) {
    fwrite(STDERR, "Estruturas da migration 085 nao encontradas. Execute scripts/migrate.sh.\n");
    exit(1);
}

// A conexao PDO e criada uma unica vez e reaproveitada por todo o loop do
// worker (processo de longa duracao). Se o mysqld reiniciar ou a conexao
// cair por qualquer motivo, toda query subsequente falha com "MySQL server
// has gone away"/"Lost connection" e o worker fica preso nesse erro para
// sempre, sem se recuperar sozinho (so um restart manual do container
// resolvia). Isso e detectado aqui e a conexao e recriada automaticamente.
$isConnectionLost = static function (Throwable $e): bool {
    $mensagem = $e->getMessage();
    return str_contains($mensagem, 'server has gone away')
        || str_contains($mensagem, 'Lost connection')
        || str_contains($mensagem, 'Error while sending')
        || (int) $e->getCode() === 2006
        || (int) $e->getCode() === 2013;
};
$reconectar = static function () use (&$pdo, &$repository): void {
    $pdo = Database::pdo();
    $repository = new IntegracaoEventoSaidaRepository($pdo);
};

$lastHeartbeatAt = 0.0;
$lastHeartbeatWarningAt = 0.0;
$heartbeat = static function (bool $force = false) use (
    $heartbeatTtl,
    $heartbeatInterval,
    $heartbeatFile,
    $workerId,
    &$lastHeartbeatAt,
    &$lastHeartbeatWarningAt
): void {
    $now = microtime(true);
    if (!$force && $lastHeartbeatAt > 0 && ($now - $lastHeartbeatAt) < $heartbeatInterval) {
        return;
    }

    $payload = [
        'at' => date(DATE_ATOM),
        'worker_id' => $workerId,
        'pid' => getmypid(),
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $payload['at'];
    $errors = [];

    try {
        RedisConnections::cache()->setEx(
            'crm:infra:integration_worker_heartbeat',
            $heartbeatTtl,
            $encoded
        );
    } catch (Throwable $e) {
        $errors[] = 'Redis: ' . $e->getMessage();
    }

    try {
        $dir = dirname($heartbeatFile);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar ' . $dir);
        }
        $tmp = $heartbeatFile . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Falha ao gravar arquivo temporario de heartbeat.');
        }
        if (!@rename($tmp, $heartbeatFile)) {
            @unlink($tmp);
            throw new RuntimeException('Falha ao publicar arquivo de heartbeat.');
        }
    } catch (Throwable $e) {
        $errors[] = 'arquivo: ' . $e->getMessage();
    }

    $lastHeartbeatAt = $now;
    if ($errors !== [] && ($lastHeartbeatWarningAt <= 0 || ($now - $lastHeartbeatWarningAt) >= 60)) {
        fwrite(STDERR, 'Aviso heartbeat Integration Worker: ' . implode(' | ', $errors) . PHP_EOL);
        $lastHeartbeatWarningAt = $now;
    }
};

$sleep = static function () use ($pollMilliseconds): void {
    usleep($pollMilliseconds * 1000);
};

fwrite(STDOUT, sprintf(
    "Integration Worker iniciado (id=%s, lote=%d, espera=%dms, lock=%ds).\n",
    $workerId,
    $batchSize,
    $pollMilliseconds,
    $lockSeconds
));
$heartbeat(true);

while ($running) {
    try {
        $eventos = $repository->reservarLote($workerId, $batchSize, $lockSeconds);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Falha ao reservar eventos: ' . $e->getMessage() . PHP_EOL);
        if ($isConnectionLost($e)) {
            try {
                $reconectar();
                fwrite(STDOUT, "Conexao com o MySQL recriada apos queda.\n");
            } catch (Throwable $e2) {
                fwrite(STDERR, 'Falha ao reconectar ao MySQL: ' . $e2->getMessage() . PHP_EOL);
            }
        }
        if ($once) {
            exit(1);
        }
        $sleep();
        continue;
    }

    if ($eventos === []) {
        $heartbeat();
        if ($once) {
            break;
        }
        $sleep();
        continue;
    }

    $idsReservados = array_values(array_filter(array_map(
        static fn (array $evento): int => (int) ($evento['id'] ?? 0),
        $eventos
    ), static fn (int $id): bool => $id > 0));

    foreach ($eventos as $evento) {
        if (!$running) {
            break;
        }

        $heartbeat();
        $eventoId = (int) ($evento['id'] ?? 0);
        try {
            // Um lote pode levar mais tempo que o lock quando a API estiver lenta.
            // Renove todas as reservas ainda processando para impedir que outro
            // worker recupere e envie o mesmo acionamento em paralelo.
            $repository->renovarReservas($idsReservados, $workerId);
            if (!$repository->reservaPertenceAoWorker($eventoId, $workerId)) {
                throw new RuntimeException('A reserva do evento não pertence mais a este worker.');
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'Falha ao renovar reservas do lote: ' . $e->getMessage() . PHP_EOL);
            if ($isConnectionLost($e)) {
                try {
                    $reconectar();
                    fwrite(STDOUT, "Conexao com o MySQL recriada apos queda.\n");
                } catch (Throwable $e2) {
                    fwrite(STDERR, 'Falha ao reconectar ao MySQL: ' . $e2->getMessage() . PHP_EOL);
                }
            }
            // Sem confirmar o lock no MySQL, não chame o credor. Os eventos
            // permanecem processando e serão recuperados pelo timeout seguro.
            break;
        }
        $numeroTentativa = max(1, (int) ($evento['tentativas'] ?? 1));
        $tentativaId = 0;
        $startedAt = microtime(true);
        $requestPayload = [];

        try {
            if (strtolower((string) ($evento['tipo_transporte'] ?? '')) !== 'api') {
                throw new InvalidArgumentException('O transporte configurado ainda nao possui worker de entrega: ' . (string) ($evento['tipo_transporte'] ?? 'indefinido'));
            }

            $adapter = $factory->criar(
                (string) ($evento['adapter'] ?? ''),
                isset($evento['timeout_segundos']) ? (int) $evento['timeout_segundos'] : null,
                is_array($evento['configuracao_array'] ?? null) ? $evento['configuracao_array'] : []
            );
            if (!$adapter->configurado()) {
                throw new RuntimeException('Credenciais/configuracao do adapter ' . $adapter->codigo() . ' nao estao disponiveis no ambiente.');
            }

            $requestPayload = $adapter->preparar($evento);
            $tentativaId = $repository->iniciarTentativa($eventoId, $numeroTentativa, $workerId, $requestPayload);
            $resultado = $adapter->enviar($requestPayload);
            $duracaoMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            $repository->marcarEnviado(
                $eventoId,
                $tentativaId,
                (int) ($resultado['http_status'] ?? 0),
                $duracaoMs,
                is_array($resultado['response'] ?? null) ? $resultado['response'] : [],
                isset($resultado['protocolo']) && $resultado['protocolo'] !== null
                    ? (string) $resultado['protocolo']
                    : null
            );
            fwrite(STDOUT, sprintf("Evento #%d enviado por %s na tentativa %d.\n", $eventoId, $adapter->codigo(), $numeroTentativa));
        } catch (Throwable $e) {
            if ($tentativaId <= 0) {
                try {
                    $tentativaId = $repository->iniciarTentativa($eventoId, $numeroTentativa, $workerId, $requestPayload);
                } catch (Throwable) {
                    $tentativaId = 0;
                }
            }

            $duracaoMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            $retryable = match (true) {
                $e instanceof AcmeApiException => $e->retryable(),
                $e instanceof InvalidArgumentException => false,
                str_contains(mb_strtolower($e->getMessage()), 'credenciais/configuracao') => true,
                str_contains(mb_strtolower($e->getMessage()), 'credenciais/configuração') => true,
                str_contains(mb_strtolower($e->getMessage()), 'adapter de integracao nao implementado') => false,
                str_contains(mb_strtolower($e->getMessage()), 'adapter de integração não implementado') => false,
                $e instanceof RuntimeException => false,
                default => true,
            };
            $httpStatus = $e instanceof AcmeApiException ? $e->httpStatus() : 0;
            $response = $e instanceof AcmeApiException ? $e->response() : [];

            $status = $repository->marcarErro(
                $evento,
                $tentativaId,
                $e->getMessage(),
                $retryable,
                $httpStatus,
                $duracaoMs,
                $response
            );
            fwrite(STDERR, sprintf("Evento #%d falhou (%s): %s\n", $eventoId, $status, $e->getMessage()));
        }

        $heartbeat();
    }

    $heartbeat();
    if ($once) {
        break;
    }
}

fwrite(STDOUT, "Integration Worker encerrado.\n");
