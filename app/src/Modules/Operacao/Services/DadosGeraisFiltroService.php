<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Normaliza os filtros do modulo Dados Gerais sem conhecer SQL ou HTTP.
 * Mantem a mesma semantica visual do construtor de estrategias: um filtro so
 * participa quando o respectivo "usar_*" estiver ativo.
 */
final class DadosGeraisFiltroService
{
    public const FORMATOS = [
        'mailing' => 'Mailing para discadora',
        'base' => 'Base geral canonica',
    ];

    public const SITUACOES_BASE = [
        'ativos' => 'Somente contratos ativos na ultima carga',
        'retirados' => 'Somente retirados pelo credor',
        'todos' => 'Ativos e retirados',
    ];

    /**
     * @param array<string,mixed> $dados
     * @return array<string,mixed>
     */
    public function normalizar(array $dados, bool $exigirConfirmacaoSemFiltro = true): array
    {
        $situacaoBase = strtolower(trim((string) ($dados['situacao_base'] ?? 'ativos')));
        if (!isset(self::SITUACOES_BASE[$situacaoBase])) {
            throw new InvalidArgumentException('Selecione uma situacao valida da base.');
        }

        $filtros = ['situacao_base' => $situacaoBase];
        $segmentacaoAtiva = false;
        $ativo = static fn(string $chave): bool => (string) ($dados['usar_' . $chave] ?? '') === '1';
        $marcar = static function () use (&$segmentacaoAtiva): void {
            $segmentacaoAtiva = true;
        };

        if ($ativo('credor')) {
            $filtros['credor_id'] = $this->idObrigatorio($dados['credor_id'] ?? null, 'Selecione o credor.');
            $marcar();
        }
        if ($ativo('carteira')) {
            $filtros['carteira_id'] = $this->idObrigatorio($dados['carteira_id'] ?? null, 'Selecione a carteira.');
            $marcar();
        }
        foreach ([
            'produto' => 180,
            'subproduto' => 180,
            'status_contrato' => 120,
            'status_devedor' => 120,
            'codigo_contrato' => 120,
            'conta_id' => 120,
            'lote' => 180,
            'fila' => 180,
            'filial' => 180,
            'unidade_cobranca' => 120,
            'cliente_nome' => 255,
            'documento' => 20,
        ] as $campo => $limite) {
            if ($ativo($campo)) {
                $filtros[$campo] = $this->normalizarTexto($dados[$campo] ?? null, $limite, true);
                $marcar();
            }
        }

        $datas = [
            'data_entrada' => ['data_entrada_de', 'data_entrada_ate', 'entrada na base'],
            'data_atualizacao' => ['data_atualizacao_de', 'data_atualizacao_ate', 'atualizacao do contrato'],
            'vencimento_original' => ['vencimento_original_de', 'vencimento_original_ate', 'vencimento original'],
            'data_ultima_tentativa' => ['ultima_tentativa_de', 'ultima_tentativa_ate', 'ultima tentativa'],
            'data_ultimo_pagamento' => ['ultimo_pagamento_de', 'ultimo_pagamento_ate', 'ultimo pagamento'],
        ];
        foreach ($datas as $campo => [$de, $ate, $rotulo]) {
            if ($ativo($campo)) {
                $filtros[$campo] = $this->normalizarFaixaData($dados[$de] ?? null, $dados[$ate] ?? null, $rotulo);
                $marcar();
            }
        }

        $inteiros = [
            'dia_vencimento' => ['dia_vencimento_de', 'dia_vencimento_ate', 1, 31, 'dia do vencimento'],
            'aging' => ['aging_min', 'aging_max', 0, 99999, 'aging'],
            'quantidade_tentativas' => ['tentativas_min', 'tentativas_max', 0, 999999, 'quantidade de tentativas'],
            'dias_sem_tentativa' => ['dias_sem_tentativa_min', 'dias_sem_tentativa_max', 0, 99999, 'dias sem tentativa'],
            'total_parcelas' => ['total_parcelas_min', 'total_parcelas_max', 0, 99999, 'quantidade de parcelas'],
        ];
        foreach ($inteiros as $campo => [$de, $ate, $min, $max, $rotulo]) {
            if ($ativo($campo)) {
                $faixa = $this->normalizarFaixaInteiro($dados[$de] ?? null, $dados[$ate] ?? null, $min, $max, $rotulo);
                if ($campo === 'dias_sem_tentativa') {
                    $faixa['incluir_nunca'] = (string) ($dados['dias_sem_incluir_nunca'] ?? '1') === '1';
                }
                $filtros[$campo] = $faixa;
                $marcar();
            }
        }

        $decimais = [
            'saldo_atual' => ['saldo_atual_min', 'saldo_atual_max', 'saldo atual'],
            'valor_original' => ['valor_original_min', 'valor_original_max', 'valor original'],
            'valor_pago' => ['valor_pago_min', 'valor_pago_max', 'valor pago'],
            'valor_faltante' => ['valor_faltante_min', 'valor_faltante_max', 'valor faltante'],
        ];
        foreach ($decimais as $campo => [$de, $ate, $rotulo]) {
            if ($ativo($campo)) {
                $filtros[$campo] = $this->normalizarFaixaDecimal($dados[$de] ?? null, $dados[$ate] ?? null, $rotulo);
                $marcar();
            }
        }

        if ($ativo('situacao_tentativas')) {
            $valor = (string) ($dados['situacao_tentativas'] ?? '');
            if (!in_array($valor, ['nunca', 'com'], true)) {
                throw new InvalidArgumentException('Selecione uma situacao valida para as tentativas.');
            }
            $filtros['situacao_tentativas'] = $valor;
            $marcar();
        }
        if ($ativo('ultimo_resultado')) {
            $filtros['ultimo_resultado_id'] = $this->idObrigatorio($dados['ultimo_resultado_id'] ?? null, 'Selecione o ultimo resultado.');
            $marcar();
        }
        if ($ativo('situacao_acordo')) {
            $valor = (string) ($dados['situacao_acordo'] ?? '');
            if (!in_array($valor, ['sem_acordo', 'sem_ativo', 'ativo', 'quebrado', 'cumprido', 'cancelado'], true)) {
                throw new InvalidArgumentException('Selecione uma situacao valida para os acordos.');
            }
            $filtros['situacao_acordo'] = $valor;
            $marcar();
        }
        if ($ativo('tipo_contato')) {
            $valor = (string) ($dados['tipo_contato'] ?? '');
            if (!in_array($valor, ['telefone_valido', 'sem_telefone_valido', 'whatsapp', 'email', 'qualquer_valido'], true)) {
                throw new InvalidArgumentException('Selecione um filtro de contato valido.');
            }
            $filtros['tipo_contato'] = $valor;
            $marcar();
        }
        if ($ativo('localidade')) {
            $uf = strtoupper(trim((string) ($dados['uf'] ?? '')));
            $cidade = $this->normalizarTexto($dados['cidade'] ?? null, 120, false);
            if ($uf === '' && $cidade === null) {
                throw new InvalidArgumentException('Informe UF ou cidade para utilizar o filtro de localidade.');
            }
            if ($uf !== '') {
                if (!preg_match('/^[A-Z]{2}$/', $uf)) {
                    throw new InvalidArgumentException('UF invalida.');
                }
                $filtros['uf'] = $uf;
            }
            if ($cidade !== null) {
                $filtros['cidade'] = $cidade;
            }
            $marcar();
        }

        if (!$segmentacaoAtiva && $exigirConfirmacaoSemFiltro && (string) ($dados['confirmar_todos_escopo'] ?? '') !== '1') {
            throw new InvalidArgumentException('Nenhum filtro de segmentacao esta ativo. Marque a confirmacao para consultar toda a base permitida pelo seu escopo.');
        }

        $filtros['_segmentacao_ativa'] = $segmentacaoAtiva;
        return $filtros;
    }

