<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\DashboardOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard/funil de atendimento solicitado pela operação (2026-09-23): clientes
 * contactados -> eventos "Alô" -> eventos CPC -> acordos no período, com
 * filtro de credor. "Alô" e CPC reaproveitam a classificação já existente
 * em atendimento_resultados (considera_contato_efetivo = CPC). Zero teste
 * cobria essa lógica antes.
 *
 * As contagens são globais (é um dashboard), então os testes usam SEMPRE
 * um credor de teste recém-criado (zero atividade prévia) e filtram por
 * ele - senão a base real de produção (que já tem milhares de acordos e
 * atendimentos "hoje") vazaria pra dentro das asserções.
 */
final class DashboardOperacionalRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DashboardOperacionalRepository $repository;
    private int $credorId;
    private int $outroCredorId;
    private int $pessoaId;
    private int $contratoId;
    private int $contratoLoteBId;
    private int $outroContratoId;
    private string $loteA;
    private string $loteB;
    private int $resultadoCpcId;
    private int $resultadoNaoAtendeId;
    private int $resultadoAcordoId;
    /** @var list<int> */
    private array $atendimentosCriados = [];
    /** @var list<int> */
    private array $acordosCriados = [];
    /** @var list<int> */
    private array $contratosCriados = [];
    /** @var list<int> */
    private array $pessoasCriadas = [];
    /** @var list<int> */
    private array $resultadosCriados = [];
    /** @var list<int> */
    private array $credoresCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new DashboardOperacionalRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de atendimentos indisponível nesta instância.');
        }

        $sufixo = bin2hex(random_bytes(4));

        $this->pdo->prepare("INSERT INTO credores (codigo, nome, ativo) VALUES (:codigo, :nome, 1)")
            ->execute(['codigo' => 'DASH_A_' . strtoupper($sufixo), 'nome' => 'Credor Teste Dashboard A ' . $sufixo]);
        $this->credorId = (int) $this->pdo->lastInsertId();
        $this->credoresCriados[] = $this->credorId;

        $this->pdo->prepare("INSERT INTO credores (codigo, nome, ativo) VALUES (:codigo, :nome, 1)")
            ->execute(['codigo' => 'DASH_B_' . strtoupper($sufixo), 'nome' => 'Credor Teste Dashboard B ' . $sufixo]);
        $this->outroCredorId = (int) $this->pdo->lastInsertId();
        $this->credoresCriados[] = $this->outroCredorId;

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Dashboard', 'documento' => '555' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $this->pessoaId;

        $this->loteA = 'LOTE_TESTE_A_' . $sufixo;
        $this->loteB = 'LOTE_TESTE_B_' . $sufixo;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato, lote) VALUES (:pessoa_id, :credor_id, :codigo, :lote)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-DASH-' . $sufixo, 'lote' => $this->loteA]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $this->contratoId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato, lote) VALUES (:pessoa_id, :credor_id, :codigo, :lote)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'codigo' => 'TESTE-DASH-B-' . $sufixo, 'lote' => $this->loteB]);
        $this->contratoLoteBId = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $this->contratoLoteBId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->outroCredorId, 'codigo' => 'TESTE-DASH-OUTRO-' . $sufixo]);
        $this->outroContratoId = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $this->outroContratoId;

        $this->pdo->prepare("INSERT INTO atendimento_resultados (codigo, nome, categoria, resultado_legacy, considera_contato_efetivo, considera_alo, ativo) VALUES (:codigo, :nome, 'teste', 'em_negociacao', 1, 1, 1)")
            ->execute(['codigo' => 'dash_cpc_' . $sufixo, 'nome' => 'CPC Teste ' . $sufixo]);
        $this->resultadoCpcId = (int) $this->pdo->lastInsertId();
        $this->resultadosCriados[] = $this->resultadoCpcId;

        $this->pdo->prepare("INSERT INTO atendimento_resultados (codigo, nome, categoria, resultado_legacy, considera_contato_efetivo, ativo) VALUES (:codigo, :nome, 'teste', 'nao_atende', 0, 1)")
            ->execute(['codigo' => 'dash_na_' . $sufixo, 'nome' => 'Não Atende Teste ' . $sufixo]);
        $this->resultadoNaoAtendeId = (int) $this->pdo->lastInsertId();
        $this->resultadosCriados[] = $this->resultadoNaoAtendeId;

        // Flags alô/CPC desligados de propósito: a tabulação de acordo conta
        // como alô/CPC pelo resultado_legacy, não pelos flags nem pelo canal.
        $this->pdo->prepare("INSERT INTO atendimento_resultados (codigo, nome, categoria, resultado_legacy, considera_contato_efetivo, considera_alo, ativo) VALUES (:codigo, :nome, 'teste', 'promessa_pagamento', 0, 0, 1)")
            ->execute(['codigo' => 'dash_acordo_' . $sufixo, 'nome' => 'Acordo Teste ' . $sufixo]);
        $this->resultadoAcordoId = (int) $this->pdo->lastInsertId();
        $this->resultadosCriados[] = $this->resultadoAcordoId;
    }

    protected function tearDown(): void
    {
        foreach ($this->acordosCriados as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->atendimentosCriados as $id) {
            $this->pdo->prepare('DELETE FROM atendimentos_historico WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->resultadosCriados as $id) {
            $this->pdo->prepare('DELETE FROM atendimento_resultados WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->contratosCriados as $id) {
            $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->pessoasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->credoresCriados as $id) {
            $this->pdo->prepare('DELETE FROM credores WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function criarAtendimento(int $contratoId, string $canal, ?int $resultadoId, string $quando): int
    {
        $resultadoLegado = 'outro';
        if ($resultadoId === $this->resultadoCpcId) {
            $resultadoLegado = 'em_negociacao';
        } elseif ($resultadoId === $this->resultadoNaoAtendeId) {
            $resultadoLegado = 'nao_atende';
        } elseif ($resultadoId === $this->resultadoAcordoId) {
            $resultadoLegado = 'promessa_pagamento';
        }

        $stmt = $this->pdo->prepare('INSERT INTO atendimentos_historico
                (pessoa_id, contrato_id, canal, resultado, resultado_id, contato_utilizado, operador_nome, criado_em)
            VALUES (:pessoa_id, :contrato_id, :canal, :resultado, :resultado_id, :contato_utilizado, :operador_nome, :criado_em)');
        $stmt->execute([
            'pessoa_id' => $this->pessoaId,
            'contrato_id' => $contratoId,
            'canal' => $canal,
            'resultado' => $resultadoLegado,
            'resultado_id' => $resultadoId,
            'contato_utilizado' => '11999998888',
            'operador_nome' => 'Teste Dashboard',
            'criado_em' => $quando,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->atendimentosCriados[] = $id;
        return $id;
    }

    private function criarAcordo(int $contratoId, int $credorId, string $quando): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, credor_id, status_operacional, valor_acordo, data_promessa_pagamento, quantidade_parcelas, origem, criado_em)
            VALUES (:pessoa_id, :contrato_id, :credor_id, 'formalizado', 300.00, CURRENT_DATE, 1, 'manual', :criado_em)");
        $stmt->execute([
            'pessoa_id' => $this->pessoaId,
            'contrato_id' => $contratoId,
            'credor_id' => $credorId,
            'criado_em' => $quando,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->acordosCriados[] = $id;
        return $id;
    }

    /** Simula um acordo criado/conciliado automaticamente pela sincronização com a ACME (não é "feito pelo CRM"). */
    private function criarAcordoOrigemAcme(int $contratoId, int $credorId, string $quando, ?array $responsavelAtribuido = null): int
    {
        // status 'quebrado' (não ativo) só pra não colidir com a unicidade de
        // acordo ativo por contrato (o teste já tem outro acordo ativo nesse
        // mesmo contrato) - a classificação de origem não depende do status.
        $dadosPayload = ['financeiro_perfil' => 'acme', 'credor_api' => 'ACME', 'agreement_id' => '999999'];
        if ($responsavelAtribuido !== null) {
            $dadosPayload['operador_responsavel'] = $responsavelAtribuido;
        }
        $payload = json_encode($dadosPayload, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare("INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, credor_id, status_operacional, valor_acordo, data_promessa_pagamento, quantidade_parcelas, origem, payload_extra, criado_em)
            VALUES (:pessoa_id, :contrato_id, :credor_id, 'quebrado', 300.00, CURRENT_DATE, 1, 'manual', :payload, :criado_em)");
        $stmt->execute([
            'pessoa_id' => $this->pessoaId,
            'contrato_id' => $contratoId,
            'credor_id' => $credorId,
            'payload' => $payload,
            'criado_em' => $quando,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->acordosCriados[] = $id;
        return $id;
    }

    /** @return array<string,mixed> */
    private function filtrosHoje(?int $credorId = null): array
    {
        $filtros = ['data_inicio' => date('Y-m-d'), 'data_fim' => date('Y-m-d')];
        if ($credorId !== null) {
            $filtros['credor_id'] = (string) $credorId;
        }
        return $filtros;
    }

    public function testFunilContaCadaEtapaCorretamente(): void
    {
        $hoje = date('Y-m-d 12:00:00');

        // 3 atendimentos na mesma pessoa/contrato - ainda 1 "contactado".
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoNaoAtendeId, $hoje);
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoCpcId, $hoje);
        // Canal não-ligação não conta como Alô/CPC mas conta como contactado.
        $this->criarAtendimento($this->contratoId, 'whatsapp', $this->resultadoCpcId, $hoje);
        $this->criarAcordo($this->contratoId, $this->credorId, $hoje);

        $funil = $this->repository->funil($this->filtrosHoje($this->credorId), null);

        self::assertSame(1, $funil['contactados'], 'mesma pessoa em 3 atendimentos conta 1x');
        // 1 ligação com alguém atendendo + o acordo (sem acionamento de acordo no dia, conta ele mesmo).
        self::assertSame(2, $funil['alo']);
        self::assertSame(2, $funil['cpc']);
        self::assertSame(1, $funil['acordos']);
    }

    public function testFiltroDeCredorRestringeOFunil(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoCpcId, $hoje);
        $this->criarAtendimento($this->outroContratoId, 'ligacao', $this->resultadoCpcId, $hoje);
        $this->criarAcordo($this->contratoId, $this->credorId, $hoje);
        $this->criarAcordo($this->outroContratoId, $this->outroCredorId, $hoje);

        $funil = $this->repository->funil($this->filtrosHoje($this->credorId), null);

        self::assertSame(1, $funil['contactados']);
        self::assertSame(2, $funil['cpc'], 'ligação CPC + acordo do credor; nada do outro credor');
        self::assertSame(1, $funil['acordos'], 'acordo do outro credor não pode entrar no filtro');
    }

    public function testForaDoPeriodoNaoEntraNoFunil(): void
    {
        $ontem = date('Y-m-d 12:00:00', strtotime('-1 day'));
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoCpcId, $ontem);
        $this->criarAcordo($this->contratoId, $this->credorId, $ontem);

        $funil = $this->repository->funil($this->filtrosHoje($this->credorId), null);

        self::assertSame(0, $funil['contactados']);
        self::assertSame(0, $funil['acordos']);
    }

    public function testDetalharRetornaOsRegistrosDoEstagio(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $idCpc = $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoCpcId, $hoje);
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoNaoAtendeId, $hoje);

        $detalheCpc = $this->repository->detalhar('cpc', $this->filtrosHoje($this->credorId), null);

        self::assertCount(1, $detalheCpc);
        self::assertSame($idCpc, (int) $detalheCpc[0]['id']);
    }

    public function testDetalharAcordosRetornaOsRegistros(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $idAcordo = $this->criarAcordo($this->contratoId, $this->credorId, $hoje);

        $detalhe = $this->repository->detalhar('acordos', $this->filtrosHoje($this->credorId), null);

        self::assertCount(1, $detalhe);
        self::assertSame($idAcordo, (int) $detalhe[0]['id']);
    }

    /**
     * Achado (2026-09-23): "os acordos do dashboard só deve
     * contabilizar os feitos pelo CRM" - acordo criado/conciliado pela
     * sincronização com a ACME (cron de carência, reconciliação, botão
     * Sincronizar) não é um fechamento real do operador e não pode contar.
     */
    public function testAcordoDeSincronizacaoComAcmeNaoEntraNoFunil(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $idCrm = $this->criarAcordo($this->contratoId, $this->credorId, $hoje);
        $this->criarAcordoOrigemAcme($this->contratoId, $this->credorId, $hoje);

        $funil = $this->repository->funil($this->filtrosHoje($this->credorId), null);
        $detalhe = $this->repository->detalhar('acordos', $this->filtrosHoje($this->credorId), null);

        self::assertSame(1, $funil['acordos'], 'só o acordo feito pelo CRM deve contar, não o de sincronização ACME');
        self::assertCount(1, $detalhe);
        self::assertSame($idCrm, (int) $detalhe[0]['id']);
    }

    /**
     * Requisito (2026-09-25): exceção aprovada que veio por API/carga
     * e teve responsável atribuído em Controle de Acordos passa a contar.
     */
    public function testAcordoExternoComResponsavelAtribuidoEntraNoFunil(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $this->criarAcordo($this->contratoId, $this->credorId, $hoje);
        $this->criarAcordoOrigemAcme($this->contratoId, $this->credorId, $hoje);
        $idAtribuido = $this->criarAcordoOrigemAcme($this->contratoId, $this->credorId, $hoje, ['usuario_id' => 7, 'nome' => 'Bruno Teste']);

        $funil = $this->repository->funil($this->filtrosHoje($this->credorId), null);
        $ids = array_map(static fn (array $l): int => (int) $l['id'], $this->repository->detalhar('acordos', $this->filtrosHoje($this->credorId), null));

        self::assertSame(2, $funil['acordos'], 'acordo do CRM + acordo externo com responsável atribuído; o externo sem responsável não conta');
        self::assertContains($idAtribuido, $ids);
    }

    /** Achado (2026-09-23): "coloca uma visão por lote". */
    public function testFiltroDeLoteRestringeOFunil(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoCpcId, $hoje);
        $this->criarAtendimento($this->contratoLoteBId, 'ligacao', $this->resultadoCpcId, $hoje);
        $this->criarAcordo($this->contratoId, $this->credorId, $hoje);

        $filtros = $this->filtrosHoje($this->credorId);
        $filtros['lote'] = $this->loteA;
        $funil = $this->repository->funil($filtros, null);

        self::assertSame(1, $funil['contactados'], 'só o contato do lote A deve contar');
        self::assertSame(1, $funil['acordos']);
    }

    public function testFunilPorLoteQuebraCadaEtapaPorLote(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoCpcId, $hoje);
        $this->criarAtendimento($this->contratoLoteBId, 'ligacao', $this->resultadoNaoAtendeId, $hoje);
        $this->criarAcordo($this->contratoId, $this->credorId, $hoje);

        $porLote = $this->repository->funilPorLote($this->filtrosHoje($this->credorId), null);
        $porLoteIndexado = [];
        foreach ($porLote as $linha) {
            $porLoteIndexado[$linha['lote']] = $linha;
        }

        self::assertArrayHasKey($this->loteA, $porLoteIndexado);
        self::assertArrayHasKey($this->loteB, $porLoteIndexado);
        self::assertSame(1, $porLoteIndexado[$this->loteA]['contactados']);
        self::assertSame(2, $porLoteIndexado[$this->loteA]['cpc'], 'ligação CPC + acordo sem acionamento de acordo');
        self::assertSame(1, $porLoteIndexado[$this->loteA]['acordos']);
        self::assertSame(1, $porLoteIndexado[$this->loteB]['contactados']);
        self::assertSame(0, $porLoteIndexado[$this->loteB]['cpc']);
        self::assertSame(0, $porLoteIndexado[$this->loteB]['acordos']);
    }

    public function testFunilPorLoteIgnoraFiltroDeLoteJaAplicado(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $this->criarAtendimento($this->contratoId, 'ligacao', $this->resultadoCpcId, $hoje);
        $this->criarAtendimento($this->contratoLoteBId, 'ligacao', $this->resultadoCpcId, $hoje);

        $filtros = $this->filtrosHoje($this->credorId);
        $filtros['lote'] = $this->loteA;
        $porLote = $this->repository->funilPorLote($filtros, null);
        $lotesEncontrados = array_column($porLote, 'lote');

        self::assertContains($this->loteA, $lotesEncontrados);
        self::assertContains($this->loteB, $lotesEncontrados, 'a visão por lote mostra todos os lotes, mesmo com um lote já filtrado');
    }

    /**
     * Achado (2026-10-01): "quando fecha o acordo quer dizer que
     * ele falou com o cliente" - acordo fechado por WhatsApp tem que contar
     * como alô e CPC, e o acordo não conta de novo por cima do acionamento.
     */
    public function testAcionamentoDeAcordoContaComoAloECpcEmQualquerCanalSemDuplicar(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $idAcionamento = $this->criarAtendimento($this->contratoId, 'whatsapp', $this->resultadoAcordoId, $hoje);
        $this->criarAcordo($this->contratoId, $this->credorId, date('Y-m-d 11:59:58'));

        $filtros = $this->filtrosHoje($this->credorId);
        $funil = $this->repository->funil($filtros, null);

        self::assertSame(['contactados' => 1, 'alo' => 1, 'cpc' => 1, 'acordos' => 1], $funil);
        $detalheCpc = $this->repository->detalhar('cpc', $filtros, null);
        self::assertCount(1, $detalheCpc);
        self::assertSame($idAcionamento, (int) $detalheCpc[0]['id']);
    }

    /** Acordo sem nenhum acionamento (ex.: exceção da ACME atribuída a um operador) percorre o funil inteiro sozinho. */
    public function testAcordoSemAcionamentoContaComoContactadoAloECpc(): void
    {
        $hoje = date('Y-m-d 12:00:00');
        $this->criarAcordoOrigemAcme($this->contratoId, $this->credorId, $hoje, ['usuario_id' => 7, 'nome' => 'Bruno Teste']);

        $filtros = $this->filtrosHoje($this->credorId);
        $funil = $this->repository->funil($filtros, null);

        self::assertSame(['contactados' => 1, 'alo' => 1, 'cpc' => 1, 'acordos' => 1], $funil);

        foreach (['contactados', 'alo', 'cpc'] as $estagio) {
            $detalhe = $this->repository->detalhar($estagio, $filtros, null);
            self::assertCount(1, $detalhe, $estagio);
            self::assertSame('acordo', $detalhe[0]['canal']);
            self::assertSame('Bruno Teste', $detalhe[0]['operador_nome']);
        }

        $porLote = array_column($this->repository->funilPorLote($filtros, null), null, 'lote');
        self::assertSame(1, $porLote[$this->loteA]['contactados']);
        self::assertSame(1, $porLote[$this->loteA]['alo']);
        self::assertSame(1, $porLote[$this->loteA]['cpc']);
    }

    /** O acionamento de acordo de outro dia não "cobre" o acordo de hoje. */
    public function testAcionamentoDeAcordoDeOutroDiaNaoCobreOAcordo(): void
    {
        $this->criarAtendimento($this->contratoId, 'whatsapp', $this->resultadoAcordoId, date('Y-m-d 12:00:00', strtotime('-1 day')));
        $acordo = ['id' => 1, 'pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'criado_em' => date('Y-m-d 12:00:00')];

        self::assertCount(1, $this->repository->acordosSemAcionamentoDeAcordo([$acordo]));

        $this->criarAtendimento($this->contratoId, 'sms', $this->resultadoAcordoId, date('Y-m-d 09:00:00'));
        self::assertCount(0, $this->repository->acordosSemAcionamentoDeAcordo([$acordo]));
    }

    public function testListarLotesRetornaOsLotesDoEscopo(): void
    {
        $lotes = $this->repository->listarLotes(null);

        self::assertContains($this->loteA, $lotes);
        self::assertContains($this->loteB, $lotes);
    }
}
