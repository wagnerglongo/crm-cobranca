<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use PDO;
use Throwable;

final class CargasOperacaoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        return $this->tabelaExiste('cargas_arquivos');
    }

    /** @return list<array<string,mixed>> */
    public function listarCredores(?array $escopo = null): array
    {
        if (!$this->tabelaExiste('credores')) {
            return [];
        }

        $params = [];
        $where = ['cr.ativo = 1'];
        if ($escopo !== null && empty($escopo['todos'])) {
            $ids = array_values(array_filter(array_map('intval', $escopo['credor_ids'] ?? [])));
            if ($ids === []) {
                return [];
            }
            $ph = [];
            foreach ($ids as $i => $id) {
                $key = 'credor_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'cr.id IN (' . implode(',', $ph) . ')';
        }

        $stmt = $this->pdo->prepare('SELECT cr.id, cr.codigo, cr.nome FROM credores cr WHERE ' . implode(' AND ', $where) . ' ORDER BY cr.nome');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $filtros @return list<array<string,mixed>> */
    public function listarCargas(array $filtros, ?array $escopo = null, int $limite = 80): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 300));
        $where = [];
        $params = [];
        $scope = EscopoCredorSql::contrato($escopo, 'co_scope', 'cargas_scope');
        if ($scope['sql'] !== '1=1') {
            $where[] = '(ca.credor_id IS NULL OR EXISTS (SELECT 1 FROM contratos co_scope WHERE co_scope.credor_id = ca.credor_id AND ' . $scope['sql'] . ' LIMIT 1))';
            $params = array_merge($params, $scope['params']);
        }

        $credorId = $this->idFiltro($filtros['credor_id'] ?? '');
        if ($credorId !== null) {
            $where[] = 'ca.credor_id = :credor_id';
            $params['credor_id'] = $credorId;
        }

        $status = trim((string) ($filtros['status'] ?? ''));
        if ($status !== '' && $status !== 'todos') {
            $where[] = 'ca.status = :status';
            $params['status'] = $status;
        }

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            // Com PDO::ATTR_EMULATE_PREPARES=false, o driver nao aceita o
            // mesmo placeholder nomeado repetido varias vezes na mesma query
            // (cada ocorrencia exige seu proprio parametro) - por isso 3
            // placeholders distintos com o mesmo valor, em vez de :q 3x.
            $where[] = '(ca.nome_original LIKE :q1 OR ca.hash_arquivo LIKE :q2 OR ca.erro_resumo LIKE :q3)';
            $termoBusca = '%' . $q . '%';
            $params['q1'] = $termoBusca;
            $params['q2'] = $termoBusca;
            $params['q3'] = $termoBusca;
        }

        $dataDe = $this->dataFiltro($filtros['data_de'] ?? '');
        if ($dataDe !== null) {
            $where[] = 'DATE(ca.criado_em) >= :data_de';
            $params['data_de'] = $dataDe;
        }
        $dataAte = $this->dataFiltro($filtros['data_ate'] ?? '');
        if ($dataAte !== null) {
            $where[] = 'DATE(ca.criado_em) <= :data_ate';
            $params['data_ate'] = $dataAte;
        }

        $sql = 'SELECT ca.*, cr.nome AS credor_nome, car.nome AS carteira_nome, cl.codigo AS layout_codigo,
                (SELECT COUNT(*) FROM cargas_erros ce WHERE ce.carga_arquivo_id = ca.id) AS erros_total
            FROM cargas_arquivos ca
            LEFT JOIN credores cr ON cr.id = ca.credor_id
            LEFT JOIN carteiras car ON car.id = ca.carteira_id
            LEFT JOIN carga_layouts cl ON cl.id = ca.layout_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ca.id DESC LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, is_int($value) ? $value : (string) $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarCarga(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ca.*, cr.nome AS credor_nome, car.nome AS carteira_nome, cl.codigo AS layout_codigo
            FROM cargas_arquivos ca
            LEFT JOIN credores cr ON cr.id = ca.credor_id
            LEFT JOIN carteiras car ON car.id = ca.carteira_id
            LEFT JOIN carga_layouts cl ON cl.id = ca.layout_id
            WHERE ca.id = :id LIMIT 1');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listarErros(int $cargaId, int $limite = 80): array
    {
        if (!$this->tabelaExiste('cargas_erros')) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM cargas_erros WHERE carga_arquivo_id = :id ORDER BY id DESC LIMIT :limite');
        $stmt->bindValue('id', $cargaId, PDO::PARAM_INT);
        $stmt->bindValue('limite', max(1, min($limite, 300)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    public function resumo(): array
    {
        if (!$this->moduloDisponivel()) {
            return ['total' => 0, 'sucesso' => 0, 'erro' => 0, 'processando' => 0, 'pendentes' => 0];
        }
        $stmt = $this->pdo->query("SELECT COUNT(*) total,
            SUM(CASE WHEN status IN ('concluido','concluido_com_erros') THEN 1 ELSE 0 END) sucesso,
            SUM(CASE WHEN status IN ('erro','cancelado','layout_nao_identificado') THEN 1 ELSE 0 END) erro,
            SUM(CASE WHEN status IN ('identificando_layout','processando_raw','normalizando','validando','aplicando','indexando') THEN 1 ELSE 0 END) processando,
            SUM(CASE WHEN status IN ('recebido','aguardando_processamento') THEN 1 ELSE 0 END) pendentes
            FROM cargas_arquivos");
        $row = $stmt !== false ? ($stmt->fetch() ?: []) : [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'sucesso' => (int) ($row['sucesso'] ?? 0),
            'erro' => (int) ($row['erro'] ?? 0),
            'processando' => (int) ($row['processando'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
        ];
    }

    public function prepararReprocessamento(int $cargaId, int $usuarioId): void
    {
        $stmt = $this->pdo->prepare("UPDATE cargas_arquivos
            SET status = 'aguardando_processamento', erro_resumo = NULL, iniciado_em = NULL, finalizado_em = NULL,
                payload_extra = JSON_SET(COALESCE(payload_extra, JSON_OBJECT()), '$.reprocessamento_solicitado_por', CAST(:usuario_id AS UNSIGNED), '$.reprocessamento_solicitado_em', NOW())
            WHERE id = :id LIMIT 1");
        $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue('id', $cargaId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function idFiltro(mixed $value): ?int
    {
        $v = trim((string) $value);
        return ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }

    private function dataFiltro(mixed $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        return $v;
    }

    private function tabelaExiste(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
                LIMIT 1");
            $stmt->bindValue('table_name', $table, PDO::PARAM_STR);
            $stmt->execute();
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
