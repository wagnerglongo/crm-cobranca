<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Services\AcionamentoIntegracaoService;
use CRM\Modules\Operacao\Support\EscopoCredorSql;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AtendimentoRepository
{
    public const CANAIS = [
        'ligacao' => 'Ligação',
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'email' => 'E-mail',
        'outro' => 'Outro',
    ];

    /**
     * Mantido para compatibilidade com a coluna antiga atendimentos_historico.resultado.
     * A nova tabulação usa atendimento_resultados.id e preserva esse campo legado.
     */
    public const RESULTADOS = [
        'sem_contato' => 'Sem contato',
        'nao_atende' => 'Não atende',
        'atendeu' => 'Atendeu',
        'ocupado' => 'Ocupado',
        'caixa_postal' => 'Caixa postal',
        'numero_invalido' => 'Número inválido',
        'recado' => 'Recado',
        'promessa_pagamento' => 'Promessa de pagamento',
        'em_negociacao' => 'Em negociação',
        'outro' => 'Outro',
    ];

    public const RETORNO_STATUS = [
        'pendente' => 'Pendente',
        'tratado' => 'Tratado',
        'reagendado' => 'Reagendado',
        'cancelado' => 'Cancelado',
    ];

    private ?bool $moduloDisponivel = null;
    private ?bool $resultadoPadronizadoDisponivel = null;
    private ?bool $retornoStatusDisponivel = null;
    private ?bool $campanhaItemVinculoDisponivel = null;
    private ?bool $usuarioIdDisponivel = null;
    private ?bool $motivoInadimplenciaDisponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'atendimentos_historico'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    public function resultadoPadronizadoDisponivel(): bool
    {
        if ($this->resultadoPadronizadoDisponivel !== null) {
            return $this->resultadoPadronizadoDisponivel;
        }

        try {
            $tableStmt = $this->pdo->query("SHOW TABLES LIKE 'atendimento_resultados'");
            $tableExists = $tableStmt !== false && $tableStmt->fetchColumn() !== false;

            $columnStmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'resultado_id'");
            $columnExists = $columnStmt !== false && $columnStmt->fetchColumn() !== false;

            $this->resultadoPadronizadoDisponivel = $this->moduloDisponivel() && $tableExists && $columnExists;
        } catch (Throwable) {
            $this->resultadoPadronizadoDisponivel = false;
        }

        return $this->resultadoPadronizadoDisponivel;
    }

    public function retornoStatusDisponivel(): bool
    {
        if ($this->retornoStatusDisponivel !== null) {
            return $this->retornoStatusDisponivel;
        }

        try {
            $statusStmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'retorno_status'");
            $tratadoStmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'retorno_tratado_em'");
            $observacaoStmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'retorno_observacao'");

            $this->retornoStatusDisponivel = $this->moduloDisponivel()
                && $statusStmt !== false && $statusStmt->fetchColumn() !== false
                && $tratadoStmt !== false && $tratadoStmt->fetchColumn() !== false
                && $observacaoStmt !== false && $observacaoStmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->retornoStatusDisponivel = false;
        }

        return $this->retornoStatusDisponivel;
    }


    public function campanhaItemVinculoDisponivel(): bool
    {
        if ($this->campanhaItemVinculoDisponivel !== null) {
            return $this->campanhaItemVinculoDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'campanha_item_id'");
            $this->campanhaItemVinculoDisponivel = $this->moduloDisponivel() && $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->campanhaItemVinculoDisponivel = false;
        }

        return $this->campanhaItemVinculoDisponivel;
    }

    public function usuarioIdDisponivel(): bool
    {
        if ($this->usuarioIdDisponivel !== null) {
            return $this->usuarioIdDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'usuario_id'");
            $this->usuarioIdDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->usuarioIdDisponivel = false;
        }

        return $this->usuarioIdDisponivel;
    }

    public function motivoInadimplenciaDisponivel(): bool
    {
        if ($this->motivoInadimplenciaDisponivel !== null) {
            return $this->motivoInadimplenciaDisponivel;
        }

        try {
            $tableStmt = $this->pdo->query("SHOW TABLES LIKE 'motivos_inadimplencia'");
            $tableExists = $tableStmt !== false && $tableStmt->fetchColumn() !== false;

            $columnStmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'motivo_inadimplencia_id'");
            $columnExists = $columnStmt !== false && $columnStmt->fetchColumn() !== false;

            $this->motivoInadimplenciaDisponivel = $tableExists && $columnExists;
        } catch (Throwable) {
            $this->motivoInadimplenciaDisponivel = false;
        }

        return $this->motivoInadimplenciaDisponivel;
    }

    /**
     * Ajuste (2026-09-23): motivo da inadimplência só faz
     * sentido quando a pessoa NÃO tem acordo/promessa já ativo - se já tem
     * um acordo em pé (pendente/formalizado), o "motivo de não ter
     * fechado" não se aplica. Escopado pelo contrato quando disponível
     * (o mesmo cliente pode ter outro contrato sem acordo); sem
     * contrato_id, verifica qualquer acordo ativo da pessoa.
     */
    private function possuiAcordoAtivo(int $pessoaId, ?int $contratoId): bool
    {
        try {
            $tableStmt = $this->pdo->query("SHOW TABLES LIKE 'acordos_operacionais'");
            if ($tableStmt === false || $tableStmt->fetchColumn() === false) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        if ($contratoId !== null) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM acordos_operacionais
                WHERE contrato_id = :contrato_id AND status_operacional IN ('pendente','formalizado') LIMIT 1");
            $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare("SELECT 1 FROM acordos_operacionais
                WHERE pessoa_id = :pessoa_id AND status_operacional IN ('pendente','formalizado') LIMIT 1");
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function listarResultadosAtivos(): array
    {
        if ($this->resultadoPadronizadoDisponivel()) {
            $stmt = $this->pdo->query('SELECT
                    id,
                    codigo,
                    nome,
                    categoria,
                    credor_id,
                    resultado_legacy,
                    descricao,
                    gera_reagendamento,
                    padrao_fechamento_acordo,
                    considera_sucesso,
                    considera_contato_efetivo,
                    considera_alo,
                    considera_telefone_ruim,
                    ativo,
                    ordem
                FROM atendimento_resultados
                WHERE ativo = 1
                ORDER BY ordem ASC, nome ASC');

            return $stmt !== false ? $stmt->fetchAll() : [];
        }

        $fallback = [];
        $ordem = 10;
        foreach (self::RESULTADOS as $codigoLegado => $nome) {
            $fallback[] = [
                'id' => null,
                'codigo' => strtoupper($codigoLegado),
                'nome' => $nome,
                'categoria' => 'Compatibilidade',
                'resultado_legacy' => $codigoLegado,
                'descricao' => null,
                'gera_reagendamento' => 0,
                'considera_sucesso' => in_array($codigoLegado, ['atendeu', 'promessa_pagamento', 'em_negociacao'], true) ? 1 : 0,
                'considera_contato_efetivo' => in_array($codigoLegado, ['atendeu', 'promessa_pagamento', 'em_negociacao'], true) ? 1 : 0,
                'considera_alo' => in_array($codigoLegado, ['atendeu', 'promessa_pagamento', 'em_negociacao'], true) ? 1 : 0,
                'considera_telefone_ruim' => $codigoLegado === 'numero_invalido' ? 1 : 0,
                'ativo' => 1,
                'ordem' => $ordem,
            ];
            $ordem += 10;
        }

        return $fallback;
    }

    /** @return array<string,mixed> */
    public function resumoPessoa(int $pessoaId): array
    {
        if (!$this->moduloDisponivel()) {
            return [
                'total_atendimentos' => 0,
                'ultimo_atendimento' => null,
                'proximo_acionamento' => null,
            ];
        }

        $proximoExpr = $this->retornoStatusDisponivel()
            ? "MIN(CASE WHEN proximo_acionamento IS NOT NULL AND COALESCE(retorno_status, 'pendente') = 'pendente' AND proximo_acionamento >= NOW() THEN proximo_acionamento ELSE NULL END)"
            : 'MIN(CASE WHEN proximo_acionamento >= NOW() THEN proximo_acionamento ELSE NULL END)';

        $stmt = $this->pdo->prepare('SELECT
                COUNT(*) AS total_atendimentos,
                MAX(criado_em) AS ultimo_atendimento,
                ' . $proximoExpr . ' AS proximo_acionamento
            FROM atendimentos_historico
            WHERE pessoa_id = :pessoa_id');
        $stmt->execute(['pessoa_id' => $pessoaId]);
        $row = $stmt->fetch() ?: [];

        return [
            'total_atendimentos' => (int) ($row['total_atendimentos'] ?? 0),
            'ultimo_atendimento' => $row['ultimo_atendimento'] ?? null,
            'proximo_acionamento' => $row['proximo_acionamento'] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listarPorPessoa(int $pessoaId, int $limite = 30, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 100));
        $where = ['ah.pessoa_id = :pessoa_id'];
        $params = ['pessoa_id' => $pessoaId];

        $scopeContrato = EscopoCredorSql::contrato($escopo, 'c', 'hist_pessoa_c');
        $scopePessoa = EscopoCredorSql::contrato($escopo, 'c_scope', 'hist_pessoa_p');
        $where[] = '((ah.contrato_id IS NOT NULL AND ' . $scopeContrato['sql'] . ') OR (ah.contrato_id IS NULL AND EXISTS (SELECT 1 FROM contratos c_scope WHERE c_scope.pessoa_id = ah.pessoa_id AND ' . $scopePessoa['sql'] . ')))';
        $params = array_merge($params, $scopeContrato['params'], $scopePessoa['params']);

        $resultadoSelect = $this->resultadoPadronizadoDisponivel()
            ? 'ar.codigo AS resultado_codigo, ar.nome AS resultado_nome, ar.categoria AS resultado_categoria, ar.gera_reagendamento, ar.considera_sucesso, ar.considera_contato_efetivo, ar.considera_telefone_ruim'
            : 'NULL AS resultado_codigo, NULL AS resultado_nome, NULL AS resultado_categoria, NULL AS gera_reagendamento, NULL AS considera_sucesso, NULL AS considera_contato_efetivo, NULL AS considera_telefone_ruim';
        $resultadoJoin = $this->resultadoPadronizadoDisponivel()
            ? 'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id'
            : '';
        $retornoSelect = $this->retornoStatusDisponivel()
            ? "COALESCE(ah.retorno_status, CASE WHEN ah.proximo_acionamento IS NOT NULL THEN 'pendente' ELSE NULL END) AS retorno_status_normalizado, ah.retorno_status, ah.retorno_tratado_em, ah.retorno_observacao"
            : "CASE WHEN ah.proximo_acionamento IS NOT NULL THEN 'pendente' ELSE NULL END AS retorno_status_normalizado, NULL AS retorno_status, NULL AS retorno_tratado_em, NULL AS retorno_observacao";

        $stmt = $this->pdo->prepare('SELECT
                ah.*,
                ' . $resultadoSelect . ',
                ' . $retornoSelect . ',
                c.codigo_contrato,
                c.produto,
                pc.tipo AS contato_tipo,
                pc.valor AS contato_valor
            FROM atendimentos_historico ah
            ' . $resultadoJoin . '
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            LEFT JOIN pessoa_contatos pc ON pc.id = ah.contato_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ah.criado_em DESC, ah.id DESC
            LIMIT :limite');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    public function resumoGeral(): array
    {
        if (!$this->moduloDisponivel()) {
            return [
                'total_atendimentos' => 0,
                'total_hoje' => 0,
                'proximos_pendentes' => 0,
            ];
        }

        $pendenteExpr = $this->retornoStatusDisponivel()
            ? "proximo_acionamento IS NOT NULL AND COALESCE(retorno_status, 'pendente') = 'pendente' AND proximo_acionamento >= NOW()"
            : 'proximo_acionamento >= NOW()';

        $stmt = $this->pdo->query('SELECT
                COUNT(*) AS total_atendimentos,
                SUM(CASE WHEN DATE(criado_em) = CURRENT_DATE THEN 1 ELSE 0 END) AS total_hoje,
                SUM(CASE WHEN ' . $pendenteExpr . ' THEN 1 ELSE 0 END) AS proximos_pendentes
            FROM atendimentos_historico');
        $row = $stmt !== false ? ($stmt->fetch() ?: []) : [];

        return [
            'total_atendimentos' => (int) ($row['total_atendimentos'] ?? 0),
            'total_hoje' => (int) ($row['total_hoje'] ?? 0),
            'proximos_pendentes' => (int) ($row['proximos_pendentes'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $filtros
     * @return list<array<string,mixed>>
     */
    public function listarRecentes(array $filtros = [], int $limite = 50, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 100));
        $where = [];
        $params = [];

        $termo = trim((string) ($filtros['q'] ?? ''));
        if ($termo !== '' && mb_strlen($termo) >= 2) {
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';
            $buscas = [];

            $buscas[] = 'p.nome LIKE :q_texto_nome';
            $buscas[] = 'c.codigo_contrato LIKE :q_texto_contrato';
            $buscas[] = 'ah.contato_utilizado LIKE :q_texto_contato';
            $params['q_texto_nome'] = '%' . $termo . '%';
            $params['q_texto_contrato'] = '%' . $termo . '%';
            $params['q_texto_contato'] = '%' . $termo . '%';

            if ($digitos !== '' && strlen($digitos) >= 3) {
                $buscas[] = 'p.documento LIKE :q_digitos_documento';
                $buscas[] = 'pc.valor_normalizado LIKE :q_digitos_contato';
                $buscas[] = 'REPLACE(REPLACE(REPLACE(REPLACE(ah.contato_utilizado, \'.\', \'\'), \'-\', \'\'), \')\', \'\'), \'(\', \'\') LIKE :q_digitos_atendimento';
                $params['q_digitos_documento'] = '%' . $digitos . '%';
                $params['q_digitos_contato'] = '%' . $digitos . '%';
                $params['q_digitos_atendimento'] = '%' . $digitos . '%';
            }

            $where[] = '(' . implode(' OR ', $buscas) . ')';
        }

        $canal = trim((string) ($filtros['canal'] ?? ''));
        if ($canal !== '' && array_key_exists($canal, self::CANAIS)) {
            $where[] = 'ah.canal = :canal';
            $params['canal'] = $canal;
        }

        $resultadoFiltro = $this->normalizarResultadoFiltro($filtros);
        if ($resultadoFiltro !== null) {
            if ($resultadoFiltro['tipo'] === 'id') {
                $where[] = 'ah.resultado_id = :resultado_id';
                $params['resultado_id'] = $resultadoFiltro['valor'];
            } else {
                $where[] = 'ah.resultado = :resultado';
                $params['resultado'] = $resultadoFiltro['valor'];
            }
        }

        $dataInicio = $this->normalizarDataFiltro($filtros['data_inicio'] ?? null, false);
        if ($dataInicio !== null) {
            $where[] = 'ah.criado_em >= :data_inicio';
            $params['data_inicio'] = $dataInicio;
        }

        $dataFim = $this->normalizarDataFiltro($filtros['data_fim'] ?? null, true);
        if ($dataFim !== null) {
            $where[] = 'ah.criado_em <= :data_fim';
            $params['data_fim'] = $dataFim;
        }

        $scopeContrato = EscopoCredorSql::contrato($escopo, 'c', 'recentes_c');
        $scopePessoa = EscopoCredorSql::contrato($escopo, 'c_scope', 'recentes_p');
        $where[] = '((ah.contrato_id IS NOT NULL AND ' . $scopeContrato['sql'] . ') OR (ah.contrato_id IS NULL AND EXISTS (SELECT 1 FROM contratos c_scope WHERE c_scope.pessoa_id = ah.pessoa_id AND ' . $scopePessoa['sql'] . ')))';
        $params = array_merge($params, $scopeContrato['params'], $scopePessoa['params']);

        $resultadoSelect = $this->resultadoPadronizadoDisponivel()
            ? 'ar.codigo AS resultado_codigo, ar.nome AS resultado_nome, ar.categoria AS resultado_categoria, ar.gera_reagendamento, ar.considera_sucesso, ar.considera_contato_efetivo, ar.considera_telefone_ruim'
            : 'NULL AS resultado_codigo, NULL AS resultado_nome, NULL AS resultado_categoria, NULL AS gera_reagendamento, NULL AS considera_sucesso, NULL AS considera_contato_efetivo, NULL AS considera_telefone_ruim';
        $resultadoJoin = $this->resultadoPadronizadoDisponivel()
            ? 'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id'
            : '';

        $sql = 'SELECT
                ah.id,
                ah.pessoa_id,
                ah.contrato_id,
                ah.contato_id,
                ah.operador_nome,
                ah.canal,
                ah.resultado,
                ' . ($this->resultadoPadronizadoDisponivel() ? 'ah.resultado_id,' : 'NULL AS resultado_id,') . '
                ' . $resultadoSelect . ',
                ah.contato_utilizado,
                ah.observacao,
                ah.proximo_acionamento,
                ah.origem,
                ah.criado_em,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                c.codigo_contrato,
                c.produto,
                cr.nome AS credor_nome,
                pc.tipo AS contato_tipo,
                pc.valor AS contato_valor
            FROM atendimentos_historico ah
            ' . $resultadoJoin . '
            INNER JOIN pessoas p ON p.id = ah.pessoa_id
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            LEFT JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN pessoa_contatos pc ON pc.id = ah.contato_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY ah.criado_em DESC, ah.id DESC LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = $key === 'resultado_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $paramType);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }


    /** @return array<string,int> */
    public function resumoRetornos(?array $escopo = null): array
    {
        $fallback = [
            'total_pendentes' => 0,
            'vencidos' => 0,
            'hoje' => 0,
            'futuros' => 0,
            'tratados' => 0,
            'reagendados' => 0,
            'cancelados' => 0,
            'total_geral' => 0,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        $scopeContrato = EscopoCredorSql::contrato($escopo, 'c', 'resumo_ret_c');
        $scopePessoa = EscopoCredorSql::contrato($escopo, 'c_scope', 'resumo_ret_p');
        $scopeSql = '((ah.contrato_id IS NOT NULL AND ' . $scopeContrato['sql'] . ') OR (ah.contrato_id IS NULL AND EXISTS (SELECT 1 FROM contratos c_scope WHERE c_scope.pessoa_id = ah.pessoa_id AND ' . $scopePessoa['sql'] . ')))';
        $scopeParams = array_merge($scopeContrato['params'], $scopePessoa['params']);

        if ($this->retornoStatusDisponivel()) {
            $stmt = $this->pdo->prepare("SELECT
                    COUNT(*) AS total_geral,
                    SUM(CASE WHEN COALESCE(retorno_status, 'pendente') = 'pendente' THEN 1 ELSE 0 END) AS total_pendentes,
                    SUM(CASE WHEN COALESCE(retorno_status, 'pendente') = 'pendente' AND proximo_acionamento < NOW() THEN 1 ELSE 0 END) AS vencidos,
                    SUM(CASE WHEN COALESCE(retorno_status, 'pendente') = 'pendente' AND DATE(proximo_acionamento) = CURRENT_DATE THEN 1 ELSE 0 END) AS hoje,
                    SUM(CASE WHEN COALESCE(retorno_status, 'pendente') = 'pendente' AND proximo_acionamento > CONCAT(CURRENT_DATE, ' 23:59:59') THEN 1 ELSE 0 END) AS futuros,
                    SUM(CASE WHEN retorno_status = 'tratado' THEN 1 ELSE 0 END) AS tratados,
                    SUM(CASE WHEN retorno_status = 'reagendado' THEN 1 ELSE 0 END) AS reagendados,
                    SUM(CASE WHEN retorno_status = 'cancelado' THEN 1 ELSE 0 END) AS cancelados
                FROM atendimentos_historico ah
                LEFT JOIN contratos c ON c.id = ah.contrato_id
                WHERE ah.proximo_acionamento IS NOT NULL AND " . $scopeSql);
        } else {
            $stmt = $this->pdo->prepare("SELECT
                    COUNT(*) AS total_geral,
                    COUNT(*) AS total_pendentes,
                    SUM(CASE WHEN proximo_acionamento < NOW() THEN 1 ELSE 0 END) AS vencidos,
                    SUM(CASE WHEN DATE(proximo_acionamento) = CURRENT_DATE THEN 1 ELSE 0 END) AS hoje,
                    SUM(CASE WHEN proximo_acionamento > CONCAT(CURRENT_DATE, ' 23:59:59') THEN 1 ELSE 0 END) AS futuros,
                    0 AS tratados,
                    0 AS reagendados,
                    0 AS cancelados
                FROM atendimentos_historico ah
                LEFT JOIN contratos c ON c.id = ah.contrato_id
                WHERE ah.proximo_acionamento IS NOT NULL AND " . $scopeSql);
        }

        if ($stmt !== false) {
            EscopoCredorSql::bind($stmt, $scopeParams);
            $stmt->execute();
        }
        $row = $stmt !== false ? ($stmt->fetch() ?: []) : [];

        return [
            'total_pendentes' => (int) ($row['total_pendentes'] ?? 0),
            'vencidos' => (int) ($row['vencidos'] ?? 0),
            'hoje' => (int) ($row['hoje'] ?? 0),
            'futuros' => (int) ($row['futuros'] ?? 0),
            'tratados' => (int) ($row['tratados'] ?? 0),
            'reagendados' => (int) ($row['reagendados'] ?? 0),
            'cancelados' => (int) ($row['cancelados'] ?? 0),
            'total_geral' => (int) ($row['total_geral'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $filtros
     * @return list<array<string,mixed>>
     */
    public function listarRetornos(array $filtros = [], int $limite = 100, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 150));
        $where = ['ah.proximo_acionamento IS NOT NULL'];
        $params = [];

        $status = $this->normalizarRetornoStatusFiltro($filtros['status'] ?? 'pendente');
        if ($status !== 'todos') {
            if ($this->retornoStatusDisponivel()) {
                $where[] = "COALESCE(ah.retorno_status, 'pendente') = :retorno_status";
                $params['retorno_status'] = $status;
            } elseif ($status !== 'pendente') {
                return [];
            }
        }

        $situacao = trim((string) ($filtros['situacao'] ?? 'todos'));
        if ($situacao === 'pendentes') {
            $situacao = 'todos';
        }
        if (!in_array($situacao, ['vencidos', 'hoje', 'futuros', 'todos'], true)) {
            $situacao = 'todos';
        }

        if ($situacao === 'vencidos') {
            $where[] = 'ah.proximo_acionamento < NOW()';
        } elseif ($situacao === 'hoje') {
            $where[] = 'DATE(ah.proximo_acionamento) = CURRENT_DATE';
        } elseif ($situacao === 'futuros') {
            $where[] = 'ah.proximo_acionamento > CONCAT(CURRENT_DATE, \' 23:59:59\')';
        }

        $termo = trim((string) ($filtros['q'] ?? ''));
        if ($termo !== '' && mb_strlen($termo) >= 2) {
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';
            $buscas = [];

            $buscas[] = 'p.nome LIKE :q_texto_nome';
            $buscas[] = 'c.codigo_contrato LIKE :q_texto_contrato';
            $buscas[] = 'ah.contato_utilizado LIKE :q_texto_contato';
            $params['q_texto_nome'] = '%' . $termo . '%';
            $params['q_texto_contrato'] = '%' . $termo . '%';
            $params['q_texto_contato'] = '%' . $termo . '%';

            if ($digitos !== '' && strlen($digitos) >= 3) {
                $buscas[] = 'p.documento LIKE :q_digitos_documento';
                $buscas[] = 'pc.valor_normalizado LIKE :q_digitos_contato';
                $buscas[] = 'REPLACE(REPLACE(REPLACE(REPLACE(ah.contato_utilizado, \'.\', \'\'), \'-\', \'\'), \')\', \'\'), \'(\', \'\') LIKE :q_digitos_atendimento';
                $params['q_digitos_documento'] = '%' . $digitos . '%';
                $params['q_digitos_contato'] = '%' . $digitos . '%';
                $params['q_digitos_atendimento'] = '%' . $digitos . '%';
            }

            $where[] = '(' . implode(' OR ', $buscas) . ')';
        }

        $canal = trim((string) ($filtros['canal'] ?? ''));
        if ($canal !== '' && array_key_exists($canal, self::CANAIS)) {
            $where[] = 'ah.canal = :canal';
            $params['canal'] = $canal;
        }

        $resultadoFiltro = $this->normalizarResultadoFiltro($filtros);
        if ($resultadoFiltro !== null) {
            if ($resultadoFiltro['tipo'] === 'id') {
                $where[] = 'ah.resultado_id = :resultado_id';
                $params['resultado_id'] = $resultadoFiltro['valor'];
            } else {
                $where[] = 'ah.resultado = :resultado';
                $params['resultado'] = $resultadoFiltro['valor'];
            }
        }

        $dataInicio = $this->normalizarDataFiltro($filtros['data_inicio'] ?? null, false);
        if ($dataInicio !== null) {
            $where[] = 'ah.proximo_acionamento >= :data_inicio';
            $params['data_inicio'] = $dataInicio;
        }

        $dataFim = $this->normalizarDataFiltro($filtros['data_fim'] ?? null, true);
        if ($dataFim !== null) {
            $where[] = 'ah.proximo_acionamento <= :data_fim';
            $params['data_fim'] = $dataFim;
        }

        $scopeContrato = EscopoCredorSql::contrato($escopo, 'c', 'retornos_c');
        $scopePessoa = EscopoCredorSql::contrato($escopo, 'c_scope', 'retornos_p');
        $where[] = '((ah.contrato_id IS NOT NULL AND ' . $scopeContrato['sql'] . ') OR (ah.contrato_id IS NULL AND EXISTS (SELECT 1 FROM contratos c_scope WHERE c_scope.pessoa_id = ah.pessoa_id AND ' . $scopePessoa['sql'] . ')))';
        $params = array_merge($params, $scopeContrato['params'], $scopePessoa['params']);

        $resultadoSelect = $this->resultadoPadronizadoDisponivel()
            ? 'ar.codigo AS resultado_codigo, ar.nome AS resultado_nome, ar.categoria AS resultado_categoria, ar.gera_reagendamento, ar.considera_sucesso, ar.considera_contato_efetivo, ar.considera_telefone_ruim'
            : 'NULL AS resultado_codigo, NULL AS resultado_nome, NULL AS resultado_categoria, NULL AS gera_reagendamento, NULL AS considera_sucesso, NULL AS considera_contato_efetivo, NULL AS considera_telefone_ruim';
        $resultadoJoin = $this->resultadoPadronizadoDisponivel()
            ? 'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id'
            : '';
        $retornoSelect = $this->retornoStatusDisponivel()
            ? "COALESCE(ah.retorno_status, 'pendente') AS retorno_status, ah.retorno_tratado_em, ah.retorno_observacao"
            : "'pendente' AS retorno_status, NULL AS retorno_tratado_em, NULL AS retorno_observacao";

        $sql = 'SELECT
                ah.id,
                ah.pessoa_id,
                ah.contrato_id,
                ah.contato_id,
                ah.operador_nome,
                ah.canal,
                ah.resultado,
                ' . ($this->resultadoPadronizadoDisponivel() ? 'ah.resultado_id,' : 'NULL AS resultado_id,') . '
                ' . $resultadoSelect . ',
                ' . $retornoSelect . ',
                ah.contato_utilizado,
                ah.observacao,
                ah.proximo_acionamento,
                ah.criado_em,
                CASE
                    WHEN ah.proximo_acionamento < NOW() THEN \'vencido\'
                    WHEN DATE(ah.proximo_acionamento) = CURRENT_DATE THEN \'hoje\'
                    ELSE \'futuro\'
                END AS retorno_situacao,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                c.codigo_contrato,
                c.produto,
                cr.nome AS credor_nome,
                pc.tipo AS contato_tipo,
                pc.valor AS contato_valor
            FROM atendimentos_historico ah
            ' . $resultadoJoin . '
            INNER JOIN pessoas p ON p.id = ah.pessoa_id
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            LEFT JOIN credores cr ON cr.id = c.credor_id
            LEFT JOIN pessoa_contatos pc ON pc.id = ah.contato_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
                CASE
                    WHEN ' . ($this->retornoStatusDisponivel() ? "COALESCE(ah.retorno_status, 'pendente') = 'pendente'" : '1 = 1') . ' THEN 0
                    ELSE 1
                END ASC,
                CASE
                    WHEN ah.proximo_acionamento < NOW() THEN 0
                    WHEN DATE(ah.proximo_acionamento) = CURRENT_DATE THEN 1
                    ELSE 2
                END ASC,
                ah.proximo_acionamento ASC,
                ah.id ASC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = in_array($key, ['resultado_id'], true) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $paramType);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listarRetornosPendentesPessoa(int $pessoaId, int $limite = 5, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 20));
        $where = ['ah.pessoa_id = :pessoa_id', 'ah.proximo_acionamento IS NOT NULL'];
        $params = ['pessoa_id' => $pessoaId];

        $scopeContrato = EscopoCredorSql::contrato($escopo, 'c', 'ret_pessoa_c');
        $scopePessoa = EscopoCredorSql::contrato($escopo, 'c_scope', 'ret_pessoa_p');
        $where[] = '((ah.contrato_id IS NOT NULL AND ' . $scopeContrato['sql'] . ') OR (ah.contrato_id IS NULL AND EXISTS (SELECT 1 FROM contratos c_scope WHERE c_scope.pessoa_id = ah.pessoa_id AND ' . $scopePessoa['sql'] . ')))';
        $params = array_merge($params, $scopeContrato['params'], $scopePessoa['params']);

        if ($this->retornoStatusDisponivel()) {
            $where[] = "COALESCE(ah.retorno_status, 'pendente') = 'pendente'";
            $retornoSelect = "COALESCE(ah.retorno_status, 'pendente') AS retorno_status, ah.retorno_tratado_em, ah.retorno_observacao";
        } else {
            $retornoSelect = "'pendente' AS retorno_status, NULL AS retorno_tratado_em, NULL AS retorno_observacao";
        }

        $stmt = $this->pdo->prepare('SELECT
                ah.id,
                ah.pessoa_id,
                ah.canal,
                ah.resultado,
                ah.contato_utilizado,
                ah.observacao,
                ah.proximo_acionamento,
                ah.criado_em,
                ' . $retornoSelect . ',
                CASE
                    WHEN ah.proximo_acionamento < NOW() THEN \'vencido\'
                    WHEN DATE(ah.proximo_acionamento) = CURRENT_DATE THEN \'hoje\'
                    ELSE \'futuro\'
                END AS retorno_situacao,
                c.codigo_contrato,
                c.produto
            FROM atendimentos_historico ah
            LEFT JOIN contratos c ON c.id = ah.contrato_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
                CASE
                    WHEN ah.proximo_acionamento < NOW() THEN 0
                    WHEN DATE(ah.proximo_acionamento) = CURRENT_DATE THEN 1
                    ELSE 2
                END ASC,
                ah.proximo_acionamento ASC,
                ah.id ASC
            LIMIT :limite');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function atualizarStatusRetorno(int $retornoId, string $status, ?string $observacao = null, ?int $pessoaId = null): void
    {
        if (!$this->retornoStatusDisponivel()) {
            throw new InvalidArgumentException('Controle de status dos retornos indisponível. Execute a migration 011 antes de atualizar retornos.');
        }

        if ($retornoId <= 0) {
            throw new InvalidArgumentException('Retorno inválido.');
        }

        $status = $this->validarOpcao($status, self::RETORNO_STATUS, 'status do retorno');
        $observacao = $this->normalizarTexto($observacao, 1000);

        $tratadoEmSql = $status === 'pendente' ? 'NULL' : 'NOW()';
        $where = 'id = :id AND proximo_acionamento IS NOT NULL';
        if ($pessoaId !== null) {
            $where .= ' AND pessoa_id = :pessoa_id';
        }

        $stmt = $this->pdo->prepare('UPDATE atendimentos_historico
            SET retorno_status = :status,
                retorno_tratado_em = ' . $tratadoEmSql . ',
                retorno_observacao = :observacao
            WHERE ' . $where . '
            LIMIT 1');
        $stmt->bindValue('id', $retornoId, PDO::PARAM_INT);
        $stmt->bindValue('status', $status);
        $this->bindNullableString($stmt, 'observacao', $observacao);
        if ($pessoaId !== null) {
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        }
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Retorno não encontrado ou sem próximo acionamento.');
        }
    }

    public static function retornoSituacaoLabel(?string $situacao): string
    {
        return match ((string) $situacao) {
            'vencido' => 'Vencido',
            'hoje' => 'Hoje',
            'futuro' => 'Futuro',
            default => 'Pendente',
        };
    }

    public static function retornoStatusLabel(?string $status): string
    {
        return self::RETORNO_STATUS[(string) $status] ?? 'Pendente';
    }

    /** @param array<string,mixed> $dados */
    public function registrar(int $pessoaId, array $dados): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('A tabela atendimentos_historico ainda não existe. Execute a migration 009 antes de registrar atendimentos.');
        }

        $canal = $this->validarOpcao((string) ($dados['canal'] ?? ''), self::CANAIS, 'canal');
        $resultadoId = null;
        $ehCpcSemSucesso = false;
        $contratoId = $this->normalizarId($dados['contrato_id'] ?? null);

        $geraReagendamento = false;
        if ($this->resultadoPadronizadoDisponivel()) {
            $resultado = $this->normalizarResultadoPadronizado($dados['resultado_id'] ?? null);
            $resultadoId = (int) $resultado['id'];
            $resultadoLegado = $this->validarResultadoLegado((string) $resultado['resultado_legacy']);
            $geraReagendamento = (int) ($resultado['gera_reagendamento'] ?? 0) === 1;
            // Ajuste (2026-09-23, duas rodadas): motivo só é pedido
            // quando teve contato efetivo E NÃO teve sucesso - se já teve
            // sucesso (ex.: acordo fechado, pagamento efetuado), não faz
            // sentido perguntar por que não fechou. E também não pergunta se
            // a pessoa já tem um acordo/promessa ativo em aberto - aí o
            // motivo de não ter fechado já não se aplica.
            $ehCpcSemSucesso = (int) ($resultado['considera_contato_efetivo'] ?? 0) === 1
                && (int) ($resultado['considera_sucesso'] ?? 0) === 0
                && !$this->possuiAcordoAtivo($pessoaId, $contratoId);
        } else {
            $resultadoLegado = $this->validarResultadoLegado((string) ($dados['resultado'] ?? ''));
        }

        // Regra de negócio (2026-09-23): quando o acionamento é CPC (contato
        // com a pessoa certa) sem sucesso e sem acordo/promessa já ativo, o
        // operador teve contato de verdade com o cliente e não fechou nada -
        // então precisa dizer o motivo. Lista configurável em Administração
        // > Motivos de Inadimplência.
        $motivoInadimplenciaId = null;
        if ($this->motivoInadimplenciaDisponivel()) {
            $motivoInadimplenciaId = $this->normalizarId($dados['motivo_inadimplencia_id'] ?? null);
            if ($ehCpcSemSucesso) {
                if ($motivoInadimplenciaId === null) {
                    throw new InvalidArgumentException('Como houve contato com a pessoa certa (CPC) sem sucesso e sem acordo ativo, informe o motivo da inadimplência.');
                }
                $motivoStmt = $this->pdo->prepare('SELECT id FROM motivos_inadimplencia WHERE id = :id AND ativo = 1 LIMIT 1');
                $motivoStmt->bindValue('id', $motivoInadimplenciaId, PDO::PARAM_INT);
                $motivoStmt->execute();
                if ($motivoStmt->fetchColumn() === false) {
                    throw new InvalidArgumentException('Motivo de inadimplência inválido ou inativo.');
                }
            } else {
                $motivoInadimplenciaId = null;
            }
        }

        $contatoId = $this->normalizarId($dados['contato_id'] ?? null);
        $campanhaItemId = $this->campanhaItemVinculoDisponivel() ? $this->normalizarId($dados['campanha_item_id'] ?? null) : null;
        $estrategiaItemId = $this->normalizarId($dados['estrategia_item_id'] ?? null);
        $usuarioId = $this->usuarioIdDisponivel() ? $this->normalizarId($dados['usuario_id'] ?? null) : null;
        $operadorNome = $this->normalizarTexto($dados['operador_nome'] ?? null, 120);
        $contatoUtilizado = $this->normalizarTexto($dados['contato_utilizado'] ?? null, 255);
        $observacao = $this->normalizarTexto($dados['observacao'] ?? null, 3000);
        $proximoAcionamento = $this->normalizarDataHora($dados['proximo_acionamento'] ?? null);

        // Achado 2026-09-28: tabulação marcada como "gera reagendamento"
        // (ex.: Recado - Terceiros, Retornar Contato) sem data de retorno
        // preenchida não entrava na fila de futuros e, quando sincronizada
        // com a ACME, a API rejeita sem o horário do agendamento (mensagem
        // real: "Para Recado deve informar Data e Hora!") - o acionamento
        // ficava preso tentando de novo até esgotar as tentativas, sem
        // nunca sincronizar.
        if ($geraReagendamento && $proximoAcionamento === null) {
            throw new InvalidArgumentException('Esta tabulação exige reagendamento: informe a data e hora do próximo acionamento.');
        }

        // Regra de negócio (2026-09-23): o contato usado é obrigatório em todo
        // acionamento, não só uma alternativa quando falta observação - sem
        // isso não dá pra saber depois por qual telefone/e-mail o cliente foi
        // contatado.
        if ($contatoId === null && $contatoUtilizado === null) {
            throw new InvalidArgumentException('Informe o contato utilizado no acionamento.');
        }

        if ($contratoId !== null && !$this->contratoPertencePessoa($pessoaId, $contratoId)) {
            throw new InvalidArgumentException('Contrato informado não pertence a este cliente.');
        }

        if ($contatoId !== null) {
            $contatoBanco = $this->buscarContatoDaPessoa($pessoaId, $contatoId);
            if ($contatoBanco === null) {
                throw new InvalidArgumentException('Contato informado não pertence a este cliente.');
            }

            // Regra de negócio (2026-09-23): número negativado não pode ser
            // usado pra acionamento (nem, por extensão, pra fechar acordo -
            // fechar acordo no Pactua registra um acionamento de promessa de
            // pagamento usando este mesmo método).
            if ($this->contatoNegativado($contatoBanco)) {
                throw new InvalidArgumentException('Este contato está negativado e não pode ser usado para acionamento.');
            }

            if ($contatoUtilizado === null) {
                $contatoUtilizado = (string) $contatoBanco['valor'];
            }
        } elseif ($contatoUtilizado !== null) {
            // Achado 2026-09-28: a ficha do cliente tem um campo "Contato
            // manual" além da lista (pra número/e-mail que ainda não está
            // cadastrado) - a lista já esconde os negativados, mas digitando
            // o mesmo valor à mão nesse campo o bloqueio acima nunca rodava,
            // porque só considerava contato_id. Confere aqui também contra
            // os contatos já cadastrados da pessoa.
            if ($this->contatoUtilizadoNegativado($pessoaId, $contatoUtilizado)) {
                throw new InvalidArgumentException('Este contato está negativado e não pode ser usado para acionamento.');
            }
        }

        $payloadExtra = [
            'origem_tela' => 'operacao/cliente_detalhe.php',
            'sem_login' => $usuarioId === null,
            'resultado_padronizado' => $resultadoId !== null,
        ];

        $usuarioColumn = $usuarioId !== null ? "                    usuario_id,\n" : '';
        $usuarioValue = $usuarioId !== null ? "                    :usuario_id,\n" : '';

        $campanhaItemColumn = $campanhaItemId !== null ? "                    campanha_item_id,\n" : '';
        $campanhaItemValue = $campanhaItemId !== null ? "                    :campanha_item_id,\n" : '';

        if ($campanhaItemId !== null) {
            $payloadExtra['campanha_item_id'] = $campanhaItemId;
            $payloadExtra['origem_fila'] = 'campanha_manual';
        }

        if ($estrategiaItemId !== null) {
            $payloadExtra['estrategia_item_id'] = $estrategiaItemId;
            $payloadExtra['origem_fila'] = 'estrategia_operacional';
            $payloadExtra['origem_tela'] = 'operacao/works.php';
        }

        if ($this->resultadoPadronizadoDisponivel()) {
            $motivoColumn = $this->motivoInadimplenciaDisponivel() ? "                    motivo_inadimplencia_id,\n" : '';
            $motivoValue = $this->motivoInadimplenciaDisponivel() ? "                    :motivo_inadimplencia_id,\n" : '';

            $stmt = $this->pdo->prepare('INSERT INTO atendimentos_historico (
                    pessoa_id,
' . $usuarioColumn . '                    contrato_id,
                    contato_id,
' . $campanhaItemColumn . '
                    operador_nome,
                    canal,
                    resultado,
                    resultado_id,
' . $motivoColumn . '                    contato_utilizado,
                    observacao,
                    proximo_acionamento,
                    origem,
                    payload_extra
                ) VALUES (
                    :pessoa_id,
' . $usuarioValue . '                    :contrato_id,
                    :contato_id,
' . $campanhaItemValue . '
                    :operador_nome,
                    :canal,
                    :resultado,
                    :resultado_id,
' . $motivoValue . '                    :contato_utilizado,
                    :observacao,
                    :proximo_acionamento,
                    \'manual\',
                    :payload_extra
                )');
            $this->bindNullableInt($stmt, 'resultado_id', $resultadoId);
            if ($this->motivoInadimplenciaDisponivel()) {
                $this->bindNullableInt($stmt, 'motivo_inadimplencia_id', $motivoInadimplenciaId);
            }
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO atendimentos_historico (
                    pessoa_id,
' . $usuarioColumn . '                    contrato_id,
                    contato_id,
' . $campanhaItemColumn . '
                    operador_nome,
                    canal,
                    resultado,
                    contato_utilizado,
                    observacao,
                    proximo_acionamento,
                    origem,
                    payload_extra
                ) VALUES (
                    :pessoa_id,
' . $usuarioValue . '                    :contrato_id,
                    :contato_id,
' . $campanhaItemValue . '
                    :operador_nome,
                    :canal,
                    :resultado,
                    :contato_utilizado,
                    :observacao,
                    :proximo_acionamento,
                    \'manual\',
                    :payload_extra
                )');
        }

        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        if ($usuarioId !== null) {
            $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        }
        $this->bindNullableInt($stmt, 'contrato_id', $contratoId);
        $this->bindNullableInt($stmt, 'contato_id', $contatoId);
        if ($campanhaItemId !== null) {
            $stmt->bindValue('campanha_item_id', $campanhaItemId, PDO::PARAM_INT);
        }
        $this->bindNullableString($stmt, 'operador_nome', $operadorNome);
        $stmt->bindValue('canal', $canal);
        $stmt->bindValue('resultado', $resultadoLegado);
        $this->bindNullableString($stmt, 'contato_utilizado', $contatoUtilizado);
        $this->bindNullableString($stmt, 'observacao', $observacao);
        $this->bindNullableString($stmt, 'proximo_acionamento', $proximoAcionamento);
        $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $iniciouTransacao = !$this->pdo->inTransaction();
        try {
            if ($iniciouTransacao) {
                $this->pdo->beginTransaction();
            }

            $stmt->execute();
            $atendimentoId = (int) $this->pdo->lastInsertId();

            // O de/para e a outbox sao gravados junto com o acionamento. A chamada
            // ao credor ocorre depois, no worker, e nunca bloqueia a tela do operador.
            (new AcionamentoIntegracaoService($this->pdo))->enfileirarAtendimento($atendimentoId);

            if ($iniciouTransacao) {
                $this->pdo->commit();
            }

            return $atendimentoId;
        } catch (Throwable $e) {
            if ($iniciouTransacao && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function canalLabel(?string $canal): string
    {
        return self::CANAIS[(string) $canal] ?? 'Outro';
    }

    public static function resultadoLabel(?string $resultado): string
    {
        return self::RESULTADOS[(string) $resultado] ?? 'Outro';
    }

    /** @param array<string,mixed> $registro */
    public static function resultadoLabelRegistro(array $registro): string
    {
        $nomePadronizado = trim((string) ($registro['resultado_nome'] ?? ''));
        if ($nomePadronizado !== '') {
            return $nomePadronizado;
        }

        return self::resultadoLabel(isset($registro['resultado']) ? (string) $registro['resultado'] : null);
    }

    /** @param array<string,string> $opcoes */
    private function validarOpcao(string $valor, array $opcoes, string $campo): string
    {
        $valor = trim($valor);
        if ($valor === '' || !array_key_exists($valor, $opcoes)) {
            throw new InvalidArgumentException('Opção inválida para ' . $campo . '.');
        }

        return $valor;
    }

    private function validarResultadoLegado(string $valor): string
    {
        return $this->validarOpcao($valor, self::RESULTADOS, 'resultado');
    }

    /** @return array<string,mixed> */
    private function normalizarResultadoPadronizado(mixed $value): array
    {
        $id = $this->normalizarId($value);
        if ($id === null) {
            throw new InvalidArgumentException('Selecione um resultado de atendimento.');
        }

        $stmt = $this->pdo->prepare('SELECT id, codigo, nome, categoria, resultado_legacy, considera_contato_efetivo, considera_sucesso, gera_reagendamento, ativo
            FROM atendimento_resultados
            WHERE id = :id AND ativo = 1
            LIMIT 1');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            throw new InvalidArgumentException('Resultado de atendimento inválido ou inativo.');
        }

        return $row;
    }

    private function normalizarRetornoStatusFiltro(mixed $value): string
    {
        $status = trim((string) ($value ?? 'pendente'));

        if ($status === '') {
            return 'pendente';
        }

        if ($status === 'todos') {
            return 'todos';
        }

        return array_key_exists($status, self::RETORNO_STATUS) ? $status : 'pendente';
    }

    /**
     * @param array<string,mixed> $filtros
     * @return array{tipo:string,valor:int|string}|null
     */
    private function normalizarResultadoFiltro(array $filtros): ?array
    {
        $resultadoId = $this->normalizarId($filtros['resultado_id'] ?? null);
        if ($resultadoId !== null && $this->resultadoPadronizadoDisponivel()) {
            return [
                'tipo' => 'id',
                'valor' => $resultadoId,
            ];
        }

        $resultadoLegado = trim((string) ($filtros['resultado'] ?? ''));
        if ($resultadoLegado !== '' && array_key_exists($resultadoLegado, self::RESULTADOS)) {
            return [
                'tipo' => 'legacy',
                'valor' => $resultadoLegado,
            ];
        }

        return null;
    }

    private function normalizarId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit((string) $value)) {
            throw new InvalidArgumentException('ID inválido informado no atendimento.');
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function normalizarTexto(mixed $value, int $limite): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > $limite) {
            $texto = mb_substr($texto, 0, $limite);
        }

        return $texto;
    }

    private function normalizarDataHora(mixed $value): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($texto))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new InvalidArgumentException('Próximo acionamento inválido. Use data e hora válidas.');
        }
    }

    private function normalizarDataFiltro(mixed $value, bool $fimDoDia): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        try {
            $data = new DateTimeImmutable($texto);
        } catch (Throwable) {
            throw new InvalidArgumentException('Filtro de data inválido.');
        }

        return $fimDoDia ? $data->format('Y-m-d 23:59:59') : $data->format('Y-m-d 00:00:00');
    }

    private function contratoPertencePessoa(int $pessoaId, int $contratoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE id = :contrato_id AND pessoa_id = :pessoa_id LIMIT 1');
        $stmt->execute([
            'contrato_id' => $contratoId,
            'pessoa_id' => $pessoaId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    private function buscarContatoDaPessoa(int $pessoaId, int $contatoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, valor, payload_extra FROM pessoa_contatos WHERE id = :contato_id AND pessoa_id = :pessoa_id LIMIT 1');
        $stmt->execute([
            'contato_id' => $contatoId,
            'pessoa_id' => $pessoaId,
        ]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $contato */
    private function contatoNegativado(array $contato): bool
    {
        $payload = $contato['payload_extra'] ?? null;
        $decoded = is_string($payload) && trim($payload) !== '' ? json_decode($payload, true) : null;
        $situacao = is_array($decoded) ? (string) ($decoded['situacao_validacao'] ?? '') : '';
        return $situacao === 'negativado';
    }

    /**
     * Mesma checagem de contatoNegativado(), mas pra quando o operador
     * digitou o valor à mão (contato_id nulo) em vez de escolher da lista -
     * compara com valor_normalizado dos contatos já cadastrados da pessoa.
     */
    private function contatoUtilizadoNegativado(int $pessoaId, string $contatoUtilizado): bool
    {
        $normalizado = str_contains($contatoUtilizado, '@')
            ? mb_strtolower(trim($contatoUtilizado))
            : preg_replace('/\D+/', '', $contatoUtilizado);
        if ($normalizado === null || $normalizado === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT payload_extra FROM pessoa_contatos WHERE pessoa_id = :pessoa_id AND valor_normalizado = :valor_normalizado');
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('valor_normalizado', $normalizado);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $contato) {
            if ($this->contatoNegativado($contato)) {
                return true;
            }
        }
        return false;
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
