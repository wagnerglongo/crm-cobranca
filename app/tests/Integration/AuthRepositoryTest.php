<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Auth\Repositories\AuthRepository;
use PHPUnit\Framework\TestCase;

/**
 * AuthRepository.php (931 linhas) e' o portao de login/permissao/escopo
 * de todo o CRM - autenticar(), usuarioTemPermissao(), escopoUsuario().
 * Achado 2026-09-17 (auditoria de cobertura de teste): zero testes
 * cobriam essa classe, apesar de ser a mais critica do sistema em termos
 * de risco (uma quebra silenciosa aqui significa login errado, acesso a
 * dado de outro escopo, ou lockout que nao funciona).
 *
 * Cobre so os caminhos de maior risco - nao e' cobertura exaustiva dos
 * 22 metodos publicos da classe (autenticar, permissao, escopo e o
 * bloqueio temporario por tentativas invalidas).
 */
final class AuthRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AuthRepository $repository;
    private ?int $perfilId = null;
    /** @var list<int> */
    private array $usuariosCriados = [];
    /** @var list<int> */
    private array $permissoesCriadas = [];
    /** @var list<int> */
    private array $contratosCriados = [];
    /** @var list<int> */
    private array $pessoasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AuthRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de usuários indisponível nesta instância.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->contratosCriados as $id) {
            $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->pessoasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->usuariosCriados as $id) {
            $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $id]);
        }
        if ($this->perfilId !== null) {
            $this->pdo->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = :id')->execute(['id' => $this->perfilId]);
            $this->pdo->prepare('DELETE FROM perfis_acesso WHERE id = :id')->execute(['id' => $this->perfilId]);
        }
        foreach ($this->permissoesCriadas as $id) {
            $this->pdo->prepare('DELETE FROM permissoes WHERE id = :id')->execute(['id' => $id]);
        }
    }

    /** @param array<string,mixed> $overrides @return int */
    private function criarUsuario(string $senha, array $overrides = []): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $dados = array_merge([
            'login' => 'teste.auth.' . $sufixo,
            'nome' => 'Usuário Teste Auth',
            'email' => 'teste.auth.' . $sufixo . '@example.test',
            'senha_hash' => password_hash($senha, PASSWORD_BCRYPT),
            'status' => 'ativo',
            'admin_total' => 0,
            'escopo_todos_credores' => 0,
            'perfil_id' => $this->perfilId,
        ], $overrides);

        $colunas = array_keys($dados);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $colunas);
        $sql = 'INSERT INTO usuarios (' . implode(',', $colunas) . ') VALUES (' . implode(',', $placeholders) . ')';
        $this->pdo->prepare($sql)->execute($dados);
        $id = (int) $this->pdo->lastInsertId();
        $this->usuariosCriados[] = $id;
        return $id;
    }

    private function criarPerfilComPermissao(string $codigoPermissao): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO perfis_acesso (codigo, nome, ativo) VALUES (:codigo, :nome, 1)')
            ->execute(['codigo' => 'teste_' . $sufixo, 'nome' => 'Perfil Teste ' . $sufixo]);
        $this->perfilId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT id FROM permissoes WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => $codigoPermissao]);
        $permissaoId = $stmt->fetchColumn();
        if ($permissaoId === false) {
            $this->pdo->prepare('INSERT INTO permissoes (codigo, grupo, nome, ativo) VALUES (:codigo, :grupo, :nome, 1)')
                ->execute(['codigo' => $codigoPermissao, 'grupo' => 'teste', 'nome' => $codigoPermissao]);
            $permissaoId = (int) $this->pdo->lastInsertId();
            $this->permissoesCriadas[] = $permissaoId;
        }

        $this->pdo->prepare('INSERT INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:perfil_id, :permissao_id)')
            ->execute(['perfil_id' => $this->perfilId, 'permissao_id' => (int) $permissaoId]);

        return $this->perfilId;
    }

    public function testAutenticarComSenhaCorretaRetornaUsuario(): void
    {
        $this->criarPerfilComPermissao('teste.qualquer');
        $usuarioId = $this->criarUsuario('SenhaForte123!');
        $login = $this->pdo->query("SELECT login FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();

        $resultado = $this->repository->autenticar((string) $login, 'SenhaForte123!');

        self::assertSame($usuarioId, (int) $resultado['id']);
    }

    public function testAutenticarComSenhaErradaLancaExcecao(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!');
        $login = $this->pdo->query("SELECT login FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usuário ou senha inválidos.');
        $this->repository->autenticar((string) $login, 'senha-errada');
    }

    /**
     * Porteiro do Dashboards (public/dashboards_auth.php) valida credencial a cada ~60s
     * por usuario: com $registrarSucesso=false NAO pode mexer em ultimo_login_em
     * (senao o "ultimo login" do usuario viraria o ultimo acesso ao Dashboards).
     */
    public function testAutenticarSemRegistrarSucessoNaoAtualizaUltimoLogin(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!');
        $login = (string) $this->pdo->query("SELECT login FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();

        $this->repository->autenticar($login, 'SenhaForte123!', false);

        $ultimo = $this->pdo->query("SELECT ultimo_login_em FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();
        self::assertNull($ultimo, 'verificacao sem sessao nao deve registrar login');

        $this->repository->autenticar($login, 'SenhaForte123!');
        $ultimo = $this->pdo->query("SELECT ultimo_login_em FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();
        self::assertNotNull($ultimo, 'login normal continua registrando');
    }

    public function testAutenticarSemRegistrarSucessoAindaContaFalhaEBloqueia(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!');
        $login = (string) $this->pdo->query("SELECT login FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();

        try {
            $this->repository->autenticar($login, 'errada', false);
        } catch (InvalidArgumentException) {
        }

        $falhas = (int) $this->pdo->query("SELECT tentativas_login_falha FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();
        self::assertSame(1, $falhas, 'falha no porteiro precisa contar pro bloqueio temporario');
    }

    public function testPermissaoDashboardsExisteEEstaSoNoPerfilAdminPorPadrao(): void
    {
        $stmt = $this->pdo->query("SELECT pa.codigo FROM perfil_permissoes pp
            INNER JOIN permissoes pe ON pe.id = pp.permissao_id
            INNER JOIN perfis_acesso pa ON pa.id = pp.perfil_id
            WHERE pe.codigo = 'dashboards.acessar' AND pa.codigo NOT LIKE 'teste\\_%'");
        $perfis = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertSame(['admin'], $perfis, 'Dashboards mostra dado de todos os credores - so admin por padrao (migration 093)');
    }

    public function testAutenticarUsuarioInexistenteLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->autenticar('usuario.que.nao.existe.' . bin2hex(random_bytes(4)), 'qualquer');
    }

    public function testAutenticarUsuarioInativoLancaExcecao(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!', ['status' => 'inativo']);
        $login = $this->pdo->query("SELECT login FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usuário inativo ou bloqueado.');
        $this->repository->autenticar((string) $login, 'SenhaForte123!');
    }

    public function testBloqueioTemporarioAposTentativasInvalidas(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!');
        $login = (string) $this->pdo->query("SELECT login FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->repository->autenticar($login, 'senha-errada');
            } catch (InvalidArgumentException) {
                // esperado a cada tentativa - só a mensagem da 6ª importa
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usuário temporariamente bloqueado por tentativas inválidas. Tente novamente mais tarde.');
        $this->repository->autenticar($login, 'SenhaForte123!');
    }

    public function testUsuarioAdminTotalTemQualquerPermissao(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!', ['admin_total' => 1]);

        self::assertTrue($this->repository->usuarioTemPermissao($usuarioId, 'qualquer.permissao.inexistente'));
    }

    public function testUsuarioComPermissaoConcedidaRetornaTrue(): void
    {
        $this->criarPerfilComPermissao('teste.acesso.especial');
        $usuarioId = $this->criarUsuario('SenhaForte123!');

        self::assertTrue($this->repository->usuarioTemPermissao($usuarioId, 'teste.acesso.especial'));
    }

    public function testUsuarioSemPermissaoRetornaFalse(): void
    {
        $this->criarPerfilComPermissao('teste.acesso.especial');
        $usuarioId = $this->criarUsuario('SenhaForte123!');

        self::assertFalse($this->repository->usuarioTemPermissao($usuarioId, 'teste.acesso.que.nao.foi.concedido'));
    }

    public function testEscopoUsuarioAdminTotalVeTudo(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!', ['admin_total' => 1]);

        $escopo = $this->repository->escopoUsuario($usuarioId);

        self::assertTrue($escopo['todos']);
    }

    public function testEscopoUsuarioSemCredorAssociadoNaoVeNada(): void
    {
        $this->criarPerfilComPermissao('teste.qualquer');
        $usuarioId = $this->criarUsuario('SenhaForte123!');

        $escopo = $this->repository->escopoUsuario($usuarioId);

        self::assertFalse($escopo['todos']);
        self::assertSame([], $escopo['credor_ids']);
    }

    private function credorId(string $codigo): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => $codigo]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "credor {$codigo} não encontrado para o teste");
        return (int) $id;
    }

    private function criarContrato(int $credorId): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Auth', 'documento' => '888' . random_int(10000000, 99999999)]);
        $pessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $pessoaId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-AUTH-' . $sufixo]);
        $contratoId = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $contratoId;
        return $contratoId;
    }

    private function concederEscopoCredor(int $usuarioId, int $credorId): void
    {
        $this->pdo->prepare('INSERT INTO usuario_credores (usuario_id, credor_id, pode_consultar) VALUES (:usuario_id, :credor_id, 1)')
            ->execute(['usuario_id' => $usuarioId, 'credor_id' => $credorId]);
    }

    /**
     * Achado 2026-09-17: usuarioPodeContrato/Pessoa/Acordo() sao a barreira
     * anti-IDOR do sistema - impedem um operador de um credor de acessar
     * dado de outro so por saber o id. Sem teste, uma quebra aqui (ex.: um
     * refactor que trocasse AND por OR no WHERE) vazaria dado entre
     * credores em silencio.
     */
    public function testUsuarioComEscopoSoVePropriosContratos(): void
    {
        $this->criarPerfilComPermissao('teste.qualquer');
        $usuarioId = $this->criarUsuario('SenhaForte123!');
        $this->concederEscopoCredor($usuarioId, $this->credorId('ACME'));

        $contratoPermitido = $this->criarContrato($this->credorId('ACME'));
        $contratoNegado = $this->criarContrato($this->credorId('GLOBEX'));

        self::assertTrue($this->repository->usuarioPodeContrato($usuarioId, $contratoPermitido));
        self::assertFalse($this->repository->usuarioPodeContrato($usuarioId, $contratoNegado));
    }

    public function testUsuarioComEscopoTodosVeQualquerContrato(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!', ['escopo_todos_credores' => 1]);
        $contrato = $this->criarContrato($this->credorId('INITECH'));

        self::assertTrue($this->repository->usuarioPodeContrato($usuarioId, $contrato));
    }

    public function testUsuarioSemNenhumEscopoNaoVeNenhumContrato(): void
    {
        $this->criarPerfilComPermissao('teste.qualquer');
        $usuarioId = $this->criarUsuario('SenhaForte123!');
        $contrato = $this->criarContrato($this->credorId('ACME'));

        self::assertFalse($this->repository->usuarioPodeContrato($usuarioId, $contrato));
    }

    public function testUsuarioPodeContratoComIdInvalidoRetornaFalse(): void
    {
        $usuarioId = $this->criarUsuario('SenhaForte123!', ['escopo_todos_credores' => 1]);

        self::assertFalse($this->repository->usuarioPodeContrato($usuarioId, 0));
        self::assertFalse($this->repository->usuarioPodeContrato($usuarioId, -1));
    }
}
