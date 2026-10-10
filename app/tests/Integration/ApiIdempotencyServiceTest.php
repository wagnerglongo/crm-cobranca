<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Modules\Api\Auth\AuthContext;
use CRM\Modules\Api\Idempotency\ApiIdempotencyService;
use PHPUnit\Framework\TestCase;

/**
 * ApiIdempotencyService estava implementado mas nunca era chamado de
 * lugar nenhum (achado da auditoria de 2026-09-15/16) - ate 2026-09-16
 * nunca tinha rodado uma vez de verdade. Agora esta ligado em
 * ApiApplication::run() para as rotas marcadas 'idempotent' (criar
 * acionamento, fechar acordo ACME). Este teste cobre o servico em si,
 * contra o banco real, sem precisar de um token HTTP autenticado.
 */
final class ApiIdempotencyServiceTest extends TestCase
{
    private int $usuarioId;

    protected function setUp(): void
    {
        // api_idempotencias.usuario_id tem FK pra usuarios(id) - precisa
        // ser um usuario real que exista, senao o INSERT falha por
        // violacao de FK (SQLSTATE 23000, o MESMO codigo de chave
        // duplicada), e o servico interpreta erradamente como "outra
        // chamada ja esta usando esta chave".
        $id = Database::pdo()->query('SELECT id FROM usuarios ORDER BY id ASC LIMIT 1')->fetchColumn();
        self::assertNotFalse($id, 'nenhum usuario encontrado para rodar o teste');
        $this->usuarioId = (int) $id;
    }

    protected function tearDown(): void
    {
        Database::pdo()->prepare('DELETE FROM api_idempotencias WHERE usuario_id = :id AND rota = :rota')
            ->execute(['id' => $this->usuarioId, 'rota' => '/api/v1/acionamentos/lote']);
    }

    private function contexto(): AuthContext
    {
        return new AuthContext(
            $this->usuarioId,
            null,
            'access',
            ['id' => $this->usuarioId],
            [],
            ['todos' => true, 'credor_ids' => [], 'carteira_ids' => []],
            ['api'],
        );
    }

    private function requisicao(string $idempotencyKey, string $corpo): ApiRequest
    {
        return new ApiRequest('POST', '/api/v1/acionamentos/lote', [], ['Idempotency-Key' => $idempotencyKey], $corpo, '127.0.0.1');
    }

    public function testSegundaChamadaComMesmaChaveECorpoReproduzARespostaOriginal(): void
    {
        $service = new ApiIdempotencyService(Database::pdo());
        $contexto = $this->contexto();
        $corpo = json_encode(['pessoa_id' => 123], JSON_THROW_ON_ERROR);

        $primeira = $service->iniciar($contexto, $this->requisicao('teste-idem-replay', $corpo), '/api/v1/acionamentos/lote');
        self::assertNull($primeira['replay'], 'primeira chamada nao deveria ter replay');

        $service->concluir($primeira['id'], ApiResponse::json(['ok' => true, 'acionamento_id' => 777], 201));

        $segunda = $service->iniciar($contexto, $this->requisicao('teste-idem-replay', $corpo), '/api/v1/acionamentos/lote');
        self::assertNotNull($segunda['replay'], 'segunda chamada com a mesma chave+corpo deveria reproduzir a resposta');
        self::assertSame(201, $segunda['replay']->status());
        self::assertSame(['ok' => true, 'acionamento_id' => 777], $segunda['replay']->payload());
    }

    public function testMesmaChaveComCorpoDiferenteLancaExcecaoDeReuso(): void
    {
        $service = new ApiIdempotencyService(Database::pdo());
        $contexto = $this->contexto();

        $service->iniciar($contexto, $this->requisicao('teste-idem-reuso', json_encode(['pessoa_id' => 123], JSON_THROW_ON_ERROR)), '/api/v1/acionamentos/lote');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('mesma chave de idempotência foi usada com outro conteúdo');
        $service->iniciar($contexto, $this->requisicao('teste-idem-reuso', json_encode(['pessoa_id' => 999], JSON_THROW_ON_ERROR)), '/api/v1/acionamentos/lote');
    }

    public function testChaveFalhouPodeSerRetentada(): void
    {
        $service = new ApiIdempotencyService(Database::pdo());
        $contexto = $this->contexto();
        $corpo = json_encode(['pessoa_id' => 123], JSON_THROW_ON_ERROR);

        $primeira = $service->iniciar($contexto, $this->requisicao('teste-idem-retry', $corpo), '/api/v1/acionamentos/lote');
        $service->falhar($primeira['id']);

        $retentativa = $service->iniciar($contexto, $this->requisicao('teste-idem-retry', $corpo), '/api/v1/acionamentos/lote');
        self::assertNull($retentativa['replay'], 'apos falhar(), a mesma chave deveria poder ser retentada do zero');
    }
}
