<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Cargas\Repositories\CargaRepository;
use CRM\Modules\Cargas\Services\AplicarCargaAcmeService;
use PHPUnit\Framework\TestCase;

/**
 * Aplicação de linha da carga ACME de ponta a ponta (staging -> pessoa,
 * contrato, contatos, endereço). Achado 2026-10-06: a carga diária regravava
 * toda pessoa/contato/endereço todo dia sem nenhum dado mudar (~80% das
 * escritas, ~6h/dia de CDC). Regras que estes testes travam (pedido do
 * Requisito: "o que tiver diferente precisa atualizar" e "cuidado com os
 * retirados pelo credor"):
 *  - carga repetida sem mudança não regrava pessoa/contato/endereço;
 *  - qualquer dado diferente atualiza como antes;
 *  - campo vazio na carga não apaga dado existente (comportamento antigo);
 *  - contrato SEMPRE é atualizado (a regra de retirado usa
 *    contratos.ultima_carga_id).
 */
final class AplicarCargaAcmeServiceAplicacaoTest extends TestCase
{
    private PDO $pdo;
    private int $credorId;
    private int $layoutId;
    private string $documento;
    private string $codigoContrato;
    /** @var list<int> */
    private array $cargas = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();

        $this->credorId = (int) $this->pdo->query("SELECT id FROM credores WHERE codigo = 'ACME' LIMIT 1")->fetchColumn();
        $this->layoutId = (int) $this->pdo->query("SELECT id FROM carga_layouts WHERE codigo = 'ACME_COBRANCA_PIPE' LIMIT 1")->fetchColumn();
        if ($this->credorId === 0 || $this->layoutId === 0) {
            self::markTestSkipped('Credor ACME ou layout ACME_COBRANCA_PIPE ausente nesta instância.');
        }

