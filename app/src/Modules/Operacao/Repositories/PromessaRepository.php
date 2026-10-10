<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class PromessaRepository
{
    public const STATUS = [
        'pendente' => 'Pendente',
        'cumprida' => 'Cumprida',
        'quebrada' => 'Quebrada',
        'cancelada' => 'Cancelada',
        'convertida_acordo' => 'Convertida em acordo',
    ];

    public const ORIGENS = [
        'manual' => 'Manual',
        'discador' => 'Discador',
        'fila_manual' => 'Fila manual',
        'retorno' => 'Retorno',
    ];

    private ?bool $moduloDisponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'promessas_pagamento'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @param array<string,mixed> $dados */
    public function registrar(int $pessoaId, array $dados): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de promessas indisponível. Execute a migration 013 antes de registrar promessa.');
        }

        if ($pessoaId <= 0) {
            throw new InvalidArgumentException('Cliente inválido para registrar promessa.');
        }

        $contratoId = $this->normalizarId($dados['contrato_id'] ?? null);
        if ($contratoId !== null && !$this->contratoPertencePessoa($pessoaId, $contratoId)) {
            throw new InvalidArgumentException('Contrato informado na promessa não pertence a este cliente.');
        }

        $valorPromessa = $this->normalizarDecimalObrigatorio($dados['valor_promessa'] ?? null, 'valor da promessa');
        $dataPromessa = $this->normalizarDataObrigatoria($dados['data_promessa'] ?? null, 'data prometida');
        $observacao = $this->normalizarTexto($dados['promessa_observacao'] ?? ($dados['observacao_promessa'] ?? null), 2000);
        $operadorNome = $this->normalizarTexto($dados['operador_nome'] ?? null, 120);
        $status = $this->normalizarStatus($dados['status'] ?? 'pendente');
        $origem = $this->normalizarOrigem($dados['origem'] ?? 'manual');

        $atendimentoId = $this->normalizarId($dados['atendimento_id'] ?? null);
        $campanhaItemId = $this->normalizarId($dados['campanha_item_id'] ?? null);
        $retornoOrigemId = $this->normalizarId($dados['retorno_origem_id'] ?? null);

        $payloadExtra = [
            'origem_tela' => $dados['origem_tela'] ?? 'operacao/cliente_detalhe.php',
            'sem_login' => true,
            'registro_operacional' => true,
            'observacao_arquitetural' => 'Promessa operacional; nao altera saldo, boleto, pagamento ou acordo financeiro definitivo.',
        ];

        $stmt = $this->pdo->prepare('INSERT INTO promessas_pagamento (
                pessoa_id,
                contrato_id,
                atendimento_id,
                campanha_item_id,
                retorno_origem_id,
                valor_promessa,
                data_promessa,
                status,
                origem,
                operador_nome,
                observacao,
                payload_extra
            ) VALUES (
                :pessoa_id,
                :contrato_id,
                :atendimento_id,
                :campanha_item_id,
                :retorno_origem_id,
                :valor_promessa,
                :data_promessa,
                :status,
                :origem,
                :operador_nome,
                :observacao,
                :payload_extra
            )');

        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'contrato_id', $contratoId);
        $this->bindNullableInt($stmt, 'atendimento_id', $atendimentoId);
        $this->bindNullableInt($stmt, 'campanha_item_id', $campanhaItemId);
        $this->bindNullableInt($stmt, 'retorno_origem_id', $retornoOrigemId);
        $stmt->bindValue('valor_promessa', number_format($valorPromessa, 2, '.', ''));
        $stmt->bindValue('data_promessa', $dataPromessa);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('origem', $origem);
        $this->bindNullableString($stmt, 'operador_nome', $operadorNome);
        $this->bindNullableString($stmt, 'observacao', $observacao);
        $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function listarPorPessoa(int $pessoaId, int $limite = 20): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 100));
        $stmt = $this->pdo->prepare('SELECT
                pp.*,
                c.codigo_contrato,
                c.produto,
                c.saldo_atual,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome,
                ah.canal AS atendimento_canal,
                ah.resultado AS atendimento_resultado,
                ah.criado_em AS atendimento_criado_em
            FROM promessas_pagamento pp
            LEFT JOIN contratos c ON c.id = pp.contrato_id
            LEFT JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN atendimentos_historico ah ON ah.id = pp.atendimento_id
            WHERE pp.pessoa_id = :pessoa_id
            ORDER BY
                CASE pp.status
                    WHEN \'pendente\' THEN 0
                    WHEN \'convertida_acordo\' THEN 1
                    WHEN \'cumprida\' THEN 2
                    WHEN \'quebrada\' THEN 3
                    ELSE 4
                END ASC,
                pp.data_promessa ASC,
                pp.id DESC
            LIMIT :limite');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,int|float> */
    public function resumoPessoa(int $pessoaId): array
    {
        $fallback = [
            'total' => 0,
            'pendentes' => 0,
            'vencidas' => 0,
            'valor_pendente' => 0.0,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        $stmt = $this->pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status = 'pendente' AND data_promessa < CURRENT_DATE THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN status = 'pendente' THEN valor_promessa ELSE 0 END) AS valor_pendente
            FROM promessas_pagamento
            WHERE pessoa_id = :pessoa_id");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
            'vencidas' => (int) ($row['vencidas'] ?? 0),
            'valor_pendente' => (float) ($row['valor_pendente'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $filtros @return list<array<string,mixed>> */
    public function listar(array $filtros = [], int $limite = 100): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 200));
        $where = [];
        $params = [];

        $status = trim((string) ($filtros['status'] ?? 'pendente'));
        if ($status === '') {
            $status = 'pendente';
        }
        if ($status !== 'todos') {
            $status = $this->normalizarStatus($status);
            $where[] = 'pp.status = :status';
            $params['status'] = $status;
        }

        $situacao = trim((string) ($filtros['situacao'] ?? 'todos'));
        if (!in_array($situacao, ['vencidas','hoje','futuras','todos'], true)) {
            $situacao = 'todos';
        }
        if ($situacao === 'vencidas') {
            $where[] = 'pp.data_promessa < CURRENT_DATE';
        } elseif ($situacao === 'hoje') {
            $where[] = 'pp.data_promessa = CURRENT_DATE';
        } elseif ($situacao === 'futuras') {
            $where[] = 'pp.data_promessa > CURRENT_DATE';
        }

        $termo = trim((string) ($filtros['q'] ?? ''));
        if ($termo !== '' && mb_strlen($termo) >= 2) {
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';
            $buscas = ['p.nome LIKE :q_texto_nome', 'c.codigo_contrato LIKE :q_texto_contrato'];
            $params['q_texto_nome'] = '%' . $termo . '%';
            $params['q_texto_contrato'] = '%' . $termo . '%';
            if ($digitos !== '' && strlen($digitos) >= 3) {
                $buscas[] = 'p.documento LIKE :q_digitos';
                $params['q_digitos'] = '%' . $digitos . '%';
            }
            $where[] = '(' . implode(' OR ', $buscas) . ')';
        }

        $dataInicio = $this->normalizarDataFiltro($filtros['data_inicio'] ?? null, false);
        if ($dataInicio !== null) {
            $where[] = 'pp.data_promessa >= :data_inicio';
            $params['data_inicio'] = $dataInicio;
        }

        $dataFim = $this->normalizarDataFiltro($filtros['data_fim'] ?? null, false);
        if ($dataFim !== null) {
            $where[] = 'pp.data_promessa <= :data_fim';
            $params['data_fim'] = $dataFim;
        }

        $sql = 'SELECT
                pp.*,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                c.codigo_contrato,
                c.produto,
                c.saldo_atual,
                c.dias_atraso,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome,
                ah.canal AS atendimento_canal,
                ah.resultado AS atendimento_resultado,
                ah.criado_em AS atendimento_criado_em
            FROM promessas_pagamento pp
            INNER JOIN pessoas p ON p.id = pp.pessoa_id
            LEFT JOIN contratos c ON c.id = pp.contrato_id
            LEFT JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN atendimentos_historico ah ON ah.id = pp.atendimento_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY
                CASE pp.status
                    WHEN \'pendente\' THEN 0
                    WHEN \'convertida_acordo\' THEN 1
                    WHEN \'cumprida\' THEN 2
                    WHEN \'quebrada\' THEN 3
                    ELSE 4
                END ASC,
                pp.data_promessa ASC,
                pp.id DESC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,int|float> */
    public function resumoGeral(): array
    {
        $fallback = [
            'total' => 0,
            'pendentes' => 0,
            'vencidas' => 0,
            'hoje' => 0,
            'cumpridas' => 0,
            'quebradas' => 0,
            'canceladas' => 0,
            'valor_pendente' => 0.0,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        $stmt = $this->pdo->query("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status = 'pendente' AND data_promessa < CURRENT_DATE THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN status = 'pendente' AND data_promessa = CURRENT_DATE THEN 1 ELSE 0 END) AS hoje,
                SUM(CASE WHEN status = 'cumprida' THEN 1 ELSE 0 END) AS cumpridas,
                SUM(CASE WHEN status = 'quebrada' THEN 1 ELSE 0 END) AS quebradas,
                SUM(CASE WHEN status = 'cancelada' THEN 1 ELSE 0 END) AS canceladas,
                SUM(CASE WHEN status = 'pendente' THEN valor_promessa ELSE 0 END) AS valor_pendente
            FROM promessas_pagamento");
        $row = $stmt !== false ? ($stmt->fetch() ?: []) : [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
            'vencidas' => (int) ($row['vencidas'] ?? 0),
            'hoje' => (int) ($row['hoje'] ?? 0),
            'cumpridas' => (int) ($row['cumpridas'] ?? 0),
            'quebradas' => (int) ($row['quebradas'] ?? 0),
            'canceladas' => (int) ($row['canceladas'] ?? 0),
            'valor_pendente' => (float) ($row['valor_pendente'] ?? 0),
        ];
    }

    public function atualizarStatus(int $promessaId, string $status, ?string $observacao = null): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de promessas indisponível.');
        }

        $status = $this->normalizarStatus($status);
        $observacao = $this->normalizarTexto($observacao, 2000);

        $stmt = $this->pdo->prepare('UPDATE promessas_pagamento
            SET status = :status,
                status_atualizado_em = NOW(),
                observacao = CASE
                    WHEN :observacao_check_null IS NULL OR :observacao_check_empty = \'\' THEN observacao
                    WHEN observacao IS NULL OR observacao = \'\' THEN :observacao_novo
                    ELSE CONCAT(observacao, "\n", :observacao_concat)
                END
            WHERE id = :id
            LIMIT 1');
        $stmt->bindValue('status', $status);
        $this->bindNullableString($stmt, 'observacao_check_null', $observacao);
        $this->bindNullableString($stmt, 'observacao_check_empty', $observacao);
        $this->bindNullableString($stmt, 'observacao_novo', $observacao);
        $this->bindNullableString($stmt, 'observacao_concat', $observacao);
        $stmt->bindValue('id', $promessaId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS[(string) $status] ?? 'Pendente';
    }

    public static function origemLabel(?string $origem): string
    {
        return self::ORIGENS[(string) $origem] ?? 'Manual';
    }

    private function contratoPertencePessoa(int $pessoaId, int $contratoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM contratos WHERE id = :id AND pessoa_id = :pessoa_id');
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function normalizarStatus(mixed $value): string
    {
        $status = trim((string) $value);
        if (!array_key_exists($status, self::STATUS)) {
            throw new InvalidArgumentException('Status da promessa inválido.');
        }

        return $status;
    }

    private function normalizarOrigem(mixed $value): string
    {
        $origem = trim((string) $value);
        if (!array_key_exists($origem, self::ORIGENS)) {
            return 'manual';
        }

        return $origem;
    }

    private function normalizarId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int) $id;
    }

    private function normalizarDecimalObrigatorio(mixed $value, string $campo): float
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            throw new InvalidArgumentException('Informe o ' . $campo . '.');
        }

        $texto = str_replace(['R$', ' '], '', $texto);
        $temVirgula = str_contains($texto, ',');
        $temPonto = str_contains($texto, '.');

        if ($temVirgula && $temPonto) {
            $ultimaVirgula = strrpos($texto, ',');
            $ultimoPonto = strrpos($texto, '.');
            if ($ultimaVirgula !== false && $ultimoPonto !== false && $ultimaVirgula > $ultimoPonto) {
                $texto = str_replace('.', '', $texto);
                $texto = str_replace(',', '.', $texto);
            } else {
                $texto = str_replace(',', '', $texto);
            }
        } elseif ($temVirgula) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        if (!is_numeric($texto)) {
            throw new InvalidArgumentException('Valor inválido para ' . $campo . '.');
        }

        $valor = (float) $texto;
        if ($valor <= 0) {
            throw new InvalidArgumentException('O ' . $campo . ' deve ser maior que zero.');
        }

        return round($valor, 2);
    }

    private function normalizarDataObrigatoria(mixed $value, string $campo): string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            throw new InvalidArgumentException('Informe a ' . $campo . '.');
        }

        try {
            return (new DateTimeImmutable($texto))->format('Y-m-d');
        } catch (Throwable) {
            throw new InvalidArgumentException('Data inválida para ' . $campo . '.');
        }
    }

    private function normalizarDataFiltro(mixed $value, bool $fimDoDia): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($texto))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizarTexto(mixed $value, int $limite): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        return mb_substr($texto, 0, $limite);
    }

    private function bindNullableInt(\PDOStatement $stmt, string $key, ?int $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    private function bindNullableString(\PDOStatement $stmt, string $key, ?string $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value);
    }
}
