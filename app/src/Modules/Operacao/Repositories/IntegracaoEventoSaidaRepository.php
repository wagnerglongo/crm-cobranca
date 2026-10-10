<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use PDO;
use PDOStatement;
use Throwable;

final class IntegracaoEventoSaidaRepository
{
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
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'integracao_eventos_saida'");
            $saida = $stmt !== false && $stmt->fetchColumn() !== false;
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'integracao_eventos_tentativas'");
            $tentativas = $stmt !== false && $stmt->fetchColumn() !== false;
            $this->moduloDisponivel = $saida && $tentativas;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @param array<string,mixed> $contexto @param array<string,mixed> $payload */
    public function enfileirar(array $contexto, array $payload, string $eventoUuid): int
    {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $stmt = $this->pdo->prepare('INSERT INTO integracao_eventos_saida (
                evento_uuid, integracao_id, credor_id, evento_tipo,
                origem_tabela, origem_id, pessoa_id, contrato_id, resultado_id,
                mapeamento_id, mapeamento_versao, status, prioridade, payload,
                tentativas, max_tentativas, disponivel_em
            ) VALUES (
                :evento_uuid, :integracao_id, :credor_id, :evento_tipo,
                :origem_tabela, :origem_id, :pessoa_id, :contrato_id, :resultado_id,
                :mapeamento_id, :mapeamento_versao, \'pendente\', :prioridade, :payload,
                0, :max_tentativas, CURRENT_TIMESTAMP(3)
            ) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        $stmt->bindValue('evento_uuid', $eventoUuid);
        $stmt->bindValue('integracao_id', (int) ($contexto['integracao_id'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue('credor_id', (int) ($contexto['credor_id'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue('evento_tipo', (string) ($contexto['evento_tipo'] ?? 'acionamento.registrado'));
        $stmt->bindValue('origem_tabela', 'atendimentos_historico');
        $stmt->bindValue('origem_id', (int) ($contexto['atendimento_id'] ?? 0), PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'pessoa_id', $this->intOrNull($contexto['pessoa_id'] ?? null));
        $this->bindNullableInt($stmt, 'contrato_id', $this->intOrNull($contexto['contrato_id'] ?? null));
        $this->bindNullableInt($stmt, 'resultado_id', $this->intOrNull($contexto['resultado_id'] ?? null));
        $this->bindNullableInt($stmt, 'mapeamento_id', $this->intOrNull($contexto['mapeamento_id'] ?? null));
        $this->bindNullableInt($stmt, 'mapeamento_versao', $this->intOrNull($contexto['mapeamento_versao'] ?? null));
        $stmt->bindValue('prioridade', max(1, min(999, (int) ($contexto['prioridade'] ?? 100))), PDO::PARAM_INT);
        $stmt->bindValue('payload', $this->json($payload));
        $stmt->bindValue('max_tentativas', max(1, min(100, (int) ($contexto['max_tentativas'] ?? 8))), PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function buscarPorOrigem(int $integracaoId, string $eventoTipo, string $origemTabela, int $origemId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, payload FROM integracao_eventos_saida
            WHERE integracao_id = :integracao_id AND evento_tipo = :evento_tipo
              AND origem_tabela = :origem_tabela AND origem_id = :origem_id
            LIMIT 1');
        $stmt->bindValue('integracao_id', $integracaoId, PDO::PARAM_INT);
        $stmt->bindValue('evento_tipo', $eventoTipo);
        $stmt->bindValue('origem_tabela', $origemTabela);
        $stmt->bindValue('origem_id', $origemId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['payload_array'] = $this->decodeJson($row['payload'] ?? null);
        return $row;
    }

    /**
     * Fila de ESTADO DESEJADO (contatos, 2026-10-06): um evento por registro
     * de origem (a chave unica da tabela ja e integracao+tipo+origem). Uma
     * nova alteracao do mesmo registro reaproveita o evento: payload novo,
     * volta pra pendente e zera as tentativas - inclusive se ja tinha sido
     * enviado. Se estiver 'processando', marcarEnviado() nao o fecha
     * (WHERE status = 'processando'), entao ele e reenviado com o estado novo.
     *
     * @param array<string,mixed> $contexto integracao_id, credor_id, evento_tipo, pessoa_id, contrato_id, max_tentativas, prioridade
     * @param array<string,mixed> $payload
     */
    public function enfileirarEstado(array $contexto, array $payload, string $eventoUuid, string $origemTabela, int $origemId): int
    {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $stmt = $this->pdo->prepare('INSERT INTO integracao_eventos_saida (
                evento_uuid, integracao_id, credor_id, evento_tipo,
                origem_tabela, origem_id, pessoa_id, contrato_id,
                status, prioridade, payload, tentativas, max_tentativas, disponivel_em
            ) VALUES (
                :evento_uuid, :integracao_id, :credor_id, :evento_tipo,
                :origem_tabela, :origem_id, :pessoa_id, :contrato_id,
                \'pendente\', :prioridade, :payload, 0, :max_tentativas, CURRENT_TIMESTAMP(3)
            ) AS novo ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(integracao_eventos_saida.id),
                pessoa_id = novo.pessoa_id,
                contrato_id = novo.contrato_id,
                payload = novo.payload,
                status = \'pendente\',
                tentativas = 0,
                max_tentativas = novo.max_tentativas,
                disponivel_em = CURRENT_TIMESTAMP(3),
                bloqueado_em = NULL,
                bloqueado_por = NULL,
                ultimo_erro = NULL');
        $stmt->bindValue('evento_uuid', $eventoUuid);
        $stmt->bindValue('integracao_id', (int) ($contexto['integracao_id'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue('credor_id', (int) ($contexto['credor_id'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue('evento_tipo', (string) ($contexto['evento_tipo'] ?? ''));
        $stmt->bindValue('origem_tabela', $origemTabela);
        $stmt->bindValue('origem_id', $origemId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'pessoa_id', $this->intOrNull($contexto['pessoa_id'] ?? null));
        $this->bindNullableInt($stmt, 'contrato_id', $this->intOrNull($contexto['contrato_id'] ?? null));
        $stmt->bindValue('prioridade', max(1, min(999, (int) ($contexto['prioridade'] ?? 100))), PDO::PARAM_INT);
        $stmt->bindValue('payload', $this->json($payload));
        $stmt->bindValue('max_tentativas', max(1, min(100, (int) ($contexto['max_tentativas'] ?? 8))), PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function recuperarTravados(int $segundos): int
    {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $segundos = max(30, min(86400, $segundos));
        $iniciou = !$this->pdo->inTransaction();

        try {
            if ($iniciou) {
                $this->pdo->beginTransaction();
            }

            // Finaliza também a tentativa que ficou aberta. Sem isso, o evento era
            // recuperado, mas a auditoria continuava eternamente como "iniciada".
            $tentativas = $this->pdo->prepare("UPDATE integracao_eventos_tentativas iet
                INNER JOIN integracao_eventos_saida ies ON ies.id = iet.evento_saida_id
                SET iet.status = CASE
                        WHEN ies.tentativas >= ies.max_tentativas THEN 'erro_definitivo'
                        ELSE 'erro_retry'
                    END,
                    iet.erro = 'Worker interrompido ou sem heartbeat; lock recuperado automaticamente após timeout.',
                    iet.finalizado_em = CURRENT_TIMESTAMP(3)
                WHERE ies.status = 'processando'
                  AND ies.bloqueado_em < DATE_SUB(CURRENT_TIMESTAMP(3), INTERVAL {$segundos} SECOND)
                  AND iet.numero_tentativa = ies.tentativas
                  AND iet.status = 'iniciada'");
            $tentativas->execute();

            $stmt = $this->pdo->prepare("UPDATE integracao_eventos_saida
                SET status = CASE WHEN tentativas >= max_tentativas THEN 'erro_definitivo' ELSE 'erro_retry' END,
                    disponivel_em = CASE WHEN tentativas >= max_tentativas THEN disponivel_em ELSE CURRENT_TIMESTAMP(3) END,
                    ultimo_erro = CONCAT(
                        COALESCE(ultimo_erro, ''),
                        CASE WHEN COALESCE(ultimo_erro, '') = '' THEN '' ELSE '\\n' END,
                        'Lock recuperado automaticamente após timeout.'
                    ),
                    bloqueado_em = NULL,
                    bloqueado_por = NULL
                WHERE status = 'processando'
                  AND bloqueado_em < DATE_SUB(CURRENT_TIMESTAMP(3), INTERVAL {$segundos} SECOND)");
            $stmt->execute();
            $recuperados = $stmt->rowCount();

            if ($iniciou) {
                $this->pdo->commit();
            }

            return $recuperados;
        } catch (Throwable $e) {
            if ($iniciou && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<int> $eventoIds */
    public function renovarReservas(array $eventoIds, string $workerId): int
    {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $ids = $this->normalizarIds($eventoIds);
        $workerId = mb_substr(trim($workerId), 0, 120);
        if ($ids === [] || $workerId === '') {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE integracao_eventos_saida
            SET bloqueado_em = CURRENT_TIMESTAMP(3)
            WHERE status = 'processando'
              AND bloqueado_por = ?
              AND id IN ({$placeholders})");
        $stmt->execute(array_merge([$workerId], $ids));
        return $stmt->rowCount();
    }

    public function reservaPertenceAoWorker(int $eventoId, string $workerId): bool
    {
        if (!$this->moduloDisponivel() || $eventoId <= 0) {
            return false;
        }

        $workerId = mb_substr(trim($workerId), 0, 120);
        if ($workerId === '') {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT 1
            FROM integracao_eventos_saida
            WHERE id = :id
              AND status = 'processando'
              AND bloqueado_por = :worker_id
            LIMIT 1");
        $stmt->bindValue('id', $eventoId, PDO::PARAM_INT);
        $stmt->bindValue('worker_id', $workerId);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function reservarLote(string $workerId, int $limite, int $lockSegundos): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $this->recuperarTravados($lockSegundos);
        $limite = max(1, min(500, $limite));
        $ids = [];
        $iniciou = !$this->pdo->inTransaction();

        try {
            if ($iniciou) {
                $this->pdo->beginTransaction();
            }

            $select = $this->pdo->prepare("SELECT ies.id
                FROM integracao_eventos_saida ies
                INNER JOIN credor_integracoes ci ON ci.id = ies.integracao_id AND ci.ativo = 1
                WHERE ies.status IN ('pendente','erro_retry')
                  AND ies.disponivel_em <= CURRENT_TIMESTAMP(3)
                  AND ies.tentativas < ies.max_tentativas
                ORDER BY ies.prioridade ASC, ies.disponivel_em ASC, ies.id ASC
                LIMIT {$limite}
                FOR UPDATE SKIP LOCKED");
            $select->execute();
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare("UPDATE integracao_eventos_saida
                    SET status = 'processando',
                        tentativas = tentativas + 1,
                        bloqueado_em = CURRENT_TIMESTAMP(3),
                        bloqueado_por = ?
                    WHERE id IN ({$placeholders})");
                $update->execute(array_merge([$workerId], $ids));
            }

            if ($iniciou) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($iniciou && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT
                ies.*,
                ci.codigo AS integracao_codigo,
                ci.nome AS integracao_nome,
                ci.tipo_transporte,
                ci.adapter,
                ci.segredo_env_prefixo,
                ci.configuracao_json,
                ci.timeout_segundos,
                ci.retry_inicial_segundos,
                ci.retry_max_segundos,
                c.codigo AS credor_codigo,
                c.nome AS credor_nome
            FROM integracao_eventos_saida ies
            INNER JOIN credor_integracoes ci ON ci.id = ies.integracao_id
            INNER JOIN credores c ON c.id = ies.credor_id
            WHERE ies.id IN ({$placeholders})
            ORDER BY ies.prioridade ASC, ies.id ASC");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['payload_array'] = $this->decodeJson($row['payload'] ?? null);
            $row['configuracao_array'] = $this->decodeJson($row['configuracao_json'] ?? null);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $requisicao */
    public function iniciarTentativa(int $eventoId, int $numeroTentativa, string $workerId, array $requisicao): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO integracao_eventos_tentativas (
                evento_saida_id, numero_tentativa, worker_id, status, requisicao_payload
            ) VALUES (
                :evento_id, :numero, :worker_id, \'iniciada\', :requisicao
            ) ON DUPLICATE KEY UPDATE
                worker_id = VALUES(worker_id),
                status = \'iniciada\',
                requisicao_payload = VALUES(requisicao_payload),
                resposta_payload = NULL,
                erro = NULL,
                iniciado_em = CURRENT_TIMESTAMP(3),
                finalizado_em = NULL,
                id = LAST_INSERT_ID(id)');
        $stmt->bindValue('evento_id', $eventoId, PDO::PARAM_INT);
        $stmt->bindValue('numero', max(1, $numeroTentativa), PDO::PARAM_INT);
        $stmt->bindValue('worker_id', mb_substr($workerId, 0, 120));
        $stmt->bindValue('requisicao', $this->json($requisicao));
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $resposta */
    public function marcarEnviado(
        int $eventoId,
        int $tentativaId,
        int $httpStatus,
        int $duracaoMs,
        array $resposta,
        ?string $protocolo
    ): void {
        $iniciou = !$this->pdo->inTransaction();
        try {
            if ($iniciou) {
                $this->pdo->beginTransaction();
            }

            $stmt = $this->pdo->prepare("UPDATE integracao_eventos_saida
                SET status = 'enviado',
                    ultimo_http_status = :http_status,
                    protocolo_externo = :protocolo,
                    resposta_payload = :resposta,
                    ultimo_erro = NULL,
                    enviado_em = CURRENT_TIMESTAMP(3),
                    bloqueado_em = NULL,
                    bloqueado_por = NULL
                WHERE id = :id AND status = 'processando'");
            $this->bindNullableInt($stmt, 'http_status', $httpStatus > 0 ? $httpStatus : null);
            $this->bindNullableString($stmt, 'protocolo', $protocolo === null ? null : mb_substr($protocolo, 0, 180));
            $stmt->bindValue('resposta', $this->json($resposta));
            $stmt->bindValue('id', $eventoId, PDO::PARAM_INT);
            $stmt->execute();

            $attempt = $this->pdo->prepare("UPDATE integracao_eventos_tentativas
                SET status = 'sucesso',
                    http_status = :http_status,
                    duracao_ms = :duracao_ms,
                    resposta_payload = :resposta,
                    erro = NULL,
                    finalizado_em = CURRENT_TIMESTAMP(3)
                WHERE id = :id");
            $this->bindNullableInt($attempt, 'http_status', $httpStatus > 0 ? $httpStatus : null);
            $attempt->bindValue('duracao_ms', max(0, $duracaoMs), PDO::PARAM_INT);
            $attempt->bindValue('resposta', $this->json($resposta));
            $attempt->bindValue('id', $tentativaId, PDO::PARAM_INT);
            $attempt->execute();

            if ($iniciou) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($iniciou && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $evento @param array<string,mixed>|null $resposta */
    public function marcarErro(
        array $evento,
        int $tentativaId,
        string $erro,
        bool $retryable,
        int $httpStatus,
        int $duracaoMs,
        ?array $resposta
    ): string {
        $tentativas = max(1, (int) ($evento['tentativas'] ?? 1));
        $maxTentativas = max(1, (int) ($evento['max_tentativas'] ?? 8));
        $definitivo = !$retryable || $tentativas >= $maxTentativas;
        $status = $definitivo ? 'erro_definitivo' : 'erro_retry';
        $retryInicial = max(5, (int) ($evento['retry_inicial_segundos'] ?? 60));
        $retryMaximo = max($retryInicial, (int) ($evento['retry_max_segundos'] ?? 3600));
        $expoente = min(20, max(0, $tentativas - 1));
        $segundos = min($retryMaximo, $retryInicial * (2 ** $expoente));
        $erro = mb_substr(trim($erro), 0, 12000);
        $disponivelSql = $definitivo
            ? 'disponivel_em'
            : 'DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ' . (int) $segundos . ' SECOND)';

        $iniciou = !$this->pdo->inTransaction();
        try {
            if ($iniciou) {
                $this->pdo->beginTransaction();
            }

            $stmt = $this->pdo->prepare("UPDATE integracao_eventos_saida
                SET status = :status,
                    disponivel_em = {$disponivelSql},
                    ultimo_http_status = :http_status,
                    resposta_payload = :resposta,
                    ultimo_erro = :erro,
                    bloqueado_em = NULL,
                    bloqueado_por = NULL
                WHERE id = :id");
            $stmt->bindValue('status', $status);
            $this->bindNullableInt($stmt, 'http_status', $httpStatus > 0 ? $httpStatus : null);
            $this->bindNullableString($stmt, 'resposta', $resposta === null ? null : $this->json($resposta));
            $stmt->bindValue('erro', $erro);
            $stmt->bindValue('id', (int) ($evento['id'] ?? 0), PDO::PARAM_INT);
            $stmt->execute();

            if ($tentativaId > 0) {
                $attempt = $this->pdo->prepare('UPDATE integracao_eventos_tentativas
                    SET status = :status,
                        http_status = :http_status,
                        duracao_ms = :duracao_ms,
                        resposta_payload = :resposta,
                        erro = :erro,
                        finalizado_em = CURRENT_TIMESTAMP(3)
                    WHERE id = :id');
                $attempt->bindValue('status', $status);
                $this->bindNullableInt($attempt, 'http_status', $httpStatus > 0 ? $httpStatus : null);
                $attempt->bindValue('duracao_ms', max(0, $duracaoMs), PDO::PARAM_INT);
                $this->bindNullableString($attempt, 'resposta', $resposta === null ? null : $this->json($resposta));
                $attempt->bindValue('erro', $erro);
                $attempt->bindValue('id', $tentativaId, PDO::PARAM_INT);
                $attempt->execute();
            }

            if ($iniciou) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($iniciou && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $status;
    }

    /** @return list<array<string,mixed>> */
    public function listarPorContrato(int $contratoId, int $limite = 80): array
    {
        if (!$this->moduloDisponivel() || $contratoId <= 0) {
            return [];
        }
        $limite = max(1, min(200, $limite));
        $stmt = $this->pdo->prepare("SELECT
                ies.id, ies.evento_uuid, ies.evento_tipo, ies.origem_id AS atendimento_id,
                ies.status, ies.tentativas, ies.max_tentativas, ies.ultimo_http_status,
                ies.protocolo_externo, ies.ultimo_erro, ies.enviado_em, ies.criado_em,
                ci.nome AS integracao_nome, ci.adapter,
                ar.nome AS resultado_nome,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ies.payload, '$.mapeamento.codigo_externo')), ''),
                    itm.codigo_externo
                ) AS codigo_externo,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ies.payload, '$.mapeamento.descricao_externa')), ''),
                    itm.descricao_externa
                ) AS descricao_externa
            FROM integracao_eventos_saida ies
            INNER JOIN credor_integracoes ci ON ci.id = ies.integracao_id
            LEFT JOIN atendimento_resultados ar ON ar.id = ies.resultado_id
            LEFT JOIN integracao_tabulacao_mapeamentos itm ON itm.id = ies.mapeamento_id
            WHERE ies.contrato_id = :contrato_id
            ORDER BY ies.id DESC
            LIMIT {$limite}");
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $eventoId): ?array
    {
        if (!$this->moduloDisponivel() || $eventoId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT
                ies.*,
                ci.nome AS integracao_nome,
                ci.codigo AS integracao_codigo,
                ci.adapter,
                ci.tipo_transporte,
                c.codigo AS credor_codigo,
                c.nome AS credor_nome,
                ar.nome AS resultado_nome,
                itm.codigo_externo,
                itm.descricao_externa,
                ct.codigo_contrato,
                p.nome AS pessoa_nome,
                p.documento,
                ah.operador_nome
            FROM integracao_eventos_saida ies
            INNER JOIN credor_integracoes ci ON ci.id = ies.integracao_id
            INNER JOIN credores c ON c.id = ies.credor_id
            LEFT JOIN atendimento_resultados ar ON ar.id = ies.resultado_id
            LEFT JOIN integracao_tabulacao_mapeamentos itm ON itm.id = ies.mapeamento_id
            LEFT JOIN contratos ct ON ct.id = ies.contrato_id
            LEFT JOIN pessoas p ON p.id = ies.pessoa_id
            LEFT JOIN atendimentos_historico ah
                ON ies.origem_tabela = \'atendimentos_historico\'
               AND ah.id = ies.origem_id
            WHERE ies.id = :id
            LIMIT 1');
        $stmt->bindValue('id', $eventoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return list<array<string,mixed>>
     */
    public function listarRecentes(int $limite = 80, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $limite = max(1, min(250, $limite));
        $scope = $this->escopoSql($escopo, 'integracao_saida_lista');
        $stmt = $this->pdo->prepare("SELECT
                ies.id, ies.evento_uuid, ies.evento_tipo, ies.status,
                ies.contrato_id, ies.tentativas, ies.max_tentativas, ies.disponivel_em,
                ies.ultimo_http_status, ies.protocolo_externo, ies.ultimo_erro,
                ies.enviado_em, ies.criado_em, ies.atualizado_em,
                ci.nome AS integracao_nome, ci.codigo AS integracao_codigo,
                ci.adapter, ci.tipo_transporte,
                c.id AS credor_id, c.codigo AS credor_codigo, c.nome AS credor_nome,
                ar.nome AS resultado_nome,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ies.payload, '$.mapeamento.codigo_externo')), ''),
                    itm.codigo_externo
                ) AS codigo_externo,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ies.payload, '$.mapeamento.descricao_externa')), ''),
                    itm.descricao_externa
                ) AS descricao_externa,
                ct.codigo_contrato,
                p.nome AS pessoa_nome, p.documento,
                COALESCE(ah.operador_nome, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ies.payload, '$.origem.operador_nome')), '')) AS operador_nome,
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ies.payload, '$.contato.descricao')), '') AS contato_descricao,
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ies.resposta_payload, '$.mensagem')), '') AS contato_resultado
            FROM integracao_eventos_saida ies
            INNER JOIN credor_integracoes ci ON ci.id = ies.integracao_id
            INNER JOIN credores c ON c.id = ies.credor_id
            LEFT JOIN atendimento_resultados ar ON ar.id = ies.resultado_id
            LEFT JOIN integracao_tabulacao_mapeamentos itm ON itm.id = ies.mapeamento_id
            LEFT JOIN contratos ct ON ct.id = ies.contrato_id
            LEFT JOIN pessoas p ON p.id = ies.pessoa_id
            LEFT JOIN atendimentos_historico ah
                ON ies.origem_tabela = 'atendimentos_historico'
               AND ah.id = ies.origem_id
            WHERE {$scope['sql']}
            ORDER BY ies.criado_em DESC, ies.id DESC
            LIMIT {$limite}");
        $this->bindEscopo($stmt, $scope['params']);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return array<string,mixed>
     */
    public function reprocessar(int $eventoId, ?array $escopo = null): array
    {
        $atual = $this->buscar($eventoId);
        if ($atual === null) {
            throw new \InvalidArgumentException('Evento de integração não encontrado.');
        }
        if (!$this->eventoNoEscopo($eventoId, $escopo)) {
            throw new \InvalidArgumentException('Evento fora do escopo de credores do seu usuário.');
        }
        if (!in_array((string) ($atual['status'] ?? ''), ['erro_retry', 'erro_definitivo'], true)) {
            throw new \InvalidArgumentException('Somente eventos com erro podem ser reenviados manualmente.');
        }

        $tentativasAtuais = max(0, (int) ($atual['tentativas'] ?? 0));
        $maximoAtual = max(1, (int) ($atual['max_tentativas'] ?? 1));
        $novoMaximo = min(100, max($maximoAtual, $tentativasAtuais + 3));

        $stmt = $this->pdo->prepare("UPDATE integracao_eventos_saida
            SET status = 'pendente',
                max_tentativas = :max_tentativas,
                disponivel_em = CURRENT_TIMESTAMP(3),
                bloqueado_em = NULL,
                bloqueado_por = NULL,
                ultimo_http_status = NULL,
                ultimo_erro = NULL,
                resposta_payload = NULL,
                protocolo_externo = NULL,
                enviado_em = NULL,
                confirmado_em = NULL
            WHERE id = :id
              AND status IN ('erro_retry','erro_definitivo')");
        $stmt->bindValue('max_tentativas', $novoMaximo, PDO::PARAM_INT);
        $stmt->bindValue('id', $eventoId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('O evento mudou de estado antes do reprocessamento. Atualize a tela.');
        }

        $atual['novo_max_tentativas'] = $novoMaximo;
        return $atual;
    }

    /**
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return array{pendentes:int,processando:int,retry:int,definitivos:int,enviados:int}
     */
    public function resumoFila(?array $escopo = null): array
    {
        $fallback = ['pendentes' => 0, 'processando' => 0, 'retry' => 0, 'definitivos' => 0, 'enviados' => 0];
        if (!$this->moduloDisponivel()) {
            return $fallback;
        }
        $scope = $this->escopoSql($escopo, 'integracao_saida_resumo');
        $stmt = $this->pdo->prepare("SELECT
            SUM(ies.status = 'pendente') AS pendentes,
            SUM(ies.status = 'processando') AS processando,
            SUM(ies.status = 'erro_retry') AS retry,
            SUM(ies.status = 'erro_definitivo') AS definitivos,
            SUM(ies.status IN ('enviado','confirmado')) AS enviados
        FROM integracao_eventos_saida ies
        LEFT JOIN contratos ct ON ct.id = ies.contrato_id
        WHERE {$scope['sql']}");
        $this->bindEscopo($stmt, $scope['params']);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'pendentes' => (int) ($row['pendentes'] ?? 0),
            'processando' => (int) ($row['processando'] ?? 0),
            'retry' => (int) ($row['retry'] ?? 0),
            'definitivos' => (int) ($row['definitivos'] ?? 0),
            'enviados' => (int) ($row['enviados'] ?? 0),
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        $number = (int) ($value ?? 0);
        return $number > 0 ? $number : null;
    }

    /**
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo
     * @return array{sql:string,params:array<string,int>}
     */
    private function escopoSql(?array $escopo, string $prefixo): array
    {
        if ($escopo === null || (bool) ($escopo['todos'] ?? false)) {
            return ['sql' => '1=1', 'params' => []];
        }

        $credorIds = $this->normalizarIds($escopo['credor_ids'] ?? []);
        $carteiraIds = $this->normalizarIds($escopo['carteira_ids'] ?? []);
        if ($credorIds === [] && $carteiraIds === []) {
            return ['sql' => '0=1', 'params' => []];
        }

        $partes = [];
        $params = [];
        if ($credorIds !== []) {
            $placeholders = [];
            foreach ($credorIds as $indice => $credorId) {
                $chave = $prefixo . '_credor_' . $indice;
                $placeholders[] = ':' . $chave;
                $params[$chave] = $credorId;
            }
            $partes[] = 'COALESCE(ct.credor_id, ies.credor_id) IN (' . implode(',', $placeholders) . ')';
        }

        if ($carteiraIds !== []) {
            $placeholders = [];
            foreach ($carteiraIds as $indice => $carteiraId) {
                $chave = $prefixo . '_carteira_' . $indice;
                $placeholders[] = ':' . $chave;
                $params[$chave] = $carteiraId;
            }
            $partes[] = 'ct.carteira_id IN (' . implode(',', $placeholders) . ')';
        }

        return ['sql' => '(' . implode(' OR ', $partes) . ')', 'params' => $params];
    }

    /** @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopo */
    private function eventoNoEscopo(int $eventoId, ?array $escopo): bool
    {
        if ($escopo === null || (bool) ($escopo['todos'] ?? false)) {
            return true;
        }

        $scope = $this->escopoSql($escopo, 'integracao_saida_evento');
        $stmt = $this->pdo->prepare("SELECT 1
            FROM integracao_eventos_saida ies
            LEFT JOIN contratos ct ON ct.id = ies.contrato_id
            WHERE ies.id = :evento_id
              AND {$scope['sql']}
            LIMIT 1");
        $stmt->bindValue('evento_id', $eventoId, PDO::PARAM_INT);
        $this->bindEscopo($stmt, $scope['params']);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /** @param list<int|string> $ids @return list<int> */
    private function normalizarIds(array $ids): array
    {
        $normalizados = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalizados[$id] = $id;
            }
        }
        return array_values($normalizados);
    }

    /** @param array<string,int> $params */
    private function bindEscopo(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $chave => $valor) {
            $stmt->bindValue($chave, $valor, PDO::PARAM_INT);
        }
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) ($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function bindNullableInt(PDOStatement $stmt, string $key, ?int $value): void
    {
        $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    private function bindNullableString(PDOStatement $stmt, string $key, ?string $value): void
    {
        $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }
}
