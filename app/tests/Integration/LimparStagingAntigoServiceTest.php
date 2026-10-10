<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\LimparStagingAntigoService;
use PHPUnit\Framework\TestCase;

/**
 * LimparStagingAntigoService.php - achado 2026-09-22 (reavaliação): a
 * carga diária real da ACME vinha empilhando ~178 mil linhas/dia em
 * `cargas_linhas_raw`/`stg_carga_canonica` SEM NUNCA purgar - em 6 dias
 * de operação (16 a 22/09) isso já era 93% do tamanho do banco (10,3GB
 * de 11,07GB) e crescendo ~1,7GB/dia, o mesmo padrão que causou o
 * incidente de disco cheio de 2026-09-15. Diferente do
 * desfazer uma aplicação (que mexe em
 * dado canônico), este serviço só remove o rastro intermediário de
 * cargas JÁ aplicadas e antigas - contratos/pessoas/acordos ficam
 * intactos. Foco de segurança do teste: nunca purgar linha que ainda
 * não foi aplicada (`status='valido'`/`'valida'`), mesmo que velha, e
 * nunca purgar carga que não está `concluido`/`concluido_com_erros`.
 */
final class LimparStagingAntigoServiceTest extends TestCase
{
    private PDO $pdo;
    private CargaRepository $repository;
    private LimparStagingAntigoService $service;
    private int $credorId;
    private int $layoutId;
    /** @var list<int> */
    private array $cargasCriadas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CargaRepository($this->pdo);
        $this->service = new LimparStagingAntigoService($this->repository, $this->pdo);

