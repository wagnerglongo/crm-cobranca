<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use JsonException;

final class ApiResponse
{
    /**
     * @param array<string,mixed>|list<mixed>|null $payload
     * @param array<string,string> $headers
     */
    private function __construct(
        private readonly int $status,
        private readonly array|null $payload,
        private readonly array $headers = []
    ) {
    }

    /** @param array<string,mixed>|list<mixed> $payload @param array<string,string> $headers */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self($status, $payload, $headers);
    }

    /** @param array<string,string> $headers */
    public static function noContent(array $headers = []): self
    {
        return new self(204, null, $headers);
    }

    /** @param array<string,mixed> $details @param array<string,string> $headers */
    public static function error(
        int $status,
        string $code,
        string $message,
        string $requestId,
        array $details = [],
        array $headers = []
    ): self {
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return new self($status, ['error' => $error], $headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed>|list<mixed>|null */
    public function payload(): array|null
    {
        return $this->payload;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param array<string,string> $extraHeaders */
    public function send(string $requestId, array $extraHeaders = []): never
    {
        http_response_code($this->status);
        $headers = array_merge([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ], $extraHeaders, $this->headers);

        if ($this->payload !== null) {
            $headers['Content-Type'] = 'application/json; charset=UTF-8';
        }

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->payload !== null) {
            try {
                echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                http_response_code(500);
                echo '{"error":{"code":"RESPONSE_ENCODING_ERROR","message":"Falha ao gerar a resposta.","request_id":"' .
                    addslashes($requestId) . '"}}';
            }
        }
        exit;
    }
}
