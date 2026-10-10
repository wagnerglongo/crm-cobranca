<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use RuntimeException;

final class RelatorioOperacionalRepository
{
    /** @var array<string,bool> */
    private array $tableCache = [];

    /** @var array<string,array<string,bool>> */
    private array $columnCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,array{label:string,descricao:string,periodo:string,endpoint:string}> */
    public static function tipos(): array
    {
        return [
            'acionamentos' => [
                'label' => 'Acionamentos',
                'descricao' => 'Eventos/tabulações registrados no histórico de atendimento.',
                'periodo' => 'Data/hora do acionamento',
                'endpoint' => 'acionamentos.php',
            ],
            'tentativas' => [
                'label' => 'Tentativas',
                'descricao' => 'Tentativas de contato com canal ou contato utilizado.',
                'periodo' => 'Data/hora da tentativa',
                'endpoint' => 'tentativas.php',
            ],
            'acordos' => [
                'label' => 'Acordos',
                'descricao' => 'Acordos/promessas com responsável, status, valores e acompanhamento.',
                'periodo' => 'Data do acordo/promessa',
                'endpoint' => 'acordos.php',
            ],
            'preventiva' => [
                'label' => 'Preventiva',
                'descricao' => 'Parcelas abertas/vencidas da carteira para trabalho preventivo.',
                'periodo' => 'Vencimento da parcela',
                'endpoint' => 'preventiva.php',
            ],
            'colchao' => [
                'label' => 'Colchão de acordos',
                'descricao' => 'Carteira pós-negociação com próxima parcela, carência e evolução do acordo.',
                'periodo' => 'Próximo vencimento',
                'endpoint' => 'colchao.php',
            ],
        ];
    }

