<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\AplicarCargaAcmeService;
use PHPUnit\Framework\TestCase;

/**
 * AplicarCargaAcmeService.php (718 linhas) - aplica a carga diária real
 * da ACME (único credor em produção de verdade hoje) no banco. Achado
 * 2026-09-18: zero teste cobria isso.
 *
 * O comentário no próprio código documenta um fix real de concorrência:
 * "nenhum dos scripts que chamam este método tinha proteção contra duas
 * execuções simultâneas para a MESMA carga" - resolvido com
 * GET_LOCK/RELEASE_LOCK nomeado por carga_id. Esse é o ponto de maior
 * risco e o foco deste teste (não a aplicação de linha em si, que
 * exigiria replicar o formato exato do layout ACME_COBRANCA_PIPE - fica
 * pra uma rodada futura se for necessário).
 */
final class AplicarCargaAcmeServiceTest extends TestCase
{
    private PDO $pdo;
    private CargaRepository $repository;
    private AplicarCargaAcmeService $service;
    private int $credorId;
    private int $layoutId;
    /** @var list<int> */
    private array $cargasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CargaRepository($this->pdo);
        $this->service = new AplicarCargaAcmeService($this->repository);

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();

        $stmt2 = $this->pdo->prepare("SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' LIMIT 1");
        $stmt2->execute();
        $this->layoutId = (int) $stmt2->fetchColumn();
        self::assertGreaterThan(0, $this->layoutId, 'layout ACME_COBRANCA_PIPE não encontrado para o teste');
    }

    protected function tearDown(): void
    {
        foreach ($this->cargasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM cargas_arquivos WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function criarCarga(string $status, ?int $credorId = null, ?int $layoutId = null): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO cargas_arquivos (nome_original, caminho_storage, hash_arquivo, status, credor_id, layout_id)
            VALUES (:nome, :caminho, :hash, :status, :credor_id, :layout_id)')
            ->execute([
                'nome' => 'teste_acme_' . $sufixo . '.txt',
                'caminho' => '/tmp/teste_acme_' . $sufixo . '.txt',
                'hash' => hash('sha256', $sufixo),
                'status' => $status,
                'credor_id' => $credorId ?? $this->credorId,
                'layout_id' => $layoutId ?? $this->layoutId,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cargasCriadas[] = $id;
        return $id;
    }

    private function novaConexao(): PDO
    {
        $host = Database::env('DB_HOST', 'mysql');
        $port = Database::env('DB_PORT', '3306');
        $database = Database::env('DB_DATABASE', 'crm_dev');
        $username = Database::env('DB_USERNAME', 'crm_app');
        $password = Database::env('DB_PASSWORD', '');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
        return new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testAplicarCargaInexistenteLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não encontrada');
        $this->service->aplicar(999999999);
    }

    public function testAplicarCargaComStatusErradoLancaExcecao(): void
    {
        $id = $this->criarCarga('recebido');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Permitido apenas status concluido.');
        $this->service->aplicar($id);
    }

    public function testAplicarCargaDeOutroCredorLancaExcecao(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'GLOBEX']);
        $outroCredorId = (int) $stmt->fetchColumn();
        $id = $this->criarCarga('concluido', $outroCredorId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não é do credor ACME');
        $this->service->aplicar($id);
    }

    public function testAplicarCargaSemLinhasValidasRetornaStatusSemLinhas(): void
    {
        $id = $this->criarCarga('concluido');

        $resultado = $this->service->aplicar($id);

        self::assertSame('sem_linhas_validas', $resultado['status']);
    }

    /**
     * Prova que o lock e' liberado corretamente mesmo no caminho
     * "sem_linhas_validas" - se o release_lock falhasse (ex.: excecao
     * antes do finally, ou bug no nome do lock), esta segunda chamada
     * imediata ficaria travada achando que ainda ha' aplicacao em
     * andamento.
     */
    public function testLockELiberadoAposAplicarComSucessoPermitindoNovaChamada(): void
    {
        $id = $this->criarCarga('concluido');

        $this->service->aplicar($id);
        $resultado = $this->service->aplicar($id);

        self::assertSame('sem_linhas_validas', $resultado['status']);
    }

    /**
     * O teste mais importante: com o lock nomeado JA' adquirido por outra
     * conexao real (simulando outro processo/cron rodando a mesma carga
     * ao mesmo tempo), aplicar() deve detectar a contencao e falhar
     * rapido com mensagem clara - nunca tentar aplicar a mesma carga 2x
     * em paralelo.
     */
    public function testAplicarComLockJaAdquiridoPorOutraConexaoLancaExcecao(): void
    {
        $id = $this->criarCarga('concluido');
        $conexaoConcorrente = $this->novaConexao();

        $lockName = 'crm:aplicar_carga:' . $id;
        $stmt = $conexaoConcorrente->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->bindValue('name', $lockName);
        $stmt->execute();
        self::assertSame(1, (int) $stmt->fetchColumn(), 'não conseguiu adquirir o lock de teste na conexão concorrente');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Já existe uma aplicação em andamento');
            $this->service->aplicar($id);
        } finally {
            $release = $conexaoConcorrente->prepare('SELECT RELEASE_LOCK(:name)');
            $release->bindValue('name', $lockName);
            $release->execute();
        }
    }
}
