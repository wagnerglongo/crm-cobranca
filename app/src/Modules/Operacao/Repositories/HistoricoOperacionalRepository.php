<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use PDO;
use Throwable;

final class HistoricoOperacionalRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function resumoCliente(int $pessoaId, ?array $escopo = null): array
    {
        return [
            'atendimentos' => $this->countAtendimentos($pessoaId, $escopo),
            'acordos' => $this->countAcordos($pessoaId, $escopo),
            'reservas' => $this->countReservas($pessoaId, $escopo),
            'campanhas' => $this->countCampanhas($pessoaId, $escopo),
            'retornos_pendentes' => $this->countRetornosPendentes($pessoaId, $escopo),
            'ultimo_evento' => $this->ultimoEvento($pessoaId, $escopo),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function timelineCliente(int $pessoaId, ?array $escopo = null, int $limite = 200): array
    {
        $limite = max(10, min($limite, 500));
        $eventos = [];
        $eventos = array_merge($eventos, $this->eventosAtendimentos($pessoaId, $escopo));
        $eventos = array_merge($eventos, $this->eventosRetornos($pessoaId, $escopo));
        $eventos = array_merge($eventos, $this->eventosAcordos($pessoaId, $escopo));
        $eventos = array_merge($eventos, $this->eventosCampanhas($pessoaId, $escopo));
        $eventos = array_merge($eventos, $this->eventosReservas($pessoaId, $escopo));
        $eventos = array_merge($eventos, $this->eventosFila($pessoaId, $escopo));
        $eventos = array_merge($eventos, $this->eventosManuais($pessoaId, $escopo));

        usort($eventos, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['data'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['data'] ?? '')) ?: 0;
            if ($ta === $tb) {
                return strcmp((string) ($b['origem_id'] ?? ''), (string) ($a['origem_id'] ?? ''));
            }
            return $tb <=> $ta;
        });

        return array_slice($eventos, 0, $limite);
    }

    /** @return list<array<string,mixed>> */
    private function eventosAtendimentos(int $pessoaId, ?array $escopo): array
    {
        if (!$this->tabelaExiste('atendimentos_historico')) {
            return [];
        }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'hist_at');
        $stmt = $this->pdo->prepare("SELECT ah.id, ah.criado_em, ah.canal, ah.resultado, ah.operador_nome, ah.contato_utilizado, ah.observacao,
                ah.proximo_acionamento, co.id AS contrato_id, co.codigo_contrato, cr.nome AS credor_nome, car.nome AS carteira_nome,
                ar.nome AS resultado_nome, ar.categoria AS resultado_categoria
            FROM atendimentos_historico ah
            LEFT JOIN contratos co ON co.id = ah.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
            WHERE ah.pessoa_id = :pessoa_id AND {$scope['sql']}
            ORDER BY ah.criado_em DESC, ah.id DESC LIMIT 120");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $resultado = (string) (($row['resultado_nome'] ?? '') ?: ($row['resultado'] ?? 'Atendimento'));
            $out[] = [
                'tipo' => 'atendimento',
                'icone' => 'AT',
                'data' => $row['criado_em'] ?? null,
                'titulo' => 'Atendimento: ' . $resultado,
                'badge' => (string) ($row['canal'] ?? 'manual'),
                'descricao' => $row['observacao'] ?? null,
                'operador' => $row['operador_nome'] ?? null,
                'contrato_id' => $row['contrato_id'] ?? null,
                'contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'contato' => $row['contato_utilizado'] ?? null,
                'extra' => !empty($row['proximo_acionamento']) ? 'Retorno agendado para ' . (string) $row['proximo_acionamento'] : null,
                'origem' => 'atendimentos_historico',
                'origem_id' => (int) $row['id'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function eventosRetornos(int $pessoaId, ?array $escopo): array
    {
        if (!$this->tabelaExiste('atendimentos_historico')) {
            return [];
        }
        $statusCol = $this->colunaExiste('atendimentos_historico', 'retorno_status') ? "COALESCE(ah.retorno_status, 'pendente')" : "'pendente'";
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'hist_rt');
        $stmt = $this->pdo->prepare("SELECT ah.id, ah.proximo_acionamento, ah.operador_nome, ah.contato_utilizado, ah.observacao,
                {$statusCol} AS retorno_status, co.id AS contrato_id, co.codigo_contrato, cr.nome AS credor_nome, car.nome AS carteira_nome
            FROM atendimentos_historico ah
            LEFT JOIN contratos co ON co.id = ah.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE ah.pessoa_id = :pessoa_id AND ah.proximo_acionamento IS NOT NULL AND {$scope['sql']}
            ORDER BY ah.proximo_acionamento DESC, ah.id DESC LIMIT 80");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $vencido = !empty($row['proximo_acionamento']) && strtotime((string) $row['proximo_acionamento']) < time() && (string) $row['retorno_status'] === 'pendente';
            $out[] = [
                'tipo' => $vencido ? 'retorno_vencido' : 'retorno',
                'icone' => 'RT',
                'data' => $row['proximo_acionamento'] ?? null,
                'titulo' => $vencido ? 'Retorno vencido' : 'Retorno agendado',
                'badge' => (string) ($row['retorno_status'] ?? 'pendente'),
                'descricao' => $row['observacao'] ?? null,
                'operador' => $row['operador_nome'] ?? null,
                'contrato_id' => $row['contrato_id'] ?? null,
                'contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'contato' => $row['contato_utilizado'] ?? null,
                'extra' => null,
                'origem' => 'retorno_atendimento',
                'origem_id' => (int) $row['id'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function eventosAcordos(int $pessoaId, ?array $escopo): array
    {
        if (!$this->tabelaExiste('acordos_operacionais')) {
            return [];
        }
        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'co', 'hist_ac');
        $stmt = $this->pdo->prepare("SELECT ao.id, ao.criado_em, ao.status_operacional, ao.status_integracao, ao.valor_acordo, ao.operador_nome,
                ao.data_promessa_pagamento, ao.observacao, co.id AS contrato_id, co.codigo_contrato, cr.nome AS credor_nome, car.nome AS carteira_nome
            FROM acordos_operacionais ao
            LEFT JOIN contratos co ON co.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, co.credor_id)
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE ao.pessoa_id = :pessoa_id AND {$scope['sql']}
            ORDER BY ao.criado_em DESC, ao.id DESC LIMIT 80");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'tipo' => 'acordo',
                'icone' => 'AC',
                'data' => $row['criado_em'] ?? null,
                'titulo' => 'Acordo ' . (string) ($row['status_operacional'] ?? 'pendente'),
                'badge' => 'R$ ' . number_format((float) ($row['valor_acordo'] ?? 0), 2, ',', '.'),
                'descricao' => $row['observacao'] ?? null,
                'operador' => $row['operador_nome'] ?? null,
                'contrato_id' => $row['contrato_id'] ?? null,
                'contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'contato' => null,
                'extra' => 'Vencimento inicial: ' . (string) ($row['data_promessa_pagamento'] ?? '-'),
                'origem' => 'acordos_operacionais',
                'origem_id' => (int) $row['id'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function eventosCampanhas(int $pessoaId, ?array $escopo): array
    {
        if (!$this->tabelaExiste('campanha_itens')) {
            return [];
        }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'hist_cp');
        $stmt = $this->pdo->prepare("SELECT ci.id, ci.gerado_em, ci.status, ci.prioridade, ci.observacao, ci.trabalhado_em,
                c.nome AS campanha_nome, c.status AS campanha_status, co.id AS contrato_id, co.codigo_contrato,
                cr.nome AS credor_nome, car.nome AS carteira_nome
            FROM campanha_itens ci
            INNER JOIN campanhas c ON c.id = ci.campanha_id
            LEFT JOIN contratos co ON co.id = ci.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE ci.pessoa_id = :pessoa_id AND {$scope['sql']}
            ORDER BY ci.gerado_em DESC, ci.id DESC LIMIT 80");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'tipo' => 'campanha',
                'icone' => 'CP',
                'data' => $row['gerado_em'] ?? null,
                'titulo' => 'Entrou na campanha: ' . (string) ($row['campanha_nome'] ?? '-'),
                'badge' => (string) ($row['status'] ?? 'pendente'),
                'descricao' => $row['observacao'] ?? null,
                'operador' => null,
                'contrato_id' => $row['contrato_id'] ?? null,
                'contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'contato' => null,
                'extra' => !empty($row['trabalhado_em']) ? 'Trabalhado em ' . (string) $row['trabalhado_em'] : null,
                'origem' => 'campanha_itens',
                'origem_id' => (int) $row['id'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function eventosReservas(int $pessoaId, ?array $escopo): array
    {
        if (!$this->tabelaExiste('operacao_cliente_reservas')) {
            return [];
        }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'hist_rv');
        $stmt = $this->pdo->prepare("SELECT r.id, r.reservado_em, r.expira_em, r.status, r.origem, r.observacao,
                u.nome AS usuario_nome, ur.nome AS reservado_por_nome, co.id AS contrato_id, co.codigo_contrato,
                cr.nome AS credor_nome, car.nome AS carteira_nome
            FROM operacao_cliente_reservas r
            INNER JOIN usuarios u ON u.id = r.usuario_id
            LEFT JOIN usuarios ur ON ur.id = r.reservado_por_usuario_id
            LEFT JOIN contratos co ON co.id = r.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE r.pessoa_id = :pessoa_id AND {$scope['sql']}
            ORDER BY r.reservado_em DESC, r.id DESC LIMIT 80");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'tipo' => 'reserva',
                'icone' => 'RS',
                'data' => $row['reservado_em'] ?? null,
                'titulo' => 'Cliente reservado para ' . (string) ($row['usuario_nome'] ?? '-'),
                'badge' => (string) ($row['status'] ?? 'ativa'),
                'descricao' => $row['observacao'] ?? null,
                'operador' => $row['usuario_nome'] ?? null,
                'contrato_id' => $row['contrato_id'] ?? null,
                'contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'contato' => null,
                'extra' => 'Origem: ' . (string) ($row['origem'] ?? '-') . ' · Expira em ' . (string) ($row['expira_em'] ?? '-'),
                'origem' => 'operacao_cliente_reservas',
                'origem_id' => (int) $row['id'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function eventosFila(int $pessoaId, ?array $escopo): array
    {
        if (!$this->tabelaExiste('operacao_fila_movimentos')) {
            return [];
        }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'hist_fm');
        $stmt = $this->pdo->prepare("SELECT m.*, uo.nome AS usuario_origem_nome, ud.nome AS usuario_destino_nome,
                co.id AS contrato_id_real, co.codigo_contrato, cr.nome AS credor_nome, car.nome AS carteira_nome
            FROM operacao_fila_movimentos m
            LEFT JOIN usuarios uo ON uo.id = m.usuario_origem_id
            LEFT JOIN usuarios ud ON ud.id = m.usuario_destino_id
            LEFT JOIN contratos co ON co.id = m.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE m.pessoa_id = :pessoa_id AND {$scope['sql']}
            ORDER BY m.criado_em DESC, m.id DESC LIMIT 80");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $operador = $row['usuario_destino_nome'] ?? $row['usuario_origem_nome'] ?? null;
            $out[] = [
                'tipo' => 'fila',
                'icone' => 'FL',
                'data' => $row['criado_em'] ?? null,
                'titulo' => 'Movimento de fila: ' . (string) ($row['tipo'] ?? '-'),
                'badge' => (string) ($row['tipo'] ?? 'fila'),
                'descricao' => $row['descricao'] ?? null,
                'operador' => $operador,
                'contrato_id' => $row['contrato_id_real'] ?? null,
                'contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'contato' => null,
                'extra' => null,
                'origem' => 'operacao_fila_movimentos',
                'origem_id' => (int) $row['id'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function eventosManuais(int $pessoaId, ?array $escopo): array
    {
        if (!$this->tabelaExiste('operacao_cliente_historico_eventos')) {
            return [];
        }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'hist_ev');
        $stmt = $this->pdo->prepare("SELECT e.*, u.nome AS usuario_nome, co.id AS contrato_id_real, co.codigo_contrato, cr.nome AS credor_nome, car.nome AS carteira_nome
            FROM operacao_cliente_historico_eventos e
            LEFT JOIN usuarios u ON u.id = e.usuario_id
            LEFT JOIN contratos co ON co.id = e.contrato_id
            LEFT JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            WHERE e.pessoa_id = :pessoa_id AND {$scope['sql']}
            ORDER BY e.ocorrido_em DESC, e.id DESC LIMIT 80");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'tipo' => (string) ($row['tipo'] ?? 'evento'),
                'icone' => 'EV',
                'data' => $row['ocorrido_em'] ?? null,
                'titulo' => (string) ($row['titulo'] ?? 'Evento operacional'),
                'badge' => (string) ($row['tipo'] ?? 'evento'),
                'descricao' => $row['descricao'] ?? null,
                'operador' => $row['usuario_nome'] ?? null,
                'contrato_id' => $row['contrato_id_real'] ?? null,
                'contrato' => $row['codigo_contrato'] ?? null,
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'contato' => null,
                'extra' => null,
                'origem' => 'operacao_cliente_historico_eventos',
                'origem_id' => (int) $row['id'],
            ];
        }
        return $out;
    }

    private function countAtendimentos(int $pessoaId, ?array $escopo): int { return $this->countScoped('atendimentos_historico', 'ah', 'ah.pessoa_id', $pessoaId, 'ah.contrato_id', $escopo); }
    private function countAcordos(int $pessoaId, ?array $escopo): int { return $this->countScoped('acordos_operacionais', 'ao', 'ao.pessoa_id', $pessoaId, 'ao.contrato_id', $escopo); }
    private function countCampanhas(int $pessoaId, ?array $escopo): int { return $this->countScoped('campanha_itens', 'ci', 'ci.pessoa_id', $pessoaId, 'ci.contrato_id', $escopo); }
    private function countReservas(int $pessoaId, ?array $escopo): int { return $this->countScoped('operacao_cliente_reservas', 'r', 'r.pessoa_id', $pessoaId, 'r.contrato_id', $escopo); }

    private function countRetornosPendentes(int $pessoaId, ?array $escopo): int
    {
        if (!$this->tabelaExiste('atendimentos_historico')) { return 0; }
        $status = $this->colunaExiste('atendimentos_historico', 'retorno_status') ? "AND COALESCE(ah.retorno_status, 'pendente') = 'pendente'" : '';
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'count_rt');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM atendimentos_historico ah LEFT JOIN contratos co ON co.id = ah.contrato_id WHERE ah.pessoa_id = :pessoa_id AND ah.proximo_acionamento IS NOT NULL {$status} AND {$scope['sql']}");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function countScoped(string $table, string $alias, string $pessoaColumn, int $pessoaId, string $contratoColumn, ?array $escopo): int
    {
        if (!$this->tabelaExiste($table)) { return 0; }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'cnt_' . preg_replace('/\W+/', '', $table));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} {$alias} LEFT JOIN contratos co ON co.id = {$contratoColumn} WHERE {$pessoaColumn} = :pessoa_id AND {$scope['sql']}");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function ultimoEvento(int $pessoaId, ?array $escopo): ?array
    {
        $timeline = $this->timelineCliente($pessoaId, $escopo, 1);
        return $timeline[0] ?? null;
    }

    private function tabelaExiste(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->bindValue('table', $table);
            $stmt->execute();
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function colunaExiste(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
            $stmt->bindValue('table', $table);
            $stmt->bindValue('column', $column);
            $stmt->execute();
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
