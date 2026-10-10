<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use PHPUnit\Framework\TestCase;

/**
 * AtendimentoAdaptorRepository.php (1.401 linhas, só 2 dos 25 métodos
 * públicos tinham teste) - continuação da auditoria de cobertura
 * 2026-09-17/18. Foco nos métodos de cache ACME corrigidos em 2026-09-16
 * por causa de race conditions reais (lost update - duas chamadas
 * concorrentes escrevendo em payload_extra do MESMO contrato, uma
 * perdendo o que a outra tinha gravado). As correções foram testadas ao
 * vivo com concorrência real na época, mas nunca ganharam um teste
 * automatizado - esses testes cobrem a lógica de merge que sustenta a
 * correção (preservar outras chaves JSON, mesclar caches por parcela),
 * não a concorrência em si (testar lock real de forma segura dentro de
 * PHPUnit single-threaded teria o mesmo risco de lock-wait-timeout já
 * evitado no teste de IntegracaoEventoSaidaRepositoryTest).
 */
final class AtendimentoAdaptorRepositoryAcmeCacheTest extends TestCase
{
    private PDO $pdo;
    private AtendimentoAdaptorRepository $repository;
    private int $contratoId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AtendimentoAdaptorRepository($this->pdo);

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Cache ACME', 'documento' => '666' . random_int(10000000, 99999999)]);
        $pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-ACMECACHE-' . bin2hex(random_bytes(4))]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $stmt = $this->pdo->prepare('SELECT pessoa_id FROM contratos WHERE id = :id');
        $stmt->execute(['id' => $this->contratoId]);
        $pessoaId = $stmt->fetchColumn();
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        if ($pessoaId !== false) {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => (int) $pessoaId]);
        }
    }

    public function testAtualizarFinanceiroEDepoisLerORetornaOMesmoConteudo(): void
    {
        $this->repository->atualizarContratoFinanceiroAcme($this->contratoId, ['Amount' => '123.45', 'DocNumber' => 'DOC-1']);

        $financeiro = $this->repository->obterFinanceiroAcmeCache($this->contratoId);
        self::assertSame('DOC-1', $financeiro['doc_number']);
        self::assertSame(123.45, $financeiro['amount']);
    }

    /**
     * O teste mais importante desta classe: grava "acordo" primeiro,
     * depois "financeiro" - se o UPDATE não fosse atômico via JSON_SET
     * (ex.: alguém trocasse por sobrescrever o payload_extra inteiro), a
     * segunda chamada apagaria o "acme_acordo" que a primeira gravou.
     */
    public function testAtualizarFinanceiroPreservaAcordoJaGravado(): void
    {
        $this->repository->atualizarContratoAcordoAcme($this->contratoId, ['Debt' => ['DebtAmount' => '500.00']]);
        $this->repository->atualizarContratoFinanceiroAcme($this->contratoId, ['Amount' => '200.00']);

        $acordo = $this->repository->obterAcordoAcmeCache($this->contratoId);
        $financeiro = $this->repository->obterFinanceiroAcmeCache($this->contratoId);
        self::assertSame('500.00', $acordo['debt']['DebtAmount']);
        self::assertEquals(200.0, $financeiro['amount']);
    }

    public function testAtualizarAcordoPreservaFinanceiroJaGravado(): void
    {
        $this->repository->atualizarContratoFinanceiroAcme($this->contratoId, ['Amount' => '200.00']);
        $this->repository->atualizarContratoAcordoAcme($this->contratoId, ['Debt' => ['DebtAmount' => '500.00']]);

        $financeiro = $this->repository->obterFinanceiroAcmeCache($this->contratoId);
        self::assertEquals(200.0, $financeiro['amount']);
    }

    public function testSalvarBoletoDeDuasParcelasDiferentesPreservaAmbas(): void
    {
        $this->repository->salvarBoletoAcmeCache($this->contratoId, 100, ['DigitableLine' => 'linha-100']);
        $this->repository->salvarBoletoAcmeCache($this->contratoId, 200, ['DigitableLine' => 'linha-200']);

        $boleto100 = $this->repository->obterBoletoAcmeCache($this->contratoId, 100);
        $boleto200 = $this->repository->obterBoletoAcmeCache($this->contratoId, 200);
        self::assertSame('linha-100', $boleto100['linha_digitavel']);
        self::assertSame('linha-200', $boleto200['linha_digitavel']);
    }

    public function testRegistrarAcaoBoletoAnexaNoHistoricoExistente(): void
    {
        $this->repository->salvarBoletoAcmeCache($this->contratoId, 300, ['DigitableLine' => 'linha-300']);
        $this->repository->registrarAcaoBoletoAcme($this->contratoId, 300, 'download_pdf', 42, 'Operador Teste');

        $boleto = $this->repository->obterBoletoAcmeCache($this->contratoId, 300);
        $acoes = $boleto['acoes'];
        self::assertCount(2, $acoes, 'deve ter a acao de cache_atualizado do salvar + a de download_pdf registrada');
        self::assertSame('download_pdf', $acoes[1]['acao']);
        self::assertSame(42, $acoes[1]['usuario_id']);
    }

    public function testRegistrarAcaoBoletoSemCacheExistenteNaoLancaExcecao(): void
    {
        $this->repository->registrarAcaoBoletoAcme($this->contratoId, 999, 'download_pdf', 1, 'Teste');

        self::assertSame([], $this->repository->obterBoletoAcmeCache($this->contratoId, 999));
    }
}
