<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoLogin();

$destino = match (true) {
    usuarioPode('estrategias.gerenciar') => '/operacao/supervisao_estrategias.php',
    usuarioPode('acordos.visualizar'), usuarioPode('acordos.carteira.visualizar'), usuarioPode('preventiva.visualizar') => '/operacao/acordos.php',
    usuarioPode('campanhas.gerenciar') => '/operacao/campanhas.php',
    usuarioPode('relatorios.visualizar') => '/operacao/relatorios.php',
    default => '/operacao/works.php',
};

header('Location: ' . $destino);
exit;
