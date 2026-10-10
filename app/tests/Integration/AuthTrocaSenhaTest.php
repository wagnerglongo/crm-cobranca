<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Auth\Repositories\AuthRepository;
use PHPUnit\Framework\TestCase;

/**
 * Troca de senha pelo próprio usuário (requisito de 2026-10-06): o
 * admin cria com senha provisória + "trocar no próximo acesso" e o operador
 * define a dele.
 */
final class AuthTrocaSenhaTest extends TestCase
{
    private PDO $pdo;
    private AuthRepository $repo;
    private int $usuarioId;
    private string $login;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repo = new AuthRepository($this->pdo);
        $this->login = 'teste.trocasenha.' . bin2hex(random_bytes(4));
        $this->pdo->prepare("INSERT INTO usuarios (nome, login, senha_hash, status, forcar_troca_senha) VALUES ('Teste Troca Senha', :login, :hash, 'ativo', 1)")
            ->execute(['login' => $this->login, 'hash' => password_hash('Provisoria123', PASSWORD_DEFAULT)]);
        $this->usuarioId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM usuario_auditoria WHERE usuario_id = :id')->execute(['id' => $this->usuarioId]);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $this->usuarioId]);
    }

    /** @return array<string,mixed> */
    private function usuario(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $this->usuarioId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testTrocaComSucessoZeraAObrigacaoEPassaAValerANova(): void
    {
        $this->repo->alterarSenhaPropria($this->usuarioId, 'Provisoria123', 'MinhaSenha#2026', 'MinhaSenha#2026', 8);

        $usuario = $this->usuario();
        self::assertSame(0, (int) $usuario['forcar_troca_senha']);
        self::assertNotNull($usuario['senha_alterada_em']);
        self::assertTrue(password_verify('MinhaSenha#2026', (string) $usuario['senha_hash']));
        self::assertFalse(password_verify('Provisoria123', (string) $usuario['senha_hash']));

        $auditoria = $this->pdo->prepare("SELECT COUNT(*) FROM usuario_auditoria WHERE usuario_id = :id AND evento = 'usuario.senha_alterada'");
        $auditoria->execute(['id' => $this->usuarioId]);
        self::assertSame(1, (int) $auditoria->fetchColumn());
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function casosInvalidos(): iterable
    {
        yield 'senha atual errada' => ['Errada123', 'MinhaSenha#2026', 'MinhaSenha#2026', 'senha atual não confere'];
        yield 'nova curta' => ['Provisoria123', 'curta', 'curta', 'pelo menos 8'];
        yield 'confirmação diferente' => ['Provisoria123', 'MinhaSenha#2026', 'OutraSenha#2026', 'confirmação'];
        yield 'igual à provisória' => ['Provisoria123', 'Provisoria123', 'Provisoria123', 'diferente da atual'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casosInvalidos')]
    public function testCasoInvalidoNaoMudaNada(string $atual, string $nova, string $confirmacao, string $mensagem): void
    {
        try {
            $this->repo->alterarSenhaPropria($this->usuarioId, $atual, $nova, $confirmacao, 8);
            self::fail('deveria ter recusado');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($mensagem, $e->getMessage());
        }

        $usuario = $this->usuario();
        self::assertSame(1, (int) $usuario['forcar_troca_senha'], 'continua obrigado a trocar');
        self::assertTrue(password_verify('Provisoria123', (string) $usuario['senha_hash']));
    }

    public function testSessaoDoUsuarioMostraAObrigacao(): void
    {
        self::assertSame(1, (int) ($this->repo->buscarUsuarioSessao($this->usuarioId)['forcar_troca_senha'] ?? 0));
    }
}
