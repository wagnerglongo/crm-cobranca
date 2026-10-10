<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class AcordoOperacionalRepository
{
    public const STATUS_OPERACIONAL = [
        'pendente' => 'Pendente',
        'cumprido' => 'Quitado',
        'quebrado' => 'Quebrado',
        'cancelado' => 'Cancelado',
        'formalizado' => 'Formalizado',
    ];

    /**
     * Status que significam acordo ativo para regra operacional de unicidade por contrato.
     * Um contrato pode ter apenas um acordo pendente/formalizado ao mesmo tempo.
     */
    private const STATUS_OPERACIONAL_ATIVO = ['pendente', 'formalizado'];

    public const STATUS_INTEGRACAO = [
        'nao_enviado' => 'Não enviado',
        'pendente_envio' => 'Pendente de envio',
        'enviado' => 'Enviado',
        'confirmado_credor' => 'Confirmado pelo credor',
        'recusado_credor' => 'Recusado pelo credor',
        'erro' => 'Erro de integração',
    ];

    public const ORIGENS = [
        'manual' => 'Manual',
        'discador' => 'Discador',
        'fila_manual' => 'Fila manual',
        'retorno' => 'Retorno',
    ];

    public const PERIODICIDADES = [
        'unica' => 'Única',
        'semanal' => 'Semanal',
        'quinzenal' => 'Quinzenal',
        'mensal' => 'Mensal',
    ];

    public const FORMAS_PAGAMENTO = [
        'nao_informado' => 'Não informado',
        'boleto' => 'Boleto',
        'pix' => 'Pix',
        'debito_conta' => 'Débito em conta',
        'cartao' => 'Cartão',
        'transferencia' => 'Transferência',
        'outro' => 'Outro',
    ];

    public const STATUS_PARCELA = [
        'pendente' => 'Pendente',
        'vencida' => 'Vencida',
        'paga' => 'Paga',
        'cancelada' => 'Cancelada',
        'renegociada' => 'Renegociada',
    ];

    private ?bool $moduloDisponivel = null;
    private ?bool $carenciaDisponivel = null;

    /** @var array<string,int> */
    private array $carenciaCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Achado 2026-09-29 (relato da operação: "tem acordo e mesmo assim tá simulando"):
     * o Financeiro ACME (works_financeiro_acme.php) só esconde a simulação
     * quando o PRÓPRIO acordo da ACME (contratos.payload_extra.acme_acordo,
     * só atualizado quando o CRM chama consultarAcordo na ACME) está
     * vigente - um acordo criado só no CRM (ex.: botão "Criar acordo" da
     * ficha do cliente, sem integração com o credor) nunca aparece nesse
     * cache e a simulação continua liberada mesmo com um acordo
     * `pendente`/`formalizado` aqui dentro. Reaproveitado no gate.
     */
    public function existeAcordoAtivoNoContrato(int $contratoId): bool
    {
        if (!$this->moduloDisponivel() || $contratoId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT 1 FROM acordos_operacionais
            WHERE contrato_id = :contrato_id AND status_operacional IN ('pendente', 'formalizado') LIMIT 1");
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }

        try {
            $stmtAcordos = $this->pdo->query("SHOW TABLES LIKE 'acordos_operacionais'");
            $stmtParcelas = $this->pdo->query("SHOW TABLES LIKE 'acordos_operacionais_parcelas'");
            $stmtColuna = $this->pdo->query("SHOW COLUMNS FROM acordos_operacionais LIKE 'quantidade_parcelas'");
            $this->moduloDisponivel = $stmtAcordos !== false
                && $stmtAcordos->fetchColumn() !== false
                && $stmtParcelas !== false
                && $stmtParcelas->fetchColumn() !== false
                && $stmtColuna !== false
                && $stmtColuna->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    public function moduloCarenciaDisponivel(): bool
    {
        if ($this->carenciaDisponivel !== null) {
            return $this->carenciaDisponivel;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'acordo_regras_carencia'");
            $this->carenciaDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->carenciaDisponivel = false;
        }

        return $this->carenciaDisponivel;
    }

    public function diasCarenciaPadrao(): int
    {
        $raw = getenv('ACORDO_CARENCIA_PADRAO_DIAS');
        if ($raw === false || !is_numeric($raw)) {
            return 0;
        }

        return max(0, min(365, (int) $raw));
    }

    /** @return list<array<string,mixed>> */
    public function listarRegrasCarencia(): array
    {
        if (!$this->moduloCarenciaDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->query("SELECT
                r.*,
                cr.nome AS credor_nome
            FROM acordo_regras_carencia r
            INNER JOIN credores cr ON cr.id = r.credor_id
            ORDER BY cr.nome ASC,
                CASE WHEN r.produto = '' THEN 0 ELSE 1 END,
                r.produto ASC");

        return $stmt !== false ? ($stmt->fetchAll() ?: []) : [];
    }

    /** @return array{credores:list<array<string,mixed>>,produtos_por_credor:array<int,list<string>>} */
    public function opcoesRegrasCarencia(): array
    {
        $credoresStmt = $this->pdo->query('SELECT id, nome, codigo FROM credores ORDER BY nome ASC');
        $credores = $credoresStmt !== false ? ($credoresStmt->fetchAll() ?: []) : [];
        $produtosPorCredor = [];

        $produtosStmt = $this->pdo->query("SELECT DISTINCT credor_id, TRIM(produto) AS produto
            FROM contratos
            WHERE produto IS NOT NULL
              AND TRIM(produto) <> ''
            ORDER BY credor_id ASC, produto ASC");
        $produtos = $produtosStmt !== false ? ($produtosStmt->fetchAll() ?: []) : [];
        foreach ($produtos as $row) {
            $credorId = (int) ($row['credor_id'] ?? 0);
            $produto = trim((string) ($row['produto'] ?? ''));
            if ($credorId <= 0 || $produto === '') {
                continue;
            }
            $produtosPorCredor[$credorId] ??= [];
            if (!in_array($produto, $produtosPorCredor[$credorId], true)) {
                $produtosPorCredor[$credorId][] = $produto;
            }
        }

        return [
            'credores' => $credores,
            'produtos_por_credor' => $produtosPorCredor,
        ];
    }

    public function salvarRegraCarencia(int $credorId, ?string $produto, int $diasCarencia, bool $ativo = true): void
    {
        if (!$this->moduloCarenciaDisponivel()) {
            throw new InvalidArgumentException('Configuração de carência indisponível. Execute a migration 074_acordo_carencia_credor_produto.sql.');
        }
        if ($credorId <= 0) {
            throw new InvalidArgumentException('Credor inválido para regra de carência.');
        }

        $produto = mb_substr(trim((string) $produto), 0, 180);
        $diasCarencia = max(0, min(365, $diasCarencia));

        $credorStmt = $this->pdo->prepare('SELECT id FROM credores WHERE id = :id LIMIT 1');
        $credorStmt->bindValue('id', $credorId, PDO::PARAM_INT);
        $credorStmt->execute();
        if ($credorStmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Credor não encontrado para regra de carência.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO acordo_regras_carencia (
                credor_id,
                produto,
                dias_carencia,
                ativo
            ) VALUES (
                :credor_id,
                :produto,
                :dias_carencia,
                :ativo
            ) ON DUPLICATE KEY UPDATE
                dias_carencia = VALUES(dias_carencia),
                ativo = VALUES(ativo),
                atualizado_em = NOW()');
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('produto', $produto);
        $stmt->bindValue('dias_carencia', $diasCarencia, PDO::PARAM_INT);
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        $this->carenciaCache = [];
    }

    public function removerRegraCarencia(int $regraId): void
    {
        if (!$this->moduloCarenciaDisponivel()) {
            throw new InvalidArgumentException('Configuração de carência indisponível.');
        }
        if ($regraId <= 0) {
            throw new InvalidArgumentException('Regra de carência inválida.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM acordo_regras_carencia WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $regraId, PDO::PARAM_INT);
        $stmt->execute();
        $this->carenciaCache = [];
    }

    public function diasCarenciaPara(int $credorId, ?string $produto): int
    {
        $produto = mb_substr(trim((string) $produto), 0, 180);
        $cacheKey = $credorId . '|' . mb_strtolower($produto);
        if (array_key_exists($cacheKey, $this->carenciaCache)) {
            return $this->carenciaCache[$cacheKey];
        }

        $dias = $this->diasCarenciaPadrao();
        if ($credorId > 0 && $this->moduloCarenciaDisponivel()) {
            if ($produto !== '') {
                $stmt = $this->pdo->prepare("SELECT dias_carencia
                    FROM acordo_regras_carencia
                    WHERE credor_id = :credor_id
                      AND ativo = 1
                      AND produto IN (:produto_exato, :produto_padrao)
                    ORDER BY CASE WHEN produto = :produto_ordem THEN 0 ELSE 1 END
                    LIMIT 1");
                $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
                $stmt->bindValue('produto_exato', $produto);
                $stmt->bindValue('produto_padrao', '');
                $stmt->bindValue('produto_ordem', $produto);
            } else {
                $stmt = $this->pdo->prepare("SELECT dias_carencia
                    FROM acordo_regras_carencia
                    WHERE credor_id = :credor_id
                      AND ativo = 1
                      AND produto = ''
                    LIMIT 1");
                $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
            }
            $stmt->execute();
            $valor = $stmt->fetchColumn();
            if ($valor !== false && $valor !== null) {
                $dias = max(0, min(365, (int) $valor));
            }
        }

        $this->carenciaCache[$cacheKey] = $dias;
        return $dias;
    }

    /** @return array{dias:int,data_limite:?string,data_limite_label:string,situacao:string,label:string,classe:string,expirada:bool,dias_restantes:?int} */
    public function situacaoCarencia(mixed $dataVencimento, int $credorId, ?string $produto, ?string $statusParcela = null): array
    {
        $dias = $this->diasCarenciaPara($credorId, $produto);
        $data = $this->normalizarDataOpcional($dataVencimento);
        $status = (string) $statusParcela;
        $base = [
            'dias' => $dias,
            'data_limite' => null,
            'data_limite_label' => '-',
            'situacao' => 'sem_vencimento',
            'label' => 'Sem vencimento',
            'classe' => 'neutral',
            'expirada' => false,
            'dias_restantes' => null,
        ];

        if ($status === 'paga') {
            return array_replace($base, ['situacao' => 'paga', 'label' => 'Paga', 'classe' => 'paid']);
        }
        if (in_array($status, ['cancelada', 'renegociada'], true)) {
            return array_replace($base, ['situacao' => $status, 'label' => self::statusParcelaLabel($status), 'classe' => 'neutral']);
        }
        if ($data === null) {
            return $base;
        }

        $vencimento = (new DateTimeImmutable($data))->setTime(0, 0);
        $limite = $vencimento->add(new DateInterval('P' . $dias . 'D'));
        $hoje = (new DateTimeImmutable('today'))->setTime(0, 0);
        $dataLimite = $limite->format('Y-m-d');
        $base['data_limite'] = $dataLimite;
        $base['data_limite_label'] = $limite->format('d/m/Y');

        if ($hoje < $vencimento) {
            $restantes = (int) $hoje->diff($vencimento)->format('%a');
            return array_replace($base, [
                'situacao' => 'futura',
                'label' => 'Vence em ' . $restantes . ' dia(s)',
                'classe' => 'future',
                'dias_restantes' => $restantes,
            ]);
        }
        if ($hoje == $vencimento) {
            return array_replace($base, [
                'situacao' => 'vence_hoje',
                'label' => 'Vence hoje',
                'classe' => 'today',
                'dias_restantes' => $dias,
            ]);
        }
        if ($hoje <= $limite) {
            $restantes = (int) $hoje->diff($limite)->format('%a');
            return array_replace($base, [
                'situacao' => 'em_carencia',
                'label' => $restantes > 0 ? 'Em carência · ' . $restantes . ' dia(s)' : 'Último dia da carência',
                'classe' => 'grace',
                'dias_restantes' => $restantes,
            ]);
        }

        return array_replace($base, [
            'situacao' => 'carencia_expirada',
            'label' => 'Carência expirada',
            'classe' => 'broken',
            'expirada' => true,
            'dias_restantes' => 0,
        ]);
    }

    public static function deadlineCredorParcela(array $parcela): ?string
    {
        $extra = self::decodeJsonObject((string) ($parcela['payload_extra'] ?? ''));
        return self::dataAcmeOuNula($extra['acme']['deadline_date'] ?? null);
    }

    /** @return array{dias:int,data_limite:?string,data_limite_label:string,situacao:string,label:string,classe:string,expirada:bool,dias_restantes:?int} */
    public function situacaoCarenciaParcela(array $parcela, int $credorId, ?string $produto): array
    {
        $status = (string) ($parcela['status'] ?? '');
        $deadline = self::deadlineCredorParcela($parcela);
        if ($deadline === null || in_array($status, ['paga', 'cancelada', 'renegociada'], true)) {
            return $this->situacaoCarencia($parcela['data_vencimento'] ?? null, $credorId, $produto, $status);
        }

        $vencimento = self::dataAcmeOuNula($parcela['data_vencimento'] ?? null);
        $deadlineData = (new DateTimeImmutable($deadline))->setTime(0, 0);
        $hoje = (new DateTimeImmutable('today'))->setTime(0, 0);
        $diasCarencia = 0;
        if ($vencimento !== null) {
            $vencimentoData = (new DateTimeImmutable($vencimento))->setTime(0, 0);
            if ($vencimentoData < $deadlineData) {
                $diasCarencia = (int) $vencimentoData->diff($deadlineData)->format('%a');
            }
            if ($hoje < $vencimentoData) {
                $restantesVencimento = (int) $hoje->diff($vencimentoData)->format('%a');
                return [
                    'dias' => $diasCarencia,
                    'data_limite' => $deadline,
                    'data_limite_label' => $deadlineData->format('d/m/Y'),
                    'situacao' => 'futura',
                    'label' => 'Vence em ' . $restantesVencimento . ' dia(s)',
                    'classe' => 'future',
                    'expirada' => false,
                    'dias_restantes' => $restantesVencimento,
                ];
            }
        }

        if ($hoje <= $deadlineData) {
            $restantes = (int) $hoje->diff($deadlineData)->format('%a');
            return [
                'dias' => $diasCarencia,
                'data_limite' => $deadline,
                'data_limite_label' => $deadlineData->format('d/m/Y'),
                'situacao' => 'em_carencia',
                'label' => $restantes > 0 ? 'Em carência ACME · ' . $restantes . ' dia(s)' : 'Último dia da carência ACME',
                'classe' => 'grace',
                'expirada' => false,
                'dias_restantes' => $restantes,
            ];
        }

        return [
            'dias' => $diasCarencia,
            'data_limite' => $deadline,
            'data_limite_label' => $deadlineData->format('d/m/Y'),
            'situacao' => 'carencia_expirada',
            'label' => 'Carência ACME expirada',
            'classe' => 'broken',
            'expirada' => true,
            'dias_restantes' => 0,
        ];
    }

    /**
     * Interpreta o retorno oficial de GET /v1/devedores/{CPF}/contratos/{CONTRATO}/acordo.
     *
     * A situação da dívida tem precedência sobre textos antigos do acordo. Assim,
     * Debt.StatusDescription=Liquidada e pagamentos confirmados nunca podem ser
     * rebaixados para quebrado apenas porque a carência local expirou.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public static function interpretarAcordoAcme(array $payload): ?array
    {
        $payload = self::normalizarPayloadAcordoAcme($payload);
        $agreement = is_array($payload['Agreement'] ?? null) ? $payload['Agreement'] : [];
        $debt = is_array($payload['Debt'] ?? null) ? $payload['Debt'] : [];
        $agreementId = trim((string) ($agreement['AgreementID'] ?? ''));
        $debtStatus = trim((string) ($debt['StatusDescription'] ?? ''));
        $agreementStatus = trim((string) ($agreement['AgreementStatus'] ?? ''));
        $debtLiquidada = self::textoAcmeContem($debtStatus, ['liquid', 'quitad', 'pago', 'paga']);
        $hasAgreementHistory = (bool) ($payload['HasAgreementHistory'] ?? false);
        $temAcordoDetalhado = $agreementId !== '';
        if (!$temAcordoDetalhado && !$debtLiquidada) {
            return null;
        }

        $installmentsRaw = $agreement['ListInstallments'] ?? [];
        $installmentsRaw = is_array($installmentsRaw)
            ? array_values(array_filter($installmentsRaw, static fn (mixed $item): bool => is_array($item)))
            : [];

        $totalRaw = trim((string) ($agreement['TotalInstalments'] ?? ''));
        $pagasDeclaradas = 0;
        $totalDeclarado = count($installmentsRaw);
        if (preg_match('/(\d+)\s*\/\s*(\d+)/', $totalRaw, $m)) {
            $pagasDeclaradas = max(0, (int) $m[1]);
            $totalDeclarado = max(1, (int) $m[2]);
        } elseif (preg_match('/^\d+$/', $totalRaw)) {
            $totalDeclarado = max(1, (int) $totalRaw);
        }

        $hoje = (new DateTimeImmutable('today'))->setTime(0, 0);
        $parcelas = [];
        $numerosUsados = [];
        $pagasConfirmadas = 0;
        $deadlineExpirada = false;
        $primeiraDeadlineExpirada = null;
        $primeiraDeadlineAberta = null;
        $temParcelaEmCarencia = false;

        foreach ($installmentsRaw as $index => $item) {
            $numero = (int) ($item['InstallmentNumber'] ?? 0);
            if ($numero <= 0 || isset($numerosUsados[$numero])) {
                $numero = $index + 1;
                while (isset($numerosUsados[$numero])) {
                    $numero++;
                }
            }
            $numerosUsados[$numero] = true;

            $statusRaw = trim((string) ($item['Status'] ?? ''));
            $vencimento = self::dataAcmeOuNula($item['InstallmentDate'] ?? null);
            $pagamento = self::dataAcmeOuNula($item['PaymentDate'] ?? null);
            $deadline = self::dataAcmeOuNula($item['DeadlineDate'] ?? null);
            $deadlineVencida = false;
            if ($deadline !== null) {
                try {
                    $deadlineVencida = (new DateTimeImmutable($deadline))->setTime(0, 0) < $hoje;
                } catch (Throwable) {
                    $deadlineVencida = false;
                }
            }

            $parcelaPaga = $debtLiquidada
                || $pagamento !== null
                || self::textoAcmeContem($statusRaw, ['pago', 'paga', 'liquid', 'quitad']);
            $statusOperacional = 'pendente';
            $statusLabel = 'Aberta';
            $statusClasse = 'aberta';
            $statusMotivo = 'Parcela aberta segundo o retorno da ACME.';

            if ($parcelaPaga) {
                $statusOperacional = 'paga';
                $statusLabel = 'Paga';
                $statusClasse = 'pago';
                $statusMotivo = $pagamento !== null
                    ? 'Pagamento confirmado pela ACME em ' . (new DateTimeImmutable($pagamento))->format('d/m/Y') . '.'
                    : 'Pagamento confirmado pela situação liquidada da dívida na ACME.';
                $pagasConfirmadas++;
            } elseif (self::textoAcmeContem($statusRaw, ['cancel'])) {
                $statusOperacional = 'cancelada';
                $statusLabel = 'Cancelada';
                $statusClasse = 'indisponivel';
                $statusMotivo = 'Parcela cancelada segundo a ACME.';
            } elseif (self::textoAcmeContem($statusRaw, ['renegoc'])) {
                $statusOperacional = 'renegociada';
                $statusLabel = 'Renegociada';
                $statusClasse = 'indisponivel';
                $statusMotivo = 'Parcela renegociada segundo a ACME.';
            } else {
                $vencidaPorData = false;
                if ($vencimento !== null) {
                    try {
                        $vencidaPorData = (new DateTimeImmutable($vencimento))->setTime(0, 0) < $hoje;
                    } catch (Throwable) {
                        $vencidaPorData = false;
                    }
                }
                if ($deadline !== null && !$deadlineVencida
                    && ($primeiraDeadlineAberta === null || $deadline < $primeiraDeadlineAberta)) {
                    $primeiraDeadlineAberta = $deadline;
                }
                if ($deadlineVencida) {
                    $statusOperacional = 'vencida';
                    $statusLabel = 'Carência expirada';
                    $statusClasse = 'vencida';
                    $statusMotivo = 'Prazo de quebra informado pela ACME expirou em ' . (new DateTimeImmutable($deadline))->format('d/m/Y') . '.';
                    $deadlineExpirada = true;
                    $primeiraDeadlineExpirada ??= $deadline;
                } elseif ($vencidaPorData || self::textoAcmeContem($statusRaw, ['vencid', 'atras'])) {
                    $statusOperacional = 'vencida';
                    $statusClasse = 'vencida';
                    if ($deadline !== null) {
                        $statusLabel = 'Em carência';
                        $statusMotivo = 'Parcela vencida; prazo de quebra ACME até ' . (new DateTimeImmutable($deadline))->format('d/m/Y') . '.';
                        $temParcelaEmCarencia = true;
                    } else {
                        $statusLabel = 'Vencida';
                        $statusMotivo = 'Parcela vencida sem DeadlineDate informado pela ACME.';
                    }
                } elseif ($statusRaw === '' || self::textoAcmeContem($statusRaw, ['aguard'])) {
                    $statusLabel = 'Aguardando liberação';
                    $statusClasse = 'indisponivel';
                    $statusMotivo = 'Parcela ainda não liberada/registrada pela ACME.';
                }
            }

            $valor = isset($item['Amount']) && is_numeric($item['Amount']) ? (float) $item['Amount'] : null;
            $parcelas[] = [
                'numero' => $numero,
                'descricao' => trim((string) ($item['InstallmentDescription'] ?? ($numero . '/' . max(1, $totalDeclarado)))),
                'valor' => $valor,
                'vencimento' => $vencimento,
                'pagamento' => $pagamento,
                'deadline' => $deadline,
                'status' => $statusRaw !== '' ? $statusRaw : 'Não informado',
                'status_operacional' => $statusOperacional,
                'status_label' => $statusLabel,
                'status_classe' => $statusClasse,
                'status_motivo' => $statusMotivo,
                'deadline_expirada' => $deadlineVencida,
                'installment_id' => (int) ($item['InstallmentID'] ?? 0) > 0 ? (int) $item['InstallmentID'] : null,
                'valor_pago' => $parcelaPaga ? $valor : null,
                'raw' => $item,
            ];
        }

        $total = max(1, $totalDeclarado, count($parcelas));
        $pagasReconhecidas = max($pagasDeclaradas, $pagasConfirmadas);
        $parcelasCompletas = count($parcelas) >= $total;
        $todasPagas = $total > 0 && ($pagasReconhecidas >= $total || ($parcelasCompletas && $pagasConfirmadas >= $total));

        $statusOperacional = 'formalizado';
        $statusLabel = 'Acordo ativo';
        $statusMotivo = 'Acordo confirmado pela ACME.';

        if ($debtLiquidada) {
            $statusOperacional = 'cumprido';
            $statusLabel = 'Liquidado / pago';
            $statusMotivo = 'A dívida está liquidada na ACME (Debt.StatusDescription: ' . ($debtStatus !== '' ? $debtStatus : 'Liquidada') . ').';
        } elseif (self::textoAcmeContem($agreementStatus, ['liquid', 'quitad', 'pago', 'paga', 'cumpr', 'concluid', 'finaliz'])) {
            $statusOperacional = 'cumprido';
            $statusLabel = 'Quitado';
            $statusMotivo = 'O status do acordo na ACME indica pagamento/conclusão: ' . $agreementStatus . '.';
        } elseif ($todasPagas) {
            $statusOperacional = 'cumprido';
            $statusLabel = 'Quitado';
            $statusMotivo = 'Todas as parcelas foram informadas como pagas pela ACME.';
        } elseif (self::textoAcmeContem($agreementStatus, ['cancel'])) {
            $statusOperacional = 'cancelado';
            $statusLabel = 'Cancelado';
            $statusMotivo = 'A ACME informou o acordo como cancelado.';
        } elseif (
            self::textoAcmeContem($agreementStatus, ['quebr', 'rompid', 'inadimpl'])
            || $deadlineExpirada
            || (self::textoAcmeContem($agreementStatus, ['expir']) && $primeiraDeadlineAberta === null)
        ) {
            $statusOperacional = 'quebrado';
            $statusLabel = 'Quebrado';
            $statusMotivo = $deadlineExpirada && $primeiraDeadlineExpirada !== null
                ? 'O prazo de quebra informado pela ACME expirou em ' . (new DateTimeImmutable($primeiraDeadlineExpirada))->format('d/m/Y') . ' sem pagamento confirmado.'
                : 'O status do acordo na ACME indica quebra/expiração: ' . ($agreementStatus !== '' ? $agreementStatus : 'não informado') . '.';
        } elseif ($temParcelaEmCarencia && $primeiraDeadlineAberta !== null) {
            $statusLabel = 'Em carência';
            $statusMotivo = 'Há parcela vencida, mas o prazo de quebra informado pela ACME termina em '
                . (new DateTimeImmutable($primeiraDeadlineAberta))->format('d/m/Y') . '.';
        }

        $valorAcordo = isset($agreement['AgreementAmount']) && is_numeric($agreement['AgreementAmount'])
            ? (float) $agreement['AgreementAmount']
            : null;
        if (($valorAcordo === null || $valorAcordo <= 0) && $parcelas !== []) {
            $soma = array_sum(array_map(static fn (array $parcela): float => (float) ($parcela['valor'] ?? 0), $parcelas));
            $valorAcordo = $soma > 0 ? round($soma, 2) : $valorAcordo;
        }

        return [
            'id' => $agreementId,
            'tem_acordo_detalhado' => $temAcordoDetalhado,
            'has_agreement_history' => $hasAgreementHistory,
            'status' => $agreementStatus,
            'status_operacional' => $statusOperacional,
            'status_label' => $statusLabel,
            'status_motivo' => $statusMotivo,
            'impede_nova_proposta' => in_array($statusOperacional, ['pendente', 'formalizado', 'cumprido'], true),
            'divida_liquidada' => $debtLiquidada,
            'valor_acordo' => $valorAcordo,
            'data_acordo' => self::dataAcmeOuNula($agreement['AgreementDate'] ?? null),
            'total_parcelas' => $total,
            'parcelas_pagas' => $statusOperacional === 'cumprido' && $debtLiquidada ? $total : $pagasReconhecidas,
            'total_instalments_raw' => $totalRaw,
            'payment_type_tag' => (string) ($agreement['PaymentTypeTag'] ?? ''),
            'negotiation_notes' => (string) ($agreement['NegotiationNotes'] ?? ''),
            'is_accepted' => (bool) ($agreement['IsAccepted'] ?? false),
            'debt' => [
                'contract' => (string) ($debt['Contract'] ?? ''),
                'amount' => isset($debt['DebtAmount']) && is_numeric($debt['DebtAmount']) ? (float) $debt['DebtAmount'] : null,
                'status' => $debtStatus,
                'owner_description' => (string) ($debt['OwnerDescription'] ?? ''),
                'debt_date' => self::dataAcmeOuNula($debt['DebtDate'] ?? null),
                'more_detail' => (string) ($debt['MoreDetail'] ?? ''),
                'negotiation_type' => (string) ($debt['NegotiationType'] ?? ''),
                'email' => (string) ($debt['Email'] ?? ''),
                'info_notes' => (string) ($debt['InfoNotes'] ?? ''),
            ],
            'parcelas' => $parcelas,
            'raw' => $payload,
        ];
    }

    /**
     * Atualiza o mesmo acordo e suas parcelas com o retorno oficial da ACME.
     * Também corrige acordos que tenham sido quebrados localmente antes de a API
     * confirmar liquidação/pagamento.
     *
     * @param array<string,mixed> $contrato
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function sincronizarAcordoAcme(array $contrato, array $payload): array
    {
        if (!$this->moduloDisponivel()) {
            return ['acordo_crm' => 'modulo_acordos_indisponivel'];
        }

        $info = self::interpretarAcordoAcme($payload);
        if ($info === null) {
            return ['acordo_crm' => 'sem_acordo_retornado'];
        }

        $contratoId = (int) ($contrato['contrato_id'] ?? $contrato['id'] ?? 0);
        $pessoaId = (int) ($contrato['pessoa_id'] ?? 0);
        if ($contratoId <= 0 || $pessoaId <= 0) {
            return ['acordo_crm' => 'contrato_invalido'];
        }

        $temAcordoDetalhado = (bool) ($info['tem_acordo_detalhado'] ?? false);
        $valorAcordo = $temAcordoDetalhado ? (float) ($info['valor_acordo'] ?? 0) : 0.0;
        if ($temAcordoDetalhado && $valorAcordo <= 0) {
            $valorAcordo = (float) ($info['debt']['amount'] ?? 0);
        }

        $this->pdo->beginTransaction();
        try {
            $acordo = $this->localizarAcordoParaSincronizacaoAcme($contratoId, $info);
            $criado = false;
            if ($acordo === null) {
                if (!$temAcordoDetalhado) {
                    $this->pdo->rollBack();
                    return [
                        'acordo_crm' => 'sem_acordo_local_para_conciliar',
                        'status_operacional' => (string) $info['status_operacional'],
                        'status_motivo' => (string) $info['status_motivo'],
                    ];
                }
                if ($valorAcordo <= 0) {
                    $this->pdo->rollBack();
                    return [
                        'acordo_crm' => 'nao_registrado_sem_valor',
                        'acordo_credor_id' => (string) $info['id'],
                        'status_operacional' => (string) $info['status_operacional'],
                    ];
                }

                $primeiraParcela = $info['parcelas'][0] ?? [];
                $dataPrimeira = (string) ($primeiraParcela['vencimento'] ?? $info['data_acordo'] ?? '');
                if ($dataPrimeira === '') {
                    $dataPrimeira = (new DateTimeImmutable('today'))->format('Y-m-d');
                }
                $acordoId = $this->registrar($pessoaId, [
                    'contrato_id' => $contratoId,
                    'valor_acordo' => number_format($valorAcordo, 2, ',', '.'),
                    'quantidade_parcelas' => max(1, (int) ($info['total_parcelas'] ?? 1)),
                    'data_primeira_parcela' => $dataPrimeira,
                    'periodicidade' => (int) ($info['total_parcelas'] ?? 1) === 1 ? 'unica' : 'mensal',
                    'forma_pagamento' => self::formaPagamentoAcme((string) ($info['payment_type_tag'] ?? '')),
                    'status_operacional' => (string) $info['status_operacional'],
                    'status_integracao' => 'confirmado_credor',
                    'observacao' => 'Acordo identificado e conciliado automaticamente pela API ACME. AgreementID: ' . (string) $info['id'],
                    'operador_nome' => 'Não definido',
                    'origem' => 'manual',
                    'origem_tela' => 'operacao/works_financeiro_acme.php',
                    // Achado (2026-09-29): esse acordo "veio de
                    // fora" - o CRM só ficou sabendo dele agora, mas ele foi
                    // gerado na ACME na data abaixo (AgreementDate). Sem
                    // isso, criado_em virava "hoje" (quando o CRM
                    // descobriu), não a data real do acordo.
                    'criado_em' => $info['data_acordo'] ?? null,
                    'payload_extra_adicional' => [
                        'financeiro_perfil' => 'acme',
                        'origem_externa_credor' => true,
                        'credor_api' => 'ACME',
                        'agreement_id' => (string) $info['id'],
                    ],
                ]);
                // Achado 2026-09-29: usar um stand-in com payload_extra=null
                // aqui fazia o bloco de UPDATE logo abaixo decodificar "vazio"
                // e reescrever payload_extra do zero, jogando fora tudo que
                // registrar() acabou de gravar (origem_tela,
                // origem_externa_credor etc.) - por isso essas marcas nunca
                // apareciam num acordo recém-sincronizado, mesmo tendo sido
                // passadas. Rebusca a linha real recém-criada.
                $acordoRecemCriado = $this->pdo->prepare('SELECT id, status_operacional, payload_extra FROM acordos_operacionais WHERE id = :id LIMIT 1');
                $acordoRecemCriado->bindValue('id', $acordoId, PDO::PARAM_INT);
                $acordoRecemCriado->execute();
                $acordo = $acordoRecemCriado->fetch() ?: ['id' => $acordoId, 'status_operacional' => null, 'payload_extra' => null];
                $criado = true;
            }

            $acordoId = (int) $acordo['id'];
            $statusAnterior = (string) ($acordo['status_operacional'] ?? '');
            $payloadExtra = self::decodeJsonObject((string) ($acordo['payload_extra'] ?? ''));
            $payloadExtra['financeiro_perfil'] = 'acme';
            $payloadExtra['credor_api'] = 'ACME';
            if (trim((string) ($info['id'] ?? '')) !== '') {
                $payloadExtra['agreement_id'] = (string) $info['id'];
            }
            if (trim((string) ($info['status'] ?? '')) !== '') {
                $payloadExtra['agreement_status'] = (string) $info['status'];
            }
            $payloadExtra['debt_status'] = (string) ($info['debt']['status'] ?? '');
            $payloadExtra['status_conciliado'] = (string) $info['status_operacional'];
            $payloadExtra['status_conciliado_motivo'] = (string) $info['status_motivo'];
            $payloadExtra['sincronizado_acme_em'] = date('c');
            $snapshotCompacto = $info;
            unset($snapshotCompacto['raw']);
            foreach ($snapshotCompacto['parcelas'] as &$parcelaSnapshot) {
                if (is_array($parcelaSnapshot)) {
                    unset($parcelaSnapshot['raw']);
                }
            }
            unset($parcelaSnapshot);
            if ($temAcordoDetalhado) {
                $payloadExtra['parcelas_acme'] = $snapshotCompacto['parcelas'];
                $payloadExtra['acme_agreement_snapshot'] = $snapshotCompacto;
            } else {
                $payloadExtra['acme_ultima_liquidacao_snapshot'] = $snapshotCompacto;
            }

            $primeiraParcela = $info['parcelas'][0] ?? [];
            $dataPrimeira = (string) ($primeiraParcela['vencimento'] ?? $info['data_acordo'] ?? '');
            if ($dataPrimeira === '') {
                $dataPrimeira = (string) ($acordo['data_primeira_parcela'] ?? date('Y-m-d'));
            }

            $camposUpdate = [
                'codigo_acordo_credor = COALESCE(NULLIF(:codigo, \'\'), codigo_acordo_credor)',
                'valor_acordo = CASE WHEN :valor_condicao > 0 THEN :valor_atualizado ELSE valor_acordo END',
                'status_operacional = :status_operacional',
                'status_integracao = \'confirmado_credor\'',
                'confirmado_credor_em = COALESCE(confirmado_credor_em, NOW())',
                'status_atualizado_em = CASE WHEN status_operacional <> :status_comparacao THEN NOW() ELSE status_atualizado_em END',
                'payload_extra = :payload_extra',
            ];
            if ($temAcordoDetalhado) {
                $camposUpdate[] = 'quantidade_parcelas = :quantidade';
                $camposUpdate[] = 'data_primeira_parcela = :data_primeira';
                $camposUpdate[] = 'data_promessa_pagamento = :data_promessa';
                $camposUpdate[] = 'forma_pagamento = :forma_pagamento';
            }
            $update = $this->pdo->prepare('UPDATE acordos_operacionais SET '
                . implode(', ', $camposUpdate)
                . ' WHERE id = :id LIMIT 1');
            $update->bindValue('codigo', (string) $info['id']);
            $update->bindValue('valor_condicao', number_format(max(0, $valorAcordo), 2, '.', ''));
            $update->bindValue('valor_atualizado', number_format(max(0, $valorAcordo), 2, '.', ''));
            if ($temAcordoDetalhado) {
                $update->bindValue('quantidade', max(1, (int) ($info['total_parcelas'] ?? 1)), PDO::PARAM_INT);
                $update->bindValue('data_primeira', $dataPrimeira);
                $update->bindValue('data_promessa', $dataPrimeira);
                $update->bindValue('forma_pagamento', self::formaPagamentoAcme((string) ($info['payment_type_tag'] ?? '')));
            }
            $update->bindValue('status_operacional', (string) $info['status_operacional']);
            $update->bindValue('status_comparacao', (string) $info['status_operacional']);
            $update->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $update->bindValue('id', $acordoId, PDO::PARAM_INT);
            $update->execute();

            $parcelasAlteradas = $this->sincronizarParcelasAcme($acordoId, $info);

            if ($statusAnterior !== (string) $info['status_operacional']) {
                $this->registrarAuditoria(
                    $acordoId,
                    null,
                    'status_acordo_conciliado_acme',
                    (string) $info['status_motivo'],
                    ['status_operacional' => $statusAnterior],
                    [
                        'status_operacional' => (string) $info['status_operacional'],
                        'agreement_id' => (string) $info['id'],
                        'agreement_status' => (string) $info['status'],
                        'debt_status' => (string) ($info['debt']['status'] ?? ''),
                    ],
                    null,
                    'acme_api'
                );
            }

            $this->pdo->commit();

            try {
                $carteira = new CarteiraAcordosRepository($this->pdo, $this);
                if ($carteira->moduloDisponivel()) {
                    $carteira->atualizarCarteiraGeral(1, $acordoId);
                }
            } catch (Throwable) {
                // A conciliação oficial não deve falhar por indisponibilidade do acompanhamento.
            }

            return [
                'acordo_crm' => $criado ? 'registrado' : 'atualizado',
                'acordo_crm_id' => $acordoId,
                'acordo_credor_id' => (string) $info['id'],
                'status_anterior' => $statusAnterior,
                'status_operacional' => (string) $info['status_operacional'],
                'status_motivo' => (string) $info['status_motivo'],
                'parcelas_atualizadas' => $parcelasAlteradas,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['acordo_crm' => 'nao_sincronizado', 'acordo_crm_erro' => $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $info @return array<string,mixed>|null */
    private function localizarAcordoParaSincronizacaoAcme(int $contratoId, array $info): ?array
    {
        $stmt = $this->pdo->prepare('SELECT *
            FROM acordos_operacionais
            WHERE contrato_id = :contrato_id
            ORDER BY id DESC
            LIMIT 30
            FOR UPDATE');
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        $candidatos = $stmt->fetchAll() ?: [];
        if ($candidatos === []) {
            return null;
        }

        $agreementId = trim((string) ($info['id'] ?? ''));
        if ($agreementId !== '') {
            foreach ($candidatos as $candidato) {
                if (trim((string) ($candidato['codigo_acordo_credor'] ?? '')) === $agreementId) {
                    return $candidato;
                }
                $extra = self::decodeJsonObject((string) ($candidato['payload_extra'] ?? ''));
                $ids = [
                    (string) ($extra['agreement_id'] ?? ''),
                    (string) ($extra['acme_agreement_snapshot']['id'] ?? ''),
                    (string) ($extra['acme_agreement_snapshot']['agreement_id'] ?? ''),
                ];
                if (in_array($agreementId, $ids, true)) {
                    return $candidato;
                }
            }
        }

        // Pela regra de negócio só existe um acordo ativo por contrato. Quando a
        // ACME devolve o AgreementID após a criação no Pactua, esse é o registro a
        // ser enriquecido, preservando operador e autoria.
        foreach ($candidatos as $candidato) {
            if (in_array((string) ($candidato['status_operacional'] ?? ''), self::STATUS_OPERACIONAL_ATIVO, true)) {
                return $candidato;
            }
        }

        // Depois da liquidação, a ACME pode manter HasAgreementHistory=true e não
        // devolver mais o bloco Agreement. Nesse caso, concilia o acordo ACME mais
        // recente do contrato sem apagar código, valor ou parcelamento históricos.
        if (!(bool) ($info['tem_acordo_detalhado'] ?? false) && (bool) ($info['divida_liquidada'] ?? false)) {
            foreach ($candidatos as $candidato) {
                $extra = self::decodeJsonObject((string) ($candidato['payload_extra'] ?? ''));
                $perfilAcme = mb_strtolower((string) ($extra['financeiro_perfil'] ?? '')) === 'acme'
                    || mb_strtoupper((string) ($extra['credor_api'] ?? '')) === 'ACME'
                    || ($extra['origem_externa_credor'] ?? false) === true
                    || trim((string) ($candidato['codigo_acordo_credor'] ?? '')) !== '';
                $integrado = in_array((string) ($candidato['status_integracao'] ?? ''), ['enviado', 'confirmado_credor'], true);
                if ($perfilAcme || $integrado) {
                    return $candidato;
                }
            }
        }

        // Repara o caso antigo em que o mesmo acordo foi quebrado localmente antes
        // da confirmação da API. Exige evidência de integração ACME e compatibilidade
        // de valor/parcelamento para não reaproveitar um acordo histórico diferente.
        $valorApi = (float) ($info['valor_acordo'] ?? 0);
        $totalApi = max(1, (int) ($info['total_parcelas'] ?? 1));
        foreach ($candidatos as $candidato) {
            $extra = self::decodeJsonObject((string) ($candidato['payload_extra'] ?? ''));
            $perfilAcme = mb_strtolower((string) ($extra['financeiro_perfil'] ?? '')) === 'acme'
                || mb_strtoupper((string) ($extra['credor_api'] ?? '')) === 'ACME'
                || ($extra['origem_externa_credor'] ?? false) === true;
            $integrado = in_array((string) ($candidato['status_integracao'] ?? ''), ['enviado', 'confirmado_credor'], true);
            $valorLocal = (float) ($candidato['valor_acordo'] ?? 0);
            $totalLocal = max(1, (int) ($candidato['quantidade_parcelas'] ?? 1));
            $valorCompativel = $valorApi <= 0 || $valorLocal <= 0 || abs($valorLocal - $valorApi) <= 0.02;
            if (($perfilAcme || $integrado) && $valorCompativel && $totalLocal === $totalApi) {
                return $candidato;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $info */
    private function sincronizarParcelasAcme(int $acordoId, array $info): int
    {
        $parcelas = is_array($info['parcelas'] ?? null) ? $info['parcelas'] : [];
        $total = max(1, (int) ($info['total_parcelas'] ?? (count($parcelas) > 0 ? count($parcelas) : 1)));
        $valorTotal = max(0.0, (float) ($info['valor_acordo'] ?? 0));
        $valorFallback = $valorTotal > 0 ? round($valorTotal / $total, 2) : 0.0;
        $dataFallback = (string) ($info['data_acordo'] ?? date('Y-m-d'));
        $alteradas = 0;

        $upsert = $this->pdo->prepare('INSERT INTO acordos_operacionais_parcelas (
                acordo_operacional_id,
                numero_parcela,
                tipo,
                data_vencimento,
                valor_parcela,
                status,
                codigo_boleto,
                data_pagamento,
                valor_pago,
                observacao,
                payload_extra
            ) VALUES (
                :acordo_id,
                :numero,
                \'parcela\',
                :vencimento,
                :valor,
                :status,
                :codigo_boleto,
                :data_pagamento,
                :valor_pago,
                :observacao,
                :payload_extra
            ) ON DUPLICATE KEY UPDATE
                data_vencimento = VALUES(data_vencimento),
                valor_parcela = CASE WHEN VALUES(valor_parcela) > 0 THEN VALUES(valor_parcela) ELSE valor_parcela END,
                status = VALUES(status),
                codigo_boleto = COALESCE(NULLIF(VALUES(codigo_boleto), \'\'), codigo_boleto),
                data_pagamento = VALUES(data_pagamento),
                valor_pago = VALUES(valor_pago),
                observacao = COALESCE(NULLIF(observacao, \'\'), VALUES(observacao)),
                payload_extra = JSON_MERGE_PATCH(COALESCE(payload_extra, JSON_OBJECT()), VALUES(payload_extra))');

        foreach ($parcelas as $parcela) {
            if (!is_array($parcela)) {
                continue;
            }
            $numero = max(1, (int) ($parcela['numero'] ?? 0));
            $vencimento = (string) ($parcela['vencimento'] ?? '');
            if ($vencimento === '') {
                $vencimento = $dataFallback;
            }
            $valor = isset($parcela['valor']) && is_numeric($parcela['valor']) ? (float) $parcela['valor'] : $valorFallback;
            $status = (string) ($parcela['status_operacional'] ?? 'pendente');
            if (!array_key_exists($status, self::STATUS_PARCELA)) {
                $status = 'pendente';
            }
            $dataPagamento = $status === 'paga' ? ($parcela['pagamento'] ?? null) : null;
            $valorPago = $status === 'paga' ? ($parcela['valor_pago'] ?? $valor) : null;
            $payloadParcela = [
                'origem_api' => 'acme',
                'acme' => [
                    'agreement_id' => (string) ($info['id'] ?? ''),
                    'installment_id' => $parcela['installment_id'] ?? null,
                    'deadline_date' => $parcela['deadline'] ?? null,
                    'status_raw' => (string) ($parcela['status'] ?? ''),
                    'status_conciliado' => $status,
                    'status_motivo' => (string) ($parcela['status_motivo'] ?? ''),
                    'sincronizado_em' => date('c'),
                    'raw' => $parcela['raw'] ?? [],
                ],
            ];

            $upsert->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $upsert->bindValue('numero', $numero, PDO::PARAM_INT);
            $upsert->bindValue('vencimento', $vencimento);
            $upsert->bindValue('valor', number_format(max(0, $valor), 2, '.', ''));
            $upsert->bindValue('status', $status);
            $this->bindNullableString($upsert, 'codigo_boleto', !empty($parcela['installment_id']) ? (string) $parcela['installment_id'] : null);
            $this->bindNullableString($upsert, 'data_pagamento', is_string($dataPagamento) && $dataPagamento !== '' ? $dataPagamento : null);
            $this->bindNullableDecimal($upsert, 'valor_pago', is_numeric($valorPago) ? (float) $valorPago : null);
            $this->bindNullableString($upsert, 'observacao', (string) ($parcela['status_motivo'] ?? 'Sincronizada com a ACME.'));
            $upsert->bindValue('payload_extra', json_encode($payloadParcela, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $upsert->execute();
            $alteradas++;
        }

        $statusAcordo = (string) ($info['status_operacional'] ?? 'formalizado');
        if ($statusAcordo === 'cumprido') {
            $stmt = $this->pdo->prepare("UPDATE acordos_operacionais_parcelas
                SET status = 'paga',
                    valor_pago = COALESCE(valor_pago, valor_parcela),
                    observacao = NULLIF(CONCAT_WS('\n', NULLIF(observacao, ''), :observacao), '')
                WHERE acordo_operacional_id = :acordo_id
                  AND status <> 'paga'");
            $stmt->bindValue('observacao', 'Pagamento/liquidação confirmada pela API ACME.');
            $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $stmt->execute();
            $alteradas += $stmt->rowCount();
        } elseif ($statusAcordo === 'cancelado') {
            $stmt = $this->pdo->prepare("UPDATE acordos_operacionais_parcelas
                SET status = 'cancelada'
                WHERE acordo_operacional_id = :acordo_id
                  AND status IN ('pendente','vencida')");
            $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $stmt->execute();
            $alteradas += $stmt->rowCount();
        } elseif ($statusAcordo === 'quebrado') {
            $stmt = $this->pdo->prepare("UPDATE acordos_operacionais_parcelas
                SET status = 'vencida'
                WHERE acordo_operacional_id = :acordo_id
                  AND status = 'pendente'
                  AND data_vencimento < CURRENT_DATE");
            $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $stmt->execute();
            $alteradas += $stmt->rowCount();
        }

        return $alteradas;
    }

    /** @return array<string,mixed> */
    private static function normalizarPayloadAcordoAcme(array $payload): array
    {
        if (is_array($payload['Agreement'] ?? null) || is_array($payload['Debt'] ?? null)) {
            return $payload;
        }
        if (is_array($payload['raw'] ?? null)) {
            $raw = $payload['raw'];
            if (is_array($raw['Agreement'] ?? null) || is_array($raw['Debt'] ?? null)) {
                return $raw;
            }
        }
        if (is_array($payload['agreement'] ?? null) || is_array($payload['debt'] ?? null)) {
            return [
                'HasAgreementHistory' => (bool) ($payload['has_agreement_history'] ?? false),
                'Agreement' => is_array($payload['agreement'] ?? null) ? $payload['agreement'] : [],
                'Debt' => is_array($payload['debt'] ?? null) ? $payload['debt'] : [],
            ];
        }
        if (is_array($payload['acme_acordo'] ?? null)) {
            return self::normalizarPayloadAcordoAcme($payload['acme_acordo']);
        }
        return $payload;
    }

    private static function dataAcmeOuNula(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || str_starts_with($raw, '0001-01-01') || str_starts_with($raw, '0000-00-00')) {
            return null;
        }
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (Throwable) {
            $data = substr($raw, 0, 10);
            return $data !== '' ? $data : null;
        }
    }

    /** @param list<string> $agulhas */
    private static function textoAcmeContem(string $texto, array $agulhas): bool
    {
        $normalizado = mb_strtolower(trim($texto));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalizado);
        if ($ascii !== false) {
            $normalizado = mb_strtolower($ascii);
        }
        foreach ($agulhas as $agulha) {
            if ($agulha !== '' && str_contains($normalizado, mb_strtolower($agulha))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Classifica a origem "de verdade" de um acordo: se foi fechado pelo
     * operador no CRM (works) ou se veio/foi conciliado a partir da ACME
     * (acme_works/acme_externo) - mesma lógica que já existia só dentro de
     * acordos.php (Controle de Acordos), movida pra cá em 2026-09-23 pra
     * ser reaproveitada pelo dashboard também (requisito: "só deve
     * contabilizar os acordos feitos pelo CRM").
     *
     * @param array<string,mixed> $acordo
     */
    public static function classificarOrigemOperacional(array $acordo): string
    {
        $payload = self::decodeJsonObject((string) ($acordo['payload_extra'] ?? ''));
        $origem = mb_strtolower(trim((string) ($acordo['origem'] ?? '')));
        $fluxo = mb_strtolower(trim((string) ($payload['fluxo'] ?? '')));
        $api = mb_strtolower(trim((string) ($payload['credor_api'] ?? '')));
        $agreementId = trim((string) ($payload['agreement_id'] ?? ($payload['acme']['agreement_id'] ?? '')));

        if ($api === 'acme' && $agreementId !== '' && str_contains($fluxo, 'api_credor')) {
            return 'acme_works';
        }
        if (is_array($payload['acme_acordo'] ?? null) || str_contains($fluxo, 'extern') || ($payload['origem_externa_credor'] ?? false) === true) {
            return 'acme_externo';
        }
        if ($api === 'acme' || $agreementId !== '') {
            return 'acme_works';
        }
        if (in_array($origem, ['manual', 'works', 'operacao/works.php'], true) || str_contains($fluxo, 'promessa')) {
            return 'works';
        }
        return $origem !== '' ? $origem : 'indefinido';
    }

    /**
     * Responsável atribuído ao acordo em Controle de Acordos
     * (payload_extra.operador_responsavel) - permite que um acordo
     * fechado fora do CRM (exceção aprovada que veio por API/carga) passe a
     * contar para o operador que a supervisão atribuiu.
     *
     * @param array<string,mixed> $acordo precisa da chave payload_extra
     * @return array{usuario_id:int,nome:string}|null
     */
    public static function responsavelAtribuido(array $acordo): ?array
    {
        $payload = self::decodeJsonObject((string) ($acordo['payload_extra'] ?? ''));
        $resp = $payload['operador_responsavel'] ?? null;
        if (!is_array($resp)) {
            return null;
        }
        $id = (int) ($resp['usuario_id'] ?? 0);
        $nome = trim((string) ($resp['nome'] ?? ''));
        return $id > 0 && $nome !== '' ? ['usuario_id' => $id, 'nome' => $nome] : null;
    }

    /**
     * Regra dos dashboards (CRM e OpenSearch): conta o acordo fechado aqui
     * no CRM OU o que tem responsável atribuído (mesmo vindo de fora).
     *
     * @param array<string,mixed> $acordo precisa de origem e payload_extra
     */
    public static function contabilizaNoDashboard(array $acordo): bool
    {
        return self::classificarOrigemOperacional($acordo) === 'works' || self::responsavelAtribuido($acordo) !== null;
    }

    /** @return array<string,mixed> */
    private static function decodeJsonObject(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function formaPagamentoAcme(string $tag): string
    {
        $tag = mb_strtolower(trim($tag));
        if (str_contains($tag, 'boleto')) {
            return 'boleto';
        }
        if (str_contains($tag, 'pix')) {
            return 'pix';
        }
        if (str_contains($tag, 'debito')) {
            return 'debito_conta';
        }
        if (str_contains($tag, 'cartao')) {
            return 'cartao';
        }
        return 'nao_informado';
    }

    public function recalcularQuebrasPorCarencia(?array $escopo = null, ?int $contratoId = null): int
    {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'acordo_carencia_scope');
        $where = [
            "ao.status_operacional IN ('pendente','formalizado')",
            $scope['sql'],
        ];
        $params = $scope['params'];
        if ($contratoId !== null && $contratoId > 0) {
            $where[] = 'ao.contrato_id = :carencia_contrato_id';
            $params['carencia_contrato_id'] = $contratoId;
        }

        $sql = "SELECT
                ao.id,
                ao.credor_id,
                ao.contrato_id,
                ao.validado_credor_em,
                c.produto,
                MIN(CASE
                    WHEN p.status IN ('pendente','vencida') THEN p.data_vencimento
                    ELSE NULL
                END) AS primeiro_vencimento_aberto,
                MIN(CASE
                    WHEN p.status IN ('pendente','vencida')
                    THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.payload_extra, '$.acme.deadline_date')), ''), 'null')
                    ELSE NULL
                END) AS primeiro_deadline_credor
            FROM acordos_operacionais ao
            INNER JOIN acordos_operacionais_parcelas p ON p.acordo_operacional_id = ao.id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY ao.id, ao.credor_id, ao.contrato_id, ao.validado_credor_em, c.produto
            HAVING primeiro_vencimento_aberto IS NOT NULL
            ORDER BY primeiro_vencimento_aberto ASC
            LIMIT 5000";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        $alterados = 0;

        foreach ($rows as $row) {
            $acordoId = (int) ($row['id'] ?? 0);
            $credorId = (int) ($row['credor_id'] ?? 0);
            $vencimento = (string) ($row['primeiro_vencimento_aberto'] ?? '');
            $deadlineCredor = (string) ($row['primeiro_deadline_credor'] ?? '');
            if ($acordoId <= 0 || $vencimento === '') {
                continue;
            }

            $situacao = $this->resolverSituacaoAberta($credorId, (string) ($row['produto'] ?? ''), $vencimento, $deadlineCredor);
            if (!$situacao['expirada']) {
                continue;
            }

            // Achado 2026-09-22: a quebra rodava só com dado local/cacheado
            // (às vezes com dias de defasagem da ACME) e derrubava acordo que já tinha
            // sido pago de verdade, sem ninguém perceber. Nos dias-gatilho (vencimento,
            // D+1 do vencimento, último dia da carência, D+1 do fim da carência), só
            // deixa quebrar depois que a validação ao vivo com o credor já rodou HOJE
            // (ver AcordoValidacaoCredorService::validarAntesDeQuebrar(), chamado antes
            // desta rotina no mesmo cron). Fora desses dias, comportamento igual a antes.
            $validadoHoje = $this->validadoComCredorHoje((string) ($row['validado_credor_em'] ?? ''));
            if (!$validadoHoje && $this->diaDeValidacaoCredor($vencimento, (string) $situacao['data_limite'])) {
                continue;
            }

            $descricao = $deadlineCredor !== ''
                ? 'Acordo quebrado automaticamente: DeadlineDate informado pela ACME expirou em '
                    . (string) $situacao['data_limite_label'] . ' sem pagamento confirmado.'
                : 'Acordo quebrado automaticamente: parcela vencida em '
                    . (new DateTimeImmutable($vencimento))->format('d/m/Y')
                    . ', carência de ' . (int) $situacao['dias'] . ' dia(s), limite '
                    . (string) $situacao['data_limite_label'] . '.';

            $update = $this->pdo->prepare("UPDATE acordos_operacionais
                SET status_operacional = 'quebrado',
                    status_atualizado_em = NOW(),
                    observacao = NULLIF(CONCAT_WS('\n', NULLIF(observacao, ''), :observacao), '')
                WHERE id = :id
                  AND status_operacional IN ('pendente','formalizado')
                LIMIT 1");
            $update->bindValue('observacao', '[' . date('d/m/Y H:i') . '] ' . $descricao);
            $update->bindValue('id', $acordoId, PDO::PARAM_INT);
            $update->execute();
            if ($update->rowCount() <= 0) {
                continue;
            }

            $parcelas = $this->pdo->prepare("UPDATE acordos_operacionais_parcelas
                SET status = 'vencida',
                    observacao = NULLIF(CONCAT_WS('\n', NULLIF(observacao, ''), :observacao), '')
                WHERE acordo_operacional_id = :acordo_id
                  AND status = 'pendente'
                  AND data_vencimento < CURRENT_DATE");
            $parcelas->bindValue('observacao', 'Parcela vencida; carência operacional encerrada.');
            $parcelas->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $parcelas->execute();

            $this->registrarAuditoria(
                $acordoId,
                null,
                'acordo_quebrado_carencia',
                $descricao,
                ['status_operacional' => 'pendente'],
                [
                    'status_operacional' => 'quebrado',
                    'dias_carencia' => (int) $situacao['dias'],
                    'primeiro_vencimento_aberto' => $vencimento,
                    'data_limite_carencia' => $situacao['data_limite'],
                    'fonte_carencia' => $situacao['fonte'] ?? 'regra_local',
                ],
                null,
                'sistema'
            );
            $alterados++;
        }

        return $alterados;
    }

    /**
     * Resolve a situação de carência de um acordo a partir do deadline vindo
     * da ACME (se já tiver sido cacheado numa sincronização anterior) ou, na
     * falta dele, das regras locais de carência.
     *
     * @return array{expirada:bool,dias:int,data_limite:?string,data_limite_label:string,fonte:string}
     */
    private function resolverSituacaoAberta(int $credorId, string $produto, string $vencimento, string $deadlineCredor): array
    {
        if ($deadlineCredor !== '') {
            $deadlineLabel = '-';
            try {
                $deadlineData = (new DateTimeImmutable($deadlineCredor))->setTime(0, 0);
                $expirada = $deadlineData < (new DateTimeImmutable('today'))->setTime(0, 0);
                $deadlineLabel = $deadlineData->format('d/m/Y');
            } catch (Throwable) {
                $expirada = false;
            }

            return [
                'expirada' => $expirada,
                'dias' => 0,
                'data_limite' => $deadlineCredor,
                'data_limite_label' => $deadlineLabel,
                'fonte' => 'acme_deadline_date',
            ];
        }

        $situacao = $this->situacaoCarencia($vencimento, $credorId, $produto, 'pendente');
        $situacao['fonte'] = 'regra_local';

        return $situacao;
    }

    /**
     * Dias-gatilho de validação ao vivo com o credor, definidos pela operação
     * em 2026-09-22 para nunca quebrar acordo "sem saber" se já foi pago:
     * o vencimento, o dia seguinte ao vencimento (se ainda em aberto), o
     * último dia da carência e o dia seguinte ao fim da carência.
     */
    private function diaDeValidacaoCredor(string $vencimento, ?string $dataLimite): bool
    {
        if ($dataLimite === null || $dataLimite === '') {
            return false;
        }

        try {
            $venc = (new DateTimeImmutable($vencimento))->setTime(0, 0);
            $limite = (new DateTimeImmutable($dataLimite))->setTime(0, 0);
        } catch (Throwable) {
            return false;
        }

        $hoje = (new DateTimeImmutable('today'))->setTime(0, 0);
        $checkpoints = [$venc, $venc->modify('+1 day'), $limite, $limite->modify('+1 day')];
        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint == $hoje) {
                return true;
            }
        }

        return false;
    }

    private function validadoComCredorHoje(string $validadoCredorEm): bool
    {
        if ($validadoCredorEm === '') {
            return false;
        }

        try {
            $data = new DateTimeImmutable($validadoCredorEm);
        } catch (Throwable) {
            return false;
        }

        return $data->format('Y-m-d') === (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    /**
     * Acordos com parcela em aberto batendo hoje um dos dias-gatilho de
     * carência (vencimento, D+1, limite de carência, D+1 do limite) que
     * ainda não foram validados ao vivo com o credor hoje. Usado pelo cron
     * para consultar o credor ANTES de deixar a quebra automática agir,
     * evitando quebrar acordo que já foi pago mas ninguém marcou no CRM.
     *
     * @return list<int>
     */
    public function acordosElegiveisParaValidacaoCredor(?array $escopo = null, ?int $contratoId = null, int $limite = 500): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'acordo_validacao_scope');
        $where = [
            "ao.status_operacional IN ('pendente','formalizado')",
            "(ao.validado_credor_em IS NULL OR DATE(ao.validado_credor_em) < CURRENT_DATE)",
            $scope['sql'],
        ];
        $params = $scope['params'];
        if ($contratoId !== null && $contratoId > 0) {
            $where[] = 'ao.contrato_id = :validacao_contrato_id';
            $params['validacao_contrato_id'] = $contratoId;
        }

        $sql = "SELECT
                ao.id,
                ao.credor_id,
                c.produto,
                MIN(CASE
                    WHEN p.status IN ('pendente','vencida') THEN p.data_vencimento
                    ELSE NULL
                END) AS primeiro_vencimento_aberto,
                MIN(CASE
                    WHEN p.status IN ('pendente','vencida')
                    THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.payload_extra, '$.acme.deadline_date')), ''), 'null')
                    ELSE NULL
                END) AS primeiro_deadline_credor
            FROM acordos_operacionais ao
            INNER JOIN acordos_operacionais_parcelas p ON p.acordo_operacional_id = ao.id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY ao.id, ao.credor_id, c.produto
            HAVING primeiro_vencimento_aberto IS NOT NULL
            ORDER BY primeiro_vencimento_aberto ASC
            LIMIT " . max(1, min($limite, 5000));

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $elegiveis = [];
        foreach ($rows as $row) {
            $acordoId = (int) ($row['id'] ?? 0);
            $credorId = (int) ($row['credor_id'] ?? 0);
            $vencimento = (string) ($row['primeiro_vencimento_aberto'] ?? '');
            $deadlineCredor = (string) ($row['primeiro_deadline_credor'] ?? '');
            if ($acordoId <= 0 || $vencimento === '') {
                continue;
            }

            $situacao = $this->resolverSituacaoAberta($credorId, (string) ($row['produto'] ?? ''), $vencimento, $deadlineCredor);
            if ($this->diaDeValidacaoCredor($vencimento, $situacao['data_limite'])) {
                $elegiveis[] = $acordoId;
            }
        }

        return $elegiveis;
    }

    /**
     * Marca que o acordo já teve seus pagamentos conferidos ao vivo com o
     * credor hoje, para não repetir a chamada à API na mesma execução/dia
     * e para liberar (ou não) a quebra automática por carência.
     */
    public function registrarValidacaoCredor(int $acordoId): void
    {
        if (!$this->moduloDisponivel() || $acordoId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE acordos_operacionais SET validado_credor_em = NOW() WHERE id = :id');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @param array<string,mixed> $dados */
    public function registrar(int $pessoaId, array $dados): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de acordos operacionais indisponível. Execute as migrations 014 e 015 antes de registrar acordo.');
        }

        if ($pessoaId <= 0) {
            throw new InvalidArgumentException('Cliente inválido para registrar acordo.');
        }

        $contratoId = $this->normalizarId($dados['contrato_id'] ?? null);
        $credorId = null;
        if ($contratoId !== null) {
            $credorId = $this->credorDoContratoDaPessoa($pessoaId, $contratoId);
            if ($credorId === null) {
                throw new InvalidArgumentException('Contrato informado no acordo não pertence a este cliente.');
            }

            $this->recalcularQuebrasPorCarencia(null, $contratoId);
            $this->validarContratoSemAcordoAtivo($contratoId);
        }

        $valorAcordo = $this->normalizarDecimalObrigatorio($dados['valor_acordo'] ?? ($dados['valor_promessa'] ?? null), 'valor total do acordo');
        $valorEntrada = $this->normalizarDecimalOpcional($dados['valor_entrada'] ?? null, 'valor de entrada');
        $dataEntrada = $valorEntrada !== null && $valorEntrada > 0
            ? $this->normalizarDataObrigatoria($dados['data_entrada'] ?? null, 'data de vencimento da entrada')
            : $this->normalizarDataOpcional($dados['data_entrada'] ?? null);
        $quantidadeParcelas = $this->normalizarQuantidadeParcelas($dados['quantidade_parcelas'] ?? 1);
        $dataPrimeiraParcela = $this->normalizarDataObrigatoria(
            $dados['data_primeira_parcela'] ?? ($dados['data_promessa_pagamento'] ?? ($dados['data_promessa'] ?? null)),
            'data da primeira parcela'
        );
        $periodicidade = $this->normalizarPeriodicidade($dados['periodicidade'] ?? 'mensal');
        $formaPagamento = $this->normalizarFormaPagamento($dados['forma_pagamento'] ?? 'nao_informado');

        if ($valorEntrada !== null && $valorEntrada >= $valorAcordo) {
            throw new InvalidArgumentException('O valor da entrada deve ser menor que o valor total do acordo. Para pagamento à vista, deixe entrada vazia e use 1 parcela.');
        }

        $valorParcelado = round($valorAcordo - (float) ($valorEntrada ?? 0.0), 2);
        if ($valorParcelado <= 0) {
            throw new InvalidArgumentException('O valor parcelado deve ser maior que zero.');
        }

        $valorParcela = round($valorParcelado / $quantidadeParcelas, 2);
        $dataPromessaPagamento = $dataEntrada ?? $dataPrimeiraParcela;
        $observacao = $this->normalizarTexto($dados['acordo_observacao'] ?? ($dados['promessa_observacao'] ?? ($dados['observacao'] ?? null)), 2000);
        $operadorNome = $this->normalizarTexto($dados['operador_nome'] ?? null, 120);
        $criadoPorUsuarioId = $this->normalizarId($dados['criado_por_usuario_id'] ?? ($dados['usuario_id'] ?? null));
        $apiTokenId = $this->normalizarId($dados['api_token_id'] ?? null);
        $statusOperacional = $this->normalizarStatusOperacional($dados['status_operacional'] ?? ($dados['status'] ?? 'pendente'));
        $statusIntegracao = $this->normalizarStatusIntegracao($dados['status_integracao'] ?? 'nao_enviado');
        $origem = $this->normalizarOrigem($dados['origem'] ?? 'manual');

        $atendimentoId = $this->normalizarId($dados['atendimento_id'] ?? null);
        $campanhaItemId = $this->normalizarId($dados['campanha_item_id'] ?? null);
        $retornoOrigemId = $this->normalizarId($dados['retorno_origem_id'] ?? null);
        // Achado (2026-09-29): acordo que "veio de fora" (sincronizado
        // da ACME, geralmente dias depois de ter sido feito lá) registrava
        // criado_em = agora (quando o CRM ficou sabendo), não a data real do
        // acordo na ACME - a Central de Acordos mostrava "Gerado em" errado, e
        // o dashboard/funil por período contava o acordo no dia errado.
        // Opcional: só sincronizarAcordoAcme() usa isto hoje.
        $criadoEm = $this->normalizarDataHoraOpcional($dados['criado_em'] ?? null);

        $parcelas = $this->gerarParcelas($valorAcordo, $valorEntrada, $dataEntrada, $quantidadeParcelas, $valorParcela, $dataPrimeiraParcela, $periodicidade);
        $resumoParcelamento = [
            'valor_total_acordo' => $valorAcordo,
            'valor_entrada' => $valorEntrada,
            'data_entrada' => $dataEntrada,
            'quantidade_parcelas' => $quantidadeParcelas,
            'valor_parcela_referencia' => $valorParcela,
            'data_primeira_parcela' => $dataPrimeiraParcela,
            'periodicidade' => $periodicidade,
            'forma_pagamento' => $formaPagamento,
            'total_itens' => count($parcelas),
        ];

        $payloadExtra = [
            'origem_tela' => $dados['origem_tela'] ?? 'operacao/cliente_detalhe.php',
            'sem_login' => true,
            'registro_operacional' => true,
            'observacao_arquitetural' => 'Acordo operacional; nao altera saldo, boleto ou pagamento automaticamente.',
            'integracao_credor_preparada' => true,
            'integracao_credor_executada' => false,
            'parcelas_geradas_automaticamente' => true,
        ];

        if (isset($dados['payload_extra_adicional']) && is_array($dados['payload_extra_adicional'])) {
            $payloadExtra = array_replace_recursive($payloadExtra, $dados['payload_extra_adicional']);
        }

        $stmt = $this->pdo->prepare('INSERT INTO acordos_operacionais (
                pessoa_id,
                contrato_id,
                credor_id,
                atendimento_id,
                campanha_item_id,
                retorno_origem_id,
                valor_acordo,
                valor_entrada,
                data_entrada,
                quantidade_parcelas,
                valor_parcela,
                data_primeira_parcela,
                data_promessa_pagamento,
                periodicidade,
                forma_pagamento,
                status_operacional,
                status_integracao,
                origem,
                operador_nome,
                criado_por_usuario_id,
                api_token_id,
                observacao,
                resumo_parcelamento,
                payload_extra' . ($criadoEm !== null ? ",\n                criado_em" : '') . '
            ) VALUES (
                :pessoa_id,
                :contrato_id,
                :credor_id,
                :atendimento_id,
                :campanha_item_id,
                :retorno_origem_id,
                :valor_acordo,
                :valor_entrada,
                :data_entrada,
                :quantidade_parcelas,
                :valor_parcela,
                :data_primeira_parcela,
                :data_promessa_pagamento,
                :periodicidade,
                :forma_pagamento,
                :status_operacional,
                :status_integracao,
                :origem,
                :operador_nome,
                :criado_por_usuario_id,
                :api_token_id,
                :observacao,
                :resumo_parcelamento,
                :payload_extra' . ($criadoEm !== null ? ",\n                :criado_em" : '') . '
            )');

        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'contrato_id', $contratoId);
        $this->bindNullableInt($stmt, 'credor_id', $credorId);
        $this->bindNullableInt($stmt, 'atendimento_id', $atendimentoId);
        $this->bindNullableInt($stmt, 'campanha_item_id', $campanhaItemId);
        $this->bindNullableInt($stmt, 'retorno_origem_id', $retornoOrigemId);
        $stmt->bindValue('valor_acordo', number_format($valorAcordo, 2, '.', ''));
        $this->bindNullableDecimal($stmt, 'valor_entrada', $valorEntrada);
        $this->bindNullableString($stmt, 'data_entrada', $dataEntrada);
        $stmt->bindValue('quantidade_parcelas', $quantidadeParcelas, PDO::PARAM_INT);
        $stmt->bindValue('valor_parcela', number_format($valorParcela, 2, '.', ''));
        $stmt->bindValue('data_primeira_parcela', $dataPrimeiraParcela);
        $stmt->bindValue('data_promessa_pagamento', $dataPromessaPagamento);
        $stmt->bindValue('periodicidade', $periodicidade);
        $stmt->bindValue('forma_pagamento', $formaPagamento);
        $stmt->bindValue('status_operacional', $statusOperacional);
        $stmt->bindValue('status_integracao', $statusIntegracao);
        $stmt->bindValue('origem', $origem);
        $this->bindNullableString($stmt, 'operador_nome', $operadorNome);
        $this->bindNullableInt($stmt, 'criado_por_usuario_id', $criadoPorUsuarioId);
        $this->bindNullableInt($stmt, 'api_token_id', $apiTokenId);
        $this->bindNullableString($stmt, 'observacao', $observacao);
        $stmt->bindValue('resumo_parcelamento', json_encode($resumoParcelamento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue('payload_extra', json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($criadoEm !== null) {
            $stmt->bindValue('criado_em', $criadoEm);
        }
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            // Corrida rara entre dois operadores no mesmo contrato: validarContratoSemAcordoAtivo()
            // acima e um SELECT sem lock, entao os dois podem passar por ela ao mesmo tempo. Quem
            // garante que so um acordo ativo existe de fato e a migration 017 (trigger
            // acordo_ativo_contrato_id + UNIQUE KEY uk_acordos_operacionais_contrato_ativo) no
            // banco. Aqui so traduzimos o erro tecnico do MySQL (1062/duplicate entry) na mesma
            // mensagem amigavel que o caminho normal (checagem previa) ja usa, em vez de deixar o
            // operador ver um SQLSTATE cru na tela.
            if ($contratoId !== null && (int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uk_acordos_operacionais_contrato_ativo')) {
                $this->validarContratoSemAcordoAtivo($contratoId);
            }
            throw $e;
        }

        $acordoId = (int) $this->pdo->lastInsertId();
        $this->inserirParcelas($acordoId, $parcelas);
        $this->registrarAuditoria(
            $acordoId,
            null,
            'acordo_criado',
            'Acordo operacional criado.',
            null,
            [
                'pessoa_id' => $pessoaId,
                'contrato_id' => $contratoId,
                'credor_id' => $credorId,
                'valor_acordo' => $valorAcordo,
                'valor_entrada' => $valorEntrada,
                'data_entrada' => $dataEntrada,
                'quantidade_parcelas' => $quantidadeParcelas,
                'valor_parcela' => $valorParcela,
                'data_primeira_parcela' => $dataPrimeiraParcela,
                'periodicidade' => $periodicidade,
                'forma_pagamento' => $formaPagamento,
                'status_operacional' => $statusOperacional,
                'status_integracao' => $statusIntegracao,
                'parcelas' => $parcelas,
            ],
            $operadorNome,
            'operacao'
        );

        try {
            $carteira = new CarteiraAcordosRepository($this->pdo, $this);
            if ($carteira->moduloDisponivel()) {
                $carteira->atualizarCarteiraGeral(1, $acordoId);
            }
        } catch (Throwable) {
            // A criação do acordo não deve falhar caso a migration 075 ainda não tenha sido aplicada.
        }

        return $acordoId;
    }

    /**
     * Busca DIRETO por contrato_id o acordo que bloquearia a criacao de um
     * novo acordo ACME para este contrato (pendente/formalizado = acordo
     * ativo; cumprido = divida ja quitada). Existia antes um metodo
     * equivalente que so funcionava "por acaso": validarContratoAcmeDisponivel
     * buscava os ate 100 acordos mais recentes da PESSOA (nao do contrato) e
     * filtrava em PHP - funcionava porque o ORDER BY colocava
     * pendente/formalizado primeiro, mas uma pessoa com mais de 100 acordos
     * (comum, contratos diferentes se acumulam com o tempo) podia ter o
     * acordo relevante deste contrato empurrado pra fora do corte de 100,
     * deixando passar um acordo duplicado ou uma divida ja quitada sem
     * bloquear. Esta busca e' escopada diretamente por contrato_id, sem
     * depender de quantos outros acordos a pessoa tenha.
     *
     * @return array<string,mixed>|null
     */
    public function buscarAcordoBloqueanteContrato(int $contratoId, ?array $escopo = null): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'acordo_bloqueio_contrato');
        $stmt = $this->pdo->prepare("SELECT ao.id, ao.status_operacional
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            WHERE ao.contrato_id = :contrato_id
              AND ao.status_operacional IN ('pendente','formalizado','cumprido')
              AND {$scope['sql']}
            ORDER BY CASE ao.status_operacional
                    WHEN 'pendente' THEN 0
                    WHEN 'formalizado' THEN 0
                    WHEN 'cumprido' THEN 1
                    ELSE 2
                END ASC,
                ao.id DESC
            LIMIT 1");
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listarPorPessoa(int $pessoaId, int $limite = 20, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 100));
        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'acordos_pessoa_scope');
        $stmt = $this->pdo->prepare('SELECT
                ao.*,
                c.codigo_contrato,
                c.produto,
                c.saldo_atual,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome,
                ah.canal AS atendimento_canal,
                ah.resultado AS atendimento_resultado,
                ah.criado_em AS atendimento_criado_em
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, c.credor_id)
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN atendimentos_historico ah ON ah.id = ao.atendimento_id
            WHERE ao.pessoa_id = :pessoa_id
              AND ' . $scope['sql'] . '
            ORDER BY
                CASE ao.status_operacional
                    WHEN \'pendente\' THEN 0
                    WHEN \'formalizado\' THEN 1
                    WHEN \'cumprido\' THEN 2
                    WHEN \'quebrado\' THEN 3
                    ELSE 4
                END ASC,
                ao.data_promessa_pagamento ASC,
                ao.id DESC
            LIMIT :limite');
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarDetalhe(int $acordoId, ?array $escopo = null): ?array
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'acordo_detalhe_scope');
        $stmt = $this->pdo->prepare('SELECT
                ao.*,
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
            FROM acordos_operacionais ao
            INNER JOIN pessoas p ON p.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, c.credor_id)
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN atendimentos_historico ah ON ah.id = ao.atendimento_id
            WHERE ao.id = :id
              AND ' . $scope['sql'] . '
            LIMIT 1');
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listarParcelas(int $acordoId): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT *
            FROM acordos_operacionais_parcelas
            WHERE acordo_operacional_id = :acordo_id
            ORDER BY numero_parcela ASC');
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param list<int|string> $acordoIds @return array<int,list<array<string,mixed>>> */
    public function listarParcelasPorAcordos(array $acordoIds): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $ids = [];
        foreach ($acordoIds as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $key = ':id' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $stmt = $this->pdo->prepare('SELECT *
            FROM acordos_operacionais_parcelas
            WHERE acordo_operacional_id IN (' . implode(',', $placeholders) . ')
            ORDER BY acordo_operacional_id ASC, numero_parcela ASC');
        foreach ($params as $key => $id) {
            $stmt->bindValue($key, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $porAcordo = [];
        foreach ($stmt->fetchAll() as $row) {
            $porAcordo[(int) $row['acordo_operacional_id']][] = $row;
        }

        return $porAcordo;
    }

    /** @return list<array<string,mixed>> */
    public function listarIntegracoes(int $acordoId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT *
                FROM acordo_operacional_integracoes
                WHERE acordo_operacional_id = :acordo_id
                ORDER BY id DESC
                LIMIT 30');
            $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,int|float> */
    public function resumoPessoa(int $pessoaId, ?array $escopo = null): array
    {
        $fallback = [
            'total' => 0,
            'pendentes' => 0,
            'vencidos' => 0,
            'valor_pendente' => 0.0,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'resumo_pessoa_acordo_scope');
        $stmt = $this->pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status_operacional = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status_operacional = 'pendente' AND data_promessa_pagamento < CURRENT_DATE THEN 1 ELSE 0 END) AS vencidos,
                SUM(CASE WHEN status_operacional = 'pendente' THEN valor_acordo ELSE 0 END) AS valor_pendente
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            WHERE ao.pessoa_id = :pessoa_id AND " . $scope['sql']);
        EscopoCredorSql::bind($stmt, $scope['params']);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
            'vencidos' => (int) ($row['vencidos'] ?? 0),
            'valor_pendente' => (float) ($row['valor_pendente'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $filtros @return list<array<string,mixed>> */
    /**
     * Filtros de "recorte" (busca, periodo de geracao, fila, credor) -
     * compartilhados entre listar() e resumoGeral(), pra que os cards de
     * resumo respeitem o mesmo recorte da lista abaixo. NAO inclui
     * status/vencimento/carteira, que sao as dimensoes que os proprios
     * cards de resumo detalham.
     * @param array<string,mixed> $filtros
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function whereRecorteAcordos(array $filtros): array
    {
        $where = [];
        $params = [];

        $termo = trim((string) ($filtros['q'] ?? ''));
        if ($termo !== '' && mb_strlen($termo) >= 2) {
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';
            $buscas = ['p.nome LIKE :q_texto_nome', 'c.codigo_contrato LIKE :q_texto_contrato', 'ao.codigo_acordo_credor LIKE :q_texto_codigo', 'ao.protocolo_credor LIKE :q_texto_protocolo'];
            $params['q_texto_nome'] = '%' . $termo . '%';
            $params['q_texto_contrato'] = '%' . $termo . '%';
            $params['q_texto_codigo'] = '%' . $termo . '%';
            $params['q_texto_protocolo'] = '%' . $termo . '%';
            if ($digitos !== '' && strlen($digitos) >= 3) {
                $buscas[] = 'p.documento LIKE :q_digitos';
                $params['q_digitos'] = '%' . $digitos . '%';
            }
            $where[] = '(' . implode(' OR ', $buscas) . ')';
        }

        $emissaoInicio = $this->normalizarDataFiltro($filtros['emissao_inicio'] ?? null);
        if ($emissaoInicio !== null) {
            $where[] = 'ao.criado_em >= :emissao_inicio';
            $params['emissao_inicio'] = $emissaoInicio . ' 00:00:00';
        }

        $emissaoFim = $this->normalizarDataFiltro($filtros['emissao_fim'] ?? null);
        if ($emissaoFim !== null) {
            $where[] = 'ao.criado_em < DATE_ADD(:emissao_fim, INTERVAL 1 DAY)';
            $params['emissao_fim'] = $emissaoFim;
        }

        // Responsável atribuído (payload_extra.operador_responsavel): filtrado
        // no SQL. Antes só era filtrado em PHP depois do LIMIT 200, então
        // acordos fora dos 200 mais recentes só apareciam buscando por CPF.
        $responsavel = trim((string) ($filtros['responsavel'] ?? 'todos'));
        if ($responsavel !== '' && $responsavel !== 'todos') {
            $respIdExpr = "CASE WHEN JSON_VALID(ao.payload_extra) THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(ao.payload_extra, '$.operador_responsavel.usuario_id')) AS UNSIGNED), 0) ELSE 0 END";
            if ($responsavel === 'sem_responsavel') {
                $where[] = "({$respIdExpr}) = 0";
            } elseif (ctype_digit($responsavel)) {
                $where[] = "({$respIdExpr}) = :filtro_responsavel_id";
                $params['filtro_responsavel_id'] = (int) $responsavel;
            }
        }

        $fila = trim((string) ($filtros['fila'] ?? 'todos'));
        if ($fila !== '' && $fila !== 'todos') {
            $notaExpr = self::sqlResponsavelNegociacao('ao');
            if ($fila === 'Outras') {
                $condicoes = ["{$notaExpr} IS NULL"];
                foreach (self::GRUPOS_FILA as $indice => $grupoExcluido) {
                    $condicoes[] = "{$notaExpr} NOT LIKE :fila_exclui_{$indice}";
                    $params['fila_exclui_' . $indice] = 'Responsável: ' . $grupoExcluido . '%';
                }
                $where[] = '(' . $condicoes[0] . ' OR (' . implode(' AND ', array_slice($condicoes, 1)) . '))';
            } else {
                $where[] = "{$notaExpr} LIKE :fila_prefixo";
                $params['fila_prefixo'] = 'Responsável: ' . mb_substr($fila, 0, 150) . '%';
            }
        }

        $credorId = (int) ($filtros['credor_id'] ?? 0);
        if ($credorId > 0) {
            $where[] = 'COALESCE(ao.credor_id, c.credor_id) = :credor_id_filtro';
            $params['credor_id_filtro'] = $credorId;
        }

        return [$where, $params];
    }

    public function listar(array $filtros = [], int $limite = 100, ?array $escopo = null): array
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
        if ($status === 'ativos') {
            $where[] = "ao.status_operacional IN ('pendente','formalizado')";
        } elseif ($status !== 'todos') {
            $status = $this->normalizarStatusOperacional($status);
            $where[] = 'ao.status_operacional = :status';
            $params['status'] = $status;
        }

        $integracao = trim((string) ($filtros['integracao'] ?? 'todos'));
        if ($integracao !== '' && $integracao !== 'todos') {
            $integracao = $this->normalizarStatusIntegracao($integracao);
            $where[] = 'ao.status_integracao = :integracao';
            $params['integracao'] = $integracao;
        }

        $situacao = trim((string) ($filtros['situacao'] ?? 'todos'));
        if (!in_array($situacao, ['vencidos','hoje','futuros','todos'], true)) {
            $situacao = 'todos';
        }
        if ($situacao === 'vencidos') {
            $where[] = 'ao.data_promessa_pagamento < CURRENT_DATE';
        } elseif ($situacao === 'hoje') {
            $where[] = 'ao.data_promessa_pagamento = CURRENT_DATE';
        } elseif ($situacao === 'futuros') {
            $where[] = 'ao.data_promessa_pagamento > CURRENT_DATE';
        }

        [$whereRecorte, $paramsRecorte] = $this->whereRecorteAcordos($filtros);
        $where = array_merge($where, $whereRecorte);
        $params = array_merge($params, $paramsRecorte);

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'acordos_lista_scope');
        $where[] = $scope['sql'];
        $params = array_merge($params, $scope['params']);

        $sql = 'SELECT
                ao.*,
                p.nome AS pessoa_nome,
                p.documento AS pessoa_documento,
                c.codigo_contrato,
                c.produto,
                c.saldo_atual,
                c.dias_atraso,
                c.fila,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome,
                ah.canal AS atendimento_canal,
                ah.resultado AS atendimento_resultado,
                ah.criado_em AS atendimento_criado_em
            FROM acordos_operacionais ao
            INNER JOIN pessoas p ON p.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = COALESCE(ao.credor_id, c.credor_id)
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            LEFT JOIN atendimentos_historico ah ON ah.id = ao.atendimento_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY
                CASE ao.status_operacional
                    WHEN \'pendente\' THEN 0
                    WHEN \'formalizado\' THEN 1
                    WHEN \'cumprido\' THEN 2
                    WHEN \'quebrado\' THEN 3
                    ELSE 4
                END ASC,
                ao.data_promessa_pagamento ASC,
                ao.id DESC
            LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @var list<string> grupos conhecidos do responsavel da negociacao - o nome de cada grupo e' o prefixo usado no LIKE. */
    private const GRUPOS_FILA = ['Vertex', 'Hyperion', 'Fortis', 'Portal'];

    /**
     * Expressao SQL que le o "responsavel pela negociacao" retornado pela
     * propria API da ACME (campo Agreement.NegotiationNotes, guardado em
     * acordos_operacionais.payload_extra). Vem no formato livre
     * "Responsavel: Vertex - VarejoCred 01 - EXTERNO", "Responsavel: Portal
     * Limpa Nome - BD-Multiproduto 8" etc - dezenas de variantes que
     * comecam sempre com o mesmo prefixo (Vertex/Hyperion/Fortis/Portal).
     *
     * Achado 2026-09-17: o campo contratos.fila (usado antes aqui) e' da
     * carga estatica e a operação apontou que o filtro certo pra "quem
     * negociou o acordo" e' este, que so existe pra contratos com acordo
     * ACME de fato sincronizado (por isso NULL/vazio em ~11% dos casos -
     * cai em "Outras").
     */
    public static function sqlResponsavelNegociacao(string $aliasAcordo): string
    {
        return "COALESCE("
            . "JSON_UNQUOTE(JSON_EXTRACT({$aliasAcordo}.payload_extra, '$.acme_agreement_snapshot.negotiation_notes')), "
            . "JSON_UNQUOTE(JSON_EXTRACT({$aliasAcordo}.payload_extra, '$.acme_ultima_liquidacao_snapshot.negotiation_notes'))"
            . ')';
    }

    public static function extrairResponsavelNegociacao(?string $nota): string
    {
        $nota = trim((string) $nota);
        $prefixo = 'Responsável: ';
        if (str_starts_with($nota, $prefixo)) {
            return trim(mb_substr($nota, mb_strlen($prefixo)));
        }
        return $nota;
    }

    /**
     * @return list<string>
     */
    public function listarFilasDisponiveis(?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $notaExpr = self::sqlResponsavelNegociacao('ao');
        $where = ["JSON_VALID(ao.payload_extra)", "{$notaExpr} IS NOT NULL", "{$notaExpr} <> ''"];
        $params = [];
        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'acordos_filas_scope');
        $where[] = $scope['sql'];
        $params = array_merge($params, $scope['params']);

        $sql = "SELECT DISTINCT {$notaExpr} AS nota
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $notasReais = array_map(static fn (mixed $row): string => (string) $row, $stmt->fetchAll(PDO::FETCH_COLUMN));

        $gruposEncontrados = [];
        foreach ($notasReais as $nota) {
            $gruposEncontrados[self::grupoFila(self::extrairResponsavelNegociacao($nota))] = true;
        }

        $ordemPreferida = [...self::GRUPOS_FILA, 'Outras'];
        return array_values(array_filter($ordemPreferida, static fn (string $grupo): bool => isset($gruposEncontrados[$grupo])));
    }

    public static function grupoFila(string $fila): string
    {
        $filaNormalizada = mb_strtolower(trim($fila));
        foreach (self::GRUPOS_FILA as $grupo) {
            if (str_starts_with($filaNormalizada, mb_strtolower($grupo))) {
                return $grupo;
            }
        }
        return 'Outras';
    }

    /** @return array<string,int|float> */
    /**
     * @param array<string,mixed> $filtros
     * Aceita os mesmos filtros de listar() que fazem sentido como recorte
     * (fila, credor, busca, periodo de geracao) - NAO aceita status/
     * vencimento/carteira porque essas sao justamente as dimensoes que os
     * cards abaixo detalham (aplicar como WHERE zeraria a maioria deles).
     */
    public function resumoGeral(array $filtros = [], ?array $escopo = null): array
    {
        $fallback = [
            'total' => 0,
            'pendentes' => 0,
            'vencidos' => 0,
            'hoje' => 0,
            'cumpridos' => 0,
            'quebrados' => 0,
            'cancelados' => 0,
            'formalizados' => 0,
            'pendentes_integracao' => 0,
            'erros_integracao' => 0,
            'valor_pendente' => 0.0,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        [$whereRecorte, $paramsRecorte] = $this->whereRecorteAcordos($filtros);

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'resumo_acordos_scope');
        $where = array_merge($whereRecorte, [$scope['sql']]);
        $params = array_merge($paramsRecorte, $scope['params']);

        $stmt = $this->pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status_operacional = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status_operacional = 'pendente' AND data_promessa_pagamento < CURRENT_DATE THEN 1 ELSE 0 END) AS vencidos,
                SUM(CASE WHEN status_operacional = 'pendente' AND data_promessa_pagamento = CURRENT_DATE THEN 1 ELSE 0 END) AS hoje,
                SUM(CASE WHEN status_operacional = 'cumprido' THEN 1 ELSE 0 END) AS cumpridos,
                SUM(CASE WHEN status_operacional = 'quebrado' THEN 1 ELSE 0 END) AS quebrados,
                SUM(CASE WHEN status_operacional = 'cancelado' THEN 1 ELSE 0 END) AS cancelados,
                SUM(CASE WHEN status_operacional = 'formalizado' THEN 1 ELSE 0 END) AS formalizados,
                SUM(CASE WHEN status_integracao = 'pendente_envio' THEN 1 ELSE 0 END) AS pendentes_integracao,
                SUM(CASE WHEN status_integracao = 'erro' THEN 1 ELSE 0 END) AS erros_integracao,
                SUM(CASE WHEN status_operacional = 'pendente' THEN valor_acordo ELSE 0 END) AS valor_pendente
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN pessoas p ON p.id = ao.pessoa_id
            WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt !== false ? ($stmt->fetch() ?: []) : [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
            'vencidos' => (int) ($row['vencidos'] ?? 0),
            'hoje' => (int) ($row['hoje'] ?? 0),
            'cumpridos' => (int) ($row['cumpridos'] ?? 0),
            'quebrados' => (int) ($row['quebrados'] ?? 0),
            'cancelados' => (int) ($row['cancelados'] ?? 0),
            'formalizados' => (int) ($row['formalizados'] ?? 0),
            'pendentes_integracao' => (int) ($row['pendentes_integracao'] ?? 0),
            'erros_integracao' => (int) ($row['erros_integracao'] ?? 0),
            'valor_pendente' => (float) ($row['valor_pendente'] ?? 0),
        ];
    }

    public function atualizarStatusOperacional(int $acordoId, string $status, ?string $observacao = null): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de acordos operacionais indisponível.');
        }

        $status = $this->normalizarStatusOperacional($status);
        $observacao = $this->normalizarTexto($observacao, 2000);

        if ($this->statusOperacionalAtivo($status)) {
            $contratoId = $this->contratoDoAcordo($acordoId);
            if ($contratoId !== null) {
                $this->validarContratoSemAcordoAtivo($contratoId, $acordoId);
            }
        }

        $antesStmt = $this->pdo->prepare('SELECT id, status_operacional, status_integracao, observacao
            FROM acordos_operacionais
            WHERE id = :id
            LIMIT 1');
        $antesStmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $antesStmt->execute();
        $antes = $antesStmt->fetch();
        if (!$antes) {
            throw new InvalidArgumentException('Acordo operacional não encontrado.');
        }

        $stmt = $this->pdo->prepare('UPDATE acordos_operacionais
            SET status_operacional = :status,
                status_atualizado_em = NOW(),
                observacao = NULLIF(CONCAT_WS("\\n", NULLIF(observacao, \'\'), NULLIF(:observacao, \'\')), \'\')
            WHERE id = :id
            LIMIT 1');
        $stmt->bindValue('status', $status);
        $this->bindNullableString($stmt, 'observacao', $observacao);
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();

        $this->registrarAuditoria(
            $acordoId,
            null,
            'status_acordo_atualizado',
            'Status geral do acordo atualizado para ' . self::statusLabel($status) . '.',
            ['status_operacional' => $antes['status_operacional'] ?? null],
            ['status_operacional' => $status, 'observacao_status' => $observacao],
            null,
            'operacao'
        );
    }

    public function atualizarStatusParcela(
        int $acordoId,
        int $parcelaId,
        string $status,
        mixed $dataPagamento = null,
        mixed $valorPago = null,
        ?string $observacao = null
    ): void {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de acordos operacionais indisponível.');
        }

        if ($acordoId <= 0 || $parcelaId <= 0) {
            throw new InvalidArgumentException('Acordo ou parcela inválidos.');
        }

        $status = $this->normalizarStatusParcela($status);
        $observacao = $this->normalizarTexto($observacao, 2000);
        $dataPagamentoNormalizada = $this->normalizarDataOpcional($dataPagamento);
        $valorPagoNormalizado = $this->normalizarDecimalOpcional($valorPago, 'valor pago');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT *
                FROM acordos_operacionais_parcelas
                WHERE id = :id
                  AND acordo_operacional_id = :acordo_id
                LIMIT 1
                FOR UPDATE');
            $stmt->bindValue('id', $parcelaId, PDO::PARAM_INT);
            $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $stmt->execute();
            $parcela = $stmt->fetch();

            if (!$parcela) {
                throw new InvalidArgumentException('Parcela não encontrada para este acordo.');
            }

            $parcelaAntes = [
                'status' => $parcela['status'] ?? null,
                'data_pagamento' => $parcela['data_pagamento'] ?? null,
                'valor_pago' => $parcela['valor_pago'] ?? null,
                'observacao' => $parcela['observacao'] ?? null,
            ];

            if ($status === 'paga') {
                $dataPagamentoNormalizada ??= (new DateTimeImmutable('today'))->format('Y-m-d');
                $valorPagoNormalizado ??= (float) ($parcela['valor_pago'] ?? $parcela['valor_parcela']);
                if ($valorPagoNormalizado <= 0) {
                    throw new InvalidArgumentException('O valor pago deve ser maior que zero.');
                }
            } else {
                $dataPagamentoNormalizada = null;
                $valorPagoNormalizado = null;
            }

            $update = $this->pdo->prepare('UPDATE acordos_operacionais_parcelas
                SET status = :status,
                    data_pagamento = :data_pagamento,
                    valor_pago = :valor_pago,
                    observacao = NULLIF(CONCAT_WS("\n", NULLIF(observacao, \'\'), NULLIF(:observacao, \'\')), \'\')
                WHERE id = :id
                  AND acordo_operacional_id = :acordo_id
                LIMIT 1');
            $update->bindValue('status', $status);
            $this->bindNullableString($update, 'data_pagamento', $dataPagamentoNormalizada);
            $this->bindNullableDecimal($update, 'valor_pago', $valorPagoNormalizado);
            $this->bindNullableString($update, 'observacao', $observacao);
            $update->bindValue('id', $parcelaId, PDO::PARAM_INT);
            $update->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
            $update->execute();

            $this->registrarAuditoria(
                $acordoId,
                $parcelaId,
                'parcela_status_atualizado',
                'Parcela atualizada para ' . self::statusParcelaLabel($status) . '.',
                $parcelaAntes,
                [
                    'status' => $status,
                    'data_pagamento' => $dataPagamentoNormalizada,
                    'valor_pago' => $valorPagoNormalizado,
                    'observacao_status' => $observacao,
                ],
                null,
                'operacao'
            );

            $this->sincronizarStatusOperacionalPorParcelas($acordoId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,int|float|string|null> */
    public function resumoParcelas(int $acordoId): array
    {
        $fallback = [
            'total' => 0,
            'pendentes' => 0,
            'vencidas' => 0,
            'pagas' => 0,
            'canceladas' => 0,
            'renegociadas' => 0,
            'valor_previsto' => 0.0,
            'valor_pago' => 0.0,
            'valor_aberto' => 0.0,
            'proximo_vencimento' => null,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        $stmt = $this->pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status = 'vencida' OR (status = 'pendente' AND data_vencimento < CURRENT_DATE) THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN status = 'paga' THEN 1 ELSE 0 END) AS pagas,
                SUM(CASE WHEN status = 'cancelada' THEN 1 ELSE 0 END) AS canceladas,
                SUM(CASE WHEN status = 'renegociada' THEN 1 ELSE 0 END) AS renegociadas,
                SUM(valor_parcela) AS valor_previsto,
                SUM(CASE WHEN status = 'paga' THEN COALESCE(valor_pago, valor_parcela) ELSE 0 END) AS valor_pago,
                SUM(CASE WHEN status <> 'paga' THEN valor_parcela ELSE 0 END) AS valor_aberto,
                MIN(CASE WHEN status IN ('pendente','vencida') THEN data_vencimento ELSE NULL END) AS proximo_vencimento
            FROM acordos_operacionais_parcelas
            WHERE acordo_operacional_id = :acordo_id");
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
            'vencidas' => (int) ($row['vencidas'] ?? 0),
            'pagas' => (int) ($row['pagas'] ?? 0),
            'canceladas' => (int) ($row['canceladas'] ?? 0),
            'renegociadas' => (int) ($row['renegociadas'] ?? 0),
            'valor_previsto' => (float) ($row['valor_previsto'] ?? 0),
            'valor_pago' => (float) ($row['valor_pago'] ?? 0),
            'valor_aberto' => (float) ($row['valor_aberto'] ?? 0),
            'proximo_vencimento' => $row['proximo_vencimento'] ?? null,
        ];
    }

    public function marcarPendenteEnvioCredor(int $acordoId, ?string $observacao = null): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de acordos operacionais indisponível.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, credor_id, contrato_id, valor_acordo, valor_entrada, quantidade_parcelas, valor_parcela, data_promessa_pagamento, data_primeira_parcela, forma_pagamento, periodicidade, status_integracao
                FROM acordos_operacionais
                WHERE id = :id
                LIMIT 1
                FOR UPDATE');
            $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
            $stmt->execute();
            $acordo = $stmt->fetch();
            if (!$acordo) {
                throw new InvalidArgumentException('Acordo operacional não encontrado.');
            }
            if (empty($acordo['credor_id'])) {
                throw new InvalidArgumentException('Não é possível preparar envio sem credor vinculado ao contrato.');
            }

            $observacao = $this->normalizarTexto($observacao, 2000);
            $update = $this->pdo->prepare('UPDATE acordos_operacionais
                SET status_integracao = \'pendente_envio\',
                    observacao = NULLIF(CONCAT_WS("\n", NULLIF(observacao, \'\'), NULLIF(:observacao, \'\')), \'\')
                WHERE id = :id
                LIMIT 1');
            $this->bindNullableString($update, 'observacao', $observacao);
            $update->bindValue('id', $acordoId, PDO::PARAM_INT);
            $update->execute();

            $insert = $this->pdo->prepare('INSERT INTO acordo_operacional_integracoes (
                    acordo_operacional_id,
                    credor_id,
                    acao,
                    status,
                    request_payload
                ) VALUES (
                    :acordo_operacional_id,
                    :credor_id,
                    \'criar_acordo\',
                    \'pendente\',
                    :request_payload
                )');
            $insert->bindValue('acordo_operacional_id', $acordoId, PDO::PARAM_INT);
            $insert->bindValue('credor_id', (int) $acordo['credor_id'], PDO::PARAM_INT);
            $insert->bindValue('request_payload', json_encode([
                'acordo_operacional_id' => $acordoId,
                'contrato_id' => $acordo['contrato_id'] ?? null,
                'valor_acordo' => $acordo['valor_acordo'] ?? null,
                'valor_entrada' => $acordo['valor_entrada'] ?? null,
                'quantidade_parcelas' => $acordo['quantidade_parcelas'] ?? null,
                'valor_parcela' => $acordo['valor_parcela'] ?? null,
                'data_promessa_pagamento' => $acordo['data_promessa_pagamento'] ?? null,
                'data_primeira_parcela' => $acordo['data_primeira_parcela'] ?? null,
                'forma_pagamento' => $acordo['forma_pagamento'] ?? null,
                'periodicidade' => $acordo['periodicidade'] ?? null,
                'parcelas' => $this->listarParcelas($acordoId),
                'observacao' => 'Payload placeholder; worker/API do credor será implementado futuramente.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $insert->execute();
            $integracaoId = (int) $this->pdo->lastInsertId();

            $this->registrarAuditoria(
                $acordoId,
                null,
                'integracao_preparada',
                'Acordo marcado como pendente de envio ao credor.',
                ['status_integracao' => $acordo['status_integracao'] ?? null],
                ['status_integracao' => 'pendente_envio', 'integracao_id' => $integracaoId, 'observacao_status' => $observacao],
                null,
                'operacao'
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listarAuditoria(int $acordoId, int $limite = 100): array
    {
        if ($acordoId <= 0 || !$this->auditoriaDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 300));
        $stmt = $this->pdo->prepare("SELECT
                aud.*,
                p.numero_parcela,
                p.tipo AS parcela_tipo,
                p.data_vencimento AS parcela_vencimento,
                p.valor_parcela
            FROM acordos_operacionais_auditoria aud
            LEFT JOIN acordos_operacionais_parcelas p ON p.id = aud.parcela_id
            WHERE aud.acordo_operacional_id = :acordo_id
            ORDER BY aud.criado_em DESC, aud.id DESC
            LIMIT {$limite}");
        $stmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $filtros @return list<array<string,mixed>> */
    public function listarPreventiva(array $filtros = [], int $limite = 200, ?array $escopo = null): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $limite = max(1, min($limite, 500));
        [$where, $params] = $this->filtrosPreventiva($filtros, true, $escopo);
        $sql = "SELECT
                p.*,
                ao.id AS acordo_id,
                ao.pessoa_id,
                ao.contrato_id,
                ao.credor_id,
                ao.valor_acordo,
                ao.status_operacional,
                ao.status_integracao,
                ao.origem,
                ao.operador_nome,
                ao.payload_extra AS acordo_payload_extra,
                ao.criado_em AS acordo_criado_em,
                pe.nome AS pessoa_nome,
                pe.documento AS pessoa_documento,
                c.codigo_contrato,
                c.produto,
                cr.nome AS credor_nome,
                car.nome AS carteira_nome
            FROM acordos_operacionais_parcelas p
            INNER JOIN acordos_operacionais ao ON ao.id = p.acordo_operacional_id
            INNER JOIN pessoas pe ON pe.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = ao.credor_id
            LEFT JOIN carteiras car ON car.id = c.carteira_id
            WHERE {$where}
            ORDER BY
                CASE
                    WHEN p.status IN ('pendente','vencida') AND p.data_vencimento < CURRENT_DATE THEN 0
                    WHEN p.status IN ('pendente','vencida') AND p.data_vencimento = CURRENT_DATE THEN 1
                    WHEN p.status = 'pendente' THEN 2
                    WHEN p.status = 'paga' THEN 3
                    ELSE 4
                END,
                p.data_vencimento ASC,
                p.id ASC
            LIMIT {$limite}";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $filtros @return array<string,int|float> */
    public function resumoPreventiva(array $filtros = [], ?array $escopo = null): array
    {
        $fallback = [
            'total_aberto' => 0,
            'vencidas' => 0,
            'vencem_hoje' => 0,
            'entradas_hoje' => 0,
            'parcelas_hoje' => 0,
            'pagas_hoje' => 0,
            'valor_previsto_hoje' => 0.0,
            'valor_aberto_vencido' => 0.0,
            'valor_pago_hoje' => 0.0,
            'valor_aberto_total' => 0.0,
        ];

        if (!$this->moduloDisponivel()) {
            return $fallback;
        }

        [$where, $params] = $this->filtrosPreventiva($filtros, false, $escopo);
        $stmt = $this->pdo->prepare("SELECT
                SUM(CASE WHEN p.status IN ('pendente','vencida') THEN 1 ELSE 0 END) AS total_aberto,
                SUM(CASE WHEN p.status IN ('pendente','vencida') AND p.data_vencimento < CURRENT_DATE THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN p.status IN ('pendente','vencida') AND p.data_vencimento = CURRENT_DATE THEN 1 ELSE 0 END) AS vencem_hoje,
                SUM(CASE WHEN p.status IN ('pendente','vencida') AND p.tipo = 'entrada' AND p.data_vencimento = CURRENT_DATE THEN 1 ELSE 0 END) AS entradas_hoje,
                SUM(CASE WHEN p.status IN ('pendente','vencida') AND p.tipo = 'parcela' AND p.data_vencimento = CURRENT_DATE THEN 1 ELSE 0 END) AS parcelas_hoje,
                SUM(CASE WHEN p.status = 'paga' AND p.data_pagamento = CURRENT_DATE THEN 1 ELSE 0 END) AS pagas_hoje,
                SUM(CASE WHEN p.status IN ('pendente','vencida') AND p.data_vencimento = CURRENT_DATE THEN p.valor_parcela ELSE 0 END) AS valor_previsto_hoje,
                SUM(CASE WHEN p.status IN ('pendente','vencida') AND p.data_vencimento < CURRENT_DATE THEN p.valor_parcela ELSE 0 END) AS valor_aberto_vencido,
                SUM(CASE WHEN p.status = 'paga' AND p.data_pagamento = CURRENT_DATE THEN COALESCE(p.valor_pago, p.valor_parcela) ELSE 0 END) AS valor_pago_hoje,
                SUM(CASE WHEN p.status IN ('pendente','vencida') THEN p.valor_parcela ELSE 0 END) AS valor_aberto_total
            FROM acordos_operacionais_parcelas p
            INNER JOIN acordos_operacionais ao ON ao.id = p.acordo_operacional_id
            INNER JOIN pessoas pe ON pe.id = ao.pessoa_id
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            LEFT JOIN credores cr ON cr.id = ao.credor_id
            WHERE {$where}");
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'total_aberto' => (int) ($row['total_aberto'] ?? 0),
            'vencidas' => (int) ($row['vencidas'] ?? 0),
            'vencem_hoje' => (int) ($row['vencem_hoje'] ?? 0),
            'entradas_hoje' => (int) ($row['entradas_hoje'] ?? 0),
            'parcelas_hoje' => (int) ($row['parcelas_hoje'] ?? 0),
            'pagas_hoje' => (int) ($row['pagas_hoje'] ?? 0),
            'valor_previsto_hoje' => (float) ($row['valor_previsto_hoje'] ?? 0),
            'valor_aberto_vencido' => (float) ($row['valor_aberto_vencido'] ?? 0),
            'valor_pago_hoje' => (float) ($row['valor_pago_hoje'] ?? 0),
            'valor_aberto_total' => (float) ($row['valor_aberto_total'] ?? 0),
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_OPERACIONAL[(string) $status] ?? 'Pendente';
    }

    public static function integracaoLabel(?string $status): string
    {
        return self::STATUS_INTEGRACAO[(string) $status] ?? 'Não enviado';
    }

    public static function origemLabel(?string $origem): string
    {
        return self::ORIGENS[(string) $origem] ?? 'Manual';
    }

    public static function periodicidadeLabel(?string $periodicidade): string
    {
        return self::PERIODICIDADES[(string) $periodicidade] ?? 'Mensal';
    }

    public static function formaPagamentoLabel(?string $forma): string
    {
        return self::FORMAS_PAGAMENTO[(string) $forma] ?? 'Não informado';
    }

    public static function statusParcelaLabel(?string $status): string
    {
        return self::STATUS_PARCELA[(string) $status] ?? 'Pendente';
    }

    private function auditoriaDisponivel(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'acordos_operacionais_auditoria'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed>|null $antes @param array<string,mixed>|null $depois */
    private function registrarAuditoria(
        int $acordoId,
        ?int $parcelaId,
        string $evento,
        ?string $descricao,
        ?array $antes,
        ?array $depois,
        ?string $usuarioNome = null,
        string $origem = 'sistema',
        ?array $payloadExtra = null
    ): void {
        if ($acordoId <= 0 || !$this->auditoriaDisponivel()) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO acordos_operacionais_auditoria (
                acordo_operacional_id,
                parcela_id,
                evento,
                descricao,
                usuario_nome,
                origem,
                dados_antes,
                dados_depois,
                payload_extra
            ) VALUES (
                :acordo_operacional_id,
                :parcela_id,
                :evento,
                :descricao,
                :usuario_nome,
                :origem,
                :dados_antes,
                :dados_depois,
                :payload_extra
            )');
        $stmt->bindValue('acordo_operacional_id', $acordoId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'parcela_id', $parcelaId);
        $stmt->bindValue('evento', $this->normalizarTexto($evento, 80) ?? 'evento');
        $this->bindNullableString($stmt, 'descricao', $this->normalizarTexto($descricao, 2000));
        $this->bindNullableString($stmt, 'usuario_nome', $this->normalizarTexto($usuarioNome, 120));
        $stmt->bindValue('origem', $this->normalizarTexto($origem, 80) ?? 'sistema');
        $this->bindNullableString($stmt, 'dados_antes', $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->bindNullableString($stmt, 'dados_depois', $depois === null ? null : json_encode($depois, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->bindNullableString($stmt, 'payload_extra', $payloadExtra === null ? null : json_encode($payloadExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();
    }

    /** @param array<string,mixed> $filtros @return array{0:string,1:array<string,mixed>} */
    private function filtrosPreventiva(array $filtros, bool $aplicarPeriodoPadrao = true, ?array $escopo = null): array
    {
        $where = ['1=1'];
        $params = [];

        $situacao = (string) ($filtros['situacao'] ?? 'abertas');
        if ($situacao === 'vencidas') {
            $where[] = "p.status IN ('pendente','vencida') AND p.data_vencimento < CURRENT_DATE";
        } elseif ($situacao === 'hoje') {
            $where[] = "p.status IN ('pendente','vencida') AND p.data_vencimento = CURRENT_DATE";
        } elseif ($situacao === 'proximos') {
            $where[] = "p.status IN ('pendente','vencida') AND p.data_vencimento > CURRENT_DATE";
        } elseif ($situacao === 'pagas_hoje') {
            $where[] = "p.status = 'paga' AND p.data_pagamento = CURRENT_DATE";
        } elseif ($situacao === 'pagas') {
            $where[] = "p.status = 'paga'";
        } elseif ($situacao === 'todos') {
            // sem filtro por situacao
        } else {
            $where[] = "p.status IN ('pendente','vencida')";
        }

        $status = (string) ($filtros['status'] ?? 'todos');
        if (array_key_exists($status, self::STATUS_PARCELA)) {
            $where[] = 'p.status = :status_parcela';
            $params['status_parcela'] = $status;
        }

        $tipo = (string) ($filtros['tipo'] ?? 'todos');
        if (in_array($tipo, ['entrada', 'parcela'], true)) {
            $where[] = 'p.tipo = :tipo';
            $params['tipo'] = $tipo;
        }

        $credorId = $this->normalizarId($filtros['credor_id'] ?? null);
        if ($credorId !== null) {
            $where[] = 'ao.credor_id = :credor_id';
            $params['credor_id'] = $credorId;
        }

        $statusAcordo = (string) ($filtros['status_acordo'] ?? 'ativos');
        if ($statusAcordo === 'ativos') {
            $where[] = "ao.status_operacional IN ('pendente','formalizado')";
        } elseif (array_key_exists($statusAcordo, self::STATUS_OPERACIONAL)) {
            $where[] = 'ao.status_operacional = :status_acordo';
            $params['status_acordo'] = $statusAcordo;
        }

        $scope = EscopoCredorSql::acordo($escopo, 'ao', 'c', 'preventiva_scope');
        $where[] = $scope['sql'];
        $params = array_merge($params, $scope['params']);

        $dataInicio = $this->normalizarDataFiltro($filtros['data_inicio'] ?? null);
        $dataFim = $this->normalizarDataFiltro($filtros['data_fim'] ?? null);
        if ($dataInicio !== null) {
            $where[] = 'p.data_vencimento >= :data_inicio';
            $params['data_inicio'] = $dataInicio;
        }
        if ($dataFim !== null) {
            $where[] = 'p.data_vencimento <= :data_fim';
            $params['data_fim'] = $dataFim;
        }

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(pe.nome LIKE :q_like_nome OR pe.documento LIKE :q_digits OR c.codigo_contrato LIKE :q_like_contrato OR c.produto LIKE :q_like_produto)';
            $params['q_like_nome'] = '%' . $q . '%';
            $params['q_like_contrato'] = '%' . $q . '%';
            $params['q_like_produto'] = '%' . $q . '%';
            $digits = preg_replace('/\D+/', '', $q) ?: $q;
            $params['q_digits'] = '%' . $digits . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private function sincronizarStatusOperacionalPorParcelas(int $acordoId): void
    {
        $stmt = $this->pdo->prepare('SELECT status_operacional, quantidade_parcelas
            FROM acordos_operacionais
            WHERE id = :id
            LIMIT 1
            FOR UPDATE');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $acordo = $stmt->fetch();

        if (!$acordo) {
            throw new InvalidArgumentException('Acordo operacional não encontrado.');
        }

        $resumoStmt = $this->pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'paga' THEN 1 ELSE 0 END) AS pagas,
                SUM(CASE WHEN status = 'cancelada' THEN 1 ELSE 0 END) AS canceladas,
                SUM(CASE WHEN status = 'renegociada' THEN 1 ELSE 0 END) AS renegociadas,
                SUM(CASE WHEN status = 'vencida' OR (status = 'pendente' AND data_vencimento < CURRENT_DATE) THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                MIN(CASE WHEN status IN ('pendente','vencida') THEN data_vencimento ELSE NULL END) AS primeiro_vencimento_aberto,
                MIN(CASE
                    WHEN status IN ('pendente','vencida')
                    THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_extra, '$.acme.deadline_date')), ''), 'null')
                    ELSE NULL
                END) AS primeiro_deadline_credor
            FROM acordos_operacionais_parcelas
            WHERE acordo_operacional_id = :acordo_id");
        $resumoStmt->bindValue('acordo_id', $acordoId, PDO::PARAM_INT);
        $resumoStmt->execute();
        $resumo = $resumoStmt->fetch() ?: [];

        $total = (int) ($resumo['total'] ?? 0);
        if ($total <= 0) {
            return;
        }
        // Alguns credores liberam apenas a parcela corrente. Não encerrar o acordo
        // enquanto a quantidade de parcelas persistidas for menor que a contratada.
        $totalEsperado = max($total, (int) ($acordo['quantidade_parcelas'] ?? 0));
        $parcelasCompletas = $total >= $totalEsperado;

        $statusAtual = (string) ($acordo['status_operacional'] ?? 'pendente');
        $pagas = (int) ($resumo['pagas'] ?? 0);
        $canceladas = (int) ($resumo['canceladas'] ?? 0);
        $renegociadas = (int) ($resumo['renegociadas'] ?? 0);
        $vencidas = (int) ($resumo['vencidas'] ?? 0);
        $pendentes = (int) ($resumo['pendentes'] ?? 0);
        $novoStatus = null;

        if ($parcelasCompletas && $pagas >= $totalEsperado) {
            $novoStatus = 'cumprido';
        } elseif ($parcelasCompletas && $canceladas >= $totalEsperado) {
            $novoStatus = 'cancelado';
        } elseif ($vencidas > 0 && $statusAtual !== 'cancelado') {
            $deadlineCredor = trim((string) ($resumo['primeiro_deadline_credor'] ?? ''));
            if ($deadlineCredor !== '') {
                try {
                    $carenciaExpirada = (new DateTimeImmutable($deadlineCredor))->setTime(0, 0)
                        < (new DateTimeImmutable('today'))->setTime(0, 0);
                } catch (Throwable) {
                    $carenciaExpirada = false;
                }
            } else {
                $credorProduto = $this->credorProdutoDoAcordo($acordoId);
                $situacaoCarencia = $this->situacaoCarencia(
                    $resumo['primeiro_vencimento_aberto'] ?? null,
                    (int) ($credorProduto['credor_id'] ?? 0),
                    (string) ($credorProduto['produto'] ?? ''),
                    'vencida'
                );
                $carenciaExpirada = (bool) $situacaoCarencia['expirada'];
            }
            if ($carenciaExpirada) {
                $novoStatus = 'quebrado';
            }
        } elseif ($statusAtual === 'cumprido' && ($pendentes > 0 || $vencidas > 0 || $renegociadas > 0 || $canceladas > 0)) {
            $novoStatus = 'pendente';
        } elseif ($statusAtual === 'quebrado' && $vencidas === 0 && $pagas < $total && $canceladas < $total) {
            $novoStatus = 'pendente';
        }

        if ($novoStatus !== null && $novoStatus !== $statusAtual) {
            if ($this->statusOperacionalAtivo($novoStatus)) {
                $contratoId = $this->contratoDoAcordo($acordoId);
                if ($contratoId !== null) {
                    $this->validarContratoSemAcordoAtivo($contratoId, $acordoId);
                }
            }

            $update = $this->pdo->prepare('UPDATE acordos_operacionais
                SET status_operacional = :status,
                    status_atualizado_em = NOW()
                WHERE id = :id
                LIMIT 1');
            $update->bindValue('status', $novoStatus);
            $update->bindValue('id', $acordoId, PDO::PARAM_INT);
            $update->execute();

            $this->registrarAuditoria(
                $acordoId,
                null,
                'status_acordo_recalculado',
                'Status geral recalculado automaticamente pelo controle de parcelas.',
                ['status_operacional' => $statusAtual],
                ['status_operacional' => $novoStatus, 'resumo_parcelas' => $resumo],
                null,
                'sistema'
            );
        }
    }

    private function statusOperacionalAtivo(string $status): bool
    {
        return in_array($status, self::STATUS_OPERACIONAL_ATIVO, true);
    }

    /** @return array{credor_id:int,produto:string} */
    private function credorProdutoDoAcordo(int $acordoId): array
    {
        $stmt = $this->pdo->prepare('SELECT
                COALESCE(ao.credor_id, c.credor_id) AS credor_id,
                COALESCE(c.produto, \'\') AS produto
            FROM acordos_operacionais ao
            LEFT JOIN contratos c ON c.id = ao.contrato_id
            WHERE ao.id = :id
            LIMIT 1');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'credor_id' => (int) ($row['credor_id'] ?? 0),
            'produto' => (string) ($row['produto'] ?? ''),
        ];
    }

    private function contratoDoAcordo(int $acordoId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT contrato_id
            FROM acordos_operacionais
            WHERE id = :id
            LIMIT 1');
        $stmt->bindValue('id', $acordoId, PDO::PARAM_INT);
        $stmt->execute();
        $valor = $stmt->fetchColumn();

        return $valor === false || $valor === null ? null : (int) $valor;
    }

    private function validarContratoSemAcordoAtivo(int $contratoId, ?int $ignorarAcordoId = null): void
    {
        if ($contratoId <= 0) {
            return;
        }

        $sql = "SELECT
                ao.id,
                ao.status_operacional,
                ao.valor_acordo,
                ao.data_promessa_pagamento,
                p.nome AS pessoa_nome
            FROM acordos_operacionais ao
            LEFT JOIN pessoas p ON p.id = ao.pessoa_id
            WHERE ao.contrato_id = :contrato_id
              AND ao.status_operacional IN ('pendente','formalizado')";

        if ($ignorarAcordoId !== null) {
            $sql .= ' AND ao.id <> :ignorar_acordo_id';
        }

        $sql .= ' ORDER BY ao.id DESC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('contrato_id', $contratoId, PDO::PARAM_INT);
        if ($ignorarAcordoId !== null) {
            $stmt->bindValue('ignorar_acordo_id', $ignorarAcordoId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $acordoAtivo = $stmt->fetch();

        if (!$acordoAtivo) {
            return;
        }

        $partes = ['Já existe acordo ativo para este contrato'];
        $partes[] = '#'.(int) $acordoAtivo['id'];
        if (!empty($acordoAtivo['pessoa_nome'])) {
            $partes[] = 'cliente: '.(string) $acordoAtivo['pessoa_nome'];
        }
        if (!empty($acordoAtivo['valor_acordo'])) {
            $partes[] = 'valor: R$ '.number_format((float) $acordoAtivo['valor_acordo'], 2, ',', '.');
        }
        if (!empty($acordoAtivo['data_promessa_pagamento'])) {
            $partes[] = 'vencimento/base: '.(new DateTimeImmutable((string) $acordoAtivo['data_promessa_pagamento']))->format('d/m/Y');
        }

        throw new InvalidArgumentException(implode(' · ', $partes) . '. Quebre, cancele ou cumpra o acordo atual antes de abrir outro acordo para o mesmo contrato.');
    }

    private function credorDoContratoDaPessoa(int $pessoaId, int $contratoId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT credor_id FROM contratos WHERE id = :id AND pessoa_id = :pessoa_id LIMIT 1');
        $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $valor = $stmt->fetchColumn();

        return $valor === false || $valor === null ? null : (int) $valor;
    }

    /** @param list<array<string,mixed>> $parcelas */
    private function inserirParcelas(int $acordoId, array $parcelas): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO acordos_operacionais_parcelas (
                acordo_operacional_id,
                numero_parcela,
                tipo,
                data_vencimento,
                valor_parcela,
                status,
                observacao,
                payload_extra
            ) VALUES (
                :acordo_operacional_id,
                :numero_parcela,
                :tipo,
                :data_vencimento,
                :valor_parcela,
                \'pendente\',
                :observacao,
                :payload_extra
            )');

        foreach ($parcelas as $parcela) {
            $stmt->bindValue('acordo_operacional_id', $acordoId, PDO::PARAM_INT);
            $stmt->bindValue('numero_parcela', (int) $parcela['numero_parcela'], PDO::PARAM_INT);
            $stmt->bindValue('tipo', (string) $parcela['tipo']);
            $stmt->bindValue('data_vencimento', (string) $parcela['data_vencimento']);
            $stmt->bindValue('valor_parcela', number_format((float) $parcela['valor_parcela'], 2, '.', ''));
            $this->bindNullableString($stmt, 'observacao', $parcela['observacao'] ?? null);
            $stmt->bindValue('payload_extra', json_encode($parcela['payload_extra'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
        }
    }

    /** @return list<array<string,mixed>> */
    private function gerarParcelas(float $valorTotal, ?float $valorEntrada, ?string $dataEntrada, int $quantidadeParcelas, float $valorParcelaReferencia, string $dataPrimeiraParcela, string $periodicidade): array
    {
        $parcelas = [];
        $valorEntrada = $valorEntrada !== null ? round($valorEntrada, 2) : null;
        $valorRestante = round($valorTotal - (float) ($valorEntrada ?? 0.0), 2);

        if ($valorEntrada !== null && $valorEntrada > 0 && $dataEntrada !== null) {
            $parcelas[] = [
                'numero_parcela' => 0,
                'tipo' => 'entrada',
                'data_vencimento' => $dataEntrada,
                'valor_parcela' => $valorEntrada,
                'observacao' => 'Entrada do acordo operacional.',
                'payload_extra' => ['gerado_automaticamente' => true],
            ];
        }

        $dataBase = new DateTimeImmutable($dataPrimeiraParcela);
        $somaParcelas = 0.0;
        for ($i = 1; $i <= $quantidadeParcelas; $i++) {
            $valor = $i === $quantidadeParcelas
                ? round($valorRestante - $somaParcelas, 2)
                : $valorParcelaReferencia;
            $somaParcelas = round($somaParcelas + $valor, 2);

            $parcelas[] = [
                'numero_parcela' => $i,
                'tipo' => 'parcela',
                'data_vencimento' => $this->dataParcela($dataBase, $i, $periodicidade),
                'valor_parcela' => $valor,
                'observacao' => 'Parcela gerada automaticamente no registro do acordo.',
                'payload_extra' => [
                    'gerado_automaticamente' => true,
                    'periodicidade' => $periodicidade,
                ],
            ];
        }

        return $parcelas;
    }

    private function dataParcela(DateTimeImmutable $dataBase, int $numeroParcela, string $periodicidade): string
    {
        $incrementos = max(0, $numeroParcela - 1);
        if ($incrementos === 0) {
            return $dataBase->format('Y-m-d');
        }

        return match ($periodicidade) {
            'semanal' => $dataBase->add(new DateInterval('P' . (7 * $incrementos) . 'D'))->format('Y-m-d'),
            'quinzenal' => $dataBase->add(new DateInterval('P' . (15 * $incrementos) . 'D'))->format('Y-m-d'),
            'unica' => $dataBase->format('Y-m-d'),
            default => $dataBase->add(new DateInterval('P' . $incrementos . 'M'))->format('Y-m-d'),
        };
    }

    private function normalizarStatusOperacional(mixed $value): string
    {
        $status = trim((string) $value);
        if (!array_key_exists($status, self::STATUS_OPERACIONAL)) {
            throw new InvalidArgumentException('Status do acordo inválido.');
        }

        return $status;
    }

    private function normalizarStatusIntegracao(mixed $value): string
    {
        $status = trim((string) $value);
        if (!array_key_exists($status, self::STATUS_INTEGRACAO)) {
            throw new InvalidArgumentException('Status de integração inválido.');
        }

        return $status;
    }

    private function normalizarStatusParcela(mixed $value): string
    {
        $status = trim((string) $value);
        if (!array_key_exists($status, self::STATUS_PARCELA)) {
            throw new InvalidArgumentException('Status da parcela inválido.');
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

    private function normalizarPeriodicidade(mixed $value): string
    {
        $periodicidade = trim((string) $value);
        if (!array_key_exists($periodicidade, self::PERIODICIDADES)) {
            return 'mensal';
        }

        return $periodicidade;
    }

    private function normalizarFormaPagamento(mixed $value): string
    {
        $forma = trim((string) $value);
        if (!array_key_exists($forma, self::FORMAS_PAGAMENTO)) {
            return 'nao_informado';
        }

        return $forma;
    }

    private function normalizarQuantidadeParcelas(mixed $value): int
    {
        $qtd = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        if ($qtd === false) {
            throw new InvalidArgumentException('Informe uma quantidade de parcelas entre 1 e 120.');
        }

        return (int) $qtd;
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
        $valor = $this->normalizarDecimalOpcional($value, $campo);
        if ($valor === null) {
            throw new InvalidArgumentException('Informe o ' . $campo . '.');
        }
        if ($valor <= 0) {
            throw new InvalidArgumentException('O ' . $campo . ' deve ser maior que zero.');
        }

        return $valor;
    }

    private function normalizarDecimalOpcional(mixed $value, string $campo): ?float
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
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

        $valor = round((float) $texto, 2);
        if ($valor < 0) {
            throw new InvalidArgumentException('O ' . $campo . ' não pode ser negativo.');
        }

        return $valor;
    }

    private function normalizarDataObrigatoria(mixed $value, string $campo): string
    {
        $data = $this->normalizarDataOpcional($value);
        if ($data === null) {
            throw new InvalidArgumentException('Informe a ' . $campo . '.');
        }

        return $data;
    }

    private function normalizarDataOpcional(mixed $value): ?string
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

    private function normalizarDataFiltro(mixed $value): ?string
    {
        return $this->normalizarDataOpcional($value);
    }

    private function normalizarDataHoraOpcional(mixed $value): ?string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            return null;
        }

        try {
            $data = new DateTimeImmutable($texto);
        } catch (Throwable) {
            return null;
        }

        // Nunca aceita data futura (evita corromper o registro com uma
        // data mal formatada vinda da API) nem antes da existência do CRM.
        if ($data > new DateTimeImmutable('now') || $data->format('Y') < '2020') {
            return null;
        }

        return $data->format('Y-m-d H:i:s');
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

    private function bindNullableDecimal(\PDOStatement $stmt, string $key, ?float $value): void
    {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, number_format($value, 2, '.', ''));
    }
}
