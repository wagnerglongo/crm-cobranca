<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Redis;

use CRM\Config\Database;
use Redis;
use Throwable;

final class RedisConnection
{
    /** @var array<string,Redis|null> */
    private static array $connections = [];

    public static function cache(): ?Redis
    {
        return self::connection(
            'cache',
            (string) Database::env('REDIS_CACHE_HOST', 'redis-cache'),
            (int) Database::env('REDIS_CACHE_PORT', '6379'),
            (string) Database::env('REDIS_CACHE_PASSWORD', ''),
            (int) Database::env('REDIS_CACHE_DB', '0')
        );
    }

    public static function queue(): ?Redis
    {
        return self::connection(
            'queue',
            (string) Database::env('REDIS_QUEUE_HOST', 'redis-queue'),
            (int) Database::env('REDIS_QUEUE_PORT', '6379'),
            (string) Database::env('REDIS_QUEUE_PASSWORD', ''),
            (int) Database::env('REDIS_QUEUE_DB', '0')
        );
    }

    private static function connection(string $name, string $host, int $port, string $password, int $database): ?Redis
    {
        if (isset(self::$connections[$name]) && self::$connections[$name] instanceof Redis) {
            // A conexao era cacheada pra sempre sem nunca ser revalidada -
            // um restart do Redis ou uma queda de rede breve degradava
            // cache/fila desse processo PHP pro resto da vida dele
            // (silenciosamente, sem crashar, mas sem se recuperar tambem -
            // mesma classe de bug do integracao_worker.php, 2026-09-15, so
            // que la travava o processo inteiro em vez de so degradar).
            try {
                if (self::$connections[$name]->ping() !== false) {
                    return self::$connections[$name];
                }
            } catch (Throwable) {
                // conexao morta - cai pra reconectar abaixo
            }
            self::$connections[$name] = null;
        }

        if (!class_exists(Redis::class) || $host === '' || $port <= 0) {
            return null;
        }

        $timeout = max(0.1, min(3.0, (float) Database::env('REDIS_CONNECT_TIMEOUT', '0.8')));

        try {
            $redis = new Redis();
            if (!$redis->connect($host, $port, $timeout)) {
                return null;
            }
            if ($password !== '' && !$redis->auth($password)) {
                return null;
            }
            if ($database > 0 && !$redis->select($database)) {
                return null;
            }
            $redis->setOption(Redis::OPT_READ_TIMEOUT, max(0.1, min(3.0, (float) Database::env('REDIS_READ_TIMEOUT', '0.8'))));
            return self::$connections[$name] = $redis;
        } catch (Throwable) {
            return null;
        }
    }
}
