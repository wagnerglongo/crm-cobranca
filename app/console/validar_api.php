<?php

declare(strict_types=1);

$specPath = __DIR__ . '/../resources/openapi/openapi-v1.json';
$raw = is_file($specPath) ? file_get_contents($specPath) : false;
if ($raw === false) {
    fwrite(STDERR, "ERRO: OpenAPI não encontrado em {$specPath}.\n");
    exit(1);
}

try {
    $spec = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "ERRO: JSON inválido: {$e->getMessage()}\n");
    exit(2);
}

if (!is_array($spec) || ($spec['openapi'] ?? null) !== '3.1.0') {
    fwrite(STDERR, "ERRO: a especificação deve usar OpenAPI 3.1.0.\n");
    exit(3);
}

$required = [
    'GET /health',
    'GET /health/components',
    'POST /auth/token',
    'GET /auth/me',
];
$operations = [];
$operationIds = [];
foreach (($spec['paths'] ?? []) as $path => $pathItem) {
    if (!is_array($pathItem)) {
        continue;
    }
    foreach ($pathItem as $method => $operation) {
        $method = strtoupper((string) $method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || !is_array($operation)) {
            continue;
        }
        $operations[] = $method . ' ' . $path;
        $operationId = trim((string) ($operation['operationId'] ?? ''));
        if ($operationId === '') {
            fwrite(STDERR, "ERRO: operationId ausente em {$method} {$path}.\n");
            exit(4);
        }
        if (isset($operationIds[$operationId])) {
            fwrite(STDERR, "ERRO: operationId duplicado: {$operationId}.\n");
            exit(5);
        }
        $operationIds[$operationId] = true;
        if (($operation['x-implementation-status'] ?? null) !== 'implemented') {
            fwrite(STDERR, "ERRO: status de implementação ausente em {$method} {$path}.\n");
            exit(6);
        }
    }
}

foreach ($required as $operation) {
    if (!in_array($operation, $operations, true)) {
        fwrite(STDERR, "ERRO: endpoint obrigatório ausente da especificação: {$operation}.\n");
        exit(7);
    }
}

foreach (['POST /auth/refresh', 'POST /auth/revoke'] as $removed) {
    if (in_array($removed, $operations, true)) {
        fwrite(STDERR, "ERRO: endpoint removido ainda presente na especificação: {$removed}.\n");
        exit(7);
    }
}

/** @param mixed $node */
$validateRefs = function (mixed $node) use (&$validateRefs, $spec): void {
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
            $target = $spec;
            foreach (explode('/', substr($value, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!is_array($target) || !array_key_exists($segment, $target)) {
                    throw new RuntimeException('Referência inexistente: ' . $value);
                }
                $target = $target[$segment];
            }
        }
        $validateRefs($value);
    }
};

try {
    $validateRefs($spec);
} catch (RuntimeException $e) {
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(8);
}

printf("OK: OpenAPI %s válido; %d endpoints implementados; %d operationIds únicos.\n", $spec['openapi'], count($operations), count($operationIds));
