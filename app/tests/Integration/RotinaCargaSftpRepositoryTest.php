<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Security\CredencialCryptoService;
use CRM\Modules\Cargas\Repositories\RotinaCargaSftpRepository;
use PHPUnit\Framework\TestCase;

/**
 * Rotinas de coleta de carga via SFTP (feature nova de 2026-09-16, pedido
 * do TI). Cobre o ponto mais sensivel - senha nunca sai em
 * texto puro de buscarPorId()/listar(), so credenciaisParaExecucao()
 * descriptografa - e a regra de que so pode ativar uma rotina se o
 * credor tiver um comando de processamento diario conhecido (ACME
 * hoje; Globex ainda nao tem pipeline, entao nao pode ser ativada).
 */
final class RotinaCargaSftpRepositoryTest extends TestCase
{
    private PDO $pdo;
    private RotinaCargaSftpRepository $repository;
    /** @var list<int> */
    private array $rotinasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new RotinaCargaSftpRepository($this->pdo, new CredencialCryptoService());
    }

    protected function tearDown(): void
    {
        foreach ($this->rotinasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM rotinas_carga_sftp WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function credorId(string $codigo): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => $codigo]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "credor {$codigo} não encontrado para o teste");
        return (int) $id;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function dadosRotina(array $overrides = []): array
    {
        return array_merge([
            'credor_id' => $this->credorId('ACME'),
            'nome' => 'Rotina de teste ' . bin2hex(random_bytes(4)),
            'origem_host' => 'sftp.teste.local',
            'origem_porta' => '22',
            'origem_caminho' => '/cargas',
            'usuario' => 'usuario_teste',
            'senha' => 'senha-secreta-123',
            'periodicidade' => 'manual',
        ], $overrides);
    }

    public function testCriarNaoExpoeSenhaEmBuscarPorIdOuListar(): void
    {
        $id = $this->repository->criar($this->dadosRotina(), null);
        $this->rotinasCriadas[] = $id;

        $rotina = $this->repository->buscarPorId($id);
        self::assertNotNull($rotina);
        self::assertArrayNotHasKey('senha_criptografada', $rotina);

        $listagem = $this->repository->listar();
        $encontrada = null;
        foreach ($listagem as $row) {
            if ((int) $row['id'] === $id) {
                $encontrada = $row;
            }
        }
        self::assertNotNull($encontrada);
        self::assertArrayNotHasKey('senha_criptografada', $encontrada);
    }

    public function testCredenciaisParaExecucaoDescriptografaCorretamente(): void
    {
        $id = $this->repository->criar($this->dadosRotina(['senha' => 'minha-senha-original']), null);
        $this->rotinasCriadas[] = $id;

        $cred = $this->repository->credenciaisParaExecucao($id);
        self::assertSame('minha-senha-original', $cred['senha']);
        self::assertSame('usuario_teste', $cred['usuario']);
        self::assertSame('ACME', $cred['credor_codigo']);
    }

    public function testAtualizarSemSenhaMantemASenhaAnterior(): void
    {
        $id = $this->repository->criar($this->dadosRotina(['senha' => 'senha-original']), null);
        $this->rotinasCriadas[] = $id;

        $dados = $this->dadosRotina(['senha' => '']);
        $this->repository->atualizar($id, $dados, null);

        $cred = $this->repository->credenciaisParaExecucao($id);
        self::assertSame('senha-original', $cred['senha'], 'senha deveria permanecer a mesma quando o campo é deixado em branco');
    }

    public function testAtualizarComNovaSenhaSubstituiASenha(): void
    {
        $id = $this->repository->criar($this->dadosRotina(['senha' => 'senha-antiga']), null);
        $this->rotinasCriadas[] = $id;

        $this->repository->atualizar($id, $this->dadosRotina(['senha' => 'senha-nova']), null);

        $cred = $this->repository->credenciaisParaExecucao($id);
        self::assertSame('senha-nova', $cred['senha']);
    }

    public function testNaoPermiteAtivarRotinaDeCredorSemComandoDeProcessamento(): void
    {
        $id = $this->repository->criar($this->dadosRotina(['credor_id' => $this->credorId('GLOBEX')]), null);
        $this->rotinasCriadas[] = $id;

        $this->expectException(InvalidArgumentException::class);
        $this->repository->alternarStatus($id, true, null);
    }

    public function testPermiteAtivarRotinaDeCredorComComandoDeProcessamento(): void
    {
        $id = $this->repository->criar($this->dadosRotina(['credor_id' => $this->credorId('ACME')]), null);
        $this->rotinasCriadas[] = $id;

        $this->repository->alternarStatus($id, true, null);

        $rotina = $this->repository->buscarPorId($id);
        self::assertSame('ativa', $rotina['status']);
    }

    public function testNormalizaHostQuandoColaAUrlInteiraComEsquemaECaminho(): void
    {
        $id = $this->repository->criar($this->dadosRotina([
            'origem_host' => 'sftp://203.0.113.10/FilesPool/WorkTeam249/',
            'origem_porta' => '2224',
            'origem_caminho' => '/',
        ]), null);
        $this->rotinasCriadas[] = $id;

        $cred = $this->repository->credenciaisParaExecucao($id);
        self::assertSame('203.0.113.10', $cred['origem_host'], 'o esquema sftp:// e o caminho embutido não deveriam sobrar no host');
        self::assertSame('/FilesPool/WorkTeam249/', $cred['origem_caminho'], 'o caminho embutido no host deveria ter sido extraído pra Pasta de origem');
    }

    public function testNaoSobrescreveCaminhoExplicitoComOQueEstiverNoHost(): void
    {
        $id = $this->repository->criar($this->dadosRotina([
            'origem_host' => 'sftp://203.0.113.10/caminho-do-host/',
            'origem_caminho' => '/caminho-explicito',
        ]), null);
        $this->rotinasCriadas[] = $id;

        $cred = $this->repository->credenciaisParaExecucao($id);
        self::assertSame('203.0.113.10', $cred['origem_host']);
        self::assertSame('/caminho-explicito', $cred['origem_caminho'], 'caminho digitado explicitamente no campo próprio não deveria ser sobrescrito');
    }

    public function testDestinoEComandoSaoMapeadosPorCredor(): void
    {
        $base = rtrim((string) Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas'), '/');
        self::assertSame($base . '/inbox/acme', $this->repository->destinoPara('ACME'));
        self::assertSame($base . '/inbox/initech', $this->repository->destinoPara('INITECH'));
        self::assertSame('processar_acme_diario.php', $this->repository->comandoProcessamentoPara('ACME'));
        self::assertNull($this->repository->comandoProcessamentoPara('INITECH'));
        self::assertNull($this->repository->comandoProcessamentoPara('GLOBEX'));
    }

    public function testListarAtivasDevidasConsideraHorarioEEvitaRepetirNoMesmoDia(): void
    {
        $id = $this->repository->criar($this->dadosRotina([
            'periodicidade' => 'diario',
            'horario' => '00:00',
        ]), null);
        $this->rotinasCriadas[] = $id;
        $this->repository->alternarStatus($id, true, null);

        $agora = new DateTimeImmutable('now');
        $devidas = $this->repository->listarAtivasDevidas($agora);
        self::assertContains($id, array_map(static fn (array $r): int => (int) $r['id'], $devidas), 'rotina ativa com horário já passado deveria estar devida');

        $this->repository->registrarExecucao($id, 'sucesso', 1, 'teste', null);

        $devidasDepois = $this->repository->listarAtivasDevidas($agora);
        self::assertNotContains($id, array_map(static fn (array $r): int => (int) $r['id'], $devidasDepois), 'não deveria rodar de novo no mesmo dia após já ter executado');
    }
}
