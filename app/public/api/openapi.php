<?php

declare(strict_types=1);

require_once __DIR__ . '/_docs_auth.php';

$spec = dirname(__DIR__, 2) . '/resources/openapi/openapi-v1.json';
if (!is_file($spec) || !is_readable($spec)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => ['code' => 'OPENAPI_UNAVAILABLE', 'message' => 'A especificação OpenAPI está indisponível.']], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: inline; filename="works-crm-openapi-v1.json"');
readfile($spec);
exit;
