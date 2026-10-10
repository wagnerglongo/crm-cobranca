<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use PHPUnit\Framework\TestCase;

/**
 * Contato/endereço alterado pelo operador -> evento na fila pro credor
 * (requisito de 2026-10-06). Passa pelas mesmas funções que as telas
 * (ficha do cliente e Pactua > Localização) chamam. Roda no crm_test, que o
 * worker de integração nem enxerga - nada é enviado.
 */
final class ContatoIntegracaoServiceTest extends TestCase
{
    private PDO $pdo;
    private int $credorId;
    private int $integracaoId;
    private int $pessoaId;
    private int $contratoId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $sufixo = strtoupper(bin2hex(random_bytes(4)));

        $this->pdo->prepare('INSERT INTO credores (codigo, nome, ativo) VALUES (:codigo, :nome, 1)')
            ->execute(['codigo' => 'CONT_' . $sufixo, 'nome' => 'Credor Teste Contatos ' . $sufixo]);
        $this->credorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO credor_integracoes (credor_id, codigo, nome, evento_tipo, tipo_transporte, adapter, ativo)
            VALUES (:credor, 'CONTATOS', 'Teste - Contatos', 'contato.sincronizar', 'api', 'ACME_CONTATOS', 1)")
            ->execute(['credor' => $this->credorId]);
        $this->integracaoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Contatos', 'documento' => '998' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa, :credor, :codigo)')
            ->execute(['pessoa' => $this->pessoaId, 'credor' => $this->credorId, 'codigo' => 'TESTE-CONT-' . $sufixo]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM integracao_eventos_saida WHERE integracao_id = :id')->execute(['id' => $this->integracaoId]);
        $this->pdo->prepare('DELETE FROM credor_integracoes WHERE id = :id')->execute(['id' => $this->integracaoId]);
        foreach (['pessoa_contatos', 'pessoa_enderecos', 'contratos'] as $tabela) {
            $this->pdo->prepare("DELETE FROM $tabela WHERE pessoa_id = :id")->execute(['id' => $this->pessoaId]);
        }
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
        $this->pdo->prepare('DELETE FROM credores WHERE id = :id')->execute(['id' => $this->credorId]);
    }

    /** @return list<array<string,mixed>> eventos da integração de teste, com payload decodificado */
    private function eventos(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM integracao_eventos_saida WHERE integracao_id = :id ORDER BY id');
        $stmt->execute(['id' => $this->integracaoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['p'] = json_decode((string) $row['payload'], true);
        }
        return $rows;
    }

    /** Flags de alteracoes em ordem fixa (o MySQL reordena as chaves do JSON). @return array<string,bool> */
    private function flags(array $evento): array
    {
        $alteracoes = $evento['p']['alteracoes'];
        return ['status' => $alteracoes['status'], 'tipo' => $alteracoes['tipo'], 'criado' => $alteracoes['criado']];
    }

    /** Contato como a carga/localização da ACME grava (não é do operador). */
    private function contatoVindoDaAcme(string $numero, string $status = 'desconhecido'): int
    {
        $this->pdo->prepare("INSERT INTO pessoa_contatos (pessoa_id, tipo, valor, valor_normalizado, ddd, telefone, status, principal, origem)
            VALUES (:pessoa, 'telefone', :valor, :normalizado, :ddd, :telefone, :status, 0, 'ACME')")
            ->execute(['pessoa' => $this->pessoaId, 'valor' => $numero, 'normalizado' => $numero, 'telefone' => $numero, 'ddd' => substr($numero, 0, 2), 'status' => $status]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testTelefoneNovoDoOperadorVaiInteiroPraInclusao(): void
    {
        $id = (new ConsultaClienteRepository($this->pdo))->adicionarContatoManual($this->pessoaId, [
            'tipo' => 'whatsapp', 'valor' => '(11) 98765-4321', 'status' => 'novo',
        ]);

        $eventos = $this->eventos();
        self::assertCount(1, $eventos);
        self::assertSame('contato.sincronizar', $eventos[0]['evento_tipo']);
        self::assertSame('pessoa_contatos', $eventos[0]['origem_tabela']);
        self::assertSame($id, (int) $eventos[0]['origem_id']);
        self::assertSame($this->contratoId, (int) $eventos[0]['contrato_id']);
        self::assertSame('pendente', $eventos[0]['status']);
        $c = $eventos[0]['p']['contato'];
        self::assertSame(['telefone', '11', '987654321', 15, 'P', true], [$c['entidade'], $c['ddd'], $c['numero'], $c['tipo_inclusao'], $c['status_inclusao'], $c['permitir_inclusao']]);
        self::assertTrue($eventos[0]['p']['alteracoes']['criado']);
    }

    public function testNegativarNoWorksUmNumeroQueVeioDaAcmeMandaSoOStatus(): void
    {
        $id = $this->contatoVindoDaAcme('11987654321');

        (new AtendimentoAdaptorRepository($this->pdo))->atualizarContatoWorks($this->pessoaId, $id, ['situacao' => 'negativado', 'principal' => '0']);

        $eventos = $this->eventos();
        self::assertCount(1, $eventos);
        $p = $eventos[0]['p'];
        self::assertSame('I', $p['contato']['status_acme']);
        self::assertFalse($p['contato']['permitir_inclusao'], 'número que veio da ACME nunca é incluído de volta');
        self::assertSame(['status' => true, 'tipo' => false, 'criado' => false], $this->flags($eventos[0]));
    }

    public function testMarcarSoComoPrincipalNaoEnviaNada(): void
    {
        $id = $this->contatoVindoDaAcme('11987654321');

        (new AtendimentoAdaptorRepository($this->pdo))->atualizarContatoWorks($this->pessoaId, $id, ['principal' => '1']);

        self::assertSame([], $this->eventos(), 'sem mudança de status/tipo não sobrescreve a ACME com dado velho do CRM');
    }

    public function testDuasMudancasAntesDoEnvioViramUmEventoComAsDuas(): void
    {
        $id = $this->contatoVindoDaAcme('11987654321');
        $repo = new AtendimentoAdaptorRepository($this->pdo);

        $repo->atualizarContatoWorks($this->pessoaId, $id, ['subtipo' => 'celular', 'principal' => '0']);
        $repo->atualizarContatoWorks($this->pessoaId, $id, ['subtipo' => 'celular', 'situacao' => 'validado', 'principal' => '0']);

        $eventos = $this->eventos();
        self::assertCount(1, $eventos);
        self::assertSame(['status' => true, 'tipo' => true, 'criado' => false], $this->flags($eventos[0]));
        self::assertSame(4, $eventos[0]['p']['contato']['tipo_acme']);
        self::assertSame('O', $eventos[0]['p']['contato']['status_acme']);
    }

    public function testNovaMudancaDepoisDeEnviadoReabreOEvento(): void
    {
        $id = $this->contatoVindoDaAcme('11987654321');
        $repo = new AtendimentoAdaptorRepository($this->pdo);
        $repo->atualizarContatoWorks($this->pessoaId, $id, ['situacao' => 'negativado', 'principal' => '0']);
        $this->pdo->prepare("UPDATE integracao_eventos_saida SET status = 'enviado', tentativas = 1, ultimo_erro = 'x' WHERE integracao_id = :id")
            ->execute(['id' => $this->integracaoId]);

        $repo->atualizarContatoWorks($this->pessoaId, $id, ['situacao' => 'validado', 'principal' => '0']);

        $eventos = $this->eventos();
        self::assertCount(1, $eventos);
        self::assertSame(['pendente', 0, null], [$eventos[0]['status'], (int) $eventos[0]['tentativas'], $eventos[0]['ultimo_erro']]);
        self::assertSame('O', $eventos[0]['p']['contato']['status_acme']);
        self::assertSame(['status' => true, 'tipo' => false, 'criado' => false], $this->flags($eventos[0]), 'evento já enviado não herda flags antigas');
    }

    public function testSemContratoComOCredorOuIntegracaoDesligadaNaoEnfileira(): void
    {
        $this->pdo->prepare('UPDATE credor_integracoes SET ativo = 0 WHERE id = :id')->execute(['id' => $this->integracaoId]);
        (new ConsultaClienteRepository($this->pdo))->adicionarContatoManual($this->pessoaId, ['tipo' => 'telefone', 'valor' => '11987654321', 'status' => 'novo']);
        self::assertSame([], $this->eventos());

        $this->pdo->prepare('UPDATE credor_integracoes SET ativo = 1 WHERE id = :id')->execute(['id' => $this->integracaoId]);
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        (new ConsultaClienteRepository($this->pdo))->adicionarContatoManual($this->pessoaId, ['tipo' => 'telefone', 'valor' => '11912345678', 'status' => 'novo']);
        self::assertSame([], $this->eventos());
    }

    public function testTelefoneSemDddNaoEnfileira(): void
    {
        (new ConsultaClienteRepository($this->pdo))->adicionarContatoManual($this->pessoaId, ['tipo' => 'telefone', 'valor' => '987654321', 'status' => 'novo']);
        self::assertSame([], $this->eventos());
    }

    public function testEnderecoNovoEMudancaDeTipo(): void
    {
        $repo = new AtendimentoAdaptorRepository($this->pdo);
        $id = $repo->adicionarEnderecoManual($this->pessoaId, [
            'tipo' => 'residencial', 'cep' => '01001-000', 'uf' => 'sp', 'cidade' => 'São Paulo',
            'bairro' => 'Sé', 'logradouro' => 'Praça da Sé', 'numero' => '100A', 'complemento' => 'Apto 1',
        ]);

        $eventos = $this->eventos();
        self::assertCount(1, $eventos);
        $c = $eventos[0]['p']['contato'];
        self::assertSame(['endereco', '01001000', 100, 'Apto 1 - Nº 100A', 'SP', 'R', true], [$c['entidade'], $c['cep'], $c['numero'], $c['complemento'], $c['uf'], $c['tipo_inclusao'], $c['permitir_inclusao']]);

        $this->pdo->prepare("UPDATE integracao_eventos_saida SET status = 'enviado' WHERE integracao_id = :id")->execute(['id' => $this->integracaoId]);
        $repo->atualizarEnderecoManual($this->pessoaId, $id, ['tipo' => 'cobranca', 'principal' => '0']);
        $eventos = $this->eventos();
        self::assertSame('pendente', $eventos[0]['status']);
        self::assertSame('E', $eventos[0]['p']['contato']['tipo_acme']);
        self::assertSame(['status' => false, 'tipo' => true, 'criado' => false], $this->flags($eventos[0]));
    }

    public function testFichaDoClienteMudandoStatusDeContatoExistente(): void
    {
        $id = $this->contatoVindoDaAcme('11987654321', 'desconhecido');

        (new ConsultaClienteRepository($this->pdo))->atualizarContatoManual($this->pessoaId, $id, ['status' => 'hot']);

        $eventos = $this->eventos();
        self::assertCount(1, $eventos);
        self::assertSame('H', $eventos[0]['p']['contato']['status_acme']);
    }
}
