<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Api\Controllers\ClientesController;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
use PHPUnit\Framework\TestCase;

/**
 * ClientesController.php (241 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18, mesmo padrão de ContratosControllerTest.php.
 * Depende só de ConsultaClienteRepository + AtendimentoAdaptorRepository
 * (ambos já testados), sem AcmeApiClient - testável de ponta a ponta.
 * Foco de novo em defesa em profundidade: garantirPessoaNoEscopo() usa o
 * MESMO 404 pra "não existe" e "existe mas fora do escopo" de propósito
 * (não revelar cadastro pra quem não tem acesso) - o teste confirma que
 * o controller de fato aplica isso, não só o repositório.
 */
final class ClientesControllerTest extends TestCase
{
    private PDO $pdo;
    private ClientesController $controller;
    private int $credorAcme;
    private int $credorOutro;
    private int $pessoaId;
    private int $contratoId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->controller = new ClientesController(
            new ConsultaClienteRepository($this->pdo),
            new AtendimentoAdaptorRepository($this->pdo)
        );

        $this->credorAcme = $this->credorId('ACME');
        $this->credorOutro = $this->credorId('GLOBEX');

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Controller Cliente', 'documento' => '779' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorAcme, 'codigo' => 'TESTE-CTRLCLI-' . bin2hex(random_bytes(4))]);
        $this->contratoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute(['id' => $this->contratoId]);
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    private function credorId(string $codigo): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM credores WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => $codigo]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "credor {$codigo} não encontrado para o teste");
        return (int) $id;
    }

    private function contexto(int $credorId): AuthContext
    {
        $escopo = ['todos' => false, 'credor_ids' => [$credorId], 'carteira_ids' => []];
        return new AuthContext(1, null, 'usuario', ['id' => 1], ['*'], $escopo, ['api']);
    }

    private function requisicao(string $path, array $query = []): ApiRequest
    {
        return new ApiRequest('GET', $path, $query, [], '', '127.0.0.1');
    }

    public function testDetalheSemAutenticacaoLancaExcecao(): void
    {
        $this->expectException(ApiException::class);
        $this->controller->detalhe($this->requisicao('/clientes/' . $this->pessoaId), null, ['id' => (string) $this->pessoaId], 'req-1');
    }

    public function testDetalheComEscopoCertoRetornaOCliente(): void
    {
        $resposta = $this->controller->detalhe(
            $this->requisicao('/clientes/' . $this->pessoaId),
            $this->contexto($this->credorAcme),
            ['id' => (string) $this->pessoaId],
            'req-2'
        );

        self::assertSame(200, $resposta->status());
        self::assertSame($this->pessoaId, $resposta->payload()['data']['cliente']['id']);
    }

    /**
     * Defesa em profundidade: um cliente cujo único contrato pertence a
     * outro credor deve dar 404 ao ser acessado por quem só tem escopo
     * no credor errado - garantirPessoaNoEscopo() usa o mesmo 404 pra
     * "não existe" e "fora do escopo" de propósito.
     */
    public function testDetalheComEscopoErradoRetorna404(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->detalhe(
                $this->requisicao('/clientes/' . $this->pessoaId),
                $this->contexto($this->credorOutro),
                ['id' => (string) $this->pessoaId],
                'req-3'
            );
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
            throw $e;
        }
    }

    public function testDetalheComIdInvalidoLancaValidationError(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->detalhe($this->requisicao('/clientes/abc'), $this->contexto($this->credorAcme), ['id' => 'abc'], 'req-4');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            throw $e;
        }
    }

    public function testPesquisarComTermoMuitoCurtoLancaValidationError(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->pesquisar($this->requisicao('/clientes', ['q' => 'a']), $this->contexto($this->credorAcme), [], 'req-5');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            throw $e;
        }
    }

    public function testPesquisarComEscopoErradoNaoEncontraOClienteSintetico(): void
    {
        $resposta = $this->controller->pesquisar(
            $this->requisicao('/clientes', ['q' => $this->documentoComoTermo()]),
            $this->contexto($this->credorOutro),
            [],
            'req-6'
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $resposta->payload()['data']);
        self::assertNotContains($this->pessoaId, $ids);
    }

    public function testContratosComEscopoCertoRetornaOContrato(): void
    {
        $resposta = $this->controller->contratos(
            $this->requisicao('/clientes/' . $this->pessoaId . '/contratos'),
            $this->contexto($this->credorAcme),
            ['id' => (string) $this->pessoaId],
            'req-7'
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $resposta->payload()['data']);
        self::assertContains($this->contratoId, $ids);
    }

    public function testContratosComEscopoErradoLanca404(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->contratos(
                $this->requisicao('/clientes/' . $this->pessoaId . '/contratos'),
                $this->contexto($this->credorOutro),
                ['id' => (string) $this->pessoaId],
                'req-8'
            );
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
            throw $e;
        }
    }

    public function testContatosComPessoaInexistenteLanca404(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->contatos(
                $this->requisicao('/clientes/999999999/contatos'),
                $this->contexto($this->credorAcme),
                ['id' => '999999999'],
                'req-9'
            );
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
            throw $e;
        }
    }

    public function testPorDocumentoComDocumentoInvalidoLancaValidationError(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->porDocumento($this->requisicao('/clientes/documento/123'), $this->contexto($this->credorAcme), ['documento' => '123'], 'req-10');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            throw $e;
        }
    }

    public function testPorDocumentoComDocumentoInexistenteLanca404(): void
    {
        $documento = '77712345678';
        $this->expectException(ApiException::class);
        try {
            $this->controller->porDocumento($this->requisicao('/clientes/documento/' . $documento), $this->contexto($this->credorAcme), ['documento' => $documento], 'req-11');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
            throw $e;
        }
    }

    private function documentoComoTermo(): string
    {
        $stmt = $this->pdo->prepare('SELECT documento FROM pessoas WHERE id = :id');
        $stmt->execute(['id' => $this->pessoaId]);
        return (string) $stmt->fetchColumn();
    }
}
