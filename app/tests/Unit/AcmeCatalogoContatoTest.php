<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Services\AcmeCatalogoContato;
use PHPUnit\Framework\TestCase;

/** Tabelas de códigos da ACME consultadas só quando chega código desconhecido (decisão de projeto, 2026-10-06). */
final class AcmeCatalogoContatoTest extends TestCase
{
    private string $arquivo;
    private int $consultas = 0;

    protected function setUp(): void
    {
        $this->arquivo = sys_get_temp_dir() . '/acme_catalogo_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
    }

    /** @param array<string,array<string,string>>|null $tabelas null = a consulta falha */
    private function catalogo(?array $tabelas): AcmeCatalogoContato
    {
        return new AcmeCatalogoContato($this->arquivo, function () use ($tabelas): array {
            $this->consultas++;
            if ($tabelas === null) {
                throw new RuntimeException('ACME fora do ar');
            }
            return $tabelas;
        });
    }

    public function testCodigoConhecidoNaoConsultaAAcme(): void
    {
        $catalogo = $this->catalogo(null);

        self::assertFalse($catalogo->garantirCodigos(['status' => ['I', 'H'], 'telefone' => [15, '4'], 'endereco' => ['E']]));
        self::assertSame(0, $this->consultas);
        self::assertSame('Incorreto', $catalogo->descricao('status', 'i'));
        self::assertSame('Celular / Whats', $catalogo->descricao('telefone', 15));
    }

    public function testCodigoNovoConsultaUmaVezEGuardaPraProximas(): void
    {
        $catalogo = $this->catalogo(['status' => ['B' => 'Bloqueado pelo credor'], 'telefone' => ['18' => 'Telefone Novo']]);

        self::assertTrue($catalogo->garantirCodigos(['status' => ['B'], 'telefone' => [18]]));
        self::assertSame(1, $this->consultas);
        self::assertSame('Bloqueado pelo credor', $catalogo->descricao('status', 'B'));
        self::assertSame('Telefone Novo', $catalogo->descricao('telefone', 18));
        self::assertSame('Hot', $catalogo->descricao('status', 'H'), 'as tabelas padrão continuam valendo');

        $outraInstancia = new AcmeCatalogoContato($this->arquivo);
        self::assertSame('Bloqueado pelo credor', $outraInstancia->descricao('status', 'B'), 'fica salvo pra próxima requisição');
    }

    public function testCodigoQueNemAAcmeListaNaoFicaMartelandoAApi(): void
    {
        $catalogo = $this->catalogo(['status' => ['H' => 'Hot']]);

        self::assertTrue($catalogo->garantirCodigos(['status' => ['Z']]));
        self::assertFalse($catalogo->garantirCodigos(['status' => ['Z']]), 'dentro de 1h não consulta de novo');
        self::assertSame(1, $this->consultas);
        self::assertNull($catalogo->descricao('status', 'Z'));
    }

    public function testFalhaNaConsultaNaoQuebraQuemChamou(): void
    {
        $catalogo = $this->catalogo(null);

        self::assertTrue($catalogo->garantirCodigos(['status' => ['Z']]));
        self::assertNull($catalogo->descricao('status', 'Z'));
        self::assertSame('Correto', $catalogo->descricao('status', 'O'));
    }
}
