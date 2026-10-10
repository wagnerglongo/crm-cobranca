<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Services\AcmeApiClient;
use CRM\Modules\Operacao\Services\AcmeApiException;
use CRM\Modules\Operacao\Services\AcmeCatalogoContato;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('clientes.consultar');

header('Content-Type: application/json; charset=utf-8');

/** @param array<string,mixed> $payload */
function worksLocalizacaoAcmeJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $contratoId = filter_var($_GET['contrato_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($contratoId <= 0) {
        worksLocalizacaoAcmeJson(['ok' => false, 'erro' => 'Contrato não informado.'], 422);
    }

    $pdo = operacaoPdo();
    $repository = new AtendimentoAdaptorRepository($pdo);
    $contrato = $repository->buscarContrato($contratoId, usuarioEscopoCredoresAcao('consultar'));
    if ($contrato === null) {
        worksLocalizacaoAcmeJson(['ok' => false, 'erro' => 'Contrato não encontrado ou fora do seu escopo.'], 404);
    }
    requireContratoNoEscopo($contratoId, 'consultar');

    $factory = new CredorAdapterFactory($pdo, new CredorConfiguracaoRepository($pdo));
    $client = $factory->paraContrato($contrato);
    if ($client === null || strtoupper($client->codigo()) !== 'ACME' || !$client->configurado()) {
        worksLocalizacaoAcmeJson([
            'ok' => true,
            'disponivel' => false,
            'mensagem' => 'Este contrato não possui consulta de localização ACME ativa.',
            'telefones_inseridos' => 0,
            'emails_inseridos' => 0,
            'enderecos_inseridos' => 0,
        ]);
    }

    $documento = preg_replace('/\D+/', '', (string) ($contrato['pessoa_documento'] ?? '')) ?? '';
    if (!in_array(strlen($documento), [11, 14], true)) {
        worksLocalizacaoAcmeJson(['ok' => false, 'erro' => 'CPF/CNPJ inválido para consulta de localização ACME.'], 422);
    }

    // A chamada externa não deve manter a sessão PHP bloqueada.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    try {
        $payload = $client->consultarCliente($documento);
        // Código de status/tipo que o CRM não conhece -> consulta as tabelas da ACME só nessa hora.
        $sync = $repository->sincronizarLocalizacaoAcme((int) $contrato['pessoa_id'], $payload, AcmeCatalogoContato::comApi(new AcmeApiClient()));
        worksLocalizacaoAcmeJson([
            'ok' => true,
            'disponivel' => true,
            'mensagem' => 'Localização ACME consultada e conciliada com o CRM.',
            'telefones_inseridos' => (int) ($sync['telefones_inseridos'] ?? 0),
            'emails_inseridos' => (int) ($sync['emails_inseridos'] ?? 0),
            'enderecos_inseridos' => (int) ($sync['enderecos_inseridos'] ?? 0),
        ]);
    } catch (AcmeApiException $e) {
        if ($e->httpStatus() === 404) {
            worksLocalizacaoAcmeJson([
                'ok' => true,
                'disponivel' => false,
                'http_status' => 404,
                'mensagem' => 'O endpoint de localização ACME não está disponível no ambiente selecionado (HTTP 404). Os dados locais foram mantidos.',
                'telefones_inseridos' => 0,
                'emails_inseridos' => 0,
                'enderecos_inseridos' => 0,
            ]);
        }
        throw $e;
    }
} catch (AcmeApiException $e) {
    worksLocalizacaoAcmeJson([
        'ok' => false,
        'erro' => $e->getMessage(),
        'http_status' => $e->httpStatus(),
        'retryable' => $e->retryable(),
    ], $e->retryable() ? 503 : 502);
} catch (Throwable $e) {
    worksLocalizacaoAcmeJson(['ok' => false, 'erro' => $e->getMessage()], 500);
}
