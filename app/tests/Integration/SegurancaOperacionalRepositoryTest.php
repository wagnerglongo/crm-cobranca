<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\SegurancaOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * SegurancaOperacionalRepository.php (161 linhas) - continuação da
 * auditoria de cobertura de 2026-09-17/18. É o backend da tela de
 * configurações de segurança - controla os MESMOS parâmetros que
 * AuthRepository::segurancaConfigInt() usa pro bloqueio temporário de
 * login (já testado em AuthRepositoryTest.php). Zero teste cobria a
 * validação/clamping desses valores.
 *
 * Cuidado: esta classe grava configuração GLOBAL real (não um registro
 * sintético isolado) - o teste captura o valor original em setUp() e
 * SEMPRE restaura em tearDown(), pra não deixar o sistema com um valor
 * de segurança de teste depois da suite rodar.
 */
final class SegurancaOperacionalRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SegurancaOperacionalRepository $repository;
    /** @var array<string,string> */
    private array $configuracaoOriginal;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new SegurancaOperacionalRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de segurança operacional indisponível nesta instância.');
        }

        $this->configuracaoOriginal = $this->repository->configuracoes();
    }

    protected function tearDown(): void
    {
        $this->repository->salvar($this->configuracaoOriginal, null);
    }

    public function testSalvarClampaMaxTentativasLoginNoLimiteSuperior(): void
    {
        $this->repository->salvar(['max_tentativas_login' => 999], null);

        self::assertSame('20', $this->repository->configuracoes()['max_tentativas_login']);
    }

    public function testSalvarClampaMaxTentativasLoginNoLimiteInferior(): void
    {
        $this->repository->salvar(['max_tentativas_login' => 0], null);

        self::assertSame('1', $this->repository->configuracoes()['max_tentativas_login']);
    }

    public function testSalvarClampaMinTamanhoSenhaNoLimiteInferior(): void
    {
        $this->repository->salvar(['min_tamanho_senha' => 1], null);

        self::assertSame('6', $this->repository->configuracoes()['min_tamanho_senha']);
    }

    public function testSalvarERelerRoundtripDentroDosLimites(): void
    {
        $this->repository->salvar([
            'max_tentativas_login' => 7,
            'minutos_bloqueio_login' => 30,
            'min_tamanho_senha' => 10,
            'sessao_expira_horas' => 8,
        ], null);

        $cfg = $this->repository->configuracoes();
        self::assertSame('7', $cfg['max_tentativas_login']);
        self::assertSame('30', $cfg['minutos_bloqueio_login']);
        self::assertSame('10', $cfg['min_tamanho_senha']);
        self::assertSame('8', $cfg['sessao_expira_horas']);
    }

    public function testAvisarAdminPadraoAceitaSomenteValorLiteral1(): void
    {
        $this->repository->salvar(['avisar_admin_padrao' => '1'], null);
        self::assertSame('1', $this->repository->configuracoes()['avisar_admin_padrao']);

        $this->repository->salvar(['avisar_admin_padrao' => 'qualquer_outra_coisa'], null);
        self::assertSame('0', $this->repository->configuracoes()['avisar_admin_padrao']);
    }

    public function testResumoRefleteAdminPadraoDeAcordoComOBancoReal(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE login = 'admin' AND status = 'ativo'");
        $existeDeVerdade = ((int) $stmt->fetchColumn()) > 0;

        $resumo = $this->repository->resumo();

        self::assertSame($existeDeVerdade, $resumo['admin_padrao_existe']);
    }

    public function testResumoContaUsuariosAtivosMaiorOuIgualAZero(): void
    {
        $resumo = $this->repository->resumo();

        self::assertGreaterThanOrEqual(0, $resumo['usuarios_ativos']);
    }
}
