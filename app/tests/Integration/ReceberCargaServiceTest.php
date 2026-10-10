<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\ReceberCargaService;
use PHPUnit\Framework\TestCase;

/**
 * ReceberCargaService.php (225 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. É a porta de entrada de QUALQUER arquivo de
 * carga (ACME e futuros credores) - calcula hash, copia pro
 * storage definitivo e registra em cargas_arquivos. Ponto de maior risco
 * de segurança real: safeStorageSubdir()/safeFilename() sanitizam
 * caminho vindo de fora (nome de arquivo enviado, subdiretório do
 * scanner) antes de usar em mkdir/copy - se isso falhar, um nome de
 * arquivo malicioso poderia tentar escrever fora do diretório de
 * storage (path traversal). Usa um subdiretório de teste isolado
 * ('teste_receber_carga') dentro do storage real, limpo no tearDown.
 */
final class ReceberCargaServiceTest extends TestCase
{
    private const SUBDIR = 'teste_receber_carga';

    private PDO $pdo;
    private ReceberCargaService $service;
    /** @var list<int> */
    private array $cargasCriadas = [];
    /** @var list<string> */
    private array $arquivosOrigem = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->service = new ReceberCargaService(new CargaRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        foreach ($this->cargasCriadas as $id) {
            $stmt = $this->pdo->prepare('SELECT caminho_storage FROM cargas_arquivos WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $caminho = $stmt->fetchColumn();
            if (is_string($caminho) && $caminho !== '') {
                @unlink($caminho);
            }
            $this->pdo->prepare('DELETE FROM cargas_arquivos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->arquivosOrigem as $path) {
            @unlink($path);
        }

        $base = rtrim(Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas') ?? '/var/www/storage/cargas', '/') . '/' . self::SUBDIR;
        $this->removerDiretorioRecursivo($base);
    }

    private function removerDiretorioRecursivo(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removerDiretorioRecursivo($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function criarArquivoOrigem(string $nome, string $conteudo): string
    {
        $path = sys_get_temp_dir() . '/' . $nome;
        file_put_contents($path, $conteudo);
        $this->arquivosOrigem[] = $path;
        return $path;
    }

    public function testArquivoInexistenteLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Arquivo não encontrado ou sem permissão de leitura');
        $this->service->receber('/tmp/caminho_que_nao_existe_' . bin2hex(random_bytes(4)) . '.txt', self::SUBDIR);
    }

    public function testReceberArquivoNovoCopiaParaStorageERegistra(): void
    {
        $conteudo = 'conteudo-de-teste-' . bin2hex(random_bytes(8));
        $origem = $this->criarArquivoOrigem('teste_20260315_receber.txt', $conteudo);

        $resultado = $this->service->receber($origem, self::SUBDIR);
        $this->cargasCriadas[] = $resultado['id'];

        self::assertFalse($resultado['duplicado']);
        self::assertNull($resultado['duplicado_de_id']);
        self::assertSame(hash('sha256', $conteudo), $resultado['hash_arquivo']);
        self::assertFileExists($resultado['caminho_storage']);
        self::assertSame($conteudo, file_get_contents($resultado['caminho_storage']));
        self::assertStringContainsString('/' . self::SUBDIR . '/', $resultado['caminho_storage']);

        $stmt = $this->pdo->prepare('SELECT data_referencia FROM cargas_arquivos WHERE id = :id');
        $stmt->execute(['id' => $resultado['id']]);
        self::assertSame('2026-03-15', $stmt->fetchColumn());
    }

    /**
     * Mesmo conteúdo enviado 2x (ex.: reenvio acidental de um arquivo já
     * processado) deve ser detectado via hash e marcado como duplicado,
     * apontando pra carga original - nunca reprocessar dado idêntico
     * como se fosse novo.
     */
    public function testReceberArquivoComConteudoIgualJaRecebidoMarcaComoDuplicado(): void
    {
        $conteudo = 'conteudo-duplicado-' . bin2hex(random_bytes(8));
        $origem1 = $this->criarArquivoOrigem('teste_original.txt', $conteudo);
        $primeiro = $this->service->receber($origem1, self::SUBDIR);
        $this->cargasCriadas[] = $primeiro['id'];

        $origem2 = $this->criarArquivoOrigem('teste_reenvio_com_outro_nome.txt', $conteudo);
        $segundo = $this->service->receber($origem2, self::SUBDIR);
        $this->cargasCriadas[] = $segundo['id'];

        self::assertTrue($segundo['duplicado']);
        self::assertSame($primeiro['id'], $segundo['duplicado_de_id']);
    }

    /**
     * safeStorageSubdir() precisa neutralizar tentativa de path traversal
     * no subdiretório (ex.: vindo de um nome de credor malformado) -
     * nunca deixar o arquivo escapar da árvore de storage de cargas.
     */
    public function testSubdiretorioComTentativaDePathTraversalEhSaneado(): void
    {
        $origem = $this->criarArquivoOrigem('teste_traversal.txt', 'x');

        $resultado = $this->service->receber($origem, '../../etc/teste_malicioso');
        $this->cargasCriadas[] = $resultado['id'];

        $base = rtrim(Database::env('STORAGE_CARGAS_PATH', '/var/www/storage/cargas') ?? '/var/www/storage/cargas', '/');
        self::assertStringStartsWith($base . '/', $resultado['caminho_storage']);
        self::assertStringNotContainsString('..', $resultado['caminho_storage']);

        // Limpeza do subdiretório saneado gerado por este teste especifico.
        $this->removerDiretorioRecursivo($base . '/etc_teste_malicioso');
    }

    /**
     * safeFilename() precisa neutralizar caracteres perigosos no NOME do
     * arquivo (ex.: nome de arquivo com metacaracteres de shell ou barra
     * simulando subpath) antes de compor o caminho final no storage.
     */
    public function testNomeDeArquivoComCaracteresPerigososEhSaneado(): void
    {
        $nomePerigoso = 'teste; rm -rf $HOME #.txt';
        $origem = $this->criarArquivoOrigem($nomePerigoso, 'z');

        $resultado = $this->service->receber($origem, self::SUBDIR);
        $this->cargasCriadas[] = $resultado['id'];

        $nomeArmazenado = basename($resultado['caminho_storage']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $nomeArmazenado);
        self::assertFileExists($resultado['caminho_storage']);
    }
}
