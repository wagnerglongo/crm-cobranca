<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Api\Controllers\ContratosController;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use PHPUnit\Framework\TestCase;

/**
 * ContratosController.php (168 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. Diferente de OperacoesController (que
 * depende do AcmeApiClient real, não testável sem refatoração),
 * ContratosController só depende de AtendimentoAdaptorRepository (já
 * testado) - testável de ponta a ponta de verdade. Foco no isolamento
 * por escopo NA CAMADA DE API (defesa em profundidade: confirma que o
 * controller de fato passa context->escopo pro repositório, não só que
 * o repositório em si respeita escopo quando chamado corretamente).
 */
final class ContratosControllerTest extends TestCase
{
    private PDO $pdo;
    private ContratosController $controller;
    private int $credorAcme;
    private int $credorOutro;
    private int $pessoaId;
    private int $contratoId;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->controller = new ContratosController(new AtendimentoAdaptorRepository($this->pdo));

        $this->credorAcme = $this->credorId('ACME');
        $this->credorOutro = $this->credorId('GLOBEX');

        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Controller Contrato', 'documento' => '778' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO contratos (pessoa_id, credor_id, codigo_contrato) VALUES (:pessoa_id, :credor_id, :codigo)')
            ->execute(['pessoa_id' => $this->pessoaId, 'credor_id' => $this->credorAcme, 'codigo' => 'TESTE-CTRLCONTR-' . bin2hex(random_bytes(4))]);
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
        $this->controller->detalhe($this->requisicao('/contratos/' . $this->contratoId), null, ['id' => (string) $this->contratoId], 'req-1');
    }

    public function testDetalheComEscopoCertoRetornaOContrato(): void
    {
        $resposta = $this->controller->detalhe(
            $this->requisicao('/contratos/' . $this->contratoId),
            $this->contexto($this->credorAcme),
            ['id' => (string) $this->contratoId],
            'req-2'
        );

        self::assertSame(200, $resposta->status());
        self::assertSame($this->contratoId, $resposta->payload()['data']['id']);
    }

    /**
     * Defesa em profundidade: confirma que o CONTROLLER passa o escopo
     * pro repositório - um contrato de outro credor deve dar 404, nunca
     * 200 com dado de outro credor.
     */
    public function testDetalheComEscopoErradoRetorna404(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->detalhe(
                $this->requisicao('/contratos/' . $this->contratoId),
                $this->contexto($this->credorOutro),
                ['id' => (string) $this->contratoId],
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
            $this->controller->detalhe($this->requisicao('/contratos/abc'), $this->contexto($this->credorAcme), ['id' => 'abc'], 'req-4');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            throw $e;
        }
    }

    public function testPesquisarComTermoMuitoCurtoLancaValidationError(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->pesquisar($this->requisicao('/contratos', ['q' => 'a']), $this->contexto($this->credorAcme), [], 'req-5');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            throw $e;
        }
    }

    public function testPesquisarComTipoInvalidoLancaValidationError(): void
    {
        $this->expectException(ApiException::class);
        try {
            $this->controller->pesquisar($this->requisicao('/contratos', ['q' => 'teste', 'tipo' => 'tipo_invalido']), $this->contexto($this->credorAcme), [], 'req-6');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            throw $e;
        }
    }

    public function testPesquisarComEscopoErradoNaoEncontraOContratoSintetico(): void
    {
        $resposta = $this->controller->pesquisar(
            $this->requisicao('/contratos', ['q' => $this->contratoIdComoTermo()]),
            $this->contexto($this->credorOutro),
            [],
            'req-7'
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $resposta->payload()['data']);
        self::assertNotContains($this->contratoId, $ids);
    }

    private function contratoIdComoTermo(): string
    {
        $stmt = $this->pdo->prepare('SELECT codigo_contrato FROM contratos WHERE id = :id');
        $stmt->execute(['id' => $this->contratoId]);
        return (string) $stmt->fetchColumn();
    }
}
