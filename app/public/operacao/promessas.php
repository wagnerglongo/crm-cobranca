<?php

declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
$destino = '/operacao/acordos.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $destino, true, 302);
exit;
