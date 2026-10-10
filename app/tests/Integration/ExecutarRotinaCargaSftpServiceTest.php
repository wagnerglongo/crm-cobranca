<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Security\CredencialCryptoService;
use CRM\Modules\Cargas\Repositories\RotinaCargaSftpRepository;
use CRM\Modules\Cargas\Services\ExecutarRotinaCargaSftpService;
use PHPUnit\Framework\TestCase;

/**
 * ExecutarRotinaCargaSftpService.php (149 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. O corpo do método (conectar no
 * SFTP real do credor, baixar arquivo, opcionalmente disparar
 * processamento) não é testável sem um servidor SFTP real (mesma
 * decisão já tomada pra `AcmeApiClient` - sem cliente
 * injetável, phpseclib3\Net\SFTP é chamado direto). O que É testável e
 * de alto risco real: o lock nomeado (`GET_LOCK`) que impede a MESMA
 * rotina rodar duas vezes ao mesmo tempo (agendador automático e
 * "Executar agora" clicado juntos) - o mesmo padrão de proteção já
 * testado em `AplicarCargaAcmeServiceTest`/`IntegracaoEventoSaidaRepositoryTest`.
 * Esse teste passa NUNCA chega a tentar conectar no SFTP, porque o
 * bloqueio do lock intercepta antes.
 */
final class ExecutarRotinaCargaSftpServiceTest extends TestCase
{
    private PDO $pdo;
    private RotinaCargaSftpRepository $repository;
    private ExecutarRotinaCargaSftpService $service;
    private int $rotinaId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new RotinaCargaSftpRepository($this->pdo, new CredencialCryptoService());
        $this->service = new ExecutarRotinaCargaSftpService($this->pdo, $this->repository, '/var/www/html/console');

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = (int) $stmt->fetchColumn();

        $this->rotinaId = $this->repository->criar([
            'credor_id' => $credorId,
            'nome' => 'Rotina de teste lock ' . bin2hex(random_bytes(4)),
            'origem_host' => 'sftp.teste.local',
            'origem_porta' => '22',
            'origem_caminho' => '/cargas',
            'usuario' => 'usuario_teste',
            'senha' => 'senha-secreta-123',
            'periodicidade' => 'manual',
        ], null);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM rotinas_carga_sftp_execucoes WHERE rotina_id = :id')->execute(['id' => $this->rotinaId]);
        $this->pdo->prepare('DELETE FROM rotinas_carga_sftp WHERE id = :id')->execute(['id' => $this->rotinaId]);
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

    /**
     * Com o lock nomeado JÁ adquirido por outra conexão real (simulando
     * o agendador e "Executar agora" disparando ao mesmo tempo),
     * executar() precisa detectar a contenção e retornar 'bloqueada'
     * IMEDIATAMENTE, sem tentar conectar no SFTP - e ainda assim
     * registrar a tentativa bloqueada no histórico de execuções.
     */
    public function testExecutarComLockJaAdquiridoPorOutraConexaoRetornaBloqueadaSemTentarSftp(): void
    {
        $conexaoConcorrente = $this->novaConexao();
        $lockName = 'crm:rotina_carga_sftp:' . $this->rotinaId;
        $stmt = $conexaoConcorrente->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->bindValue('name', $lockName);
        $stmt->execute();
        self::assertSame(1, (int) $stmt->fetchColumn(), 'não conseguiu adquirir o lock de teste na conexão concorrente');

        try {
            $resultado = $this->service->executar($this->rotinaId, null);

            self::assertSame('bloqueada', $resultado['status']);
            self::assertSame(0, $resultado['total_arquivos']);
            self::assertStringContainsString('já existe uma execução', mb_strtolower($resultado['mensagem']));

            $registro = $this->pdo->prepare('SELECT status, total_arquivos_baixados FROM rotinas_carga_sftp_execucoes WHERE rotina_id = :id ORDER BY id DESC LIMIT 1');
            $registro->execute(['id' => $this->rotinaId]);
            $linha = $registro->fetch(PDO::FETCH_ASSOC);
            self::assertSame('bloqueada', $linha['status']);
            self::assertSame(0, (int) $linha['total_arquivos_baixados']);
        } finally {
            $release = $conexaoConcorrente->prepare('SELECT RELEASE_LOCK(:name)');
            $release->bindValue('name', $lockName);
            $release->execute();
        }
    }

    /**
     * Depois que o lock é liberado, uma nova chamada não pode ficar
     * presa achando que ainda há execução em andamento - mesma garantia
     * de "lock sempre libera" já provada em AplicarCargaAcmeServiceTest.
     * Aqui a chamada real chega a tentar o SFTP (host falso) e falha por
     * conexão - o que já basta pra provar que passou pelo lock.
     */
    public function testLockEhLiberadoAposFalhaPermitindoNovaTentativa(): void
    {
        $resultado = $this->service->executar($this->rotinaId, null);
        self::assertSame('falha', $resultado['status']);

        $conexaoConcorrente = $this->novaConexao();
        $lockName = 'crm:rotina_carga_sftp:' . $this->rotinaId;
        $stmt = $conexaoConcorrente->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->bindValue('name', $lockName);
        $stmt->execute();
        self::assertSame(1, (int) $stmt->fetchColumn(), 'lock deveria estar livre depois da execução anterior terminar');

        $release = $conexaoConcorrente->prepare('SELECT RELEASE_LOCK(:name)');
        $release->bindValue('name', $lockName);
        $release->execute();
    }
}
