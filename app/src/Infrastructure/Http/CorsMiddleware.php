<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use CRM\Config\Database;

final class CorsMiddleware
{
    /** @var list<string> */
    private array $allowedOrigins;

    public function __construct()
    {
        $origins = array_values(array_filter(array_map(
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            explode(',', (string) Database::env('API_CORS_ALLOWED_ORIGINS', ''))
        )));
        $appUrl = rtrim(trim((string) Database::env('APP_URL', '')), '/');
        if ($appUrl !== '') {
            $origins[] = $appUrl;
        }
        $environment = mb_strtolower((string) Database::env('APP_ENV', 'production'));
        if ($environment === 'production') {
            $origins = array_values(array_filter($origins, static fn (string $origin): bool => $origin !== '*'));
        }
        $this->allowedOrigins = array_values(array_unique($origins));
    }

    /** @return array<string,string> */
    public function headers(ApiRequest $request): array
    {
        $origin = rtrim(trim((string) $request->header('Origin', '')), '/');
        if ($origin === '') {
            return [];
        }
        if (!$this->mesmoHost($origin, (string) $request->header('Host', ''))
            && !in_array($origin, $this->allowedOrigins, true)
            && !in_array('*', $this->allowedOrigins, true)) {
            throw new ApiException(403, 'CORS_ORIGIN_DENIED', 'A origem informada não está autorizada para esta API.');
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Request-ID',
            'Access-Control-Expose-Headers' => 'X-Request-ID, X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }

    private function mesmoHost(string $origin, string $requestHost): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $port = parse_url($origin, PHP_URL_PORT);
        $originAuthority = mb_strtolower($host . (is_int($port) ? ':' . $port : ''));
        return hash_equals(mb_strtolower(trim($requestHost)), $originAuthority);
    }
}
