<?php

declare(strict_types=1);

use CRM\Infrastructure\Http\ApiRequest;
use PHPUnit\Framework\TestCase;

/**
 * resolveClientIp() pegava o PRIMEIRO IP de X-Forwarded-For (escrito pelo
 * proprio cliente, forjavel) em vez do primeiro IP que nao seja um proxy
 * conhecido, andando da direita pra esquerda - o unico dado que a cadeia
 * de proxies confiaveis realmente confirma. So importa depois que
 * API_TRUSTED_PROXIES e configurado (ambiente de producao atras de um
 * load balancer); sem isso o codigo so usa REMOTE_ADDR direto.
 */
final class ApiRequestClientIpTest extends TestCase
{
    private function resolve(array $server): string
    {
        $method = new ReflectionMethod(ApiRequest::class, 'resolveClientIp');
        $method->setAccessible(true);
        return $method->invoke(null, $server);
    }

    protected function setUp(): void
    {
        putenv('API_TRUSTED_PROXIES=10.0.0.1');
        $_ENV['API_TRUSTED_PROXIES'] = '10.0.0.1';
    }

    protected function tearDown(): void
    {
        putenv('API_TRUSTED_PROXIES');
        unset($_ENV['API_TRUSTED_PROXIES']);
    }

    public function testIgnoraIpForjadoPeloClienteNoInicioDaCadeia(): void
    {
        $ip = $this->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 10.0.0.1',
        ]);

        self::assertSame('203.0.113.99', $ip, 'deve pegar o IP real do cliente, nao o proxy confiavel');
    }

    public function testRejeitaTentativaDeForjarUmSegundoSaltoDeProxy(): void
    {
        // Um atacante conectando direto no proxy confiavel pode mandar
        // "X-Forwarded-For: 1.2.3.4, 5.6.7.8" tentando se passar por um IP
        // qualquer que nao seja o seu. So 10.0.0.1 (REMOTE_ADDR real) e
        // confiavel; o restante da cadeia e sempre atacante-controlado
        // quando ha um unico proxy confiavel configurado.
        $ip = $this->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
        ]);

        self::assertSame('5.6.7.8', $ip, 'pega o ultimo da cadeia que nao e um proxy conhecido');
    }

    public function testSemProxyConfiavelNaListaUsaRemoteAddrDireto(): void
    {
        putenv('API_TRUSTED_PROXIES');
        unset($_ENV['API_TRUSTED_PROXIES']);

        $ip = $this->resolve([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('203.0.113.50', $ip, 'sem API_TRUSTED_PROXIES configurado, X-Forwarded-For nunca e confiavel');
    }

    public function testRemoteAddrForaDaListaDeConfiaveisIgnoraForwardedFor(): void
    {
        $ip = $this->resolve([
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('198.51.100.7', $ip, 'requisicao nao veio de um proxy confiavel - X-Forwarded-For e ignorado');
    }
}
