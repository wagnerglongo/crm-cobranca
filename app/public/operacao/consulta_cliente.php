<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('clientes.consultar');

header('Location: /operacao/works.php', true, 302);
exit;