    /** @return list<array{id:int,nome:string,codigo:string}> */
    public function listarCredores(?array $escopo = null): array
    {
        if (!$this->tableExists('credores')) {
            return [];
        }

        $codigoExpr = $this->columnExists('credores', 'codigo') ? 'COALESCE(cr.codigo, \'\')' : "''";
        $where = ['1=1'];
        $params = [];

        if ($escopo !== null && !($escopo['todos'] ?? false)) {
            $credorIds = $this->ids($escopo['credor_ids'] ?? []);
            $carteiraIds = $this->ids($escopo['carteira_ids'] ?? []);
            $parts = [];

            if ($credorIds !== []) {
                $parts[] = $this->inClause('cr.id', $credorIds, 'cred_list', $params);
            }
            if ($carteiraIds !== [] && $this->tableExists('carteiras')) {
                $parts[] = 'EXISTS (SELECT 1 FROM carteiras car_scope WHERE car_scope.credor_id = cr.id AND '
                    . $this->inClause('car_scope.id', $carteiraIds, 'car_list', $params) . ')';
            }

            $where[] = $parts === [] ? '1=0' : '(' . implode(' OR ', $parts) . ')';
        }

        $stmt = $this->pdo->prepare('SELECT cr.id, cr.nome, ' . $codigoExpr . ' AS codigo FROM credores cr WHERE '
            . implode(' AND ', $where) . ' ORDER BY cr.nome ASC, cr.id ASC');
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nome' => (string) ($row['nome'] ?? ''),
                'codigo' => (string) ($row['codigo'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $filtros
     * @param array{todos?:bool,credor_ids?:array,carteira_ids?:array}|null $escopo
     * @return array{stmt:PDOStatement,colunas:array<string,string>,arquivo:string,filtros:array<string,mixed>,limite:int}
     */
    public function preparar(string $tipo, array $filtros, ?array $escopo = null): array
    {
        $tipos = self::tipos();
        if (!isset($tipos[$tipo])) {
            throw new RuntimeException('Tipo de relatório inválido.');
        }

        $filtros = $this->normalizarFiltros($filtros);
        $limite = $this->maxRows();
        $this->habilitarStreamingMysql();

        return match ($tipo) {
            'acionamentos' => $this->prepararAcionamentos($filtros, $escopo, false, $limite),
            'tentativas' => $this->prepararAcionamentos($filtros, $escopo, true, $limite),
            'acordos' => $this->prepararAcordos($filtros, $escopo, $limite),
            'preventiva' => $this->prepararPreventiva($filtros, $escopo, $limite),
            'colchao' => $this->prepararColchao($filtros, $escopo, $limite),
            default => throw new RuntimeException('Tipo de relatório inválido.'),
        };
    }

    /** @param array<string,mixed> $filtros @return array<string,mixed> */
    private function normalizarFiltros(array $filtros): array
    {
        $hoje = new DateTimeImmutable('today');
        $inicioPadrao = $hoje->modify('first day of this month');

        $inicio = $this->parseDate((string) ($filtros['data_inicio'] ?? '')) ?? $inicioPadrao;
        $fim = $this->parseDate((string) ($filtros['data_fim'] ?? '')) ?? $hoje;

        if ($fim < $inicio) {
            throw new RuntimeException('A data final não pode ser menor que a data inicial.');
        }

        $dias = (int) $inicio->diff($fim)->format('%a') + 1;
        $maxDias = $this->maxDays();
        if ($dias > $maxDias) {
            throw new RuntimeException('Período muito grande. O limite atual é de ' . $maxDias . ' dias por exportação.');
        }

        $credorId = filter_var($filtros['credor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'data_inicio' => $inicio->format('Y-m-d'),
            'data_fim' => $fim->format('Y-m-d'),
            'data_fim_exclusivo' => $fim->modify('+1 day')->format('Y-m-d'),
            'credor_id' => $credorId === false ? null : $credorId,
        ];
    }

    /** @param array<string,mixed> $filtros */
    private function prepararAcionamentos(array $filtros, ?array $escopo, bool $somenteTentativas, int $limite): array
    {
        foreach (['atendimentos_historico', 'pessoas'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Relatório indisponível: tabela ' . $table . ' não encontrada.');
            }
        }

        $joins = [
            'INNER JOIN pessoas pe ON pe.id = ah.pessoa_id',
        ];
        $select = [
            'ah.id AS id',
            $this->expr('atendimentos_historico', 'ah', 'criado_em', 'data_hora'),
            'pe.nome AS cliente',
            'pe.documento AS documento',
        ];

        $credorExpr = 'NULL';
        $carteiraExpr = null;
        if ($this->tableExists('contratos')) {
            $joins[] = 'LEFT JOIN contratos c ON c.id = ah.contrato_id';
            $select[] = $this->expr('contratos', 'c', 'codigo_contrato', 'contrato');
            $select[] = $this->expr('contratos', 'c', 'produto', 'produto');
            $credorExpr = $this->columnExists('contratos', 'credor_id') ? 'c.credor_id' : 'NULL';
            $carteiraExpr = $this->columnExists('contratos', 'carteira_id') ? 'c.carteira_id' : null;

            if ($this->tableExists('credores') && $credorExpr !== 'NULL') {
                $joins[] = 'LEFT JOIN credores cr ON cr.id = ' . $credorExpr;
                $select[] = 'cr.nome AS credor';
            } else {
                $select[] = 'NULL AS credor';
            }
        } else {
            $select[] = 'NULL AS contrato';
            $select[] = 'NULL AS produto';
            $select[] = 'NULL AS credor';
        }

        $select[] = $this->expr('atendimentos_historico', 'ah', 'canal', 'canal');

        $resultadoPadronizado = $this->columnExists('atendimentos_historico', 'resultado_id')
            && $this->tableExists('atendimento_resultados')
            && $this->columnExists('atendimento_resultados', 'id');
        if ($resultadoPadronizado) {
            $joins[] = 'LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id';
            $resultadoLegado = $this->columnExists('atendimentos_historico', 'resultado') ? 'ah.resultado' : 'NULL';
            $nomeResultado = $this->columnExists('atendimento_resultados', 'nome') ? 'ar.nome' : 'NULL';
            $select[] = 'COALESCE(' . $nomeResultado . ', ' . $resultadoLegado . ') AS resultado';
            $select[] = $this->expr('atendimento_resultados', 'ar', 'categoria', 'categoria_resultado');
        } else {
            $select[] = $this->expr('atendimentos_historico', 'ah', 'resultado', 'resultado');
            $select[] = 'NULL AS categoria_resultado';
        }

        $contatoExprs = [];
        if ($this->columnExists('atendimentos_historico', 'contato_id') && $this->tableExists('pessoa_contatos')) {
            $joins[] = 'LEFT JOIN pessoa_contatos pc ON pc.id = ah.contato_id';
            if ($this->columnExists('pessoa_contatos', 'valor')) {
                $contatoExprs[] = 'pc.valor';
            }
        }
        if ($this->columnExists('atendimentos_historico', 'contato_utilizado')) {
            $contatoExprs[] = 'ah.contato_utilizado';
        }
        $select[] = ($contatoExprs === [] ? 'NULL' : 'COALESCE(' . implode(', ', $contatoExprs) . ')') . ' AS contato';
        $select[] = $this->expr('atendimentos_historico', 'ah', 'observacao', 'observacao');
        $select[] = $this->expr('atendimentos_historico', 'ah', 'proximo_acionamento', 'proximo_acionamento');
        $select[] = $this->expr('atendimentos_historico', 'ah', 'retorno_status', 'retorno_status');

        $userIdCol = $this->firstExistingColumn('atendimentos_historico', ['usuario_id', 'operador_id', 'user_id']);
        $userNameCol = $this->firstExistingColumn('usuarios', ['nome', 'nome_completo', 'login', 'usuario', 'email']);
        if ($userIdCol !== null && $userNameCol !== null && $this->tableExists('usuarios')) {
            $joins[] = 'LEFT JOIN usuarios us ON us.id = ah.`' . $userIdCol . '`';
            $select[] = 'us.`' . $userNameCol . '` AS operador';
        } else {
            $select[] = $this->expr('atendimentos_historico', 'ah', 'operador_nome', 'operador');
        }

        $where = ['ah.criado_em >= :data_inicio', 'ah.criado_em < :data_fim_exclusivo'];
        $params = [
            'data_inicio' => $filtros['data_inicio'],
            'data_fim_exclusivo' => $filtros['data_fim_exclusivo'],
        ];

        $this->applyCredorAndScope($where, $params, $credorExpr, $carteiraExpr, $filtros['credor_id'], $escopo, 'atd');

        if ($somenteTentativas) {
            $parts = [];
            if ($this->columnExists('atendimentos_historico', 'canal')) {
                $parts[] = "NULLIF(TRIM(COALESCE(ah.canal,'')), '') IS NOT NULL";
            }
            if ($this->columnExists('atendimentos_historico', 'contato_id')) {
                $parts[] = 'ah.contato_id IS NOT NULL';
            }
            if ($this->columnExists('atendimentos_historico', 'contato_utilizado')) {
                $parts[] = "NULLIF(TRIM(COALESCE(ah.contato_utilizado,'')), '') IS NOT NULL";
            }
            if ($parts !== []) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $sql = 'SELECT ' . implode(",\n", $select)
            . "\nFROM atendimentos_historico ah\n" . implode("\n", $joins)
            . "\nWHERE " . implode(' AND ', $where)
            . "\nORDER BY ah.criado_em ASC, ah.id ASC\nLIMIT " . $limite;

        $stmt = $this->pdo->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $colunas = [
            'id' => 'ID',
            'data_hora' => 'Data/Hora',
            'cliente' => 'Cliente',
            'documento' => 'CPF/CNPJ',
            'contrato' => 'Contrato',
            'produto' => 'Produto',
            'credor' => 'Credor',
            'canal' => 'Canal',
            'resultado' => 'Resultado',
            'categoria_resultado' => 'Categoria do resultado',
            'contato' => 'Contato utilizado',
            'observacao' => 'Observação',
            'proximo_acionamento' => 'Próximo acionamento',
            'retorno_status' => 'Status retorno',
            'operador' => 'Operador',
        ];

        $nome = $somenteTentativas ? 'tentativas' : 'acionamentos';
        return $this->pack($stmt, $colunas, $nome, $filtros, $limite);
    }

    /** @param array<string,mixed> $filtros */
    private function prepararAcordos(array $filtros, ?array $escopo, int $limite): array
    {
        foreach (['acordos_operacionais', 'pessoas'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Relatório indisponível: tabela ' . $table . ' não encontrada.');
            }
        }

        $joins = ['INNER JOIN pessoas pe ON pe.id = ao.pessoa_id'];
        $select = [
            'ao.id AS acordo_id',
            $this->expr('acordos_operacionais', 'ao', 'criado_em', 'criado_em'),
            $this->expr('acordos_operacionais', 'ao', 'data_promessa_pagamento', 'data_acordo', 'DATE(ao.criado_em)'),
            'pe.nome AS cliente',
            'pe.documento AS documento',
        ];

        $credorExpr = $this->columnExists('acordos_operacionais', 'credor_id') ? 'ao.credor_id' : 'NULL';
        $carteiraExpr = null;
        if ($this->tableExists('contratos')) {
            $joins[] = 'LEFT JOIN contratos c ON c.id = ao.contrato_id';
            $select[] = $this->expr('contratos', 'c', 'codigo_contrato', 'contrato');
            $select[] = $this->expr('contratos', 'c', 'produto', 'produto');
            if ($credorExpr === 'NULL' && $this->columnExists('contratos', 'credor_id')) {
                $credorExpr = 'c.credor_id';
            } elseif ($credorExpr !== 'NULL' && $this->columnExists('contratos', 'credor_id')) {
                $credorExpr = 'COALESCE(ao.credor_id, c.credor_id)';
            }
            $carteiraExpr = $this->columnExists('contratos', 'carteira_id') ? 'c.carteira_id' : null;
        } else {
            $select[] = 'NULL AS contrato';
            $select[] = 'NULL AS produto';
        }

        if ($this->tableExists('credores') && $credorExpr !== 'NULL') {
            $joins[] = 'LEFT JOIN credores cr ON cr.id = ' . $credorExpr;
            $select[] = 'cr.nome AS credor';
        } else {
            $select[] = 'NULL AS credor';
        }

        $select[] = $this->expr('acordos_operacionais', 'ao', 'valor_acordo', 'valor_acordo');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'valor_entrada', 'valor_entrada');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'quantidade_parcelas', 'quantidade_parcelas');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'status_operacional', 'status_acordo');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'status_integracao', 'status_integracao');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'origem', 'origem');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'operador_nome', 'fechado_por');
        $select[] = $this->payloadResponsavelExpr('ao') . ' AS responsavel';

