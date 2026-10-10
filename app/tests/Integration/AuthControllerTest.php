<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\RedisRateLimiter;
use CRM\Modules\Api\Auth\ApiTokenRepository;
use CRM\Modules\Api\Auth\ApiTokenService;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Api\Controllers\AuthController;
use CRM\Modules\Auth\Repositories\AuthRepository;
use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;
use PHPUnit\Framework\TestCase;

/**
 * AuthController.php (109 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. É o ponto de entrada de autenticação de
 * TODA a API corporativa (POST /auth/token). Foco no que é mais
 * crítico de segurança e nunca foi testado: senha errada e usuário
 * inativo devem dar a MESMA mensagem 401 genérica (não revelar se o
 * login existe), e toda tentativa (sucesso ou falha) deve gravar
 * auditoria - se isso quebrar, um ataque de força bruta ficaria sem
 * rastro.
 *
 * RedisRateLimiter é construído direto (sem mock) contra o Redis real
 * do docker-compose, igual ao já feito em CredorConfiguracaoRepositoryTest.
 * Poucas chamadas a token() de propósito (limite real é 10/60s por IP)
 * pra não flertar com o próprio rate limit sendo testado.
 */
final class AuthControllerTest extends TestCase
{
    private PDO $pdo;
    private AuthController $controller;
    private AuditoriaSistemaRepository $auditoria;
    private int $usuarioId;
    private string $login;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $auth = new AuthRepository($this->pdo);
        $tokenRepository = new ApiTokenRepository($this->pdo);
        $this->auditoria = new AuditoriaSistemaRepository($this->pdo);

        if (!$auth->moduloDisponivel() || !$tokenRepository->moduloDisponivel() || !$this->auditoria->moduloDisponivel()) {
            self::markTestSkipped('Módulo de usuários/tokens/auditoria indisponível nesta instância.');
        }

        $this->controller = new AuthController($auth, new ApiTokenService($tokenRepository, $auth), new RedisRateLimiter(), $this->auditoria);

        $this->login = 'teste.authctrl.' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash, status, senha_alterada_em) VALUES (:login, :nome, :hash, :status, NOW())')
            ->execute(['login' => $this->login, 'nome' => 'Usuário Teste AuthController', 'hash' => password_hash('senha-correta-123', PASSWORD_BCRYPT), 'status' => 'ativo']);
        $this->usuarioId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM api_tokens WHERE usuario_id = :id')->execute(['id' => $this->usuarioId]);
        $this->pdo->prepare('DELETE FROM auditoria_sistema WHERE usuario_id = :id')->execute(['id' => $this->usuarioId]);
        $this->pdo->prepare('DELETE FROM auditoria_sistema WHERE payload_depois LIKE :termo')->execute(['termo' => '%' . $this->login . '%']);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $this->usuarioId]);
    }

    private function requisicao(array $body): ApiRequest
    {
        return new ApiRequest('POST', '/auth/token', [], ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), '127.0.0.1');
    }

    private function ultimoEvento(string $evento): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM auditoria_sistema WHERE evento = :evento AND payload_depois LIKE :termo ORDER BY id DESC LIMIT 1');
        $stmt->execute(['evento' => $evento, 'termo' => '%' . $this->login . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function testTokenComCamposVaziosLancaValidationError(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->token($this->requisicao(['login' => '', 'senha' => '']), null, [], 'req-1');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            throw $e;
        }
    }

    public function testTokenComSenhaErradaLanca401GenericoEGravaAuditoria(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->token($this->requisicao(['login' => $this->login, 'senha' => 'senha-totalmente-errada']), null, [], 'req-2');
        } catch (ApiException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('INVALID_CREDENTIALS', $e->errorCode);
            throw $e;
        } finally {
            $evento = $this->ultimoEvento('api.auth.login_falha');
            self::assertNotNull($evento, 'deveria ter gravado auditoria de falha de login');
            self::assertNull($evento['usuario_id'], 'auditoria de login falho não deve expor usuario_id (o mesmo path cobre login inexistente)');
        }
    }

    /**
     * Usuário inativo deve receber a MESMA mensagem genérica de senha
     * errada - o controller nunca deve deixar escapar "usuário existe
     * mas está inativo" pra quem não está autenticado.
     */
    public function testTokenComUsuarioInativoLanca401Generico(): void
    {
        $this->pdo->prepare('UPDATE usuarios SET status = :status WHERE id = :id')->execute(['status' => 'inativo', 'id' => $this->usuarioId]);

        $this->expectException(ApiException::class);
        try {
            $this->controller->token($this->requisicao(['login' => $this->login, 'senha' => 'senha-correta-123']), null, [], 'req-3');
        } catch (ApiException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('INVALID_CREDENTIALS', $e->errorCode);
            throw $e;
        }
    }

    public function testTokenComCredenciaisValidasEmiteTokenEGravaAuditoria(): void
    {
        $resposta = $this->controller->token($this->requisicao(['login' => $this->login, 'senha' => 'senha-correta-123']), null, [], 'req-4');

        self::assertSame(200, $resposta->status());
        self::assertNotEmpty($resposta->payload()['access_token'] ?? null);

        $stmt = $this->pdo->prepare("SELECT * FROM auditoria_sistema WHERE evento = 'api.auth.token_emitido' AND usuario_id = :id ORDER BY id DESC LIMIT 1");
        $stmt->execute(['id' => $this->usuarioId]);
        $evento = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($evento, 'deveria ter gravado auditoria de emissão de token');
    }

    public function testMeSemAutenticacaoLancaExcecao(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->me($this->requisicao([]), null, [], 'req-5');
        } catch (ApiException $e) {
            self::assertSame(401, $e->status);
            throw $e;
        }
    }

    public function testMeComContextoRetornaUsuarioAutenticado(): void
    {
        $context = new AuthContext($this->usuarioId, null, 'usuario', ['id' => $this->usuarioId, 'login' => $this->login], ['*'], ['todos' => true, 'credor_ids' => [], 'carteira_ids' => []], ['api']);

        $resposta = $this->controller->me($this->requisicao([]), $context, [], 'req-6');

        self::assertSame($this->usuarioId, $resposta->payload()['usuario']['id']);
    }
}
