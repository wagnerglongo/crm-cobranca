<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoAlgumaPermissao(['acordos.visualizar', 'acordos.carteira.visualizar', 'preventiva.visualizar']);

$situacao = trim((string) ($_GET['situacao'] ?? ''));
$vencimento = match ($situacao) {
    'vencidas', 'vencidos' => 'vencidos',
    'hoje' => 'hoje',
    'proximos' => 'proximos_7',
    default => 'todos',
};

$params = [
    'status' => 'ativos',
    'vencimento' => $vencimento,
];
if (isset($_GET['q']) && trim((string) $_GET['q']) !== '') {
    $params['q'] = trim((string) $_GET['q']);
}

header('Location: /operacao/acordos.php?' . http_build_query($params));
exit;
