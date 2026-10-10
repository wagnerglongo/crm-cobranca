<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CarteiraAcordosRepository
{
    public const SITUACOES = [
        'aguardando_primeira' => 'Aguardando primeira parcela',
        'em_dia' => 'Em dia',
        'vence_hoje' => 'Vence hoje',
        'em_carencia' => 'Em carência',
        'aguardando_proxima' => 'Aguardando próxima parcela',
        'quitado' => 'Quitado',
        'quebrado' => 'Quebrado',
        'cancelado' => 'Cancelado',
        'suspenso' => 'Suspenso',
    ];

    private ?bool $moduloDisponivel = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?AcordoOperacionalRepository $acordos = null
    ) {
    }

    private function acordos(): AcordoOperacionalRepository
    {
        return $this->acordos ?? new AcordoOperacionalRepository($this->pdo);
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $acompanhamento = $this->pdo->query("SHOW TABLES LIKE 'acordos_operacionais_acompanhamento'");
            $movimentos = $this->pdo->query("SHOW TABLES LIKE 'acordos_operacionais_movimentos'");
            $this->moduloDisponivel = $acompanhamento !== false
                && $acompanhamento->fetchColumn() !== false
                && $movimentos !== false
                && $movimentos->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @return array<string,mixed>|null */
    public function acompanhamento(int $acordoId): ?array
    {
        if ($acordoId <= 0 || !$this->moduloDisponivel()) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM acordos_operacionais_acompanhamento WHERE acordo_operacional_id = :id LIMIT 1');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param list<int|string> $acordoIds @return array<int,array<string,mixed>> */
    public function acompanhamentosPorAcordos(array $acordoIds): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $acordoIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM acordos_operacionais_acompanhamento WHERE acordo_operacional_id IN ({$placeholders})");
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $resultado = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $resultado[(int) $row['acordo_operacional_id']] = $row;
        }
        return $resultado;
    }

    /**
     * Atualiza a carteira local usando somente os dados persistidos no CRM.
     * A integração de confirmação de pagamento do credor será plugada depois.
     *
     * @return array{processados:int,quitados:int,quebrados:int,vencidas:int,erros:int}
     */
    public function atualizarCarteiraGeral(int $limite = 5000, ?int $acordoId = null): array
    {
        if (!$this->moduloDisponivel()) {
            throw new RuntimeException('Carteira de acordos indisponível. Execute a migration 075_carteira_acordos.sql.');
        }

        $limite = max(1, min($limite, 20000));
        if ($acordoId !== null && $acordoId > 0) {
            $where = 'WHERE ao.id = :acordo_id';
        } else {
            // A rotina diária prioriza itens ainda não inicializados e acordos ativos
            // cuja próxima verificação venceu. Acordos encerrados já acompanhados não
            // ocupam o lote indefinidamente.
            $where = "WHERE aca.acordo_operacional_id IS NULL
                OR (ao.status_operacional IN ('pendente','formalizado')
                    AND (aca.proxima_verificacao_em IS NULL OR aca.proxima_verificacao_em <= NOW()))";
        }

        $stmt = $this->pdo->prepare("SELECT ao.id
            FROM acordos_operacionais ao
            LEFT JOIN acordos_operacionais_acompanhamento aca
              ON aca.acordo_operacional_id = ao.id
            {$where}
            ORDER BY CASE WHEN aca.acordo_operacional_id IS NULL THEN 0 ELSE 1 END,
                     CASE WHEN ao.status_operacional IN ('pendente','formalizado') THEN 0 ELSE 1 END,
                     COALESCE(aca.proxima_verificacao_em, '1970-01-01 00:00:00') ASC,
                     ao.id ASC
            LIMIT {$limite}");
        if ($acordoId !== null && $acordoId > 0) {
            $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return $this->atualizarPorIds($ids);
    }

    /** @param list<int|string> $acordoIds @return array{processados:int,quitados:int,quebrados:int,vencidas:int,erros:int} */
    public function atualizarPorIds(array $acordoIds): array
    {
        $resultado = ['processados' => 0, 'quitados' => 0, 'quebrados' => 0, 'vencidas' => 0, 'erros' => 0];
        if (!$this->moduloDisponivel()) {
            return $resultado;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $acordoIds), static fn (int $id): bool => $id > 0)));
        $contratosRecalculados = [];
        foreach ($ids as $acordoId) {
            try {
                $antes = $this->buscarEstadoAcordo($acordoId);
                if ($antes === null) {
                    continue;
                }

                $vencidas = $this->marcarParcelasVencidas($acordoId);
                $resultado['vencidas'] += $vencidas;

                // A quebra usa a regra já configurada por credor/produto.
                $contratoId = $antes['contrato_id'] !== null ? (int) $antes['contrato_id'] : 0;
                if ($contratoId > 0 && !isset($contratosRecalculados[$contratoId])) {
                    $resultado['quebrados'] += $this->acordos()->recalcularQuebrasPorCarencia(null, $contratoId);
                    $contratosRecalculados[$contratoId] = true;
                }

                $estado = $this->buscarEstadoAcordo($acordoId);
                if ($estado === null) {
                    continue;
                }

                $totalEsperado = max((int) ($estado['total_parcelas'] ?? 0), (int) ($estado['quantidade_parcelas'] ?? 0));
                if ($totalEsperado > 0
                    && (int) ($estado['total_parcelas'] ?? 0) >= $totalEsperado
                    && (int) ($estado['parcelas_pagas'] ?? 0) >= $totalEsperado
                    && !in_array((string) $estado['status_operacional'], ['cumprido', 'cancelado'], true)) {
                    $this->acordos()->atualizarStatusOperacional($acordoId, 'cumprido', 'Quitado automaticamente: todas as parcelas estão pagas.');
                    $resultado['quitados']++;
                    $estado = $this->buscarEstadoAcordo($acordoId) ?? $estado;
                }

                $this->salvarAcompanhamento($estado);
                $resultado['processados']++;
            } catch (Throwable $e) {
                $resultado['erros']++;
                $this->registrarMovimento(
                    $acordoId,
                    null,
                    'rotina_erro',
                    'sistema',
                    null,
                    null,
                    null,
                    ['erro' => $e->getMessage()],
                    false,
                    'Falha ao atualizar carteira de acordos.'
                );
            }
        }

        return $resultado;
    }

    /** @return array<string,mixed>|null */
    private function buscarEstadoAcordo(int $acordoId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT
                ao.id,
                ao.pessoa_id,
                ao.contrato_id,
                ao.credor_id,
                ao.status_operacional,
                ao.quantidade_parcelas,
                ao.valor_acordo,
                ao.origem,
                ao.payload_extra,
                c.produto,
                COUNT(p.id) AS total_parcelas,
                SUM(CASE WHEN p.status = 'paga' THEN 1 ELSE 0 END) AS parcelas_pagas,
                SUM(CASE WHEN p.status = 'cancelada' THEN 1 ELSE 0 END) AS parcelas_canceladas,
                SUM(CASE WHEN p.status = 'renegociada' THEN 1 ELSE 0 END) AS parcelas_renegociadas,
                SUM(CASE WHEN p.status IN ('pendente','vencida') THEN 1 ELSE 0 END) AS parcelas_abertas,
                COALESCE(SUM(p.valor_parcela), 0) AS valor_previsto,
                COALESCE(SUM(CASE WHEN p.status = 'paga' THEN COALESCE(p.valor_pago, p.valor_parcela) ELSE 0 END), 0) AS valor_pago,
                (SELECT p2.id
                   FROM acordos_operacionais_parcelas p2
                  WHERE p2.acordo_operacional_id = ao.id
                    AND p2.status IN ('pendente','vencida')
                  ORDER BY p2.data_vencimento ASC, p2.numero_parcela ASC, p2.id ASC
                  LIMIT 1) AS parcela_atual_id,
                MIN(CASE WHEN p.status IN ('pendente','vencida') THEN p.data_vencimento ELSE NULL END) AS proximo_vencimento,
                (SELECT p3.status
                   FROM acordos_operacionais_parcelas p3
                  WHERE p3.acordo_operacional_id = ao.id
                    AND p3.status IN ('pendente','vencida')
                  ORDER BY p3.data_vencimento ASC, p3.numero_parcela ASC, p3.id ASC
                  LIMIT 1) AS status_parcela_atual,
                (SELECT p4.payload_extra
                   FROM acordos_operacionais_parcelas p4
                  WHERE p4.acordo_operacional_id = ao.id
                    AND p4.status IN ('pendente','vencida')
                  ORDER BY p4.data_vencimento ASC, p4.numero_parcela ASC, p4.id ASC
                  LIMIT 1) AS payload_parcela_atual
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN acordos_operacionais_parcelas p ON p.acordo_operacional_id = ao.id
            WHERE ao.id = :id
            GROUP BY ao.id, ao.pessoa_id, ao.contrato_id, ao.credor_id, ao.status_operacional,
                     ao.quantidade_parcelas, ao.valor_acordo, ao.origem, ao.payload_extra, c.produto
            LIMIT 1");
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        foreach (['pessoa_id', 'contrato_id', 'credor_id', 'total_parcelas', 'parcelas_pagas', 'parcelas_canceladas', 'parcelas_renegociadas', 'parcelas_abertas', 'parcela_atual_id'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['quantidade_parcelas'] = (int) ($row['quantidade_parcelas'] ?? 0);
        $row['valor_previsto'] = (float) ($row['valor_previsto'] ?? 0);
        $row['valor_pago'] = (float) ($row['valor_pago'] ?? 0);

        return $row;
    }

    private function marcarParcelasVencidas(int $acordoId): int
    {
        $stmt = $this->pdo->prepare("UPDATE acordos_operacionais_parcelas
            SET status = 'vencida',
                observacao = NULLIF(CONCAT_WS('\n', NULLIF(observacao, ''), 'Status atualizado automaticamente pela carteira: vencimento ultrapassado.'), '')
            WHERE acordo_operacional_id = :acordo_id
              AND status = 'pendente'
              AND data_vencimento < CURRENT_DATE");
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $estado */
    private function salvarAcompanhamento(array $estado): void
    {
        $status = (string) ($estado['status_operacional'] ?? 'pendente');
        $situacao = 'suspenso';
        $mensagem = 'Acordo sem próxima ação automática.';
        $fimCarencia = null;
        $proximoVencimento = $estado['proximo_vencimento'] ?: null;
        $totalParcelas = max((int) ($estado['total_parcelas'] ?? 0), (int) ($estado['quantidade_parcelas'] ?? 0));
        $pagas = (int) ($estado['parcelas_pagas'] ?? 0);

        if ($status === 'cumprido') {
            $situacao = 'quitado';
            $mensagem = 'Todas as parcelas foram pagas.';
        } elseif ($status === 'quebrado') {
            $situacao = 'quebrado';
            $mensagem = 'Acordo quebrado; contrato liberado para nova negociação.';
        } elseif ($status === 'cancelado') {
            $situacao = 'cancelado';
            $mensagem = 'Acordo cancelado.';
        } elseif ($proximoVencimento !== null) {
            $situacaoCarencia = $this->acordos()->situacaoCarenciaParcela(
                [
                    'data_vencimento' => $proximoVencimento,
                    'status' => (string) ($estado['status_parcela_atual'] ?? 'pendente'),
                    'payload_extra' => $estado['payload_parcela_atual'] ?? null,
                ],
                (int) ($estado['credor_id'] ?? 0),
                (string) ($estado['produto'] ?? '')
            );
            $fimCarencia = $situacaoCarencia['data_limite'] ?? null;
            $situacao = match ((string) ($situacaoCarencia['situacao'] ?? 'futuro')) {
                'vence_hoje' => 'vence_hoje',
                'em_carencia' => 'em_carencia',
                'carencia_expirada' => 'quebrado',
                default => $pagas === 0 ? 'aguardando_primeira' : 'em_dia',
            };
            $mensagem = match ($situacao) {
                'vence_hoje' => 'Parcela vence hoje; priorizar contato preventivo.',
                'em_carencia' => 'Parcela vencida ainda dentro da carência.',
                'quebrado' => 'Carência encerrada; acordo deve ficar quebrado.',
                'aguardando_primeira' => 'Aguardando pagamento da primeira parcela.',
                default => 'Acordo em dia; acompanhar o próximo vencimento.',
            };
        } elseif ($status === 'pendente' || $status === 'formalizado') {
            $situacao = 'aguardando_proxima';
            $mensagem = $pagas > 0 && $pagas < $totalParcelas
                ? 'Aguardando o credor liberar/confirmar a próxima parcela.'
                : 'Aguardando confirmação das parcelas do acordo.';
        }

        $proximaVerificacao = in_array($situacao, ['quitado', 'quebrado', 'cancelado'], true)
            ? null
            : (new DateTimeImmutable('tomorrow 03:00:00'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("INSERT INTO acordos_operacionais_acompanhamento (
                acordo_operacional_id,
                situacao_carteira,
                parcela_atual_id,
                proximo_vencimento,
                fim_carencia,
                total_parcelas,
                parcelas_pagas,
                valor_previsto,
                valor_pago,
                proxima_acao,
                ultima_verificacao_em,
                proxima_verificacao_em,
                ultima_origem
            ) VALUES (
                :acordo_id,
                :situacao,
                :parcela_atual_id,
                :proximo_vencimento,
                :fim_carencia,
                :total_parcelas,
                :parcelas_pagas,
                :valor_previsto,
                :valor_pago,
                :proxima_acao,
                NOW(),
                :proxima_verificacao_em,
                'sistema'
            ) ON DUPLICATE KEY UPDATE
                situacao_carteira = VALUES(situacao_carteira),
                parcela_atual_id = VALUES(parcela_atual_id),
                proximo_vencimento = VALUES(proximo_vencimento),
                fim_carencia = VALUES(fim_carencia),
                total_parcelas = VALUES(total_parcelas),
                parcelas_pagas = VALUES(parcelas_pagas),
                valor_previsto = VALUES(valor_previsto),
                valor_pago = VALUES(valor_pago),
                proxima_acao = VALUES(proxima_acao),
                ultima_verificacao_em = NOW(),
                proxima_verificacao_em = VALUES(proxima_verificacao_em),
                ultima_origem = VALUES(ultima_origem),
                atualizado_em = NOW()");
        $stmt->bindValue('acordo_id', (int) $estado['id'], PDO::PARAM_INT);
        $stmt->bindValue('situacao', $situacao);
        $this->bindNullableInt($stmt, 'parcela_atual_id', $estado['parcela_atual_id'] !== null ? (int) $estado['parcela_atual_id'] : null);
        $this->bindNullableString($stmt, 'proximo_vencimento', $proximoVencimento);
        $this->bindNullableString($stmt, 'fim_carencia', $fimCarencia);
        $stmt->bindValue('total_parcelas', $totalParcelas, PDO::PARAM_INT);
        $stmt->bindValue('parcelas_pagas', $pagas, PDO::PARAM_INT);
        $stmt->bindValue('valor_previsto', number_format((float) $estado['valor_previsto'], 2, '.', ''));
        $stmt->bindValue('valor_pago', number_format((float) $estado['valor_pago'], 2, '.', ''));
        $stmt->bindValue('proxima_acao', mb_substr($mensagem, 0, 255));
        $this->bindNullableString($stmt, 'proxima_verificacao_em', $proximaVerificacao);
        $stmt->execute();
    }

    /**
     * Registra um acordo manual a partir de CPF/CNPJ + código do contrato.
     *
     * @param array<string,mixed> $dados
     */
    public function registrarManual(array $dados, ?int $usuarioId, ?string $usuarioNome): int
    {
        if (!$this->moduloDisponivel()) {
            throw new RuntimeException('Carteira de acordos indisponível. Execute a migration 075.');
        }

        $documento = preg_replace('/\D+/', '', (string) ($dados['documento'] ?? '')) ?: '';
        $codigoContrato = trim((string) ($dados['codigo_contrato'] ?? ''));
        if ($documento === '' || $codigoContrato === '') {
            throw new InvalidArgumentException('Informe CPF/CNPJ e contrato para o cadastro manual.');
        }

        $contrato = $this->resolverContratoManual($documento, $codigoContrato);
        if ($contrato === null) {
            throw new InvalidArgumentException('Contrato não encontrado para o CPF/CNPJ informado.');
        }

        $payload = is_array($dados['payload_extra_adicional'] ?? null) ? $dados['payload_extra_adicional'] : [];
        $payload = array_replace_recursive($payload, [
            'cadastro_manual' => true,
            'origem_tela' => 'operacao/acordos.php',
            'operador_fechamento' => [
                'usuario_id' => $usuarioId,
                'nome' => trim((string) $usuarioNome) !== '' ? trim((string) $usuarioNome) : 'Não definido',
                'registrado_em' => date('c'),
            ],
            'operador_responsavel' => [
                'usuario_id' => $usuarioId,
                'nome' => trim((string) $usuarioNome) !== '' ? trim((string) $usuarioNome) : 'Não definido',
                'atribuido_em' => date('c'),
                'origem' => 'cadastro_manual',
            ],
        ]);

        $dadosRegistro = array_replace($dados, [
            'contrato_id' => (int) $contrato['contrato_id'],
            'origem' => 'manual',
            'operador_nome' => trim((string) $usuarioNome) !== '' ? trim((string) $usuarioNome) : null,
            'status_operacional' => (string) ($dados['status_operacional'] ?? 'formalizado'),
            'status_integracao' => (string) ($dados['status_integracao'] ?? 'nao_enviado'),
            'payload_extra_adicional' => $payload,
        ]);

        $this->pdo->beginTransaction();
        try {
            $acordoId = $this->acordos()->registrar((int) $contrato['pessoa_id'], $dadosRegistro);
            $this->registrarMovimento(
                $acordoId,
                null,
                'acordo_cadastrado_manual',
                'manual',
                $usuarioId,
                $usuarioNome,
                null,
                ['acordo_id' => $acordoId, 'contrato_id' => (int) $contrato['contrato_id']],
                true,
                'Cadastro manual de acordo. Desfazer cancela o acordo sem apagar o histórico.'
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->atualizarCarteiraGeral(1, $acordoId);
        return $acordoId;
    }

    /** @return array{contrato_id:int,pessoa_id:int,credor_id:int}|null */
    public function resolverContratoManual(string $documento, string $codigoContrato): ?array
    {
        $stmt = $this->pdo->prepare("SELECT c.id AS contrato_id, c.pessoa_id, c.credor_id
            FROM contratos c
            INNER JOIN pessoas p ON p.id = c.pessoa_id
            WHERE c.codigo_contrato = :codigo_contrato
              AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.documento, ''), '.', ''), '-', ''), '/', ''), ' ', '') = :documento
            ORDER BY c.id DESC
            LIMIT 1");
        $stmt->bindValue('codigo_contrato', $codigoContrato);
        $stmt->bindValue('documento', $documento);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'contrato_id' => (int) $row['contrato_id'],
            'pessoa_id' => (int) $row['pessoa_id'],
            'credor_id' => (int) $row['credor_id'],
        ];
    }

    public function corrigirParcelaManual(
        int $acordoId,
        int $parcelaId,
        string $status,
        mixed $dataPagamento,
        mixed $valorPago,
        ?string $observacao,
        ?int $usuarioId,
        ?string $usuarioNome
    ): int {
        $antes = $this->buscarParcela($acordoId, $parcelaId);
        if ($antes === null) {
            throw new InvalidArgumentException('Parcela não encontrada para correção.');
        }

        $this->acordos()->atualizarStatusParcela($acordoId, $parcelaId, $status, $dataPagamento, $valorPago, $observacao);
        $depois = $this->buscarParcela($acordoId, $parcelaId);
        $movimentoId = $this->registrarMovimento(
            $acordoId,
            $parcelaId,
            'parcela_corrigida_manual',
            'manual',
            $usuarioId,
            $usuarioNome,
            $antes,
            $depois,
            true,
            $observacao
        );
        $this->atualizarCarteiraGeral(1, $acordoId);
        return $movimentoId;
    }

    public function alterarStatusManual(int $acordoId, string $status, ?string $observacao, ?int $usuarioId, ?string $usuarioNome): int
    {
        $antes = $this->buscarAcordoBasico($acordoId);
        if ($antes === null) {
            throw new InvalidArgumentException('Acordo não encontrado.');
        }
        $this->acordos()->atualizarStatusOperacional($acordoId, $status, $observacao);
        $depois = $this->buscarAcordoBasico($acordoId);
        $movimentoId = $this->registrarMovimento(
            $acordoId,
            null,
            'acordo_status_manual',
            'manual',
            $usuarioId,
            $usuarioNome,
            $antes,
            $depois,
            true,
            $observacao
        );
        $this->atualizarCarteiraGeral(1, $acordoId);
        return $movimentoId;
    }

    public function desfazerCadastroManual(int $acordoId, ?string $observacao, ?int $usuarioId, ?string $usuarioNome): int
    {
        $acordo = $this->buscarAcordoBasico($acordoId);
        if ($acordo === null) {
            throw new InvalidArgumentException('Acordo não encontrado.');
        }
        if (!$this->acordoEhManual($acordoId)) {
            throw new InvalidArgumentException('Somente cadastro manual pode ser desfeito por esta ação.');
        }

        $antes = [
            'acordo' => $acordo,
            'parcelas' => $this->parcelasBasicas($acordoId),
        ];

        $this->pdo->beginTransaction();
        try {
            $cancelarParcelas = $this->pdo->prepare("UPDATE acordos_operacionais_parcelas
                SET status = 'cancelada',
                    observacao = NULLIF(CONCAT_WS('\n', NULLIF(observacao, ''), :observacao), '')
                WHERE acordo_operacional_id = :acordo_id
                  AND status <> 'paga'");
            $cancelarParcelas->bindValue('observacao', 'Cadastro manual desfeito: ' . trim((string) $observacao));
            $cancelarParcelas->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $cancelarParcelas->execute();
            $this->acordos()->atualizarStatusOperacional($acordoId, 'cancelado', 'Cadastro manual desfeito. ' . trim((string) $observacao));
            $depois = [
                'acordo' => $this->buscarAcordoBasico($acordoId),
                'parcelas' => $this->parcelasBasicas($acordoId),
            ];
            $movimentoId = $this->registrarMovimento(
                $acordoId,
                null,
                'cadastro_manual_desfeito',
                'manual',
                $usuarioId,
                $usuarioNome,
                $antes,
                $depois,
                true,
                $observacao
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->atualizarCarteiraGeral(1, $acordoId);
        return $movimentoId;
    }

    public function desfazerMovimento(int $movimentoId, ?int $usuarioId, ?string $usuarioNome, ?int $acordoEsperadoId = null): void
    {
        if ($movimentoId <= 0 || !$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Movimento inválido para desfazer.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM acordos_operacionais_movimentos WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $movimentoId, PDO::PARAM_INT);
        $stmt->execute();
        $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movimento) {
            throw new InvalidArgumentException('Movimento não encontrado.');
        }
        if ((int) ($movimento['reversivel'] ?? 0) !== 1 || !empty($movimento['desfeito_em'])) {
            throw new InvalidArgumentException('Este movimento não está disponível para desfazer.');
        }

        $acordoId = (int) $movimento['acordo_operacional_id'];
        if ($acordoEsperadoId !== null && $acordoEsperadoId > 0 && $acordoId !== $acordoEsperadoId) {
            throw new InvalidArgumentException('O movimento informado não pertence a este acordo.');
        }
        $parcelaId = !empty($movimento['parcela_id']) ? (int) $movimento['parcela_id'] : null;
        $antes = json_decode((string) ($movimento['dados_antes'] ?? ''), true);
        $acao = (string) ($movimento['acao'] ?? '');

        if ($acao === 'acordo_cadastrado_manual') {
            $this->acordos()->atualizarStatusOperacional($acordoId, 'cancelado', 'Cadastro manual desfeito pelo histórico de movimentos.');
            $cancelar = $this->pdo->prepare("UPDATE acordos_operacionais_parcelas SET status = 'cancelada' WHERE acordo_operacional_id = :acordo_id AND status <> 'paga'");
            $cancelar->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $cancelar->execute();
        } elseif ($acao === 'parcela_corrigida_manual' && $parcelaId !== null && is_array($antes)) {
            $this->acordos()->atualizarStatusParcela(
                $acordoId,
                $parcelaId,
                (string) ($antes['status'] ?? 'pendente'),
                $antes['data_pagamento'] ?? null,
                $antes['valor_pago'] ?? null,
                'Correção manual desfeita.'
            );
        } elseif ($acao === 'acordo_status_manual' && is_array($antes)) {
            $this->acordos()->atualizarStatusOperacional($acordoId, (string) ($antes['status_operacional'] ?? 'pendente'), 'Alteração manual de status desfeita.');
        } elseif ($acao === 'cadastro_manual_desfeito' && is_array($antes)) {
            $acordoAntes = is_array($antes['acordo'] ?? null) ? $antes['acordo'] : [];
            $this->acordos()->atualizarStatusOperacional($acordoId, (string) ($acordoAntes['status_operacional'] ?? 'formalizado'), 'Desfazer cancelamento do cadastro manual.');
            foreach ((array) ($antes['parcelas'] ?? []) as $parcelaAntes) {
                if (!is_array($parcelaAntes) || empty($parcelaAntes['id'])) {
                    continue;
                }
                $this->acordos()->atualizarStatusParcela(
                    $acordoId,
                    (int) $parcelaAntes['id'],
                    (string) ($parcelaAntes['status'] ?? 'pendente'),
                    $parcelaAntes['data_pagamento'] ?? null,
                    $parcelaAntes['valor_pago'] ?? null,
                    'Cancelamento manual desfeito.'
                );
            }
        } else {
            throw new InvalidArgumentException('Tipo de movimento ainda não suporta desfazer automático.');
        }

        $update = $this->pdo->prepare('UPDATE acordos_operacionais_movimentos
            SET desfeito_em = NOW(),
                desfeito_por_usuario_id = :usuario_id,
                desfeito_por_nome = :usuario_nome
            WHERE id = :id
            LIMIT 1');
        $this->bindNullableInt($update, 'usuario_id', $usuarioId);
        $this->bindNullableString($update, 'usuario_nome', $usuarioNome);
        $update->bindValue('id', $movimentoId, PDO::PARAM_INT);
        $update->execute();

        $this->registrarMovimento(
            $acordoId,
            $parcelaId,
            'movimento_desfeito',
            'manual',
            $usuarioId,
            $usuarioNome,
            ['movimento_id' => $movimentoId, 'acao' => $acao],
            ['desfeito' => true],
            false,
            'Movimento manual desfeito.'
        );

        $this->atualizarCarteiraGeral(1, $acordoId);
    }

    /** @return list<array<string,mixed>> */
    public function listarMovimentos(int $acordoId, int $limite = 100): array
    {
        if ($acordoId <= 0 || !$this->moduloDisponivel()) {
            return [];
        }
        $limite = max(1, min($limite, 300));
        $stmt = $this->pdo->prepare("SELECT *
            FROM acordos_operacionais_movimentos
            WHERE acordo_operacional_id = :acordo_id
            ORDER BY id DESC
            LIMIT {$limite}");
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function acordoEhManual(int $acordoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT origem, payload_extra FROM acordos_operacionais WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $payload = json_decode((string) ($row['payload_extra'] ?? ''), true);
        return (string) ($row['origem'] ?? '') === 'manual' || (is_array($payload) && ($payload['cadastro_manual'] ?? false) === true);
    }

    /** @return array<string,mixed>|null */
    private function buscarParcela(int $acordoId, int $parcelaId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, acordo_operacional_id, status, data_pagamento, valor_pago, observacao
            FROM acordos_operacionais_parcelas
            WHERE id = :parcela_id AND acordo_operacional_id = :acordo_id
            LIMIT 1');
        $stmt->bindValue('parcela_id', $parcelaId, PDO::PARAM_INT);
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function buscarAcordoBasico(int $acordoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status_operacional, observacao, origem, payload_extra FROM acordos_operacionais WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function parcelasBasicas(int $acordoId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, data_pagamento, valor_pago, observacao FROM acordos_operacionais_parcelas WHERE acordo_operacional_id = :id ORDER BY id');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed>|null $antes
     * @param array<string,mixed>|null $depois
     */
    private function registrarMovimento(
        int $acordoId,
        ?int $parcelaId,
        string $acao,
        string $origem,
        ?int $usuarioId,
        ?string $usuarioNome,
        ?array $antes,
        ?array $depois,
        bool $reversivel,
        ?string $observacao
    ): int {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $stmt = $this->pdo->prepare('INSERT INTO acordos_operacionais_movimentos (
                acordo_operacional_id,
                parcela_id,
                acao,
                origem,
                usuario_id,
                usuario_nome,
                dados_antes,
                dados_depois,
                reversivel,
                observacao
            ) VALUES (
                :acordo_id,
                :parcela_id,
                :acao,
                :origem,
                :usuario_id,
                :usuario_nome,
                :dados_antes,
                :dados_depois,
                :reversivel,
                :observacao
            )');
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'parcela_id', $parcelaId);
        $stmt->bindValue('acao', mb_substr($acao, 0, 80));
        $stmt->bindValue('origem', mb_substr($origem, 0, 30));
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $this->bindNullableString($stmt, 'usuario_nome', $usuarioNome !== null ? mb_substr($usuarioNome, 0, 150) : null);
        $this->bindNullableString($stmt, 'dados_antes', $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
        $this->bindNullableString($stmt, 'dados_depois', $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
        $stmt->bindValue('reversivel', $reversivel ? 1 : 0, PDO::PARAM_INT);
        $this->bindNullableString($stmt, 'observacao', $observacao !== null ? mb_substr(trim($observacao), 0, 2000) : null);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    public static function situacaoLabel(?string $situacao): string
    {
        return self::SITUACOES[(string) $situacao] ?? 'Não calculada';
    }

    private function bindNullableInt(\PDOStatement $stmt, string $key, ?int $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
    }

    private function bindNullableString(\PDOStatement $stmt, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
}
