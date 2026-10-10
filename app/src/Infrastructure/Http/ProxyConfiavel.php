<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use CRM\Config\Database;

/**
 * O acesso pelo nome crm.exemplo.com.br passa pelo proxy da
 * empresa (10.0.0.3), que faz o HTTPS e repassa X-Forwarded-For (IP real
 * do usuário) e X-Forwarded-Proto: https - conferido no log em 2026-10-06.
 * Esses cabeçalhos só valem quando a requisição chega de um proxy listado
 * em API_TRUSTED_PROXIES; de qualquer outro lugar (ex.: acesso direto em
 * :8080) qualquer um poderia forjá-los.
 */
final class ProxyConfiavel
{
    /** @return list<string> */
    public static function lista(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) Database::env('API_TRUSTED_PROXIES', '')))));
    }

    /**
     * IP real do cliente. X-Forwarded-For acumula "cliente, proxy1, proxy2":
     * anda da direita pra esquerda e usa o primeiro IP que não é proxy
     * confiável (o da esquerda é escrito pelo próprio cliente e é forjável).
     *
     * @param array<string,mixed> $server
     * @param list<string>|null $confiaveis
     */
    public static function ipCliente(array $server, ?array $confiaveis = null): string
    {
        $confiaveis ??= self::lista();
        $remoto = self::ipValido($server['REMOTE_ADDR'] ?? null) ?? '0.0.0.0';
        if ($confiaveis === [] || !in_array($remoto, $confiaveis, true)) {
            return $remoto;
        }

        foreach (array_reverse(explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))) as $candidato) {
            $ip = self::ipValido(trim($candidato));
            if ($ip !== null && !in_array($ip, $confiaveis, true)) {
                return $ip;
            }
        }
        return $remoto;
    }

    /**
     * A conexão do usuário é HTTPS? Direto (HTTPS=on) ou pelo proxy
     * confiável (X-Forwarded-Proto: https).
     *
     * @param array<string,mixed> $server
     * @param list<string>|null $confiaveis
     */
    public static function viaHttps(array $server, ?array $confiaveis = null): bool
    {
        if (strtolower((string) ($server['HTTPS'] ?? '')) === 'on') {
            return true;
        }
        $confiaveis ??= self::lista();
        $remoto = self::ipValido($server['REMOTE_ADDR'] ?? null);
        if ($remoto === null || !in_array($remoto, $confiaveis, true)) {
            return false;
        }
        $protocolo = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $protocolo === 'https';
    }

    private static function ipValido(mixed $valor): ?string
    {
        return is_string($valor) && filter_var($valor, FILTER_VALIDATE_IP) !== false ? $valor : null;
    }
}
