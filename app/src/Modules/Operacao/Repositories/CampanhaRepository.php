<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class CampanhaRepository
{
    public const STATUS_CAMPANHA = [
        'rascunho' => 'Rascunho',
        'ativa' => 'Ativa',
        'pausada' => 'Pausada',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    public const STATUS_ITEM = [
        'pendente' => 'Pendente',
        'em_atendimento' => 'Em atendimento',
        'trabalhado' => 'Trabalhado',
        'pulado' => 'Pulado',
        'descartado' => 'Descartado',
    ];

    private ?bool $moduloDisponivel = null;
    private ?bool $retornoStatusDisponivel = null;
    private ?bool $resultadoPadronizadoDisponivel = null;
    private ?bool $atendimentoCampanhaItemDisponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $campanhasStmt = $this->pdo->query("SHOW TABLES LIKE 'campanhas'");
            $itensStmt = $this->pdo->query("SHOW TABLES LIKE 'campanha_itens'");

            $this->moduloDisponivel = $campanhasStmt !== false && $campanhasStmt->fetchColumn() !== false
                && $itensStmt !== false && $itensStmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    public function atendimentoCampanhaItemDisponivel(): bool
    {
        if ($this->atendimentoCampanhaItemDisponivel !== null) {
            return $this->atendimentoCampanhaItemDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'campanha_item_id'");
            $this->atendimentoCampanhaItemDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->atendimentoCampanhaItemDisponivel = false;
        }

        return $this->atendimentoCampanhaItemDisponivel;
    }

    public function retornoStatusDisponivel(): bool
    {
        if ($this->retornoStatusDisponivel !== null) {
            return $this->retornoStatusDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM atendimentos_historico LIKE 'retorno_status'");
            $this->retornoStatusDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->retornoStatusDisponivel = false;
        }

        return $this->retornoStatusDisponivel;
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
            $this->resultadoPadronizadoDisponivel = $tableExists && $columnExists;
        } catch (Throwable) {
            $this->resultadoPadronizadoDisponivel = false;
        }

        return $this->resultadoPadronizadoDisponivel;
    }

    /** @return list<array<string,mixed>> */
    public function listarCredores(?array $escopo = null): array
    {
        if ($escopo !== null && !(bool) ($escopo['todos'] ?? false)) {
            $ids = array_values(array_filter(array_map('intval', $escopo['credor_ids'] ?? [])));
            if ($ids === []) {
                return [];
            }
            $ph = [];
            foreach ($ids as $i => $id) {
                $ph[] = ':id' . $i;
            }
            $stmt = $this->pdo->prepare('SELECT id, codigo, nome FROM credores WHERE ativo = 1 AND id IN (' . implode(',', $ph) . ') ORDER BY nome ASC');
            foreach ($ids as $i => $id) {
                $stmt->bindValue('id' . $i, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->query('SELECT id, codigo, nome FROM credores WHERE ativo = 1 ORDER BY nome ASC');
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return list<array<string,mixed>> */
    public function listarCarteiras(?array $escopo = null): array
    {
        $where = ['car.ativo = 1', 'cr.ativo = 1'];
        $params = [];
        if ($escopo !== null && !(bool) ($escopo['todos'] ?? false)) {
            $credorIds = array_values(array_filter(array_map('intval', $escopo['credor_ids'] ?? [])));
            $carteiraIds = array_values(array_filter(array_map('intval', $escopo['carteira_ids'] ?? [])));
            $parts = [];
            foreach ($credorIds as $i => $id) {
                $key = 'credor_' . $i;
                $params[$key] = $id;
            }
            if ($credorIds !== []) {
                $parts[] = 'car.credor_id IN (' . implode(',', array_map(static fn($i) => ':credor_' . $i, array_keys($credorIds))) . ')';
            }
            foreach ($carteiraIds as $i => $id) {
                $key = 'carteira_' . $i;
                $params[$key] = $id;
            }
            if ($carteiraIds !== []) {
                $parts[] = 'car.id IN (' . implode(',', array_map(static fn($i) => ':carteira_' . $i, array_keys($carteiraIds))) . ')';
            }
            if ($parts === []) {
                return [];
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        $stmt = $this->pdo->prepare('SELECT car.id, car.codigo, car.nome, car.credor_id, cr.nome AS credor_nome
            FROM carteiras car
            INNER JOIN credores cr ON cr.id = car.credor_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY cr.nome ASC, car.nome ASC');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listarResultadosAtendimento(): array
    {
        if (!$this->resultadoPadronizadoDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT id, codigo, nome, categoria
            FROM atendimento_resultados
            WHERE ativo = 1
            ORDER BY ordem ASC, nome ASC');

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return array<string,int> */
    public function resumoGeral(): array
    {
        $fallback = [
            'campanhas_ativas' => 0,
            'itens_pendentes' => 0,
            'itens_em_atendimento' => 0,
            'itens_trabalhados' => 0,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        $stmt = $this->pdo->query("SELECT
                (SELECT COUNT(*) FROM campanhas WHERE status = 'ativa') AS campanhas_ativas,
                SUM(CASE WHEN ci.status = 'pendente' THEN 1 ELSE 0 END) AS itens_pendentes,
                SUM(CASE WHEN ci.status = 'em_atendimento' THEN 1 ELSE 0 END) AS itens_em_atendimento,
                SUM(CASE WHEN ci.status = 'trabalhado' THEN 1 ELSE 0 END) AS itens_trabalhados
            FROM campanha_itens ci
            INNER JOIN campanhas c ON c.id = ci.campanha_id
            WHERE c.status IN ('ativa','pausada','concluida')");
        $row = $stmt !== false ? ($stmt->fetch() ?: []) : [];

        return [
            'campanhas_ativas' => (int) ($row['campanhas_ativas'] ?? 0),
            'itens_pendentes' => (int) ($row['itens_pendentes'] ?? 0),
            'itens_em_atendimento' => (int) ($row['itens_em_atendimento'] ?? 0),
            'itens_trabalhados' => (int) ($row['itens_trabalhados'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listarCampanhas(int $limite = 50, ?array $escopo = null, bool $incluirCanceladas = false): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 150));
        $scope = EscopoCredorSql::contrato($escopo, 'co_scope', 'campanhas_scope');
        $where = [];
        if (!$incluirCanceladas) {
            $where[] = "c.status <> 'cancelada'";
        }
        if ($scope['sql'] !== '1=1') {
            $where[] = 'EXISTS (SELECT 1 FROM campanha_itens ci_scope INNER JOIN contratos co_scope ON co_scope.id = ci_scope.contrato_id WHERE ci_scope.campanha_id = c.id AND ' . $scope['sql'] . ')';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare("SELECT
                c.*,
                SUM(CASE WHEN ci.status = 'pendente' THEN 1 ELSE 0 END) AS itens_pendentes_calc,
                SUM(CASE WHEN ci.status = 'em_atendimento' THEN 1 ELSE 0 END) AS itens_em_atendimento_calc,
                SUM(CASE WHEN ci.status = 'trabalhado' THEN 1 ELSE 0 END) AS itens_trabalhados_calc,
                SUM(CASE WHEN ci.status = 'pulado' THEN 1 ELSE 0 END) AS itens_pulados_calc,
                SUM(CASE WHEN ci.status = 'descartado' THEN 1 ELSE 0 END) AS itens_descartados_calc,
                COUNT(ci.id) AS total_itens_calc
            FROM campanhas c
            LEFT JOIN campanha_itens ci ON ci.campanha_id = c.id
            " . $whereSql . "
            GROUP BY c.id
            ORDER BY
                CASE c.status
                    WHEN 'ativa' THEN 0
                    WHEN 'pausada' THEN 1
                    WHEN 'rascunho' THEN 2
                    WHEN 'concluida' THEN 3
                    WHEN 'cancelada' THEN 4
                    ELSE 5
                END ASC,
                c.prioridade DESC,
                c.atualizado_em DESC,
                c.id DESC
            LIMIT :limite");
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    /**
     * $escopo omitido (null) preserva o comportamento antigo (sem filtro) -
     * usado internamente por metodos que ja aplicam sua propria checagem.
     * Passar o escopo do usuario e obrigatorio em qualquer caminho
     * acionavel a partir de uma requisicao (ver atualizarStatusCampanha/
     * excluirCampanhaLogica/regerarFila): sem isso, um operador de um
     * credor podia pausar/excluir/regerar a fila de uma campanha de OUTRO
     * credor so adivinhando o id (a listagem ja filtrava certo por
     * escopo - listarCampanhas - mas essas 3 acoes por id nao).
     */
    public function buscarCampanha(int $campanhaId, ?array $escopo = null): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }

        $scope = EscopoCredorSql::contrato($escopo, 'co_scope', 'campanha_busca');
        $scopeWhere = $scope['sql'] === '1=1'
            ? '1=1'
            : "(NOT EXISTS (SELECT 1 FROM campanha_itens ci_any WHERE ci_any.campanha_id = c.id) OR EXISTS (
                SELECT 1 FROM campanha_itens ci_scope
                INNER JOIN contratos co_scope ON co_scope.id = ci_scope.contrato_id
                WHERE ci_scope.campanha_id = c.id AND {$scope['sql']}
            ))";

        $stmt = $this->pdo->prepare("SELECT c.* FROM campanhas c WHERE c.id = :id AND {$scopeWhere} LIMIT 1");
        $stmt->bindValue('id', $campanhaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listarItensDaPessoa(int $pessoaId, int $limite = 8, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel() || $pessoaId <= 0) {
            return [];
        }

        $limite = max(1, min($limite, 30));
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'itens_pessoa_scope');

        $sql = 'SELECT
                ci.*,
                c.nome AS campanha_nome,
                c.status AS campanha_status,
                c.tipo AS campanha_tipo,
                c.prioridade AS campanha_prioridade,
                co.codigo_contrato,
                co.produto,
                co.saldo_atual,
                co.dias_atraso,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome
            FROM campanha_itens ci
            INNER JOIN campanhas c ON c.id = ci.campanha_id
            LEFT JOIN contratos co ON co.id = ci.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE ci.pessoa_id = :pessoa_id
              AND c.status <> \'cancelada\'
              AND ' . $scope['sql'] . '
            ORDER BY
                CASE ci.status
                    WHEN \'pendente\' THEN 0
                    WHEN \'em_atendimento\' THEN 1
                    WHEN \'pulado\' THEN 2
                    WHEN \'trabalhado\' THEN 3
                    WHEN \'descartado\' THEN 4
                    ELSE 5
                END ASC,
                CASE c.status
                    WHEN \'ativa\' THEN 0
                    WHEN \'pausada\' THEN 1
                    WHEN \'rascunho\' THEN 2
                    WHEN \'concluida\' THEN 3
                    ELSE 4
                END ASC,
                c.prioridade DESC,
                ci.gerado_em DESC,
                ci.id DESC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarItem(int $itemId, ?array $escopo = null): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT
                ci.*,
                c.nome AS campanha_nome,
                c.status AS campanha_status,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                co.codigo_contrato,
                co.produto,
                co.saldo_atual,
                co.dias_atraso,
                cr.nome AS credor_nome
            FROM campanha_itens ci
            INNER JOIN campanhas c ON c.id = ci.campanha_id
            INNER JOIN pessoas p ON p.id = ci.pessoa_id
            LEFT JOIN contratos co ON co.id = ci.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            WHERE ci.id = :id
              AND ' . EscopoCredorSql::contrato($escopo, 'co', 'item_scope')['sql'] . '
            LIMIT 1');
        $scopeItem = EscopoCredorSql::contrato($escopo, 'co', 'item_scope');
        EscopoCredorSql::bind($stmt, $scopeItem['params']);
        $stmt->bindValue('id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function buscarItemDaPessoa(int $itemId, int $pessoaId, ?array $escopo = null): ?array
    {
        if ($itemId <= 0 || $pessoaId <= 0) {
            return null;
        }

        $item = $this->buscarItem($itemId);
        if ($item === null || (int) $item['pessoa_id'] !== $pessoaId) {
            return null;
        }

        return $item;
    }

    /** @param array<string,mixed> $dados */
    public function criarCampanhaEGerar(array $dados, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de campanhas indisponível. Execute a migration 012 antes de criar filas.');
        }

        $nome = $this->normalizarTexto($dados['nome'] ?? null, 160, true);
        $descricao = $this->normalizarTexto($dados['descricao'] ?? null, 500, false);
        $prioridade = $this->normalizarInteiro($dados['prioridade'] ?? 10, 0, 999, 10);
        $filtros = $this->normalizarFiltros($dados);
        $this->validarFiltrosDentroDoEscopo($filtros, $escopo);
        $limite = $this->normalizarInteiro($dados['limite'] ?? 200, 1, 5000, 200);
        $filtros['limite'] = $limite;

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('INSERT INTO campanhas (
                    nome,
                    descricao,
                    tipo,
                    status,
                    prioridade,
                    filtros_json,
                    criado_por,
                    payload_extra
                ) VALUES (
                    :nome,
                    :descricao,
                    \'manual\',
                    \'ativa\',
                    :prioridade,
                    :filtros_json,
                    :criado_por,
                    :payload_extra
                )');
            $stmt->bindValue('nome', $nome);
            $this->bindNullableString($stmt, 'descricao', $descricao);
            $stmt->bindValue('prioridade', $prioridade, PDO::PARAM_INT);
            $stmt->bindValue('filtros_json', json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->bindNullableString($stmt, 'criado_por', $this->normalizarTexto($dados['criado_por'] ?? null, 120, false));
            $stmt->bindValue('payload_extra', json_encode(['origem_tela' => 'operacao/campanhas.php'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();

            $campanhaId = (int) $this->pdo->lastInsertId();
            $gerados = $this->gerarItensInterno($campanhaId, $filtros, $limite, $escopo);
            $distribuidos = $this->distribuirCampanhaSeSolicitado($campanhaId, $filtros, (int) ($dados['criado_por_usuario_id'] ?? 0));
            $this->registrarEvento($campanhaId, null, 'criada', 'Campanha criada e fila inicial gerada.', [
                'filtros' => $filtros,
                'itens_gerados' => $gerados,
                'itens_distribuidos' => $distribuidos ?? 0,
            ]);
            $this->recalcularContadores($campanhaId);

            $this->pdo->commit();

            return [
                'campanha_id' => $campanhaId,
                'itens_gerados' => $gerados,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function regerarFila(int $campanhaId, int $limite = 1000, ?array $escopo = null): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de campanhas indisponível.');
        }

        $campanha = $this->buscarCampanha($campanhaId, $escopo);
        if ($campanha === null) {
            throw new InvalidArgumentException('Campanha não encontrada no seu escopo.');
        }

        $filtros = $this->decodeJsonArray($campanha['filtros_json'] ?? null);
        $limite = $this->normalizarInteiro($limite, 1, 5000, (int) ($filtros['limite'] ?? 1000));

        $this->pdo->beginTransaction();
        try {
            $gerados = $this->gerarItensInterno($campanhaId, $filtros, $limite, $escopo);
            $distribuidos = $this->distribuirCampanhaSeSolicitado($campanhaId, $filtros, 0);
            $this->registrarEvento($campanhaId, null, 'gerada', 'Fila regerada com os filtros salvos.', [
                'limite' => $limite,
                'itens_gerados' => $gerados,
                'itens_distribuidos' => $distribuidos ?? 0,
            ]);
            $this->recalcularContadores($campanhaId);
            $this->pdo->commit();
            return $gerados;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listarItens(int $campanhaId = 0, string $status = 'pendente', string $termo = '', int $limite = 80, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 200));
        $where = [];
        $params = [];

        if ($campanhaId > 0) {
            $where[] = 'ci.campanha_id = :campanha_id';
            $params['campanha_id'] = $campanhaId;
        }

        $status = $this->normalizarStatusItemFiltro($status);
        if ($status !== 'todos') {
            $where[] = 'ci.status = :status';
            $params['status'] = $status;
        }

        $termo = trim($termo);
        if ($termo !== '' && mb_strlen($termo) >= 2) {
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';
            $buscas = ['p.nome LIKE :q_texto_nome', 'co.codigo_contrato LIKE :q_texto_contrato'];
            $params['q_texto_nome'] = '%' . $termo . '%';
            $params['q_texto_contrato'] = '%' . $termo . '%';

            if ($digitos !== '' && strlen($digitos) >= 3) {
                $buscas[] = 'p.documento LIKE :q_digitos';
                $params['q_digitos'] = '%' . $digitos . '%';
            }

            $where[] = '(' . implode(' OR ', $buscas) . ')';
        }

        $scopeItens = EscopoCredorSql::contrato($escopo, 'co', 'itens_scope');
        $where[] = $scopeItens['sql'];
        $params = array_merge($params, $scopeItens['params']);

        $sql = 'SELECT
                ci.*,
                c.nome AS campanha_nome,
                c.status AS campanha_status,
                c.prioridade AS campanha_prioridade,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                co.codigo_contrato,
                co.produto,
                co.saldo_atual,
                co.dias_atraso,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome,
                (SELECT COUNT(*) FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id) AS total_contatos,
                (SELECT MAX(ah.criado_em) FROM atendimentos_historico ah WHERE ah.pessoa_id = p.id) AS ultimo_atendimento
            FROM campanha_itens ci
            INNER JOIN campanhas c ON c.id = ci.campanha_id
            INNER JOIN pessoas p ON p.id = ci.pessoa_id
            LEFT JOIN contratos co ON co.id = ci.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY
                CASE ci.status
                    WHEN \'pendente\' THEN 0
                    WHEN \'em_atendimento\' THEN 1
                    WHEN \'pulado\' THEN 2
                    WHEN \'trabalhado\' THEN 3
                    ELSE 4
                END ASC,
                ci.prioridade DESC,
                ci.gerado_em ASC,
                ci.id ASC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, in_array($key, ['campanha_id'], true) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function atualizarStatusCampanha(int $campanhaId, string $status, ?array $escopo = null): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de campanhas indisponível.');
        }

        if (!array_key_exists($status, self::STATUS_CAMPANHA)) {
            throw new InvalidArgumentException('Status de campanha inválido.');
        }

        if ($this->buscarCampanha($campanhaId, $escopo) === null) {
            throw new InvalidArgumentException('Campanha não encontrada no seu escopo.');
        }

        $stmt = $this->pdo->prepare('UPDATE campanhas SET status = :status WHERE id = :id LIMIT 1');
        $stmt->bindValue('status', $status);
        $stmt->bindValue('id', $campanhaId, PDO::PARAM_INT);
        $stmt->execute();

        $this->registrarEvento($campanhaId, null, 'status_alterado', 'Status da campanha alterado para ' . self::STATUS_CAMPANHA[$status] . '.', [
            'status' => $status,
        ]);
        $this->recalcularContadores($campanhaId);
    }

    public function excluirCampanhaLogica(int $campanhaId, int $usuarioId, string $usuarioNome, ?string $motivo = null, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de campanhas indisponível.');
        }

        $campanha = $this->buscarCampanha($campanhaId, $escopo);
        if ($campanha === null) {
            throw new InvalidArgumentException('Campanha não encontrada no seu escopo.');
        }

        if ((string) ($campanha['status'] ?? '') === 'cancelada') {
            return ['itens_descartados' => 0, 'reservas_liberadas' => 0];
        }

        $motivo = $this->normalizarTexto($motivo, 500, false) ?? 'Campanha apagada/arquivada pela operação.';
        $this->pdo->beginTransaction();

        try {
            $stmtCampanha = $this->pdo->prepare("UPDATE campanhas SET status = 'cancelada', atualizado_em = NOW(), payload_extra = JSON_MERGE_PATCH(COALESCE(payload_extra, JSON_OBJECT()), JSON_OBJECT('apagada_em', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), 'apagada_por_usuario_id', :usuario_id, 'apagada_por', :usuario_nome, 'motivo_apagar', :motivo)) WHERE id = :id LIMIT 1");
            if ($usuarioId > 0) {
                $stmtCampanha->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
            } else {
                $stmtCampanha->bindValue('usuario_id', null, PDO::PARAM_NULL);
            }
            $stmtCampanha->bindValue('usuario_nome', mb_substr($usuarioNome, 0, 120));
            $stmtCampanha->bindValue('motivo', $motivo);
            $stmtCampanha->bindValue('id', $campanhaId, PDO::PARAM_INT);
            $stmtCampanha->execute();

            $stmtItens = $this->pdo->prepare("UPDATE campanha_itens SET status = 'descartado', observacao = CONCAT(COALESCE(observacao, ''), CASE WHEN observacao IS NULL OR observacao = '' THEN '' ELSE '\n' END, :obs), atualizado_em = NOW() WHERE campanha_id = :campanha_id AND status IN ('pendente','em_atendimento','pulado')");
            $stmtItens->bindValue('obs', 'Campanha apagada/arquivada: ' . $motivo);
            $stmtItens->bindValue('campanha_id', $campanhaId, PDO::PARAM_INT);
            $stmtItens->execute();
            $itensDescartados = $stmtItens->rowCount();

            $reservasLiberadas = 0;
            if ($this->tabelaExiste('operacao_cliente_reservas')) {
                $stmtReservas = $this->pdo->prepare("UPDATE operacao_cliente_reservas r INNER JOIN campanha_itens ci ON ci.id = r.campanha_item_id SET r.status = 'liberada', r.liberado_em = NOW(), r.payload_extra = JSON_MERGE_PATCH(COALESCE(r.payload_extra, JSON_OBJECT()), JSON_OBJECT('liberada_por_usuario_id', :usuario_id, 'liberada_por', :usuario_nome)), r.observacao = CONCAT(COALESCE(r.observacao, ''), CASE WHEN r.observacao IS NULL OR r.observacao = '' THEN '' ELSE '\n' END, :obs) WHERE ci.campanha_id = :campanha_id AND r.status = 'ativa'");
                if ($usuarioId > 0) {
                    $stmtReservas->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
                } else {
                    $stmtReservas->bindValue('usuario_id', null, PDO::PARAM_NULL);
                }
                $stmtReservas->bindValue('usuario_nome', mb_substr($usuarioNome, 0, 120));
                $stmtReservas->bindValue('obs', 'Reserva liberada porque a campanha foi apagada/arquivada.');
                $stmtReservas->bindValue('campanha_id', $campanhaId, PDO::PARAM_INT);
                $stmtReservas->execute();
                $reservasLiberadas = $stmtReservas->rowCount();
            }

            $this->registrarEvento($campanhaId, null, 'apagada', 'Campanha apagada/arquivada logicamente.', [
                'motivo' => $motivo,
                'usuario_id' => $usuarioId,
                'usuario_nome' => $usuarioNome,
                'itens_descartados' => $itensDescartados,
                'reservas_liberadas' => $reservasLiberadas,
            ]);
            $this->recalcularContadores($campanhaId);
            $this->pdo->commit();

            return ['itens_descartados' => $itensDescartados, 'reservas_liberadas' => $reservasLiberadas];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function atualizarStatusItem(int $itemId, string $status, ?string $observacao = null): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de campanhas indisponível.');
        }

        if (!array_key_exists($status, self::STATUS_ITEM)) {
            throw new InvalidArgumentException('Status de item inválido.');
        }

        $item = $this->buscarItem($itemId);
        if ($item === null) {
            throw new InvalidArgumentException('Item de fila não encontrado.');
        }

        $observacao = $this->normalizarTexto($observacao, 1000, false);
        $reservadoSql = $status === 'em_atendimento' ? ', reservado_em = COALESCE(reservado_em, NOW())' : '';
        $trabalhadoSql = in_array($status, ['trabalhado','descartado'], true) ? ', trabalhado_em = COALESCE(trabalhado_em, NOW())' : '';

        $stmt = $this->pdo->prepare('UPDATE campanha_itens
            SET status = :status,
                observacao = COALESCE(:observacao, observacao)
                ' . $reservadoSql . '
                ' . $trabalhadoSql . '
            WHERE id = :id
            LIMIT 1');
        $stmt->bindValue('status', $status);
        $this->bindNullableString($stmt, 'observacao', $observacao);
        $stmt->bindValue('id', $itemId, PDO::PARAM_INT);
        $stmt->execute();

        $this->registrarEvento((int) $item['campanha_id'], $itemId, 'item_' . $status, 'Item marcado como ' . self::STATUS_ITEM[$status] . '.', [
            'status' => $status,
            'observacao' => $observacao,
        ]);
        $this->recalcularContadores((int) $item['campanha_id']);
    }

    public function marcarItemTrabalhado(int $itemId, int $atendimentoId, int $pessoaId): void
    {
        if (!$this->moduloDisponivel()) {
            return;
        }

        $item = $this->buscarItemDaPessoa($itemId, $pessoaId);
        if ($item === null) {
            throw new InvalidArgumentException('Item de campanha inválido para este cliente.');
        }

        $stmt = $this->pdo->prepare("UPDATE campanha_itens
            SET status = 'trabalhado',
                atendimento_id = :atendimento_id,
                trabalhado_em = NOW(),
                observacao = COALESCE(observacao, 'Trabalhado pela ficha do cliente.')
            WHERE id = :id
            LIMIT 1");
        $stmt->bindValue('atendimento_id', $atendimentoId, PDO::PARAM_INT);
        $stmt->bindValue('id', $itemId, PDO::PARAM_INT);
        $stmt->execute();

        $this->registrarEvento((int) $item['campanha_id'], $itemId, 'item_trabalhado', 'Item trabalhado por atendimento registrado.', [
            'atendimento_id' => $atendimentoId,
            'pessoa_id' => $pessoaId,
        ]);
        $this->recalcularContadores((int) $item['campanha_id']);
    }


    /**
     * @param array<string,mixed> $dados
     * @return array{total:int,amostra:list<array<string,mixed>>,filtros:array<string,mixed>}
     */
    public function previsualizarCampanha(array $dados, ?array $escopo = null, int $limiteAmostra = 20): array
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de campanhas indisponível.');
        }

        $filtros = $this->normalizarFiltros($dados);
        $this->validarFiltrosDentroDoEscopo($filtros, $escopo);
        $where = [];
        $params = [];

        $credorId = $this->normalizarIdOpcional($filtros['credor_id'] ?? null);
        if ($credorId !== null) { $where[] = 'co.credor_id = :credor_id'; $params['credor_id'] = $credorId; }
        $carteiraId = $this->normalizarIdOpcional($filtros['carteira_id'] ?? null);
        if ($carteiraId !== null) { $where[] = 'co.carteira_id = :carteira_id'; $params['carteira_id'] = $carteiraId; }
        $produto = trim((string) ($filtros['produto'] ?? ''));
        if ($produto !== '') { $where[] = 'co.produto LIKE :produto'; $params['produto'] = '%' . $produto . '%'; }
        $saldoMin = $this->normalizarDecimalOpcional($filtros['saldo_min'] ?? null);
        if ($saldoMin !== null) { $where[] = 'co.saldo_atual >= :saldo_min'; $params['saldo_min'] = $saldoMin; }
        $saldoMax = $this->normalizarDecimalOpcional($filtros['saldo_max'] ?? null);
        if ($saldoMax !== null) { $where[] = 'co.saldo_atual <= :saldo_max'; $params['saldo_max'] = $saldoMax; }
        $diasAtrasoMin = $this->normalizarInteiroOpcional($filtros['dias_atraso_min'] ?? null, 0, 99999);
        if ($diasAtrasoMin !== null) { $where[] = 'co.dias_atraso >= :dias_atraso_min'; $params['dias_atraso_min'] = $diasAtrasoMin; }
        $diasAtrasoMax = $this->normalizarInteiroOpcional($filtros['dias_atraso_max'] ?? null, 0, 99999);
        if ($diasAtrasoMax !== null) { $where[] = 'co.dias_atraso <= :dias_atraso_max'; $params['dias_atraso_max'] = $diasAtrasoMax; }

        if (!empty($filtros['exigir_contato_valido'])) {
            $where[] = "EXISTS (SELECT 1 FROM pessoa_contatos pc_ok WHERE pc_ok.pessoa_id = p.id AND pc_ok.tipo IN ('telefone','whatsapp') AND pc_ok.status NOT IN ('incorreto','bloqueado') AND CHAR_LENGTH(pc_ok.valor_normalizado) >= 8)";
        }
        if (!empty($filtros['excluir_retorno_pendente'])) {
            $retornoWhere = $this->retornoStatusDisponivel() ? "ret.proximo_acionamento IS NOT NULL AND COALESCE(ret.retorno_status, 'pendente') = 'pendente'" : 'ret.proximo_acionamento IS NOT NULL';
            $where[] = 'NOT EXISTS (SELECT 1 FROM atendimentos_historico ret WHERE ret.pessoa_id = p.id AND ' . $retornoWhere . ')';
        }
        if (!empty($filtros['excluir_acordo_ativo'])) {
            $where[] = "NOT EXISTS (SELECT 1 FROM acordos_operacionais ao_ativo WHERE ao_ativo.contrato_id = co.id AND ao_ativo.status_operacional IN ('pendente','formalizado'))";
        }
        if (!empty($filtros['excluir_reserva_ativa']) && $this->tabelaExiste('operacao_cliente_reservas')) {
            $where[] = "NOT EXISTS (SELECT 1 FROM operacao_cliente_reservas res_ativa WHERE res_ativa.pessoa_id = p.id AND res_ativa.status = 'ativa' AND (res_ativa.expira_em IS NULL OR res_ativa.expira_em >= NOW()))";
        }
        $semContatoDias = $this->normalizarInteiroOpcional($filtros['sem_contato_efetivo_dias'] ?? null, 1, 9999);
        if ($semContatoDias !== null) {
            $params['sem_contato_dias'] = $semContatoDias;
            $where[] = "NOT EXISTS (SELECT 1 FROM atendimentos_historico ah_eff WHERE ah_eff.pessoa_id = p.id AND ah_eff.criado_em >= DATE_SUB(NOW(), INTERVAL :sem_contato_dias DAY) AND ah_eff.resultado IN ('atendeu','promessa_pagamento','em_negociacao'))";
        }
        $ultimoResultadoId = $this->normalizarIdOpcional($filtros['ultimo_resultado_id'] ?? null);
        if ($ultimoResultadoId !== null && $this->resultadoPadronizadoDisponivel()) {
            $where[] = '(SELECT ah_last.resultado_id FROM atendimentos_historico ah_last WHERE ah_last.pessoa_id = p.id ORDER BY ah_last.criado_em DESC, ah_last.id DESC LIMIT 1) = :ultimo_resultado_id';
            $params['ultimo_resultado_id'] = $ultimoResultadoId;
        }

        $scope = EscopoCredorSql::contrato($escopo, 'co', 'preview_scope');
        $where[] = $scope['sql'];
        $params = array_merge($params, $scope['params']);
        $from = ' FROM contratos co INNER JOIN pessoas p ON p.id = co.pessoa_id INNER JOIN credores cr ON cr.id = co.credor_id LEFT JOIN carteiras car ON car.id = co.carteira_id WHERE ' . implode(' AND ', $where);

        $stmtTotal = $this->pdo->prepare('SELECT COUNT(*) ' . $from);
        foreach ($params as $key => $value) { $stmtTotal->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR); }
        $stmtTotal->execute();
        $total = (int) $stmtTotal->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT p.id AS pessoa_id, p.nome AS pessoa_nome, p.documento, co.id AS contrato_id, co.codigo_contrato, co.produto, co.saldo_atual, co.dias_atraso, cr.nome AS credor_nome, car.nome AS carteira_nome, (SELECT COUNT(*) FROM pessoa_contatos pc WHERE pc.pessoa_id = p.id AND pc.tipo IN ('telefone','whatsapp')) AS telefones, (SELECT MAX(ah.criado_em) FROM atendimentos_historico ah WHERE ah.pessoa_id = p.id) AS ultimo_atendimento " . $from . " ORDER BY COALESCE(co.saldo_atual,0) DESC, COALESCE(co.dias_atraso,0) DESC, co.id DESC LIMIT :limite");
        foreach ($params as $key => $value) { $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR); }
        $stmt->bindValue('limite', max(1, min(100, $limiteAmostra)), PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => $total, 'amostra' => $stmt->fetchAll(), 'filtros' => $filtros];
    }

    /** @param array<string,mixed> $filtros */
    private function gerarItensInterno(int $campanhaId, array $filtros, int $limite, ?array $escopo = null): int
    {
        $where = [];
        $params = ['campanha_id' => $campanhaId];

        $credorId = $this->normalizarIdOpcional($filtros['credor_id'] ?? null);
        if ($credorId !== null) {
            $where[] = 'co.credor_id = :credor_id';
            $params['credor_id'] = $credorId;
        }

        $carteiraId = $this->normalizarIdOpcional($filtros['carteira_id'] ?? null);
        if ($carteiraId !== null) {
            $where[] = 'co.carteira_id = :carteira_id';
            $params['carteira_id'] = $carteiraId;
        }

        $produto = trim((string) ($filtros['produto'] ?? ''));
        if ($produto !== '') {
            $where[] = 'co.produto LIKE :produto';
            $params['produto'] = '%' . $produto . '%';
        }

        $saldoMin = $this->normalizarDecimalOpcional($filtros['saldo_min'] ?? null);
        if ($saldoMin !== null) {
            $where[] = 'co.saldo_atual >= :saldo_min';
            $params['saldo_min'] = $saldoMin;
        }

        $saldoMax = $this->normalizarDecimalOpcional($filtros['saldo_max'] ?? null);
        if ($saldoMax !== null) {
            $where[] = 'co.saldo_atual <= :saldo_max';
            $params['saldo_max'] = $saldoMax;
        }

        $diasAtrasoMin = $this->normalizarInteiroOpcional($filtros['dias_atraso_min'] ?? null, 0, 99999);
        if ($diasAtrasoMin !== null) {
            $where[] = 'co.dias_atraso >= :dias_atraso_min';
            $params['dias_atraso_min'] = $diasAtrasoMin;
        }

        $diasAtrasoMax = $this->normalizarInteiroOpcional($filtros['dias_atraso_max'] ?? null, 0, 99999);
        if ($diasAtrasoMax !== null) {
            $where[] = 'co.dias_atraso <= :dias_atraso_max';
            $params['dias_atraso_max'] = $diasAtrasoMax;
        }

        if (!empty($filtros['exigir_contato_valido'])) {
            $where[] = "EXISTS (
                SELECT 1
                FROM pessoa_contatos pc_ok
                WHERE pc_ok.pessoa_id = p.id
                  AND pc_ok.tipo IN ('telefone','whatsapp')
                  AND pc_ok.status NOT IN ('incorreto','bloqueado')
                  AND CHAR_LENGTH(pc_ok.valor_normalizado) >= 8
            )";
        }

        if (!empty($filtros['excluir_retorno_pendente'])) {
            $retornoWhere = $this->retornoStatusDisponivel()
                ? "ret.proximo_acionamento IS NOT NULL AND COALESCE(ret.retorno_status, 'pendente') = 'pendente'"
                : 'ret.proximo_acionamento IS NOT NULL';
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM atendimentos_historico ret
                WHERE ret.pessoa_id = p.id
                  AND ' . $retornoWhere . '
            )';
        }

        if (!empty($filtros['excluir_acordo_ativo'])) {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM acordos_operacionais ao_ativo
                WHERE ao_ativo.contrato_id = co.id
                  AND ao_ativo.status_operacional IN ('pendente','formalizado')
            )";
        }

        if (!empty($filtros['excluir_reserva_ativa']) && $this->tabelaExiste('operacao_cliente_reservas')) {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM operacao_cliente_reservas res_ativa
                WHERE res_ativa.pessoa_id = p.id
                  AND res_ativa.status = 'ativa'
                  AND (res_ativa.expira_em IS NULL OR res_ativa.expira_em >= NOW())
            )";
        }

        $semContatoDias = $this->normalizarInteiroOpcional($filtros['sem_contato_efetivo_dias'] ?? null, 1, 9999);
        if ($semContatoDias !== null) {
            $params['sem_contato_dias'] = $semContatoDias;
            if ($this->resultadoPadronizadoDisponivel()) {
                $where[] = "NOT EXISTS (
                    SELECT 1
                    FROM atendimentos_historico ah_eff
                    LEFT JOIN atendimento_resultados ar_eff ON ar_eff.id = ah_eff.resultado_id
                    WHERE ah_eff.pessoa_id = p.id
                      AND ah_eff.criado_em >= DATE_SUB(NOW(), INTERVAL :sem_contato_dias DAY)
                      AND (ar_eff.considera_contato_efetivo = 1 OR ah_eff.resultado IN ('atendeu','promessa_pagamento','em_negociacao'))
                )";
            } else {
                $where[] = "NOT EXISTS (
                    SELECT 1
                    FROM atendimentos_historico ah_eff
                    WHERE ah_eff.pessoa_id = p.id
                      AND ah_eff.criado_em >= DATE_SUB(NOW(), INTERVAL :sem_contato_dias DAY)
                      AND ah_eff.resultado IN ('atendeu','promessa_pagamento','em_negociacao')
                )";
            }
        }

        $ultimoResultadoId = $this->normalizarIdOpcional($filtros['ultimo_resultado_id'] ?? null);
        if ($ultimoResultadoId !== null && $this->resultadoPadronizadoDisponivel()) {
            $where[] = '(SELECT ah_last.resultado_id
                FROM atendimentos_historico ah_last
                WHERE ah_last.pessoa_id = p.id
                ORDER BY ah_last.criado_em DESC, ah_last.id DESC
                LIMIT 1) = :ultimo_resultado_id';
            $params['ultimo_resultado_id'] = $ultimoResultadoId;
        }

        $scopeGeracao = EscopoCredorSql::contrato($escopo, 'co', 'geracao_scope');
        $where[] = $scopeGeracao['sql'];
        $params = array_merge($params, $scopeGeracao['params']);

        $where[] = "NOT EXISTS (
            SELECT 1
            FROM campanha_itens ci_dup
            WHERE ci_dup.campanha_id = :campanha_id
              AND ci_dup.chave_item = CONCAT('p', p.id, '_c', co.id)
        )";

        $sql = 'SELECT
                p.id AS pessoa_id,
                co.id AS contrato_id,
                co.saldo_atual,
                co.dias_atraso,
                co.produto,
                co.codigo_contrato,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome
            FROM contratos co
            INNER JOIN pessoas p ON p.id = co.pessoa_id
            INNER JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
                COALESCE(co.saldo_atual, 0) DESC,
                COALESCE(co.dias_atraso, 0) DESC,
                co.atualizado_em DESC,
                co.id DESC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if (in_array($key, ['campanha_id','credor_id','carteira_id','dias_atraso_min','dias_atraso_max','sem_contato_dias','ultimo_resultado_id'], true)) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        $candidatos = $stmt->fetchAll();

        if ($candidatos === []) {
            $this->pdo->prepare('UPDATE campanhas SET gerada_em = NOW() WHERE id = :id')->execute(['id' => $campanhaId]);
            return 0;
        }

        $prioridadeItem = (int) ($filtros['prioridade_item'] ?? 10);
        $gerados = 0;
        foreach (array_chunk($candidatos, 500) as $lote) {
            $gerados += $this->inserirLoteCampanhaItens($campanhaId, $lote, $prioridadeItem);
        }

        $this->pdo->prepare('UPDATE campanhas SET gerada_em = NOW() WHERE id = :id')->execute(['id' => $campanhaId]);

        return $gerados;
    }

    /** @param list<array<string,mixed>> $lote */
    private function inserirLoteCampanhaItens(int $campanhaId, array $lote, int $prioridadeItem): int
    {
        if ($lote === []) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($lote as $i => $row) {
            $pessoaId = (int) $row['pessoa_id'];
            $contratoId = (int) $row['contrato_id'];
            $origemFiltro = json_encode([
                'saldo_atual' => $row['saldo_atual'] ?? null,
                'dias_atraso' => $row['dias_atraso'] ?? null,
                'produto' => $row['produto'] ?? null,
                'codigo_contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $placeholders[] = "(:campanha_id_{$i}, :pessoa_id_{$i}, :contrato_id_{$i}, :chave_item_{$i}, 'pendente', :prioridade_{$i}, :origem_filtro_{$i})";
            $params["campanha_id_{$i}"] = $campanhaId;
            $params["pessoa_id_{$i}"] = $pessoaId;
            $params["contrato_id_{$i}"] = $contratoId;
            $params["chave_item_{$i}"] = 'p' . $pessoaId . '_c' . $contratoId;
            $params["prioridade_{$i}"] = $prioridadeItem;
            $params["origem_filtro_{$i}"] = $origemFiltro === false ? '{}' : $origemFiltro;
        }

        $sql = 'INSERT IGNORE INTO campanha_itens (
                campanha_id, pessoa_id, contrato_id, chave_item, status, prioridade, origem_filtro
            ) VALUES ' . implode(', ', $placeholders);
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $ehTexto = str_starts_with($key, 'origem_filtro_') || str_starts_with($key, 'chave_item_');
            $stmt->bindValue($key, $value, $ehTexto ? PDO::PARAM_STR : PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->rowCount();
    }


    /** @param array<string,mixed> $filtros */
    private function distribuirCampanhaSeSolicitado(int $campanhaId, array $filtros, int $atorUsuarioId): int
    {
        $destino = (string) ($filtros['destino_distribuicao'] ?? 'geral');
        $usuarioId = $this->normalizarIdOpcional($filtros['atribuir_usuario_id'] ?? null);

        if ($destino === 'geral') {
            $this->registrarEvento($campanhaId, null, 'distribuida_geral', 'Campanha disponibilizada na fila geral do credor/carteira.', [
                'credor_id' => $filtros['credor_id'] ?? null,
                'carteira_id' => $filtros['carteira_id'] ?? null,
            ]);
            return 0;
        }

        if ($usuarioId === null) {
            throw new InvalidArgumentException('Selecione o operador quando o destino da campanha for operador específico.');
        }

        if (!$this->tabelaExiste('operacao_cliente_reservas')) {
            return 0;
        }

        $stmtUsuario = $this->pdo->prepare("SELECT nome FROM usuarios WHERE id = :id AND status = 'ativo' LIMIT 1");
        $stmtUsuario->bindValue('id', $usuarioId, PDO::PARAM_INT);
        $stmtUsuario->execute();
        $usuarioNome = (string) ($stmtUsuario->fetchColumn() ?: 'Operador #' . $usuarioId);

        $horas = $this->horasReservaUsuario($usuarioId);
        $expiraEm = (new \DateTimeImmutable('now'))->modify('+' . $horas . ' hours')->format('Y-m-d H:i:s');

        $updateItens = $this->pdo->prepare("UPDATE campanha_itens
            SET atribuido_para = :usuario_nome,
                status = CASE WHEN status = 'pendente' THEN 'em_atendimento' ELSE status END,
                reservado_em = COALESCE(reservado_em, NOW())
            WHERE campanha_id = :campanha_id
              AND status IN ('pendente','em_atendimento')
              AND (atribuido_para IS NULL OR atribuido_para = '')");
        $updateItens->bindValue('usuario_nome', $usuarioNome);
        $updateItens->bindValue('campanha_id', $campanhaId, PDO::PARAM_INT);
        $updateItens->execute();

        $insert = $this->pdo->prepare("INSERT INTO operacao_cliente_reservas (
                pessoa_id, contrato_id, usuario_id, status, origem, campanha_item_id, observacao,
                reservado_por_usuario_id, reservado_em, expira_em, payload_extra
            )
            SELECT ci.pessoa_id, ci.contrato_id, :usuario_id, 'ativa', 'campanha', ci.id,
                   CONCAT('Distribuição automática da campanha #', ci.campanha_id),
                   NULLIF(:ator_usuario_id, 0), NOW(), :expira_em,
                   JSON_OBJECT('campanha_id', ci.campanha_id, 'horas', :horas)
            FROM campanha_itens ci
            WHERE ci.campanha_id = :campanha_id
              AND ci.status IN ('pendente','em_atendimento')
              AND NOT EXISTS (
                    SELECT 1 FROM operacao_cliente_reservas r
                    WHERE r.pessoa_id = ci.pessoa_id
                      AND r.status = 'ativa'
                      AND (r.expira_em IS NULL OR r.expira_em >= NOW())
              )");
        $insert->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $insert->bindValue('ator_usuario_id', $atorUsuarioId, PDO::PARAM_INT);
        $insert->bindValue('expira_em', $expiraEm);
        $insert->bindValue('horas', $horas, PDO::PARAM_INT);
        $insert->bindValue('campanha_id', $campanhaId, PDO::PARAM_INT);
        $insert->execute();
        $distribuidos = $insert->rowCount();

        $this->registrarEvento($campanhaId, null, 'distribuida', 'Campanha distribuída para ' . $usuarioNome . '.', [
            'usuario_id' => $usuarioId,
            'usuario_nome' => $usuarioNome,
            'itens_distribuidos' => $distribuidos,
            'expira_em' => $expiraEm,
        ]);

        return $distribuidos;
    }

    private function horasReservaUsuario(int $usuarioId): int
    {
        try {
            $stmt = $this->pdo->query("SELECT valor FROM operacao_fila_regras WHERE codigo = 'horas_reserva_operador' LIMIT 1");
            $valor = $stmt !== false ? $stmt->fetchColumn() : false;
            return max(1, min(720, (int) ($valor ?: 24)));
        } catch (Throwable) {
            return 24;
        }
    }

    private function tabelaExiste(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT 1
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
                  AND table_type = \'BASE TABLE\'
                LIMIT 1');
            $stmt->bindValue('table_name', $table);
            $stmt->execute();
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function recalcularContadores(int $campanhaId): void
    {
        $stmt = $this->pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status = 'trabalhado' THEN 1 ELSE 0 END) AS trabalhados
            FROM campanha_itens
            WHERE campanha_id = :campanha_id");
        $stmt->bindValue('campanha_id', $campanhaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        $update = $this->pdo->prepare('UPDATE campanhas
            SET total_itens = :total,
                itens_pendentes = :pendentes,
                itens_trabalhados = :trabalhados
            WHERE id = :id
            LIMIT 1');
        $update->bindValue('total', (int) ($row['total'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('pendentes', (int) ($row['pendentes'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('trabalhados', (int) ($row['trabalhados'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('id', $campanhaId, PDO::PARAM_INT);
        $update->execute();
    }

    /** @param array<string,mixed> $payload */
    private function registrarEvento(int $campanhaId, ?int $itemId, string $tipo, string $descricao, array $payload = []): void
    {
        if (!$this->moduloDisponivel()) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO campanha_eventos (
                campanha_id,
                campanha_item_id,
                tipo,
                descricao,
                payload_extra
            ) VALUES (
                :campanha_id,
                :campanha_item_id,
                :tipo,
                :descricao,
                :payload_extra
            )');
        $stmt->bindValue('campanha_id', $campanhaId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'campanha_item_id', $itemId);
        $stmt->bindValue('tipo', mb_substr($tipo, 0, 80));
        $stmt->bindValue('descricao', mb_substr($descricao, 0, 500));
        $stmt->bindValue('payload_extra', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();
    }

    /** @param array<string,mixed> $dados */
    private function normalizarFiltros(array $dados): array
    {
        return [
            'credor_id' => $this->normalizarIdOpcional($dados['credor_id'] ?? null),
            'carteira_id' => $this->normalizarIdOpcional($dados['carteira_id'] ?? null),
            'produto' => $this->normalizarTexto($dados['produto'] ?? null, 180, false),
            'saldo_min' => $this->normalizarDecimalOpcional($dados['saldo_min'] ?? null),
            'saldo_max' => $this->normalizarDecimalOpcional($dados['saldo_max'] ?? null),
            'dias_atraso_min' => $this->normalizarInteiroOpcional($dados['dias_atraso_min'] ?? null, 0, 99999),
            'dias_atraso_max' => $this->normalizarInteiroOpcional($dados['dias_atraso_max'] ?? null, 0, 99999),
            'exigir_contato_valido' => (string) ($dados['exigir_contato_valido'] ?? '') === '1',
            'excluir_retorno_pendente' => (string) ($dados['excluir_retorno_pendente'] ?? '') === '1',
            'sem_contato_efetivo_dias' => $this->normalizarInteiroOpcional($dados['sem_contato_efetivo_dias'] ?? null, 1, 9999),
            'ultimo_resultado_id' => $this->normalizarIdOpcional($dados['ultimo_resultado_id'] ?? null),
            'excluir_acordo_ativo' => (string) ($dados['excluir_acordo_ativo'] ?? '') === '1',
            'excluir_reserva_ativa' => (string) ($dados['excluir_reserva_ativa'] ?? '') === '1',
            'destino_distribuicao' => $this->normalizarDestinoDistribuicao($dados['destino_distribuicao'] ?? null, $dados['atribuir_usuario_id'] ?? null),
            'atribuir_usuario_id' => $this->normalizarIdOpcional($dados['atribuir_usuario_id'] ?? null),
        ];
    }

    private function normalizarDestinoDistribuicao(mixed $valor, mixed $usuarioIdRaw = null): string
    {
        $destino = strtolower(trim((string) ($valor ?? '')));
        if ($destino === '') {
            return $this->normalizarIdOpcional($usuarioIdRaw) !== null ? 'operador' : 'geral';
        }

        return in_array($destino, ['geral', 'operador'], true) ? $destino : 'geral';
    }

    /** @param array<string,mixed> $filtros */
    private function validarFiltrosDentroDoEscopo(array $filtros, ?array $escopo): void
    {
        if ($escopo === null || (bool) ($escopo['todos'] ?? false)) {
            return;
        }

        $credorId = $this->normalizarIdOpcional($filtros['credor_id'] ?? null);
        $carteiraId = $this->normalizarIdOpcional($filtros['carteira_id'] ?? null);
        $credorIds = array_values(array_filter(array_map('intval', $escopo['credor_ids'] ?? [])));
        $carteiraIds = array_values(array_filter(array_map('intval', $escopo['carteira_ids'] ?? [])));

        if ($credorId !== null && !in_array($credorId, $credorIds, true)) {
            throw new InvalidArgumentException('Seu usuário não tem permissão para criar campanha nesse credor.');
        }

        if ($carteiraId !== null && !in_array($carteiraId, $carteiraIds, true)) {
            $stmt = $this->pdo->prepare('SELECT credor_id FROM carteiras WHERE id = :id LIMIT 1');
            $stmt->bindValue('id', $carteiraId, PDO::PARAM_INT);
            $stmt->execute();
            $credorCarteira = $stmt->fetchColumn();
            if ($credorCarteira === false || !in_array((int) $credorCarteira, $credorIds, true)) {
                throw new InvalidArgumentException('Seu usuário não tem permissão para criar campanha nessa carteira.');
            }
        }
    }

    private function normalizarStatusItemFiltro(string $status): string
    {
        $status = trim($status);
        if ($status === '' || $status === 'todos') {
            return $status === 'todos' ? 'todos' : 'pendente';
        }

        return array_key_exists($status, self::STATUS_ITEM) ? $status : 'pendente';
    }

    private function normalizarTexto(mixed $value, int $limite, bool $obrigatorio): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException('Informe o campo obrigatório.');
            }
            return null;
        }

        return mb_substr($texto, 0, $limite);
    }

    private function normalizarInteiro(mixed $value, int $min, int $max, int $default): int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return $default;
        }

        if (!ctype_digit($texto)) {
            return $default;
        }

        return max($min, min($max, (int) $texto));
    }

    private function normalizarInteiroOpcional(mixed $value, int $min, int $max): ?int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        if (!ctype_digit($texto)) {
            throw new InvalidArgumentException('Filtro numérico inválido.');
        }

        return max($min, min($max, (int) $texto));
    }

    private function normalizarIdOpcional(mixed $value): ?int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        if (!ctype_digit($texto)) {
            throw new InvalidArgumentException('ID inválido informado nos filtros.');
        }

        $id = (int) $texto;
        return $id > 0 ? $id : null;
    }

    private function normalizarDecimalOpcional(mixed $value): ?float
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        $texto = str_replace(['R$', ' '], '', $texto);
        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        } elseif (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', $texto);
        }

        if (!is_numeric($texto)) {
            throw new InvalidArgumentException('Valor monetário inválido nos filtros.');
        }

        return (float) $texto;
    }

    /** @return array<string,mixed> */
    private function decodeJsonArray(mixed $json): array
    {
        $decoded = json_decode((string) ($json ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function bindNullableString(\PDOStatement $stmt, string $key, ?string $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value);
    }

    private function bindNullableInt(\PDOStatement $stmt, string $key, ?int $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    public static function statusCampanhaLabel(?string $status): string
    {
        return self::STATUS_CAMPANHA[(string) $status] ?? 'Rascunho';
    }

    public static function statusItemLabel(?string $status): string
    {
        return self::STATUS_ITEM[(string) $status] ?? 'Pendente';
    }
}
