<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Controllers;

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Infrastructure\Health\InfrastructureHealthService;
use CRM\Infrastructure\Redis\RedisConnection;
use CRM\Modules\Api\Auth\AuthContext;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class HealthController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string> $params */
    public function health(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $mysql = false;
        $redis = false;
        try {
            $mysql = (string) $this->pdo->query('SELECT 1')->fetchColumn() === '1';
        } catch (Throwable) {
            $mysql = false;
        }
        try {
            $connection = RedisConnection::cache();
            $pong = $connection?->ping();
            $redis = $pong === true || $pong === '+PONG';
        } catch (Throwable) {
            $redis = false;
        }

        $ok = $mysql && $redis;
        return ApiResponse::json([
            'status' => $ok ? 'ok' : 'degraded',
            'app' => (string) Database::env('APP_NAME', 'Pactua CRM'),
            'api_version' => 'v1',
            'foundation_patch' => 121,
            'time' => (new DateTimeImmutable('now', new DateTimeZone((string) Database::env('APP_TIMEZONE', 'America/Sao_Paulo'))))->format(DATE_ATOM),
            'components' => [
                'mysql' => $mysql ? 'up' : 'down',
                'redis_rate_limit' => $redis ? 'up' : 'down',
            ],
        ], $ok ? 200 : 503);
    }

    /** @param array<string,string> $params */
    public function components(ApiRequest $request, ?AuthContext $context, array $params, string $requestId): ApiResponse
    {
        $checks = (new InfrastructureHealthService($this->pdo))->verificarTudo();
        $components = [];
        foreach ($checks as $check) {
            $components[] = [
                'name' => (string) ($check['servico'] ?? 'Componente'),
                'status' => (string) ($check['status'] ?? 'unknown'),
                'ok' => (bool) ($check['ok'] ?? false),
                'latency_ms' => (int) ($check['latencia_ms'] ?? 0),
            ];
        }
        return ApiResponse::json([
            'status' => count(array_filter($components, static fn (array $item): bool => !$item['ok'])) === 0 ? 'ok' : 'degraded',
            'components' => $components,
        ]);
    }
}
