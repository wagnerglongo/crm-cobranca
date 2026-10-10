<?php

declare(strict_types=1);

use CRM\Infrastructure\Http\CircuitBreaker;
use PHPUnit\Framework\TestCase;

/**
 * CircuitBreaker protege AcmeApiClient contra o timeout
 * de 45s ser pago por todo operador durante uma indisponibilidade da
 * API externa (achado de baixo impacto da auditoria de 2026-09-15/16).
 * Testado aqui contra o Redis real do stack, não um mock, porque o
 * comportamento que importa é justamente a contagem com TTL no Redis.
 */
final class CircuitBreakerTest extends TestCase
{
    public function testPermaneceFechadoAbaixoDoLimiteDeFalhas(): void
    {
        $breaker = new CircuitBreaker(limiteFalhas: 3, janelaSegundos: 10, cooldownSegundos: 5);
        $servico = 'teste:' . bin2hex(random_bytes(4));

        $breaker->registrarFalha($servico);
        $breaker->registrarFalha($servico);
        $breaker->assertFechado($servico);

        $this->addToAssertionCount(1);
    }

    public function testAbreDepoisDoLimiteDeFalhasELiberaAposCooldown(): void
    {
        $breaker = new CircuitBreaker(limiteFalhas: 2, janelaSegundos: 10, cooldownSegundos: 1);
        $servico = 'teste:' . bin2hex(random_bytes(4));

        $breaker->registrarFalha($servico);
        $breaker->registrarFalha($servico);

        try {
            $breaker->assertFechado($servico);
            self::fail('Deveria estar aberto após atingir o limite de falhas.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($servico, $e->getMessage());
        }

        sleep(2);
        $breaker->assertFechado($servico);
        $this->addToAssertionCount(1);
    }

    public function testSucessoResetaContadorDeFalhas(): void
    {
        $breaker = new CircuitBreaker(limiteFalhas: 2, janelaSegundos: 10, cooldownSegundos: 5);
        $servico = 'teste:' . bin2hex(random_bytes(4));

        $breaker->registrarFalha($servico);
        $breaker->registrarSucesso($servico);
        $breaker->registrarFalha($servico);
        $breaker->assertFechado($servico);

        $this->addToAssertionCount(1);
    }
}
