<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoLogin();

if (usuarioPode('clientes.consultar')) {
    header('Location: /operacao/works.php');
    exit;
}

if (usuarioPode('relatorios.visualizar')) {
    header('Location: /operacao/relatorios.php');
    exit;
}

if (usuarioPode('acordos.visualizar') || usuarioPode('acordos.carteira.visualizar')) {
    header('Location: /operacao/acordos.php');
    exit;
}

http_response_code(403);
echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pactua</title><link rel="stylesheet" href="' . h(operacaoCssUrl()) . '"></head><body><main class="container"><section class="card error"><strong>Nenhum módulo operacional liberado.</strong><p>Solicite ao administrador a permissão necessária para o seu perfil.</p><p><a href="/operacao/logout.php">Sair</a></p></section></main></body></html>';
