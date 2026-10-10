<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Repositories;

use CRM\Modules\Operacao\Support\EscopoCredorSql;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AgendaOperadorRepository
{
    public const REGRAS_PADRAO = [
        'retorno_vencido_vai_geral' => '1',
        'horas_reserva_padrao' => '24',
        'horas_reserva_manual' => '8',
        'permitir_operador_puxar_geral' => '1',
        'permitir_operador_liberar_reserva' => '1',
        'horas_reserva_admin' => '72',
        'horas_reserva_supervisor' => '48',
        'horas_reserva_lider' => '36',
        'horas_reserva_operador' => '24',
        'supervisao_ver_todos' => '1',
    ];

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
            $regras = $this->pdo->query("SHOW TABLES LIKE 'operacao_fila_regras'");
            $reservas = $this->pdo->query("SHOW TABLES LIKE 'operacao_cliente_reservas'");
            $this->moduloDisponivel = $regras !== false && $regras->fetchColumn() !== false
                && $reservas !== false && $reservas->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }

        return $this->moduloDisponivel;
    }

    /** @return array<string,string> */
    public function regras(): array
    {
        $regras = self::REGRAS_PADRAO;
        if (!$this->moduloDisponivel()) {
            return $regras;
        }

        $stmt = $this->pdo->query('SELECT codigo, valor FROM operacao_fila_regras');
        if ($stmt === false) {
            return $regras;
        }

        foreach ($stmt->fetchAll() as $row) {
            $codigo = (string) ($row['codigo'] ?? '');
            if ($codigo !== '') {
                $regras[$codigo] = (string) ($row['valor'] ?? '');
            }
        }

        return $regras;
    }

    /** @param array<string,mixed> $dados */
    public function salvarRegras(array $dados, ?int $usuarioId): void
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de agenda/fila ainda não está ativo. Execute a migration 031.');
        }

        $regras = [
            'retorno_vencido_vai_geral' => (string) (($dados['retorno_vencido_vai_geral'] ?? '0') === '1' ? '1' : '0'),
            'permitir_operador_puxar_geral' => (string) (($dados['permitir_operador_puxar_geral'] ?? '0') === '1' ? '1' : '0'),
            'horas_reserva_padrao' => (string) max(1, min(720, (int) ($dados['horas_reserva_padrao'] ?? 24))),
            'horas_reserva_manual' => (string) max(1, min(720, (int) ($dados['horas_reserva_manual'] ?? 8))),
            'permitir_operador_liberar_reserva' => (string) (($dados['permitir_operador_liberar_reserva'] ?? '0') === '1' ? '1' : '0'),
            'horas_reserva_admin' => (string) max(1, min(720, (int) ($dados['horas_reserva_admin'] ?? 72))),
            'horas_reserva_supervisor' => (string) max(1, min(720, (int) ($dados['horas_reserva_supervisor'] ?? 48))),
            'horas_reserva_lider' => (string) max(1, min(720, (int) ($dados['horas_reserva_lider'] ?? 36))),
            'horas_reserva_operador' => (string) max(1, min(720, (int) ($dados['horas_reserva_operador'] ?? 24))),
            'supervisao_ver_todos' => (string) (($dados['supervisao_ver_todos'] ?? '0') === '1' ? '1' : '0'),
        ];

        $descricao = [
            'retorno_vencido_vai_geral' => 'Quando um retorno agendado vencer sem tratamento, ele aparece tambem na fila geral.',
            'permitir_operador_puxar_geral' => 'Permite que usuario com fila.trabalhar reserve um cliente disponivel da fila geral para si.',
            'horas_reserva_padrao' => 'Tempo padrao de reserva quando um operador puxa um cliente da fila geral.',
            'horas_reserva_manual' => 'Tempo padrao de reserva manual/transferencia feita pela tela de agenda.',
            'permitir_operador_liberar_reserva' => 'Permite que operadores liberem as proprias reservas antes do vencimento.',
            'horas_reserva_admin' => 'Prazo de reserva para perfil admin.',
            'horas_reserva_supervisor' => 'Prazo de reserva para perfil supervisor.',
            'horas_reserva_lider' => 'Prazo de reserva para perfil lider.',
            'horas_reserva_operador' => 'Prazo de reserva para perfil operador.',
            'supervisao_ver_todos' => 'Permite visao ampla na supervisao quando o perfil possuir permissao.',
        ];

        $stmt = $this->pdo->prepare('INSERT INTO operacao_fila_regras (codigo, valor, descricao, atualizado_por_usuario_id)
            VALUES (:codigo, :valor, :descricao, :usuario_id)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao), atualizado_por_usuario_id = VALUES(atualizado_por_usuario_id)');

        foreach ($regras as $codigo => $valor) {
            $stmt->bindValue('codigo', $codigo);
            $stmt->bindValue('valor', $valor);
            $stmt->bindValue('descricao', $descricao[$codigo] ?? null);
            $this->bindNullableInt($stmt, 'usuario_id', $usuarioId);
            $stmt->execute();
        }
    }

    public function regraBool(string $codigo): bool
    {
        $valor = $this->regras()[$codigo] ?? (self::REGRAS_PADRAO[$codigo] ?? '0');
        return in_array((string) $valor, ['1', 'true', 'sim', 'yes'], true);
    }

    public function regraInt(string $codigo, int $padrao): int
    {
        $valor = $this->regras()[$codigo] ?? (string) $padrao;
        return max(1, min(720, (int) $valor));
    }

    /** @return list<array<string,mixed>> */
    public function listarUsuariosOperacionais(): array
    {
        $stmt = $this->pdo->query("SELECT u.id, u.nome, u.login, p.nome AS perfil_nome
            FROM usuarios u
            LEFT JOIN perfis_acesso p ON p.id = u.perfil_id
            WHERE u.status = 'ativo'
            ORDER BY p.nivel DESC, u.nome ASC");

        return $stmt !== false ? $stmt->fetchAll() : [];
    }




    public function horasReservaParaUsuario(int $usuarioId, string $origem = 'manual'): int
    {
        if ($origem === 'fila_geral' || $origem === 'campanha' || $origem === 'retorno') {
            $padrao = $this->regraInt('horas_reserva_padrao', 24);
        } else {
            $padrao = $this->regraInt('horas_reserva_manual', 8);
        }

        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(p.codigo, \'operador\') AS perfil_codigo
                FROM usuarios u
                LEFT JOIN perfis_acesso p ON p.id = u.perfil_id
                WHERE u.id = :id
                LIMIT 1');
            $stmt->bindValue('id', $usuarioId, PDO::PARAM_INT);
            $stmt->execute();
            $perfil = strtolower((string) ($stmt->fetchColumn() ?: 'operador'));
        } catch (Throwable) {
            return $padrao;
        }

        return match ($perfil) {
            'admin' => $this->regraInt('horas_reserva_admin', 72),
            'supervisor' => $this->regraInt('horas_reserva_supervisor', 48),
            'lider', 'líder' => $this->regraInt('horas_reserva_lider', 36),
            'operador' => $this->regraInt('horas_reserva_operador', 24),
            default => $padrao,
        };
    }

    /** @param array<string,mixed> $dados */
    public function reservarCliente(array $dados, int $atorUsuarioId): int
    {
        if (!$this->moduloDisponivel()) {
            throw new InvalidArgumentException('Módulo de agenda/fila ainda não está ativo. Execute a migration 031.');
        }

        $pessoaId = $this->normalizarId($dados['pessoa_id'] ?? null, 'cliente');
        $contratoId = $this->normalizarIdOpcional($dados['contrato_id'] ?? null);
        $usuarioId = $this->normalizarId($dados['usuario_id'] ?? null, 'operador destino');
        $origem = $this->normalizarOrigem((string) ($dados['origem'] ?? 'manual'));
        $observacao = trim((string) ($dados['observacao'] ?? ''));
        $campanhaItemId = $this->normalizarIdOpcional($dados['campanha_item_id'] ?? null);
        $retornoOrigemId = $this->normalizarIdOpcional($dados['retorno_origem_id'] ?? null);
        $horas = max(1, min(720, (int) ($dados['horas'] ?? $this->horasReservaParaUsuario($usuarioId, $origem))));
        $expiraEm = (new \DateTimeImmutable('now'))->modify('+' . $horas . ' hours')->format('Y-m-d H:i:s');

        $this->validarPessoaContrato($pessoaId, $contratoId);
        $this->validarUsuarioAtivo($usuarioId);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE operacao_cliente_reservas
                SET status = 'transferida', liberado_em = NOW()
                WHERE pessoa_id = :pessoa_id AND status = 'ativa'")
                ->execute(['pessoa_id' => $pessoaId]);

            $stmt = $this->pdo->prepare("INSERT INTO operacao_cliente_reservas (
                    pessoa_id, contrato_id, usuario_id, status, origem, campanha_item_id, retorno_origem_id,
                    observacao, reservado_por_usuario_id, reservado_em, expira_em, payload_extra
                ) VALUES (
                    :pessoa_id, :contrato_id, :usuario_id, 'ativa', :origem, :campanha_item_id, :retorno_origem_id,
                    :observacao, :ator_usuario_id, NOW(), :expira_em, :payload_extra
                )");
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
            $this->bindNullableInt($stmt, 'contrato_id', $contratoId);
            $stmt->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
            $stmt->bindValue('origem', $origem);
            $this->bindNullableInt($stmt, 'campanha_item_id', $campanhaItemId);
            $this->bindNullableInt($stmt, 'retorno_origem_id', $retornoOrigemId);
            $this->bindNullableString($stmt, 'observacao', $observacao !== '' ? $observacao : null);
            $stmt->bindValue('ator_usuario_id', $atorUsuarioId, PDO::PARAM_INT);
            $stmt->bindValue('expira_em', $expiraEm);
            $stmt->bindValue('payload_extra', json_encode(['horas' => $horas], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
            $id = (int) $this->pdo->lastInsertId();
            $this->registrarMovimentoFila($pessoaId, $contratoId, $id, $atorUsuarioId, $usuarioId, $origem === 'transferencia' ? 'transferencia' : 'reserva', $observacao !== '' ? $observacao : 'Reserva criada.');
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function liberarReserva(int $reservaId, int $atorUsuarioId, ?string $observacao = null): void
    {
        if ($reservaId <= 0) {
            throw new InvalidArgumentException('Reserva inválida.');
        }

        $consulta = $this->pdo->prepare("SELECT pessoa_id, contrato_id, usuario_id FROM operacao_cliente_reservas WHERE id = :id LIMIT 1");
        $consulta->bindValue('id', $reservaId, PDO::PARAM_INT);
        $consulta->execute();
        $reserva = $consulta->fetch() ?: [];

        $stmt = $this->pdo->prepare("UPDATE operacao_cliente_reservas
            SET status = 'liberada', liberado_em = NOW(),
                observacao = TRIM(CONCAT(COALESCE(observacao, ''), CASE WHEN COALESCE(observacao, '') = '' THEN '' ELSE '\n' END, :obs))
            WHERE id = :id AND status = 'ativa'");
        $stmt->bindValue('id', $reservaId, PDO::PARAM_INT);
        $stmt->bindValue('obs', trim((string) $observacao) !== '' ? 'Liberada por usuario #' . $atorUsuarioId . ': ' . trim((string) $observacao) : 'Liberada por usuario #' . $atorUsuarioId);
        $stmt->execute();

        if ($stmt->rowCount() > 0 && $reserva !== []) {
            $this->registrarMovimentoFila((int) $reserva['pessoa_id'], !empty($reserva['contrato_id']) ? (int) $reserva['contrato_id'] : null, $reservaId, (int) $reserva['usuario_id'], null, 'liberacao', trim((string) $observacao) !== '' ? trim((string) $observacao) : 'Reserva liberada.');
        }
    }

    /** @return array<string,mixed>|null */
    public function reservaAtivaPessoa(int $pessoaId): ?array
    {
        if (!$this->moduloDisponivel() || $pessoaId <= 0) {
            return null;
        }

        $this->expirarReservasVencidas();
        $stmt = $this->pdo->prepare("SELECT r.*, u.nome AS usuario_nome, autor.nome AS reservado_por_nome
            FROM operacao_cliente_reservas r
            INNER JOIN usuarios u ON u.id = r.usuario_id
            LEFT JOIN usuarios autor ON autor.id = r.reservado_por_usuario_id
            WHERE r.pessoa_id = :pessoa_id AND r.status = 'ativa'
            ORDER BY r.id DESC
            LIMIT 1");
        $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function expirarReservasVencidas(): void
    {
        if (!$this->moduloDisponivel()) {
            return;
        }

        $this->pdo->exec("UPDATE operacao_cliente_reservas
            SET status = 'expirada', liberado_em = NOW()
            WHERE status = 'ativa' AND expira_em IS NOT NULL AND expira_em < NOW()");
    }

    private function validarPessoaContrato(int $pessoaId, ?int $contratoId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM pessoas WHERE id = :id LIMIT 1');
        $stmt->bindValue('id', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Cliente não encontrado.');
        }

        if ($contratoId !== null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE id = :id AND pessoa_id = :pessoa_id LIMIT 1');
            $stmt->bindValue('id', $contratoId, PDO::PARAM_INT);
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('Contrato não pertence ao cliente informado.');
            }
        }
    }

    private function validarUsuarioAtivo(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM usuarios WHERE id = :id AND status = 'ativo' LIMIT 1");
        $stmt->bindValue('id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Operador destino inválido ou inativo.');
        }
    }

    private function normalizarId(mixed $valor, string $campo): int
    {
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Informe um ' . $campo . ' válido.');
        }
        return (int) $id;
    }

    private function normalizarIdOpcional(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int) $id;
    }

    private function normalizarOrigem(string $origem): string
    {
        $permitidas = ['manual', 'transferencia', 'fila_geral', 'campanha', 'retorno', 'atendimento'];
        return in_array($origem, $permitidas, true) ? $origem : 'manual';
    }


    private function registrarMovimentoFila(int $pessoaId, ?int $contratoId, ?int $reservaId, ?int $usuarioOrigemId, ?int $usuarioDestinoId, string $tipo, ?string $descricao = null): void
    {
        if (!$this->tabelaExiste('operacao_fila_movimentos')) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO operacao_fila_movimentos (
                    pessoa_id, contrato_id, reserva_id, usuario_origem_id, usuario_destino_id, tipo, descricao, payload_extra
                ) VALUES (
                    :pessoa_id, :contrato_id, :reserva_id, :usuario_origem_id, :usuario_destino_id, :tipo, :descricao, :payload_extra
                )');
            $stmt->bindValue('pessoa_id', $pessoaId, PDO::PARAM_INT);
            $this->bindNullableInt($stmt, 'contrato_id', $contratoId);
            $this->bindNullableInt($stmt, 'reserva_id', $reservaId);
            $this->bindNullableInt($stmt, 'usuario_origem_id', $usuarioOrigemId);
            $this->bindNullableInt($stmt, 'usuario_destino_id', $usuarioDestinoId);
            $stmt->bindValue('tipo', mb_substr($tipo, 0, 60));
            $this->bindNullableString($stmt, 'descricao', $descricao !== null ? mb_substr($descricao, 0, 500) : null);
            $stmt->bindValue('payload_extra', json_encode(['origem' => 'agenda_operador_repository'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
        } catch (Throwable) {
            return;
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
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
}
