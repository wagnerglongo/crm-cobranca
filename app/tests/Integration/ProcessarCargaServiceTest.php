<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Parsers\AcmePipeParser;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\NormalizarLinhaAcmeService;
use CRM\Modules\Cargas\Services\ProcessarCargaService;
use CRM\Modules\Cargas\Validators\ValidadorLinhaAcme;
use PHPUnit\Framework\TestCase;

/**
 * ProcessarCargaService.php (267 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. É o núcleo do pipeline da carga diária ACME:
 * lê o arquivo linha a linha, faz parse+normalize+validate e grava em 3
 * tabelas dentro de UMA transação por lote (cargas_linhas_raw,
 * stg_carga_canonica, cargas_erros) - se qualquer parte falhar, o lote
 * inteiro reverte (nunca staging parcial sem o raw correspondente).
 * Ponto de maior risco: erro BLOQUEANTE (documento ausente/invalido)
 * marca a linha como 'erro' e conta em linhas_com_erro, mas ALERTA
 * (e-mail mal formatado) deixa a linha 'valido' e vai pro staging mesmo
 * assim - se essa distinção quebrar, dado recuperável seria descartado
 * ou dado ruim entraria sem sinalização.
 */
final class ProcessarCargaServiceTest extends TestCase
{
    private PDO $pdo;
    private CargaRepository $repository;
    private ProcessarCargaService $service;
    private int $credorId;
    private int $layoutId;
    /** @var list<int> */
    private array $cargasCriadas = [];
    /** @var list<string> */
    private array $arquivosCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CargaRepository($this->pdo);
        $this->service = new ProcessarCargaService(
            $this->repository,
            new AcmePipeParser(),
            new NormalizarLinhaAcmeService(),
            new ValidadorLinhaAcme()
        );

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();

