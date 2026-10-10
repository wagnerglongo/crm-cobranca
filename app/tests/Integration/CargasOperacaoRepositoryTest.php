<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\CargasOperacaoRepository;
use PHPUnit\Framework\TestCase;

/**
 * CargasOperacaoRepository.php (209 linhas) - continuação da auditoria
 * de cobertura 2026-09-17/18. resumo() bucketiza os status de carga em
 * 4 categorias (sucesso/erro/processando/pendentes) pra tela de Gestão
 * de Cargas.
 *
 * DOIS BUGS REAIS ACHADOS E CORRIGIDOS ao escrever este teste (não
 * eram achado teórico, escritos aqui pra travar a correção):
 * 1. resumo() verificava status='falha', que NUNCA existiu no ENUM real
 *    da coluna (dead code) - e 'cancelado' (status real) não caía em
 *    NENHUM dos 4 buckets, ficando invisível no resumo mesmo contando
 *    no total. Corrigido: 'falha' -> 'cancelado' no bucket 'erro'.
 * 2. listarCargas() com filtro de busca (q) estava genuinamente quebrado
 *    pra qualquer usuário real: o SQL usava :q três vezes mas só fazia
 *    UM bind - com PDO::ATTR_EMULATE_PREPARES=false (configuração real
 *    deste projeto), isso sempre lançava "Invalid parameter number".
 *    Corrigido usando 3 placeholders distintos com o mesmo valor.
 */
final class CargasOperacaoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CargasOperacaoRepository $repository;
    /** @var list<int> */
    private array $cargasCriadas = [];
    private string $hashPrefixo;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->repository = new CargasOperacaoRepository($this->pdo);

        if (!$this->repository->moduloDisponivel()) {
            self::markTestSkipped('Módulo de cargas indisponível nesta instância.');
        }

        $this->hashPrefixo = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->cargasCriadas as $id) {
            $this->pdo->prepare('DELETE FROM cargas_arquivos WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function criarCarga(string $status): int
    {
        $sufixo = $this->hashPrefixo . '-' . count($this->cargasCriadas);
        $this->pdo->prepare('INSERT INTO cargas_arquivos (nome_original, caminho_storage, hash_arquivo, status)
            VALUES (:nome, :caminho, :hash, :status)')
            ->execute([
                'nome' => 'teste_' . $sufixo . '.txt',
                'caminho' => '/tmp/teste_' . $sufixo . '.txt',
                'hash' => hash('sha256', $sufixo),
                'status' => $status,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cargasCriadas[] = $id;
        return $id;
    }

    /** @return list<array{0:string,1:string}> */
    public static function statusEBucket(): array
    {
        return [
            ['concluido', 'sucesso'],
            ['concluido_com_erros', 'sucesso'],
            ['erro', 'erro'],
            ['cancelado', 'erro'],
            ['layout_nao_identificado', 'erro'],
            ['identificando_layout', 'processando'],
            ['processando_raw', 'processando'],
            ['normalizando', 'processando'],
            ['validando', 'processando'],
            ['aplicando', 'processando'],
            ['indexando', 'processando'],
            ['recebido', 'pendentes'],
            ['aguardando_processamento', 'pendentes'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusEBucket')]
    public function testResumoBucketizaCadaStatusNaCategoriaCorreta(string $status, string $bucketEsperado): void
    {
        $antes = $this->repository->resumo();
        $this->criarCarga($status);
        $depois = $this->repository->resumo();

        foreach (['sucesso', 'erro', 'processando', 'pendentes'] as $bucket) {
            $delta = $depois[$bucket] - $antes[$bucket];
            self::assertSame($bucket === $bucketEsperado ? 1 : 0, $delta, "status '{$status}' deveria contar só em '{$bucketEsperado}'");
        }
        self::assertSame($antes['total'] + 1, $depois['total']);
    }

    public function testPrepararReprocessamentoResetaStatusEErro(): void
    {
        $id = $this->criarCarga('erro');
        $this->pdo->prepare("UPDATE cargas_arquivos SET erro_resumo = 'falha de teste', iniciado_em = NOW(), finalizado_em = NOW() WHERE id = :id")
            ->execute(['id' => $id]);

        $this->repository->prepararReprocessamento($id, 99);

        $carga = $this->repository->buscarCarga($id);
        self::assertSame('aguardando_processamento', $carga['status']);
        self::assertNull($carga['erro_resumo']);
        self::assertNull($carga['iniciado_em']);
        self::assertNull($carga['finalizado_em']);
    }

    public function testListarCargasFiltraPorStatus(): void
    {
        $this->criarCarga('erro');
        $this->criarCarga('concluido');

        $resultado = $this->repository->listarCargas(['status' => 'erro', 'q' => $this->hashPrefixo], null, 80);

        self::assertCount(1, $resultado);
        self::assertSame('erro', $resultado[0]['status']);
    }

    public function testListarCargasFiltraPorTermoDeBusca(): void
    {
        $this->criarCarga('concluido');

        $resultado = $this->repository->listarCargas(['q' => $this->hashPrefixo], null, 80);
        $semResultado = $this->repository->listarCargas(['q' => 'termo-que-nao-existe-' . bin2hex(random_bytes(4))], null, 80);

        self::assertNotEmpty($resultado);
        self::assertCount(0, $semResultado);
    }
}
