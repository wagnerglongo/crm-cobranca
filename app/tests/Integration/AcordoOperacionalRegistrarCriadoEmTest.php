<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Achado (2026-09-29): acordo que "veio de fora" (sincronizado
 * automaticamente da ACME) registrava criado_em = agora (quando o CRM ficou
 * sabendo), não a data real em que o acordo foi gerado na ACME. registrar()
 * passa a aceitar um criado_em opcional pra esses casos - sem quebrar o
 * comportamento padrão (NOW() do banco) pra todo o resto.
 */
final class AcordoOperacionalRegistrarCriadoEmTest extends TestCase
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
            ->execute(['n' => 'Pessoa Teste Criado Em', 'd' => '558' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->acordos as $id) {
            $this->pdo->prepare('DELETE FROM acordos_operacionais WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function dadosValidos(array $overrides = []): array
    {
        return array_merge([
            'valor_acordo' => '300,00',
            'quantidade_parcelas' => 1,
            'data_primeira_parcela' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'status_operacional' => 'pendente',
            'origem' => 'manual',
        ], $overrides);
    }

    private function criadoEm(int $id): string
    {
        return (string) $this->pdo->query("SELECT criado_em FROM acordos_operacionais WHERE id = {$id}")->fetchColumn();
    }

    public function testSemCriadoEmUsaOPadraoDoBanco(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos());
        $this->acordos[] = $id;

        $criadoEm = new DateTimeImmutable($this->criadoEm($id));
        $diferenca = (new DateTimeImmutable('now'))->getTimestamp() - $criadoEm->getTimestamp();
        self::assertLessThan(60, abs($diferenca), 'sem criado_em explícito, deve usar o momento atual (padrão do banco)');
    }

    public function testComCriadoEmUsaADataInformada(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'criado_em' => '2026-05-14 10:00:00',
        ]));
        $this->acordos[] = $id;

        self::assertSame('2026-05-14 10:00:00', $this->criadoEm($id));
    }

    public function testCriadoEmFuturoEIgnorado(): void
    {
        $id = $this->repository->registrar($this->pessoaId, $this->dadosValidos([
            'criado_em' => (new DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s'),
        ]));
        $this->acordos[] = $id;

        $criadoEm = new DateTimeImmutable($this->criadoEm($id));
        self::assertLessThan(60, abs((new DateTimeImmutable('now'))->getTimestamp() - $criadoEm->getTimestamp()), 'data futura inválida deve ser ignorada e cair no padrão do banco');
    }
}
