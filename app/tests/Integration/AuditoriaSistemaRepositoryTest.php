<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;
use PHPUnit\Framework\TestCase;

/**
 * AuditoriaSistemaRepository.php (385 linhas) - continuação (última
 * desta rodada) da auditoria de cobertura de 2026-09-17. Ironia real: o
 * PRÓPRIO registro de auditoria do sistema nunca foi testado. O ponto
 * de maior risco é removerDadosSensiveis() - filtra senha/token/segredo
 * do payload ANTES de gravar no log de auditoria (lógica própria,
 * diferente da regex já testada em CredorIntegracaoSegredoTest.php) -
 * se quebrar, um evento de auditoria poderia gravar senha em texto
 * puro no histórico permanente do sistema.
 */
final class AuditoriaSistemaRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AuditoriaSistemaRepository $repository;
    private string $evento;
    /** @var list<int> */
    private array $registrosCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AuditoriaSistemaRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de auditoria do sistema indisponível nesta instância.');
        }

        $this->evento = 'teste.auditoria.' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM auditoria_sistema WHERE evento = :evento')->execute(['evento' => $this->evento]);
    }

    private function buscarUltimoPayload(string $coluna): ?array
    {
        $stmt = $this->pdo->prepare("SELECT {$coluna} FROM auditoria_sistema WHERE evento = :evento ORDER BY id DESC LIMIT 1");
        $stmt->execute(['evento' => $this->evento]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null) {
            return null;
        }
        return json_decode((string) $json, true);
    }

    public function testRegistrarGravaEventoComPayload(): void
    {
        $this->repository->registrar(1, $this->evento, 'teste', 42, 'Descrição de teste', null, ['campo' => 'valor']);

        $payload = $this->buscarUltimoPayload('payload_depois');
        self::assertSame('valor', $payload['campo']);
    }

    public function testRegistrarRemoveChaveDeSegredoDoPayload(): void
    {
        $this->repository->registrar(1, $this->evento, null, null, null, null, [
            'nome' => 'Usuário Teste',
            'senha' => 'nao-deveria-ser-gravado',
            'token' => 'nao-deveria-ser-gravado',
        ]);

        $payload = $this->buscarUltimoPayload('payload_depois');
        self::assertSame('Usuário Teste', $payload['nome']);
        self::assertArrayNotHasKey('senha', $payload);
        self::assertArrayNotHasKey('token', $payload);
    }

    public function testRegistrarRemoveChaveDeSegredoAninhada(): void
    {
        $this->repository->registrar(1, $this->evento, null, null, null, null, [
            'usuario' => [
                'nome' => 'Usuário Teste',
                'password' => 'nao-deveria-ser-gravado',
            ],
        ]);

        $payload = $this->buscarUltimoPayload('payload_depois');
        self::assertSame('Usuário Teste', $payload['usuario']['nome']);
        self::assertArrayNotHasKey('password', $payload['usuario']);
    }

    public function testRegistrarRemoveChaveDeSegredoIndependenteDeMaiuscula(): void
    {
        $this->repository->registrar(1, $this->evento, null, null, null, null, [
            'SENHA' => 'nao-deveria-ser-gravado',
            'Token' => 'nao-deveria-ser-gravado',
        ]);

        $payload = $this->buscarUltimoPayload('payload_depois');
        self::assertArrayNotHasKey('SENHA', $payload);
        self::assertArrayNotHasKey('Token', $payload);
    }

    public function testRegistrarComEntidadeIdNaoNumericaNaoLancaExcecaoESalvaNulo(): void
    {
        $this->repository->registrar(1, $this->evento, 'teste', 'nao-e-um-id', null, null, null);

        $stmt = $this->pdo->prepare('SELECT entidade_id FROM auditoria_sistema WHERE evento = :evento ORDER BY id DESC LIMIT 1');
        $stmt->execute(['evento' => $this->evento]);
        self::assertNull($stmt->fetchColumn());
    }

    public function testResumoGeralContaRegistroRecemCriado(): void
    {
        $antes = $this->repository->resumoGeral();
        $this->repository->registrar(1, $this->evento, null, null, null, null, null);
        $depois = $this->repository->resumoGeral();

        self::assertSame($antes['total'] + 1, $depois['total']);
        self::assertSame($antes['total_hoje'] + 1, $depois['total_hoje']);
    }

    public function testListarFiltraPorEvento(): void
    {
        $this->repository->registrar(1, $this->evento, null, null, 'registro de teste', null, null);

        $resultado = $this->repository->listar(['evento' => $this->evento], 10);

        self::assertCount(1, $resultado);
        self::assertSame($this->evento, $resultado[0]['evento']);
    }
}