        $stmt2 = $this->pdo->prepare("SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' LIMIT 1");
        $stmt2->execute();
        $this->layoutId = (int) $stmt2->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach ($this->cargasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM cargas_erros WHERE carga_arquivo_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM stg_carga_canonica WHERE carga_arquivo_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM cargas_linhas_raw WHERE carga_arquivo_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM cargas_arquivos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->arquivosCriados as $path) {
            @unlink($path);
        }
    }

    private function criarCarga(): int
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO cargas_arquivos (nome_original, caminho_storage, hash_arquivo, status, credor_id, layout_id)
            VALUES (:nome, :caminho, :hash, :status, :credor_id, :layout_id)')
            ->execute([
                'nome' => 'teste_processar_' . $sufixo . '.txt',
                'caminho' => '/tmp/teste_processar_' . $sufixo . '.txt',
                'hash' => hash('sha256', $sufixo),
                'status' => 'aguardando_processamento',
                'credor_id' => $this->credorId,
                'layout_id' => $this->layoutId,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cargasCriadas[] = $id;
        return $id;
    }

    /** @param array<string,string> $overrides */
    private function linha(array $overrides = []): string
    {
        $campos = array_fill_keys(AcmePipeParser::EXPECTED_HEADER, '');
        foreach ($overrides as $campo => $valor) {
            $campos[$campo] = $valor;
        }
        return implode('|', array_values($campos));
    }

    private function criarArquivo(array $linhas): string
    {
        $conteudo = implode('|', AcmePipeParser::EXPECTED_HEADER) . "\n" . implode("\n", $linhas) . "\n";
        $path = sys_get_temp_dir() . '/teste_processar_carga_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($path, $conteudo);
        $this->arquivosCriados[] = $path;
        return $path;
    }

    private function processar(int $cargaId, string $path): array
    {
        return $this->service->processarAcme($cargaId, $path, $this->credorId, null, $this->layoutId, AcmePipeParser::EXPECTED_HEADER);
    }

    public function testDuasLinhasValidasGravamStagingEConcluiComSucesso(): void
    {
        $cargaId = $this->criarCarga();
        $path = $this->criarArquivo([
            $this->linha(['Number' => '11122233344', 'Name' => 'Cliente Um', 'AccountNumber' => 'CTR-1']),
            $this->linha(['Number' => '22233344455', 'Name' => 'Cliente Dois', 'AccountNumber' => 'CTR-2']),
        ]);

        $resultado = $this->processar($cargaId, $path);

        self::assertSame(2, $resultado['total_linhas']);
        self::assertSame(2, $resultado['linhas_raw_gravadas']);
        self::assertSame(2, $resultado['linhas_validas']);
        self::assertSame(0, $resultado['linhas_com_erro']);
        self::assertSame(2, $resultado['linhas_staging']);
        self::assertSame('concluido', $resultado['status_final']);

        $stmt = $this->pdo->prepare("SELECT status FROM cargas_arquivos WHERE id = :id");
        $stmt->execute(['id' => $cargaId]);
        self::assertSame('concluido', $stmt->fetchColumn());
    }

    /**
     * Documento ausente é erro BLOQUEANTE (ValidadorLinhaAcme) - a linha
     * precisa ser marcada como 'erro' tanto no raw quanto no staging, e
     * contabilizada em linhas_com_erro, sem derrubar o processamento das
     * outras linhas do mesmo lote.
     */
    public function testLinhaSemDocumentoContaComoErroBloqueanteSemDerrubarOLote(): void
    {
        $cargaId = $this->criarCarga();
        $path = $this->criarArquivo([
            $this->linha(['Number' => '', 'Name' => 'Sem Documento', 'AccountNumber' => 'CTR-3']),
            $this->linha(['Number' => '33344455566', 'Name' => 'Cliente Válido', 'AccountNumber' => 'CTR-4']),
        ]);

        $resultado = $this->processar($cargaId, $path);

        self::assertSame(1, $resultado['linhas_com_erro']);
        self::assertSame(1, $resultado['linhas_validas']);
        self::assertSame('concluido_com_erros', $resultado['status_final']);

        $stmt = $this->pdo->prepare("SELECT codigo_erro FROM cargas_erros WHERE carga_arquivo_id = :id AND etapa = 'validacao'");
        $stmt->execute(['id' => $cargaId]);
        self::assertSame(['DOCUMENTO_OBRIGATORIO'], $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt2 = $this->pdo->prepare("SELECT status FROM stg_carga_canonica WHERE carga_arquivo_id = :id ORDER BY id");
        $stmt2->execute(['id' => $cargaId]);
        self::assertSame(['erro', 'valido'], $stmt2->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Achado ao escrever este teste (documentado, não é bug): os dois
     * caminhos de ALERTA de ValidadorLinhaAcme (EMAIL_FORMATO_INVALIDO e
     * ANO_VEICULO_FORA_FAIXA) são inalcançáveis por ESTE pipeline -
     * NormalizarLinhaAcmeService já descarta e-mail sem formato válido
     * (email()/extractEmails() só retornam o que já passou por
     * filter_var) e já zera ano fora de 1900-2100 (year()) ANTES do
     * Validador rodar. Não é dado ruim escapando - é o oposto (o
     * normalizador é mais estrito que o validador) - mas o Validador
     * nunca vai de fato emitir esses 2 alertas enquanto for chamado só
     * depois do Normalizador, como é hoje. Cobertura direta desses 2
     * caminhos (chamando o Validador isolado, sem passar pelo
     * Normalizador) está em ValidadorLinhaAcmeTest.php.
     */

    public function testLinhaVaziaEhIgnoradaSemContarComoLinhaProcessada(): void
    {
        $cargaId = $this->criarCarga();
        $conteudo = implode('|', AcmePipeParser::EXPECTED_HEADER) . "\n"
            . $this->linha(['Number' => '55566677788', 'Name' => 'Cliente Um', 'AccountNumber' => 'CTR-6']) . "\n"
            . "\n"
            . $this->linha(['Number' => '66677788899', 'Name' => 'Cliente Dois', 'AccountNumber' => 'CTR-7']) . "\n";
        $path = sys_get_temp_dir() . '/teste_processar_vazia_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($path, $conteudo);
        $this->arquivosCriados[] = $path;

        $resultado = $this->processar($cargaId, $path);

        self::assertSame(2, $resultado['total_linhas']);
        self::assertSame(2, $resultado['linhas_validas']);
    }

    /**
     * Linha com quantidade de colunas errada (arquivo corrompido no meio,
     * não no cabeçalho) precisa virar erro de PARSE na própria linha, sem
     * quebrar o processamento das linhas vizinhas.
     */
    public function testLinhaComQuantidadeDeColunasErradaContaComoErroDeParse(): void
    {
        $cargaId = $this->criarCarga();
        $conteudo = implode('|', AcmePipeParser::EXPECTED_HEADER) . "\n"
            . 'coluna1|coluna2|coluna3' . "\n"
            . $this->linha(['Number' => '77788899900', 'Name' => 'Cliente Ok', 'AccountNumber' => 'CTR-8']) . "\n";
        $path = sys_get_temp_dir() . '/teste_processar_colunas_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($path, $conteudo);
        $this->arquivosCriados[] = $path;

        $resultado = $this->processar($cargaId, $path);

        self::assertSame(2, $resultado['total_linhas']);
        self::assertSame(1, $resultado['linhas_com_erro']);
        self::assertSame(1, $resultado['linhas_validas']);
        self::assertSame('concluido_com_erros', $resultado['status_final']);

        $stmt = $this->pdo->prepare("SELECT codigo_erro FROM cargas_erros WHERE carga_arquivo_id = :id AND etapa = 'parse'");
        $stmt->execute(['id' => $cargaId]);
        self::assertSame('ACME_PARSE_LINHA_INVALIDA', $stmt->fetchColumn());
    }
}
