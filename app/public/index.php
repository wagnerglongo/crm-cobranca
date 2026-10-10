<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'app' => getenv('APP_NAME') ?: 'Pactua CRM',
    'env' => getenv('APP_ENV') ?: 'local',
    'status' => 'ok',
    'message' => 'Infra DEV do CRM ativa. Proximo passo: migrations e motor de cargas.',
    'time' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format(DateTimeInterface::ATOM),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
