<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\EstrategiaRepository;
use CRM\Modules\Operacao\Services\WorksQueryService;
use PHPUnit\Framework\TestCase;

/**
 * WorksQueryService.php (141 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. Centraliza as consultas de leitura da tela
 * Pactua (a tela principal de atendimento do operador). Ponto de maior
 * risco real: a validação do vínculo com a estratégia (linhas 86-105) -
 * decide se o "contexto de estratégia" que o operador está vendo ainda é
 * válido (item do contrato certo, reservado PRA ELE, estratégia ainda
 * ativa, item ainda em atendimento). Um bug aqui poderia deixar um
 * operador ver/agir no contexto de reserva de OUTRO operador, ou ver um
 * vínculo de outro contrato. Os 3 caminhos de invalidação têm
 * comportamentos DIFERENTES (2 geram mensagem de erro pro operador, 1 só
 * reseta em silêncio) - por isso são testados separadamente.
 */
final class WorksQueryServiceTest extends TestCase
{
    private PDO $pdo;
    private WorksQueryService $service;
    private EstrategiaRepository $estrategiaRepository;
    private int $pessoaId;
    private int $contratoId;
    private int $outroContratoId;
    private int $estrategiaId;
    private int $itemId;
    private int $itemOutroContratoId;
    private int $operador1;
    private int $operador2;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->estrategiaRepository = new EstrategiaRepository($this->pdo);

        if (!$this->estrategiaRepository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de estratégias indisponível nesta instância.');
        }

        $this->service = new WorksQueryService(
            new AtendimentoAdaptorRepository($this->pdo),
            new AtendimentoRepository($this->pdo),
            new AcordoOperacionalRepository($this->pdo),
            $this->estrategiaRepository
        );

