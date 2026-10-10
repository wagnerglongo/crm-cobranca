<?php

declare(strict_types=1);

$worksFinanceStartedAt = microtime(true);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('clientes.consultar');

header('Content-Type: application/json; charset=utf-8');

function worksFinanceiroJson(array $payload, int $status = 200): never
{
    global $worksFinanceStartedAt;
    http_response_code($status);
    if (!headers_sent()) {
        header('Server-Timing: works-finance;dur=' . number_format((microtime(true) - $worksFinanceStartedAt) * 1000, 1, '.', ''));
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return list<array<string,mixed>> */
function worksList(array $payload, string $key): array
{
    $value = $payload[$key] ?? [];
    return is_array($value) ? array_values(array_filter($value, static fn ($item): bool => is_array($item))) : [];
}

function worksDateLabel(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('d/m/Y');
    } catch (Throwable) {
        return substr($raw, 0, 10);
    }
}

function worksDateValue(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d');
    } catch (Throwable) {
        return substr($raw, 0, 10);
    }
}

function worksCacheTimestamp(mixed $value): ?int
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $timestamp = strtotime($raw);
    return $timestamp === false ? null : $timestamp;
}

function worksCacheFresh(mixed $value, int $seconds): bool
{
    $timestamp = worksCacheTimestamp($value);
    return $timestamp !== null && (time() - $timestamp) >= 0 && (time() - $timestamp) < $seconds;
}

function worksCacheFromToday(mixed $value): bool
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return false;
    }

    try {
        $timezone = new DateTimeZone((string) Database::env('APP_TIMEZONE', 'America/Sao_Paulo'));
        $cached = new DateTimeImmutable($raw, $timezone);
        $today = new DateTimeImmutable('today', $timezone);
        return $cached->setTimezone($timezone)->format('Y-m-d') === $today->format('Y-m-d');
    } catch (Throwable) {
        return false;
    }
}

function worksContratoRetiradoCredor(array $contrato): bool
{
    if ((int) ($contrato['retirado_credor'] ?? 0) === 1) {
        return true;
    }

    $status = mb_strtolower(trim((string) ($contrato['status_contrato'] ?? '')));
    $status = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $status) ?: $status;
    return str_contains($status, 'retirado')
        || str_contains($status, 'devolvido')
        || str_contains($status, 'fora da carga')
        || str_contains($status, 'fora_de_carga');
}

/** @return array{base:?int,atual:?int,data_base:?string,dias_projetados:int,fonte:string,label:string,nota:string} */
function worksAtrasoContrato(array $contrato): array
{
    $baseRaw = $contrato['dias_atraso'] ?? null;
    $base = is_numeric($baseRaw) ? (int) $baseRaw : null;
    $dataBase = trim((string) ($contrato['ultima_carga_data_referencia'] ?? ''));
    if ($dataBase === '') {
        $dataBase = trim((string) ($contrato['ultima_carga_finalizado_em'] ?? ''));
    }
    if ($dataBase === '') {
        $dataBase = trim((string) ($contrato['atualizado_em'] ?? ''));
    }

    $dataBaseIso = null;
    $diasProjetados = 0;
    if ($base !== null && $dataBase !== '') {
        try {
            $baseDate = (new DateTimeImmutable($dataBase))->setTime(0, 0);
            $today = (new DateTimeImmutable('today'))->setTime(0, 0);
            $dataAlvo = worksContratoRetiradoCredor($contrato) ? $baseDate : $today;
            $dataBaseIso = $baseDate->format('Y-m-d');
            if ($baseDate < $dataAlvo) {
                $diasProjetados = max(0, (int) $baseDate->diff($dataAlvo)->format('%a'));
            }
        } catch (Throwable) {
            $dataBaseIso = null;
            $diasProjetados = 0;
        }
    }

    $atual = $base === null ? null : $base + $diasProjetados;
    $nota = '';
    if ($base !== null) {
        if (worksContratoRetiradoCredor($contrato) && $dataBaseIso !== null) {
            $nota = 'Contrato retirado pelo credor. Atraso congelado na última carga em que apareceu: ' . dataBr($dataBaseIso) . '.';
        } elseif ($diasProjetados > 0 && $dataBaseIso !== null) {
            $nota = 'Base ' . $base . ' dia(s) na carga de ' . dataBr($dataBaseIso) . ' + ' . $diasProjetados . ' dia(s) sem carga nova.';
        } elseif ($dataBaseIso !== null) {
            $nota = 'Atraso conforme carga/base de ' . dataBr($dataBaseIso) . '.';
        } else {
            $nota = 'Atraso conforme base.';
        }
    }

    return [
        'base' => $base,
        'atual' => $atual,
        'data_base' => $dataBaseIso,
        'dias_projetados' => $diasProjetados,
        'fonte' => worksContratoRetiradoCredor($contrato) ? 'retirado_credor' : ($diasProjetados > 0 ? 'projetado' : 'base'),
        'label' => $atual === null ? '-' : (string) $atual,
        'nota' => $nota,
    ];
}


