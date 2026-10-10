<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

final class WorksTabPolicy
{
    public const TABS = ['acionamento', 'localizacao', 'financeiro'];

    public function normalizar(string $tab, bool $temContrato): string
    {
        if (!$temContrato) {
            return 'busca';
        }

        return in_array($tab, self::TABS, true) ? $tab : 'acionamento';
    }

    public function usaHistorico(string $tab): bool
    {
        return $tab === 'acionamento';
    }

    public function usaLocalizacao(string $tab): bool
    {
        return $tab === 'localizacao';
    }

    public function usaFinanceiro(string $tab): bool
    {
        return $tab === 'financeiro';
    }
}
