<?php

declare(strict_types=1);

use CRM\Config\Database;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Popula um ambiente de demonstração usando a própria API REST do CRM
 * (/api/v1), do jeito que um sistema externo faria: emite um Bearer token,
 * lê as tabulações, registra acionamentos nos contratos da carga de
 * demonstração e fecha alguns acordos com o credor (no simulador).
 *
 * Pré-requisitos: carga aplicada (console/gerar_carga_demo.php +
 * console/processar_acme_diario.php) e tabulações cadastradas
 * (console/seed_tabulacoes_acme.php --confirmar).
 *
 * Uso (dentro do container php):
 *   php console/popular_demo_api.php --url=http://apache --login=admin --senha=SUA_SENHA [--contratos=60] [--semente=7]
 */

$opcoes = ['url' => 'http://apache', 'login' => 'admin', 'senha' => '', 'contratos' => 60, 'semente' => 7];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(url|login|senha|contratos|semente)=(.*)$/', $arg, $m) === 1) {
        $opcoes[$m[1]] = in_array($m[1], ['contratos', 'semente'], true) ? max(1, (int) $m[2]) : $m[2];
    } else {
        fwrite(STDERR, "Opção inválida: {$arg}\n");
        exit(2);
    }
}
if ($opcoes['senha'] === '') {
    fwrite(STDERR, "Informe --senha=... (senha do usuário {$opcoes['login']}).\n");
    exit(2);
}
mt_srand((int) $opcoes['semente']);

$base = rtrim((string) $opcoes['url'], '/') . '/api/v1';

/**
 * Chamada HTTP à API. Respeita o rate limit do próprio CRM: em 429, espera o
 * Retry-After devolvido e tenta de novo.
 * @return array{0:int,1:array<mixed>}
 */
$chamar = static function (string $metodo, string $caminho, ?array $corpo = null, ?string $token = null) use ($base): array {
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($metodo === 'POST' && $token !== null) {
        // Idempotency-Key: repetir o script não duplica acionamentos/acordos.
        $headers[] = 'Idempotency-Key: demo-' . hash('sha256', $caminho . json_encode($corpo));
    }
    for ($tentativa = 1; ; $tentativa++) {
        $retryAfter = 0;
        $ch = curl_init($base . $caminho);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $corpo === null ? null : json_encode($corpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$retryAfter): int {
                if (stripos($linha, 'Retry-After:') === 0) {
                    $retryAfter = (int) trim(substr($linha, 12));
                }
                return strlen($linha);
            },
        ]);
        $resposta = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 429 && $tentativa < 10) {
            sleep(max(1, min(60, $retryAfter)));
            continue;
        }
        $json = json_decode($resposta, true);
        return [$status, is_array($json) ? $json : ['raw' => mb_substr($resposta, 0, 300)]];
    }
};