/** @param array<string,mixed>|null $acordoInfo @param array{base:?int,atual:?int,data_base:?string,dias_projetados:int,fonte:string,label:string,nota:string} $atraso */
function worksCongelarAtrasoPorAcordo(array $atraso, ?array $acordoInfo): array
{
    if ($acordoInfo === null || trim((string) ($acordoInfo['id'] ?? '')) === '') {
        return $atraso;
    }

    $dataAcordoRaw = trim((string) ($acordoInfo['data_acordo'] ?? ''));
    if ($dataAcordoRaw === '' || str_starts_with($dataAcordoRaw, '0001-01-01')) {
        return $atraso;
    }

    $base = $atraso['base'] ?? null;
    $dataBaseRaw = trim((string) ($atraso['data_base'] ?? ''));
    if (!is_int($base) || $dataBaseRaw === '') {
        return $atraso;
    }

    try {
        $dataBase = (new DateTimeImmutable($dataBaseRaw))->setTime(0, 0);
        $dataAcordo = (new DateTimeImmutable($dataAcordoRaw))->setTime(0, 0);
        $hoje = (new DateTimeImmutable('today'))->setTime(0, 0);
        if ($dataAcordo > $hoje) {
            $dataAcordo = $hoje;
        }
    } catch (Throwable) {
        return $atraso;
    }

    $diasAteAcordo = 0;
    if ($dataBase < $dataAcordo) {
        $diasAteAcordo = max(0, (int) $dataBase->diff($dataAcordo)->format('%a'));
    }

    $atual = $base + $diasAteAcordo;
    $atraso['atual'] = $atual;
    $atraso['label'] = (string) $atual;
    $atraso['dias_projetados'] = $diasAteAcordo;
    $atraso['fonte'] = 'acordo';
    $atraso['nota'] = 'Atraso congelado no acordo de ' . dataBr($dataAcordo->format('Y-m-d')) . ': base ' . $base . ' dia(s) na carga de ' . dataBr($dataBase->format('Y-m-d')) . ' + ' . $diasAteAcordo . ' dia(s) até o acordo.';

    return $atraso;
}
function worksParseMoneyFromDescription(string $description): ?float
{
    if (!preg_match_all('/(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}|\d+\.\d{2})/', $description, $matches) || empty($matches[1])) {
        return null;
    }

    $raw = (string) end($matches[1]);
    if (str_contains($raw, ',')) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }

    $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
    return $value === false ? null : (float) $value;
}

function worksParseParcelasFromDescription(string $description): int
{
    if (preg_match('/a\s*vista|à\s*vista/iu', $description)) {
        return 1;
    }

    if (preg_match('/\b(\d+)\s*\+\s*(\d+)\b/u', $description, $m)) {
        return max(1, (int) $m[1] + (int) $m[2]);
    }

    if (preg_match('/\b(\d+)\s*x\b/iu', $description, $m)) {
        return max(1, (int) $m[1]);
    }

    return 1;
}

/** @param array<string,mixed> $cache @return array<string,mixed> */
function worksPayloadFromCache(array $cache, array $contrato): array
{
    $lists = $cache['proposal_payload'] ?? [];
    $lists = is_array($lists) ? $lists : [];

    return array_merge($lists, [
        'DocNumber' => (string) ($cache['doc_number'] ?? $contrato['pessoa_documento'] ?? ''),
        'Amount' => $cache['amount'] ?? ($contrato['saldo_atual'] ?? null),
        'Provider' => (string) ($cache['provider'] ?? ''),
        'ProviderID' => $cache['provider_id'] ?? null,
        'OwnerDescription' => (string) ($cache['owner_description'] ?? ''),
        'Contract' => (string) ($cache['contract'] ?? $contrato['codigo_contrato'] ?? ''),
        'DebtDate' => (string) ($cache['debt_date'] ?? ''),
        'MoreDetail' => (string) ($cache['more_detail'] ?? ''),
        'CustomProposalTag' => (string) ($cache['custom_proposal_tag'] ?? ''),
        'BindingID' => (string) ($cache['binding_id'] ?? ''),
    ]);
}



/** @return array<string,mixed> */
function worksNormalizeAgreementPayload(array $payload): array
{
    // A API ACME normalmente retorna Debt/Agreement no topo.
    if (is_array($payload['Agreement'] ?? null) || is_array($payload['Debt'] ?? null)) {
        return $payload;
    }

    // Cache local salvo em contratos.payload_extra: acme_acordo.raw.
    if (is_array($payload['raw'] ?? null)) {
        $raw = $payload['raw'];
        if (is_array($raw['Agreement'] ?? null) || is_array($raw['Debt'] ?? null)) {
            return $raw;
        }
    }

    // Cache compacto salvo em contratos.payload_extra: acme_acordo.agreement / acme_acordo.debt.
    if (is_array($payload['agreement'] ?? null) || is_array($payload['debt'] ?? null)) {
        $normalizado = [
            'HasAgreementHistory' => (bool) ($payload['has_agreement_history'] ?? false),
            'Debt' => is_array($payload['debt'] ?? null) ? $payload['debt'] : [],
            'Agreement' => is_array($payload['agreement'] ?? null) ? $payload['agreement'] : [],
        ];
        if (is_array($payload['raw'] ?? null)) {
            $normalizado['raw'] = $payload['raw'];
        }
        return $normalizado;
    }

    // Alguns retornos/diagnosticos podem vir dentro de acme_acordo.
    if (is_array($payload['acme_acordo'] ?? null)) {
        return worksNormalizeAgreementPayload($payload['acme_acordo']);
    }

    return $payload;
}

