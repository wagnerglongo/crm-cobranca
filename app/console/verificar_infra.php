<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Health\InfrastructureHealthService;

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CRM\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

$json = in_array('--json', $argv, true);
$service = new InfrastructureHealthService(Database::pdo());
$rows = $service->verificarTudo();

if ($json) {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

printf("%-18s %-10s %-22s %-8s %s\n", 'SERVICO', 'STATUS', 'USO', 'MS', 'DETALHE');
printf("%s\n", str_repeat('-', 118));
foreach ($rows as $row) {
    printf(
        "%-18s %-10s %-22s %-8d %s\n",
        (string) ($row['servico'] ?? '-'),
        (string) ($row['status'] ?? '-'),
        (string) ($row['uso'] ?? '-'),
        (int) ($row['latencia_ms'] ?? 0),
        (string) ($row['detalhe'] ?? '')
    );
}

$falhas = array_filter($rows, static fn (array $row): bool => empty($row['ok']));
exit($falhas === [] ? 0 : 2);
