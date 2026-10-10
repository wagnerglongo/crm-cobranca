<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcionamentoExternoRepository;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;
use PHPUnit\Framework\TestCase;

/**
 * AcionamentoExternoRepository.php (353 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. sincronizar() grava acionamentos
 * feitos DIRETO na ACME (fora do CRM) - tem um guard defensivo real
 * ("nunca associe ao cliente aberto um item de outro documento") contra
 * a API externa devolver por engano um item de OUTRO CPF. Zero teste
 * cobria essa defesa nem a deduplicação por identificador externo.
 */
final class AcionamentoExternoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AcionamentoExternoRepository $repository;
    private int $credorId;
    private int $integracaoId;
    private int $pessoaId;
    private string $documento;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AcionamentoExternoRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de acionamentos externos indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $this->credorId = (int) $stmt->fetchColumn();

        $credorIntegracoes = new CredorIntegracaoRepository($this->pdo);
        $this->integracaoId = $credorIntegracoes->salvarIntegracao($this->credorId, [
            'codigo' => 'teste_acext_' . bin2hex(random_bytes(4)),
            'nome' => 'Integração Teste Acionamento Externo',
            'evento_tipo' => 'acionamento.registrado',
            'tipo_transporte' => 'api',
            'adapter' => 'ACME_ACIONAMENTOS',
            'ativo' => 1,
        ], null);

        $this->documento = '555' . random_int(10000000, 99999999);
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Acionamento Externo', 'documento' => $this->documento]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM integracao_acionamentos_externos WHERE integracao_id = :id')->execute(['id' => $this->integracaoId]);
        $this->pdo->prepare("DELETE FROM integracao_consultas_estado WHERE credor_id = :credor_id AND chave_consulta = :documento")
            ->execute(['credor_id' => $this->credorId, 'documento' => $this->documento]);
        $this->pdo->prepare('DELETE FROM credor_integracoes WHERE id = :id')->execute(['id' => $this->integracaoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    /** @return array<string,mixed> */
    private function integracaoContexto(): array
    {
        return ['id' => $this->integracaoId, 'credor_id' => $this->credorId];
    }

    public function testSincronizarGravaItemValido(): void
    {
        $total = $this->repository->sincronizar($this->integracaoContexto(), $this->pessoaId, null, $this->documento, [
            ['TrackingID' => 'trk-1', 'DocNumber' => $this->documento, 'ActionID' => '10', 'Notes' => 'Contato feito'],
        ]);

        self::assertSame(1, $total);
        $itens = $this->repository->listar($this->credorId, $this->documento);
        self::assertCount(1, $itens);
    }

    public function testSincronizarIgnoraItemComDocumentoDiferenteDoSolicitado(): void
    {
        $outroDocumento = '444' . random_int(10000000, 99999999);

        $total = $this->repository->sincronizar($this->integracaoContexto(), $this->pessoaId, null, $this->documento, [
            ['TrackingID' => 'trk-2', 'DocNumber' => $outroDocumento, 'ActionID' => '10', 'Notes' => 'Item de outro CPF'],
        ]);

        self::assertSame(0, $total, 'item com DocNumber diferente do solicitado nunca deve ser persistido');
        self::assertCount(0, $this->repository->listar($this->credorId, $this->documento));
    }

    public function testSincronizarNovamenteOMesmoTrackingIdNaoDuplica(): void
    {
        $item = ['TrackingID' => 'trk-3', 'DocNumber' => $this->documento, 'ActionID' => '10', 'Notes' => 'Primeira versão'];
        $this->repository->sincronizar($this->integracaoContexto(), $this->pessoaId, null, $this->documento, [$item]);

        $itemAtualizado = ['TrackingID' => 'trk-3', 'DocNumber' => $this->documento, 'ActionID' => '10', 'Notes' => 'Versão atualizada'];
        $this->repository->sincronizar($this->integracaoContexto(), $this->pessoaId, null, $this->documento, [$itemAtualizado]);

        $itens = $this->repository->listar($this->credorId, $this->documento);
        self::assertCount(1, $itens, 'mesmo TrackingID deve atualizar a linha existente, não duplicar');
        self::assertSame('Versão atualizada', $itens[0]['observacao']);
    }

    public function testSincronizarSemItensValidosRetornaZeroENaoLancaExcecao(): void
    {
        $total = $this->repository->sincronizar($this->integracaoContexto(), $this->pessoaId, null, $this->documento, []);

        self::assertSame(0, $total);
    }

    public function testRegistrarFalhaConsultaGravaEstadoDeErroENaoContaComoSincronizacaoValida(): void
    {
        $this->repository->registrarFalhaConsulta($this->integracaoContexto(), $this->documento, 'Timeout na API da ACME');

        // registrarFalhaConsulta grava status='erro' - so' status='sucesso' conta
        // como "ultima sincronizacao" (ver ultimaSincronizacao()), por isso null aqui.
        self::assertNull($this->repository->ultimaSincronizacao($this->credorId, $this->documento));

        $stmt = $this->pdo->prepare("SELECT status, ultimo_erro FROM integracao_consultas_estado WHERE credor_id = :credor_id AND chave_consulta = :documento LIMIT 1");
        $stmt->execute(['credor_id' => $this->credorId, 'documento' => $this->documento]);
        $estado = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($estado);
        self::assertSame('erro', $estado['status']);
        self::assertSame('Timeout na API da ACME', $estado['ultimo_erro']);
    }

    public function testListarComDocumentoDeOutraPessoaNaoRetornaNada(): void
    {
        $this->repository->sincronizar($this->integracaoContexto(), $this->pessoaId, null, $this->documento, [
            ['TrackingID' => 'trk-4', 'DocNumber' => $this->documento, 'ActionID' => '10'],
        ]);

        $outroDocumento = '333' . random_int(10000000, 99999999);
        self::assertCount(0, $this->repository->listar($this->credorId, $outroDocumento));
    }
}