/** @param array<string,mixed> $payload */
function worksAgreementNotFoundPayload(array $payload): bool
{
    $normalizado = worksNormalizeAgreementPayload($payload);
    if (($normalizado['agreement_not_found'] ?? false) === true) {
        return true;
    }

    $message = trim((string) ($normalizado['Message'] ?? $normalizado['message'] ?? ''));
    $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
    $message = rtrim($message, ". \t\n\r\0\x0B");
    return mb_strtolower($message) === mb_strtolower('Dados do acordo não encontrados');
}

/** @return array<string,mixed> */
function worksAgreementDiagnostics(array $payload): array
{
    $normalizado = worksNormalizeAgreementPayload($payload);
    $agreement = is_array($normalizado['Agreement'] ?? null) ? $normalizado['Agreement'] : [];
    $debt = is_array($normalizado['Debt'] ?? null) ? $normalizado['Debt'] : [];
    return [
        'tem_payload' => $payload !== [],
        'nao_encontrado' => worksAgreementNotFoundPayload($payload),
        'mensagem' => (string) ($normalizado['Message'] ?? $normalizado['message'] ?? ''),
        'tem_debt' => $debt !== [],
        'tem_agreement' => $agreement !== [],
        'agreement_id' => (string) ($agreement['AgreementID'] ?? ''),
        'agreement_status' => (string) ($agreement['AgreementStatus'] ?? ''),
        'contract' => (string) ($debt['Contract'] ?? ''),
    ];
}

function worksAcmeDateOrNull(mixed $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '' || str_starts_with($raw, '0001-01-01') || str_starts_with($raw, '0000-00-00')) {
        return null;
    }
    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d');
    } catch (Throwable) {
        return substr($raw, 0, 10) ?: null;
    }
}

/** @return array{pagas:int,total:int} */
function worksAcmeTotalParcelas(mixed $value, array $installments): array
{
    $raw = trim((string) $value);
    if (preg_match('/(\d+)\s*\/\s*(\d+)/', $raw, $m)) {
        return ['pagas' => max(0, (int) $m[1]), 'total' => max(1, (int) $m[2])];
    }

    return ['pagas' => 0, 'total' => max(1, count($installments))];
}


