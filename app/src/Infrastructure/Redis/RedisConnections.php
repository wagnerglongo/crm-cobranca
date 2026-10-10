<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Redis;

use CRM\Config\Database;
use Redis;
use RuntimeException;

final class RedisConnections
{
    private static ?Redis $cache = null;
    private static ?Redis $queue = null;

    public static function cache(): Redis
    {
        return self::$cache ??= self::connect(
            (string) Database::env('REDIS_CACHE_HOST', 'redis-cache'),
            (int) Database::env('REDIS_CACHE_PORT', '6379'),
            (string) Database::env('REDIS_CACHE_PASSWORD', ''),
            (int) Database::env('REDIS_CACHE_DATABASE', '0')
        );
    }

    public static function queue(): Redis
    {
        return self::$queue ??= self::connect(
            (string) Database::env('REDIS_QUEUE_HOST', 'redis-queue'),
            (int) Database::env('REDIS_QUEUE_PORT', '6379'),
            (string) Database::env('REDIS_QUEUE_PASSWORD', ''),
            (int) Database::env('REDIS_QUEUE_DATABASE', '0')
        );
    }

    public static function configureSession(): void
    {
        // O prazo operacional e absoluto e controlado pelo Pactua. O armazenamento
        // da sessao precisa apenas sobreviver por tempo suficiente para nao expirar
        // antes do limite configuravel de ate 24 horas.
        ini_set('session.gc_maxlifetime', '90000');
        ini_set('session.cookie_lifetime', '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if (filter_var(Database::env('REDIS_SESSION_ENABLED', 'true'), FILTER_VALIDATE_BOOL) !== true) {
            return;
        }

        // Testa a conexao antes de trocar o handler. Se o Redis estiver fora,
        // o PHP continua usando sessoes locais e o login nao fica indisponivel.
        self::cache()->ping();
        $host = (string) Database::env('REDIS_CACHE_HOST', 'redis-cache');
        $port = (int) Database::env('REDIS_CACHE_PORT', '6379');
        $password = (string) Database::env('REDIS_CACHE_PASSWORD', '');
        $database = (int) Database::env('REDIS_SESSION_DATABASE', '1');
        // Sem senha, o parametro "auth" nao pode ir vazio: o phpredis tentaria
        // AUTH com string vazia e a sessao falharia (Redis local sem senha).
        $query = http_build_query(($password !== '' ? ['auth' => $password] : []) + [
            'database' => $database,
            'prefix' => 'crm_session:',
            'timeout' => 2,
            'read_timeout' => 2,
        ]);

        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', sprintf('tcp://%s:%d?%s', $host, $port, $query));
    }

    private static function connect(string $host, int $port, string $password, int $database): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('Extensao PHP Redis nao instalada.');
        }

        $redis = new Redis();
        if (!$redis->connect($host, $port, 2.0)) {
            throw new RuntimeException(sprintf('Nao foi possivel conectar ao Redis %s:%d.', $host, $port));
        }
        if ($password !== '' && !$redis->auth($password)) {
            throw new RuntimeException('Autenticacao Redis recusada.');
        }
        if ($database > 0 && !$redis->select($database)) {
            throw new RuntimeException('Nao foi possivel selecionar o database Redis.');
        }

        return $redis;
    }
}