        if ($this->tableExists('acordos_operacionais_acompanhamento')) {
            $joins[] = 'LEFT JOIN acordos_operacionais_acompanhamento ac ON ac.acordo_operacional_id = ao.id';
            $select[] = 'ac.situacao_carteira AS situacao_carteira';
            $select[] = 'ac.total_parcelas AS total_parcelas_acompanhadas';
            $select[] = 'ac.parcelas_pagas AS parcelas_pagas';
            $select[] = 'ac.proximo_vencimento AS proximo_vencimento';
            $select[] = 'ac.fim_carencia AS fim_carencia';
            $select[] = 'ac.valor_previsto AS valor_previsto';
            $select[] = 'ac.valor_pago AS valor_pago';
            $select[] = 'ac.proxima_acao AS proxima_acao';
        } else {
            $select[] = 'NULL AS situacao_carteira';
            $select[] = 'NULL AS total_parcelas_acompanhadas';
            $select[] = 'NULL AS parcelas_pagas';
            $select[] = 'NULL AS proximo_vencimento';
            $select[] = 'NULL AS fim_carencia';
            $select[] = 'NULL AS valor_previsto';
            $select[] = 'NULL AS valor_pago';
            $select[] = 'NULL AS proxima_acao';
        }

        $dateExpr = $this->columnExists('acordos_operacionais', 'data_promessa_pagamento')
            ? 'COALESCE(DATE(ao.data_promessa_pagamento), DATE(ao.criado_em))'
            : 'DATE(ao.criado_em)';
        $where = [$dateExpr . ' >= :data_inicio', $dateExpr . ' <= :data_fim'];
        $params = ['data_inicio' => $filtros['data_inicio'], 'data_fim' => $filtros['data_fim']];
        $this->applyCredorAndScope($where, $params, $credorExpr, $carteiraExpr, $filtros['credor_id'], $escopo, 'aco');

