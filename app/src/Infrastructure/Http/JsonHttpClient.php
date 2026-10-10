<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use RuntimeException;

final class JsonHttpClient
{
    /** @param array<string,mixed>|string|null $body @return array{status:int,body:string,json:mixed} */
    public function request(string $method, string $url, array|string|null $body = null, int $timeout = 10, array $headers = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nao foi possivel iniciar a requisicao HTTP.');
        }

        $payload = $body;
        if (is_array($body)) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        $method = strtoupper($method);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'HEAD') {
            // Sem CURLOPT_NOBODY, o cURL aguarda o Content-Length informado
            // pelo servidor mesmo que uma resposta HEAD nao possua corpo.
            $options[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($ch, $options);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Falha HTTP em ' . $url . ': ' . $error);
        }

        $decoded = null;
        if ($response !== '') {
            $decoded = json_decode($response, true);
        }

        return ['status' => $status, 'body' => $response, 'json' => $decoded];
    }

    /** @param array{status:int,body:string,json:mixed} $response */
    public function requireSuccess(array $response, string $operation): void
    {
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return;
        }

        throw new RuntimeException(sprintf('%s falhou (HTTP %d): %s', $operation, $response['status'], mb_substr($response['body'], 0, 1000)));
    }
}
