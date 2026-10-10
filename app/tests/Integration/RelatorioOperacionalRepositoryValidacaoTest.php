<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\RelatorioOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * RelatorioOperacionalRepository.php (804 linhas) - continuação da
 * auditoria de cobertura 2026-09-17/18. preparar() usa streaming MySQL
 * pra exportar relatórios grandes (acionamentos/acordos/preventiva/
 * colchão) - caro por natureza (varredura de intervalo de datas), então
 * este teste cobre SÓ as validações de entrada (tipo/data/período), que
 * lançam exceção ANTES de rodar a query cara (falha rápido, não chega a
 * ser cara). Mesma decisão de escopo de EstrategiaRepositoryStatusTest.php
 * e DadosGeraisRepositoryEscopoTest.php (descartado por custo).
 */
final class RelatorioOperacionalRepositoryValidacaoTest extends TestCase
{
    private RelatorioOperacionalRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new RelatorioOperacionalRepository(Database::pdo());
    }

    public function testTipoInvalidoLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tipo de relatório inválido.');
        $this->repository->preparar('tipo_que_nao_existe', []);
    }

    public function testDataFimMenorQueDataInicioLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A data final não pode ser menor que a data inicial.');
        $this->repository->preparar('acionamentos', ['data_inicio' => '2026-06-01', 'data_fim' => '2026-01-01']);
    }

    public function testPeriodoMuitoGrandeLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Período muito grande.');
        $this->repository->preparar('acionamentos', ['data_inicio' => '2020-01-01', 'data_fim' => date('Y-m-d')]);
    }

    public function testListarCredoresRetornaListaNaoVazia(): void
    {
        $credores = $this->repository->listarCredores();

        self::assertNotEmpty($credores);
    }
}
