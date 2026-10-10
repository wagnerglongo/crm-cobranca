<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use PHPUnit\Framework\TestCase;

/**
 * AtendimentoRepository.php (1.158 linhas) - continuação da auditoria de
 * cobertura de 2026-09-17. registrar() é o caminho de escrita usado em
 * TODO acionamento registrado manualmente no CRM - tem 2 guards
 * anti-cross-tenant (contrato e contato precisam pertencer à MESMA
 * pessoa do atendimento) que, se quebrarem, permitiriam registrar um
 * atendimento associando o contrato/contato de OUTRA pessoa.
 */
final class AtendimentoRepositoryRegistrarTest extends TestCase
{
    private PDO $pdo;
    private AtendimentoRepository $repository;
    private int $pessoaId;
    private int $contratoId;
    private int $resultadoId;
    private int $resultadoCpcId;
    private int $resultadoCpcComSucessoId;
    private int $resultadoReagendaId;
    private int $motivoInadimplenciaId;
    /** @var list<int> */
    private array $atendimentosCriados = [];
    /** @var list<int> */
    private array $contratosCriados = [];
    /** @var list<int> */
    private array $pessoasCriadas = [];
    /** @var list<int> */
    private array $resultadosCriados = [];
    /** @var list<int> */
    private array $motivosCriados = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AtendimentoRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de atendimentos indisponível nesta instância.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => 'ACME']);
        $credorId = (int) $stmt->fetchColumn();

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Atendimento', 'documento' => '444' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $this->pessoaId;

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $credorId, 'codigo' => 'TESTE-AT-' . $sufixo]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
        $this->contratosCriados[] = $this->contratoId;

        if ($this->repository->resultadoPadronizadoDisponivel()) {
            $this->pdo->prepare("INSERT INTO atendimento_resultados (codigo, nome, categoria, resultado_legacy, ativo) VALUES (:codigo, :nome, 'teste', 'atendeu', 1)")
                ->execute(['codigo' => 'teste_' . $sufixo, 'nome' => 'Resultado Teste ' . $sufixo]);
            $this->resultadoId = (int) $this->pdo->lastInsertId();
            $this->resultadosCriados[] = $this->resultadoId;

            $this->pdo->prepare("INSERT INTO atendimento_resultados (codigo, nome, categoria, resultado_legacy, considera_contato_efetivo, ativo) VALUES (:codigo, :nome, 'teste', 'em_negociacao', 1, 1)")
                ->execute(['codigo' => 'teste_cpc_' . $sufixo, 'nome' => 'Resultado CPC Teste ' . $sufixo]);
            $this->resultadoCpcId = (int) $this->pdo->lastInsertId();
            $this->resultadosCriados[] = $this->resultadoCpcId;

            $this->pdo->prepare("INSERT INTO atendimento_resultados (codigo, nome, categoria, resultado_legacy, considera_contato_efetivo, considera_sucesso, ativo) VALUES (:codigo, :nome, 'teste', 'promessa_pagamento', 1, 1, 1)")
                ->execute(['codigo' => 'teste_cpc_sucesso_' . $sufixo, 'nome' => 'Resultado CPC Sucesso Teste ' . $sufixo]);
            $this->resultadoCpcComSucessoId = (int) $this->pdo->lastInsertId();
            $this->resultadosCriados[] = $this->resultadoCpcComSucessoId;

            $this->pdo->prepare("INSERT INTO atendimento_resultados (codigo, nome, categoria, resultado_legacy, gera_reagendamento, ativo) VALUES (:codigo, :nome, 'teste', 'outro', 1, 1)")
                ->execute(['codigo' => 'teste_reagenda_' . $sufixo, 'nome' => 'Resultado Reagenda Teste ' . $sufixo]);
            $this->resultadoReagendaId = (int) $this->pdo->lastInsertId();
            $this->resultadosCriados[] = $this->resultadoReagendaId;
        }

        if ($this->repository->motivoInadimplenciaDisponivel()) {
            $this->pdo->prepare('INSERT INTO motivos_inadimplencia (nome) VALUES (:nome)')
                ->execute(['nome' => 'Motivo Teste ' . $sufixo]);
            $this->motivoInadimplenciaId = (int) $this->pdo->lastInsertId();
            $this->motivosCriados[] = $this->motivoInadimplenciaId;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->atendimentosCriados as $id) {
            $this->pdo->prepare('DELETE FROM atendimentos_historico WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->resultadosCriados as $id) {
            $this->pdo->prepare('DELETE FROM atendimento_resultados WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->motivosCriados as $id) {
            $this->pdo->prepare('DELETE FROM motivos_inadimplencia WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->contratosCriados as $id) {
            $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->pessoasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $id]);
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function dadosValidos(array $overrides = []): array
    {
        return array_merge([
            'canal' => 'ligacao',
            'resultado_id' => $this->resultadoId,
            'resultado' => 'atendeu',
            'contrato_id' => $this->contratoId,
            'contato_utilizado' => '11999998888',
            'observacao' => 'Atendimento de teste automatizado.',
        ], $overrides);
    }

    public function testRegistrarComDadosValidosFunciona(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos());
        $this->atendimentosCriados[] = $id;

        self::assertGreaterThan(0, $id);
    }

    /**
     * Regra de negócio (2026-09-23): contato usado é obrigatório em todo
     * acionamento - observação preenchida sozinha não basta mais (antes
     * bastava, e por isso acionamento sem telefone registrado passava reto).
     */
    public function testRegistrarSemContatoLancaExcecaoMesmoComObservacao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Informe o contato utilizado no acionamento.');
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['contato_utilizado' => null]));
        $this->atendimentosCriados[] = $id;
    }

    /**
     * Regra de negócio (2026-09-23): contato negativado não pode ser usado
     * pra acionamento (nem, por extensão, pra fechar acordo, que registra
     * um acionamento de promessa de pagamento usando o mesmo método).
     */
    public function testRegistrarComContatoNegativadoLancaExcecao(): void
    {
        $payload = json_encode(['situacao_validacao' => 'negativado'], JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare("INSERT INTO pessoa_contatos (pessoa_id, tipo, valor, valor_normalizado, payload_extra) VALUES (:pessoa_id, 'telefone', '11988887777', '11988887777', :payload)")
            ->execute(['pessoa_id' => $this->pessoaId, 'payload' => $payload]);
        $contatoNegativadoId = (int) $this->pdo->lastInsertId();

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Este contato está negativado e não pode ser usado para acionamento.');
            $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['contato_utilizado' => null, 'contato_id' => $contatoNegativadoId]));
            $this->atendimentosCriados[] = $id;
        } finally {
            $this->pdo->prepare('DELETE FROM pessoa_contatos WHERE id = :id')->execute(['id' => $contatoNegativadoId]);
        }
    }

    public function testRegistrarComContratoDeOutraPessoaLancaExcecao(): void
    {
        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Outra Pessoa', 'documento' => '333' . random_int(10000000, 99999999)]);
        $outraPessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $outraPessoaId;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contrato informado não pertence a este cliente.');
        $id = $this->repository->registrar($outraPessoaId, $this->dadosValidos());
        $this->atendimentosCriados[] = $id;
    }

    public function testRegistrarComContatoDeOutraPessoaLancaExcecao(): void
    {
        $this->pdo->prepare("INSERT INTO pessoa_contatos (pessoa_id, tipo, valor, valor_normalizado) VALUES (:pessoa_id, 'telefone', '11999990000', '11999990000')")
            ->execute(['pessoa_id' => $this->pessoaId]);
        $contatoIdDaPropriaPessoa = (int) $this->pdo->lastInsertId();

        $sufixo = bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Outra Pessoa 2', 'documento' => '222' . random_int(10000000, 99999999)]);
        $outraPessoaId = (int) $this->pdo->lastInsertId();
        $this->pessoasCriadas[] = $outraPessoaId;

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Contato informado não pertence a este cliente.');
            $id = $this->repository->registrar($outraPessoaId, $this->dadosValidos(['contrato_id' => null, 'contato_id' => $contatoIdDaPropriaPessoa]));
            $this->atendimentosCriados[] = $id;
        } finally {
            $this->pdo->prepare('DELETE FROM pessoa_contatos WHERE id = :id')->execute(['id' => $contatoIdDaPropriaPessoa]);
        }
    }

    /**
     * Regra de negócio (2026-09-23): quando o acionamento é CPC (contato com
     * a pessoa certa), o motivo da inadimplência passa a ser obrigatório -
     * ele teve contato de verdade, então precisa dizer por que não fechou.
     */
    public function testRegistrarCpcSemMotivoLancaExcecao(): void
    {
        if (!$this->repository->motivoInadimplenciaDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Como houve contato com a pessoa certa (CPC) sem sucesso e sem acordo ativo, informe o motivo da inadimplência.');
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['resultado_id' => $this->resultadoCpcId]));
        $this->atendimentosCriados[] = $id;
    }

    public function testRegistrarCpcComMotivoFunciona(): void
    {
        if (!$this->repository->motivoInadimplenciaDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }

        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'resultado_id' => $this->resultadoCpcId,
            'motivo_inadimplencia_id' => $this->motivoInadimplenciaId,
        ]));
        $this->atendimentosCriados[] = $id;

        $salvo = (int) $this->pdo->query("SELECT motivo_inadimplencia_id FROM atendimentos_historico WHERE id = {$id}")->fetchColumn();
        self::assertSame($this->motivoInadimplenciaId, $salvo);
    }

    public function testRegistrarCpcComMotivoInvalidoLancaExcecao(): void
    {
        if (!$this->repository->motivoInadimplenciaDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Motivo de inadimplência inválido ou inativo.');
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'resultado_id' => $this->resultadoCpcId,
            'motivo_inadimplencia_id' => 999999999,
        ]));
        $this->atendimentosCriados[] = $id;
    }

    public function testRegistrarNaoCpcIgnoraMotivoInformadoPorEngano(): void
    {
        if (!$this->repository->motivoInadimplenciaDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }

        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'resultado_id' => $this->resultadoId,
            'motivo_inadimplencia_id' => $this->motivoInadimplenciaId,
        ]));
        $this->atendimentosCriados[] = $id;

        $salvo = $this->pdo->query("SELECT motivo_inadimplencia_id FROM atendimentos_historico WHERE id = {$id}")->fetchColumn();
        self::assertNull($salvo, 'motivo só faz sentido pra CPC - fora disso é descartado mesmo se vier no POST');
    }

    /**
     * Ajuste (2026-09-23): "a questão de pedir motivo só deve ter
     * onde tem contato efetivo e não tem sucesso" - CPC com sucesso (ex.:
     * acordo fechado, pagamento efetuado) não precisa de motivo, já fechou.
     */
    public function testRegistrarCpcComSucessoNaoExigeMotivo(): void
    {
        if (!$this->repository->motivoInadimplenciaDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }

        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'resultado_id' => $this->resultadoCpcComSucessoId,
            'motivo_inadimplencia_id' => null,
        ]));
        $this->atendimentosCriados[] = $id;

        self::assertGreaterThan(0, $id);
    }

    public function testRegistrarCpcComSucessoDescartaMotivoInformadoPorEngano(): void
    {
        if (!$this->repository->motivoInadimplenciaDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }

        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'resultado_id' => $this->resultadoCpcComSucessoId,
            'motivo_inadimplencia_id' => $this->motivoInadimplenciaId,
        ]));
        $this->atendimentosCriados[] = $id;

        $salvo = $this->pdo->query("SELECT motivo_inadimplencia_id FROM atendimentos_historico WHERE id = {$id}")->fetchColumn();
        self::assertNull($salvo);
    }

    /**
     * Ajuste (2026-09-23, segunda rodada): "só dá o motivo se não
     * tem promessa ou acordo ativo ok se tem acordo não tem motivo" - CPC
     * sem sucesso, mas com acordo/promessa já em pé pra este contrato, não
     * precisa perguntar o motivo (o "por que não fechou" não se aplica).
     */
    public function testRegistrarCpcSemSucessoComAcordoAtivoNaoExigeMotivo(): void
    {
        if (!$this->repository->motivoInadimplenciaDisponivel()) {
            self::markTestSkipped('Módulo de motivos de inadimplência indisponível (migration 095 não aplicada).');
        }

        $this->pdo->prepare("INSERT INTO acordos_operacionais
                (pessoa_id, contrato_id, status_operacional, valor_acordo, data_promessa_pagamento, quantidade_parcelas, origem, criado_em)
            VALUES (:pessoa_id, :contrato_id, 'formalizado', 300.00, CURRENT_DATE, 1, 'manual', NOW())")
            ->execute(['pessoa_id' => $this->pessoaId, 'contrato_id' => $this->contratoId]);
        $acordoId = (int) $this->pdo->lastInsertId();

        try {
            $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
                'resultado_id' => $this->resultadoCpcId,
                'motivo_inadimplencia_id' => null,
            ]));
            $this->atendimentosCriados[] = $id;

            self::assertGreaterThan(0, $id, 'com acordo ativo pro contrato, motivo não é exigido mesmo em CPC sem sucesso');
        } finally {
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $acordoId]);
        }
    }

    /**
     * Achado 2026-09-28: um acionamento tabulado como "Recado - Terceiros"
     * (ActionID ACME 4, gera_reagendamento=1) sem próximo acionamento
     * preenchido nunca sincronizava com a ACME - a API rejeita (500) com
     * "Para Recado deve informar Data e Hora!", esgotando as 8 tentativas
     * sem nunca sincronizar. Passa a ser exigido no próprio registrar().
     */
    public function testRegistrarComTabulacaoDeReagendamentoSemDataLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Esta tabulação exige reagendamento: informe a data e hora do próximo acionamento.');
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'resultado_id' => $this->resultadoReagendaId,
            'proximo_acionamento' => null,
        ]));
        $this->atendimentosCriados[] = $id;
    }

    public function testRegistrarComTabulacaoDeReagendamentoComDataFunciona(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'resultado_id' => $this->resultadoReagendaId,
            'proximo_acionamento' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ]));
        $this->atendimentosCriados[] = $id;

        self::assertGreaterThan(0, $id);
    }

    public function testRegistrarSemTabulacaoDeReagendamentoNaoExigeData(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['proximo_acionamento' => null]));
        $this->atendimentosCriados[] = $id;

        self::assertGreaterThan(0, $id);
    }

    /**
     * Achado 2026-09-28: a ficha do cliente tem um campo "Contato manual"
     * além da lista (a lista já esconde os negativados) - digitando o
     * mesmo número à mão o bloqueio de negativado não rodava, porque só
     * considerava contato_id.
     */
    public function testRegistrarComContatoManualIgualAUmNegativadoLancaExcecao(): void
    {
        $payload = json_encode(['situacao_validacao' => 'negativado'], JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare("INSERT INTO pessoa_contatos (pessoa_id, tipo, valor, valor_normalizado, payload_extra) VALUES (:pessoa_id, 'telefone', '(11) 97777-6666', '11977776666', :payload)")
            ->execute(['pessoa_id' => $this->pessoaId, 'payload' => $payload]);
        $contatoNegativadoId = (int) $this->pdo->lastInsertId();

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Este contato está negativado e não pode ser usado para acionamento.');
            $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['contato_utilizado' => '11977776666']));
            $this->atendimentosCriados[] = $id;
        } finally {
            $this->pdo->prepare('DELETE FROM pessoa_contatos WHERE id = :id')->execute(['id' => $contatoNegativadoId]);
        }
    }

    public function testRegistrarComContatoManualDiferenteDeNegativadoFunciona(): void
    {
        $payload = json_encode(['situacao_validacao' => 'negativado'], JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare("INSERT INTO pessoa_contatos (pessoa_id, tipo, valor, valor_normalizado, payload_extra) VALUES (:pessoa_id, 'telefone', '11977776666', '11977776666', :payload)")
            ->execute(['pessoa_id' => $this->pessoaId, 'payload' => $payload]);
        $contatoNegativadoId = (int) $this->pdo->lastInsertId();

        try {
            $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos(['contato_utilizado' => '11955554444']));
            $this->atendimentosCriados[] = $id;

            self::assertGreaterThan(0, $id, 'contato manual diferente do negativado cadastrado não deve ser bloqueado');
        } finally {
            $this->pdo->prepare('DELETE FROM pessoa_contatos WHERE id = :id')->execute(['id' => $contatoNegativadoId]);
        }
    }
}