/** @return array<string,mixed> */
function worksContratoPayloadExtra(array $contrato): array
{
    $raw = $contrato['payload_extra'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<string,mixed> */
function worksBoletoAcmeCacheContrato(array $contrato, int $installmentId): array
{
    if ($installmentId <= 0) {
        return [];
    }
    $extra = worksContratoPayloadExtra($contrato);
    $boletos = is_array($extra['acme_boletos'] ?? null) ? $extra['acme_boletos'] : [];
    $cache = $boletos[(string) $installmentId] ?? $boletos[$installmentId] ?? [];
    return is_array($cache) ? $cache : [];
}

function worksStatusParcelaAcmeLabel(string $status, ?string $vencimento, ?string $pagamento): array
{
    $statusLower = mb_strtolower(trim($status));
    if ($pagamento !== null || str_contains($statusLower, 'pago') || str_contains($statusLower, 'liquid')) {
        return ['label' => 'Pago', 'classe' => 'pago', 'boleto' => false, 'motivo' => 'Parcela paga.'];
    }

    if (str_contains($statusLower, 'abert') || str_contains($statusLower, 'pendente') || str_contains($statusLower, 'promessa')) {
        $classe = 'aberta';
        $label = 'Aberta';
        if ($vencimento !== null) {
            try {
                $venc = (new DateTimeImmutable($vencimento))->setTime(0, 0);
                $hoje = (new DateTimeImmutable('today'))->setTime(0, 0);
                if ($venc < $hoje) {
                    $classe = 'vencida';
                    $label = 'Vencida';
                }
            } catch (Throwable) {
            }
        }
        return ['label' => $label, 'classe' => $classe, 'boleto' => true, 'motivo' => 'Boleto disponível somente para a parcela atual liberada pela ACME.'];
    }

    if ($statusLower === '' || str_contains($statusLower, 'aguard')) {
        return ['label' => 'Indisponível', 'classe' => 'indisponivel', 'boleto' => false, 'motivo' => 'Parcela ainda não liberada/registrada pela ACME para emissão de boleto.'];
    }

    return ['label' => $status, 'classe' => 'indisponivel', 'boleto' => false, 'motivo' => 'Status sem liberação de boleto pela ACME.'];
}

/** @return array<string,mixed>|null */
function worksBuildAgreementInfo(array $payload, array $contrato): ?array
{
    $info = AcordoOperacionalRepository::interpretarAcordoAcme($payload);
    if ($info === null) {
        return null;
    }

    $parcelasPorNumero = [];
    foreach ((array) ($info['parcelas'] ?? []) as $parcela) {
        if (!is_array($parcela)) {
            continue;
        }
        $numero = max(1, (int) ($parcela['numero'] ?? (count($parcelasPorNumero) + 1)));
        $parcelasPorNumero[$numero] = $parcela;
    }

    $parcelas = [];
    $totalParcelas = max(1, (int) ($info['total_parcelas'] ?? 1), count($parcelasPorNumero));
    $pagaPorLiquidacao = (string) ($info['status_operacional'] ?? '') === 'cumprido'
        && (bool) ($info['divida_liquidada'] ?? false);
    for ($numero = 1; $numero <= $totalParcelas; $numero++) {
        $parcela = $parcelasPorNumero[$numero] ?? [
            'numero' => $numero,
            'descricao' => $numero . '/' . $totalParcelas,
            'valor' => null,
            'vencimento' => null,
            'pagamento' => null,
            'deadline' => null,
            'status' => $pagaPorLiquidacao ? 'Liquidada' : 'Aguardando registro ACME',
            'status_operacional' => $pagaPorLiquidacao ? 'paga' : 'pendente',
            'status_label' => $pagaPorLiquidacao ? 'Paga' : 'Aguardando liberação',
            'status_classe' => $pagaPorLiquidacao ? 'pago' : 'indisponivel',
            'status_motivo' => $pagaPorLiquidacao
                ? 'Pagamento confirmado pela situação liquidada da dívida na ACME.'
                : 'Parcela ainda não liberada/registrada pela ACME.',
            'deadline_expirada' => false,
            'installment_id' => null,
            'valor_pago' => null,
            'raw' => [],
        ];
        $installmentId = (int) ($parcela['installment_id'] ?? 0);
        $vencimento = $parcela['vencimento'] ?? null;
        $pagamento = $parcela['pagamento'] ?? null;
        $deadline = $parcela['deadline'] ?? null;
        $statusOperacional = (string) ($parcela['status_operacional'] ?? 'pendente');
        $boletoDisponivel = $installmentId > 0
            && in_array($statusOperacional, ['pendente', 'vencida'], true)
            && !((bool) ($parcela['deadline_expirada'] ?? false))
            && (string) ($info['status_operacional'] ?? '') !== 'cumprido';
        $boletoCache = worksBoletoAcmeCacheContrato($contrato, $installmentId);
        $linhaCache = trim((string) ($boletoCache['linha_digitavel'] ?? ''));
        $raw = is_array($parcela['raw'] ?? null) ? $parcela['raw'] : [];

        $parcelas[] = array_replace($parcela, [
            'vencimento_label' => $vencimento !== null ? dataBr($vencimento) : '-',
            'pagamento_label' => $pagamento !== null ? dataBr($pagamento) : '-',
            'deadline_label' => $deadline !== null ? dataBr($deadline) : '-',
            'installment_id' => $installmentId > 0 ? $installmentId : null,
            'invoice_number' => trim((string) ($raw['InvoiceNumber'] ?? '')),
            'alias' => trim((string) ($raw['Alias'] ?? '')),
            'boleto_disponivel' => $boletoDisponivel,
            'boleto_linha_digitavel' => $linhaCache,
            'boleto_cache_atualizado_em' => (string) ($boletoCache['atualizado_em'] ?? ''),
            'boleto_motivo' => $boletoDisponivel
                ? 'Boleto disponível para a parcela atual liberada pela ACME.'
                : (string) ($parcela['status_motivo'] ?? 'Boleto indisponível para esta situação.'),
            'boleto_download_url' => $boletoDisponivel ? '/operacao/works_boleto_acme.php?' . http_build_query(['contrato_id' => (int) $contrato['id'], 'installment_id' => $installmentId, 'modo' => 'download']) : '',
            'boleto_json_url' => $boletoDisponivel ? '/operacao/works_boleto_acme.php?' . http_build_query(['contrato_id' => (int) $contrato['id'], 'installment_id' => $installmentId, 'modo' => 'json']) : '',
            'observacao' => (string) ($parcela['status_motivo'] ?? ''),
        ]);
    }

    $info['data_acordo_label'] = dataBr($info['data_acordo'] ?? null);
    $info['debt']['contract'] = (string) ($info['debt']['contract'] ?? $contrato['codigo_contrato'] ?? '');
    $info['parcelas'] = $parcelas;
    return $info;
}

function worksStatusOperacionalAcme(?array $acordoInfo): string
{
    return (string) ($acordoInfo['status_operacional'] ?? 'formalizado');
}

function worksAcordoAcmeVigente(?array $acordoInfo): bool
{
    return $acordoInfo !== null && (bool) ($acordoInfo['impede_nova_proposta'] ?? false);
}

/** @return array<string,mixed> */
function worksPayloadBaseFromAgreement(array $agreementPayload, array $contrato): array
{
    $payload = worksNormalizeAgreementPayload($agreementPayload);
    $debt = is_array($payload['Debt'] ?? null) ? $payload['Debt'] : [];

    return [
        'DocNumber' => (string) ($contrato['pessoa_documento'] ?? ''),
        'Amount' => isset($debt['DebtAmount']) && is_numeric($debt['DebtAmount']) ? (float) $debt['DebtAmount'] : ($contrato['saldo_atual'] ?? null),
        'Provider' => '',
        'ProviderID' => null,
        'OwnerDescription' => (string) ($debt['OwnerDescription'] ?? ''),
        'Contract' => (string) ($debt['Contract'] ?? ($contrato['codigo_contrato'] ?? '')),
        'DebtDate' => (string) ($debt['DebtDate'] ?? ''),
        'MoreDetail' => (string) ($debt['MoreDetail'] ?? ''),
        'CustomProposalTag' => '',
        'BindingID' => (string) ($debt['Contract'] ?? ($contrato['codigo_contrato'] ?? '')),
        'ListProposalType' => [],
        'ListInstallmentDate' => [],
        'ListAgreementType' => [],
        'ListPaymentType' => [],
        'ListHowReceiveBankSlip' => [],
    ];
}

/** @return array<string,mixed> */
function worksRegistrarAcordoAcmeNoCrm(AcordoOperacionalRepository $acordoRepository, array $contrato, ?array $acordoInfo): array
{
    if ($acordoInfo === null) {
        return ['acordo_crm' => 'sem_acordo_vigente'];
    }
    $raw = is_array($acordoInfo['raw'] ?? null) ? $acordoInfo['raw'] : [];
    return $acordoRepository->sincronizarAcordoAcme($contrato, $raw);
}

/** @param array<string,mixed> $payload @param array<string,mixed> $sync @return array<string,mixed> */
function worksBuildAcmeResponse(array $payload, array $contrato, array $atrasoContrato, bool $apiDisponivel, string $mensagem, string $fonte, array $sync = [], ?string $apiErro = null, array $agreementPayload = []): array
{
    global $temAcordoCrmAtivo;
    $acordoAtual = worksBuildAgreementInfo($agreementPayload, $contrato);
    if (($temAcordoCrmAtivo ?? false) === true) {
        if ($acordoAtual === null) {
            // Existe acordo pendente/formalizado no CRM, mas nunca foi
            // confirmado pela ACME (ex.: criado direto na ficha do
            // cliente, sem integração) - sem dados de parcelas/valor da
            // ACME pra mostrar, só o suficiente pra esconder a simulação.
            $acordoAtual = [
                'id' => '',
                'tem_acordo_detalhado' => false,
                'status_operacional' => 'formalizado',
                'status_label' => 'Acordo ativo no CRM',
                'status_motivo' => 'Existe um acordo pendente/formalizado registrado no CRM para este contrato, ainda não confirmado pela ACME.',
                'impede_nova_proposta' => true,
                'divida_liquidada' => false,
                'parcelas' => [],
            ];
        } else {
            $acordoAtual['impede_nova_proposta'] = true;
        }
    }
    $atrasoContrato = worksCongelarAtrasoPorAcordo($atrasoContrato, $acordoAtual);
    $amount = isset($payload['Amount']) && is_numeric($payload['Amount']) ? (float) $payload['Amount'] : null;
    if ($amount === null && is_array($acordoAtual['debt'] ?? null) && isset($acordoAtual['debt']['amount']) && is_numeric($acordoAtual['debt']['amount'])) {
        $amount = (float) $acordoAtual['debt']['amount'];
    }

    $propostas = [];
    foreach (worksList($payload, 'ListProposalType') as $item) {
        $description = trim((string) ($item['ProposalTypeDescription'] ?? ''));
        if ($description === '') {
            continue;
        }
        $parcelasEstimadas = worksParseParcelasFromDescription($description);
        $valorReferencia = worksParseMoneyFromDescription($description);
        $valor = $valorReferencia !== null ? round($valorReferencia * $parcelasEstimadas, 2) : $amount;
        $propostas[] = [
            'id' => (int) ($item['ProposalTypeID'] ?? 0),
            'descricao' => $description,
            'calculo' => (string) ($item['CalculationType'] ?? ''),
            'primeiro_vencimento' => (string) ($item['FirstInstallmentDate'] ?? ''),
            'valor_estimado' => $valor,
            'valor_referencia' => $valorReferencia,
            'parcelas' => $parcelasEstimadas,
        ];
    }

    $datas = [];
    foreach (($payload['ListInstallmentDate'] ?? []) as $date) {
        $raw = trim((string) $date);
        if ($raw === '') {
            continue;
        }
        $datas[] = [
            'iso' => $raw,
            'data' => worksDateValue($raw),
            'label' => worksDateLabel($raw),
        ];
    }

    $tiposAcordo = [];
    foreach (worksList($payload, 'ListAgreementType') as $item) {
        $description = trim((string) ($item['DescriptionValue'] ?? ''));
        if ($description === '') {
            continue;
        }
        $tiposAcordo[] = [
            'id' => (int) ($item['ValueID'] ?? 0),
            'descricao' => $description,
        ];
    }

    $tiposPagamento = [];
    foreach (worksList($payload, 'ListPaymentType') as $item) {
        $description = trim((string) ($item['PaymentTypeDescription'] ?? ''));
        $tag = trim((string) ($item['PaymentTypeTag'] ?? ''));
        if ($description === '' && $tag === '') {
            continue;
        }
        $tiposPagamento[] = [
            'tag' => $tag,
            'descricao' => $description !== '' ? $description : $tag,
        ];
    }

    $formasBoleto = [];
    foreach (worksList($payload, 'ListHowReceiveBankSlip') as $item) {
        $description = trim((string) ($item['HowReceiveBankSlipDescription'] ?? ''));
        $tag = trim((string) ($item['HowReceiveBankSlipTag'] ?? ''));
        if ($description === '' && $tag === '') {
            continue;
        }
        $formasBoleto[] = [
            'payment_type_tag' => (string) ($item['PaymentTypeTag'] ?? ''),
            'tag' => $tag,
            'descricao' => $description !== '' ? $description : $tag,
        ];
    }

    return [
        'ok' => true,
        'perfil' => 'acme',
        'api_disponivel' => $apiDisponivel,
        'fonte' => $fonte,
        'mensagem' => $mensagem,
        'api_erro' => $apiErro,
        'acordo_atual' => $acordoAtual,
        'acordo_diagnostico' => worksAgreementDiagnostics($agreementPayload),
        'contrato' => (string) ($payload['Contract'] ?? $contrato['codigo_contrato']),
        'documento' => (string) ($payload['DocNumber'] ?? $contrato['pessoa_documento']),
        'valor_divida' => $amount,
        'status_divida' => (string) ($acordoAtual['debt']['status'] ?? $payload['StatusDescription'] ?? $contrato['status_contrato'] ?? ''),
        'provider' => (string) ($payload['Provider'] ?? ''),
        'provider_id' => $payload['ProviderID'] ?? null,
        'owner_description' => (string) ($payload['OwnerDescription'] ?? ''),
        'data_divida' => (string) ($payload['DebtDate'] ?? ''),
        'atraso_base' => $atrasoContrato['base'],
        'atraso_atual' => $atrasoContrato['atual'],
        'atraso_atual_label' => $atrasoContrato['label'],
        'atraso_data_base' => $atrasoContrato['data_base'],
        'atraso_dias_projetados' => $atrasoContrato['dias_projetados'],
        'atraso_nota' => $atrasoContrato['nota'],
        'detalhe' => (string) ($payload['MoreDetail'] ?? ''),
        'custom_proposal_tag' => (string) ($payload['CustomProposalTag'] ?? ''),
        'binding_id' => (string) ($payload['BindingID'] ?? ''),
        'sync' => $sync,
        'propostas' => $propostas,
        'datas' => $datas,
        'tipos_acordo' => $tiposAcordo,
        'tipos_pagamento' => $tiposPagamento,
        'formas_boleto' => $formasBoleto,
    ];
}

try {
    $contratoId = filter_var($_GET['contrato_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($contratoId <= 0) {
        worksFinanceiroJson(['ok' => false, 'erro' => 'Contrato não informado.'], 422);
    }

    $refreshApi = (string) ($_GET['refresh'] ?? '0') === '1';
    $forceAgreement = (string) ($_GET['force_agreement'] ?? '0') === '1';
    $agreementCacheSeconds = max(60, min(3600, (int) Database::env('ACME_API_AGREEMENT_CACHE_SECONDS', '600')));

    $pdo = operacaoPdo();
    $repository = new AtendimentoAdaptorRepository($pdo);
    $contrato = $repository->buscarContrato($contratoId, usuarioEscopoCredoresAcao('consultar'));
    if ($contrato === null) {
        worksFinanceiroJson(['ok' => false, 'erro' => 'Contrato não encontrado ou fora do seu escopo.'], 404);
    }
    requireContratoNoEscopo($contratoId, 'consultar');
    $atrasoContrato = worksAtrasoContrato($contrato);

    $credorCodigo = strtoupper(trim((string) ($contrato['credor_codigo'] ?? '')));
    if ($credorCodigo !== 'ACME') {
        worksFinanceiroJson([
            'ok' => true,
            'perfil' => 'padrao',
            'api_disponivel' => false,
            'mensagem' => 'Este contrato ainda não possui integração financeira específica. Perfil financeiro padrão carregado.',
            'atraso_base' => $atrasoContrato['base'],
            'atraso_atual' => $atrasoContrato['atual'],
            'atraso_atual_label' => $atrasoContrato['label'],
            'atraso_data_base' => $atrasoContrato['data_base'],
            'atraso_dias_projetados' => $atrasoContrato['dias_projetados'],
            'atraso_nota' => $atrasoContrato['nota'],
        ]);
    }

    if (worksContratoRetiradoCredor($contrato)) {
        worksFinanceiroJson([
            'ok' => true,
            'perfil' => 'acme',
            'api_disponivel' => false,
            'fonte' => 'base',
            'retirado_credor' => true,
            'mensagem' => 'Contrato retirado pelo credor. Nova negociação bloqueada. Dados mantidos apenas para consulta histórica.',
            'valor_divida' => $contrato['saldo_atual'] ?? null,
            'atraso_base' => $atrasoContrato['base'],
            'atraso_atual' => $atrasoContrato['atual'],
            'atraso_atual_label' => $atrasoContrato['label'],
            'atraso_data_base' => $atrasoContrato['data_base'],
            'atraso_dias_projetados' => $atrasoContrato['dias_projetados'],
            'atraso_nota' => $atrasoContrato['nota'],
            'propostas' => [],
            'datas' => [],
            'tipos_acordo' => [],
            'tipos_pagamento' => [],
            'formas_boleto' => [],
            'acordo_atual' => null,
            'sync' => ['retirado_credor' => true],
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $adapterFactory = new CredorAdapterFactory($pdo, new CredorConfiguracaoRepository($pdo));
    $client = $adapterFactory->paraContrato($contrato);
    if ($client === null || !$client->configurado()) {
        worksFinanceiroJson([
            'ok' => true,
            'perfil' => 'acme',
            'api_disponivel' => false,
            'mensagem' => 'API ACME não configurada no ambiente. Configure as variáveis ACME_API_* no .env.local.',
        ]);
    }

    $cache = $repository->obterFinanceiroAcmeCache($contratoId);
    $acordoCache = $repository->obterAcordoAcmeCache($contratoId);
    $acordoPayloadCache = is_array($acordoCache['raw'] ?? null) ? $acordoCache['raw'] : [];
    $financeiroCacheHoje = $cache !== [] && worksCacheFromToday($cache['atualizado_em'] ?? null);
    $acordoCacheRecente = $acordoPayloadCache !== []
        && worksCacheFresh($acordoCache['atualizado_em'] ?? null, $agreementCacheSeconds);

    // Achado 2026-09-29 (relato da operação: "tem acordo e mesmo assim tá simulando"):
    // até aqui só é checado o acordo confirmado PELA ACME (cache em
    // contratos.payload_extra.acme_acordo). Um acordo criado só no CRM (ex.:
    // "Criar acordo" na ficha do cliente, sem integração com o credor)
    // nunca passa por lá e a simulação continuava liberada mesmo com um
    // acordo pendente/formalizado aqui dentro. worksBuildAcmeResponse() usa
    // essa variável global pra forçar impede_nova_proposta=true também
    // quando o CRM já tem um acordo ativo, independente do que a ACME diga.
    $temAcordoCrmAtivo = (new AcordoOperacionalRepository($pdo))->existeAcordoAtivoNoContrato($contratoId);

    // refresh=0 e estritamente local. Essa rota e usada quando o navegador ja
    // visitou a aba nos ultimos 10 minutos. Nenhuma chamada externa e feita.
    if (!$refreshApi) {
        $syncLocal = [
            'financeiro_cache_diario' => $financeiroCacheHoje,
            'acordo_cache_recente' => $acordoCacheRecente,
            'sem_chamada_externa' => true,
        ];
        if ($acordoPayloadCache !== []) {
            try {
                $acordoRepository = new AcordoOperacionalRepository($pdo);
                $syncLocal = array_merge(
                    $syncLocal,
                    worksRegistrarAcordoAcmeNoCrm($acordoRepository, $contrato, worksBuildAgreementInfo($acordoPayloadCache, $contrato))
                );
            } catch (Throwable $e) {
                $syncLocal['acordo_crm_erro'] = $e->getMessage();
            }
        }

        if ($cache !== [] || $acordoPayloadCache !== []) {
            $payload = $cache !== []
                ? worksPayloadFromCache($cache, $contrato)
                : worksPayloadBaseFromAgreement($acordoPayloadCache, $contrato);
            worksFinanceiroJson(worksBuildAcmeResponse(
                $payload,
                $contrato,
                $atrasoContrato,
                false,
                'Dados financeiros reutilizados do CRM. Nenhuma chamada externa foi necessária nesta troca de aba.',
                'cache',
                $syncLocal,
                null,
                $acordoPayloadCache
            ));
        }

        worksFinanceiroJson([
            'ok' => true,
            'perfil' => 'acme',
            'api_disponivel' => false,
            'fonte' => 'base',
            'mensagem' => 'Ainda não existe cache ACME para este contrato. Aguarde a próxima atualização automática do Financeiro.',
            'valor_divida' => $contrato['saldo_atual'] ?? null,
            'atraso_base' => $atrasoContrato['base'],
            'atraso_atual' => $atrasoContrato['atual'],
            'atraso_atual_label' => $atrasoContrato['label'],
            'atraso_data_base' => $atrasoContrato['data_base'],
            'atraso_dias_projetados' => $atrasoContrato['dias_projetados'],
            'atraso_nota' => $atrasoContrato['nota'],
            'propostas' => [],
            'datas' => [],
            'tipos_acordo' => [],
            'tipos_pagamento' => [],
            'formas_boleto' => [],
            'acordo_atual' => null,
            'sync' => ['sem_chamada_externa' => true],
        ]);
    }

    $sync = [];
    $apiErro = null;
    $payload = [];
    $agreementPayload = $acordoPayloadCache;
    $acordoInfoAtual = $agreementPayload !== [] ? worksBuildAgreementInfo($agreementPayload, $contrato) : null;
    $consultouAcordoApi = false;
    $consultouFinanceiroApi = false;

    // O acordo pode mudar ao longo do dia, portanto a validacao e curta (10 min
    // por padrao). A simulacao/propostas, por outro lado, e reutilizada durante
    // todo o mesmo dia operacional.
    if ($forceAgreement || !$acordoCacheRecente) {
        try {
            $agreementPayload = $client->consultarAcordo((string) $contrato['pessoa_documento'], (string) $contrato['codigo_contrato']);
            $consultouAcordoApi = true;
            $repository->atualizarContratoAcordoAcme($contratoId, $agreementPayload);
            if (worksAgreementNotFoundPayload($agreementPayload)) {
                $sync['acordo_acme_nao_encontrado'] = true;
            } else {
                $sync['acordo_acme_atualizado'] = true;
            }
            $acordoInfoAtual = worksBuildAgreementInfo($agreementPayload, $contrato);
            $acordoRepository = new AcordoOperacionalRepository($pdo);
            $sync = array_merge($sync, worksRegistrarAcordoAcmeNoCrm($acordoRepository, $contrato, $acordoInfoAtual));
        } catch (Throwable $e) {
            $sync['acordo_acme_erro'] = $e->getMessage();
            if ($agreementPayload !== []) {
                $acordoInfoAtual = worksBuildAgreementInfo($agreementPayload, $contrato);
            }
        }
    } else {
        $sync['acordo_cache_reutilizado'] = true;
        if ($agreementPayload !== []) {
            try {
                $acordoRepository = new AcordoOperacionalRepository($pdo);
                $sync = array_merge($sync, worksRegistrarAcordoAcmeNoCrm($acordoRepository, $contrato, $acordoInfoAtual));
            } catch (Throwable $e) {
                $sync['acordo_crm_erro'] = $e->getMessage();
            }
        }
    }

    // Achado 2026-09-29: além do acordo confirmado pela ACME
    // ($acordoInfoAtual), também não consulta simulação/propostas quando
    // já existe acordo pendente/formalizado só no CRM ($temAcordoCrmAtivo) -
    // sem isso o gate abaixo passava reto e a ACME era consultada pra gerar
    // novas propostas em cima de um acordo já fechado no CRM.
    if (worksAcordoAcmeVigente($acordoInfoAtual) || $temAcordoCrmAtivo) {
        $payload = $cache !== []
            ? worksPayloadFromCache($cache, $contrato)
            : worksPayloadBaseFromAgreement($agreementPayload, $contrato);
        $erroConciliacao = trim((string) ($sync['acordo_crm_erro'] ?? ''));
        if ((string) ($acordoInfoAtual['status_operacional'] ?? '') === 'cumprido') {
            $mensagemAcordo = $erroConciliacao === ''
                ? 'Dívida liquidada/paga confirmada pela ACME. O acordo e suas parcelas foram conciliados no CRM.'
                : 'Dívida liquidada/paga confirmada pela ACME, mas a atualização da Central de Acordos falhou.';
        } elseif (!worksAcordoAcmeVigente($acordoInfoAtual)) {
            $mensagemAcordo = 'Existe um acordo pendente/formalizado registrado no CRM para este contrato, ainda não confirmado pela ACME. A simulação não foi consultada.';
        } else {
            $mensagemAcordo = $consultouAcordoApi
                ? 'Acordo ativo validado na ACME. A simulação não foi consultada novamente.'
                : 'Acordo ativo reutilizado do cache recente. A simulação não foi consultada novamente.';
        }
        worksFinanceiroJson(worksBuildAcmeResponse(
            $payload,
            $contrato,
            $atrasoContrato,
            $consultouAcordoApi,
            $mensagemAcordo,
            $consultouAcordoApi ? 'api' : 'cache',
            $sync,
            null,
            $agreementPayload
        ));
    }

    if ($financeiroCacheHoje && $cache !== []) {
        $payload = worksPayloadFromCache($cache, $contrato);
        $sync['financeiro_cache_diario'] = true;
    } else {
        try {
            $payload = $client->consultarNegociacao((string) $contrato['pessoa_documento'], (string) $contrato['codigo_contrato']);
            $consultouFinanceiroApi = true;
            $repository->atualizarContratoFinanceiroAcme($contratoId, $payload);
            $sync['financeiro_atualizado'] = true;
        } catch (Throwable $e) {
            $apiErro = $e->getMessage();
            $sync['financeiro_erro'] = $e->getMessage();
            if ($cache !== []) {
                $payload = worksPayloadFromCache($cache, $contrato);
            }
        }
    }

    if ($payload !== []) {
        $fonte = ($consultouFinanceiroApi || $consultouAcordoApi) ? 'api' : 'cache';
        if ($consultouFinanceiroApi) {
            $mensagem = 'Simulação/propostas ACME atualizadas e gravadas no CRM para o dia de hoje.';
        } elseif ($consultouAcordoApi) {
            $mensagem = 'Acordo validado na ACME; simulação/propostas reutilizadas do cache diário.';
        } else {
            $mensagem = 'Simulação/propostas reutilizadas do cache diário do CRM.';
        }
        worksFinanceiroJson(worksBuildAcmeResponse(
            $payload,
            $contrato,
            $atrasoContrato,
            $fonte === 'api',
            $mensagem,
            $fonte,
            $sync,
            $apiErro,
            $agreementPayload
        ));
    }

    if ($agreementPayload !== []) {
        $payload = worksPayloadBaseFromAgreement($agreementPayload, $contrato);
        worksFinanceiroJson(worksBuildAcmeResponse(
            $payload,
            $contrato,
            $atrasoContrato,
            $consultouAcordoApi,
            'Consulta de acordo disponível, mas a simulação/propostas ainda não está em cache.',
            $consultouAcordoApi ? 'api' : 'cache',
            $sync,
            $apiErro,
            $agreementPayload
        ));
    }

    throw new RuntimeException($apiErro ?: ($sync['acordo_acme_erro'] ?? 'Falha na atualização ACME e nenhum cache local disponível.'));
} catch (Throwable $e) {
    worksFinanceiroJson(['ok' => false, 'erro' => $e->getMessage()], 500);
}