        $sql = 'SELECT ' . implode(",\n", $select)
            . "\nFROM acordos_operacionais ao\n" . implode("\n", $joins)
            . "\nWHERE " . implode(' AND ', $where)
            . "\nORDER BY " . $dateExpr . " ASC, ao.id ASC\nLIMIT " . $limite;
        $stmt = $this->pdo->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $colunas = [
            'acordo_id' => 'Acordo ID',
            'criado_em' => 'Criado em',
            'data_acordo' => 'Data acordo',
            'cliente' => 'Cliente',
            'documento' => 'CPF/CNPJ',
            'contrato' => 'Contrato',
            'credor' => 'Credor',
            'produto' => 'Produto',
            'valor_acordo' => 'Valor acordo',
            'valor_entrada' => 'Valor entrada',
            'quantidade_parcelas' => 'Qtd parcelas',
            'status_acordo' => 'Status acordo',
            'status_integracao' => 'Status integração',
            'origem' => 'Origem',
            'fechado_por' => 'Fechado por',
            'responsavel' => 'Responsável',
            'situacao_carteira' => 'Situação carteira',
            'total_parcelas_acompanhadas' => 'Parcelas acompanhadas',
            'parcelas_pagas' => 'Parcelas pagas',
            'proximo_vencimento' => 'Próximo vencimento',
            'fim_carencia' => 'Fim carência',
            'valor_previsto' => 'Valor previsto',
            'valor_pago' => 'Valor pago',
            'proxima_acao' => 'Próxima ação',
        ];

