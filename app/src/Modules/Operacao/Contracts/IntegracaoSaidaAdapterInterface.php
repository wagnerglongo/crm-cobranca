<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Contracts;

interface IntegracaoSaidaAdapterInterface
{
    public function codigo(): string;

    public function configurado(): bool;

    /** @param array<string,mixed> $evento @return array<string,mixed> */
    public function preparar(array $evento): array;

    /**
     * @param array<string,mixed> $requisicao
     * @return array{response:array<string,mixed>,http_status:int,protocolo:?string}
     */
    public function enviar(array $requisicao): array;
}
