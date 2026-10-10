<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Parsers;

use RuntimeException;

final class AcmePipeParser
{
    public const EXPECTED_COLUMN_COUNT = 55;

    public const EXPECTED_HEADER = [
        'DebtorProviderDescription',
        'ProviderName',
        'ProductDescription',
        'Queuename',
        'Number',
        'Name',
        'LastActioningDate',
        'DebtorStatus',
        'Accountid',
        'DebtorId',
        'AccountNumber',
        'AccountStatus',
        'RecompraStatus',
        'CutOfdate',
        'Age',
        'CurrentBalance',
        'Description',
        'TotalCollected',
        'RegiOn',
        'City',
        'IngressoPatio',
        'DescricaoVeiculos',
        'Marca',
        'Modelo',
        'AnoFabricacao',
        'AnoModelo',
        'CategoriaVeiculo',
        'AcordoPromessa',
        'Vencimento',
        'DataFila',
        'DiasFila',
        'CelularHot',
        'FixoHot',
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
        'EmailHot',
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

    /**
     * @return list<string>
     */
    public function parseHeader(string $line): array
    {
        $columns = $this->parsePipeLine($line);

        if (isset($columns[0])) {
            $columns[0] = $this->removeBom($columns[0]);
        }

        return $columns;
    }

    /**
     * @param list<string> $header
     * @return list<array{codigo:string,mensagem:string,campo_origem:?string,valor_original:?string}>
     */
    public function validateHeader(array $header): array
    {
        $errors = [];

        if (count($header) !== self::EXPECTED_COLUMN_COUNT) {
            $errors[] = [
                'codigo' => 'ACME_HEADER_QTD_COLUNAS_INVALIDA',
                'mensagem' => sprintf('Cabeçalho ACME possui %d colunas; esperado %d.', count($header), self::EXPECTED_COLUMN_COUNT),
                'campo_origem' => null,
                'valor_original' => implode('|', $header),
            ];

            return $errors;
        }

        foreach (self::EXPECTED_HEADER as $index => $expected) {
            $received = $header[$index] ?? null;
            if ($received !== $expected) {
                $errors[] = [
                    'codigo' => 'ACME_HEADER_CAMPO_DIVERGENTE',
                    'mensagem' => sprintf('Cabeçalho ACME divergente na posição %d. Esperado "%s", recebido "%s".', $index + 1, $expected, (string) $received),
                    'campo_origem' => $expected,
                    'valor_original' => $received,
                ];
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $header
     * @return array<string,string|null>
     */
    public function parseDataLine(string $line, array $header, int $lineNumber): array
    {
        $values = $this->parsePipeLine($line);

        if (count($values) !== self::EXPECTED_COLUMN_COUNT) {
            throw new RuntimeException(sprintf(
                'Linha %d possui %d colunas; esperado %d.',
                $lineNumber,
                count($values),
                self::EXPECTED_COLUMN_COUNT
            ));
        }

        $data = [];
        foreach ($header as $index => $fieldName) {
            $value = $values[$index] ?? null;
            $value = $value === null ? null : trim($value);
            $data[$fieldName] = $value === '' ? null : $value;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function parsePipeLine(string $line): array
    {
        $line = rtrim($line, "\r\n");
        return str_getcsv($line, '|', '"', '\\');
    }

    private function removeBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }
}
