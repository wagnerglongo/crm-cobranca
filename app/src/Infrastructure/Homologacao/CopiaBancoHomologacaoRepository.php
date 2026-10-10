<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Homologacao;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Cópia do banco de produção (crm_dev) para a homologação (crm_homolog).
 * Requisito (2026-10-06): toda semana (padrão domingo 05:00) e sob
 * demanda pela tela Administração > Homologação.
 *
 * O PHP não copia nada (precisa do root do MySQL e do docker): só guarda a
 * agenda e os pedidos. O scripts/homologacao-agendador.sh, no host, chama
 * reservarProxima() a cada minuto, roda "homologacao.sh banco --confirmar"
 * e devolve o resultado em finalizar().
 */
final class CopiaBancoHomologacaoRepository
{
    public const DIAS_SEMANA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    /** A cópia leva ~2 min; executando há mais que isso = o agendador morreu no meio. */
    private const LIMITE_EXECUCAO_MINUTOS = 180;

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
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'homologacao_copias'");
            $this->moduloDisponivel = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $this->moduloDisponivel = false;
        }
        return $this->moduloDisponivel;
    }

    /** @return array{ativo:bool,dia_semana:int,horario:string,atualizado_em:?string,atualizado_por:?string} */
    public function agenda(): array
    {
        $row = $this->pdo->query('SELECT a.*, u.nome AS atualizado_por
            FROM homologacao_agenda a
            LEFT JOIN usuarios u ON u.id = a.atualizado_por_usuario_id
            WHERE a.id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['ativo' => false, 'dia_semana' => 7, 'horario' => '05:00', 'atualizado_em' => null, 'atualizado_por' => null];
        }
        return [
            'ativo' => (int) $row['ativo'] === 1,
            'dia_semana' => (int) $row['dia_semana'],
            'horario' => substr((string) $row['horario'], 0, 5),
            'atualizado_em' => $row['atualizado_em'] !== null ? (string) $row['atualizado_em'] : null,
            'atualizado_por' => $row['atualizado_por'] !== null ? (string) $row['atualizado_por'] : null,
        ];
    }

    public function salvarAgenda(bool $ativo, int $diaSemana, string $horario, ?int $usuarioId): void
    {
        if (!isset(self::DIAS_SEMANA[$diaSemana])) {
            throw new InvalidArgumentException('Dia da semana inválido.');
        }
        $horario = trim($horario);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $horario) !== 1) {
            throw new InvalidArgumentException('Horário inválido (use HH:MM).');
        }

        $stmt = $this->pdo->prepare('INSERT INTO homologacao_agenda (id, ativo, dia_semana, horario, atualizado_por_usuario_id)
            VALUES (1, :ativo, :dia, :horario, :usuario)
            ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), dia_semana = VALUES(dia_semana),
                horario = VALUES(horario), atualizado_por_usuario_id = VALUES(atualizado_por_usuario_id)');
        $stmt->bindValue('ativo', $ativo ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('dia', $diaSemana, PDO::PARAM_INT);
        $stmt->bindValue('horario', substr($horario, 0, 5) . ':00');
        $stmt->bindValue('usuario', $usuarioId, $usuarioId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Pedido de "copiar agora": o agendador do host pega em até 1 minuto. */
    public function solicitarAgora(?int $usuarioId): int
    {
        $andamento = $this->emAndamento();
        if ($andamento !== null) {
            throw new RuntimeException('Já existe uma cópia ' . ($andamento['status'] === 'pendente' ? 'aguardando' : 'em andamento') . ' (#' . $andamento['id'] . ').');
        }
        $stmt = $this->pdo->prepare("INSERT INTO homologacao_copias (origem, status, solicitado_por_usuario_id) VALUES ('manual', 'pendente', :usuario)");
        $stmt->bindValue('usuario', $usuarioId, $usuarioId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    public function cancelar(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE homologacao_copias
            SET status = 'cancelada', finalizado_em = NOW(), mensagem = 'Cancelada antes de começar.'
            WHERE id = :id AND status = 'pendente'");
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Só dá para cancelar uma cópia que ainda não começou.');
        }
    }

    /** @return array<string,mixed>|null */
    public function emAndamento(): ?array
    {
        $row = $this->pdo->query("SELECT * FROM homologacao_copias WHERE status IN ('pendente', 'executando') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listar(int $limite = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, u.nome AS solicitado_por
            FROM homologacao_copias c
            LEFT JOIN usuarios u ON u.id = c.solicitado_por_usuario_id
            ORDER BY c.id DESC LIMIT :limite');
        $stmt->bindValue('limite', max(1, min(100, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function ultimaConcluida(): ?array
    {
        $row = $this->pdo->query("SELECT * FROM homologacao_copias WHERE status = 'concluida' ORDER BY finalizado_em DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Próximo horário agendado. Se for hoje e já passou sem ter rodado,
     * devolve o de hoje (o agendador pega no próximo minuto).
     */
    public function proximaAgendada(DateTimeImmutable $agora): ?DateTimeImmutable
    {
        $agenda = $this->agenda();
        if (!$agenda['ativo']) {
            return null;
        }
        $dias = ($agenda['dia_semana'] - (int) $agora->format('N') + 7) % 7;
        $quando = $this->horarioDoDia($agora->modify('+' . $dias . ' days'), $agenda['horario']);
        if ($dias === 0 && $quando <= $agora && $this->agendaJaAtendida($agora, $quando)) {
            $quando = $quando->modify('+7 days');
        }
        return $quando;
    }

    /**
     * Chamado pelo agendador do host a cada minuto. Devolve o id da cópia
     * a executar agora (já marcada como executando) ou null.
     * Ordem: pedido manual pendente; senão o horário semanal, se chegou e
     * ainda não foi atendido hoje. Uma cópia por vez.
     */
    public function reservarProxima(DateTimeImmutable $agora): ?int
    {
        $this->pdo->beginTransaction();
        try {
            // Trava a agenda: dois agendadores ao mesmo tempo não reservam 2x.
            $this->pdo->query('SELECT id FROM homologacao_agenda WHERE id = 1 FOR UPDATE')->fetchAll();

            $presas = $this->pdo->prepare("UPDATE homologacao_copias
                SET status = 'erro', finalizado_em = :agora,
                    mensagem = 'Interrompida: o agendador não deu retorno em " . self::LIMITE_EXECUCAO_MINUTOS . " minutos. Ver storage/logs/homologacao-copia.log.'
                WHERE status = 'executando' AND iniciado_em < :limite");
            $presas->execute([
                'agora' => $agora->format('Y-m-d H:i:s'),
                'limite' => $agora->modify('-' . self::LIMITE_EXECUCAO_MINUTOS . ' minutes')->format('Y-m-d H:i:s'),
            ]);

            if ($this->pdo->query("SELECT 1 FROM homologacao_copias WHERE status = 'executando' LIMIT 1")->fetchColumn() !== false) {
                $this->pdo->commit();
                return null;
            }

            $id = null;
            $pendente = $this->pdo->query("SELECT id FROM homologacao_copias WHERE status = 'pendente' ORDER BY id ASC LIMIT 1 FOR UPDATE")->fetchColumn();
            if ($pendente !== false) {
                $id = (int) $pendente;
                $stmt = $this->pdo->prepare("UPDATE homologacao_copias SET status = 'executando', iniciado_em = :agora WHERE id = :id");
                $stmt->execute(['agora' => $agora->format('Y-m-d H:i:s'), 'id' => $id]);
            } elseif ($this->agendaDevida($agora)) {
                $stmt = $this->pdo->prepare("INSERT INTO homologacao_copias (origem, status, solicitado_em, iniciado_em)
                    VALUES ('agendada', 'executando', :agora, :agora2)");
                $stmt->execute(['agora' => $agora->format('Y-m-d H:i:s'), 'agora2' => $agora->format('Y-m-d H:i:s')]);
                $id = (int) $this->pdo->lastInsertId();
            }

            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function finalizar(int $id, bool $sucesso, string $mensagem, DateTimeImmutable $agora): void
    {
        $mensagem = trim($mensagem);
        $stmt = $this->pdo->prepare("UPDATE homologacao_copias
            SET status = :status, finalizado_em = :agora, mensagem = :mensagem
            WHERE id = :id AND status = 'executando'");
        $stmt->bindValue('status', $sucesso ? 'concluida' : 'erro');
        $stmt->bindValue('agora', $agora->format('Y-m-d H:i:s'));
        $stmt->bindValue('mensagem', $mensagem === '' ? null : mb_substr($mensagem, 0, 1000), $mensagem === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Cópia #' . $id . ' não está em execução.');
        }
    }

    private function agendaDevida(DateTimeImmutable $agora): bool
    {
        $agenda = $this->agenda();
        if (!$agenda['ativo'] || (int) $agora->format('N') !== $agenda['dia_semana']) {
            return false;
        }
        $horario = $this->horarioDoDia($agora, $agenda['horario']);
        return $horario <= $agora && !$this->agendaJaAtendida($agora, $horario);
    }

    /**
     * O horário de hoje conta como atendido se a agendada de hoje já foi
     * criada, ou se uma cópia manual começou depois desse horário (não
     * copia de novo logo em seguida).
     */
    private function agendaJaAtendida(DateTimeImmutable $agora, DateTimeImmutable $horario): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM homologacao_copias
            WHERE (origem = 'agendada' AND solicitado_em >= :inicio_dia AND solicitado_em < :fim_dia)
               OR (status IN ('executando', 'concluida') AND iniciado_em >= :horario AND iniciado_em < :fim_dia2)
            LIMIT 1");
        $stmt->execute([
            'inicio_dia' => $agora->format('Y-m-d 00:00:00'),
            'fim_dia' => $agora->modify('+1 day')->format('Y-m-d 00:00:00'),
            'horario' => $horario->format('Y-m-d H:i:s'),
            'fim_dia2' => $agora->modify('+1 day')->format('Y-m-d 00:00:00'),
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function horarioDoDia(DateTimeImmutable $dia, string $horario): DateTimeImmutable
    {
        [$hora, $minuto] = array_map('intval', explode(':', $horario));
        return $dia->setTime($hora, $minuto);
    }
}
