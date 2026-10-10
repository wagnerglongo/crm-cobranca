<?php

declare(strict_types=1);

namespace CRM\Modules\Api\Idempotency;

use CRM\Config\Database;
use CRM\Infrastructure\Http\ApiException;
use CRM\Infrastructure\Http\ApiRequest;
use CRM\Infrastructure\Http\ApiResponse;
use CRM\Modules\Api\Auth\AuthContext;
use PDO;
use PDOException;

final class ApiIdempotencyService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int,replay:ApiResponse|null} */
    public function iniciar(AuthContext $context, ApiRequest $request, string $routePattern): array
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) < 8 || strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw new ApiException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Informe um Idempotency-Key válido de 8 a 128 caracteres.');
        }

        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', $request->rawBody());
        $ttl = max(300, min(604800, (int) Database::env('API_IDEMPOTENCY_TTL', '86400')));

        $cleanup = $this->pdo->prepare('DELETE FROM api_idempotencias
            WHERE usuario_id = :usuario_id AND metodo = :metodo AND rota = :rota AND chave_hash = :chave_hash AND expira_em <= NOW()');
        $cleanup->execute([
            'usuario_id' => $context->usuarioId(),
            'metodo' => $request->method(),
            'rota' => mb_substr($routePattern, 0, 190),
            'chave_hash' => $keyHash,
        ]);

        try {
            $stmt = $this->pdo->prepare('INSERT INTO api_idempotencias (
                    usuario_id, metodo, rota, chave_hash, requisicao_hash, status, criado_em, expira_em
                ) VALUES (
                    :usuario_id, :metodo, :rota, :chave_hash, :requisicao_hash, \'processando\', NOW(), :expira_em
                )');
            $stmt->bindValue('usuario_id', $context->usuarioId(), PDO::PARAM_INT);
            $stmt->bindValue('metodo', $request->method());
            $stmt->bindValue('rota', mb_substr($routePattern, 0, 190));
            $stmt->bindValue('chave_hash', $keyHash);
            $stmt->bindValue('requisicao_hash', $requestHash);
            $stmt->bindValue('expira_em', date('Y-m-d H:i:s', time() + $ttl));
            $stmt->execute();
            return ['id' => (int) $this->pdo->lastInsertId(), 'replay' => null];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare('SELECT * FROM api_idempotencias
            WHERE usuario_id = :usuario_id AND metodo = :metodo AND rota = :rota AND chave_hash = :chave_hash
            LIMIT 1');
        $stmt->execute([
            'usuario_id' => $context->usuarioId(),
            'metodo' => $request->method(),
            'rota' => mb_substr($routePattern, 0, 190),
            'chave_hash' => $keyHash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || strtotime((string) $row['expira_em']) <= time()) {
            throw new ApiException(409, 'IDEMPOTENCY_STATE_CONFLICT', 'A chave de idempotência está em um estado inválido ou expirado.');
        }
        if (!hash_equals((string) $row['requisicao_hash'], $requestHash)) {
            throw new ApiException(409, 'IDEMPOTENCY_KEY_REUSED', 'A mesma chave de idempotência foi usada com outro conteúdo.');
        }
        if ((string) $row['status'] === 'concluido' && (int) ($row['resposta_status'] ?? 0) === 204) {
            return [
                'id' => (int) $row['id'],
                'replay' => ApiResponse::noContent(['Idempotency-Replayed' => 'true']),
            ];
        }
        if ((string) $row['status'] === 'concluido' && $row['resposta_json'] !== null) {
            $payload = json_decode((string) $row['resposta_json'], true);
            if (is_array($payload)) {
                return [
                    'id' => (int) $row['id'],
                    'replay' => ApiResponse::json($payload, (int) ($row['resposta_status'] ?? 200), ['Idempotency-Replayed' => 'true']),
                ];
            }
        }
        if ((string) $row['status'] === 'falhou') {
            $retry = $this->pdo->prepare("UPDATE api_idempotencias
                SET status = 'processando', resposta_status = NULL, resposta_json = NULL, expira_em = :expira_em
                WHERE id = :id AND status = 'falhou'");
            $retry->execute(['id' => (int) $row['id'], 'expira_em' => date('Y-m-d H:i:s', time() + $ttl)]);
            if ($retry->rowCount() === 1) {
                return ['id' => (int) $row['id'], 'replay' => null];
            }
        }
        throw new ApiException(409, 'IDEMPOTENCY_IN_PROGRESS', 'Uma requisição com esta chave já está em processamento.');
    }

    public function concluir(int $id, ApiResponse $response): void
    {
        $payload = $response->payload();
        if ($id <= 0) {
            return;
        }
        if ($payload === null && $response->status() === 204) {
            $stmt = $this->pdo->prepare("UPDATE api_idempotencias
                SET status = 'concluido', resposta_status = 204, resposta_json = NULL
                WHERE id = :id AND status = 'processando'");
            $stmt->execute(['id' => $id]);
            return;
        }
        if ($payload === null) {
            $this->falhar($id);
            return;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 65535) {
            $this->falhar($id);
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE api_idempotencias
            SET status = 'concluido', resposta_status = :status, resposta_json = :json
            WHERE id = :id AND status = 'processando'");
        $stmt->execute(['id' => $id, 'status' => $response->status(), 'json' => $json]);
    }

    public function falhar(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE api_idempotencias SET status = 'falhou' WHERE id = :id AND status = 'processando'");
        $stmt->execute(['id' => $id]);
    }
}