        $sufixoUsuarios = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.wqs1.' . $sufixoUsuarios, 'nome' => 'Operador WQS 1', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->operador1 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO usuarios (login, nome, senha_hash) VALUES (:login, :nome, :hash)')
            ->execute(['login' => 'teste.wqs2.' . $sufixoUsuarios, 'nome' => 'Operador WQS 2', 'hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->operador2 = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste WQS', 'documento' => '881' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-WQS-' . bin2hex(random_bytes(4))]);
        $this->contratoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-WQS-OUTRO-' . bin2hex(random_bytes(4))]);
        $this->outroContratoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO estrategias (nome, status) VALUES (:nome, 'ativa')")
            ->execute(['nome' => 'Estratégia Teste WQS ' . bin2hex(random_bytes(4))]);
        $this->estrategiaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO estrategia_contratos (estrategia_id, contrato_id, pessoa_id) VALUES (:estrategia_id, :contrato_id, :pessoa_id)')
            ->execute(['estrategia_id' => $this->estrategiaId, 'contrato_id' => $this->contratoId, 'pessoa_id' => $this->pessoaId]);
        $this->itemId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO estrategia_contratos (estrategia_id, contrato_id, pessoa_id) VALUES (:estrategia_id, :contrato_id, :pessoa_id)')
            ->execute(['estrategia_id' => $this->estrategiaId, 'contrato_id' => $this->outroContratoId, 'pessoa_id' => $this->pessoaId]);
        $this->itemOutroContratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM estrategia_contratos WHERE id IN (:i1, :i2)')->execute(['i1' => $this->itemId, 'i2' => $this->itemOutroContratoId]);
        $this->pdo->prepare('DELETE FROM estrategias WHERE id = :id')->execute(['id' => $this->estrategiaId]);
        $this->pdo->prepare('DELETE FROM contratos WHERE id IN (:c1, :c2)')->execute(['c1' => $this->contratoId, 'c2' => $this->outroContratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
        $this->pdo->prepare('DELETE FROM usuarios WHERE id IN (:op1, :op2)')->execute(['op1' => $this->operador1, 'op2' => $this->operador2]);
    }

    private function carregar(int $estrategiaItemId, int $usuarioId, string $tab = 'acionamento'): \CRM\Modules\Operacao\ViewModels\WorksPageViewModel
    {
        return $this->service->carregar(
            $this->contratoId,
            0,
            '',
            'auto',
            $tab,
            null,
            null,
            $estrategiaItemId,
            $usuarioId
        );
    }

    public function testVinculoValidoReservadoParaOUsuarioEhMantido(): void
    {
        $this->estrategiaRepository->reservarEAbrir($this->itemId, $this->operador1);

        $viewModel = $this->carregar($this->itemId, $this->operador1);

        self::assertNotNull($viewModel->estrategiaItem);
        self::assertSame($this->itemId, $viewModel->estrategiaItemId);
        self::assertNull($viewModel->estrategiaContextoErro);
    }

    /**
     * Item de estratégia que pertence a OUTRO contrato (não o que está
     * sendo aberto agora) precisa ser rejeitado com mensagem de erro
     * explícita - nunca aceitar silenciosamente um vínculo cruzado.
     */
    public function testVinculoDeOutroContratoGeraErroEReseta(): void
    {
        $this->estrategiaRepository->reservarEAbrir($this->itemOutroContratoId, $this->operador1);

        $viewModel = $this->carregar($this->itemOutroContratoId, $this->operador1);

        self::assertNull($viewModel->estrategiaItem);
        self::assertSame(0, $viewModel->estrategiaItemId);
        self::assertStringContainsString('não foi encontrado', (string) $viewModel->estrategiaContextoErro);
    }

    /**
     * Item já 'trabalhado' zera só o estrategiaItemId (sem mensagem de
     * erro) - diferente dos outros 2 casos de invalidação, que também
     * zeram estrategiaItem pra null. Achado ao escrever este teste (não
     * corrigido, é comportamento assimétrico documentado, não uma falha
     * de segurança): esse ramo NÃO zera estrategiaItem, então o
     * viewmodel ainda carrega os dados do item já trabalhado (só não é
     * mais tratável como "item ativo" via estrategiaItemId=0) - pode
     * ser intencional (permitir a tela mostrar que a tarefa já foi
     * concluída) ou um esquecimento de simetria com os outros 2 ramos.
     */
    public function testItemJaTrabalhadoZeraApenasOIdSemMensagemDeErro(): void
    {
        $this->estrategiaRepository->reservarEAbrir($this->itemId, $this->operador1);
        $this->pdo->prepare("UPDATE estrategia_contratos SET status_operacional = 'trabalhado' WHERE id = :id")->execute(['id' => $this->itemId]);

        $viewModel = $this->carregar($this->itemId, $this->operador1);

        self::assertNotNull($viewModel->estrategiaItem);
        self::assertSame(0, $viewModel->estrategiaItemId);
        self::assertNull($viewModel->estrategiaContextoErro);
    }

    /**
     * Item reservado por OUTRO operador (não o usuário que está abrindo
     * a tela agora) precisa gerar mensagem de erro explícita - essa é a
     * defesa real contra um operador agir no contexto de reserva de
     * outro.
     */
    public function testItemReservadoPorOutroOperadorGeraErroEReseta(): void
    {
        $this->estrategiaRepository->reservarEAbrir($this->itemId, $this->operador2);

        $viewModel = $this->carregar($this->itemId, $this->operador1);

        self::assertNull($viewModel->estrategiaItem);
        self::assertSame(0, $viewModel->estrategiaItemId);
        self::assertStringContainsString('não está reservado para o seu usuário', (string) $viewModel->estrategiaContextoErro);
    }

    public function testTabAcionamentoCarregaHistoricoETabulacoesSemCarregarEnderecosOuParcelas(): void
    {
        $viewModel = $this->carregar(0, $this->operador1, 'acionamento');

        self::assertNotEmpty($viewModel->resultadosAtendimento);
        self::assertSame([], $viewModel->enderecos);
        self::assertSame([], $viewModel->parcelas);
    }

    public function testTabLocalizacaoCarregaEnderecosSemCarregarHistorico(): void
    {
        $viewModel = $this->carregar(0, $this->operador1, 'localizacao');

        self::assertSame([], $viewModel->historico);
    }

    public function testTabFinanceiroCarregaParcelasERespectivoResumo(): void
    {
        $viewModel = $this->carregar(0, $this->operador1, 'financeiro');

        self::assertSame(0, $viewModel->resumoParcelas['total_parcelas']);
        self::assertSame([], $viewModel->historico);
    }

    public function testBuscaPorPessoaRetornaContratosDaPessoa(): void
    {
        $viewModel = $this->service->carregar(0, $this->pessoaId, '', 'auto', 'acionamento', null, null, 0, $this->operador1);

        $ids = array_map(static fn (array $c): int => (int) $c['id'], $viewModel->resultados);
        self::assertContains($this->contratoId, $ids);
        self::assertContains($this->outroContratoId, $ids);
        self::assertNull($viewModel->contrato);
    }

    public function testTimingsSaoRegistradosParaAsConsultasExecutadas(): void
    {
        $viewModel = $this->carregar(0, $this->operador1, 'acionamento');

        self::assertArrayHasKey('contrato', $viewModel->timingsMs);
        self::assertArrayHasKey('historico', $viewModel->timingsMs);
    }
}
