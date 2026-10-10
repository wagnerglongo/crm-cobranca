<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\RelatorioApiTokenRepository;
use PHPUnit\Framework\TestCase;

/**
 * RelatorioApiTokenRepository.php (128 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. Emite/valida tokens da API de
 * relatórios CSV (Bearer wrk_rpt_...), nunca tinha teste. Pontos de
 * maior risco de segurança: `validar()` precisa rejeitar token
 * revogado E expirado (não só formato/hash), e `revogar()` só pode
 * revogar um token que pertence ao PRÓPRIO usuário que está pedindo -
 * sem esse `AND usuario_id = :usuario_id` no UPDATE, um usuário
 * conseguiria revogar o token de relatório de outra pessoa só sabendo o
 * id.
 */
final class RelatorioApiTokenRepositoryTest extends TestCase
{
    private PDO $pdo;
    private RelatorioApiTokenRepository $repository;
    private int $usuarioId;
    private int $outroUsuarioId;
    /** @var list<int> */
    private array $tokensCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new RelatorioApiTokenRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de tokens de relatório indisponível nesta instância.');
        }

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.rpttoken1.' . $sufixo, 'nome' => 'Usuário Teste Relatório 1', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->usuarioId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.rpttoken2.' . $sufixo, 'nome' => 'Usuário Teste Relatório 2', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->outroUsuarioId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM relatorios_api_tokens WHERE usuario_id IN (:u1, :u2)')->execute(['u1' => $this->usuarioId, 'u2' => $this->outroUsuarioId]);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id IN (:u1, :u2)')->execute(['u1' => $this->usuarioId, 'u2' => $this->outroUsuarioId]);
    }

    public function testCriarComUsuarioInvalidoLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->repository->criar(0, 'Token Teste');
    }

    public function testCriarComNomeVazioLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->repository->criar($this->usuarioId, '   ');
    }

    public function testCriarComNomeMuitoLongoLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->repository->criar($this->usuarioId, str_repeat('a', 121));
    }

    public function testCriarEValidarRoundtripFunciona(): void
    {
        $emitido = $this->repository->criar($this->usuarioId, 'Token de Teste');
        $this->tokensCriados[] = $emitido['id'];

        $validado = $this->repository->validar($emitido['token']);

        self::assertNotNull($validado);
        self::assertSame($emitido['id'], (int) $validado['id']);
        self::assertSame($this->usuarioId, (int) $validado['usuario_id']);
    }

    public function testValidarTokenInexistenteRetornaNull(): void
    {
        $falso = 'wrk_rpt_' . bin2hex(random_bytes(24));

        self::assertNull($this->repository->validar($falso));
    }

    public function testValidarTokenComFormatoCurtoRetornaNullRapido(): void
    {
        self::assertNull($this->repository->validar('curto'));
    }

    public function testValidarTokenRevogadoRetornaNull(): void
    {
        $emitido = $this->repository->criar($this->usuarioId, 'Token a Revogar');
        $this->tokensCriados[] = $emitido['id'];

        $this->repository->revogar($emitido['id'], $this->usuarioId);

        self::assertNull($this->repository->validar($emitido['token']));
    }

    public function testValidarTokenExpiradoRetornaNull(): void
    {
        $emitido = $this->repository->criar($this->usuarioId, 'Token Expirado', 1);
        $this->tokensCriados[] = $emitido['id'];
        $this->pdo->prepare('UPDATE relatorios_api_tokens SET expira_em = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = :id')->execute(['id' => $emitido['id']]);

        self::assertNull($this->repository->validar($emitido['token']));
    }

    public function testCriarComValidadeZeroNuncaExpira(): void
    {
        $emitido = $this->repository->criar($this->usuarioId, 'Token Sem Expiração', 0);
        $this->tokensCriados[] = $emitido['id'];

        self::assertNull($emitido['expira_em']);
        self::assertNotNull($this->repository->validar($emitido['token']));
    }

    /**
     * Barreira anti-IDOR: revogar() só pode afetar um token que pertence
     * ao usuário que está pedindo. Um usuário passando o id de um token
     * de OUTRO usuário não deve conseguir revogá-lo.
     */
    public function testRevogarComUsuarioErradoNaoAfetaOTokenDeOutroUsuario(): void
    {
        $emitido = $this->repository->criar($this->usuarioId, 'Token Protegido');
        $this->tokensCriados[] = $emitido['id'];

        $this->repository->revogar($emitido['id'], $this->outroUsuarioId);

        self::assertNotNull($this->repository->validar($emitido['token']), 'token não deveria ter sido revogado por outro usuário');
    }

    public function testListarPorUsuarioNaoRetornaTokensDeOutroUsuario(): void
    {
        $meu = $this->repository->criar($this->usuarioId, 'Meu Token');
        $this->tokensCriados[] = $meu['id'];
        $doOutro = $this->repository->criar($this->outroUsuarioId, 'Token do Outro');
        $this->tokensCriados[] = $doOutro['id'];

        $lista = $this->repository->listarPorUsuario($this->usuarioId);

        $ids = array_map(static fn (array $t): int => (int) $t['id'], $lista);
        self::assertContains($meu['id'], $ids);
        self::assertNotContains($doOutro['id'], $ids);
    }

    public function testRegistrarUsoAtualizaUltimoUso(): void
    {
        $emitido = $this->repository->criar($this->usuarioId, 'Token Uso');
        $this->tokensCriados[] = $emitido['id'];

        $this->repository->registrarUso($emitido['id']);

        $stmt = $this->pdo->prepare('SELECT ultimo_uso_em FROM relatorios_api_tokens WHERE id = :id');
        $stmt->execute(['id' => $emitido['id']]);
        self::assertNotNull($stmt->fetchColumn());
    }
}
