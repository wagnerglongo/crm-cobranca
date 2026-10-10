<?php

declare(strict_types=1);

use CRM\Config\Database;

require_once __DIR__ . '/_bootstrap.php';

$token = trim((string) stream_get_contents(STDIN));
if (!str_starts_with($token, 'wrk_api_at_') || strlen($token) < 48) {
    fwrite(STDERR, "Token de teste inválido.\n");
    exit(2);
}

$pdo = Database::pdo();
$stmt = $pdo->prepare("UPDATE api_tokens
    SET expira_em = DATE_SUB(NOW(), INTERVAL 1 SECOND)
    WHERE token_hash = :hash
      AND nome LIKE 'CRM API smoke test expiry%'
      AND revogado_em IS NULL");
$stmt->execute(['hash' => hash('sha256', $token)]);

if ($stmt->rowCount() !== 1) {
    fwrite(STDERR, "Nenhum token de expiração criado pelo smoke test foi alterado.\n");
    exit(3);
}

fwrite(STDOUT, "OK: token exclusivo do smoke test marcado como expirado.\n");
