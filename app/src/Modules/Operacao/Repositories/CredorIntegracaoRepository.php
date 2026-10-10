<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class CredorIntegracaoRepository
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
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'credor_integracoes'");
            $integracoes = $stmt !== false && $stmt->fetchColumn() !== false;
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'integracao_tabulacao_mapeamentos'");
            $mapeamentos = $stmt !== false && $stmt->fetchColumn() !== false;
            $this->moduloDisponivel = $integracoes && $mapeamentos;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @return list<array<string,mixed>> */
    public function listarPorCredor(int $credorId, bool $somenteAtivas = false): array
    {
        if (!$this->moduloDisponivel() || $credorId <= 0) {
            return [];
        }

        $sql = 'SELECT ci.*,
                c.codigo AS credor_codigo,
                c.nome AS credor_nome,
                (SELECT COUNT(*) FROM integracao_tabulacao_mapeamentos itm WHERE itm.integracao_id = ci.id AND itm.ativo = 1) AS total_mapeamentos,
                (SELECT COUNT(*) FROM integracao_eventos_saida ies WHERE ies.integracao_id = ci.id AND ies.status IN (\'pendente\',\'processando\',\'erro_retry\')) AS total_pendentes
            FROM credor_integracoes ci
            INNER JOIN credores c ON c.id = ci.credor_id
            WHERE ci.credor_id = :credor_id';
        if ($somenteAtivas) {
            $sql .= ' AND ci.ativo = 1';
        }
        $sql .= ' ORDER BY ci.ativo DESC, ci.evento_tipo ASC, ci.nome ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listarTodas(bool $somenteAtivas = false): array
    {
        if (!$this->moduloDisponivel()) {
            return [];
        }

        $sql = 'SELECT ci.*, c.codigo AS credor_codigo, c.nome AS credor_nome
            FROM credor_integracoes ci
            INNER JOIN credores c ON c.id = ci.credor_id';
        if ($somenteAtivas) {
            $sql .= ' WHERE ci.ativo = 1 AND c.ativo = 1';
        }
        $sql .= ' ORDER BY c.nome ASC, ci.evento_tipo ASC, ci.nome ASC';

        $stmt = $this->pdo->query($sql);
        return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        if (!$this->moduloDisponivel() || $id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT ci.*, c.codigo AS credor_codigo, c.nome AS credor_nome
            FROM credor_integracoes ci
            INNER JOIN credores c ON c.id = ci.credor_id
            WHERE ci.id = :id
            LIMIT 1');
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function salvarIntegracao(int $credorId, array $dados, ?int $usuarioId): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Integrações por credor indisponíveis. Execute a migration 085.');
        }
        if ($credorId <= 0 || !$this->credorExiste($credorId)) {
            throw new InvalidArgumentException('Credor inválido para integração.');
        }

        $id = $this->normalizarId($dados['integracao_id'] ?? $dados['id'] ?? null);
        if ($id !== null) {
            $atual = $this->buscar($id);
            if ($atual === null || (int) $atual['credor_id'] !== $credorId) {
                throw new InvalidArgumentException('Integração não encontrada para este credor.');
            }
        }

        $codigo = $this->normalizarCodigo($dados['codigo'] ?? null, 80, 'código da integração');
        $nome = $this->normalizarTextoObrigatorio($dados['nome'] ?? null, 160, 'nome da integração');
        $eventoTipo = $this->normalizarEvento((string) ($dados['evento_tipo'] ?? 'acionamento.registrado'));
        $tipoTransporte = strtolower(trim((string) ($dados['tipo_transporte'] ?? 'api')));
        $tiposPermitidos = ['api', 'arquivo', 'sftp', 'pasta_compartilhada', 'webhook'];
        if (!in_array($tipoTransporte, $tiposPermitidos, true)) {
            throw new InvalidArgumentException('Tipo de transporte inválido.');
        }
        $adapter = $this->normalizarCodigo($dados['adapter'] ?? null, 100, 'adapter');
        $segredoEnvPrefixo = $this->normalizarTextoOpcional($dados['segredo_env_prefixo'] ?? null, 80);
        if ($segredoEnvPrefixo !== null && !preg_match('/^[A-Z][A-Z0-9_]{1,79}$/', $segredoEnvPrefixo)) {
            throw new InvalidArgumentException('O prefixo de segredos deve usar somente letras maiúsculas, números e sublinhado.');
        }
        $configuracaoJson = $this->normalizarConfiguracaoIntegracao($adapter, $dados['configuracao_json'] ?? null);
        $timeoutSegundos = $this->normalizarInteiro($dados['timeout_segundos'] ?? 45, 3, 120, 'timeout em segundos');
        $maxTentativas = $this->normalizarInteiro($dados['max_tentativas'] ?? 8, 1, 100, 'máximo de tentativas');
        $retryInicial = $this->normalizarInteiro($dados['retry_inicial_segundos'] ?? 60, 5, 86400, 'retry inicial');
        $retryMaximo = $this->normalizarInteiro($dados['retry_max_segundos'] ?? 3600, $retryInicial, 604800, 'retry máximo');
        $ativo = $this->normalizarFlag($dados['ativo'] ?? null);

        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO credor_integracoes (
                        credor_id, codigo, nome, evento_tipo, tipo_transporte, adapter,
                        segredo_env_prefixo, configuracao_json, timeout_segundos, max_tentativas,
                        retry_inicial_segundos, retry_max_segundos, ativo,
                        criado_por_usuario_id, atualizado_por_usuario_id
                    ) VALUES (
                        :credor_id, :codigo, :nome, :evento_tipo, :tipo_transporte, :adapter,
                        :segredo_env_prefixo, :configuracao_json, :timeout_segundos, :max_tentativas,
                        :retry_inicial_segundos, :retry_max_segundos, :ativo,
                        :criado_por_usuario_id, :atualizado_por_usuario_id
                    )');
            } else {
                $stmt = $this->pdo->prepare('UPDATE credor_integracoes
                    SET codigo = :codigo,
                        nome = :nome,
                        evento_tipo = :evento_tipo,
                        tipo_transporte = :tipo_transporte,
                        adapter = :adapter,
                        segredo_env_prefixo = :segredo_env_prefixo,
                        configuracao_json = :configuracao_json,
                        timeout_segundos = :timeout_segundos,
                        max_tentativas = :max_tentativas,
                        retry_inicial_segundos = :retry_inicial_segundos,
                        retry_max_segundos = :retry_max_segundos,
                        ativo = :ativo,
                        atualizado_por_usuario_id = :usuario_id
                    WHERE id = :id AND credor_id = :credor_id');
                $stmt->bindValue('id', $id, PDO::PARAM_INT);
            }

            $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
            $stmt->bindValue('codigo', $codigo);
            $stmt->bindValue('nome', $nome);
            $stmt->bindValue('evento_tipo', $eventoTipo);
            $stmt->bindValue('tipo_transporte', $tipoTransporte);
            $stmt->bindValue('adapter', $adapter);
            $this->bindNullableString($stmt, 'segredo_env_prefixo', $segredoEnvPrefixo);
            $this->bindNullableString($stmt, 'configuracao_json', $configuracaoJson);
            $stmt->bindValue('timeout_segundos', $timeoutSegundos, PDO::PARAM_INT);
            $stmt->bindValue('max_tentativas', $maxTentativas, PDO::PARAM_INT);
            $stmt->bindValue('retry_inicial_segundos', $retryInicial, PDO::PARAM_INT);
            $stmt->bindValue('retry_max_segundos', $retryMaximo, PDO::PARAM_INT);
            $stmt->bindValue('ativo', $ativo, PDO::PARAM_INT);
            if ($id === null) {
                $this->bindNullableInt($stmt, 'criado_por_usuario_id', $usuarioId);
                $this->bindNullableInt($stmt, 'atualizado_por_usuario_id', $usuarioId);
            } else {
                $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException('Já existe uma integração com esse código para o credor.');
            }
            throw $e;
        }

        return $id ?? (int) $this->pdo->lastInsertId();
    }

    public function alternarIntegracao(int $credorId, int $integracaoId, bool $ativo, ?int $usuarioId): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Integrações por credor indisponíveis.');
        }
        $stmt = $this->pdo->prepare('UPDATE credor_integracoes
            SET ativo = :ativo, atualizado_por_usuario_id = :usuario_id
            WHERE id = :id AND credor_id = :credor_id');
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $stmt->bindValue('id', $integracaoId, PDO::PARAM_INT);
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0 && $this->buscar($integracaoId) === null) {
            throw new InvalidArgumentException('Integração não encontrada.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function listarMapeamentosResultado(int $resultadoId): array
    {
        if (!$this->moduloDisponivel() || $resultadoId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT
                itm.*,
                ci.nome AS integracao_nome,
                ci.codigo AS integracao_codigo,
                ci.adapter,
                ci.evento_tipo,
                ci.tipo_transporte,
                ci.ativo AS integracao_ativa,
                c.id AS credor_id,
                c.codigo AS credor_codigo,
                c.nome AS credor_nome
            FROM integracao_tabulacao_mapeamentos itm
            INNER JOIN credor_integracoes ci ON ci.id = itm.integracao_id
            INNER JOIN credores c ON c.id = ci.credor_id
            WHERE itm.resultado_id = :resultado_id
            ORDER BY c.nome ASC, ci.nome ASC, CASE WHEN itm.produto = \'\' THEN 0 ELSE 1 END, itm.produto ASC');
        $stmt->bindValue('resultado_id', $resultadoId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function buscarMapeamento(int $mapeamentoId): ?array
    {
        if (!$this->moduloDisponivel() || $mapeamentoId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT
                itm.*,
                ci.credor_id,
                ci.nome AS integracao_nome,
                ci.adapter,
                c.codigo AS credor_codigo,
                c.nome AS credor_nome
            FROM integracao_tabulacao_mapeamentos itm
            INNER JOIN credor_integracoes ci ON ci.id = itm.integracao_id
            INNER JOIN credores c ON c.id = ci.credor_id
            WHERE itm.id = :id
            LIMIT 1');
        $stmt->bindValue('id', $mapeamentoId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function salvarMapeamento(int $resultadoId, array $dados, ?int $usuarioId): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('De/para de tabulações indisponível. Execute a migration 085.');
        }
        if ($resultadoId <= 0 || !$this->resultadoExiste($resultadoId)) {
            throw new InvalidArgumentException('Tabulação inválida para o de/para.');
        }

        $integracaoId = $this->normalizarId($dados['integracao_id'] ?? null);
        $integracao = $integracaoId === null ? null : $this->buscar($integracaoId);
        if ($integracaoId === null || $integracao === null) {
            throw new InvalidArgumentException('Selecione uma integração válida.');
        }
        $produto = mb_substr(trim((string) ($dados['produto'] ?? '')), 0, 180);
        $enviar = $this->normalizarFlag($dados['enviar'] ?? null);
        $codigoExterno = $this->normalizarTextoOpcional($dados['codigo_externo'] ?? null, 120);
        $descricaoExterna = $this->normalizarTextoOpcional($dados['descricao_externa'] ?? null, 255);
        $payloadExtra = $this->normalizarJson($dados['payload_extra'] ?? null);
        $ativo = $this->normalizarFlag($dados['mapeamento_ativo'] ?? $dados['ativo'] ?? '1');

        if ($enviar === 1 && $codigoExterno === null) {
            throw new InvalidArgumentException('Informe o código externo/ActionID para habilitar o envio.');
        }
        if ($enviar === 1 && strtoupper((string) ($integracao['adapter'] ?? '')) === 'ACME_ACIONAMENTOS') {
            if (!ctype_digit((string) $codigoExterno) || (int) $codigoExterno <= 0) {
                throw new InvalidArgumentException('Na ACME, o código externo deve ser um ActionID numérico maior que zero.');
            }
        }

        if ($ativo === 1 && $enviar === 1 && $codigoExterno !== null) {
            $duplicado = $this->pdo->prepare('SELECT itm.resultado_id, ar.nome AS resultado_nome
                FROM integracao_tabulacao_mapeamentos itm
                INNER JOIN atendimento_resultados ar ON ar.id = itm.resultado_id
                WHERE itm.integracao_id = :integracao_id
                  AND itm.produto = :produto
                  AND itm.codigo_externo = :codigo_externo
                  AND itm.ativo = 1
                  AND itm.enviar = 1
                  AND itm.resultado_id <> :resultado_id
                LIMIT 1');
            $duplicado->bindValue('integracao_id', $integracaoId, PDO::PARAM_INT);
            $duplicado->bindValue('produto', $produto);
            $duplicado->bindValue('codigo_externo', $codigoExterno);
            $duplicado->bindValue('resultado_id', $resultadoId, PDO::PARAM_INT);
            $duplicado->execute();
            $mapeamentoDuplicado = $duplicado->fetch(PDO::FETCH_ASSOC);
            if ($mapeamentoDuplicado !== false) {
                $escopoProduto = $produto === '' ? 'geral' : 'do produto ' . $produto;
                throw new InvalidArgumentException(
                    'O código externo ' . $codigoExterno . ' já está associado à tabulação "'
                    . (string) ($mapeamentoDuplicado['resultado_nome'] ?? ('#' . (int) $mapeamentoDuplicado['resultado_id']))
                    . '" no mapeamento ' . $escopoProduto . '.'
                );
            }
        }

        $stmt = $this->pdo->prepare('INSERT INTO integracao_tabulacao_mapeamentos (
                integracao_id, resultado_id, produto, enviar, codigo_externo,
                descricao_externa, payload_extra, ativo,
                criado_por_usuario_id, atualizado_por_usuario_id
            ) VALUES (
                :integracao_id, :resultado_id, :produto, :enviar, :codigo_externo,
                :descricao_externa, :payload_extra, :ativo,
                :criado_por_usuario_id, :atualizado_por_usuario_id
            ) ON DUPLICATE KEY UPDATE
                enviar = VALUES(enviar),
                codigo_externo = VALUES(codigo_externo),
                descricao_externa = VALUES(descricao_externa),
                payload_extra = VALUES(payload_extra),
                ativo = VALUES(ativo),
                versao = versao + 1,
                atualizado_por_usuario_id = VALUES(atualizado_por_usuario_id),
                atualizado_em = CURRENT_TIMESTAMP,
                id = LAST_INSERT_ID(id)');
        $stmt->bindValue('integracao_id', $integracaoId, PDO::PARAM_INT);
        $stmt->bindValue('resultado_id', $resultadoId, PDO::PARAM_INT);
        $stmt->bindValue('produto', $produto);
        $stmt->bindValue('enviar', $enviar, PDO::PARAM_INT);
        $this->bindNullableString($stmt, 'codigo_externo', $codigoExterno);
        $this->bindNullableString($stmt, 'descricao_externa', $descricaoExterna);
        $this->bindNullableString($stmt, 'payload_extra', $payloadExtra);
        $stmt->bindValue('ativo', $ativo, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, 'criado_por_usuario_id', $usuarioId);
        $this->bindNullableInt($stmt, 'atualizado_por_usuario_id', $usuarioId);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    public function removerMapeamento(int $resultadoId, int $mapeamentoId, ?int $usuarioId): void
    {
        if (!$this->moduloDisponivel() || $resultadoId <= 0 || $mapeamentoId <= 0) {
            throw new InvalidArgumentException('Mapeamento inválido.');
        }

        // Remoção lógica: o histórico conserva a versão usada, mas nenhum novo
        // acionamento será enfileirado por este de/para.
        $stmt = $this->pdo->prepare('UPDATE integracao_tabulacao_mapeamentos
            SET ativo = 0,
                enviar = 0,
                versao = versao + 1,
                atualizado_por_usuario_id = :usuario_id,
                atualizado_em = CURRENT_TIMESTAMP
            WHERE id = :id AND resultado_id = :resultado_id');
        $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
        $stmt->bindValue('id', $mapeamentoId, PDO::PARAM_INT);
        $stmt->bindValue('resultado_id', $resultadoId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0 && $this->buscarMapeamento($mapeamentoId) === null) {
            throw new InvalidArgumentException('Mapeamento não encontrado.');
        }
    }

    /**
     * Retorna todas as integrações/mapeamentos aplicáveis ao atendimento.
     * Produto específico tem precedência sobre o mapeamento geral da mesma integração.
     *
     * @return list<array<string,mixed>>
     */
    public function contextosParaAtendimento(int $atendimentoId): array
    {
        if (!$this->moduloDisponivel() || $atendimentoId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT
                ah.id AS atendimento_id,
                ah.pessoa_id,
                ah.contrato_id,
                ah.resultado_id,
                ah.usuario_id,
                ah.operador_nome,
                ah.canal,
                ah.contato_utilizado,
                ah.observacao,
                ah.proximo_acionamento,
                ah.criado_em AS atendimento_criado_em,
                ar.codigo AS resultado_codigo,
                ar.nome AS resultado_nome,
                c.credor_id,
                c.codigo_contrato,
                COALESCE(c.produto, \'\') AS produto,
                cr.codigo AS credor_codigo,
                cr.nome AS credor_nome,
                p.documento,
                p.tipo_documento,
                p.nome AS pessoa_nome,
                ci.id AS integracao_id,
                ci.codigo AS integracao_codigo,
                ci.nome AS integracao_nome,
                ci.evento_tipo,
                ci.tipo_transporte,
                ci.adapter,
                ci.segredo_env_prefixo,
                ci.configuracao_json,
                ci.timeout_segundos,
                ci.max_tentativas,
                ci.retry_inicial_segundos,
                ci.retry_max_segundos,
                itm.id AS mapeamento_id,
                itm.produto AS mapeamento_produto,
                itm.enviar AS mapeamento_enviar,
                itm.ativo AS mapeamento_ativo,
                itm.codigo_externo,
                itm.descricao_externa,
                itm.payload_extra AS mapeamento_payload_extra,
                itm.versao AS mapeamento_versao
            FROM atendimentos_historico ah
            INNER JOIN atendimento_resultados ar ON ar.id = ah.resultado_id
            INNER JOIN contratos c ON c.id = ah.contrato_id
            INNER JOIN credores cr ON cr.id = c.credor_id
            INNER JOIN pessoas p ON p.id = ah.pessoa_id
            INNER JOIN credor_integracoes ci
                ON ci.credor_id = c.credor_id
               AND ci.evento_tipo = \'acionamento.registrado\'
               AND ci.ativo = 1
            INNER JOIN integracao_tabulacao_mapeamentos itm
                ON itm.integracao_id = ci.id
               AND itm.resultado_id = ah.resultado_id
               AND itm.ativo = 1
               AND itm.produto IN (COALESCE(c.produto, \'\'), \'\')
            WHERE ah.id = :atendimento_id
            ORDER BY ci.id ASC,
                CASE WHEN itm.produto = COALESCE(c.produto, \'\') AND itm.produto <> \'\' THEN 0 ELSE 1 END,
                itm.id DESC');
        $stmt->bindValue('atendimento_id', $atendimentoId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $porIntegracao = [];
        foreach ($rows as $row) {
            $integracaoId = (int) ($row['integracao_id'] ?? 0);
            if ($integracaoId <= 0 || isset($porIntegracao[$integracaoId])) {
                continue;
            }
            $porIntegracao[$integracaoId] = $row;
        }
        return array_values($porIntegracao);
    }

    /** @return array<string,mixed>|null */
    public function integracaoAtivaPorAdapter(int $credorId, string $adapter, string $eventoTipo = 'acionamento.registrado'): ?array
    {
        if (!$this->moduloDisponivel() || $credorId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM credor_integracoes
            WHERE credor_id = :credor_id
              AND UPPER(adapter) = UPPER(:adapter)
              AND evento_tipo = :evento_tipo
              AND ativo = 1
            ORDER BY id ASC
            LIMIT 1');
        $stmt->bindValue('credor_id', $credorId, PDO::PARAM_INT);
        $stmt->bindValue('adapter', trim($adapter));
        $stmt->bindValue('evento_tipo', trim($eventoTipo));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function credorExiste(int $credorId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM credores WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $credorId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    private function resultadoExiste(int $resultadoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM atendimento_resultados WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $resultadoId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    private function normalizarId(mixed $value): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw <= 0) {
            throw new InvalidArgumentException('ID inválido.');
        }
        return (int) $raw;
    }

    private function normalizarCodigo(mixed $value, int $limite, string $campo): string
    {
        $codigo = strtoupper(trim((string) ($value ?? '')));
        $codigo = preg_replace('/[^A-Z0-9_.-]+/', '_', $codigo) ?? '';
        $codigo = trim($codigo, '_.-');
        $codigo = mb_substr($codigo, 0, $limite);
        if ($codigo === '') {
            throw new InvalidArgumentException('Informe um ' . $campo . ' válido.');
        }
        return $codigo;
    }

    private function normalizarEvento(string $value): string
    {
        $evento = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,99}$/', $evento)) {
            throw new InvalidArgumentException('Tipo de evento inválido. Use o formato entidade.acao.');
        }
        return $evento;
    }

    private function normalizarTextoObrigatorio(mixed $value, int $limite, string $campo): string
    {
        $texto = trim((string) ($value ?? ''));
        if ($texto === '') {
            throw new InvalidArgumentException('Informe o campo ' . $campo . '.');
        }
        return mb_substr($texto, 0, $limite);
    }

    private function normalizarTextoOpcional(mixed $value, int $limite): ?string
    {
        $texto = trim((string) ($value ?? ''));
        return $texto === '' ? null : mb_substr($texto, 0, $limite);
    }

    private function normalizarInteiro(mixed $value, int $min, int $max, string $campo): int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            throw new InvalidArgumentException('Informe um valor inteiro válido para ' . $campo . '.');
        }
        $numero = (int) $raw;
        if ($numero < $min || $numero > $max) {
            throw new InvalidArgumentException('O campo ' . $campo . ' deve ficar entre ' . $min . ' e ' . $max . '.');
        }
        return $numero;
    }

    private function normalizarFlag(mixed $value): int
    {
        return in_array(strtolower(trim((string) ($value ?? '0'))), ['1', 'true', 'on', 'yes', 'sim', 'ativo'], true) ? 1 : 0;
    }

    private function normalizarConfiguracaoIntegracao(string $adapter, mixed $value): ?string
    {
        $json = $this->normalizarJson($value);
        if ($json === null || strtoupper(trim($adapter)) !== 'ACME_ACIONAMENTOS') {
            return $json;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }

        // Os endpoints ACME sao parte do adapter versionado e nao configuracao de ambiente/credor.
        unset($decoded['endpoint_envio'], $decoded['endpoint_consulta']);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function normalizarJson(mixed $value): ?string
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('JSON inválido: ' . json_last_error_msg());
            }
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('A configuração JSON precisa ser um objeto ou uma lista.');
        }
        if ($this->jsonContemSegredo($decoded)) {
            throw new InvalidArgumentException(
                'Não grave senha, token, segredo ou chave privada no JSON. Use o prefixo de variáveis do .env.local.'
            );
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /** @param array<mixed> $dados */
    private function jsonContemSegredo(array $dados): bool
    {
        foreach ($dados as $chave => $valor) {
            if (is_string($chave)) {
                // Converte clientSecret, api-password e api.password para uma forma única.
                $normalizada = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($chave)) ?? trim($chave);
                $normalizada = strtolower($normalizada);
                $normalizada = preg_replace('/[^a-z0-9]+/', '_', $normalizada) ?? $normalizada;
                $normalizada = trim($normalizada, '_');

                if (preg_match(
                    '/(?:^|_)(?:password|passwd|pwd|senha|token|secret|segredo|api_?key|private_?key|chave_?privada|authorization|credential|credentials|credencial|credenciais)(?:_|$)/',
                    $normalizada
                ) === 1) {
                    return true;
                }
            }
            if (is_array($valor) && $this->jsonContemSegredo($valor)) {
                return true;
            }
        }
        return false;
    }

    private function bindNullableString(\PDOStatement $stmt, string $key, ?string $value): void
    {
        $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private function bindNullableInt(\PDOStatement $stmt, string $key, ?int $value): void
    {
        $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }
}
