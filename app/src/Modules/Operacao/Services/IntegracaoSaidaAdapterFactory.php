<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Modules\Operacao\Adapters\AcmeAcionamentoAdapter;
use CRM\Modules\Operacao\Adapters\AcmeContatoAdapter;
use CRM\Modules\Operacao\Contracts\IntegracaoSaidaAdapterInterface;
use RuntimeException;

final class IntegracaoSaidaAdapterFactory
{
    /** @param array<string,mixed> $configuracao */
    public function criar(string $adapter, ?int $timeoutSegundos = null, array $configuracao = []): IntegracaoSaidaAdapterInterface
    {
        $timeout = $timeoutSegundos === null ? null : max(3, min(120, $timeoutSegundos));

        return match (strtoupper(trim($adapter))) {
            'ACME_ACIONAMENTOS' => new AcmeAcionamentoAdapter(new AcmeApiClient(null, $timeout)),
            'ACME_CONTATOS' => new AcmeContatoAdapter(new AcmeApiClient(null, $timeout)),
            default => throw new RuntimeException('Adapter de integração não implementado: ' . trim($adapter)),
        };
    }
}
