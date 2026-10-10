<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Redis;

use CRM\Config\Database;
use Redis;
use Throwable;

final class RedisCache
{
    private string $prefix;

    public function __construct(private readonly ?Redis $redis = null)
    {
        $this->prefix = trim((string) Database::env('REDIS_CACHE_PREFIX', 'works:'), " \t\n\r\0\x0B");
    }

    public static function default(): self
    {
        return new self(RedisConnection::cache());
    }

    public function disponivel(): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            $pong = $this->redis->ping();
            return $pong === true || $pong === '+PONG';
        } catch (Throwable) {
            return false;
        }
    }

    public function get(string $key): ?string
    {
        if ($this->redis === null) {
            return null;
        }
        try {
            $value = $this->redis->get($this->key($key));
            return $value === false ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $key, string $value, int $ttlSeconds = 300): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            if ($ttlSeconds > 0) {
                return (bool) $this->redis->setex($this->key($key), $ttlSeconds, $value);
            }
            return (bool) $this->redis->set($this->key($key), $value);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public function getJson(string $key): ?array
    {
        $raw = $this->get($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $value */
    public function setJson(string $key, array $value, int $ttlSeconds = 300): bool
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false && $this->set($key, $json, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            return $this->redis->del($this->key($key)) >= 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function increment(string $key): ?int
    {
        if ($this->redis === null) {
            return null;
        }
        try {
            return (int) $this->redis->incr($this->key($key));
        } catch (Throwable) {
            return null;
        }
    }

    private function key(string $key): string
    {
        return $this->prefix . ltrim($key, ':');
    }
}