[$status, $auth] = $chamar('POST', '/auth/token', ['login' => $opcoes['login'], 'senha' => $opcoes['senha'], 'nome' => 'Popular demonstração']);
$token = (string) ($auth['access_token'] ?? '');
if ($status !== 200 || $token === '') {
    fwrite(STDERR, "Falha ao emitir token (HTTP {$status}): " . json_encode($auth, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

[, $lista] = $chamar('GET', '/tabulacoes', null, $token);
$porCodigo = [];
foreach ((array) ($lista['data'] ?? []) as $tabulacao) {
    $porCodigo[(string) $tabulacao['codigo']] = (int) $tabulacao['id'];
}
// Distribuição parecida com a de uma operação real de cobrança.
$pesos = ['ACME_183' => 30, 'ACME_003' => 18, 'ACME_191' => 8, 'ACME_004' => 10, 'ACME_006' => 14, 'ACME_010' => 6, 'ACME_181' => 3, 'ACME_190' => 5, 'ACME_189' => 6];
$pesos = array_filter($pesos, static fn (string $codigo): bool => isset($porCodigo[$codigo]), ARRAY_FILTER_USE_KEY);
if ($pesos === []) {
    fwrite(STDERR, "Nenhuma tabulação ACME encontrada. Rode antes: php console/seed_tabulacoes_acme.php --confirmar\n");
    exit(1);
}
$sortear = static function () use ($pesos): string {
    $alvo = mt_rand(1, array_sum($pesos));
    foreach ($pesos as $codigo => $peso) {
        $alvo -= $peso;
        if ($alvo <= 0) {
            return $codigo;
        }
    }
    return array_key_first($pesos);
};

$pdo = Database::pdo();
$contratos = $pdo->query(
    "SELECT c.codigo_contrato, p.documento,
            (SELECT pc.id FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id AND pc.tipo = 'telefone' ORDER BY pc.principal DESC, pc.id LIMIT 1) AS contato_id
     FROM contratos c JOIN pessoas p ON p.id = c.pessoa_id
     JOIN credores cr ON cr.id = c.credor_id WHERE cr.codigo = 'ACME' ORDER BY c.id LIMIT " . (int) $opcoes['contratos']
)->fetchAll();

$observacoes = [
    'ACME_183' => 'Chamou até cair na caixa postal.',
    'ACME_003' => 'Discador sem atendimento.',
    'ACME_191' => 'Linha ocupada nas três tentativas.',
    'ACME_004' => 'Recado deixado com familiar.',
    'ACME_006' => 'Cliente pediu para pensar e retornar.',
    'ACME_010' => 'Cliente sem interesse no momento.',
    'ACME_181' => 'Cliente diz não reconhecer o contrato.',
    'ACME_190' => 'Número inexistente.',
    'ACME_189' => 'Retornar no fim da tarde.',
];

$acionamentos = 0;
$acordos = 0;
$falhas = 0;
foreach ($contratos as $i => $contrato) {
    $codigo = (string) $contrato['codigo_contrato'];
    $tentativas = mt_rand(1, 3);
    for ($t = 0; $t < $tentativas; $t++) {
        $resultado = $sortear();
        [$status, $retornoAcionamento] = $chamar('POST', '/acionamentos/contrato/' . rawurlencode($codigo), [
            'resultado_id' => $porCodigo[$resultado],
            'canal' => $resultado === 'ACME_004' ? 'ligacao' : (mt_rand(0, 4) === 0 ? 'whatsapp' : 'ligacao'),
            'observacao' => $observacoes[$resultado] ?? 'Acionamento de demonstração.',
            'contato_id' => $contrato['contato_id'] !== null ? (int) $contrato['contato_id'] : null,
            'motivo_inadimplencia_id' => mt_rand(1, 5),
            // Tabulações que pedem reagendamento recebem um retorno para o dia seguinte.
            'proximo_acionamento' => (new DateTimeImmutable('tomorrow 10:00'))->modify('+' . mt_rand(0, 6) . ' hours')->format('Y-m-d H:i:s'),
        ], $token);
        if ($status >= 200 && $status < 300) {
            $acionamentos++;
        } else {
            $falhas++;
            fwrite(STDERR, "Acionamento {$codigo}: HTTP {$status} " . json_encode($retornoAcionamento, JSON_UNESCAPED_UNICODE) . "\n");
        }
    }

    // Um em cada seis contratos fecha acordo com o credor (simulador).
    if ($i % 6 === 0) {
        $documento = (string) $contrato['documento'];
        [$status, $simulacao] = $chamar('GET', '/acme/documento/' . $documento . '/contrato/' . rawurlencode($codigo) . '/simulacoes', null, $token);
        $proposta = $simulacao['data']['propostas'][mt_rand(0, 2)] ?? null;
        $data = $simulacao['data']['datas_primeira_parcela'][0]['data_primeira_parcela'] ?? null;
        if ($status === 200 && is_array($proposta) && $data !== null) {
            [$status, $retorno] = $chamar('POST', '/acme/documento/' . $documento . '/contrato/' . rawurlencode($codigo) . '/acordos', [
                'negotiation_id' => (int) $proposta['negotiation_id'],
                'phone_id' => 0,
                'data_primeira_parcela' => (string) $data,
                'forma_pagamento' => 'boleto',
                'observacao' => 'Acordo de demonstração: ' . ($proposta['descricao'] ?? ''),
            ], $token);
            if ($status === 201) {
                $acordos++;
            } else {
                $falhas++;
                fwrite(STDERR, "Acordo {$codigo}: HTTP {$status} " . json_encode($retorno, JSON_UNESCAPED_UNICODE) . "\n");
            }
        }
    }
}

echo "Contratos acionados: " . count($contratos) . "\n";
echo "Acionamentos registrados: {$acionamentos}\n";
echo "Acordos fechados no credor: {$acordos}\n";
echo "Falhas: {$falhas}\n";
exit($falhas > 0 ? 1 : 0);
