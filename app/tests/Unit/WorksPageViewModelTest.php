<?php

declare(strict_types=1);

use CRM\Modules\Operacao\ViewModels\WorksPageViewModel;
use PHPUnit\Framework\TestCase;

final class WorksPageViewModelTest extends TestCase
{
    public function testExportaContratoETimingsSemPerderTipos(): void
    {
        $vm = new WorksPageViewModel(
            contrato: ['id' => 10, 'codigo_contrato' => 'ABC'],
            estrategiaItemId: 7,
            timingsMs: ['contrato' => 1.25],
        );

        $data = $vm->toArray();
        self::assertSame(10, $data['contrato']['id']);
        self::assertSame(7, $data['estrategiaItemId']);
        self::assertSame(1.25, $data['worksQueryTimingsMs']['contrato']);
    }
}
