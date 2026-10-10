<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('clientes.consultar');

function worksBoletoJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function worksBoletoLinha(array $boleto): string
{
    $linha = trim((string) ($boleto['linha_digitavel'] ?? $boleto['DigitableLine'] ?? ''));
    return preg_replace('/^Linha\s+digit[aá]vel\s*:\s*/iu', '', $linha) ?? $linha;
}

function worksBoletoFilename(array $boleto, int $installmentId): string
{
    $filename = trim((string) ($boleto['filename'] ?? $boleto['FileName'] ?? ('Boleto_ACME_' . $installmentId . '.pdf')));
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? ('Boleto_ACME_' . $installmentId . '.pdf');
    if (!str_ends_with(strtolower($filename), '.pdf')) {
        $filename .= '.pdf';
    }
    return $filename;
}

try {
    $contratoId = filter_var($_GET['contrato_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $installmentId = filter_var($_GET['installment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $modo = (string) ($_GET['modo'] ?? 'json');
    $podeCopiar = usuarioEhAdminOperacao() || usuarioPode('boletos.copiar') || usuarioPode('acordos.visualizar');
    $podeBaixar = usuarioEhAdminOperacao() || usuarioPode('boletos.baixar') || usuarioPode('acordos.visualizar');
    if ($modo === 'download' && !$podeBaixar) {
        worksBoletoJson(['ok' => false, 'erro' => 'Seu perfil não possui permissão para baixar boleto.'], 403);
    }
    if ($modo !== 'download' && !$podeCopiar) {
        worksBoletoJson(['ok' => false, 'erro' => 'Seu perfil não possui permissão para copiar linha digitável.'], 403);
    }

    if ($contratoId <= 0 || $installmentId <= 0) {
        worksBoletoJson(['ok' => false, 'erro' => 'Contrato ou parcela inválida.'], 422);
    }

    $pdo = operacaoPdo();
    $repository = new AtendimentoAdaptorRepository($pdo);
    $contrato = $repository->buscarContrato($contratoId, usuarioEscopoCredoresAcao('consultar'));
    if ($contrato === null) {
        worksBoletoJson(['ok' => false, 'erro' => 'Contrato não encontrado ou fora do seu escopo.'], 404);
    }
    requireContratoNoEscopo($contratoId, 'consultar');

    $credorCodigo = strtoupper(trim((string) ($contrato['credor_codigo'] ?? '')));
    if ($credorCodigo !== 'ACME') {
        worksBoletoJson(['ok' => false, 'erro' => 'Boleto por API disponível somente para contratos ACME neste momento.'], 422);
    }

    $cache = $repository->obterBoletoAcmeCache($contratoId, $installmentId);
    if ($cache === [] || trim((string) ($cache['linha_digitavel'] ?? '')) === '' || ($modo === 'download' && trim((string) ($cache['base64'] ?? '')) === '')) {
        $factory = new CredorAdapterFactory($pdo, new CredorConfiguracaoRepository($pdo));
        $client = $factory->paraContrato($contrato);
        if ($client === null || !$client->configurado()) {
            worksBoletoJson(['ok' => false, 'erro' => 'Integração do credor não configurada para boleto.'], 503);
        }
        $boletoApi = $client->consultarBoleto($installmentId);
        $cache = $repository->salvarBoletoAcmeCache($contratoId, $installmentId, $boletoApi, usuarioAtualId(), usuarioAtualNome());
    }

    $linha = worksBoletoLinha($cache);
    $filename = worksBoletoFilename($cache, $installmentId);

    if ($modo === 'download') {
        $repository->registrarAcaoBoletoAcme($contratoId, $installmentId, 'download_pdf', usuarioAtualId(), usuarioAtualNome());
        registrarAuditoriaSistema('boleto_acme_download', 'contrato', $contratoId, 'Boleto ACME baixado no Pactua.', null, [
            'installment_id' => $installmentId,
            'filename' => $filename,
        ]);

        $base64 = trim((string) ($cache['base64'] ?? $cache['Base64'] ?? ''));
        if ($base64 === '') {
            worksBoletoJson(['ok' => false, 'erro' => 'A ACME retornou o boleto sem PDF/Base64.'], 502);
        }
        $pdf = base64_decode($base64, true);
        if ($pdf === false || $pdf === '') {
            worksBoletoJson(['ok' => false, 'erro' => 'PDF/Base64 inválido retornado pela ACME.'], 502);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf;
        exit;
    }

    $repository->registrarAcaoBoletoAcme($contratoId, $installmentId, 'copiar_linha_digitavel', usuarioAtualId(), usuarioAtualNome());
    registrarAuditoriaSistema('boleto_acme_linha_copiada', 'contrato', $contratoId, 'Linha digitável ACME consultada/copiadada no Pactua.', null, [
        'installment_id' => $installmentId,
        'tem_linha' => $linha !== '',
    ]);

    worksBoletoJson([
        'ok' => true,
        'installment_id' => $installmentId,
        'filename' => $filename,
        'linha_digitavel' => $linha,
        'tem_pdf' => trim((string) ($cache['base64'] ?? $cache['Base64'] ?? '')) !== '',
        'cache' => true,
        'atualizado_em' => (string) ($cache['atualizado_em'] ?? ''),
    ]);
} catch (Throwable $e) {
    worksBoletoJson(['ok' => false, 'erro' => $e->getMessage()], 500);
}
