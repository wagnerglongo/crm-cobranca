<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Adapters\AcmeAcionamentoAdapter;
use PHPUnit\Framework\TestCase;

final class AcmeAcionamentoAdapterTest extends TestCase
{
    public function testPreparaPayloadCanonicoDaAcme(): void
    {
        $adapter = (new ReflectionClass(AcmeAcionamentoAdapter::class))->newInstanceWithoutConstructor();
        $payload = $adapter->preparar([
            'payload_array' => [
                'pessoa' => ['documento' => '529.982.247-25'],
                'contrato' => ['codigo' => '9535240'],
                'resultado' => ['nome' => 'Não atende'],
                'mapeamento' => ['codigo_externo' => '3'],
                'acionamento' => [
                    'observacao' => 'teste',
                    'ocorrido_em_utc' => '2026-08-19T16:45:27.526Z',
                ],
            ],
        ]);

        self::assertSame([
            'Contract' => '9535240',
            'Notes' => 'teste',
            'DocNumber' => '52998224725',
            'ActionID' => 3,
            'CreationDate' => '2026-08-19T16:45:27.526Z',
        ], $payload);
    }
}
