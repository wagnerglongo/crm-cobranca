<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Modules\Operacao\Repositories\AcionamentoExternoRepository;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;
use PDO;
use RuntimeException;
use Throwable;

final class AcmeAcionamentoSyncService
{
    private CredorIntegracaoRepository $integracoes;
    private AcionamentoExternoRepository $externos;

    public function __construct(private readonly PDO $pdo, private readonly ?AcmeApiClient $client = null)
    {
        $this->integracoes = new CredorIntegracaoRepository($pdo);
        $this->externos = new AcionamentoExternoRepository($pdo);
    }

    public function disponivel(): bool
    {
        return $this->integracoes->moduloDisponivel() && $this->externos->moduloDisponivel();
    }

    /**
     * @return array{itens:list<array<string,mixed>>,sincronizados:int,ultima_sincronizacao:?string}
     */
    public function sincronizar(int $credorId, int $pessoaId, int $contratoId, string $documento): array
    {
        if (!$this->disponivel()) {
            throw new RuntimeException('Estruturas da integração ACME ainda não estão disponíveis. Execute a migration 085.');
        }

        $integracao = $this->integracoes->integracaoAtivaPorAdapter($credorId, 'ACME_ACIONAMENTOS');
        if ($integracao === null) {
            throw new RuntimeException('Integração ACME de acionamentos não está ativa para este credor.');
        }

        $client = $this->client ?? new AcmeApiClient(
            null,
            isset($integracao['timeout_segundos']) ? (int) $integracao['timeout_segundos'] : null
        );
        try {
            $itens = $client->consultarAcionamentos($documento);
        } catch (Throwable $e) {
            $this->externos->registrarFalhaConsulta($integracao, $documento, $e->getMessage());
            throw $e;
        }
        $sincronizados = $this->externos->sincronizar($integracao, $pessoaId, $contratoId, $documento, $itens);

        return [
            'itens' => $this->externos->listar($credorId, $documento),
            'sincronizados' => $sincronizados,
            'ultima_sincronizacao' => $this->externos->ultimaSincronizacao($credorId, $documento),
        ];
    }

    /** @return array{itens:list<array<string,mixed>>,ultima_sincronizacao:?string} */
    public function cache(int $credorId, string $documento): array
    {
        if (!$this->disponivel()) {
            return ['itens' => [], 'ultima_sincronizacao' => null];
        }

        return [
            'itens' => $this->externos->listar($credorId, $documento),
            'ultima_sincronizacao' => $this->externos->ultimaSincronizacao($credorId, $documento),
        ];
    }
}
