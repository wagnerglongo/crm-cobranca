<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class EstrategiaRepository
{
    public const STATUS_ESTRATEGIA = [
        'rascunho' => 'Rascunho',
        'ativa' => 'Ativa',
        'pausada' => 'Pausada',
        'encerrada' => 'Encerrada',
        'arquivada' => 'Arquivada',
    ];

    public const STATUS_ITEM = [
        'pendente' => 'Pendente',
        'em_atendimento' => 'Em atendimento',
        'trabalhado' => 'Trabalhado',
        'removido' => 'Removido',
    ];

    public const CAMPOS_ORDENACAO = [
        'aging' => 'Maior aging',
        'valor_faltante' => 'Maior valor faltante',
        'valor_pago' => 'Maior valor pago',
        'valor_original' => 'Maior valor original',
        'data_entrada' => 'Entrada mais antiga',
        'ultima_tentativa' => 'Tentativa mais antiga',
        'vencimento_original' => 'Vencimento mais antigo',
        'codigo_contrato' => 'Código do contrato',
        'aleatorio' => 'Ordem aleatória',
    ];

    private const LOCK_NAME = 'crm_estrategias_materializacao_v1';

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
            foreach (['estrategias', 'estrategia_contratos', 'estrategia_usuarios', 'estrategia_execucoes', 'estrategia_eventos'] as $tabela) {
                $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :tabela LIMIT 1');
                $stmt->bindValue('tabela', $tabela);
                $stmt->execute();
                if ($stmt->fetchColumn() === false) {
                    return $this->moduloDisponivel = false;
                }
            }
        } catch (Throwable) {
            return $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel = true;
    }

    /** @return list<array<string,mixed>> */
    public function listarCredores(?array $escopo = null): array
    {
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_credor');
        $stmt = $this->pdo->prepare('SELECT DISTINCT cr.id, cr.codigo, cr.nome
            FROM contratos co
            INNER JOIN credores cr ON cr.id = co.credor_id
            WHERE cr.ativo = 1 AND ' . $scope['sql'] . '
            ORDER BY cr.nome ASC');
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listarCarteiras(?array $escopo = null): array
    {
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_carteira');
        $stmt = $this->pdo->prepare('SELECT DISTINCT car.id, car.codigo, car.nome, car.credor_id, cr.nome AS credor_nome
            FROM contratos co
            INNER JOIN credores cr ON cr.id = co.credor_id
            INNER JOIN carteiras car ON car.id = co.carteira_id
            WHERE car.ativo = 1 AND cr.ativo = 1 AND ' . $scope['sql'] . '
            ORDER BY cr.nome ASC, car.nome ASC');
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<string> */
    public function listarProdutos(?array $escopo = null, int $limite = 400): array
    {
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_produto');
        $stmt = $this->pdo->prepare('SELECT DISTINCT TRIM(co.produto) AS produto
            FROM contratos co
            WHERE co.produto IS NOT NULL AND TRIM(co.produto) <> \'\' AND ' . $scope['sql'] . '
            ORDER BY produto ASC
            LIMIT :limite');
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('limite', max(1, min(1000, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return array_values(array_filter(array_map(static fn(array $row): string => trim((string) ($row['produto'] ?? '')), $stmt->fetchAll())));
    }

    /** @return list<string> */
    public function listarStatusContratos(?array $escopo = null, int $limite = 250): array
    {
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_status_contrato');
        $stmt = $this->pdo->prepare('SELECT DISTINCT TRIM(co.status_contrato) AS status_contrato
            FROM contratos co
            WHERE co.status_contrato IS NOT NULL AND TRIM(co.status_contrato) <> \'\' AND ' . $scope['sql'] . '
            ORDER BY status_contrato ASC
            LIMIT :limite');
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('limite', max(1, min(500, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return array_values(array_filter(array_map(static fn(array $row): string => trim((string) ($row['status_contrato'] ?? '')), $stmt->fetchAll())));
    }

    /** @return list<array<string,mixed>> */
    public function listarResultadosAtendimento(): array
    {
        $stmt = $this->pdo->query('SELECT id, codigo, nome, categoria FROM atendimento_resultados WHERE ativo = 1 ORDER BY ordem ASC, nome ASC');
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return list<array<string,mixed>> */
    public function listarUsuariosOperacionais(): array
    {
        $stmt = $this->pdo->query("SELECT u.id, u.nome, u.login, COALESCE(p.nome, 'Sem perfil') AS perfil_nome, COALESCE(p.codigo, '') AS perfil_codigo
            FROM usuarios u
            LEFT JOIN perfis_acesso p ON p.id = u.perfil_id
            WHERE u.status = 'ativo'
            ORDER BY p.nivel DESC, u.nome ASC");
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /**
     * @param array<string,mixed> $dados
     * @return array<string,mixed>
     */
    public function previsualizar(array $dados, ?array $escopo = null, int $limiteAmostra = 30): array
    {
        $this->garantirModulo();
        $estrategiaId = $this->normalizarIdOpcional($dados['estrategia_id'] ?? null) ?? 0;
        $filtros = $this->normalizarFiltros($dados, true);
        $ordenacao = $this->normalizarOrdenacao($dados);
        $this->validarFiltrosDentroDoEscopo($filtros, $escopo);

        if ($estrategiaId > 0 && $this->buscarEstrategia($estrategiaId, $escopo) === null) {
            throw new InvalidArgumentException('Estratégia não encontrada no seu escopo.');
        }

        return $this->previsualizarNormalizado(
            $filtros,
            $ordenacao,
            $escopo,
            max(1, min(100, $limiteAmostra)),
            $estrategiaId
        );
    }

    /**
     * Cria ou atualiza uma estratégia e materializa o conjunto operacional.
     *
     * @param array<string,mixed> $dados
     * @return array{estrategia_id:int,total_encontrado:int,total_conflitos:int,total_materializado:int,total_removido:int,atualizada:bool}
     */
    public function salvarEGerar(array $dados, ?array $escopo = null): array
    {
        $this->garantirModulo();

        $estrategiaId = $this->normalizarIdOpcional($dados['estrategia_id'] ?? null) ?? 0;
        $nome = $this->normalizarTexto($dados['nome'] ?? null, 160, true);
        $descricao = $this->normalizarTexto($dados['descricao'] ?? null, 700, false);
        $status = $this->normalizarStatusEstrategia((string) ($dados['status'] ?? 'ativa'), false);
        $prioridade = $this->normalizarInteiro($dados['prioridade'] ?? 100, 0, 9999, 100);
        $visibilidade = (string) ($dados['visibilidade'] ?? 'todos') === 'usuarios' ? 'usuarios' : 'todos';
        $limite = $this->normalizarInteiro($dados['limite_contratos'] ?? 5000, 1, 50000, 5000);
        $usuarioId = $this->normalizarIdOpcional($dados['usuario_atual_id'] ?? null);
        $usuariosSelecionados = $this->normalizarListaIds($dados['usuario_ids'] ?? []);
        $filtros = $this->normalizarFiltros($dados, true);
        $ordenacao = $this->normalizarOrdenacao($dados);
        $this->validarFiltrosDentroDoEscopo($filtros, $escopo);

        if ($estrategiaId > 0 && $this->buscarEstrategia($estrategiaId, $escopo) === null) {
            throw new InvalidArgumentException('Estratégia não encontrada no seu escopo.');
        }

        if ($visibilidade === 'usuarios' && $usuariosSelecionados === []) {
            throw new InvalidArgumentException('Selecione ao menos um operador quando a estratégia for restrita a usuários.');
        }

        $this->adquirirLock();
        try {
            $this->pdo->beginTransaction();
            $atualizada = $estrategiaId > 0;

            if ($atualizada) {
                $atual = $this->buscarEstrategiaParaAtualizacao($estrategiaId);
                if ($atual === null) {
                    throw new InvalidArgumentException('Estratégia não encontrada.');
                }
                if ((string) $atual['status'] === 'arquivada') {
                    throw new InvalidArgumentException('Estratégia arquivada não pode ser alterada. Duplique-a para criar uma nova versão.');
                }

                $stmt = $this->pdo->prepare('UPDATE estrategias SET
                        nome = :nome,
                        descricao = :descricao,
                        status = :status,
                        prioridade = :prioridade,
                        visibilidade = :visibilidade,
                        filtros_json = :filtros_json,
                        ordenacao_json = :ordenacao_json,
                        limite_contratos = :limite,
                        atualizado_por_usuario_id = :usuario_id,
                        ativada_em = CASE WHEN :status_ativacao = \'ativa\' THEN COALESCE(ativada_em, NOW()) ELSE ativada_em END,
                        pausada_em = CASE WHEN :status_pausa = \'pausada\' THEN NOW() ELSE pausada_em END,
                        encerrada_em = CASE WHEN :status_encerramento = \'encerrada\' THEN NOW() ELSE encerrada_em END
                    WHERE id = :id LIMIT 1');
                $this->bindEstrategiaDados($stmt, $nome, $descricao, $status, $prioridade, $visibilidade, $filtros, $ordenacao, $limite, $usuarioId);
                $stmt->bindValue('id', $estrategiaId, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO estrategias (
                        nome, descricao, status, prioridade, visibilidade, filtros_json, ordenacao_json,
                        limite_contratos, criado_por_usuario_id, atualizado_por_usuario_id,
                        ativada_em, pausada_em, encerrada_em
                    ) VALUES (
                        :nome, :descricao, :status, :prioridade, :visibilidade, :filtros_json, :ordenacao_json,
                        :limite, :usuario_id, :usuario_id_2,
                        CASE WHEN :status_ativacao = \'ativa\' THEN NOW() ELSE NULL END,
                        CASE WHEN :status_pausa = \'pausada\' THEN NOW() ELSE NULL END,
                        CASE WHEN :status_encerramento = \'encerrada\' THEN NOW() ELSE NULL END
                    )');
                $this->bindEstrategiaDados($stmt, $nome, $descricao, $status, $prioridade, $visibilidade, $filtros, $ordenacao, $limite, $usuarioId, true);
                $stmt->execute();
                $estrategiaId = (int) $this->pdo->lastInsertId();
            }

            $this->salvarUsuarios($estrategiaId, $visibilidade, $usuariosSelecionados);
            $execucaoId = $this->iniciarExecucao($estrategiaId, $atualizada ? 'regeneracao' : 'criacao', $usuarioId, [
                'filtros' => $filtros,
                'ordenacao' => $ordenacao,
                'limite' => $limite,
            ]);

            $totalRemovido = 0;
            if ($atualizada) {
                $remover = $this->pdo->prepare("UPDATE estrategia_contratos
                    SET status_operacional = 'removido',
                        reservado_por_usuario_id = NULL,
                        reserva_expira_em = NULL,
                        removido_em = NOW(),
                        motivo_remocao = 'Reavaliação por edição/regeneração da estratégia.'
                    WHERE estrategia_id = :estrategia_id
                      AND status_operacional IN ('pendente','em_atendimento')");
                $remover->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
                $remover->execute();
                $totalRemovido = $remover->rowCount();
            }

            $materializacao = $this->materializar($estrategiaId, $filtros, $ordenacao, $limite, $escopo);
            $this->recalcularContadores($estrategiaId);
            $this->finalizarExecucao($execucaoId, 'concluida', $materializacao['total_encontrado'], $materializacao['total_materializado'], $materializacao['total_conflitos'], $totalRemovido);
            $this->registrarEvento(
                $estrategiaId,
                null,
                $atualizada ? 'estrategia_atualizada' : 'estrategia_criada',
                $atualizada ? 'Estratégia atualizada e conjunto de contratos regenerado.' : 'Estratégia criada e conjunto de contratos gerado.',
                $usuarioId,
                [
                    'total_encontrado' => $materializacao['total_encontrado'],
                    'total_conflitos' => $materializacao['total_conflitos'],
                    'total_materializado' => $materializacao['total_materializado'],
                    'total_removido' => $totalRemovido,
                ]
            );

            $this->pdo->commit();

            return [
                'estrategia_id' => $estrategiaId,
                'total_encontrado' => $materializacao['total_encontrado'],
                'total_conflitos' => $materializacao['total_conflitos'],
                'total_materializado' => $materializacao['total_materializado'],
                'total_removido' => $totalRemovido,
                'atualizada' => $atualizada,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->liberarLock();
        }
    }

    /**
     * @return array{estrategia_id:int,total_encontrado:int,total_conflitos:int,total_materializado:int,total_removido:int}
     */
    public function regerar(int $estrategiaId, ?int $usuarioId, ?array $escopo = null): array
    {
        $this->garantirModulo();
        $estrategia = $this->buscarEstrategia($estrategiaId, $escopo);
        if ($estrategia === null) {
            throw new InvalidArgumentException('Estratégia não encontrada no seu escopo.');
        }
        if ((string) $estrategia['status'] === 'arquivada') {
            throw new InvalidArgumentException('Estratégia arquivada não pode ser regenerada.');
        }

        $filtros = $this->decodeJsonArray($estrategia['filtros_json'] ?? null);
        $ordenacao = $this->decodeJsonArray($estrategia['ordenacao_json'] ?? null);
        $limite = max(1, min(50000, (int) ($estrategia['limite_contratos'] ?? 5000)));

        $this->adquirirLock();
        try {
            $this->pdo->beginTransaction();
            $execucaoId = $this->iniciarExecucao($estrategiaId, 'regeneracao', $usuarioId, ['origem' => 'acao_manual']);

            $remover = $this->pdo->prepare("UPDATE estrategia_contratos
                SET status_operacional = 'removido', reservado_por_usuario_id = NULL, reserva_expira_em = NULL,
                    removido_em = NOW(), motivo_remocao = 'Reavaliação por regeneração manual.'
                WHERE estrategia_id = :estrategia_id AND status_operacional IN ('pendente','em_atendimento')");
            $remover->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
            $remover->execute();
            $totalRemovido = $remover->rowCount();

            $materializacao = $this->materializar($estrategiaId, $filtros, $ordenacao, $limite, $escopo);
            $this->recalcularContadores($estrategiaId);
            $this->finalizarExecucao($execucaoId, 'concluida', $materializacao['total_encontrado'], $materializacao['total_materializado'], $materializacao['total_conflitos'], $totalRemovido);
            $this->registrarEvento($estrategiaId, null, 'estrategia_regenerada', 'Conjunto de contratos regenerado com os filtros salvos.', $usuarioId, $materializacao + ['total_removido' => $totalRemovido]);
            $this->pdo->commit();

            return [
                'estrategia_id' => $estrategiaId,
                'total_encontrado' => $materializacao['total_encontrado'],
                'total_conflitos' => $materializacao['total_conflitos'],
                'total_materializado' => $materializacao['total_materializado'],
                'total_removido' => $totalRemovido,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->liberarLock();
        }
    }

    public function atualizarStatus(int $estrategiaId, string $status, ?int $usuarioId, ?array $escopo = null): void
    {
        $this->garantirModulo();
        $status = $this->normalizarStatusEstrategia($status, true);
        if ($status === 'rascunho') {
            throw new InvalidArgumentException('Use a edição da estratégia para mantê-la como rascunho.');
        }

        $estrategia = $this->buscarEstrategia($estrategiaId, $escopo);
        if ($estrategia === null) {
            throw new InvalidArgumentException('Estratégia não encontrada no seu escopo.');
        }
        $statusAnterior = (string) $estrategia['status'];
        if ($statusAnterior === 'arquivada' && $status !== 'arquivada') {
            throw new InvalidArgumentException('Estratégia arquivada não pode ser reativada. Duplique-a para preservar a auditoria.');
        }

        $ativando = $status === 'ativa' && $statusAnterior !== 'ativa';
        $lockAdquirido = false;
        if ($ativando) {
            $this->adquirirLock();
            $lockAdquirido = true;
        }

        try {
            $this->pdo->beginTransaction();
            $atual = $this->buscarEstrategiaParaAtualizacao($estrategiaId);
            if ($atual === null) {
                throw new InvalidArgumentException('Estratégia não encontrada.');
            }
            if ((string) $atual['status'] === 'arquivada' && $status !== 'arquivada') {
                throw new InvalidArgumentException('Estratégia arquivada não pode ser reativada. Duplique-a para preservar a auditoria.');
            }

            $stmt = $this->pdo->prepare('UPDATE estrategias SET
                    status = :status,
                    atualizado_por_usuario_id = :usuario_id,
                    ativada_em = CASE WHEN :status_ativa = \'ativa\' THEN COALESCE(ativada_em, NOW()) ELSE ativada_em END,
                    pausada_em = CASE WHEN :status_pausada = \'pausada\' THEN NOW() ELSE pausada_em END,
                    encerrada_em = CASE WHEN :status_encerrada = \'encerrada\' THEN NOW() ELSE encerrada_em END,
                    arquivada_em = CASE WHEN :status_arquivada = \'arquivada\' THEN NOW() ELSE arquivada_em END
                WHERE id = :id LIMIT 1');
            $stmt->bindValue('status', $status);
            $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
            $stmt->bindValue('status_ativa', $status);
            $stmt->bindValue('status_pausada', $status);
            $stmt->bindValue('status_encerrada', $status);
            $stmt->bindValue('status_arquivada', $status);
            $stmt->bindValue('id', $estrategiaId, PDO::PARAM_INT);
            $stmt->execute();

            $tipo = match ($status) {
                'ativa' => 'ativacao',
                'pausada' => 'pausa',
                'encerrada' => 'encerramento',
                'arquivada' => 'arquivamento',
            };
            $execucaoId = $this->iniciarExecucao($estrategiaId, $tipo, $usuarioId, [
                'status_anterior' => $statusAnterior,
                'status_novo' => $status,
            ]);
            $payloadEvento = [
                'status_anterior' => $statusAnterior,
                'status_novo' => $status,
            ];

            if ($ativando) {
                $filtros = $this->decodeJsonArray($atual['filtros_json'] ?? null);
                $ordenacao = $this->decodeJsonArray($atual['ordenacao_json'] ?? null);
                $limite = max(1, min(50000, (int) ($atual['limite_contratos'] ?? 5000)));
                $this->validarFiltrosDentroDoEscopo($filtros, $escopo);

                $remover = $this->pdo->prepare("UPDATE estrategia_contratos
                    SET status_operacional = 'removido',
                        reservado_por_usuario_id = NULL,
                        reserva_expira_em = NULL,
                        removido_em = NOW(),
                        motivo_remocao = 'Reavaliação obrigatória antes da ativação.'
                    WHERE estrategia_id = :estrategia_id
                      AND status_operacional IN ('pendente','em_atendimento')");
                $remover->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
                $remover->execute();
                $totalRemovido = $remover->rowCount();

                $materializacao = $this->materializar($estrategiaId, $filtros, $ordenacao, $limite, $escopo);
                $this->finalizarExecucao(
                    $execucaoId,
                    'concluida',
                    $materializacao['total_encontrado'],
                    $materializacao['total_materializado'],
                    $materializacao['total_conflitos'],
                    $totalRemovido
                );
                $payloadEvento += $materializacao + ['total_removido' => $totalRemovido];
            } else {
                if (in_array($status, ['pausada', 'encerrada', 'arquivada'], true)) {
                    // Em UPDATEs simples o MySQL avalia atribuicoes da esquerda para a direita.
                    // Preserve o status original ate limpar reserva e registrar a remocao.
                    $updateItens = $this->pdo->prepare("UPDATE estrategia_contratos SET
                            reservado_por_usuario_id = CASE
                                WHEN status_operacional IN ('pendente','em_atendimento') THEN NULL
                                ELSE reservado_por_usuario_id
                            END,
                            reserva_expira_em = CASE
                                WHEN status_operacional IN ('pendente','em_atendimento') THEN NULL
                                ELSE reserva_expira_em
                            END,
                            removido_em = CASE
                                WHEN :arquivar_2 = 1 AND status_operacional IN ('pendente','em_atendimento') THEN NOW()
                                ELSE removido_em
                            END,
                            motivo_remocao = CASE
                                WHEN :arquivar_3 = 1 AND status_operacional IN ('pendente','em_atendimento') THEN 'Estratégia arquivada.'
                                ELSE motivo_remocao
                            END,
                            status_operacional = CASE
                                WHEN :arquivar = 1 AND status_operacional IN ('pendente','em_atendimento') THEN 'removido'
                                WHEN status_operacional = 'em_atendimento' THEN 'pendente'
                                ELSE status_operacional
                            END
                        WHERE estrategia_id = :estrategia_id");
                    $arquivar = $status === 'arquivada' ? 1 : 0;
                    $updateItens->bindValue('arquivar', $arquivar, PDO::PARAM_INT);
                    $updateItens->bindValue('arquivar_2', $arquivar, PDO::PARAM_INT);
                    $updateItens->bindValue('arquivar_3', $arquivar, PDO::PARAM_INT);
                    $updateItens->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
                    $updateItens->execute();
                    $payloadEvento['itens_atualizados'] = $updateItens->rowCount();
                }
                $this->finalizarExecucao($execucaoId, 'concluida', 0, 0, 0, 0);
            }

            $this->registrarEvento(
                $estrategiaId,
                null,
                'status_alterado',
                'Status da estratégia alterado para ' . self::statusEstrategiaLabel($status) . '.',
                $usuarioId,
                $payloadEvento
            );
            $this->recalcularContadores($estrategiaId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($lockAdquirido) {
                $this->liberarLock();
            }
        }
    }

    /** @return array{nova_estrategia_id:int,total_materializado:int} */
    public function duplicar(int $estrategiaId, ?int $usuarioId, ?array $escopo = null): array
    {
        $origem = $this->buscarEstrategia($estrategiaId, $escopo);
        if ($origem === null) {
            throw new InvalidArgumentException('Estratégia de origem não encontrada no seu escopo.');
        }

        $filtros = $this->decodeJsonArray($origem['filtros_json'] ?? null);
        $ordenacao = $this->decodeJsonArray($origem['ordenacao_json'] ?? null);
        $dados = $this->formularioDaEstrategia($origem);
        $dados['estrategia_id'] = '';
        $dados['nome'] = mb_substr((string) $origem['nome'] . ' - Cópia', 0, 160);
        $dados['status'] = 'rascunho';
        $dados['usuario_atual_id'] = $usuarioId;
        $dados['confirmar_todos_escopo'] = '1';
        $dados['usuario_ids'] = $this->listarUsuariosIdsDaEstrategia($estrategiaId);
        $dados = array_merge($dados, $this->filtrosParaFormulario($filtros), $this->ordenacaoParaFormulario($ordenacao));

        $resultado = $this->salvarEGerar($dados, $escopo);
        $this->registrarEvento((int) $resultado['estrategia_id'], null, 'estrategia_duplicada', 'Estratégia duplicada a partir de #' . $estrategiaId . '.', $usuarioId, ['estrategia_origem_id' => $estrategiaId]);

        return ['nova_estrategia_id' => (int) $resultado['estrategia_id'], 'total_materializado' => (int) $resultado['total_materializado']];
    }

    /** @return array<string,int|float> */
    public function resumoSupervisao(?array $escopo = null): array
    {
        $fallback = [
            'estrategias_ativas' => 0,
            'contratos_pendentes' => 0,
            'em_atendimento' => 0,
            'trabalhados' => 0,
            'saldo_em_estrategias' => 0.0,
        ];
        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_resumo');
        $stmt = $this->pdo->prepare("SELECT
                COUNT(DISTINCT CASE WHEN e.status = 'ativa' THEN e.id END) AS estrategias_ativas,
                SUM(CASE WHEN e.status = 'ativa' AND (
                    ec.status_operacional = 'pendente'
                    OR (ec.status_operacional = 'em_atendimento' AND ec.reserva_expira_em IS NOT NULL AND ec.reserva_expira_em < NOW())
                ) THEN 1 ELSE 0 END) AS contratos_pendentes,
                SUM(CASE WHEN e.status = 'ativa'
                    AND ec.status_operacional = 'em_atendimento'
                    AND (ec.reserva_expira_em IS NULL OR ec.reserva_expira_em >= NOW())
                    THEN 1 ELSE 0 END) AS em_atendimento,
                SUM(CASE WHEN e.status = 'ativa' AND ec.status_operacional = 'trabalhado' THEN 1 ELSE 0 END) AS trabalhados,
                SUM(CASE WHEN e.status = 'ativa' AND ec.status_operacional <> 'removido' THEN COALESCE(ec.valor_faltante_snapshot, ec.saldo_snapshot, 0) ELSE 0 END) AS saldo_em_estrategias
            FROM estrategias e
            LEFT JOIN estrategia_contratos ec ON ec.estrategia_id = e.id
            LEFT JOIN contratos co ON co.id = ec.contrato_id
            WHERE e.status <> 'arquivada' AND (co.id IS NULL OR {$scope['sql']})");
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'estrategias_ativas' => (int) ($row['estrategias_ativas'] ?? 0),
            'contratos_pendentes' => (int) ($row['contratos_pendentes'] ?? 0),
            'em_atendimento' => (int) ($row['em_atendimento'] ?? 0),
            'trabalhados' => (int) ($row['trabalhados'] ?? 0),
            'saldo_em_estrategias' => (float) ($row['saldo_em_estrategias'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listarEstrategiasSupervisao(?array $escopo = null, bool $incluirArquivadas = false, int $limite = 150): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $scope = EscopoCredorSql::contrato($escopo, 'co_scope', 'estrategia_lista_sup');
        $whereStatus = $incluirArquivadas ? '1=1' : "e.status <> 'arquivada'";
        $scopeWhere = $scope['sql'] === '1=1'
            ? '1=1'
            : "(NOT EXISTS (SELECT 1 FROM estrategia_contratos ec_any WHERE ec_any.estrategia_id = e.id) OR EXISTS (
                SELECT 1 FROM estrategia_contratos ec_scope
                INNER JOIN contratos co_scope ON co_scope.id = ec_scope.contrato_id
                WHERE ec_scope.estrategia_id = e.id AND {$scope['sql']}
            ))";

        $stmt = $this->pdo->prepare("SELECT
                e.*,
                uc.nome AS criado_por_nome,
                ua.nome AS atualizado_por_nome,
                (SELECT GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ')
                 FROM estrategia_usuarios eu INNER JOIN usuarios u ON u.id = eu.usuario_id
                 WHERE eu.estrategia_id = e.id) AS usuarios_nomes,
                (SELECT COUNT(*) FROM estrategia_execucoes ex WHERE ex.estrategia_id = e.id) AS total_execucoes,
                (SELECT MAX(ex.finalizado_em) FROM estrategia_execucoes ex WHERE ex.estrategia_id = e.id AND ex.status = 'concluida') AS ultima_execucao_em,
                (SELECT COUNT(*) FROM estrategia_contratos ec_pending
                 WHERE ec_pending.estrategia_id = e.id AND (
                    ec_pending.status_operacional = 'pendente'
                    OR (ec_pending.status_operacional = 'em_atendimento' AND ec_pending.reserva_expira_em IS NOT NULL AND ec_pending.reserva_expira_em < NOW())
                 )) AS total_pendentes_atual,
                (SELECT COUNT(*) FROM estrategia_contratos ec_working
                 WHERE ec_working.estrategia_id = e.id
                   AND ec_working.status_operacional = 'em_atendimento'
                   AND (ec_working.reserva_expira_em IS NULL OR ec_working.reserva_expira_em >= NOW())) AS total_em_atendimento_atual
            FROM estrategias e
            LEFT JOIN usuarios uc ON uc.id = e.criado_por_usuario_id
            LEFT JOIN usuarios ua ON ua.id = e.atualizado_por_usuario_id
            WHERE {$whereStatus} AND {$scopeWhere}
            ORDER BY
                CASE e.status WHEN 'ativa' THEN 0 WHEN 'pausada' THEN 1 WHEN 'rascunho' THEN 2 WHEN 'encerrada' THEN 3 ELSE 4 END,
                e.prioridade DESC,
                e.atualizado_em DESC,
                e.id DESC
            LIMIT :limite");
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('limite', max(1, min(500, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarEstrategia(int $estrategiaId, ?array $escopo = null): ?array
    {
        if (!$this->moduloDisponivel() || $estrategiaId <= 0) {
            return null;
        }

        $scope = EscopoCredorSql::contrato($escopo, 'co_scope', 'estrategia_busca');
        $scopeWhere = $scope['sql'] === '1=1'
            ? '1=1'
            : "(NOT EXISTS (SELECT 1 FROM estrategia_contratos ec_any WHERE ec_any.estrategia_id = e.id) OR EXISTS (
                SELECT 1 FROM estrategia_contratos ec_scope
                INNER JOIN contratos co_scope ON co_scope.id = ec_scope.contrato_id
                WHERE ec_scope.estrategia_id = e.id AND {$scope['sql']}
            ))";
        $stmt = $this->pdo->prepare("SELECT e.*,
                uc.nome AS criado_por_nome,
                ua.nome AS atualizado_por_nome,
                (SELECT GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR ', ')
                 FROM estrategia_usuarios eu INNER JOIN usuarios u ON u.id = eu.usuario_id
                 WHERE eu.estrategia_id = e.id) AS usuarios_nomes
            FROM estrategias e
            LEFT JOIN usuarios uc ON uc.id = e.criado_por_usuario_id
            LEFT JOIN usuarios ua ON ua.id = e.atualizado_por_usuario_id
            WHERE e.id = :id AND {$scopeWhere}
            LIMIT 1");
        $stmt->bindValue('id', $estrategiaId, PDO::PARAM_INT);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listarExecucoes(int $estrategiaId, int $limite = 20): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT ex.*, u.nome AS executado_por_nome
            FROM estrategia_execucoes ex
            LEFT JOIN usuarios u ON u.id = ex.executado_por_usuario_id
            WHERE ex.estrategia_id = :estrategia_id
            ORDER BY ex.id DESC LIMIT :limite');
        $stmt->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
        $stmt->bindValue('limite', max(1, min(100, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listarEstrategiasOperacao(int $usuarioId, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_op_lista');
        [$visSql, $visParams] = $this->sqlVisibilidadeUsuario($usuarioId, 'e', 'vis_lista');
        $stmt = $this->pdo->prepare("SELECT
                e.id, e.nome, e.descricao, e.prioridade, e.total_contratos, e.total_trabalhados,
                COUNT(CASE
                    WHEN ec.status_operacional = 'pendente'
                      OR (ec.status_operacional = 'em_atendimento' AND ec.reserva_expira_em IS NOT NULL AND ec.reserva_expira_em < NOW())
                    THEN 1
                END) AS disponiveis,
                COUNT(CASE
                    WHEN ec.status_operacional = 'em_atendimento'
                     AND ec.reservado_por_usuario_id = :usuario_reserva
                     AND (ec.reserva_expira_em IS NULL OR ec.reserva_expira_em >= NOW())
                    THEN 1
                END) AS reservados_usuario,
                COUNT(CASE WHEN ec.status_operacional = 'trabalhado' THEN 1 END) AS trabalhados
            FROM estrategias e
            INNER JOIN estrategia_contratos ec ON ec.estrategia_id = e.id
            INNER JOIN contratos co ON co.id = ec.contrato_id
            WHERE e.status = 'ativa'
              AND {$visSql}
              AND {$scope['sql']}
              AND ec.status_operacional <> 'removido'
            GROUP BY e.id
            ORDER BY e.prioridade DESC, e.nome ASC");
        $stmt->bindValue('usuario_reserva', $usuarioId, PDO::PARAM_INT);
        $this->bindParams($stmt, $visParams + $scope['params']);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $filtros
     * @return array{itens:list<array<string,mixed>>,total:int,pagina:int,por_pagina:int,total_paginas:int,resumo:array<string,int|float>}
     */
    public function listarContratosOperacao(int $usuarioId, array $filtros, ?array $escopo = null, int $pagina = 1, int $porPagina = 40): array
    {
        if (!$this->moduloDisponivel()) {
            return ['itens' => [], 'total' => 0, 'pagina' => 1, 'por_pagina' => $porPagina, 'total_paginas' => 1, 'resumo' => ['disponiveis' => 0, 'reservados' => 0, 'trabalhados' => 0, 'saldo' => 0.0]];
        }

        $pagina = max(1, $pagina);
        $porPagina = max(10, min(100, $porPagina));
        $where = ["e.status = 'ativa'"];
        $params = [];
        [$visSql, $visParams] = $this->sqlVisibilidadeUsuario($usuarioId, 'e', 'vis_op');
        $where[] = $visSql;
        $params += $visParams;
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_op');
        $where[] = $scope['sql'];
        $params += $scope['params'];

        $estrategiaId = $this->normalizarIdOpcional($filtros['estrategia_id'] ?? null);
        if ($estrategiaId !== null) {
            $where[] = 'e.id = :f_estrategia_id';
            $params['f_estrategia_id'] = $estrategiaId;
        }

        $status = trim((string) ($filtros['status'] ?? 'disponiveis'));
        if ($status === 'trabalhado') {
            $where[] = "ec.status_operacional = 'trabalhado'";
        } elseif ($status === 'em_atendimento') {
            $where[] = "ec.status_operacional = 'em_atendimento' AND ec.reservado_por_usuario_id = :f_usuario_em_atendimento";
            $params['f_usuario_em_atendimento'] = $usuarioId;
        } elseif ($status === 'todos') {
            $where[] = "ec.status_operacional <> 'removido'";
        } else {
            $where[] = "ec.status_operacional IN ('pendente','em_atendimento')";
            $where[] = '(ec.reservado_por_usuario_id IS NULL OR ec.reservado_por_usuario_id = :f_usuario_disponivel OR (ec.reserva_expira_em IS NOT NULL AND ec.reserva_expira_em < NOW()))';
            $params['f_usuario_disponivel'] = $usuarioId;
        }

        $termo = trim((string) ($filtros['q'] ?? ''));
        if ($termo !== '') {
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';
            $partes = ['p.nome LIKE :f_q_nome', 'co.codigo_contrato LIKE :f_q_contrato'];
            $params['f_q_nome'] = '%' . $termo . '%';
            $params['f_q_contrato'] = '%' . $termo . '%';
            if (strlen($digitos) >= 3) {
                $partes[] = 'p.documento LIKE :f_q_documento';
                $params['f_q_documento'] = '%' . $digitos . '%';
            }
            $where[] = '(' . implode(' OR ', $partes) . ')';
        }

        $from = ' FROM estrategia_contratos ec
            INNER JOIN estrategias e ON e.id = ec.estrategia_id
            INNER JOIN contratos co ON co.id = ec.contrato_id
            INNER JOIN pessoas p ON p.id = ec.pessoa_id
            INNER JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            LEFT JOIN usuarios ur ON ur.id = ec.reservado_por_usuario_id
            LEFT JOIN atendimento_resultados ar ON ar.id = ec.ultimo_resultado_id_snapshot
            WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $this->bindParams($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $totalPaginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $totalPaginas);
        $offset = ($pagina - 1) * $porPagina;

        $stmt = $this->pdo->prepare('SELECT
                ec.*, e.nome AS estrategia_nome, e.prioridade AS estrategia_prioridade,
                p.nome AS pessoa_nome, p.documento AS pessoa_documento, p.tipo_documento,
                co.codigo_contrato, co.produto, co.subproduto, co.status_contrato,
                cr.nome AS credor_nome, car.nome AS carteira_nome,
                ur.nome AS reservado_por_nome,
                ar.nome AS ultimo_resultado_nome
            ' . $from . '
            ORDER BY e.prioridade DESC, ec.ordem_prioridade ASC, ec.id ASC
            LIMIT :limite OFFSET :offset');
        $this->bindParams($stmt, $params);
        $stmt->bindValue('limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $resumoStmt = $this->pdo->prepare("SELECT
                SUM(CASE
                    WHEN ec.status_operacional = 'pendente'
                      OR (ec.status_operacional = 'em_atendimento' AND ec.reserva_expira_em IS NOT NULL AND ec.reserva_expira_em < NOW())
                    THEN 1 ELSE 0
                END) AS disponiveis,
                SUM(CASE
                    WHEN ec.status_operacional = 'em_atendimento'
                     AND ec.reservado_por_usuario_id = :res_usuario
                     AND (ec.reserva_expira_em IS NULL OR ec.reserva_expira_em >= NOW())
                    THEN 1 ELSE 0
                END) AS reservados,
                SUM(CASE WHEN ec.status_operacional = 'trabalhado' THEN 1 ELSE 0 END) AS trabalhados,
                SUM(CASE WHEN ec.status_operacional <> 'removido' THEN COALESCE(ec.valor_faltante_snapshot, ec.saldo_snapshot, 0) ELSE 0 END) AS saldo
            FROM estrategia_contratos ec
            INNER JOIN estrategias e ON e.id = ec.estrategia_id
            INNER JOIN contratos co ON co.id = ec.contrato_id
            WHERE e.status = 'ativa' AND {$visSql} AND {$scope['sql']}");
        $resumoStmt->bindValue('res_usuario', $usuarioId, PDO::PARAM_INT);
        $this->bindParams($resumoStmt, $visParams + $scope['params']);
        $resumoStmt->execute();
        $resumoRow = $resumoStmt->fetch() ?: [];

        return [
            'itens' => $stmt->fetchAll(),
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_paginas' => $totalPaginas,
            'resumo' => [
                'disponiveis' => (int) ($resumoRow['disponiveis'] ?? 0),
                'reservados' => (int) ($resumoRow['reservados'] ?? 0),
                'trabalhados' => (int) ($resumoRow['trabalhados'] ?? 0),
                'saldo' => (float) ($resumoRow['saldo'] ?? 0),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function reservarEAbrir(int $itemId, int $usuarioId, ?array $escopo = null): array
    {
        $this->garantirModulo();
        if ($itemId <= 0 || $usuarioId <= 0) {
            throw new InvalidArgumentException('Item de estratégia ou usuário inválido.');
        }

        $this->pdo->beginTransaction();
        try {
            $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_reserva');
            [$visSql, $visParams] = $this->sqlVisibilidadeUsuario($usuarioId, 'e', 'vis_reserva');
            $stmt = $this->pdo->prepare("SELECT
                    ec.*, e.nome AS estrategia_nome, e.status AS estrategia_status, e.prioridade AS estrategia_prioridade,
                    co.codigo_contrato, co.credor_id, co.carteira_id, p.nome AS pessoa_nome
                FROM estrategia_contratos ec
                INNER JOIN estrategias e ON e.id = ec.estrategia_id
                INNER JOIN contratos co ON co.id = ec.contrato_id
                INNER JOIN pessoas p ON p.id = ec.pessoa_id
                WHERE ec.id = :id AND e.status = 'ativa' AND {$visSql} AND {$scope['sql']}
                LIMIT 1 FOR UPDATE");
            $stmt->bindValue('id', $itemId, PDO::PARAM_INT);
            $this->bindParams($stmt, $visParams + $scope['params']);
            $stmt->execute();
            $item = $stmt->fetch();
            if ($item === false) {
                throw new InvalidArgumentException('Contrato da estratégia não encontrado, inativo ou fora do seu escopo.');
            }
            if (!in_array((string) $item['status_operacional'], ['pendente', 'em_atendimento'], true)) {
                throw new InvalidArgumentException('Este contrato já foi trabalhado ou removido da estratégia.');
            }

            $reservadoPor = (int) ($item['reservado_por_usuario_id'] ?? 0);
            $expira = trim((string) ($item['reserva_expira_em'] ?? ''));
            $reservaAtiva = $expira === '' || new DateTimeImmutable($expira) >= new DateTimeImmutable('now');
            if ($reservadoPor > 0 && $reservadoPor !== $usuarioId && $reservaAtiva) {
                throw new RuntimeException('Este contrato já está reservado para outro operador.');
            }

            $update = $this->pdo->prepare("UPDATE estrategia_contratos SET
                    status_operacional = 'em_atendimento',
                    reservado_por_usuario_id = :usuario_id,
                    reserva_expira_em = DATE_ADD(NOW(), INTERVAL 2 HOUR),
                    aberto_em = COALESCE(aberto_em, NOW()),
                    ultimo_acesso_em = NOW()
                WHERE id = :id LIMIT 1");
            $update->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
            $update->bindValue('id', $itemId, PDO::PARAM_INT);
            $update->execute();

            $this->registrarEvento((int) $item['estrategia_id'], $itemId, 'contrato_aberto', 'Contrato reservado e aberto no Pactua.', $usuarioId, ['contrato_id' => (int) $item['contrato_id'], 'reserva_horas' => 2]);
            $this->recalcularContadores((int) $item['estrategia_id']);
            $this->pdo->commit();

            $item['status_operacional'] = 'em_atendimento';
            $item['reservado_por_usuario_id'] = $usuarioId;
            return $item;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function marcarTrabalhado(int $itemId, int $pessoaId, int $contratoId, int $atendimentoId, int $usuarioId, ?int $acordoId = null): void
    {
        if (!$this->moduloDisponivel() || $itemId <= 0 || $pessoaId <= 0 || $contratoId <= 0 || $atendimentoId <= 0) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT ec.*, e.status AS estrategia_status
                FROM estrategia_contratos ec
                INNER JOIN estrategias e ON e.id = ec.estrategia_id
                WHERE ec.id = :id AND ec.pessoa_id = :pessoa_id AND ec.contrato_id = :contrato_id
                LIMIT 1 FOR UPDATE');
            $stmt->bindValue('id', $itemId, PDO::PARAM_INT);
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
            $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
            $stmt->execute();
            $item = $stmt->fetch();
            if ($item === false) {
                throw new InvalidArgumentException('Item de estratégia inválido para este cliente e contrato.');
            }

            if ((string) $item['status_operacional'] === 'trabalhado') {
                if ((int) ($item['atendimento_id'] ?? 0) === $atendimentoId) {
                    $this->pdo->commit();
                    return;
                }
                throw new RuntimeException('Este contrato já foi concluído anteriormente na estratégia.');
            }
            if ((string) ($item['estrategia_status'] ?? '') !== 'ativa') {
                throw new RuntimeException('A estratégia não está ativa. Abra o contrato novamente por uma estratégia ativa antes de concluir o atendimento.');
            }
            if ((string) ($item['status_operacional'] ?? '') !== 'em_atendimento') {
                throw new RuntimeException('O contrato não está em atendimento nesta estratégia. Abra-o novamente pela página Estratégia antes de concluir o atendimento.');
            }

            $reservadoPor = (int) ($item['reservado_por_usuario_id'] ?? 0);
            if ($usuarioId <= 0 || $reservadoPor <= 0 || $reservadoPor !== $usuarioId) {
                throw new RuntimeException('O contrato não está reservado para este operador. Abra-o novamente pela página Estratégia antes de concluir o atendimento.');
            }

            $temAcordoId = $this->colunaDisponivel('estrategia_contratos', 'acordo_id');
            $acordoSql = $temAcordoId ? "
                    acordo_id = :acordo_id," : '';
            $update = $this->pdo->prepare("UPDATE estrategia_contratos SET
                    status_operacional = 'trabalhado',
                    atendimento_id = :atendimento_id," . $acordoSql . "
                    reservado_por_usuario_id = :usuario_id,
                    reserva_expira_em = NULL,
                    trabalhado_em = NOW(),
                    ultimo_acesso_em = NOW()
                WHERE id = :id LIMIT 1");
            $update->bindValue('atendimento_id', $atendimentoId, PDO::PARAM_INT);
            if ($temAcordoId) {
                $this->bindNullableInt($update, 'acordo_id', $acordoId !== null && $acordoId > 0 ? $acordoId : null);
            }
            $this->bindNullableInt($update, 'usuario_id', $usuarioId > 0 ? $usuarioId : null);
            $update->bindValue('id', $itemId, PDO::PARAM_INT);
            $update->execute();

            $this->registrarEvento((int) $item['estrategia_id'], $itemId, 'contrato_trabalhado', 'Contrato concluído após registro de atendimento no Pactua.', $usuarioId > 0 ? $usuarioId : null, ['atendimento_id' => $atendimentoId, 'acordo_id' => $acordoId, 'contrato_id' => (int) $item['contrato_id']]);
            $this->recalcularContadores((int) $item['estrategia_id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function buscarItem(int $itemId, int $usuarioId = 0, ?array $escopo = null): ?array
    {
        if (!$this->moduloDisponivel() || $itemId <= 0) {
            return null;
        }
        $scope = EscopoCredorSql::contrato($escopo, 'co', 'estrategia_item');
        [$visSql, $visParams] = $this->sqlVisibilidadeUsuario($usuarioId, 'e', 'vis_item');
        $stmt = $this->pdo->prepare("SELECT ec.*, e.nome AS estrategia_nome, e.status AS estrategia_status,
                co.codigo_contrato, p.nome AS pessoa_nome
            FROM estrategia_contratos ec
            INNER JOIN estrategias e ON e.id = ec.estrategia_id
            INNER JOIN contratos co ON co.id = ec.contrato_id
            INNER JOIN pessoas p ON p.id = ec.pessoa_id
            WHERE ec.id = :id AND {$visSql} AND {$scope['sql']} LIMIT 1");
        $stmt->bindValue('id', $itemId, PDO::PARAM_INT);
        $this->bindParams($stmt, $visParams + $scope['params']);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    public function formularioDaEstrategia(array $estrategia): array
    {
        $filtros = $this->decodeJsonArray($estrategia['filtros_json'] ?? null);
        $ordenacao = $this->decodeJsonArray($estrategia['ordenacao_json'] ?? null);
        return array_merge([
            'estrategia_id' => (string) ($estrategia['id'] ?? ''),
            'nome' => (string) ($estrategia['nome'] ?? ''),
            'descricao' => (string) ($estrategia['descricao'] ?? ''),
            'status' => (string) ($estrategia['status'] ?? 'ativa'),
            'prioridade' => (string) ($estrategia['prioridade'] ?? 100),
            'visibilidade' => (string) ($estrategia['visibilidade'] ?? 'todos'),
            'limite_contratos' => (string) ($estrategia['limite_contratos'] ?? 5000),
            'usuario_ids' => $this->listarUsuariosIdsDaEstrategia((int) ($estrategia['id'] ?? 0)),
            'confirmar_todos_escopo' => '1',
        ], $this->filtrosParaFormulario($filtros), $this->ordenacaoParaFormulario($ordenacao));
    }

    /** @param array<string,mixed> $filtros @param array<string,mixed> $ordenacao @return array<string,mixed> */
    private function previsualizarNormalizado(array $filtros, array $ordenacao, ?array $escopo, int $limiteAmostra, int $estrategiaAtualId): array
    {
        $metrics = $this->consultarMetricas($filtros, $escopo, $estrategiaAtualId);
        [$whereSql, $params] = $this->montarWhereFiltros($filtros, $escopo, 'preview');
        $sql = $this->cteCandidatos($estrategiaAtualId) . '
            SELECT cb.* FROM candidatos cb
            WHERE ' . $whereSql . ' AND cb.conflito_ativo = 0
            ORDER BY ' . $this->sqlOrdenacao($ordenacao) . '
            LIMIT :limite_amostra';
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->bindValue('limite_amostra', $limiteAmostra, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'metricas' => $metrics,
            'amostra' => $stmt->fetchAll(),
            'filtros' => $filtros,
            'ordenacao' => $ordenacao,
        ];
    }

    /** @param array<string,mixed> $filtros @return array<string,int|float> */
    private function consultarMetricas(array $filtros, ?array $escopo, int $estrategiaAtualId): array
    {
        [$whereSql, $params] = $this->montarWhereFiltros($filtros, $escopo, 'metricas');
        $sql = $this->cteCandidatos($estrategiaAtualId) . '
            SELECT
                COUNT(*) AS total_encontrado,
                SUM(CASE WHEN cb.conflito_ativo = 0 THEN 1 ELSE 0 END) AS total_disponivel,
                SUM(CASE WHEN cb.conflito_ativo = 1 THEN 1 ELSE 0 END) AS total_conflitos,
                COUNT(DISTINCT CASE WHEN cb.conflito_ativo = 0 THEN cb.pessoa_id END) AS clientes_unicos,
                SUM(CASE WHEN cb.conflito_ativo = 0 THEN cb.valor_faltante ELSE 0 END) AS saldo_total,
                SUM(CASE WHEN cb.conflito_ativo = 0 THEN cb.valor_pago ELSE 0 END) AS valor_pago_total,
                AVG(CASE WHEN cb.conflito_ativo = 0 THEN cb.aging END) AS aging_medio,
                SUM(CASE WHEN cb.conflito_ativo = 0 AND cb.quantidade_tentativas = 0 THEN 1 ELSE 0 END) AS nunca_acionados,
                SUM(CASE WHEN cb.conflito_ativo = 0 AND cb.acordos_ativos > 0 THEN 1 ELSE 0 END) AS com_acordo_ativo
            FROM candidatos cb
            WHERE ' . $whereSql;
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'total_encontrado' => (int) ($row['total_encontrado'] ?? 0),
            'total_disponivel' => (int) ($row['total_disponivel'] ?? 0),
            'total_conflitos' => (int) ($row['total_conflitos'] ?? 0),
            'clientes_unicos' => (int) ($row['clientes_unicos'] ?? 0),
            'saldo_total' => (float) ($row['saldo_total'] ?? 0),
            'valor_pago_total' => (float) ($row['valor_pago_total'] ?? 0),
            'aging_medio' => (float) ($row['aging_medio'] ?? 0),
            'nunca_acionados' => (int) ($row['nunca_acionados'] ?? 0),
            'com_acordo_ativo' => (int) ($row['com_acordo_ativo'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $filtros @param array<string,mixed> $ordenacao @return array{total_encontrado:int,total_conflitos:int,total_materializado:int} */
    private function materializar(int $estrategiaId, array $filtros, array $ordenacao, int $limite, ?array $escopo): array
    {
        $metrics = $this->consultarMetricas($filtros, $escopo, $estrategiaId);
        [$whereSql, $params] = $this->montarWhereFiltros($filtros, $escopo, 'materializar');
        $sql = $this->cteCandidatos($estrategiaId) . '
            SELECT
                cb.contrato_id, cb.pessoa_id, cb.saldo_atual, cb.valor_original, cb.valor_pago, cb.valor_faltante,
                cb.aging, cb.data_entrada_base, cb.data_vencimento_original, cb.ultima_tentativa,
                cb.quantidade_tentativas, cb.ultimo_resultado_id, cb.ultimo_resultado_nome,
                cb.credor_nome, cb.carteira_nome, cb.produto, cb.codigo_contrato
            FROM candidatos cb
            WHERE ' . $whereSql . ' AND cb.conflito_ativo = 0
            ORDER BY ' . $this->sqlOrdenacao($ordenacao) . '
            LIMIT :limite_materializacao';
        $stmt = $this->pdo->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->bindValue('limite_materializacao', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $ordem = 0;
        $lote = [];
        $capturadoEm = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        while (($row = $stmt->fetch()) !== false) {
            $ordem++;
            $payloadSnapshot = json_encode([
                'credor' => $row['credor_nome'] ?? null,
                'carteira' => $row['carteira_nome'] ?? null,
                'produto' => $row['produto'] ?? null,
                'codigo_contrato' => $row['codigo_contrato'] ?? null,
                'ultimo_resultado' => $row['ultimo_resultado_nome'] ?? null,
                'capturado_em' => $capturadoEm,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $lote[] = [
                'estrategia_id' => $estrategiaId,
                'contrato_id' => (int) $row['contrato_id'],
                'pessoa_id' => (int) $row['pessoa_id'],
                'ordem' => $ordem,
                'saldo' => $row['saldo_atual'] ?? null,
                'valor_original' => $row['valor_original'] ?? null,
                'valor_pago' => $row['valor_pago'] ?? null,
                'valor_faltante' => $row['valor_faltante'] ?? null,
                'aging' => isset($row['aging']) ? (int) $row['aging'] : null,
                'data_entrada' => $row['data_entrada_base'] ?? null,
                'vencimento_original' => $row['data_vencimento_original'] ?? null,
                'ultima_tentativa' => $row['ultima_tentativa'] ?? null,
                'quantidade_tentativas' => (int) ($row['quantidade_tentativas'] ?? 0),
                'ultimo_resultado_id' => !empty($row['ultimo_resultado_id']) ? (int) $row['ultimo_resultado_id'] : null,
                'payload_snapshot' => $payloadSnapshot === false ? '{}' : $payloadSnapshot,
            ];

            if (count($lote) >= 250) {
                $this->inserirLoteMaterializacao($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->inserirLoteMaterializacao($lote);
        }

        $update = $this->pdo->prepare('UPDATE estrategias SET gerada_em = NOW(), atualizado_em = CURRENT_TIMESTAMP WHERE id = :id');
        $update->bindValue('id', $estrategiaId, PDO::PARAM_INT);
        $update->execute();

        return [
            'total_encontrado' => (int) $metrics['total_encontrado'],
            'total_conflitos' => (int) $metrics['total_conflitos'],
            'total_materializado' => $ordem,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function inserirLoteMaterializacao(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $values[] = "(
                ?, ?, ?, 'pendente', ?,
                NULL, NULL, NULL,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, NOW(), NULL, NULL, NULL, NULL, NULL
            )";
            $params[] = $row['estrategia_id'];
            $params[] = $row['contrato_id'];
            $params[] = $row['pessoa_id'];
            $params[] = $row['ordem'];
            $params[] = $row['saldo'];
            $params[] = $row['valor_original'];
            $params[] = $row['valor_pago'];
            $params[] = $row['valor_faltante'];
            $params[] = $row['aging'];
            $params[] = $row['data_entrada'];
            $params[] = $row['vencimento_original'];
            $params[] = $row['ultima_tentativa'];
            $params[] = $row['quantidade_tentativas'];
            $params[] = $row['ultimo_resultado_id'];
            $params[] = $row['payload_snapshot'];
        }

        $sql = "INSERT INTO estrategia_contratos (
                estrategia_id, contrato_id, pessoa_id, status_operacional, ordem_prioridade,
                reservado_por_usuario_id, reserva_expira_em, atendimento_id,
                saldo_snapshot, valor_original_snapshot, valor_pago_snapshot, valor_faltante_snapshot,
                aging_snapshot, data_entrada_base_snapshot, data_vencimento_original_snapshot,
                ultima_tentativa_snapshot, quantidade_tentativas_snapshot, ultimo_resultado_id_snapshot,
                payload_snapshot, incluido_em, aberto_em, ultimo_acesso_em, trabalhado_em, removido_em, motivo_remocao
            ) VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                pessoa_id = VALUES(pessoa_id),
                status_operacional = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN 'trabalhado' ELSE 'pendente' END,
                ordem_prioridade = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.ordem_prioridade ELSE VALUES(ordem_prioridade) END,
                reservado_por_usuario_id = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.reservado_por_usuario_id ELSE NULL END,
                reserva_expira_em = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.reserva_expira_em ELSE NULL END,
                atendimento_id = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.atendimento_id ELSE NULL END,
                aberto_em = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.aberto_em ELSE NULL END,
                ultimo_acesso_em = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.ultimo_acesso_em ELSE NULL END,
                trabalhado_em = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.trabalhado_em ELSE NULL END,
                saldo_snapshot = VALUES(saldo_snapshot),
                valor_original_snapshot = VALUES(valor_original_snapshot),
                valor_pago_snapshot = VALUES(valor_pago_snapshot),
                valor_faltante_snapshot = VALUES(valor_faltante_snapshot),
                aging_snapshot = VALUES(aging_snapshot),
                data_entrada_base_snapshot = VALUES(data_entrada_base_snapshot),
                data_vencimento_original_snapshot = VALUES(data_vencimento_original_snapshot),
                ultima_tentativa_snapshot = VALUES(ultima_tentativa_snapshot),
                quantidade_tentativas_snapshot = VALUES(quantidade_tentativas_snapshot),
                ultimo_resultado_id_snapshot = VALUES(ultimo_resultado_id_snapshot),
                payload_snapshot = VALUES(payload_snapshot),
                removido_em = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.removido_em ELSE NULL END,
                motivo_remocao = CASE WHEN estrategia_contratos.status_operacional = 'trabalhado' THEN estrategia_contratos.motivo_remocao ELSE NULL END";

        $insert = $this->pdo->prepare($sql);
        $insert->execute($params);
    }

    /** @param array<string,mixed> $filtros @return array{0:string,1:array<string,mixed>} */
    private function montarWhereFiltros(array $filtros, ?array $escopo, string $prefix): array
    {
        $where = [];
        $params = [];
        $scope = EscopoCredorSql::contrato($escopo, 'cb', $prefix . '_scope');
        $where[] = $scope['sql'];
        $params += $scope['params'];

        $add = static function (array &$where, array &$params, string $sql, string $key, mixed $value): void {
            $where[] = $sql;
            $params[$key] = $value;
        };

        if (isset($filtros['credor_id'])) {
            $add($where, $params, 'cb.credor_id = :' . $prefix . '_credor_id', $prefix . '_credor_id', (int) $filtros['credor_id']);
        }
        if (isset($filtros['carteira_id'])) {
            $add($where, $params, 'cb.carteira_id = :' . $prefix . '_carteira_id', $prefix . '_carteira_id', (int) $filtros['carteira_id']);
        }
        if (isset($filtros['produto'])) {
            $add($where, $params, 'cb.produto LIKE :' . $prefix . '_produto', $prefix . '_produto', '%' . $filtros['produto'] . '%');
        }
        if (isset($filtros['subproduto'])) {
            $add($where, $params, 'cb.subproduto LIKE :' . $prefix . '_subproduto', $prefix . '_subproduto', '%' . $filtros['subproduto'] . '%');
        }
        if (isset($filtros['status_contrato'])) {
            $add($where, $params, 'cb.status_contrato = :' . $prefix . '_status_contrato', $prefix . '_status_contrato', $filtros['status_contrato']);
        }
        if (isset($filtros['codigo_contrato'])) {
            $add($where, $params, 'cb.codigo_contrato LIKE :' . $prefix . '_codigo_contrato', $prefix . '_codigo_contrato', '%' . $filtros['codigo_contrato'] . '%');
        }

        $this->adicionarFaixaData($where, $params, $filtros['data_entrada'] ?? null, 'DATE(cb.data_entrada_base)', $prefix . '_entrada');
        $this->adicionarFaixaData($where, $params, $filtros['data_atualizacao'] ?? null, 'DATE(cb.contrato_atualizado_em)', $prefix . '_atualizacao');
        $this->adicionarFaixaData($where, $params, $filtros['vencimento_original'] ?? null, 'cb.data_vencimento_original', $prefix . '_vencimento');
        $this->adicionarFaixaData($where, $params, $filtros['data_ultima_tentativa'] ?? null, 'DATE(cb.ultima_tentativa)', $prefix . '_tentativa');
        $this->adicionarFaixaData($where, $params, $filtros['data_ultimo_pagamento'] ?? null, 'cb.data_ultimo_pagamento', $prefix . '_pagamento');

        $this->adicionarFaixaNumero($where, $params, $filtros['aging'] ?? null, 'cb.aging', $prefix . '_aging');
        $this->adicionarFaixaNumero($where, $params, $filtros['dia_vencimento'] ?? null, 'DAY(cb.data_vencimento_original)', $prefix . '_dia_vencimento');
        $this->adicionarFaixaNumero($where, $params, $filtros['valor_original'] ?? null, 'cb.valor_original', $prefix . '_valor_original');
        $this->adicionarFaixaNumero($where, $params, $filtros['valor_pago'] ?? null, 'cb.valor_pago', $prefix . '_valor_pago');
        $this->adicionarFaixaNumero($where, $params, $filtros['valor_faltante'] ?? null, 'cb.valor_faltante', $prefix . '_valor_faltante');
        $this->adicionarFaixaNumero($where, $params, $filtros['quantidade_tentativas'] ?? null, 'cb.quantidade_tentativas', $prefix . '_qtd_tentativas');
        $this->adicionarFaixaNumero($where, $params, $filtros['total_parcelas'] ?? null, 'cb.total_parcelas', $prefix . '_parcelas');

        if (isset($filtros['situacao_tentativas'])) {
            if ($filtros['situacao_tentativas'] === 'nunca') {
                $where[] = 'cb.quantidade_tentativas = 0';
            } elseif ($filtros['situacao_tentativas'] === 'com') {
                $where[] = 'cb.quantidade_tentativas > 0';
            }
        }
        if (isset($filtros['dias_sem_tentativa'])) {
            $faixa = $filtros['dias_sem_tentativa'];
            if (($faixa['min'] ?? null) !== null) {
                $key = $prefix . '_dias_sem_min';
                if (!empty($faixa['incluir_nunca'])) {
                    $where[] = '(cb.ultima_tentativa IS NULL OR DATEDIFF(CURDATE(), DATE(cb.ultima_tentativa)) >= :' . $key . ')';
                } else {
                    $where[] = 'cb.ultima_tentativa IS NOT NULL AND DATEDIFF(CURDATE(), DATE(cb.ultima_tentativa)) >= :' . $key;
                }
                $params[$key] = (int) $faixa['min'];
            }
            if (($faixa['max'] ?? null) !== null) {
                $key = $prefix . '_dias_sem_max';
                $where[] = 'cb.ultima_tentativa IS NOT NULL AND DATEDIFF(CURDATE(), DATE(cb.ultima_tentativa)) <= :' . $key;
                $params[$key] = (int) $faixa['max'];
            }
        }
        if (isset($filtros['ultimo_resultado_id'])) {
            $add($where, $params, 'cb.ultimo_resultado_id = :' . $prefix . '_resultado_id', $prefix . '_resultado_id', (int) $filtros['ultimo_resultado_id']);
        }
        if (isset($filtros['situacao_acordo'])) {
            $where[] = match ($filtros['situacao_acordo']) {
                'sem_acordo' => 'cb.total_acordos = 0',
                'sem_ativo' => 'cb.acordos_ativos = 0',
                'ativo' => 'cb.acordos_ativos > 0',
                'quebrado' => 'cb.acordos_quebrados > 0',
                'cumprido' => 'cb.acordos_cumpridos > 0',
                'cancelado' => 'cb.acordos_cancelados > 0',
                default => '1=1',
            };
        }
        if (isset($filtros['tipo_contato'])) {
            $where[] = match ($filtros['tipo_contato']) {
                'telefone_valido' => 'cb.telefones_validos > 0',
                'sem_telefone_valido' => 'cb.telefones_validos = 0',
                'whatsapp' => 'cb.whatsapps_validos > 0',
                'email' => 'cb.emails_validos > 0',
                'qualquer_valido' => '(cb.telefones_validos + cb.whatsapps_validos + cb.emails_validos) > 0',
                default => '1=1',
            };
        }
        if (isset($filtros['uf'])) {
            $add($where, $params, 'cb.uf = :' . $prefix . '_uf', $prefix . '_uf', $filtros['uf']);
        }
        if (isset($filtros['cidade'])) {
            $add($where, $params, 'cb.cidade LIKE :' . $prefix . '_cidade', $prefix . '_cidade', '%' . $filtros['cidade'] . '%');
        }

        return [implode(' AND ', $where !== [] ? $where : ['1=1']), $params];
    }

    private function cteCandidatos(int $estrategiaAtualId): string
    {
        $estrategiaAtualId = max(0, $estrategiaAtualId);
        return "WITH contrato_base AS (
            SELECT
                co.id AS contrato_id,
                co.pessoa_id,
                co.credor_id,
                co.carteira_id,
                co.codigo_contrato,
                co.produto,
                co.subproduto,
                co.status_contrato,
                co.dias_atraso AS dias_atraso_carga,
                co.saldo_atual,
                co.saldo_contabil,
                co.valor_total_pago AS valor_total_pago_carga,
                co.valor_principal,
                co.valor_financiado,
                co.valor_contratado,
                co.atualizado_em AS contrato_atualizado_em,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                p.tipo_documento,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome,
                COALESCE(carga_origem.recebido_em, co.criado_em) AS data_entrada_base,
                COALESCE(co.data_vencimento_inicial, parcelas.vencimento_original) AS data_vencimento_original,
                COALESCE(parcelas.total_parcelas, 0) AS total_parcelas,
                COALESCE(parcelas.parcelas_pagas, 0) AS parcelas_pagas,
                COALESCE(parcelas.parcelas_abertas, 0) AS parcelas_abertas,
                COALESCE(parcelas.valor_original_parcelas, 0) AS valor_original_parcelas,
                COALESCE(pagamentos.valor_pago_importado, 0) AS valor_pago_importado,
                pagamentos.data_ultimo_pagamento,
                COALESCE(atend_contrato.quantidade_tentativas, atend_pessoa.quantidade_tentativas, 0) AS quantidade_tentativas,
                COALESCE(atend_contrato.ultima_tentativa, atend_pessoa.ultima_tentativa) AS ultima_tentativa,
                COALESCE(atend_contrato.ultimo_resultado_id, atend_pessoa.ultimo_resultado_id) AS ultimo_resultado_id,
                COALESCE(atend_contrato.ultimo_resultado_nome, atend_pessoa.ultimo_resultado_nome) AS ultimo_resultado_nome,
                COALESCE(contatos.telefones_validos, 0) AS telefones_validos,
                COALESCE(contatos.whatsapps_validos, 0) AS whatsapps_validos,
                COALESCE(contatos.emails_validos, 0) AS emails_validos,
                enderecos.uf,
                enderecos.cidade,
                COALESCE(acordos.total_acordos, 0) AS total_acordos,
                COALESCE(acordos.acordos_ativos, 0) AS acordos_ativos,
                COALESCE(acordos.acordos_quebrados, 0) AS acordos_quebrados,
                COALESCE(acordos.acordos_cumpridos, 0) AS acordos_cumpridos,
                COALESCE(acordos.acordos_cancelados, 0) AS acordos_cancelados,
                acordos.ultimo_status_acordo
            FROM contratos co
            INNER JOIN pessoas p ON p.id = co.pessoa_id
            INNER JOIN credores cr ON cr.id = co.credor_id
            LEFT JOIN carteiras car ON car.id = co.carteira_id
            LEFT JOIN cargas_arquivos carga_origem ON carga_origem.id = co.origem_carga_id
            LEFT JOIN (
                SELECT contrato_id,
                    MIN(data_vencimento) AS vencimento_original,
                    COUNT(*) AS total_parcelas,
                    SUM(CASE WHEN flag_liquidado = 1 OR status_parcela IN ('paga','pago','liquidada','liquidado') THEN 1 ELSE 0 END) AS parcelas_pagas,
                    SUM(CASE WHEN COALESCE(flag_liquidado, 0) = 0 AND COALESCE(status_parcela, '') NOT IN ('paga','pago','liquidada','liquidado') THEN 1 ELSE 0 END) AS parcelas_abertas,
                    SUM(COALESCE(valor_original, 0)) AS valor_original_parcelas
                FROM contrato_parcelas
                GROUP BY contrato_id
            ) parcelas ON parcelas.contrato_id = co.id
            LEFT JOIN (
                SELECT contrato_id,
                    SUM(GREATEST(COALESCE(valor_recebido, 0), COALESCE(valor_pago, 0))) AS valor_pago_importado,
                    MAX(COALESCE(data_pagamento, data_baixa, data_inclusao)) AS data_ultimo_pagamento
                FROM pagamentos
                WHERE contrato_id IS NOT NULL
                GROUP BY contrato_id
            ) pagamentos ON pagamentos.contrato_id = co.id
            LEFT JOIN (
                SELECT ah.contrato_id,
                    COUNT(*) AS quantidade_tentativas,
                    MAX(ah.criado_em) AS ultima_tentativa,
                    CAST(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ah.resultado_id, 0) ORDER BY ah.criado_em DESC, ah.id DESC), ',', 1), '0') AS UNSIGNED) AS ultimo_resultado_id,
                    NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ar.nome, '') ORDER BY ah.criado_em DESC, ah.id DESC SEPARATOR '||'), '||', 1), '') AS ultimo_resultado_nome
                FROM atendimentos_historico ah
                LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
                WHERE ah.contrato_id IS NOT NULL
                GROUP BY ah.contrato_id
            ) atend_contrato ON atend_contrato.contrato_id = co.id
            LEFT JOIN (
                SELECT ah.pessoa_id,
                    COUNT(*) AS quantidade_tentativas,
                    MAX(ah.criado_em) AS ultima_tentativa,
                    CAST(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ah.resultado_id, 0) ORDER BY ah.criado_em DESC, ah.id DESC), ',', 1), '0') AS UNSIGNED) AS ultimo_resultado_id,
                    NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ar.nome, '') ORDER BY ah.criado_em DESC, ah.id DESC SEPARATOR '||'), '||', 1), '') AS ultimo_resultado_nome
                FROM atendimentos_historico ah
                LEFT JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
                WHERE ah.contrato_id IS NULL
                GROUP BY ah.pessoa_id
            ) atend_pessoa ON atend_pessoa.pessoa_id = co.pessoa_id AND atend_contrato.contrato_id IS NULL
            LEFT JOIN (
                SELECT pessoa_id,
                    SUM(CASE WHEN tipo = 'telefone' AND status NOT IN ('incorreto','bloqueado') AND CHAR_LENGTH(valor_normalizado) >= 8 THEN 1 ELSE 0 END) AS telefones_validos,
                    SUM(CASE WHEN tipo = 'whatsapp' AND status NOT IN ('incorreto','bloqueado') AND CHAR_LENGTH(valor_normalizado) >= 8 THEN 1 ELSE 0 END) AS whatsapps_validos,
                    SUM(CASE WHEN tipo = 'email' AND status NOT IN ('incorreto','bloqueado') AND email IS NOT NULL AND email <> '' THEN 1 ELSE 0 END) AS emails_validos
                FROM pessoa_contatos
                GROUP BY pessoa_id
            ) contatos ON contatos.pessoa_id = co.pessoa_id
            LEFT JOIN (
                SELECT pessoa_id,
                    COALESCE(MAX(CASE WHEN principal = 1 THEN uf END), MAX(uf)) AS uf,
                    COALESCE(MAX(CASE WHEN principal = 1 THEN cidade END), MAX(cidade)) AS cidade
                FROM pessoa_enderecos
                GROUP BY pessoa_id
            ) enderecos ON enderecos.pessoa_id = co.pessoa_id
            LEFT JOIN (
                SELECT contrato_id,
                    COUNT(*) AS total_acordos,
                    SUM(CASE WHEN status_operacional IN ('pendente','formalizado') THEN 1 ELSE 0 END) AS acordos_ativos,
                    SUM(CASE WHEN status_operacional = 'quebrado' THEN 1 ELSE 0 END) AS acordos_quebrados,
                    SUM(CASE WHEN status_operacional = 'cumprido' THEN 1 ELSE 0 END) AS acordos_cumpridos,
                    SUM(CASE WHEN status_operacional = 'cancelado' THEN 1 ELSE 0 END) AS acordos_cancelados,
                    SUBSTRING_INDEX(GROUP_CONCAT(status_operacional ORDER BY criado_em DESC, id DESC), ',', 1) AS ultimo_status_acordo
                FROM acordos_operacionais
                WHERE contrato_id IS NOT NULL
                GROUP BY contrato_id
            ) acordos ON acordos.contrato_id = co.id
        ), valores AS (
            SELECT b.*,
                CASE
                    WHEN b.data_vencimento_original IS NOT NULL THEN GREATEST(DATEDIFF(CURDATE(), b.data_vencimento_original), 0)
                    ELSE GREATEST(COALESCE(b.dias_atraso_carga, 0), 0)
                END AS aging,
                GREATEST(COALESCE(b.valor_total_pago_carga, 0), COALESCE(b.valor_pago_importado, 0), 0) AS valor_pago,
                GREATEST(COALESCE(
                    NULLIF(b.valor_contratado, 0),
                    NULLIF(b.valor_financiado, 0),
                    NULLIF(b.valor_principal, 0),
                    NULLIF(b.valor_original_parcelas, 0),
                    NULLIF(COALESCE(b.saldo_atual, 0) + GREATEST(COALESCE(b.valor_total_pago_carga, 0), COALESCE(b.valor_pago_importado, 0), 0), 0),
                    NULLIF(b.saldo_contabil, 0),
                    0
                ), 0) AS valor_original
            FROM contrato_base b
        ), candidatos AS (
            SELECT v.*,
                GREATEST(COALESCE(v.saldo_atual, v.saldo_contabil, v.valor_original - v.valor_pago, 0), 0) AS valor_faltante,
                EXISTS (
                    SELECT 1
                    FROM estrategia_contratos ec_conf
                    INNER JOIN estrategias e_conf ON e_conf.id = ec_conf.estrategia_id
                    WHERE ec_conf.contrato_id = v.contrato_id
                      AND e_conf.status = 'ativa'
                      AND ec_conf.status_operacional IN ('pendente','em_atendimento')
                      AND e_conf.id <> {$estrategiaAtualId}
                ) AS conflito_ativo
            FROM valores v
        )";
    }

    /** @param array<string,mixed> $dados @return array<string,mixed> */
    private function normalizarFiltros(array $dados, bool $exigirConfirmacaoSemFiltro): array
    {
        $filtros = [];
        $ativo = static fn(string $chave): bool => (string) ($dados['usar_' . $chave] ?? '') === '1';

        if ($ativo('credor')) {
            $filtros['credor_id'] = $this->idObrigatorio($dados['credor_id'] ?? null, 'Selecione o credor.');
        }
        if ($ativo('carteira')) {
            $filtros['carteira_id'] = $this->idObrigatorio($dados['carteira_id'] ?? null, 'Selecione a carteira.');
        }
        if ($ativo('produto')) {
            $filtros['produto'] = $this->normalizarTexto($dados['produto'] ?? null, 180, true);
        }
        if ($ativo('subproduto')) {
            $filtros['subproduto'] = $this->normalizarTexto($dados['subproduto'] ?? null, 180, true);
        }
        if ($ativo('status_contrato')) {
            $filtros['status_contrato'] = $this->normalizarTexto($dados['status_contrato'] ?? null, 120, true);
        }
        if ($ativo('codigo_contrato')) {
            $filtros['codigo_contrato'] = $this->normalizarTexto($dados['codigo_contrato'] ?? null, 120, true);
        }
        if ($ativo('data_entrada')) {
            $filtros['data_entrada'] = $this->normalizarFaixaData($dados['data_entrada_de'] ?? null, $dados['data_entrada_ate'] ?? null, 'entrada na base');
        }
        if ($ativo('data_atualizacao')) {
            $filtros['data_atualizacao'] = $this->normalizarFaixaData($dados['data_atualizacao_de'] ?? null, $dados['data_atualizacao_ate'] ?? null, 'atualização do contrato');
        }
        if ($ativo('vencimento_original')) {
            $filtros['vencimento_original'] = $this->normalizarFaixaData($dados['vencimento_original_de'] ?? null, $dados['vencimento_original_ate'] ?? null, 'vencimento original');
        }
        if ($ativo('dia_vencimento')) {
            $filtros['dia_vencimento'] = $this->normalizarFaixaInteiro($dados['dia_vencimento_de'] ?? null, $dados['dia_vencimento_ate'] ?? null, 1, 31, 'dia do vencimento original');
        }
        if ($ativo('aging')) {
            $filtros['aging'] = $this->normalizarFaixaInteiro($dados['aging_min'] ?? null, $dados['aging_max'] ?? null, 0, 99999, 'aging');
        }
        if ($ativo('valor_original')) {
            $filtros['valor_original'] = $this->normalizarFaixaDecimal($dados['valor_original_min'] ?? null, $dados['valor_original_max'] ?? null, 'valor original');
        }
        if ($ativo('valor_pago')) {
            $filtros['valor_pago'] = $this->normalizarFaixaDecimal($dados['valor_pago_min'] ?? null, $dados['valor_pago_max'] ?? null, 'valor pago');
        }
        if ($ativo('valor_faltante')) {
            $filtros['valor_faltante'] = $this->normalizarFaixaDecimal($dados['valor_faltante_min'] ?? null, $dados['valor_faltante_max'] ?? null, 'valor faltante');
        }
        if ($ativo('situacao_tentativas')) {
            $valor = (string) ($dados['situacao_tentativas'] ?? '');
            if (!in_array($valor, ['nunca', 'com'], true)) {
                throw new InvalidArgumentException('Selecione uma situação válida para as tentativas.');
            }
            $filtros['situacao_tentativas'] = $valor;
        }
        if ($ativo('quantidade_tentativas')) {
            $filtros['quantidade_tentativas'] = $this->normalizarFaixaInteiro($dados['tentativas_min'] ?? null, $dados['tentativas_max'] ?? null, 0, 999999, 'quantidade de tentativas');
        }
        if ($ativo('data_ultima_tentativa')) {
            $filtros['data_ultima_tentativa'] = $this->normalizarFaixaData($dados['ultima_tentativa_de'] ?? null, $dados['ultima_tentativa_ate'] ?? null, 'última tentativa');
        }
        if ($ativo('dias_sem_tentativa')) {
            $faixa = $this->normalizarFaixaInteiro($dados['dias_sem_tentativa_min'] ?? null, $dados['dias_sem_tentativa_max'] ?? null, 0, 99999, 'dias sem tentativa');
            $faixa['incluir_nunca'] = (string) ($dados['dias_sem_incluir_nunca'] ?? '1') === '1';
            $filtros['dias_sem_tentativa'] = $faixa;
        }
        if ($ativo('ultimo_resultado')) {
            $filtros['ultimo_resultado_id'] = $this->idObrigatorio($dados['ultimo_resultado_id'] ?? null, 'Selecione o último resultado.');
        }
        if ($ativo('data_ultimo_pagamento')) {
            $filtros['data_ultimo_pagamento'] = $this->normalizarFaixaData($dados['ultimo_pagamento_de'] ?? null, $dados['ultimo_pagamento_ate'] ?? null, 'último pagamento');
        }
        if ($ativo('situacao_acordo')) {
            $valor = (string) ($dados['situacao_acordo'] ?? '');
            if (!in_array($valor, ['sem_acordo', 'sem_ativo', 'ativo', 'quebrado', 'cumprido', 'cancelado'], true)) {
                throw new InvalidArgumentException('Selecione uma situação válida para os acordos.');
            }
            $filtros['situacao_acordo'] = $valor;
        }
        if ($ativo('tipo_contato')) {
            $valor = (string) ($dados['tipo_contato'] ?? '');
            if (!in_array($valor, ['telefone_valido', 'sem_telefone_valido', 'whatsapp', 'email', 'qualquer_valido'], true)) {
                throw new InvalidArgumentException('Selecione um filtro de contato válido.');
            }
            $filtros['tipo_contato'] = $valor;
        }
        if ($ativo('localidade')) {
            $uf = strtoupper(trim((string) ($dados['uf'] ?? '')));
            $cidade = $this->normalizarTexto($dados['cidade'] ?? null, 120, false);
            if ($uf === '' && $cidade === null) {
                throw new InvalidArgumentException('Informe UF ou cidade para utilizar o filtro de localidade.');
            }
            if ($uf !== '') {
                if (!preg_match('/^[A-Z]{2}$/', $uf)) {
                    throw new InvalidArgumentException('UF inválida.');
                }
                $filtros['uf'] = $uf;
            }
            if ($cidade !== null) {
                $filtros['cidade'] = $cidade;
            }
        }
        if ($ativo('total_parcelas')) {
            $filtros['total_parcelas'] = $this->normalizarFaixaInteiro($dados['total_parcelas_min'] ?? null, $dados['total_parcelas_max'] ?? null, 0, 99999, 'quantidade de parcelas');
        }

        if ($filtros === [] && $exigirConfirmacaoSemFiltro && (string) ($dados['confirmar_todos_escopo'] ?? '') !== '1') {
            throw new InvalidArgumentException('Nenhum filtro está ativo. Marque a confirmação para selecionar todos os contratos permitidos pelo seu escopo.');
        }

        return $filtros;
    }

    /** @param array<string,mixed> $dados @return array<string,mixed> */
    private function normalizarOrdenacao(array $dados): array
    {
        $campo1 = (string) ($dados['ordem_1'] ?? 'aging');
        $campo2 = (string) ($dados['ordem_2'] ?? 'valor_faltante');
        if (!isset(self::CAMPOS_ORDENACAO[$campo1])) {
            $campo1 = 'aging';
        }
        if (!isset(self::CAMPOS_ORDENACAO[$campo2])) {
            $campo2 = 'valor_faltante';
        }
        $direcao1 = strtoupper((string) ($dados['direcao_1'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $direcao2 = strtoupper((string) ($dados['direcao_2'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        return [
            ['campo' => $campo1, 'direcao' => $direcao1],
            ['campo' => $campo2, 'direcao' => $direcao2],
        ];
    }

    /** @param array<string,mixed> $ordenacao */
    private function sqlOrdenacao(array $ordenacao): string
    {
        $mapa = [
            'aging' => 'cb.aging',
            'valor_faltante' => 'cb.valor_faltante',
            'valor_pago' => 'cb.valor_pago',
            'valor_original' => 'cb.valor_original',
            'data_entrada' => 'cb.data_entrada_base',
            'ultima_tentativa' => 'COALESCE(cb.ultima_tentativa, \'1900-01-01 00:00:00\')',
            'vencimento_original' => 'COALESCE(cb.data_vencimento_original, \'9999-12-31\')',
            'codigo_contrato' => 'cb.codigo_contrato',
            'aleatorio' => 'RAND()',
        ];

        $partes = [];
        foreach (array_slice($ordenacao, 0, 2) as $regra) {
            if (!is_array($regra)) {
                continue;
            }
            $campo = (string) ($regra['campo'] ?? '');
            if (!isset($mapa[$campo])) {
                continue;
            }
            $direcao = strtoupper((string) ($regra['direcao'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            $partes[] = $mapa[$campo] . ($campo === 'aleatorio' ? '' : ' ' . $direcao);
        }
        $partes[] = 'cb.contrato_id ASC';
        return implode(', ', $partes);
    }

    /** @param array<string,mixed> $filtros */
    private function validarFiltrosDentroDoEscopo(array $filtros, ?array $escopo): void
    {
        if ($escopo === null || (bool) ($escopo['todos'] ?? false)) {
            return;
        }

        $credorIds = $this->normalizarListaIds($escopo['credor_ids'] ?? []);
        $carteiraIds = $this->normalizarListaIds($escopo['carteira_ids'] ?? []);
        if (isset($filtros['credor_id'])) {
            $credorId = (int) $filtros['credor_id'];
            if (!in_array($credorId, $credorIds, true)) {
                $permitidoViaCarteira = false;
                if ($carteiraIds !== []) {
                    $ph = implode(',', array_fill(0, count($carteiraIds), '?'));
                    $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM carteiras WHERE credor_id = ? AND id IN (' . $ph . ')');
                    $stmt->execute(array_merge([$credorId], $carteiraIds));
                    $permitidoViaCarteira = (int) $stmt->fetchColumn() > 0;
                }
                if (!$permitidoViaCarteira) {
                    throw new InvalidArgumentException('Seu usuário não possui escopo para criar estratégia nesse credor.');
                }
            }
        }

        if (isset($filtros['carteira_id'])) {
            $carteiraId = (int) $filtros['carteira_id'];
            if (!in_array($carteiraId, $carteiraIds, true)) {
                $stmt = $this->pdo->prepare('SELECT credor_id FROM carteiras WHERE id = :id LIMIT 1');
                $stmt->bindValue('id', $carteiraId, PDO::PARAM_INT);
                $stmt->execute();
                $credorId = (int) ($stmt->fetchColumn() ?: 0);
                if ($credorId <= 0 || !in_array($credorId, $credorIds, true)) {
                    throw new InvalidArgumentException('Seu usuário não possui escopo para criar estratégia nessa carteira.');
                }
            }
        }
    }

    /** @param array<string,mixed> $filtros @return array<string,mixed> */
    private function filtrosParaFormulario(array $filtros): array
    {
        $form = [];
        $simples = ['credor_id' => 'credor', 'carteira_id' => 'carteira', 'produto' => 'produto', 'subproduto' => 'subproduto', 'status_contrato' => 'status_contrato', 'codigo_contrato' => 'codigo_contrato'];
        foreach ($simples as $key => $sufixo) {
            if (array_key_exists($key, $filtros)) {
                $form['usar_' . $sufixo] = '1';
                $form[$key] = (string) $filtros[$key];
            }
        }

        $faixas = [
            'data_entrada' => ['data_entrada', 'data_entrada_de', 'data_entrada_ate'],
            'data_atualizacao' => ['data_atualizacao', 'data_atualizacao_de', 'data_atualizacao_ate'],
            'vencimento_original' => ['vencimento_original', 'vencimento_original_de', 'vencimento_original_ate'],
            'dia_vencimento' => ['dia_vencimento', 'dia_vencimento_de', 'dia_vencimento_ate'],
            'aging' => ['aging', 'aging_min', 'aging_max'],
            'valor_original' => ['valor_original', 'valor_original_min', 'valor_original_max'],
            'valor_pago' => ['valor_pago', 'valor_pago_min', 'valor_pago_max'],
            'valor_faltante' => ['valor_faltante', 'valor_faltante_min', 'valor_faltante_max'],
            'quantidade_tentativas' => ['quantidade_tentativas', 'tentativas_min', 'tentativas_max'],
            'data_ultima_tentativa' => ['data_ultima_tentativa', 'ultima_tentativa_de', 'ultima_tentativa_ate'],
            'dias_sem_tentativa' => ['dias_sem_tentativa', 'dias_sem_tentativa_min', 'dias_sem_tentativa_max'],
            'data_ultimo_pagamento' => ['data_ultimo_pagamento', 'ultimo_pagamento_de', 'ultimo_pagamento_ate'],
            'total_parcelas' => ['total_parcelas', 'total_parcelas_min', 'total_parcelas_max'],
        ];
        foreach ($faixas as $key => [$sufixo, $campoMin, $campoMax]) {
            if (!isset($filtros[$key]) || !is_array($filtros[$key])) {
                continue;
            }
            $form['usar_' . $sufixo] = '1';
            $form[$campoMin] = isset($filtros[$key]['min']) ? (string) $filtros[$key]['min'] : '';
            $form[$campoMax] = isset($filtros[$key]['max']) ? (string) $filtros[$key]['max'] : '';
        }
        if (isset($filtros['dias_sem_tentativa']['incluir_nunca'])) {
            $form['dias_sem_incluir_nunca'] = !empty($filtros['dias_sem_tentativa']['incluir_nunca']) ? '1' : '0';
        }
        if (isset($filtros['situacao_tentativas'])) {
            $form['usar_situacao_tentativas'] = '1';
            $form['situacao_tentativas'] = (string) $filtros['situacao_tentativas'];
        }
        if (isset($filtros['ultimo_resultado_id'])) {
            $form['usar_ultimo_resultado'] = '1';
            $form['ultimo_resultado_id'] = (string) $filtros['ultimo_resultado_id'];
        }
        if (isset($filtros['situacao_acordo'])) {
            $form['usar_situacao_acordo'] = '1';
            $form['situacao_acordo'] = (string) $filtros['situacao_acordo'];
        }
        if (isset($filtros['tipo_contato'])) {
            $form['usar_tipo_contato'] = '1';
            $form['tipo_contato'] = (string) $filtros['tipo_contato'];
        }
        if (isset($filtros['uf']) || isset($filtros['cidade'])) {
            $form['usar_localidade'] = '1';
            $form['uf'] = (string) ($filtros['uf'] ?? '');
            $form['cidade'] = (string) ($filtros['cidade'] ?? '');
        }
        return $form;
    }

    /** @param array<string,mixed> $ordenacao @return array<string,mixed> */
    private function ordenacaoParaFormulario(array $ordenacao): array
    {
        $r1 = is_array($ordenacao[0] ?? null) ? $ordenacao[0] : [];
        $r2 = is_array($ordenacao[1] ?? null) ? $ordenacao[1] : [];
        return [
            'ordem_1' => (string) ($r1['campo'] ?? 'aging'),
            'direcao_1' => (string) ($r1['direcao'] ?? 'DESC'),
            'ordem_2' => (string) ($r2['campo'] ?? 'valor_faltante'),
            'direcao_2' => (string) ($r2['direcao'] ?? 'DESC'),
        ];
    }

    private function salvarUsuarios(int $estrategiaId, string $visibilidade, array $usuarios): void
    {
        $delete = $this->pdo->prepare('DELETE FROM estrategia_usuarios WHERE estrategia_id = :estrategia_id');
        $delete->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
        $delete->execute();
        if ($visibilidade !== 'usuarios' || $usuarios === []) {
            return;
        }

        $insert = $this->pdo->prepare('INSERT IGNORE INTO estrategia_usuarios (estrategia_id, usuario_id) SELECT :estrategia_id, id FROM usuarios WHERE id = :usuario_id AND status = \'ativo\'');
        foreach ($usuarios as $usuarioId) {
            $insert->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
            $insert->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
            $insert->execute();
        }
    }

    /** @return list<int> */
    private function listarUsuariosIdsDaEstrategia(int $estrategiaId): array
    {
        if ($estrategiaId <= 0 || !$this->moduloDisponivel()) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT usuario_id FROM estrategia_usuarios WHERE estrategia_id = :id ORDER BY usuario_id');
        $stmt->bindValue('id', $estrategiaId, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function recalcularContadores(int $estrategiaId): void
    {
        $stmt = $this->pdo->prepare("SELECT
                SUM(CASE WHEN status_operacional <> 'removido' THEN 1 ELSE 0 END) AS total,
                SUM(CASE WHEN status_operacional = 'pendente'
                    OR (status_operacional = 'em_atendimento' AND reserva_expira_em IS NOT NULL AND reserva_expira_em < NOW())
                    THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status_operacional = 'em_atendimento'
                    AND (reserva_expira_em IS NULL OR reserva_expira_em >= NOW())
                    THEN 1 ELSE 0 END) AS em_atendimento,
                SUM(CASE WHEN status_operacional = 'trabalhado' THEN 1 ELSE 0 END) AS trabalhados,
                SUM(CASE WHEN status_operacional = 'removido' THEN 1 ELSE 0 END) AS removidos,
                SUM(CASE WHEN status_operacional <> 'removido' THEN COALESCE(valor_faltante_snapshot, saldo_snapshot, 0) ELSE 0 END) AS saldo_total,
                SUM(CASE WHEN status_operacional <> 'removido' THEN COALESCE(valor_pago_snapshot, 0) ELSE 0 END) AS valor_pago_total
            FROM estrategia_contratos WHERE estrategia_id = :id");
        $stmt->bindValue('id', $estrategiaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        $update = $this->pdo->prepare('UPDATE estrategias SET
                total_contratos = :total,
                total_pendentes = :pendentes,
                total_em_atendimento = :em_atendimento,
                total_trabalhados = :trabalhados,
                total_removidos = :removidos,
                saldo_total = :saldo_total,
                valor_pago_total = :valor_pago_total
            WHERE id = :id LIMIT 1');
        $update->bindValue('total', (int) ($row['total'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('pendentes', (int) ($row['pendentes'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('em_atendimento', (int) ($row['em_atendimento'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('trabalhados', (int) ($row['trabalhados'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('removidos', (int) ($row['removidos'] ?? 0), PDO::PARAM_INT);
        $update->bindValue('saldo_total', number_format((float) ($row['saldo_total'] ?? 0), 2, '.', ''));
        $update->bindValue('valor_pago_total', number_format((float) ($row['valor_pago_total'] ?? 0), 2, '.', ''));
        $update->bindValue('id', $estrategiaId, PDO::PARAM_INT);
        $update->execute();
    }

    /** @param array<string,mixed> $payload */
    private function registrarEvento(int $estrategiaId, ?int $itemId, string $tipo, string $descricao, ?int $usuarioId, array $payload = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO estrategia_eventos (
                estrategia_id, estrategia_contrato_id, usuario_id, tipo, descricao, payload_extra
            ) VALUES (
                :estrategia_id, :item_id, :usuario_id, :tipo, :descricao, :payload
            )');
        $stmt->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'item_id', $itemId);
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $stmt->bindValue('tipo', mb_substr($tipo, 0, 80));
        $stmt->bindValue('descricao', mb_substr($descricao, 0, 700));
        $stmt->bindValue('payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();
    }

    /** @param array<string,mixed> $payload */
    private function iniciarExecucao(int $estrategiaId, string $tipo, ?int $usuarioId, array $payload = []): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO estrategia_execucoes (
                estrategia_id, tipo, status, executado_por_usuario_id, payload_extra
            ) VALUES (
                :estrategia_id, :tipo, \'iniciada\', :usuario_id, :payload
            )');
        $stmt->bindValue('estrategia_id', $estrategiaId, PDO::PARAM_INT);
        $stmt->bindValue('tipo', $tipo);
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $stmt->bindValue('payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    private function finalizarExecucao(int $execucaoId, string $status, int $totalEncontrado, int $totalMaterializado, int $totalConflitos, int $totalRemovido, ?string $erro = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE estrategia_execucoes SET
                status = :status,
                total_encontrado = :total_encontrado,
                total_materializado = :total_materializado,
                total_conflitos = :total_conflitos,
                total_removido = :total_removido,
                erro_detalhes = :erro,
                finalizado_em = NOW()
            WHERE id = :id LIMIT 1');
        $stmt->bindValue('status', $status);
        $stmt->bindValue('total_encontrado', $totalEncontrado, PDO::PARAM_INT);
        $stmt->bindValue('total_materializado', $totalMaterializado, PDO::PARAM_INT);
        $stmt->bindValue('total_conflitos', $totalConflitos, PDO::PARAM_INT);
        $stmt->bindValue('total_removido', $totalRemovido, PDO::PARAM_INT);
        $this->bindNullableString($stmt, 'erro', $erro);
        $stmt->bindValue('id', $execucaoId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<string,mixed>|null */
    private function buscarEstrategiaParaAtualizacao(int $estrategiaId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM estrategias WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->bindValue('id', $estrategiaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Lock global (não por estratégia) de propósito: a checagem de
     * conflito em cteCandidatos() ("conflito_ativo") olha para TODAS as
     * outras estratégias, não só a atual, pra impedir que duas
     * estratégias diferentes materializem o mesmo contrato ao mesmo
     * tempo. Um lock por estratégia quebraria essa garantia. O custo é
     * dois supervisores em estratégias sem nenhuma sobreposição real
     * disputarem o mesmo lock — mitigado aumentando o tempo de espera,
     * já que materializar() é uma ação administrativa pouco frequente,
     * não um caminho de alta concorrência.
     */
    private function adquirirLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 30)');
        $stmt->bindValue('lock_name', self::LOCK_NAME);
        $stmt->execute();
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Outra estratégia está sendo processada. Tente novamente após a operação atual terminar.');
        }
    }

    private function liberarLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->bindValue('lock_name', self::LOCK_NAME);
            $stmt->execute();
        } catch (Throwable) {
            // A conexão também libera o lock ao encerrar.
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function sqlVisibilidadeUsuario(int $usuarioId, string $alias, string $prefix): array
    {
        if ($usuarioId <= 0) {
            return [$alias . ".visibilidade = 'todos'", []];
        }
        return ["({$alias}.visibilidade = 'todos' OR EXISTS (
            SELECT 1 FROM estrategia_usuarios eu_{$prefix}
            WHERE eu_{$prefix}.estrategia_id = {$alias}.id AND eu_{$prefix}.usuario_id = :{$prefix}_usuario_id
        ))", [$prefix . '_usuario_id' => $usuarioId]];
    }

    private function bindEstrategiaDados(PDOStatement $stmt, string $nome, ?string $descricao, string $status, int $prioridade, string $visibilidade, array $filtros, array $ordenacao, int $limite, ?int $usuarioId, bool $insert = false): void
    {
        $stmt->bindValue('nome', $nome);
        $this->bindNullableString($stmt, 'descricao', $descricao);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('prioridade', $prioridade, PDO::PARAM_INT);
        $stmt->bindValue('visibilidade', $visibilidade);
        $stmt->bindValue('filtros_json', json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('ordenacao_json', json_encode($ordenacao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        if ($insert) {
            $this->bindNullableInt($stmt, 'usuario_id_2', $usuarioId);
        }
        $stmt->bindValue('status_ativacao', $status);
        $stmt->bindValue('status_pausa', $status);
        $stmt->bindValue('status_encerramento', $status);
    }

    private function adicionarFaixaData(array &$where, array &$params, mixed $faixa, string $expressao, string $prefix): void
    {
        if (!is_array($faixa)) {
            return;
        }
        if (($faixa['min'] ?? null) !== null) {
            $where[] = $expressao . ' >= :' . $prefix . '_min';
            $params[$prefix . '_min'] = $faixa['min'];
        }
        if (($faixa['max'] ?? null) !== null) {
            $where[] = $expressao . ' <= :' . $prefix . '_max';
            $params[$prefix . '_max'] = $faixa['max'];
        }
    }

    private function adicionarFaixaNumero(array &$where, array &$params, mixed $faixa, string $expressao, string $prefix): void
    {
        if (!is_array($faixa)) {
            return;
        }
        if (($faixa['min'] ?? null) !== null) {
            $where[] = $expressao . ' >= :' . $prefix . '_min';
            $params[$prefix . '_min'] = $faixa['min'];
        }
        if (($faixa['max'] ?? null) !== null) {
            $where[] = $expressao . ' <= :' . $prefix . '_max';
            $params[$prefix . '_max'] = $faixa['max'];
        }
    }

    /** @return array{min:?string,max:?string} */
    private function normalizarFaixaData(mixed $min, mixed $max, string $rotulo): array
    {
        $min = $this->normalizarDataOpcional($min, $rotulo);
        $max = $this->normalizarDataOpcional($max, $rotulo);
        if ($min === null && $max === null) {
            throw new InvalidArgumentException('Informe ao menos uma data para o filtro de ' . $rotulo . '.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException('A data inicial de ' . $rotulo . ' não pode ser maior que a data final.');
        }
        return ['min' => $min, 'max' => $max];
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
            throw new InvalidArgumentException('O valor mínimo de ' . $rotulo . ' não pode ser maior que o máximo.');
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
            throw new InvalidArgumentException('O valor mínimo de ' . $rotulo . ' não pode ser maior que o máximo.');
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
            throw new InvalidArgumentException('Data inválida no filtro de ' . $rotulo . '.');
        }
        return $texto;
    }

    private function normalizarTexto(mixed $value, int $limite, bool $obrigatorio): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException('Preencha todos os campos obrigatórios do filtro.');
            }
            return null;
        }
        return mb_substr($texto, 0, $limite);
    }

    private function normalizarInteiro(mixed $value, int $min, int $max, int $default): int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '' || !preg_match('/^-?\d+$/', $texto)) {
            return $default;
        }
        return max($min, min($max, (int) $texto));
    }

    private function normalizarInteiroOpcional(mixed $value, int $min, int $max, string $rotulo): ?int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $texto)) {
            throw new InvalidArgumentException('Valor inteiro inválido em ' . $rotulo . '.');
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
            throw new InvalidArgumentException('Valor monetário inválido em ' . $rotulo . '.');
        }
        $numero = (float) $texto;
        if ($numero < 0) {
            throw new InvalidArgumentException('Valores monetários não podem ser negativos em ' . $rotulo . '.');
        }
        return $numero;
    }

    private function normalizarIdOpcional(mixed $value): ?int
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '' || !ctype_digit($texto)) {
            return null;
        }
        $id = (int) $texto;
        return $id > 0 ? $id : null;
    }

    private function idObrigatorio(mixed $value, string $mensagem): int
    {
        $id = $this->normalizarIdOpcional($value);
        if ($id === null) {
            throw new InvalidArgumentException($mensagem);
        }
        return $id;
    }

    /** @return list<int> */
    private function normalizarListaIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $normalizados = [];
        foreach ($ids as $id) {
            $normalizado = $this->normalizarIdOpcional($id);
            if ($normalizado !== null) {
                $normalizados[$normalizado] = $normalizado;
            }
        }
        return array_values($normalizados);
    }

    private function normalizarStatusEstrategia(string $status, bool $permitirArquivada): string
    {
        $status = strtolower(trim($status));
        if (!array_key_exists($status, self::STATUS_ESTRATEGIA)) {
            throw new InvalidArgumentException('Status de estratégia inválido.');
        }
        if (!$permitirArquivada && $status === 'arquivada') {
            throw new InvalidArgumentException('Use a ação Arquivar para arquivar uma estratégia.');
        }
        return $status;
    }

    /** @return array<string,mixed> */
    private function decodeJsonArray(mixed $json): array
    {
        $decoded = json_decode((string) ($json ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $params */
    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, (string) $value);
            }
        }
    }

    private function bindNullableInt(PDOStatement $stmt, string $key, ?int $value): void
    {
        $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    private function bindNullableString(PDOStatement $stmt, string $key, mixed $value): void
    {
        $texto = $value === null ? null : trim((string) $value);
        if ($texto === '') {
            $texto = null;
        }
        $stmt->bindValue($key, $texto, $texto === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private function bindNullableDecimal(PDOStatement $stmt, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($key, number_format((float) $value, 2, '.', ''));
    }

    private function colunaDisponivel(string $tabela, string $coluna): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :tabela AND column_name = :coluna');
        $stmt->execute(['tabela' => $tabela, 'coluna' => $coluna]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function garantirModulo(): void
    {
        if (!$this->moduloDisponivel()) {
            throw new RuntimeException('Módulo de estratégias indisponível. Execute a migration 083_estrategias_operacionais.sql.');
        }
    }

    public static function statusEstrategiaLabel(?string $status): string
    {
        return self::STATUS_ESTRATEGIA[(string) $status] ?? 'Rascunho';
    }

    public static function statusItemLabel(?string $status): string
    {
        return self::STATUS_ITEM[(string) $status] ?? 'Pendente';
    }
}
