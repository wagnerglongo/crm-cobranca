<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Achado 2026-09-17: os cards de resumo do Controle de Acordos nunca
 * respeitavam nenhum filtro (bug pre-existente, achado ao construir o
 * filtro de fila/responsavel) - resumoGeral() ficou mostrando sempre o
 * total geral. Corrigido extraindo whereRecorteAcordos() compartilhado
 * entre listar() e resumoGeral(). Este teste trava esse comportamento:
 * se algum dia resumoGeral() parar de aplicar o filtro de novo, ele
 * quebra aqui em vez de so ser descoberto manualmente em produção.
 *
 * Tambem cobre o filtro em si, que troca contratos.fila por
 * negotiation_notes retornado pela API da ACME (payload_extra ->
 * acme_agreement_snapshot.negotiation_notes, formato "Responsável: X").
 *
 * Cada acordo usa seu PROPRIO contrato sintetico porque
 * uk_acordos_operacionais_contrato_ativo (regra real de negocio) impede
 * dois acordos "ativos" (pendente/formalizado) pro mesmo contrato.
 */
final class AcordoOperacionalFiltroResponsavelTest extends TestCase
{
    private PDO $pdo;
    private AcordoOperacionalRepository $repository;
    private int $pessoaId;
    private int $credorId;
    private string $prefixoBusca;
    /** @var list<int> */
    private array $contratosCriados = [];
    /** @var list<int> */
    private array $acordosCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AcordoOperacionalRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de acordos operacionais indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = $stmt->fetchColumn();
        self::assertNotFalse($credorId, 'credor ACME não encontrado para o teste');
        $this->credorId = (int) $credorId;

        $documento = '999' . random_int(10000000, 99999999);
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Teste Filtro Responsável', 'documento' => $documento]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        // Sufixo so' com letras (nao digitos) de proposito: um termo de busca
        // com >=3 digitos vaza pro filtro por telefone/documento em
        // whereRecorteAcordos() (extrai digitos do termo via preg_replace) e
        // pode colidir por acaso com o documento sintetico de outro teste -
        // achado real 2026-09-18, mesma classe de bug ja corrigida em
        // ConsultaClienteRepositoryEscopoTest.php.
        $this->prefixoBusca = 'TESTE-RESP-' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 10);
    }

    protected function tearDown(): void
    {
        foreach ($this->acordosCriados as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->contratosCriados as $id) {
            $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $id]);
        }
        if (isset($this->pessoaId)) {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
        }
    }

    private function criarAcordo(string $status, ?string $negotiationNotes): int
    {
        $codigoContrato = $this->prefixoBusca . '-' . (count($this->contratosCriados) + 1);
        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorId, 'codigo' => $codigoContrato]);
        $contratoId = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $contratoId;

        $payloadExtra = $negotiationNotes === null
            ? null
            : json_encode(['acme_agreement_snapshot' => ['negotiation_notes' => $negotiationNotes]], JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare('INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, credor_id, status_operacional, valor_acordo, data_promessa_pagamento, payload_extra, criado_em)
            VALUES (:pessoa_id, :contrato_id, :credor_id, :status, 100.00, CURRENT_DATE, :payload_extra, NOW())')
            ->execute([
                'pessoa_id' => $this->pessoaId,
                'contrato_id' => $contratoId,
                'credor_id' => $this->credorId,
                'status' => $status,
                'payload_extra' => $payloadExtra,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->acordosCriados[] = $id;
        return $id;
    }

    public function testListarFiltraPorGrupoDeResponsavel(): void
    {
        $idVertex = $this->criarAcordo('pendente', 'Responsável: Vertex - VarejoCred 01 - EXTERNO');
        $this->criarAcordo('pendente', 'Responsável: Portal Renegocia - BD-Multiproduto 8');
        $this->criarAcordo('pendente', null);

        $resultado = $this->repository->listar(['status' => 'todos', 'fila' => 'Vertex', 'q' => $this->prefixoBusca], 200, null);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $resultado);

        self::assertSame([$idVertex], $ids, 'só o acordo do grupo Vertex deve aparecer, escopado aos contratos sintéticos do teste');
    }

    public function testResumoGeralRespeitaOFiltroDeResponsavel(): void
    {
        $this->criarAcordo('pendente', 'Responsável: Vertex - VarejoCred 01 - EXTERNO');
        $this->criarAcordo('pendente', 'Responsável: Portal Renegocia - BD-Multiproduto 8');
        $this->criarAcordo('pendente', null);

        $resumoSemFiltro = $this->repository->resumoGeral(['q' => $this->prefixoBusca], null);
        $resumoVertex = $this->repository->resumoGeral(['q' => $this->prefixoBusca, 'fila' => 'Vertex'], null);

        self::assertSame(3, $resumoSemFiltro['total']);
        self::assertSame(1, $resumoVertex['total'], 'resumoGeral() deve respeitar o filtro de responsável, não somar todos os acordos do escopo');
    }

    public function testOutrasAgrupaVazioEValorSemPrefixoConhecido(): void
    {
        $idSemNota = $this->criarAcordo('pendente', null);
        $idOutro = $this->criarAcordo('pendente', 'Responsável: Mesa Interna Qualquer');
        $this->criarAcordo('pendente', 'Responsável: Vertex - VarejoCred 01 - EXTERNO');

        $resultado = $this->repository->listar(['status' => 'todos', 'fila' => 'Outras', 'q' => $this->prefixoBusca], 200, null);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $resultado);

        self::assertSame([$idOutro, $idSemNota], $ids, 'grupo Outras deve conter tanto o sem nota quanto o de prefixo desconhecido, escopado aos contratos do teste (ordem: id DESC)');
    }
}
