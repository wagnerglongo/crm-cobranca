<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Contracts;

interface CredorAdapterInterface
{
    public function codigo(): string;

    public function configurado(): bool;

    /** @return array<string,mixed> */
    public function consultarCliente(string $documento): array;

    /** @return array<string,mixed> */
    public function consultarNegociacao(string $documento, string $contrato): array;

    /** @return array<string,mixed> */
    public function consultarAcordo(string $documento, string $contrato): array;

    /** @param array<string,mixed> $dados @return array<string,mixed> */
    public function criarAcordo(array $dados): array;

    /** @return array<string,mixed> */
    public function consultarBoleto(int|string $identificador): array;
}
