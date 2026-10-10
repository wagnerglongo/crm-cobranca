<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

final class AcionamentoExternoRepository
{
    private ?bool $moduloDisponivel = null;
    private ?bool $estadoDisponivel = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function moduloDisponivel(): bool
    {
        if ($this->moduloDisponivel !== null) {
            return $this->moduloDisponivel;
        }
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'integracao_acionamentos_externos'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }
        return $this->moduloDisponivel;
    }

    /**
     * @param array<string,mixed> $integracao
     * @param list<array<string,mixed>> $itens
     */
    public function sincronizar(array $integracao, int $pessoaId, ?int $contratoId, string $documento, array $itens): int
    {
        if (!$this->moduloDisponivel()) {
            return 0;
        }

        $integracaoId = (int) ($integracao['id'] ?? 0);
        $credorId = (int) ($integracao['credor_id'] ?? 0);
        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        if ($integracaoId <= 0 || $credorId <= 0 || $pessoaId <= 0 || $documento === '') {
            return 0;
        }

        $stmt = $this->pdo->prepare('INSERT INTO integracao_acionamentos_externos (
                integracao_id, credor_id, pessoa_id, contrato_id,
                identificador_externo, registro_externo, documento, codigo_contrato,
                observacao, codigo_acao_externa, descricao_acao_externa,
                agendado_em, ocorrido_em, origem, payload_original, sincronizado_em
            ) VALUES (
                :integracao_id, :credor_id, :pessoa_id, :contrato_id,
                :identificador_externo, :registro_externo, :documento, :codigo_contrato,
                :observacao, :codigo_acao_externa, :descricao_acao_externa,
                :agendado_em, :ocorrido_em, \'api_consulta\', :payload_original, CURRENT_TIMESTAMP(3)
            ) ON DUPLICATE KEY UPDATE
                pessoa_id = VALUES(pessoa_id),
                contrato_id = VALUES(contrato_id),
                registro_externo = VALUES(registro_externo),
                documento = VALUES(documento),
                codigo_contrato = VALUES(codigo_contrato),
                observacao = VALUES(observacao),
                codigo_acao_externa = VALUES(codigo_acao_externa),
                descricao_acao_externa = VALUES(descricao_acao_externa),
                agendado_em = VALUES(agendado_em),
                ocorrido_em = VALUES(ocorrido_em),
                payload_original = VALUES(payload_original),
                sincronizado_em = CURRENT_TIMESTAMP(3)');

        $total = 0;
        $iniciou = !$this->pdo->inTransaction();
        try {
            if ($iniciou) {
                $this->pdo->beginTransaction();
            }
            /** @var array<string,?int> $contratosResolvidos */
            $contratosResolvidos = [];
            foreach ($itens as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemDocumento = preg_replace('/\D+/', '', (string) ($item['DocNumber'] ?? $documento)) ?? '';
                if ($itemDocumento !== '' && $itemDocumento !== $documento) {
                    // Resposta defensiva: nunca associe ao cliente aberto um item de outro documento.
                    continue;
                }
                if ($itemDocumento === '') {
                    $itemDocumento = $documento;
                }

                $codigoContrato = trim((string) ($item['Contract'] ?? ''));
                if (!array_key_exists($codigoContrato, $contratosResolvidos)) {
                    $contratosResolvidos[$codigoContrato] = $this->resolverContratoId($credorId, $pessoaId, $codigoContrato);
                }
                $contratoItemId = $contratosResolvidos[$codigoContrato];
                $trackingId = trim((string) ($item['TrackingID'] ?? ''));
                $identificador = $trackingId !== ''
                    ? 'tracking:' . $trackingId
                    : 'hash:' . hash('sha256', json_encode([
                        $item['RecordID'] ?? null,
                        $item['DocNumber'] ?? $documento,
                        $codigoContrato,
                        $item['ActionID'] ?? null,
                        $item['CreationDate'] ?? null,
                        $item['Notes'] ?? null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

                $stmt->bindValue('integracao_id', $integracaoId, PDO::PARAM_INT);
                $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
                $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
                $this->bindNullableInt($stmt, 'contrato_id', $contratoItemId);
                $stmt->bindValue('identificador_externo', mb_substr($identificador, 0, 160));
                $this->bindNullableString($stmt, 'registro_externo', $this->nullable($item['RecordID'] ?? null, 160));
                $stmt->bindValue('documento', mb_substr($itemDocumento, 0, 20));
                $this->bindNullableString($stmt, 'codigo_contrato', $this->nullable($codigoContrato, 120));
                $this->bindNullableString($stmt, 'observacao', $this->nullable($item['Notes'] ?? null, 65535));
                $this->bindNullableString($stmt, 'codigo_acao_externa', $this->nullable($item['ActionID'] ?? null, 120));
                $this->bindNullableString($stmt, 'descricao_acao_externa', $this->nullable($item['ActionDescription'] ?? null, 255));
                $this->bindNullableString($stmt, 'agendado_em', $this->normalizarData($item['ScheduleDateTime'] ?? null));
                $this->bindNullableString($stmt, 'ocorrido_em', $this->normalizarData($item['CreationDate'] ?? null));
                $stmt->bindValue('payload_original', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $stmt->execute();
                $total++;
            }

            $this->registrarEstadoConsulta($integracaoId, $credorId, $documento, 'sucesso', $total, null);
            if ($iniciou) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($iniciou && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $total;
    }

    /** @param array<string,mixed> $integracao */
    public function registrarFalhaConsulta(array $integracao, string $documento, string $erro): void
    {
        $integracaoId = (int) ($integracao['id'] ?? 0);
        $credorId = (int) ($integracao['credor_id'] ?? 0);
        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        if ($integracaoId <= 0 || $credorId <= 0 || $documento === '') {
            return;
        }
        $this->registrarEstadoConsulta($integracaoId, $credorId, $documento, 'erro', 0, $erro);
    }

    /** @return list<array<string,mixed>> */
    public function listar(int $credorId, string $documento, ?int $contratoId = null, int $limite = 200): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }
        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        if ($credorId <= 0 || $documento === '') {
            return [];
        }
        $limite = max(1, min(500, $limite));
        $stmt = $this->pdo->prepare('SELECT
                iae.*,
                c.codigo_contrato AS contrato_crm_codigo,
                ci.nome AS integracao_nome,
                (
                    SELECT ar.nome
                    FROM integracao_tabulacao_mapeamentos itm
                    INNER JOIN atendimento_resultados ar ON ar.id = itm.resultado_id
                    WHERE itm.integracao_id = iae.integracao_id
                      AND itm.codigo_externo = iae.codigo_acao_externa
                      AND itm.ativo = 1
                      AND itm.produto IN (\'\', COALESCE(c.produto, \'\'))
                    ORDER BY CASE WHEN itm.produto = COALESCE(c.produto, \'\') AND itm.produto <> \'\' THEN 0 ELSE 1 END, itm.id DESC
                    LIMIT 1
                ) AS resultado_local_nome,
                (
                    SELECT ar.codigo
                    FROM integracao_tabulacao_mapeamentos itm
                    INNER JOIN atendimento_resultados ar ON ar.id = itm.resultado_id
                    WHERE itm.integracao_id = iae.integracao_id
                      AND itm.codigo_externo = iae.codigo_acao_externa
                      AND itm.ativo = 1
                      AND itm.produto IN (\'\', COALESCE(c.produto, \'\'))
                    ORDER BY CASE WHEN itm.produto = COALESCE(c.produto, \'\') AND itm.produto <> \'\' THEN 0 ELSE 1 END, itm.id DESC
                    LIMIT 1
                ) AS resultado_local_codigo
            FROM integracao_acionamentos_externos iae
            INNER JOIN credor_integracoes ci ON ci.id = iae.integracao_id
            LEFT JOIN contratos c ON c.id = iae.contrato_id
            WHERE iae.credor_id = :credor_id
              AND iae.documento = :documento
            ORDER BY COALESCE(iae.ocorrido_em, iae.sincronizado_em) DESC, iae.id DESC
            LIMIT ' . $limite);
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('documento', $documento);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function ultimaSincronizacao(int $credorId, string $documento): ?string
    {
        if (!$this->moduloDisponivel()) {
            return null;
        }
        $documento = preg_replace('/\D+/', '', $documento) ?? '';
        if ($credorId <= 0 || $documento === '') {
            return null;
        }
        if ($this->estadoDisponivel()) {
            $stmt = $this->pdo->prepare("SELECT MAX(ultima_sincronizacao)
                FROM integracao_consultas_estado
                WHERE credor_id = :credor_id
                  AND recurso = 'acionamentos'
                  AND chave_consulta = :documento
                  AND status = 'sucesso'");
            $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
            $stmt->bindValue('documento', $documento);
            $stmt->execute();
            $value = $stmt->fetchColumn();
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $stmt = $this->pdo->prepare('SELECT MAX(sincronizado_em)
            FROM integracao_acionamentos_externos
            WHERE credor_id = :credor_id AND documento = :documento');
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('documento', $documento);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function estadoDisponivel(): bool
    {
        if ($this->estadoDisponivel !== null) {
            return $this->estadoDisponivel;
        }
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'integracao_consultas_estado'");
            $this->estadoDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->estadoDisponivel = false;
        }
        return $this->estadoDisponivel;
    }

    private function registrarEstadoConsulta(
        int $integracaoId,
        int $credorId,
        string $documento,
        string $status,
        int $totalItens,
        ?string $erro
    ): void {
        if (!$this->estadoDisponivel()) {
            return;
        }
        $status = $status === 'erro' ? 'erro' : 'sucesso';
        $stmt = $this->pdo->prepare("INSERT INTO integracao_consultas_estado (
                integracao_id, credor_id, recurso, chave_consulta, status,
                total_itens, ultima_sincronizacao, ultima_tentativa_em, ultimo_erro
            ) VALUES (
                :integracao_id, :credor_id, 'acionamentos', :documento, :status,
                :total_itens,
                CASE WHEN :status_sucesso = 'sucesso' THEN CURRENT_TIMESTAMP(3) ELSE NULL END,
                CURRENT_TIMESTAMP(3), :ultimo_erro
            ) ON DUPLICATE KEY UPDATE
                credor_id = VALUES(credor_id),
                status = VALUES(status),
                total_itens = VALUES(total_itens),
                ultima_sincronizacao = CASE
                    WHEN VALUES(status) = 'sucesso' THEN CURRENT_TIMESTAMP(3)
                    ELSE ultima_sincronizacao
                END,
                ultima_tentativa_em = CURRENT_TIMESTAMP(3),
                ultimo_erro = VALUES(ultimo_erro)");
        $stmt->bindValue('integracao_id', $integracaoId, PDO::PARAM_INT);
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('documento', mb_substr($documento, 0, 190));
        $stmt->bindValue('status', $status);
        $stmt->bindValue('status_sucesso', $status);
        $stmt->bindValue('total_itens', max(0, $totalItens), PDO::PARAM_INT);
        $this->bindNullableString($stmt, 'ultimo_erro', $erro === null ? null : mb_substr(trim($erro), 0, 12000));
        $stmt->execute();
    }

    private function resolverContratoId(int $credorId, int $pessoaId, string $codigoContrato): ?int
    {
        if ($codigoContrato === '' || $pessoaId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id
            FROM contratos
            WHERE credor_id = :credor_id
              AND pessoa_id = :pessoa_id
              AND codigo_contrato = :codigo
            ORDER BY id DESC
            LIMIT 1');
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue('codigo', $codigoContrato);
        $stmt->execute();
        $id = (int) ($stmt->fetchColumn() ?: 0);

        // A ACME pode devolver vários contratos do documento. A associação só é
        // aceita quando credor, pessoa e código do contrato coincidem no CRM.
        return $id > 0 ? $id : null;
    }

    private function normalizarData(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            $local = new DateTimeZone('America/Sao_Paulo');
            return (new DateTimeImmutable($raw, $local))
                ->setTimezone($local)
                ->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            return null;
        }
    }

    private function nullable(mixed $value, int $limit): ?string
    {
        $raw = trim((string) ($value ?? ''));
        return $raw === '' ? null : mb_substr($raw, 0, $limit);
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
