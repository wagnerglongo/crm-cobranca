<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Parsers\AcmePipeParser;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\DetectarLayoutService;
use PHPUnit\Framework\TestCase;

/**
 * DetectarLayoutService.php (109 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. É o primeiro passo do pipeline da carga
 * diária da ACME (único credor em produção de verdade): decide se o
 * arquivo recebido tem o cabeçalho esperado ANTES de qualquer linha ser
 * processada. Ponto de maior risco: um cabeçalho errado (arquivo trocado,
 * export diferente da ACME) precisa ser detectado e marcado como
 * 'layout_nao_identificado' com erro registrado - nunca processar dado
 * na coluna errada silenciosamente.
 */
final class DetectarLayoutServiceTest extends TestCase
{
    private PDO $pdo;
    private CargaRepository $repository;
    private DetectarLayoutService $service;
    /** @var list<int> */
    private array $cargasCriadas = [];
    /** @var list<string> */
    private array $arquivosCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CargaRepository($this->pdo);
        $this->service = new DetectarLayoutService($this->repository, new AcmePipeParser());
    }

    protected function tearDown(): void
    {
        foreach ($this->cargasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM cargas_erros WHERE carga_arquivo_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM cargas_arquivos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->arquivosCriados as $path) {
            @unlink($path);
        }
    }

    private function criarCarga(): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO cargas_arquivos (nome_original, caminho_storage, hash_arquivo, status)
            VALUES (:nome, :caminho, :hash, :status)')
            ->execute([
                'nome' => 'teste_layout_' . $sufixo . '.txt',
                'caminho' => '/tmp/teste_layout_' . $sufixo . '.txt',
                'hash' => hash('sha256', $sufixo),
                'status' => 'recebido',
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cargasCriadas[] = $id;
        return $id;
    }

    private function criarArquivo(string $conteudo): string
    {
        $path = sys_get_temp_dir() . '/teste_detectar_layout_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($path, $conteudo);
        $this->arquivosCriados[] = $path;
        return $path;
    }

    private function statusCarga(int $cargaId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM cargas_arquivos WHERE id = :id');
        $stmt->execute(['id' => $cargaId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function codigosErro(int $cargaId): array
    {
        $stmt = $this->pdo->prepare('SELECT codigo_erro FROM cargas_erros WHERE carga_arquivo_id = :id');
        $stmt->execute(['id' => $cargaId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testArquivoVazioLancaExcecaoEMarcaLayoutNaoIdentificado(): void
    {
        $cargaId = $this->criarCarga();
        $path = $this->criarArquivo('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Arquivo ACME vazio ou sem cabeçalho.');
        try {
            $this->service->detectarAcme($cargaId, $path);
        } finally {
            self::assertSame('layout_nao_identificado', $this->statusCarga($cargaId));
            self::assertSame(['ACME_HEADER_AUSENTE'], $this->codigosErro($cargaId));
        }
    }

    public function testCabecalhoComQuantidadeDeColunasErradaLancaExcecao(): void
    {
        $cargaId = $this->criarCarga();
        $path = $this->criarArquivo("Col1|Col2|Col3\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cabeçalho ACME inválido. Veja cargas_erros.');
        try {
            $this->service->detectarAcme($cargaId, $path);
        } finally {
            self::assertSame('layout_nao_identificado', $this->statusCarga($cargaId));
            self::assertSame(['ACME_HEADER_QTD_COLUNAS_INVALIDA'], $this->codigosErro($cargaId));
        }
    }

    public function testCabecalhoComCampoDivergenteRegistraErroPorCampo(): void
    {
        $cargaId = $this->criarCarga();
        $header = AcmePipeParser::EXPECTED_HEADER;
        $header[0] = 'CampoTrocado';
        $header[4] = 'OutroCampoTrocado';
        $path = $this->criarArquivo(implode('|', $header) . "\n");

        $this->expectException(RuntimeException::class);
        try {
            $this->service->detectarAcme($cargaId, $path);
        } finally {
            self::assertSame('layout_nao_identificado', $this->statusCarga($cargaId));
            self::assertSame(['ACME_HEADER_CAMPO_DIVERGENTE', 'ACME_HEADER_CAMPO_DIVERGENTE'], $this->codigosErro($cargaId));
        }
    }

    public function testCabecalhoValidoRetornaMetadadosEMarcaAguardandoProcessamento(): void
    {
        $cargaId = $this->criarCarga();
        $path = $this->criarArquivo(implode('|', AcmePipeParser::EXPECTED_HEADER) . "\n");

        $resultado = $this->service->detectarAcme($cargaId, $path);

        self::assertSame('ACME_COBRANCA_PIPE', $this->layoutCodigo($resultado['layout_id']));
        self::assertSame(AcmePipeParser::EXPECTED_HEADER, $resultado['header']);
        self::assertSame('aguardando_processamento', $this->statusCarga($cargaId));
        self::assertSame([], $this->codigosErro($cargaId));
    }

    public function testCabecalhoComBomNoPrimeiroCampoEhRemovido(): void
    {
        $cargaId = $this->criarCarga();
        $header = AcmePipeParser::EXPECTED_HEADER;
        $header[0] = "\xEF\xBB\xBF" . $header[0];
        $path = $this->criarArquivo(implode('|', $header) . "\n");

        $resultado = $this->service->detectarAcme($cargaId, $path);

        self::assertSame(AcmePipeParser::EXPECTED_HEADER, $resultado['header']);
    }

    private function layoutCodigo(int $layoutId): string
    {
        $stmt = $this->pdo->prepare('SELECT codigo FROM carga_layouts WHERE id = :id');
        $stmt->execute(['id' => $layoutId]);
        return (string) $stmt->fetchColumn();
    }
}
