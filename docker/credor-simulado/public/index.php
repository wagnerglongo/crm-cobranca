<?php

declare(strict_types=1);

/**
 * Simulador da API do credor ACME (fictício).
 *
 * Implementa o contrato descrito em docs/CREDOR_API.md para que o CRM rode de
 * ponta a ponta sem depender de nenhum sistema externo: token OAuth2
 * (password grant), consulta de devedor e contatos, propostas, fechamento de
 * acordo, boleto em PDF e envio/consulta de acionamentos.
 *
 * Os dados são gerados de forma determinística a partir do CPF/CNPJ (o mesmo
 * documento sempre devolve o mesmo devedor) e o que o CRM grava (acordos,
 * acionamentos, contatos) fica num arquivo JSON, para sobreviver a reinícios.
 *
 * Uso: php -S 0.0.0.0:8080 -t public public/index.php
 */

require __DIR__ . '/../src/Simulador.php';

$simulador = new CredorSimulado\Simulador(
    arquivoEstado: getenv('SIMULADOR_ESTADO') ?: sys_get_temp_dir() . '/credor-simulado.json',
    usuario: getenv('SIMULADOR_USUARIO') ?: 'demo',
    senha: getenv('SIMULADOR_SENHA') ?: 'demo',
);

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$corpo = file_get_contents('php://input') ?: '';
$tipo = $_SERVER['CONTENT_TYPE'] ?? '';
$autorizacao = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

[$status, $resposta] = $simulador->atender($metodo, $caminho, $corpo, $tipo, $autorizacao);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