        return $this->pack($stmt, $colunas, 'acordos', $filtros, $limite);
    }

    /** @param array<string,mixed> $filtros */
    private function prepararPreventiva(array $filtros, ?array $escopo, int $limite): array
    {
        foreach (['acordos_operacionais', 'acordos_operacionais_parcelas', 'pessoas'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Relatório indisponível: tabela ' . $table . ' não encontrada.');
            }
        }

        $joins = [
            'INNER JOIN acordos_operacionais ao ON ao.id = ap.acordo_operacional_id',
            'INNER JOIN pessoas pe ON pe.id = ao.pessoa_id',
        ];
        $select = [
            'ap.id AS parcela_id',
            'ao.id AS acordo_id',
            'pe.nome AS cliente',
            'pe.documento AS documento',
        ];

        $credorExpr = $this->columnExists('acordos_operacionais', 'credor_id') ? 'ao.credor_id' : 'NULL';
        $carteiraExpr = null;
        if ($this->tableExists('contratos')) {
            $joins[] = 'LEFT JOIN contratos c ON c.id = ao.contrato_id';
            $select[] = $this->expr('contratos', 'c', 'codigo_contrato', 'contrato');
            $select[] = $this->expr('contratos', 'c', 'produto', 'produto');
            if ($this->columnExists('contratos', 'credor_id')) {
                $credorExpr = $credorExpr === 'NULL' ? 'c.credor_id' : 'COALESCE(ao.credor_id, c.credor_id)';
            }
            $carteiraExpr = $this->columnExists('contratos', 'carteira_id') ? 'c.carteira_id' : null;
        } else {
            $select[] = 'NULL AS contrato';
            $select[] = 'NULL AS produto';
        }

        if ($this->tableExists('credores') && $credorExpr !== 'NULL') {
            $joins[] = 'LEFT JOIN credores cr ON cr.id = ' . $credorExpr;
            $select[] = 'cr.nome AS credor';
        } else {
            $select[] = 'NULL AS credor';
        }

        $select[] = $this->expr('acordos_operacionais_parcelas', 'ap', 'numero_parcela', 'numero_parcela');
        $select[] = $this->expr('acordos_operacionais_parcelas', 'ap', 'tipo', 'tipo_parcela');
        $select[] = $this->expr('acordos_operacionais_parcelas', 'ap', 'valor_parcela', 'valor_parcela');
        $select[] = $this->expr('acordos_operacionais_parcelas', 'ap', 'data_vencimento', 'vencimento');
        $select[] = $this->expr('acordos_operacionais_parcelas', 'ap', 'status', 'status_parcela');
        $select[] = $this->expr('acordos_operacionais_parcelas', 'ap', 'data_pagamento', 'data_pagamento');
        $select[] = $this->expr('acordos_operacionais_parcelas', 'ap', 'valor_pago', 'valor_pago');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'status_operacional', 'status_acordo');
        $select[] = $this->payloadResponsavelExpr('ao') . ' AS responsavel';
        $select[] = 'DATEDIFF(ap.data_vencimento, CURRENT_DATE) AS dias_para_vencimento';
        $select[] = "CASE WHEN ap.status = 'paga' THEN 'Paga' WHEN ap.data_vencimento < CURRENT_DATE THEN 'Vencida' WHEN ap.data_vencimento = CURRENT_DATE THEN 'Vence hoje' ELSE 'A vencer' END AS situacao_preventiva";

        if ($this->tableExists('acordos_operacionais_acompanhamento')) {
            $joins[] = 'LEFT JOIN acordos_operacionais_acompanhamento ac ON ac.acordo_operacional_id = ao.id';
            $select[] = 'ac.fim_carencia AS fim_carencia';
            $select[] = 'ac.proxima_acao AS proxima_acao';
        } else {
            $select[] = 'NULL AS fim_carencia';
            $select[] = 'NULL AS proxima_acao';
        }

        $where = ['ap.data_vencimento >= :data_inicio', 'ap.data_vencimento <= :data_fim'];
        $params = ['data_inicio' => $filtros['data_inicio'], 'data_fim' => $filtros['data_fim']];
        if ($this->columnExists('acordos_operacionais_parcelas', 'status')) {
            $where[] = "COALESCE(ap.status,'pendente') NOT IN ('paga','cancelada')";
        }
        if ($this->columnExists('acordos_operacionais', 'status_operacional')) {
            $where[] = "COALESCE(ao.status_operacional,'pendente') IN ('pendente','formalizado')";
        }
        $this->applyCredorAndScope($where, $params, $credorExpr, $carteiraExpr, $filtros['credor_id'], $escopo, 'prev');

        $sql = 'SELECT ' . implode(",\n", $select)
            . "\nFROM acordos_operacionais_parcelas ap\n" . implode("\n", $joins)
            . "\nWHERE " . implode(' AND ', $where)
            . "\nORDER BY ap.data_vencimento ASC, ao.id ASC, ap.id ASC\nLIMIT " . $limite;
        $stmt = $this->pdo->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $colunas = [
            'parcela_id' => 'Parcela ID',
            'acordo_id' => 'Acordo ID',
            'cliente' => 'Cliente',
            'documento' => 'CPF/CNPJ',
            'contrato' => 'Contrato',
            'credor' => 'Credor',
            'produto' => 'Produto',
            'numero_parcela' => 'Parcela',
            'tipo_parcela' => 'Tipo',
            'valor_parcela' => 'Valor parcela',
            'vencimento' => 'Vencimento',
            'dias_para_vencimento' => 'Dias para vencimento',
            'situacao_preventiva' => 'Situação preventiva',
            'status_parcela' => 'Status parcela',
            'data_pagamento' => 'Data pagamento',
            'valor_pago' => 'Valor pago',
            'fim_carencia' => 'Fim carência',
            'status_acordo' => 'Status acordo',
            'responsavel' => 'Responsável',
            'proxima_acao' => 'Próxima ação',
        ];

        return $this->pack($stmt, $colunas, 'preventiva', $filtros, $limite);
    }

    /** @param array<string,mixed> $filtros */
    private function prepararColchao(array $filtros, ?array $escopo, int $limite): array
    {
        foreach (['acordos_operacionais', 'acordos_operacionais_acompanhamento', 'pessoas'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Relatório indisponível: tabela ' . $table . ' não encontrada. Execute a migration da carteira de acordos.');
            }
        }

        $joins = [
            'INNER JOIN acordos_operacionais ao ON ao.id = ac.acordo_operacional_id',
            'INNER JOIN pessoas pe ON pe.id = ao.pessoa_id',
        ];
        $select = [
            'ao.id AS acordo_id',
            'pe.nome AS cliente',
            'pe.documento AS documento',
        ];

        $credorExpr = $this->columnExists('acordos_operacionais', 'credor_id') ? 'ao.credor_id' : 'NULL';
        $carteiraExpr = null;
        if ($this->tableExists('contratos')) {
            $joins[] = 'LEFT JOIN contratos c ON c.id = ao.contrato_id';
            $select[] = $this->expr('contratos', 'c', 'codigo_contrato', 'contrato');
            $select[] = $this->expr('contratos', 'c', 'produto', 'produto');
            if ($this->columnExists('contratos', 'credor_id')) {
                $credorExpr = $credorExpr === 'NULL' ? 'c.credor_id' : 'COALESCE(ao.credor_id, c.credor_id)';
            }
            $carteiraExpr = $this->columnExists('contratos', 'carteira_id') ? 'c.carteira_id' : null;
        } else {
            $select[] = 'NULL AS contrato';
            $select[] = 'NULL AS produto';
        }

        if ($this->tableExists('credores') && $credorExpr !== 'NULL') {
            $joins[] = 'LEFT JOIN credores cr ON cr.id = ' . $credorExpr;
            $select[] = 'cr.nome AS credor';
        } else {
            $select[] = 'NULL AS credor';
        }

        $select[] = $this->expr('acordos_operacionais', 'ao', 'valor_acordo', 'valor_acordo');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'status_operacional', 'status_acordo');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'origem', 'origem');
        $select[] = $this->expr('acordos_operacionais', 'ao', 'operador_nome', 'fechado_por');
        $select[] = $this->payloadResponsavelExpr('ao') . ' AS responsavel';
        $select[] = 'ac.situacao_carteira AS situacao_carteira';
        $select[] = 'ac.total_parcelas AS total_parcelas';
        $select[] = 'ac.parcelas_pagas AS parcelas_pagas';
        $select[] = 'ac.valor_previsto AS valor_previsto';
        $select[] = 'ac.valor_pago AS valor_pago';
        $select[] = 'ac.proximo_vencimento AS proximo_vencimento';
        $select[] = 'ac.fim_carencia AS fim_carencia';
        $select[] = 'ac.proxima_acao AS proxima_acao';
        $select[] = 'ac.ultima_verificacao_em AS ultima_verificacao_em';
        $select[] = 'ac.proxima_verificacao_em AS proxima_verificacao_em';

        $dateExpr = 'COALESCE(ac.proximo_vencimento, DATE(ao.criado_em))';
        $where = [$dateExpr . ' >= :data_inicio', $dateExpr . ' <= :data_fim'];
        $params = ['data_inicio' => $filtros['data_inicio'], 'data_fim' => $filtros['data_fim']];
        if ($this->columnExists('acordos_operacionais', 'status_operacional')) {
            $where[] = "COALESCE(ao.status_operacional,'pendente') IN ('pendente','formalizado')";
        }
        $this->applyCredorAndScope($where, $params, $credorExpr, $carteiraExpr, $filtros['credor_id'], $escopo, 'col');

        $sql = 'SELECT ' . implode(",\n", $select)
            . "\nFROM acordos_operacionais_acompanhamento ac\n" . implode("\n", $joins)
            . "\nWHERE " . implode(' AND ', $where)
            . "\nORDER BY ac.proximo_vencimento ASC, ao.id ASC\nLIMIT " . $limite;
        $stmt = $this->pdo->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $colunas = [
            'acordo_id' => 'Acordo ID',
            'cliente' => 'Cliente',
            'documento' => 'CPF/CNPJ',
            'contrato' => 'Contrato',
            'credor' => 'Credor',
            'produto' => 'Produto',
            'valor_acordo' => 'Valor acordo',
            'status_acordo' => 'Status acordo',
            'situacao_carteira' => 'Situação carteira',
            'total_parcelas' => 'Total parcelas',
            'parcelas_pagas' => 'Parcelas pagas',
            'valor_previsto' => 'Valor previsto',
            'valor_pago' => 'Valor pago',
            'proximo_vencimento' => 'Próximo vencimento',
            'fim_carencia' => 'Fim carência',
            'proxima_acao' => 'Próxima ação',
            'ultima_verificacao_em' => 'Última verificação',
            'proxima_verificacao_em' => 'Próxima verificação',
            'origem' => 'Origem',
            'fechado_por' => 'Fechado por',
            'responsavel' => 'Responsável',
        ];

        return $this->pack($stmt, $colunas, 'colchao_acordos', $filtros, $limite);
    }

    /**
     * @param list<string> $where
     * @param array<string,mixed> $params
     */
    private function applyCredorAndScope(array &$where, array &$params, string $credorExpr, ?string $carteiraExpr, ?int $credorId, ?array $escopo, string $prefix): void
    {
        if ($credorId !== null) {
            if ($credorExpr === 'NULL') {
                $where[] = '1=0';
            } else {
                $where[] = $credorExpr . ' = :' . $prefix . '_credor';
                $params[$prefix . '_credor'] = $credorId;
            }
        }

        if ($escopo === null || ($escopo['todos'] ?? false)) {
            return;
        }

        $credorIds = $this->ids($escopo['credor_ids'] ?? []);
        $carteiraIds = $this->ids($escopo['carteira_ids'] ?? []);
        $parts = [];

        if ($credorIds !== [] && $credorExpr !== 'NULL') {
            $parts[] = $this->inClause($credorExpr, $credorIds, $prefix . '_scope_cred', $params);
        }
        if ($carteiraIds !== [] && $carteiraExpr !== null) {
            $parts[] = $this->inClause($carteiraExpr, $carteiraIds, $prefix . '_scope_car', $params);
        }

        $where[] = $parts === [] ? '1=0' : '(' . implode(' OR ', $parts) . ')';
    }

    /** @param list<int> $values @param array<string,mixed> $params */
    private function inClause(string $expr, array $values, string $prefix, array &$params): string
    {
        $holders = [];
        foreach (array_values($values) as $idx => $value) {
            $key = $prefix . '_' . $idx;
            $holders[] = ':' . $key;
            $params[$key] = $value;
        }
        return $expr . ' IN (' . implode(',', $holders) . ')';
    }

    /** @param mixed $values @return list<int> */
    private function ids(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    /** @param array<string,mixed> $params */
    private function bindAll(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string) $key, ':'), $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function expr(string $table, string $alias, string $column, string $as, string $fallback = 'NULL'): string
    {
        return ($this->columnExists($table, $column) ? $alias . '.`' . $column . '`' : $fallback) . ' AS `' . $as . '`';
    }

    private function payloadResponsavelExpr(string $alias): string
    {
        if (!$this->columnExists('acordos_operacionais', 'payload_extra')) {
            return 'NULL';
        }
        return "CASE WHEN JSON_VALID({$alias}.payload_extra) THEN COALESCE("
            . "JSON_UNQUOTE(JSON_EXTRACT({$alias}.payload_extra, '$.responsavel_nome')), "
            . "JSON_UNQUOTE(JSON_EXTRACT({$alias}.payload_extra, '$.responsavel.nome')), "
            . "JSON_UNQUOTE(JSON_EXTRACT({$alias}.payload_extra, '$.responsavel.usuario_nome'))"
            . ') ELSE NULL END';
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        if (!$this->tableExists($table)) {
            return null;
        }
        foreach ($columns as $column) {
            if ($this->columnExists($table, (string) $column)) {
                return (string) $column;
            }
        }
        return null;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
            $stmt->execute(['table' => $table]);
            return $this->tableCache[$table] = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return $this->tableCache[$table] = false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        if (!isset($this->columnCache[$table])) {
            $this->columnCache[$table] = [];
            try {
                $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $field = (string) ($row['Field'] ?? '');
                    if ($field !== '') {
                        $this->columnCache[$table][$field] = true;
                    }
                }
            } catch (\Throwable) {
                // mantém vazio
            }
        }
        return isset($this->columnCache[$table][$column]);
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new RuntimeException('Data inválida. Use o formato AAAA-MM-DD.');
        }
        return $date;
    }


    private function habilitarStreamingMysql(): void
    {
        try {
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
        } catch (\Throwable) {
            // O streaming continua funcional mesmo se o driver não permitir alterar
            // esse atributo; apenas poderá usar mais memória no cliente MySQL.
        }
    }

    private function maxDays(): int
    {
        $value = filter_var(getenv('RELATORIOS_CSV_MAX_DIAS') ?: 366, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3660]]);
        return $value === false ? 366 : (int) $value;
    }

    private function maxRows(): int
    {
        $value = filter_var(getenv('RELATORIOS_CSV_MAX_LINHAS') ?: 200000, FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => 1000000]]);
        return $value === false ? 200000 : (int) $value;
    }

    /** @param array<string,string> $colunas @param array<string,mixed> $filtros */
    private function pack(PDOStatement $stmt, array $colunas, string $nome, array $filtros, int $limite): array
    {
        return [
            'stmt' => $stmt,
            'colunas' => $colunas,
            'arquivo' => 'works_' . $nome . '_' . $filtros['data_inicio'] . '_a_' . $filtros['data_fim'] . '.csv',
            'filtros' => $filtros,
            'limite' => $limite,
        ];
    }
}