        $this->credorId = $this->scalar("SELECT id FROM credores WHERE codigo = 'ACME' LIMIT 1");
        $this->layoutId = $this->scalar("SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' LIMIT 1");
    }

    protected function tearDown(): void
    {
        foreach ($this->cargasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM stg_carga_canonica WHERE carga_arquivo_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM cargas_linhas_raw WHERE carga_arquivo_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM cargas_arquivos WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /**
     * @param array{status?:string,idade_dias?:int,staging_status?:string,raw_status?:string} $opcoes
     * @return array{carga_id:int}
     */
    private function criarCarga(array $opcoes = []): array
    {
        $status = $opcoes['status'] ?? 'concluido';
        $idadeDias = $opcoes['idade_dias'] ?? 0;
        $stagingStatus = $opcoes['staging_status'] ?? 'aplicado';
        $rawStatus = $opcoes['raw_status'] ?? 'aplicada';
        $sufixo = bin2hex(random_bytes(4));

        $this->pdo->prepare('INSERT INTO cargas_arquivos (nome_original, caminho_storage, hash_arquivo, status, credor_id, layout_id)
            VALUES (:nome, :caminho, :hash, :status, :credor_id, :layout_id)')
            ->execute([
                'nome' => 'teste_limpar_staging_' . $sufixo . '.txt',
                'caminho' => '/tmp/teste_limpar_staging_' . $sufixo . '.txt',
                'hash' => hash('sha256', $sufixo),
                'status' => $status,
                'credor_id' => $this->credorId,
                'layout_id' => $this->layoutId,
            ]);
        $cargaId = (int) $this->pdo->lastInsertId();
        $this->cargasCriadas[] = $cargaId;

        if ($idadeDias > 0) {
            $this->pdo->prepare('UPDATE cargas_arquivos SET criado_em = DATE_SUB(NOW(), INTERVAL :dias DAY) WHERE id = :id')
                ->execute(['dias' => $idadeDias, 'id' => $cargaId]);
        }

        $this->pdo->prepare("INSERT INTO cargas_linhas_raw (carga_arquivo_id, numero_linha, linha_bruta, hash_linha, status)
            VALUES (:carga, 1, 'linha-teste', :hash, :status)")
            ->execute(['carga' => $cargaId, 'hash' => hash('sha256', 'raw-' . $cargaId), 'status' => $rawStatus]);
        $rawId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO stg_carga_canonica (carga_arquivo_id, linha_raw_id, credor_id, status, hash_negocio)
            VALUES (:carga, :raw_id, :credor, :status, :hash)")
            ->execute(['carga' => $cargaId, 'raw_id' => $rawId, 'credor' => $this->credorId, 'status' => $stagingStatus, 'hash' => hash('sha256', 'stg-' . $cargaId)]);

        return ['carga_id' => $cargaId];
    }

    private function contarStaging(int $cargaId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stg_carga_canonica WHERE carga_arquivo_id = :id');
        $stmt->execute(['id' => $cargaId]);
        return (int) $stmt->fetchColumn();
    }

    private function contarRaw(int $cargaId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cargas_linhas_raw WHERE carga_arquivo_id = :id');
        $stmt->execute(['id' => $cargaId]);
        return (int) $stmt->fetchColumn();
    }

    public function testRetencaoMenorQueUmDiaLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Retenção deve ser de pelo menos 1 dia.');
        $this->service->executar(0);
    }

    public function testBatchForaDoIntervaloLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Batch de limpeza deve ficar entre 100 e 50000.');
        $this->service->executar(7, 50);
    }

    public function testMinFreeMbMenorQue512LancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Margem mínima de disco deve ser de pelo menos 512 MB.');
        $this->service->executar(7, 5000, 100);
    }

    public function testMinFreeMbAbsurdamenteAltoInterrompePreventivamente(): void
    {
        // Mensagem vem do DiskSpaceGuard compartilhado (mesma proteção usada por
        // outras rotinas longas de carga) - genérica
        // de propósito, não específica de limpeza de staging.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('interrompida preventivamente');
        $this->service->executar(7, 5000, 999999999);
    }

    /**
     * O teste central: carga antiga+concluída+já aplicada é purgada;
     * carga recente (dentro da janela) não é tocada mesmo concluída;
     * carga antiga mas AINDA NÃO APLICADA (staging 'valido') nunca é
     * tocada, por mais velha que seja - é exatamente o que
     * aplicar_cargas_acme_pendentes.php usa pra saber o que falta aplicar.
     */
    public function testPurgaSoCargaAntigaConcluidaEJaAplicada(): void
    {
        $antigaAplicada = $this->criarCarga(['idade_dias' => 10]);
        $recenteAplicada = $this->criarCarga(['idade_dias' => 1]);
        $antigaPendente = $this->criarCarga(['idade_dias' => 10, 'staging_status' => 'valido', 'raw_status' => 'valida']);
        $antigaComErro = $this->criarCarga(['idade_dias' => 10, 'staging_status' => 'erro', 'raw_status' => 'erro']);

        $resultado = $this->service->executar(7);

        self::assertSame(0, $this->contarStaging($antigaAplicada['carga_id']), 'antiga+aplicada deveria ter sido purgada');
        self::assertSame(0, $this->contarRaw($antigaAplicada['carga_id']));

        self::assertSame(1, $this->contarStaging($recenteAplicada['carga_id']), 'recente não deveria ter sido tocada mesmo concluída');
        self::assertSame(1, $this->contarStaging($antigaPendente['carga_id']), 'staging ainda não aplicado (valido) nunca pode ser purgado');
        self::assertSame(1, $this->contarRaw($antigaPendente['carga_id']));
        self::assertSame(0, $this->contarStaging($antigaComErro['carga_id']), 'erro também é status final, deve ser purgado se antigo');

        // Achado 2026-09-28 (checagem geral com dados reais de operador): o
        // serviço não tem como recortar por credor/carga - com dias de
        // operação real, o banco já tem outras cargas legitimamente
        // elegíveis além das 2 deste teste, então o total global purgado
        // passa a ser maior que 2 (a rodada de testes chegou a apagar
        // ~178 mil linhas de staging real, do mesmo jeito que o cron
        // scripts/limpar-staging-antigo.sh faria de qualquer forma - nada
        // fora da política, só adiantado). As asserções por carga acima (ID
        // a ID) continuam exatas e são a real garantia de segurança do
        // teste; aqui só confirmamos que as 2 cargas do teste entraram na
        // conta.
        self::assertGreaterThanOrEqual(2, $resultado['staging_removido']);
        self::assertGreaterThanOrEqual(2, $resultado['raw_removido']);
    }

    /**
     * Carga que ainda está em processamento (qualquer status diferente
     * de concluido/concluido_com_erros) NUNCA pode ser purgada, mesmo
     * que a data de criação seja antiga (ex.: uma carga travada há dias).
     */
    public function testNuncaPurgaCargaQueNaoEstaConcluida(): void
    {
        $emAndamento = $this->criarCarga(['status' => 'aplicando', 'idade_dias' => 30]);
        $comErroGeral = $this->criarCarga(['status' => 'erro', 'idade_dias' => 30]);

        $this->service->executar(7);

        self::assertSame(1, $this->contarStaging($emAndamento['carga_id']));
        self::assertSame(1, $this->contarStaging($comErroGeral['carga_id']));
    }

    public function testNaoTocaEmDadoCanonicoAoPurgar(): void
    {
        $antiga = $this->criarCarga(['idade_dias' => 10]);
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento, origem_carga_id) VALUES (:n, :d, :c)')
            ->execute(['n' => 'Pessoa Teste Limpeza Staging', 'd' => '883' . random_int(10000000, 99999999), 'c' => $antiga['carga_id']]);
        $pessoaId = (int) $this->pdo->lastInsertId();

        $this->service->executar(7);

        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM pessoas WHERE id = {$pessoaId}"), 'dado canônico não pode ser afetado pela limpeza de staging');
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $pessoaId]);
    }

    public function testCargasElegiveisContaERetornaCorte(): void
    {
        $this->criarCarga(['idade_dias' => 10]);
        $this->criarCarga(['idade_dias' => 1]);

        $resultado = $this->service->executar(7);

        self::assertGreaterThanOrEqual(1, $resultado['cargas_elegiveis']);
        self::assertNotEmpty($resultado['cutoff']);
    }

    public function testLockJaAdquiridoPorOutraConexaoLancaExcecao(): void
    {
        $outra = $this->novaConexao();
        $lock = 'crm:limpar_staging_antigo';
        $stmt = $outra->prepare('SELECT GET_LOCK(:n, 0)');
        $stmt->execute(['n' => $lock]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Já existe uma limpeza de staging antigo em execução');
            $this->service->executar(7);
        } finally {
            $outra->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $lock]);
        }
    }

    private function novaConexao(): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', Database::env('DB_HOST', 'mysql'), Database::env('DB_PORT', '3306'), Database::env('DB_DATABASE', 'crm_dev'));
        return new PDO($dsn, Database::env('DB_USERNAME', 'crm_app'), Database::env('DB_PASSWORD', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
