<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

use CRM\Modules\Operacao\Repositories\RelatorioOperacionalRepository;

final class RelatorioCsvExporter
{
    private CsvStreamExporter $stream;

    public function __construct(
        private readonly RelatorioOperacionalRepository $repository,
        ?CsvStreamExporter $stream = null
    ) {
        $this->stream = $stream ?? new CsvStreamExporter();
    }

    /** @param array<string,mixed> $filtros */
    public function enviar(string $tipo, array $filtros, ?array $escopo = null, ?string $downloadToken = null): never
    {
        $prepared = $this->repository->preparar($tipo, $filtros, $escopo);
        $this->stream->enviar($prepared, $downloadToken);
    }
}
