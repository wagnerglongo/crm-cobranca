<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use CRM\Infrastructure\Redis\RedisConnection;
use Throwable;

final class RedisRateLimiter
{
    /** @return array<string,string> */
    public function consume(string $bucket, int $limit, int $windowSeconds, bool $strict = false): array
    {
        $limit = max(1, min($limit, 10000));
        $windowSeconds = max(1, min($windowSeconds, 86400));
        $redis = RedisConnection::cache();
        if ($redis === null) {
            if ($strict) {
                throw new ApiException(503, 'RATE_LIMIT_UNAVAILABLE', 'O controle de segurança da API está temporariamente indisponível.');
            }
            return [];
        }

        $window = (int) floor(time() / $windowSeconds);
        $key = 'works:api:rate:' . hash('sha256', $bucket) . ':' . $window;
        try {
            $count = (int) $redis->incr($key);
            if ($count === 1 || (int) $redis->ttl($key) < 0) {
                $redis->expire($key, $windowSeconds + 1);
            }
            $retryAfter = max(1, $windowSeconds - (time() % $windowSeconds));
            $headers = [
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => (string) max(0, $limit - $count),
            ];
            if ($count > $limit) {
                $headers['Retry-After'] = (string) $retryAfter;
                throw new ApiException(429, 'RATE_LIMIT_EXCEEDED', 'Limite de requisições excedido. Tente novamente em instantes.', [], $headers);
            }
            return $headers;
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            if ($strict) {
                throw new ApiException(503, 'RATE_LIMIT_UNAVAILABLE', 'O controle de segurança da API está temporariamente indisponível.');
            }
            return [];
        }
    }
}
