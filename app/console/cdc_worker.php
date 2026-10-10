<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Cdc\CdcWorkerSupport;
use CRM\Infrastructure\Redis\RedisConnections;

require_once __DIR__ . '/_bootstrap.php';

if (!class_exists(RdKafka\KafkaConsumer::class)) {
    fwrite(STDERR, "Extensao rdkafka nao instalada.\n");
    exit(1);
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$processor = null;
for ($initializeAttempt = 1; ; $initializeAttempt++) {
    try {
        $processor = CdcWorkerSupport::newProcessor();
        break;
    } catch (Throwable $e) {
        if ($initializeAttempt >= 12) {
            throw $e;
        }
        fwrite(STDERR, sprintf(
            "Aguardando estruturas CDC (tentativa %d/12): %s\n",
            $initializeAttempt + 1,
            $e->getMessage()
        ));
        sleep(5);
    }
}

$conf = new RdKafka\Conf();
$conf->set('metadata.broker.list', (string) Database::env('KAFKA_BROKERS', 'redpanda:9092'));
$conf->set('group.id', (string) Database::env('CDC_CONSUMER_GROUP', 'crm-cdc-projection-v1'));
$conf->set('auto.offset.reset', 'earliest');
$conf->set('enable.auto.commit', 'false');
$conf->set('max.poll.interval.ms', '900000');
$consumer = new RdKafka\KafkaConsumer($conf);
$batchSize = max(10, min(1000, (int) Database::env('CDC_BATCH_SIZE', '250')));
$batchWaitMs = max(50, min(5000, (int) Database::env('CDC_BATCH_WAIT_MS', '500')));
$prefix = (string) Database::env('DEBEZIUM_TOPIC_PREFIX', 'crm.mysql');
$database = (string) Database::env('DB_DATABASE', 'crm_dev');
$tableList = (string) Database::env(
    'DEBEZIUM_TABLE_INCLUDE_LIST',
    'pessoas,pessoa_contatos,pessoa_enderecos,contratos,contrato_parcelas,atendimentos_historico,atendimento_resultados,campanhas,campanha_itens,estrategias,estrategia_contratos,acordos_operacionais,acordos_operacionais_parcelas,acordos_operacionais_acompanhamento,acordo_regras_carencia,usuarios,pagamentos,boletos,baixas,cargas_arquivos'
);
$tables = array_values(array_filter(array_map('trim', explode(',', $tableList))));
$topics = array_map(static fn (string $table): string => $prefix . '.' . $database . '.' . $table, $tables);
$consumer->subscribe($topics);

echo sprintf(
    "CDC Worker iniciado. Aguardando eventos do Redpanda (lote=%d, espera=%dms)...\n",
    $batchSize,
    $batchWaitMs
);

$heartbeat = static function (): void {
    try {
        RedisConnections::cache()->setEx('crm:infra:cdc_worker_heartbeat', 120, date(DATE_ATOM));
    } catch (Throwable) {
    }
};
$heartbeat();

$queueFailure = static function (object $message, ?array $event, Throwable $error, bool $analyticsDone = false): bool {
    try {
        $job = [
            'topic' => (string) $message->topic_name,
            'partition' => (int) $message->partition,
            'offset' => (int) $message->offset,
            'event' => $event,
            'attempts' => 0,
            'last_error' => $error->getMessage(),
            'analytics_done' => $analyticsDone,
            'queued_at' => date(DATE_ATOM),
        ];
        RedisConnections::queue()->rPush(
            'crm:cdc:retry',
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        RedisConnections::cache()->hIncrBy('crm:infra:cdc', 'queued_errors', 1);
        fwrite(STDERR, 'Evento enviado para retry: ' . $error->getMessage() . PHP_EOL);
        return true;
    } catch (Throwable $queueError) {
        fwrite(STDERR, 'Evento nao confirmado; fila indisponivel: ' . $queueError->getMessage() . PHP_EOL);
        return false;
    }
};

/**
 * Commit em lote: antes, cada mensagem processada com sucesso disparava seu
 * proprio $consumer->commit($message) (1 round-trip sincrono ao broker por
 * mensagem). Em cargas grandes isso apareceu no docker stats como NET I/O
 * desproporcional ao CPU do cdc_worker (~22% CPU vs ~8GB/9GB de rede) —
 * overhead de round-trip por evento, nao custo de processamento. Agora so
 * existe 1 commit() por lote (ate $batchSize mensagens ou $batchWaitMs),
 * cobrindo todas as topic-partitions tocadas nele.
 *
 * $toCommit e populado com o MAIOR offset seguro por "topic|partition":
 * seguro = processado com sucesso OU enfileirado com sucesso no Redis para
 * retry (o evento nao sera perdido de nenhuma forma se o Kafka avancar).
 * Uma mensagem que falhou e NAO pode ser enfileirada (Redis indisponivel)
 * nunca entra aqui, entao o commit em lote nao avanca o offset da particao
 * para alem dela. Como cada topico tem particao unica e os itens do lote
 * sao processados em ordem crescente de offset, a ultima escrita em cada
 * chave e sempre o maior offset seguro daquele lote.
 */
$commitBatch = static function (array $toCommit) use ($consumer): void {
    if ($toCommit === []) {
        return;
    }
    $offsets = array_map(
        static fn(array $t): RdKafka\TopicPartition => new RdKafka\TopicPartition($t[0], $t[1], $t[2] + 1),
        array_values($toCommit)
    );
    try {
        $consumer->commit($offsets);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Falha ao commitar offsets em lote: ' . $e->getMessage() . PHP_EOL);
    }
};

while ($running) {
    $message = $consumer->consume(10000);
    if ($message->err === RD_KAFKA_RESP_ERR__TIMED_OUT || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
        $heartbeat();
        continue;
    }
    if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
        fwrite(STDERR, 'Kafka: ' . $message->errstr() . PHP_EOL);
        sleep(2);
        continue;
    }

    $messages = [$message];
    $deadline = microtime(true) + ($batchWaitMs / 1000);
    while ($running && count($messages) < $batchSize) {
        $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            break;
        }

        $next = $consumer->consume($remainingMs);
        if ($next->err === RD_KAFKA_RESP_ERR__TIMED_OUT || $next->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            break;
        }
        if ($next->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            fwrite(STDERR, 'Kafka: ' . $next->errstr() . PHP_EOL);
            continue;
        }
        $messages[] = $next;
    }

    $toCommit = [];
    $items = [];
    foreach ($messages as $current) {
        try {
            $event = json_decode((string) $current->payload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($event)) {
                throw new RuntimeException('Evento CDC nao e um objeto JSON.');
            }
            $items[] = [
                'message' => $current,
                'topic' => (string) $current->topic_name,
                'partition' => (int) $current->partition,
                'offset' => (int) $current->offset,
                'event' => $event,
            ];
        } catch (Throwable $e) {
            if ($queueFailure($current, null, $e)) {
                $toCommit[$current->topic_name . '|' . $current->partition] = [(string) $current->topic_name, (int) $current->partition, (int) $current->offset];
            } else {
                sleep(3);
            }
        }
    }

    if ($items === []) {
        $commitBatch($toCommit);
        $heartbeat();
        continue;
    }

    $analyticsItems = array_map(
        static fn (array $item): array => [
            'topic' => $item['topic'],
            'partition' => $item['partition'],
            'offset' => $item['offset'],
            'event' => $item['event'],
        ],
        $items
    );

    try {
        // Um INSERT por lote evita criar uma part do ClickHouse para cada
        // linha recebida durante cargas grandes.
        $processor->appendAnalyticsBatch($analyticsItems);
    } catch (Throwable $e) {
        foreach ($items as $item) {
            if ($queueFailure($item['message'], $item['event'], $e)) {
                $toCommit[$item['topic'] . '|' . $item['partition']] = [$item['topic'], $item['partition'], $item['offset']];
            } else {
                sleep(3);
            }
        }
        $commitBatch($toCommit);
        $heartbeat();
        continue;
    }

    foreach ($items as $item) {
        try {
            $processor->processProjection(
                $item['topic'],
                $item['partition'],
                $item['offset'],
                $item['event']
            );
            $toCommit[$item['topic'] . '|' . $item['partition']] = [$item['topic'], $item['partition'], $item['offset']];
        } catch (Throwable $e) {
            if (CdcWorkerSupport::isMysqlConnectionLost($e)) {
                try {
                    fwrite(STDERR, 'Conexão MySQL do CDC expirou; recriando conexão antes de reenfileirar o evento.' . PHP_EOL);
                    $processor = CdcWorkerSupport::newProcessor();
                    $processor->processProjection(
                        $item['topic'],
                        $item['partition'],
                        $item['offset'],
                        $item['event']
                    );
                    $toCommit[$item['topic'] . '|' . $item['partition']] = [$item['topic'], $item['partition'], $item['offset']];
                    try {
                        RedisConnections::cache()->hIncrBy('crm:infra:cdc', 'mysql_reconnect_total', 1);
                    } catch (Throwable) {
                    }
                    continue;
                } catch (Throwable $reconnectError) {
                    $e = $reconnectError;
                }
            }

            if ($queueFailure($item['message'], $item['event'], $e, true)) {
                $toCommit[$item['topic'] . '|' . $item['partition']] = [$item['topic'], $item['partition'], $item['offset']];
            } else {
                sleep(3);
            }
        }
    }
    $commitBatch($toCommit);
    $heartbeat();
}

$consumer->close();
echo "CDC Worker encerrado.\n";
