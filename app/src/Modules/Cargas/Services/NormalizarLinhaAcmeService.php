<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Services;

final class NormalizarLinhaAcmeService
{
    /**
     * @param array<string,string|null> $raw
     * @return array<string,mixed>
     */
    public function normalize(array $raw, int $credorId, ?int $carteiraId, ?int $layoutId): array
    {
        $documentoOriginal = $this->digits($raw['Number'] ?? null);
        $documento = $this->documento($raw['Number'] ?? null);
        $nome = $this->nullIfEmpty($raw['Name'] ?? null) ?? $this->nullIfEmpty($raw['AccountDebtorName'] ?? null);
        $codigoContrato = $this->nullIfEmpty($raw['AccountNumber'] ?? null);
        $ufOrigem = $this->nullIfEmpty($raw['RegiOn'] ?? null);
        $uf = $ufOrigem !== null && preg_match('/^[A-Za-z]{2}$/', $ufOrigem) === 1 ? strtoupper($ufOrigem) : null;
        $contatosDetectados = $this->contatosDetectados($raw);

        $normalized = [
            'credor_id' => $credorId,
            'carteira_id' => $carteiraId,
            'layout_id' => $layoutId,
            'documento' => $documento,
            'tipo_documento' => $this->tipoDocumento($documento),
            'nome' => $nome,
            'codigo_contrato' => $codigoContrato,
            'conta_id' => $this->nullIfEmpty($raw['Accountid'] ?? null),
            'devedor_origem_id' => $this->nullIfEmpty($raw['DebtorId'] ?? null),
            'produto' => $this->nullIfEmpty($raw['ProductDescription'] ?? null),
            'lote' => $this->nullIfEmpty($raw['ProviderName'] ?? null),
            'fila' => $this->nullIfEmpty($raw['Queuename'] ?? null),
            'status_devedor' => $this->nullIfEmpty($raw['DebtorStatus'] ?? null),
            'status_contrato' => $this->nullIfEmpty($raw['AccountStatus'] ?? null),
            'data_corte' => $this->date($raw['CutOfdate'] ?? null),
            'dias_atraso' => $this->int($raw['Age'] ?? null),
            'data_vencimento_parcela' => $this->date($raw['Vencimento'] ?? null),
            'saldo_atual' => $this->decimal($raw['CurrentBalance'] ?? null),
            'valor_total_pago' => $this->decimal($raw['TotalCollected'] ?? null),
            'telefone_1' => $this->phone($raw['CelularHot'] ?? null),
            'telefone_2' => $this->phone($raw['FixoHot'] ?? null),
            'telefone_3' => $this->primeiroContatoExtra($contatosDetectados, 'telefone', ['celular_hot', 'fixo_hot']),
            'email_1' => $this->email($raw['EmailHot'] ?? null),
            'email_2' => $this->primeiroContatoExtra($contatosDetectados, 'email', ['email_hot']),
            'uf' => $uf,
            'cidade' => $this->nullIfEmpty($raw['City'] ?? null),
            'descricao_veiculo' => $this->nullIfEmpty($raw['DescricaoVeiculos'] ?? null),
            'marca_veiculo' => $this->nullIfEmpty($raw['Marca'] ?? null),
            'modelo_veiculo' => $this->nullIfEmpty($raw['Modelo'] ?? null),
            'ano_fabricacao' => $this->year($raw['AnoFabricacao'] ?? null),
            'ano_modelo' => $this->year($raw['AnoModelo'] ?? null),
            'categoria_veiculo' => $this->nullIfEmpty($raw['CategoriaVeiculo'] ?? null),
        ];

        $normalized['hash_negocio'] = hash('sha256', implode('|', [
            'ACME',
            $documento ?? '',
            $codigoContrato ?? '',
            $normalized['conta_id'] ?? '',
        ]));

        $normalized['payload_origem'] = $raw;
        $normalized['payload_normalizado'] = $this->payloadNormalizado($normalized);
        $normalized['payload_extra'] = $this->payloadExtra($raw, $ufOrigem, $documentoOriginal, $documento, $contatosDetectados);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>
     */
    private function payloadNormalizado(array $normalized): array
    {
        $ignore = ['payload_origem', 'payload_normalizado', 'payload_extra'];
        return array_filter(
            $normalized,
            static fn ($value, string $key): bool => !in_array($key, $ignore, true) && $value !== null && $value !== '',
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param array<string,string|null> $raw
     * @param list<array<string,mixed>> $contatosDetectados
     * @return array<string,mixed>
     */
    private function payloadExtra(array $raw, ?string $ufOrigem, ?string $documentoOriginal, ?string $documentoNormalizado, array $contatosDetectados): array
    {
        $extraFields = [
            'DebtorProviderDescription',
            'LastActioningDate',
            'RecompraStatus',
            'Description',
            'RegiOn',
            'IngressoPatio',
            'AcordoPromessa',
            'DataFila',
            'DiasFila',
            'Hot',
            'Incorreto',
            'SMS',
            'Desconhecido',
            'Correto',
            'NãoConfirmado',
            'DataAgendamento',
            'Mailing',
            'IdadeDevedor',
            'QuantidadeParcelasPagas',
            'EmailCorreto',
            'WarningDescription',
            'PrecatoryType',
            'PersonType',
            'BudgetOrder',
            'OpenSeem',
            'OpenCalculation',
            'StartCollectionBaseDate',
            'Adv',
            'AccountDebtorName',
            'Notas',
        ];

        $payload = [];
        foreach ($extraFields as $field) {
            $value = $this->nullIfEmpty($raw[$field] ?? null);
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        if ($contatosDetectados !== []) {
            $payload['contatos_detectados_acme'] = $contatosDetectados;
        }

        if ($ufOrigem !== null && preg_match('/^[A-Za-z]{2}$/', $ufOrigem) !== 1) {
            $payload['RegiOn_observacao'] = 'RegiOn preservado em payload_extra porque não parece UF de 2 letras.';
        }

        if ($documentoOriginal !== null && $documentoNormalizado !== null && $documentoOriginal !== $documentoNormalizado) {
            $payload['documento_original'] = $documentoOriginal;
            $payload['documento_normalizado'] = $documentoNormalizado;
            $payload['documento_normalizacao'] = 'Completado com zeros à esquerda para preservar CPF/CNPJ numérico enviado sem padding.';
        }

        return $payload;
    }

    /**
     * @param array<string,string|null> $raw
     * @return list<array<string,mixed>>
     */
    private function contatosDetectados(array $raw): array
    {
        $fields = [
            'CelularHot' => ['tipo' => 'telefone', 'subtipo' => 'celular_hot', 'status' => 'hot', 'principal' => 1],
            'FixoHot' => ['tipo' => 'telefone', 'subtipo' => 'fixo_hot', 'status' => 'hot', 'principal' => 0],
            'Hot' => ['tipo' => 'telefone', 'subtipo' => 'telefone_hot', 'status' => 'hot', 'principal' => 0],
            'Correto' => ['tipo' => 'telefone', 'subtipo' => 'telefone_correto', 'status' => 'correto', 'principal' => 0],
            'SMS' => ['tipo' => 'telefone', 'subtipo' => 'telefone_sms', 'status' => 'sms', 'principal' => 0],
            'Desconhecido' => ['tipo' => 'telefone', 'subtipo' => 'telefone_desconhecido', 'status' => 'desconhecido', 'principal' => 0],
            'NãoConfirmado' => ['tipo' => 'telefone', 'subtipo' => 'telefone_nao_confirmado', 'status' => 'nao_confirmado', 'principal' => 0],
            'Incorreto' => ['tipo' => 'telefone', 'subtipo' => 'telefone_incorreto', 'status' => 'incorreto', 'principal' => 0],
            'EmailHot' => ['tipo' => 'email', 'subtipo' => 'email_hot', 'status' => 'hot', 'principal' => 1],
            'EmailCorreto' => ['tipo' => 'email', 'subtipo' => 'email_correto', 'status' => 'correto', 'principal' => 0],
        ];

        $items = [];
        $seen = [];
        foreach ($fields as $field => $meta) {
            $value = $this->nullIfEmpty($raw[$field] ?? null);
            if ($value === null) {
                continue;
            }

            $values = $meta['tipo'] === 'email'
                ? $this->extractEmails($value)
                : $this->extractPhones($value);

            foreach ($values as $normalizedValue) {
                $key = $meta['tipo'] . ':' . $normalizedValue;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $items[] = [
                    'tipo' => $meta['tipo'],
                    'subtipo' => $meta['subtipo'],
                    'valor' => $normalizedValue,
                    'valor_normalizado' => $normalizedValue,
                    'status' => $meta['status'],
                    'principal' => $meta['principal'],
                    'campo_origem' => $field,
                ];
            }
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<string> $subtiposIgnorados
     */
    private function primeiroContatoExtra(array $items, string $tipo, array $subtiposIgnorados): ?string
    {
        foreach ($items as $item) {
            if (($item['tipo'] ?? null) !== $tipo) {
                continue;
            }

            if (in_array((string) ($item['subtipo'] ?? ''), $subtiposIgnorados, true)) {
                continue;
            }

            return $this->nullIfEmpty((string) ($item['valor_normalizado'] ?? ''));
        }

        return null;
    }

    /** @return list<string> */
    private function extractPhones(string $value): array
    {
        $parts = preg_split('/[;,\/]+/', $value) ?: [$value];
        $phones = [];
        $seen = [];

        foreach ($parts as $part) {
            $digits = preg_replace('/\D+/', '', $part) ?? '';
            if ($digits === '') {
                continue;
            }

            if ((strlen($digits) === 12 || strlen($digits) === 13) && str_starts_with($digits, '55')) {
                $digits = substr($digits, 2);
            }

            if (!in_array(strlen($digits), [10, 11], true)) {
                continue;
            }

            if (preg_match('/^(\d)\1+$/', $digits) === 1) {
                continue;
            }

            if (!isset($seen[$digits])) {
                $phones[] = $digits;
                $seen[$digits] = true;
            }
        }

        return $phones;
    }

    /** @return list<string> */
    private function extractEmails(string $value): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches);
        $emails = [];
        $seen = [];

        foreach ($matches[0] ?? [] as $email) {
            $email = strtolower(trim((string) $email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            if (!isset($seen[$email])) {
                $emails[] = $email;
                $seen[$email] = true;
            }
        }

        return $emails;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function digits(?string $value): ?string
    {
        $value = $this->nullIfEmpty($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? null : $digits;
    }

    private function documento(?string $value): ?string
    {
        $digits = $this->digits($value);
        if ($digits === null) {
            return null;
        }

        $length = strlen($digits);

        // A ACME envia o campo Number como identificador numérico. Em alguns lotes,
        // CPFs que começam com zeros vêm sem o padding original. Exemplo:
        // 1234552 => 00001234552, 98765450 => 00098765450.
        // Portanto, qualquer documento de 1 a 10 dígitos é tratado como CPF
        // incompleto e completado à esquerda até 11 posições.
        if ($length >= 1 && $length <= 10) {
            return str_pad($digits, 11, '0', STR_PAD_LEFT);
        }

        // CNPJ com zeros à esquerda também pode chegar truncado.
        if ($length >= 12 && $length <= 13) {
            return str_pad($digits, 14, '0', STR_PAD_LEFT);
        }

        return $digits;
    }

    private function phone(?string $value): ?string
    {
        $phones = $value === null ? [] : $this->extractPhones($value);
        return $phones[0] ?? null;
    }

    private function email(?string $value): ?string
    {
        $emails = $value === null ? [] : $this->extractEmails($value);
        return $emails[0] ?? null;
    }

    private function tipoDocumento(?string $documento): ?string
    {
        return match (strlen((string) $documento)) {
            11 => 'CPF',
            14 => 'CNPJ',
            default => $documento ? 'OUTRO' : null,
        };
    }

    private function int(?string $value): ?int
    {
        $value = $this->digits($value);
        return $value === null ? null : (int) $value;
    }

    private function year(?string $value): ?int
    {
        $year = $this->int($value);
        if ($year === null) {
            return null;
        }

        return $year >= 1900 && $year <= 2100 ? $year : null;
    }

    private function decimal(?string $value): ?string
    {
        $value = $this->nullIfEmpty($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^0-9,\.\-]/', '', $value) ?? '';
        if ($value === '' || $value === '-' || $value === ',' || $value === '.') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function date(?string $value): ?string
    {
        $value = $this->nullIfEmpty($value);
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Ymd', 'd/m/Y H:i:s', 'Y-m-d H:i:s'];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
