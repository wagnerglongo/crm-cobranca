<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use PHPUnit\Framework\TestCase;

final class AtendimentoAdaptorRepositoryTest extends TestCase
{
    private AtendimentoAdaptorRepository $repository;

    protected function setUp(): void
    {
        $this->repository = (new ReflectionClass(AtendimentoAdaptorRepository::class))->newInstanceWithoutConstructor();
    }

    public function testReconheceCpfComAlias(): void
    {
        $busca = $this->repository->normalizarBusca('cpf 529.982.247-25');
        self::assertSame('documento', $busca['tipo']);
        self::assertSame('52998224725', $busca['digitos']);
    }

    public function testReconheceContratoExplicitamente(): void
    {
        $busca = $this->repository->normalizarBusca('contrato 9535240');
        self::assertSame('contrato', $busca['tipo']);
        self::assertSame('9535240', $busca['digitos']);
    }
}
