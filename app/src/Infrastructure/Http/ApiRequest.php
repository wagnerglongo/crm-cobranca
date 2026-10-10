<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use CRM\Config\Database;
use JsonException;

final class ApiRequest
{
    /** @var array<string,string> */
    private array $headers;

    /** @var array<string,mixed>|null */
    private ?array $json = null;

    private bool $jsonLido = false;

    /**
     * @param array<string,mixed> $query
     * @param array<string,string> $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        array $headers,
        private readonly string $rawBody,
        private readonly string $clientIp
    ) {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[mb_strtolower(trim($name))] = trim($value);
        }
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }
        if (!isset($headers['AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $maxBytes = max(1024, min(1048576, (int) Database::env('API_MAX_JSON_BYTES', '65536')));
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > $maxBytes) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'O corpo da requisição excede o limite permitido.');
        }

        $raw = file_get_contents('php://input');
        $raw = $raw === false ? '' : $raw;
        if (strlen($raw) > $maxBytes) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'O corpo da requisição excede o limite permitido.');
        }

        return new self($method, $path, $_GET, $headers, $raw, self::resolveClientIp($_SERVER));
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        if ($this->path === '/') {
            return '/';
        }
        return rtrim($this->path, '/');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[mb_strtolower(trim($name))] ?? $default;
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonLido) {
            return $this->json ?? [];
        }
        $this->jsonLido = true;

        if (trim($this->rawBody) === '') {
            $this->json = [];
            return [];
        }

        $contentType = mb_strtolower((string) $this->header('Content-Type', ''));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Envie o corpo da requisição como application/json.');
        }

        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'O JSON enviado é inválido.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'O corpo JSON deve ser um objeto.');
        }

        /** @var array<string,mixed> $decoded */
        $this->json = $decoded;
        return $this->json;
    }

    public function bearerToken(): string
    {
        $authorization = trim((string) $this->header('Authorization', ''));
        if (preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $matches) !== 1) {
            return '';
        }
        return trim((string) $matches[1]);
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function userAgent(): ?string
    {
        $value = trim((string) $this->header('User-Agent', ''));
        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * X-Forwarded-For acumula um IP por salto de proxy, na ordem
     * "cliente, proxy1, proxy2, ...": cada proxy SO ACRESCENTA seu proprio
     * IP a direita, nunca reescreve o que ja estava la. O IP mais a
     * esquerda e' escrito pelo proprio cliente (nao confiavel - qualquer
     * requisicao pode vir com esse cabecalho contendo qualquer coisa).
     * A versao antiga pegava o PRIMEIRO IP valido da lista (o do cliente,
     * ou seja, forjavel), derrotando o rate-limit por IP assim que
     * API_TRUSTED_PROXIES fosse configurado em producao. Agora anda da
     * direita pra esquerda e usa o primeiro IP que NAO seja um proxy
     * conhecido - o unico dado que os proxies confiaveis realmente
     * confirmaram.
     *
     * @param array<string,mixed> $server
     */
    private static function resolveClientIp(array $server): string
    {
        return ProxyConfiavel::ipCliente($server);
    }

    private static function validIp(mixed $value): ?string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $value;
    }
}
