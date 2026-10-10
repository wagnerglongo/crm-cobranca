<?php

declare(strict_types=1);

/*
 * Saída pro Grafana/Prometheus (requisito de 2026-10-06). O conteúdo é
 * gerado a cada minuto pelo cron da VM (scripts/exportar-metricas.sh); aqui só
 * é entregue, com token. Se a VM cair, o scrape falha e o alerta dispara do
 * lado do Grafana - é o monitor externo que faltava.
 *
 * Prometheus: authorization: { type: Bearer, credentials: <storage/metrics/token> }
 * Ver docs/integracoes/grafana/README.md.
 */

$base = '/var/www/storage/metrics';
$tokenEsperado = trim((string) @file_get_contents($base . '/token'));
$autorizacao = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if ($tokenEsperado === '' || !hash_equals('Bearer ' . $tokenEsperado, trim($autorizacao))) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer');
    exit;
}

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store');

$arquivo = $base . '/crm.prom';
$conteudo = @file_get_contents($arquivo);
if ($conteudo === false) {
    http_response_code(503);
    echo "# o coletor (scripts/exportar-metricas.sh) ainda não gerou métricas\n";
    exit;
}

echo $conteudo;
echo "# HELP crm_metricas_idade_segundos Idade do arquivo de métricas (coletor parado se crescer)\n";
echo "# TYPE crm_metricas_idade_segundos gauge\n";
echo 'crm_metricas_idade_segundos ' . max(0, time() - (int) @filemtime($arquivo)) . "\n";
