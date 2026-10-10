<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use CRM\Infrastructure\Redis\RedisConnection;
use RuntimeException;
use Throwable;

/**
 * Protege contra APIs externas (credores) travando o pool de workers
 * PHP-FPM durante uma indisponibilidade: sem isso, cada operador que
 * clica em algo que depende da API externa espera o timeout completo
 * (ate 45s, configuravel) antes de falhar - com varios operadores
 * simultaneos batendo numa API fora do ar, o pool de workers esgota.
 * Depois de N falhas retryable em uma janela curta, passa a falhar
 * rapido por um periodo de cooldown, sem nem tentar a chamada de rede.
 * Best-effort: se o Redis estiver fora do ar, nunca bloqueia o operador
 * por causa do proprio circuito (fail-open).
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly int $limiteFalhas = 5,
        private readonly int $janelaSegundos = 60,
        private readonly int $cooldownSegundos = 30
    ) {
    }

    public function assertFechado(string $servico): void
    {
        $redis = RedisConnection::cache();
        if ($redis === null) {
            return;
        }
        try {
            if ($redis->exists($this->chaveAberto($servico))) {
                throw new RuntimeException(
                    'A API ' . $servico . ' está indisponível (muitas falhas recentes). Tente novamente em alguns instantes.'
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            // Redis com problema: nao usar isso pra bloquear o operador.
        }
    }

    public function registrarSucesso(string $servico): void
    {
        $redis = RedisConnection::cache();
        if ($redis === null) {
            return;
        }
        try {
            $redis->del($this->chaveFalhas($servico));
        } catch (Throwable) {
            // best-effort
        }
    }

    public function registrarFalha(string $servico): void
    {
        $redis = RedisConnection::cache();
        if ($redis === null) {
            return;
        }
        try {
            $chave = $this->chaveFalhas($servico);
            $total = (int) $redis->incr($chave);
            if ($total === 1 || (int) $redis->ttl($chave) < 0) {
                $redis->expire($chave, $this->janelaSegundos);
            }
            if ($total >= $this->limiteFalhas) {
                $redis->setex($this->chaveAberto($servico), $this->cooldownSegundos, '1');
            }
        } catch (Throwable) {
            // best-effort
        }
    }

    private function chaveFalhas(string $servico): string
    {
        return 'works:circuito:falhas:' . $servico;
    }

    private function chaveAberto(string $servico): string
    {
        return 'works:circuito:aberto:' . $servico;
    }
}
