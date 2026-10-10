<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Validators;

final class ValidadorLinhaAcme
{
    /**
     * @param array<string,mixed> $row
     * @return list<array{codigo:string,mensagem:string,campo_origem:?string,campo_canonico:?string,severidade:string,valor_original:mixed}>
     */
    public function validate(array $row): array
    {
        $errors = [];

        if (empty($row['documento'])) {
            $errors[] = $this->erro('DOCUMENTO_OBRIGATORIO', 'Documento é obrigatório.', 'Number', 'pessoas.documento', null);
        } elseif (!in_array(strlen((string) $row['documento']), [11, 14], true)) {
            $errors[] = $this->erro('DOCUMENTO_TAMANHO_INVALIDO', 'Documento precisa ter 11 dígitos para CPF ou 14 para CNPJ.', 'Number', 'pessoas.documento', $row['documento']);
        }

        if (!empty($row['documento']) && preg_match('/^(\d)\1+$/', (string) $row['documento']) === 1) {
            $errors[] = $this->erro('DOCUMENTO_REPETIDO_INVALIDO', 'Documento possui todos os dígitos repetidos.', 'Number', 'pessoas.documento', $row['documento']);
        }

        if (empty($row['nome'])) {
            $errors[] = $this->erro('NOME_OBRIGATORIO', 'Nome é obrigatório.', 'Name', 'pessoas.nome', null);
        }

        if (empty($row['codigo_contrato'])) {
            $errors[] = $this->erro('CONTRATO_OBRIGATORIO', 'Código do contrato é obrigatório.', 'AccountNumber', 'contratos.codigo_contrato', null);
        }

        foreach (['email_1' => 'EmailHot', 'email_2' => 'EmailCorreto'] as $field => $campoOrigem) {
            if (!empty($row[$field]) && filter_var((string) $row[$field], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = $this->alerta('EMAIL_FORMATO_INVALIDO', 'E-mail informado não possui formato válido; linha será mantida no staging.', $campoOrigem, 'pessoa_contatos.email', $row[$field]);
            }
        }

        foreach (['ano_fabricacao', 'ano_modelo'] as $field) {
            if (isset($row[$field]) && $row[$field] !== null && ((int) $row[$field] < 1900 || (int) $row[$field] > 2100)) {
                $errors[] = $this->alerta('ANO_VEICULO_FORA_FAIXA', 'Ano do veículo fora da faixa esperada.', null, 'contrato_veiculos.' . $field, $row[$field]);
            }
        }

        return $errors;
    }

    /**
     * @param list<array{severidade:string}> $issues
     */
    public function hasBlockingError(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (in_array($issue['severidade'], ['erro', 'critico'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{codigo:string,mensagem:string,campo_origem:?string,campo_canonico:?string,severidade:string,valor_original:mixed}
     */
    private function erro(string $codigo, string $mensagem, ?string $campoOrigem, ?string $campoCanonico, mixed $valorOriginal): array
    {
        return [
            'codigo' => $codigo,
            'mensagem' => $mensagem,
            'campo_origem' => $campoOrigem,
            'campo_canonico' => $campoCanonico,
            'severidade' => 'erro',
            'valor_original' => $valorOriginal,
        ];
    }

    /**
     * @return array{codigo:string,mensagem:string,campo_origem:?string,campo_canonico:?string,severidade:string,valor_original:mixed}
     */
    private function alerta(string $codigo, string $mensagem, ?string $campoOrigem, ?string $campoCanonico, mixed $valorOriginal): array
    {
        return [
            'codigo' => $codigo,
            'mensagem' => $mensagem,
            'campo_origem' => $campoOrigem,
            'campo_canonico' => $campoCanonico,
            'severidade' => 'alerta',
            'valor_original' => $valorOriginal,
        ];
    }
}
