<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\EstrategiaRepository;
use PHPUnit\Framework\TestCase;

/**
 * EstrategiaRepository.php (2.229 linhas) e' o maior arquivo do
 * repositorio sem NENHUM teste (achado 2026-09-17, auditoria de
 * cobertura). reservarEAbrir() e' o mecanismo de lock (SELECT ... FOR
 * UPDATE dentro de transacao) que impede dois operadores de trabalhar o
 * mesmo contrato ao mesmo tempo - o mesmo tipo de bug de "reserva dupla"
 * que ja foi achado e corrigido em campanhas na auditoria de 2026-09-15
 * (ver docs/RUNBOOK_SUPORTE.md). Sem teste, uma regressao aqui (ex.: comparacao
 * trocada, expiracao calculada errado) causaria dois operadores
 * prometendo coisas diferentes pro mesmo cliente, sem ningum notar até
 * reclamação do cliente.
 */
final class EstrategiaRepositoryReservaTest extends TestCase
{
    private PDO $pdo;
    private EstrategiaRepository $repository;
    private int $credorId;
    private int $pessoaId;
    private int $contratoId;
    private int $estrategiaId;
    private int $itemId;
    private int $operador1;
    private int $operador2;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new EstrategiaRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de estratégias indisponível nesta instância.');
        }

        $sufixoUsuarios = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.op1.' . $sufixoUsuarios, 'nome' => 'Operador Teste 1', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->operador1 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.op2.' . $sufixoUsuarios, 'nome' => 'Operador Teste 2', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->operador2 = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = $stmt->fetchColumn();
        self::assertNotFalse($credorId, 'credor ACME não encontrado para o teste');
        $this->credorId = (int) $credorId;

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Estratégia', 'documento' => '777' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-ESTR-' . bin2hex(random_bytes(4))]);
        $this->contratoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO estrategias (nome, status) VALUES (:nome, 'ativa')")
            ->execute(['nome' => 'Estratégia Teste ' . bin2hex(random_bytes(4))]);
        $this->estrategiaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO estrategia_contratos (estrategia_id, contrato_id, pessoa_id) VALUES (:estrategia_id, :contrato_id, :pessoa_id)')
            ->execute(['estrategia_id' => $this->estrategiaId, 'contrato_id' => $this->contratoId, 'pessoa_id' => $this->pessoaId]);
        $this->itemId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM estrategia_contratos WHERE id = :id')->execute(['id' => $this->itemId]);
        $this->pdo->prepare('DELETE FROM estrategias WHERE id = :id')->execute(['id' => $this->estrategiaId]);
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id IN (:op1, :op2)')->execute(['op1' => $this->operador1, 'op2' => $this->operador2]);
    }

    public function testReservarItemPendenteFuncionaEMarcaEmAtendimento(): void
    {
        $item = $this->repository->reservarEAbrir($this->itemId, $this->operador1);

        self::assertSame('em_atendimento', $item['status_operacional']);
        self::assertSame($this->operador1, (int) $item['reservado_por_usuario_id']);
    }

    public function testMesmoUsuarioPodeReabrirPropriaReserva(): void
    {
        $this->repository->reservarEAbrir($this->itemId, $this->operador1);

        $item = $this->repository->reservarEAbrir($this->itemId, $this->operador1);

        self::assertSame($this->operador1, (int) $item['reservado_por_usuario_id']);
    }

    public function testOutroUsuarioNaoPodeReservarItemJaReservadoEValido(): void
    {
        $this->repository->reservarEAbrir($this->itemId, $this->operador1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Este contrato já está reservado para outro operador.');
        $this->repository->reservarEAbrir($this->itemId, $this->operador2);
    }

    public function testOutroUsuarioPodeReservarAposReservaExpirar(): void
    {
        $this->repository->reservarEAbrir($this->itemId, $this->operador1);
        $this->pdo->prepare("UPDATE estrategia_contratos SET reserva_expira_em = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = :id")
            ->execute(['id' => $this->itemId]);

        $item = $this->repository->reservarEAbrir($this->itemId, $this->operador2);

        self::assertSame($this->operador2, (int) $item['reservado_por_usuario_id']);
    }

    public function testNaoPodeReservarItemJaTrabalhado(): void
    {
        $this->pdo->prepare("UPDATE estrategia_contratos SET status_operacional = 'trabalhado' WHERE id = :id")
            ->execute(['id' => $this->itemId]);

        $this->expectException(InvalidArgumentException::class);
        $this->repository->reservarEAbrir($this->itemId, $this->operador1);
    }

    public function testNaoPodeReservarItemDeEstrategiaInativa(): void
    {
        $this->pdo->prepare("UPDATE estrategias SET status = 'pausada' WHERE id = :id")
            ->execute(['id' => $this->estrategiaId]);

        $this->expectException(InvalidArgumentException::class);
        $this->repository->reservarEAbrir($this->itemId, $this->operador1);
    }
}
