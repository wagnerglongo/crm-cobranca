<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AgendaOperadorRepository;
use PHPUnit\Framework\TestCase;

/**
 * AgendaOperadorRepository.php (403 linhas, sobrou depois da limpeza de
 * código morto de agenda.php em 2026-09-16) - continuação da auditoria
 * de cobertura de 2026-09-17. reservarCliente() é o mecanismo de
 * reserva de cliente pra um operador. Zero teste cobria isso.
 *
 * Nota (achado já documentado, NÃO corrigido aqui de propósito):
 * reservarCliente() sempre transfere qualquer reserva ativa existente e
 * cria uma nova, sem lock (SELECT FOR UPDATE) contra chamadas
 * concorrentes - "cliente_detalhe.php ainda chama reservarCliente sem
 * trava" já estava anotado como ressalva conhecida na auditoria de
 * 2026-09-16 (ver docs/RUNBOOK_SUPORTE.md). Este teste documenta o
 * comportamento ATUAL (transferência sequencial), não testa concorrência
 * real - não é o objetivo desta rodada mudar esse comportamento.
 */
final class AgendaOperadorRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AgendaOperadorRepository $repository;
    private int $pessoaId;
    private int $contratoId;
    private int $usuario1;
    private int $usuario2;
    /** @var list<int> */
    private array $reservasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AgendaOperadorRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de agenda/fila indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = (int) $stmt->fetchColumn();

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Agenda', 'documento' => '888' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-AGENDA-' . $sufixo]);
        $this->contratoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.agenda1.' . $sufixo, 'nome' => 'Operador Agenda 1', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->usuario1 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.agenda2.' . $sufixo, 'nome' => 'Operador Agenda 2', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->usuario2 = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->reservasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM operacao_cliente_reservas WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id IN (:u1, :u2)')->execute(['u1' => $this->usuario1, 'u2' => $this->usuario2]);
    }

    public function testReservarClienteComDadosValidosFunciona(): void
    {
        $id = $this->repository->reservarCliente([
            'pessoa_id' => $this->pessoaId,
            'contrato_id' => $this->contratoId,
            'usuario_id' => $this->usuario1,
        ], $this->usuario1);
        $this->reservasCriadas[] = $id;

        $ativa = $this->repository->reservaAtivaPessoa($this->pessoaId);
        self::assertSame($this->usuario1, (int) $ativa['usuario_id']);
    }

    public function testReservarClienteComContratoDeOutraPessoaLancaExcecao(): void
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Outra Pessoa Agenda', 'documento' => '777' . random_int(10000000, 99999999)]);
        $outraPessoaId = (int) $this->pdo->lastInsertId();

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Contrato não pertence ao cliente informado.');
            $id = $this->repository->reservarCliente([
                'pessoa_id' => $outraPessoaId,
                'contrato_id' => $this->contratoId,
                'usuario_id' => $this->usuario1,
            ], $this->usuario1);
            $this->reservasCriadas[] = $id;
        } finally {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $outraPessoaId]);
        }
    }

    public function testReservarClienteComOperadorInativoLancaExcecao(): void
    {
        $this->pdo->prepare("UPDATE usuarios SET status = 'inativo' WHERE id = :id")->execute(['id' => $this->usuario1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operador destino inválido ou inativo.');
        $id = $this->repository->reservarCliente([
            'pessoa_id' => $this->pessoaId,
            'contrato_id' => $this->contratoId,
            'usuario_id' => $this->usuario1,
        ], $this->usuario1);
        $this->reservasCriadas[] = $id;
    }

    public function testNovaReservaTransfereAAnteriorAtiva(): void
    {
        $id1 = $this->repository->reservarCliente([
            'pessoa_id' => $this->pessoaId,
            'usuario_id' => $this->usuario1,
        ], $this->usuario1);
        $this->reservasCriadas[] = $id1;

        $id2 = $this->repository->reservarCliente([
            'pessoa_id' => $this->pessoaId,
            'usuario_id' => $this->usuario2,
        ], $this->usuario2);
        $this->reservasCriadas[] = $id2;

        $status1 = $this->pdo->query("SELECT status FROM operacao_cliente_reservas WHERE id = {$id1}")->fetchColumn();
        self::assertSame('transferida', $status1);

        $ativa = $this->repository->reservaAtivaPessoa($this->pessoaId);
        self::assertSame($id2, (int) $ativa['id']);
    }

    public function testLiberarReservaMarcaLiberadaENaoAfetaSeJaLiberada(): void
    {
        $id = $this->repository->reservarCliente([
            'pessoa_id' => $this->pessoaId,
            'usuario_id' => $this->usuario1,
        ], $this->usuario1);
        $this->reservasCriadas[] = $id;

        $this->repository->liberarReserva($id, $this->usuario1, 'teste de liberação');
        $status = $this->pdo->query("SELECT status FROM operacao_cliente_reservas WHERE id = {$id}")->fetchColumn();
        self::assertSame('liberada', $status);

        self::assertNull($this->repository->reservaAtivaPessoa($this->pessoaId));
    }
}
