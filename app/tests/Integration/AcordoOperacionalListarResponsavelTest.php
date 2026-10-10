<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Achado (2026-09-25): em Controle de Acordos, filtrar só por
 * Responsável não achava o acordo atribuído - o filtro rodava em PHP depois
 * do LIMIT 200, então só aparecia buscando por CPF. Agora vai no SQL.
 */
final class AcordoOperacionalListarResponsavelTest extends TestCase
{
    private PDO $pdo;
    private AcordoOperacionalRepository $repository;
    private int $pessoaId = 0;
    /** @var list<int> */
    private array $acordos = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new AcordoOperacionalRepository($this->pdo);
        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de acordos indisponível nesta instância.');
        }
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:n, :d)')
            ->execute(['n' => 'Pessoa Teste Responsavel', 'd' => '556' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->acordos as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    private function criarAcordo(?array $responsavel): int
    {
        $payload = $responsavel !== null ? json_encode(['operador_responsavel' => $responsavel]) : null;
        $this->pdo->prepare("INSERT INTO acordos_operacionais (pessoa_id, status_operacional, valor_acordo, data_promessa_pagamento, quantidade_parcelas, origem, payload_extra, criado_em)
            VALUES (:p, 'quebrado', 100, CURRENT_DATE, 1, 'manual', :payload, '2020-01-01 10:00:00')")
            ->execute(['p' => $this->pessoaId, 'payload' => $payload]);
        $id = (int) $this->pdo->lastInsertId();
        $this->acordos[] = $id;
        return $id;
    }

    /** @return list<int> */
    private function ids(array $filtros): array
    {
        return array_map(static fn (array $a): int => (int) $a['id'], $this->repository->listar(['status' => 'todos'] + $filtros, 200, null));
    }

    public function testFiltroPorResponsavelEhAplicadoNoSql(): void
    {
        $usuarioId = random_int(800000, 899999);
        $comResp = $this->criarAcordo(['usuario_id' => $usuarioId, 'nome' => 'Bruno Teste']);
        $semResp = $this->criarAcordo(null);

        $porResp = $this->ids(['responsavel' => (string) $usuarioId]);
        self::assertContains($comResp, $porResp);
        self::assertNotContains($semResp, $porResp);
        self::assertCount(1, $porResp);

        $semRespLista = $this->ids(['responsavel' => 'sem_responsavel']);
        // (a lista é limitada a 200 e o banco tem muitos sem responsável; só garantimos que o atribuído não vem)
        self::assertNotContains($comResp, $semRespLista);
    }
}
