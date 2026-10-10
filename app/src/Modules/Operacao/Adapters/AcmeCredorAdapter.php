<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Adapters;

use CRM\Modules\Operacao\Contracts\CredorAdapterInterface;
use CRM\Modules\Operacao\Services\AcmeApiClient;

final class AcmeCredorAdapter implements CredorAdapterInterface
{
    public function __construct(private readonly ?AcmeApiClient $client = null)
    {
    }

    private function api(): AcmeApiClient
    {
        return $this->client ?? new AcmeApiClient();
    }

    public function codigo(): string
    {
        return 'ACME';
    }

    public function configurado(): bool
    {
        return $this->api()->configurado();
    }

    public function consultarCliente(string $documento): array
    {
        return $this->api()->buscarDevedorPorDocumento($documento);
    }

    public function consultarNegociacao(string $documento, string $contrato): array
    {
        return $this->api()->gerarPropostasPortal($documento, $contrato);
    }

    public function consultarAcordo(string $documento, string $contrato): array
    {
        return $this->api()->consultarAcordo($documento, $contrato);
    }

    public function criarAcordo(array $dados): array
    {
        return $this->api()->salvarPropostaGerada($dados);
    }

    public function consultarBoleto(int|string $identificador): array
    {
        return $this->api()->baixarBoletoParcela((int) $identificador);
    }
}