        $sufixo = bin2hex(random_bytes(4));
        $this->documento = '999' . random_int(10000000, 99999999);
        $this->codigoContrato = 'TESTE-ACME-' . $sufixo;
    }

    protected function tearDown(): void
    {
        $pessoaId = $this->pessoaId();
        if ($this->cargas !== []) {
            $in = implode(',', array_map('intval', $this->cargas));
            $this->pdo->exec("DELETE FROM stg_carga_canonica WHERE carga_arquivo_id IN ($in)");
            $this->pdo->exec("DELETE FROM cargas_erros WHERE carga_arquivo_id IN ($in)");
            $this->pdo->exec("DELETE FROM cargas_linhas_raw WHERE carga_arquivo_id IN ($in)");
        }
        if ($pessoaId !== null) {
            foreach (['contratos', 'pessoa_contatos', 'pessoa_enderecos'] as $tabela) {
                $this->pdo->prepare("DELETE FROM $tabela WHERE pessoa_id = :id")->execute(['id' => $pessoaId]);
            }
            $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $pessoaId]);
        }
        foreach ($this->cargas as $id) {
            $this->pdo->prepare('DELETE FROM cargas_arquivos WHERE id = :id')->execute(['id' => $id]);
        }
    }

    /** @return array<string,mixed> */
    private function linhaBase(): array
    {
        return [
            'documento' => $this->documento,
            'tipo_documento' => 'CPF',
            'nome' => 'PESSOA TESTE CARGA',
            'nome_mae' => 'MAE TESTE',
            'data_nascimento' => '1980-05-10',
            'renda_mensal' => '1500.00',
            'codigo_contrato' => $this->codigoContrato,
            'produto' => 'CARTAO TESTE',
            'dias_atraso' => 100,
            'saldo_atual' => '1234.56',
            'telefone_1' => '11987654321',
            'email_1' => 'teste.carga.acme@example.com',
            'cep' => '01001000',
            'uf' => 'SP',
            'cidade' => 'SAO PAULO',
            'bairro' => 'SE',
            'logradouro' => 'PRACA DA SE',
            'numero_endereco' => '100',
        ];
    }

    /**
     * Cria uma carga ACME "concluida" com 1 linha valida e aplica - igual ao
     * fluxo diario (um processo/servico novo por carga).
     *
     * @param array<string,mixed> $linha
     * @return array{carga_id:int,resumo:array<string,mixed>}
     */
    private function aplicarCarga(array $linha): array
    {
        $sufixo = bin2hex(random_bytes(6));
        $this->pdo->prepare("INSERT INTO cargas_arquivos (nome_original, caminho_storage, hash_arquivo, status, credor_id, layout_id)
            VALUES (:nome, :caminho, :hash, 'concluido', :credor_id, :layout_id)")
            ->execute([
                'nome' => 'teste_aplicacao_acme_' . $sufixo . '.txt',
                'caminho' => '/tmp/teste_aplicacao_acme_' . $sufixo . '.txt',
                'hash' => hash('sha256', $sufixo),
                'credor_id' => $this->credorId,
                'layout_id' => $this->layoutId,
            ]);
        $cargaId = (int) $this->pdo->lastInsertId();
        $this->cargas[] = $cargaId;

        $this->pdo->prepare('INSERT INTO cargas_linhas_raw (carga_arquivo_id, numero_linha, linha_bruta, hash_linha)
            VALUES (:carga, 1, :linha, :hash)')
            ->execute(['carga' => $cargaId, 'linha' => 'linha teste ' . $sufixo, 'hash' => hash('sha256', 'raw' . $sufixo)]);
        $rawId = (int) $this->pdo->lastInsertId();

        $dados = ['carga_arquivo_id' => $cargaId, 'linha_raw_id' => $rawId, 'credor_id' => $this->credorId, 'layout_id' => $this->layoutId, 'status' => 'valido'] + $linha;
        $colunas = array_keys($dados);
        $this->pdo->prepare('INSERT INTO stg_carga_canonica (' . implode(',', $colunas) . ') VALUES (:' . implode(',:', $colunas) . ')')
            ->execute($dados);

        $resumo = (new AplicarCargaAcmeService(new CargaRepository($this->pdo)))->aplicar($cargaId);
        self::assertSame(0, $resumo['erros'], 'a aplicação da carga de teste não pode ter erro de linha');

        return ['carga_id' => $cargaId, 'resumo' => $resumo];
    }

    private function pessoaId(): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM pessoas WHERE documento = :documento');
        $stmt->execute(['documento' => $this->documento]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string,mixed> */
    private function pessoa(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pessoas WHERE documento = :documento');
        $stmt->execute(['documento' => $this->documento]);
        return $stmt->fetch();
    }

    /** @return array<string,mixed> */
    private function contrato(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contratos WHERE credor_id = :credor AND codigo_contrato = :codigo');
        $stmt->execute(['credor' => $this->credorId, 'codigo' => $this->codigoContrato]);
        return $stmt->fetch();
    }

    /** @return array<string,mixed> */
    private function endereco(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pessoa_enderecos WHERE pessoa_id = :id');
        $stmt->execute(['id' => $this->pessoaId()]);
        return $stmt->fetch();
    }

    /** @return list<int> ultima_carga_id de cada contato da pessoa */
    private function cargasDosContatos(): array
    {
        $stmt = $this->pdo->prepare('SELECT ultima_carga_id FROM pessoa_contatos WHERE pessoa_id = :id ORDER BY id');
        $stmt->execute(['id' => $this->pessoaId()]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testCargaRepetidaSemMudancaNaoRegravaPessoaContatoEndereco(): void
    {
        $c1 = $this->aplicarCarga($this->linhaBase());
        self::assertSame($c1['carga_id'], (int) $this->pessoa()['ultima_carga_id']);
        self::assertCount(2, $this->cargasDosContatos(), 'telefone + e-mail');

        // 2a carga identica: pessoa e endereco ficam como estao; contato ainda
        // ganha o registro de "ultima ocorrencia" uma vez (payload novo).
        $c2 = $this->aplicarCarga($this->linhaBase());
        self::assertSame(1, $c2['resumo']['pessoas_sem_alteracao']);
        self::assertSame(1, $c2['resumo']['enderecos_sem_alteracao']);

        // 3a carga identica: nada de pessoa/contato/endereco e regravado.
        $c3 = $this->aplicarCarga($this->linhaBase());
        self::assertSame(1, $c3['resumo']['pessoas_sem_alteracao']);
        self::assertSame(2, $c3['resumo']['contatos_sem_alteracao']);
        self::assertSame(1, $c3['resumo']['enderecos_sem_alteracao']);
        self::assertSame(0, $c3['resumo']['contatos_criados']);
        self::assertSame(0, $c3['resumo']['enderecos_criados']);

        self::assertSame($c1['carga_id'], (int) $this->pessoa()['ultima_carga_id'], 'pessoa sem mudança não é regravada');
        self::assertSame($c1['carga_id'], (int) $this->endereco()['ultima_carga_id'], 'endereço sem mudança não é regravado');
        self::assertSame([$c2['carga_id'], $c2['carga_id']], $this->cargasDosContatos(), 'contato sem mudança não é regravado na 3a carga');

        // Regra dos retirados pelo credor: contrato continua marcado com a carga mais recente.
        self::assertSame($c3['carga_id'], (int) $this->contrato()['ultima_carga_id']);
    }

    public function testDadoDiferenteNaCargaAtualizaComoAntes(): void
    {
        $this->aplicarCarga($this->linhaBase());

        $linha = $this->linhaBase();
        $linha['nome_mae'] = 'MAE NOVA';
        $linha['renda_mensal'] = '2000.00';
        $linha['logradouro'] = 'RUA NOVA';
        $linha['numero_endereco'] = '200';
        $linha['telefone_2'] = '1133334444';
        $linha['dias_atraso'] = 101;
        $c2 = $this->aplicarCarga($linha);

        $pessoa = $this->pessoa();
        self::assertSame('MAE NOVA', $pessoa['nome_mae']);
        self::assertSame('2000.00', (string) $pessoa['renda_mensal']);
        self::assertSame($c2['carga_id'], (int) $pessoa['ultima_carga_id']);
        self::assertSame(0, $c2['resumo']['pessoas_sem_alteracao']);

        $endereco = $this->endereco();
        self::assertSame('RUA NOVA', $endereco['logradouro']);
        self::assertSame('200', $endereco['numero']);
        self::assertSame(1, $c2['resumo']['enderecos_atualizados']);

        self::assertSame(1, $c2['resumo']['contatos_criados'], 'telefone novo na carga vira contato novo');
        self::assertSame(101, (int) $this->contrato()['dias_atraso']);
    }

    /** Comportamento que já existia e continua: vazio na carga não apaga o que está no banco. */
    public function testCampoVazioNaCargaNaoApagaDadoExistente(): void
    {
        $this->aplicarCarga($this->linhaBase());

        $linha = $this->linhaBase();
        $linha['nome_mae'] = '';
        $c2 = $this->aplicarCarga($linha);

        self::assertSame('MAE TESTE', $this->pessoa()['nome_mae']);
        self::assertSame(1, $c2['resumo']['pessoas_sem_alteracao']);
    }

    /** Mudança só nos metadados do documento (payload_extra) também conta como mudança real. */
    public function testMudancaNoPayloadDaPessoaForaDosPonteirosAtualiza(): void
    {
        $this->aplicarCarga($this->linhaBase());

        $linha = $this->linhaBase();
        $linha['payload_extra'] = json_encode(['documento_original' => '999.000.000-00']);
        $c2 = $this->aplicarCarga($linha);

        self::assertSame(0, $c2['resumo']['pessoas_sem_alteracao']);
        $payload = json_decode((string) $this->pessoa()['payload_extra'], true);
        self::assertSame('999.000.000-00', $payload['acme']['documento']['documento_original']);
        self::assertSame($c2['carga_id'], (int) $this->pessoa()['ultima_carga_id']);
    }
}
