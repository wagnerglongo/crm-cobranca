<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Modules\Operacao\Adapters\AcmeCredorAdapter;
use CRM\Modules\Operacao\Contracts\CredorAdapterInterface;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use PDO;

final class CredorAdapterFactory
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?CredorConfiguracaoRepository $configuracoes = null
    ) {
    }

    /** @param array<string,mixed> $contrato */
    public function paraContrato(array $contrato): ?CredorAdapterInterface
    {
        $codigo = strtoupper(trim((string) ($contrato['credor_codigo'] ?? $contrato['credor_nome'] ?? '')));
        $credorId = (int) ($contrato['credor_id'] ?? 0);
        $produto = trim((string) ($contrato['produto'] ?? ''));

        if ($credorId > 0) {
            $repo = $this->configuracoes ?? new CredorConfiguracaoRepository($this->pdo);
            $adapterConfigurado = trim((string) ($repo->valor($credorId, 'integracao.adapter', $produto) ?? ''));
            if ($adapterConfigurado !== '') {
                $codigo = strtoupper($adapterConfigurado);
            }
        }

        return $this->porCodigo($codigo);
    }

    public function porCodigo(string $codigo): ?CredorAdapterInterface
    {
        $codigo = strtoupper(trim($codigo));
        return match ($codigo) {
            'ACME', 'ACMECREDOR', 'ACME_API' => new AcmeCredorAdapter(),
            default => null,
        };
    }
}
