<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Controllers;

use CRM\Modules\Operacao\Services\WorksQueryService;
use CRM\Modules\Operacao\ViewModels\WorksPageViewModel;

final class WorksController
{
    public function __construct(private readonly WorksQueryService $queryService)
    {
    }

    /**
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopoConsulta
     * @param array{todos?:bool,credor_ids?:list<int>,carteira_ids?:list<int>}|null $escopoOperacao
     */
    public function carregar(
        int $contratoId,
        int $pessoaIdBusca,
        string $termoBusca,
        string $tipoBusca,
        string $tab,
        ?array $escopoConsulta,
        ?array $escopoOperacao,
        int $estrategiaItemId,
        int $usuarioId,
    ): WorksPageViewModel {
        return $this->queryService->carregar(
            $contratoId,
            $pessoaIdBusca,
            $termoBusca,
            $tipoBusca,
            $tab,
            $escopoConsulta,
            $escopoOperacao,
            $estrategiaItemId,
            $usuarioId,
        );
    }
}
