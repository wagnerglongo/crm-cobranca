<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Homologacao\CopiaBancoHomologacaoRepository;
use PHPUnit\Framework\TestCase;

/**
 * Cópia semanal/sob demanda do banco de produção para a homologação
 * (requisito de 2026-10-06). 11/10/2026 é domingo.
 */
final class CopiaBancoHomologacaoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CopiaBancoHomologacaoRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repo = new CopiaBancoHomologacaoRepository($this->pdo);
        $this->pdo->exec('DELETE FROM homologacao_copias');
        $this->repo->salvarAgenda(true, 7, '05:00', null);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM homologacao_copias');
        $this->repo->salvarAgenda(true, 7, '05:00', null);
    }

    private static function em(string $quando): DateTimeImmutable
    {
        return new DateTimeImmutable($quando);
    }

    /** @return array<string,mixed> */
    private function copia(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM homologacao_copias WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testDomingoSoRodaDepoisDoHorarioEUmaVezSo(): void
    {
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-11 04:59:00')));

        $id = $this->repo->reservarProxima(self::em('2026-10-11 05:00:30'));
        self::assertNotNull($id);
        self::assertSame('agendada', $this->copia($id)['origem']);
        self::assertSame('executando', $this->copia($id)['status']);

        // Enquanto copia, não reserva outra.
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-11 05:01:00')));

        $this->repo->finalizar($id, true, "Duração: 2 min 1 s.\ncontratos: 10", self::em('2026-10-11 05:02:31'));
        self::assertSame('concluida', $this->copia($id)['status']);

        // Depois de feita, não repete no mesmo domingo.
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-11 05:03:00')));
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-11 23:59:00')));
    }

    public function testOutroDiaDaSemanaNaoDisparaEDesligadaNunca(): void
    {
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-12 05:30:00')));
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-10 05:30:00')));

        $this->repo->salvarAgenda(false, 7, '05:00', null);
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-11 05:30:00')));
        self::assertNull($this->repo->proximaAgendada(self::em('2026-10-06 10:00:00')));
    }

    public function testVmDesligadaNoHorarioCopiaQuandoVoltarNoMesmoDia(): void
    {
        $id = $this->repo->reservarProxima(self::em('2026-10-11 09:40:00'));
        self::assertNotNull($id);
        self::assertSame('agendada', $this->copia($id)['origem']);
    }

    public function testPedidoManualTemPrioridadeEContaComoACopiaDoDia(): void
    {
        $manual = $this->repo->solicitarAgora(null);
        $id = $this->repo->reservarProxima(self::em('2026-10-11 05:10:00'));
        self::assertSame($manual, $id);
        $this->repo->finalizar($manual, true, 'ok', self::em('2026-10-11 05:12:00'));

        // A manual depois do horário já atendeu o domingo.
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-11 05:13:00')));
        self::assertEquals(self::em('2026-10-18 05:00:00'), $this->repo->proximaAgendada(self::em('2026-10-11 05:13:00')));
    }

    public function testPedidoManualEmDiaDeSemanaRodaNaHora(): void
    {
        $manual = $this->repo->solicitarAgora(null);
        self::assertSame($manual, $this->repo->reservarProxima(self::em('2026-10-07 14:00:00')));
    }

    public function testNaoAceitaDoisPedidosESoCancelaOQueNaoComecou(): void
    {
        $manual = $this->repo->solicitarAgora(null);
        try {
            $this->repo->solicitarAgora(null);
            self::fail('Aceitou um segundo pedido com outro aguardando.');
        } catch (RuntimeException) {
        }

        $this->repo->cancelar($manual);
        self::assertSame('cancelada', $this->copia($manual)['status']);
        self::assertNull($this->repo->emAndamento());

        $outro = $this->repo->solicitarAgora(null);
        $this->repo->reservarProxima(self::em('2026-10-07 14:00:00'));
        $this->expectException(RuntimeException::class);
        $this->repo->cancelar($outro);
    }

    public function testCopiaPresaViraErroEDeixaASeguinteRodar(): void
    {
        $presa = $this->repo->solicitarAgora(null);
        $this->repo->reservarProxima(self::em('2026-10-11 01:00:00'));

        // 4h depois sem retorno do agendador: marca erro e reserva a agendada.
        $id = $this->repo->reservarProxima(self::em('2026-10-11 05:01:00'));
        self::assertSame('erro', $this->copia($presa)['status']);
        self::assertStringContainsString('Interrompida', (string) $this->copia($presa)['mensagem']);
        self::assertNotNull($id);
        self::assertNotSame($presa, $id);
    }

    public function testFalhaFicaRegistradaENaoRepeteNoMesmoDia(): void
    {
        $id = $this->repo->reservarProxima(self::em('2026-10-11 05:00:00'));
        $this->repo->finalizar($id, false, 'PAROU: algo', self::em('2026-10-11 05:01:00'));
        self::assertSame('erro', $this->copia($id)['status']);
        self::assertSame('PAROU: algo', $this->copia($id)['mensagem']);
        self::assertNull($this->repo->reservarProxima(self::em('2026-10-11 05:02:00')));
    }

    public function testProximaAgendada(): void
    {
        // Terça -> domingo seguinte.
        self::assertEquals(self::em('2026-10-11 05:00:00'), $this->repo->proximaAgendada(self::em('2026-10-06 10:00:00')));
        // Domingo antes do horário -> hoje.
        self::assertEquals(self::em('2026-10-11 05:00:00'), $this->repo->proximaAgendada(self::em('2026-10-11 03:00:00')));

        $this->repo->salvarAgenda(true, 3, '22:30', null);
        self::assertEquals(self::em('2026-10-07 22:30:00'), $this->repo->proximaAgendada(self::em('2026-10-06 10:00:00')));
        $agenda = $this->repo->agenda();
        self::assertSame(3, $agenda['dia_semana']);
        self::assertSame('22:30', $agenda['horario']);
    }

    public function testAgendaInvalidaERecusada(): void
    {
        foreach ([[0, '05:00'], [8, '05:00'], [7, '24:00'], [7, '5h'], [7, '']] as [$dia, $horario]) {
            try {
                $this->repo->salvarAgenda(true, $dia, $horario, null);
                self::fail("Aceitou dia {$dia} horário '{$horario}'.");
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(7, $this->repo->agenda()['dia_semana']);
    }

    public function testFinalizarSoValeParaCopiaEmExecucao(): void
    {
        $manual = $this->repo->solicitarAgora(null);
        $this->expectException(RuntimeException::class);
        $this->repo->finalizar($manual, true, 'ok', self::em('2026-10-07 14:00:00'));
    }
}