    /** @param array<string,mixed> $dados */
    public function formato(array $dados): string
    {
        $formato = strtolower(trim((string) ($dados['formato'] ?? 'mailing')));
        return isset(self::FORMATOS[$formato]) ? $formato : 'mailing';
    }

    /** @return array{min:?string,max:?string} */
    private function normalizarFaixaData(mixed $min, mixed $max, string $rotulo): array
    {
        $minNorm = $this->normalizarDataOpcional($min, $rotulo);
        $maxNorm = $this->normalizarDataOpcional($max, $rotulo);
        if ($minNorm === null && $maxNorm === null) {
            throw new InvalidArgumentException('Informe ao menos uma data para o filtro de ' . $rotulo . '.');
        }
        if ($minNorm !== null && $maxNorm !== null && $minNorm > $maxNorm) {
            throw new InvalidArgumentException('A data inicial de ' . $rotulo . ' nao pode ser maior que a data final.');
        }
        return ['min' => $minNorm, 'max' => $maxNorm];
    }

    /** @return array{min:?int,max:?int} */
    private function normalizarFaixaInteiro(mixed $min, mixed $max, int $limiteMin, int $limiteMax, string $rotulo): array
    {
        $minNorm = $this->normalizarInteiroOpcional($min, $limiteMin, $limiteMax, $rotulo);
        $maxNorm = $this->normalizarInteiroOpcional($max, $limiteMin, $limiteMax, $rotulo);
        if ($minNorm === null && $maxNorm === null) {
            throw new InvalidArgumentException('Informe ao menos um valor para o filtro de ' . $rotulo . '.');
        }
        if ($minNorm !== null && $maxNorm !== null && $minNorm > $maxNorm) {
            throw new InvalidArgumentException('O valor minimo de ' . $rotulo . ' nao pode ser maior que o maximo.');
        }
        return ['min' => $minNorm, 'max' => $maxNorm];
    }

