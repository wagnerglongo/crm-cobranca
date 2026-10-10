<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Services\AcmeCatalogoContato;
use PHPUnit\Framework\TestCase;

/**
 * "Localização ACME" (dados que vêm DA ACME pro CRM). Achados de 2026-10-06:
 * número "Incorreto" na ACME entrava como "correto" no CRM; e código que o CRM
 * não conhece passa a ser buscado nas tabelas da ACME só quando aparece.
 */
final class LocalizacaoAcmeSincronizacaoTest extends TestCase
{
    private PDO $pdo;
    private int $pessoaId;
    private string $arquivoCatalogo;
    private int $consultasAcme = 0;

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->pdo->prepare('INSERT INTO pessoas (nome, documento) VALUES (:nome, :documento)')
            ->execute(['nome' => 'Pessoa Teste Localizacao', 'documento' => '997' . random_int(10000000, 99999999)]);
        $this->pessoaId = (int) $this->pdo->lastInsertId();
        $this->arquivoCatalogo = sys_get_temp_dir() . '/acme_catalogo_loc_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        foreach (['pessoa_contatos', 'pessoa_enderecos'] as $tabela) {
            $this->pdo->prepare("DELETE FROM $tabela WHERE pessoa_id = :id")->execute(['id' => $this->pessoaId]);
        }
        $this->pdo->prepare('DELETE FROM pessoas WHERE id = :id')->execute(['id' => $this->pessoaId]);
        @unlink($this->arquivoCatalogo);
    }

    private function sincronizar(array $payload): void
    {
        $catalogo = new AcmeCatalogoContato($this->arquivoCatalogo, function (): array {
            $this->consultasAcme++;
            return ['status' => ['B' => 'Bloqueado pelo credor'], 'telefone' => ['18' => 'Telefone Novo']];
        });
        (new AtendimentoAdaptorRepository($this->pdo))->sincronizarLocalizacaoAcme($this->pessoaId, $payload, $catalogo);
    }

    /** @return array<string,array<string,mixed>> contatos por número */
    private function contatos(): array
    {
        $stmt = $this->pdo->prepare('SELECT valor_normalizado, status, payload_extra FROM pessoa_contatos WHERE pessoa_id = :id');
        $stmt->execute(['id' => $this->pessoaId]);
        $porNumero = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['p'] = json_decode((string) $row['payload_extra'], true);
            $porNumero[$row['valor_normalizado']] = $row;
        }
        return $porNumero;
    }

    public function testIncorretoNaAcmeEntraComoIncorretoNoCrm(): void
    {
        $this->sincronizar(['RecordsPhones' => [
            ['PhoneID' => 1, 'DDD' => 11, 'PhoneNumber' => '11987654321', 'PhoneType' => 4, 'Status' => 'I', 'StatusAlias' => 'Incorreto'],
            ['PhoneID' => 2, 'DDD' => 11, 'PhoneNumber' => '11912345678', 'PhoneType' => 4, 'Status' => 'O', 'StatusAlias' => 'Correto'],
        ]]);

        $contatos = $this->contatos();
        self::assertSame('incorreto', $contatos['11987654321']['status']);
        self::assertSame('correto', $contatos['11912345678']['status']);
        self::assertSame(0, $this->consultasAcme, 'códigos conhecidos não consultam a ACME');
    }

    public function testCodigoSemDescricaoUsaATabelaOficial(): void
    {
        $this->sincronizar(['RecordsPhones' => [
            ['PhoneID' => 1, 'DDD' => 11, 'PhoneNumber' => '11987654321', 'PhoneType' => 15, 'Status' => 'N'],
            ['PhoneID' => 2, 'DDD' => 11, 'PhoneNumber' => '11912345678', 'PhoneType' => 4, 'Status' => 'P'],
        ]]);

        $contatos = $this->contatos();
        self::assertSame('desconhecido', $contatos['11987654321']['status']);
        self::assertSame('Desconhecido', $contatos['11987654321']['p']['acme_status_alias']);
        self::assertSame('Celular / Whats', $contatos['11987654321']['p']['acme_phone_type_alias']);
        self::assertSame('nao_confirmado', $contatos['11912345678']['status']);
        self::assertSame(0, $this->consultasAcme);
    }

    public function testStatusNovoDaAcmeConsultaAsTabelasEFicaMarcado(): void
    {
        $this->sincronizar(['RecordsPhones' => [
            ['PhoneID' => 9, 'DDD' => 11, 'PhoneNumber' => '11987654321', 'PhoneType' => 18, 'Status' => 'B'],
        ]]);

        $contato = $this->contatos()['11987654321'];
        self::assertSame(1, $this->consultasAcme, 'código desconhecido: consulta as tabelas da ACME uma vez');
        self::assertSame('novo', $contato['status'], 'sem equivalente no CRM entra como novo');
        self::assertTrue($contato['p']['acme_status_sem_equivalente']);
        self::assertSame('Bloqueado pelo credor', $contato['p']['acme_status_alias']);
        self::assertSame('Telefone Novo', $contato['p']['acme_phone_type_alias']);
    }

    public function testTipoDeEnderecoSemDescricaoUsaATabela(): void
    {
        $this->sincronizar(['Addresses' => [
            ['AddressID' => 5, 'CEP' => '01001000', 'Locale' => 'Praca da Se', 'Number' => 1, 'City' => 'Sao Paulo', 'State' => 'SP', 'AddressType' => 'E', 'AddressesStatus' => 'O'],
        ]]);

        $stmt = $this->pdo->prepare('SELECT tipo, payload_extra FROM pessoa_enderecos WHERE pessoa_id = :id');
        $stmt->execute(['id' => $this->pessoaId]);
        $endereco = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('cobranca', $endereco['tipo']);
        self::assertSame('Endereço de Cobrança', json_decode((string) $endereco['payload_extra'], true)['acme_address_type_alias']);
    }
}
