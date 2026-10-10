<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Modules\Api\Controllers\HealthController;
use PHPUnit\Framework\TestCase;

/**
 * HealthController::health() - endpoint público de saúde (MySQL + Redis).
 * `components()` fica de fora de propósito: dispara sondas reais na
 * infraestrutura viva (mesma decisão do IntegracaoSaudeService).
 */
final class HealthControllerTest extends TestCase
{
    public function testHealthComMysqlERedisNoArRetorna200EComponentesUp(): void
    {
        $controller = new HealthController(Database::pdo());

        $resposta = $controller->health(new ApiRequest('GET', '/health', [], [], '', '127.0.0.1'), null, [], 'req-health');
        $payload = $resposta->payload();

        self::assertSame(200, $resposta->status());
        self::assertSame('ok', $payload['status']);
        self::assertSame('up', $payload['components']['mysql']);
        self::assertSame('up', $payload['components']['redis_rate_limit']);
        self::assertSame('v1', $payload['api_version']);
    }
}