    /** @return array{min:?float,max:?float} */
    private function normalizarFaixaDecimal(mixed $min, mixed $max, string $rotulo): array
    {
        $minNorm = $this->normalizarDecimalOpcional($min, $rotulo);
        $maxNorm = $this->normalizarDecimalOpcional($max, $rotulo);
        if ($minNorm === null && $maxNorm === null) {
            throw new InvalidArgumentException('Informe ao menos um valor para o filtro de ' . $rotulo . '.');
        }
        if ($minNorm !== null && $maxNorm !== null && $minNorm > $maxNorm) {
            throw new InvalidArgumentException('O valor minimo de ' . $rotulo . ' nao pode ser maior que o maximo.');
        }
        return ['min' => $minNorm, 'max' => $maxNorm];
    }

    private function normalizarDataOpcional(mixed $value, string $rotulo): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        if ($data === false || $data->format('Y-m-d') !== $texto) {
            throw new InvalidArgumentException('Data invalida no filtro de ' . $rotulo . '.');
        }
        return $texto;
    }

    private function normalizarTexto(mixed $value, int $limite, bool $obrigatorio): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException('Preencha todos os campos obrigatorios do filtro.');
            }
            return null;
        }
        return mb_substr($texto, 0, $limite);
    }

    private function normalizarInteiroOpcional(mixed $value, int $min, int $max, string $rotulo): ?int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $texto)) {
            throw new InvalidArgumentException('Valor inteiro invalido em ' . $rotulo . '.');
        }
        $numero = (int) $texto;
        if ($numero < $min || $numero > $max) {
            throw new InvalidArgumentException('Valor fora da faixa permitida em ' . $rotulo . '.');
        }
        return $numero;
    }

    private function normalizarDecimalOpcional(mixed $value, string $rotulo): ?float
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }
        $texto = str_replace(['R$', ' '], '', $texto);
        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            if (strrpos($texto, ',') > strrpos($texto, '.')) {
                $texto = str_replace('.', '', $texto);
                $texto = str_replace(',', '.', $texto);
            } else {
                $texto = str_replace(',', '', $texto);
            }
        } elseif (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', $texto);
        }
        if (!is_numeric($texto)) {
            throw new InvalidArgumentException('Valor monetario invalido em ' . $rotulo . '.');
        }
        $numero = (float) $texto;
        if ($numero < 0) {
            throw new InvalidArgumentException('Valores monetarios nao podem ser negativos em ' . $rotulo . '.');
        }
        return $numero;
    }

    private function idObrigatorio(mixed $value, string $mensagem): int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '' || !ctype_digit($texto) || (int) $texto <= 0) {
            throw new InvalidArgumentException($mensagem);
        }
        return (int) $texto;
    }
}
