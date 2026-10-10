<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Modules\Api\Auth\ApiTokenRepository;
use CRM\Modules\Api\Auth\ApiTokenService;
use CRM\Modules\Auth\Repositories\AuthRepository;
use PHPUnit\Framework\TestCase;

/**
 * ApiTokenService.php (210 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. Emite e valida os tokens da API corporativa
 * (Bearer wrk_api_at_...). Zero teste cobria isso, incluindo a garantia
 * de segurança mais importante: um token emitido ANTES de uma troca de
 * senha deve ser automaticamente revogado quando usado DEPOIS da troca
 * (senhaAlteradaDepois()) - se essa comparação de data quebrar, um
 * token roubado continuaria valendo mesmo depois do usuário trocar a
 * senha pra se proteger.
 */
final class ApiTokenServiceTest extends TestCase
{
    private PDO $pdo;
    private ApiTokenService $service;
    private AuthRepository $auth;
    private int $usuarioId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $tokens = new ApiTokenRepository($this->pdo);
        $this->auth = new AuthRepository($this->pdo);
        $this->service = new ApiTokenService($tokens, $this->auth);

        if (!$tokens->moduloDisponivel()) {
            self::markTestSkipped('Módulo de tokens da API indisponível nesta instância.');
        }

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash, status, senha_alterada_em) VALUES (:login, :nome, :hash, :status, NOW())')
            ->execute(['login' => 'teste.apitoken.' . $sufixo, 'nome' => 'Usuário Teste API Token', 'hash' => password_hash('x', PASSWORD_BCRYPT), 'status' => 'ativo']);
        $this->usuarioId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM api_tokens WHERE usuario_id = :id')->execute(['id' => $this->usuarioId]);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $this->usuarioId]);
    }

    /** @return array<string,mixed> */
    private function usuarioAtivo(array $overrides = []): array
    {
        return array_merge(['id' => $this->usuarioId, 'status' => 'ativo', 'forcar_troca_senha' => 0], $overrides);
    }

    public function testEmitirParaUsuarioInativoLancaExcecao(): void
    {
        $this->expectException(ApiException::class);
        $this->service->emitirParaUsuario($this->usuarioAtivo(['status' => 'inativo']), '127.0.0.1', null);
    }

    public function testEmitirParaUsuarioComForcarTrocaSenhaLancaExcecao(): void
    {
        $this->expectException(ApiException::class);
        $this->service->emitirParaUsuario($this->usuarioAtivo(['forcar_troca_senha' => 1]), '127.0.0.1', null);
    }

    public function testEmitirEAutenticarRoundtripFunciona(): void
    {
        $emitido = $this->service->emitirParaUsuario($this->usuarioAtivo(), '127.0.0.1', 'PHPUnit');

        $context = $this->service->autenticar((string) $emitido['access_token'], '127.0.0.1', 'PHPUnit');

        self::assertSame($this->usuarioId, $context->usuarioId());
    }

    public function testAutenticarComTokenFormatoInvalidoLancaExcecaoRapido(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Token ausente, inválido, expirado ou revogado.');
        $this->service->autenticar('token-formato-errado', '127.0.0.1', null);
    }

    public function testAutenticarComTokenInexistenteLancaExcecao(): void
    {
        $this->expectException(ApiException::class);
        $tokenFalso = 'wrk_api_at_' . bin2hex(random_bytes(32));
        $this->service->autenticar($tokenFalso, '127.0.0.1', null);
    }

    /**
     * A garantia de seguranca mais importante deste servico: token
     * emitido ANTES de uma troca de senha e' automaticamente revogado
     * quando usado DEPOIS. Sem isso, roubar um token continuaria
     * funcionando mesmo apos o usuario trocar a senha achando que se
     * protegeu.
     *
     * Usa instancias NOVAS de AuthRepository/ApiTokenService pra emitir
     * e pra autenticar, de proposito - e' o que reflete a realidade
     * (public/api/v1/index.php cria uma instancia nova a cada request
     * HTTP; emitir e autenticar nunca compartilham a mesma instancia na
     * vida real). Usar a MESMA instancia nos dois passos mascara o teste
     * por causa do cache interno de AuthRepository::buscarUsuarioSessao()
     * (populado indiretamente via usuarioTemPermissao() dentro de
     * emitirParaUsuario()) - achado ao escrever este teste, nao e' bug
     * de producao porque isso nunca acontece de verdade (fora de um
     * teste que reusa a instancia por engano).
     */
    public function testTokenEmitidoAntesDeTrocaDeSenhaERevogadoAposATroca(): void
    {
        $tokens = new ApiTokenRepository($this->pdo);
        $servicoEmissor = new ApiTokenService($tokens, new AuthRepository($this->pdo));
        $emitido = $servicoEmissor->emitirParaUsuario($this->usuarioAtivo(), '127.0.0.1', 'PHPUnit');

        // Simula a troca de senha acontecendo DEPOIS do token ter sido emitido.
        sleep(1);
        $this->pdo->prepare('UPDATE usuarios SET senha_alterada_em = NOW() WHERE id = :id')->execute(['id' => $this->usuarioId]);

        $servicoAutenticador = new ApiTokenService($tokens, new AuthRepository($this->pdo));
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('O token foi invalidado por uma alteração de credencial.');
        $servicoAutenticador->autenticar((string) $emitido['access_token'], '127.0.0.1', 'PHPUnit');
    }
}
