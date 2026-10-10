<?php

declare(strict_types=1);

use CRM\Modules\Cargas\Services\NormalizarLinhaAcmeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NormalizarLinhaAcmeService.php (419 linhas) - continuação da auditoria
 * de cobertura 2026-09-17/18. Zero teste cobria a normalização da carga
 * diária da ACME (único credor em produção de verdade hoje) antes desta
 * classe. Puramente funcional (sem PDO), então cabe como teste unitário.
 * Foco nos pontos de maior risco de dado real errado: padding de CPF/CNPJ
 * truncado (documentos que chegam sem zero à esquerda), parser de valor
 * decimal (formato BR "1.234,56" vs formato US), dedup de telefone/email
 * detectados em múltiplos campos, e a chave hash_negocio usada pra
 * deduplicar linhas da carga.
 */
final class NormalizarLinhaAcmeServiceTest extends TestCase
{
    private NormalizarLinhaAcmeService $service;

    protected function setUp(): void
    {
        $this->service = new NormalizarLinhaAcmeService();
    }

    /** @param array<string,string|null> $raw */
    private function normalizar(array $raw): array
    {
        return $this->service->normalize($raw, 42, 7, 3);
    }

    /** @return list<array{0:string,1:string,2:string}> */
    public static function casosDocumento(): array
    {
        return [
            'CPF truncado sem zeros à esquerda' => ['1234552', '00001234552', 'CPF'],
            'CPF truncado 8 dígitos' => ['98765450', '00098765450', 'CPF'],
            'CPF já completo com 11 dígitos' => ['12345678901', '12345678901', 'CPF'],
            'CPF com máscara' => ['123.456.789-01', '12345678901', 'CPF'],
            'CNPJ truncado 12 dígitos' => ['123456789012', '00123456789012', 'CNPJ'],
            'CNPJ truncado 13 dígitos' => ['1234567890123', '01234567890123', 'CNPJ'],
            'CNPJ já completo com 14 dígitos' => ['12345678901234', '12345678901234', 'CNPJ'],
        ];
    }

    #[DataProvider('casosDocumento')]
    public function testPaddingEClassificacaoDeDocumento(string $entrada, string $esperado, string $tipoEsperado): void
    {
        $resultado = $this->normalizar(['Number' => $entrada]);

        self::assertSame($esperado, $resultado['documento']);
        self::assertSame($tipoEsperado, $resultado['tipo_documento']);
    }

    public function testDocumentoVazioNaoQuebraETipoDocumentoENulo(): void
    {
        $resultado = $this->normalizar(['Number' => null]);

        self::assertNull($resultado['documento']);
        self::assertNull($resultado['tipo_documento']);
    }

    /**
     * A completação de zeros altera o valor - o serviço precisa preservar
     * o original em payload_extra pra auditoria/rastreabilidade, nunca
     * silenciosamente descartar o dado como veio na carga.
     */
    public function testDocumentoCompletadoPreservaOriginalEmPayloadExtra(): void
    {
        $resultado = $this->normalizar(['Number' => '1234552']);

        self::assertSame('1234552', $resultado['payload_extra']['documento_original']);
        self::assertSame('00001234552', $resultado['payload_extra']['documento_normalizado']);
    }

    public function testDocumentoSemAlteracaoNaoPoluiPayloadExtra(): void
    {
        $resultado = $this->normalizar(['Number' => '12345678901']);

        self::assertArrayNotHasKey('documento_original', $resultado['payload_extra']);
    }

    /** @return list<array{0:string,1:?string}> */
    public static function casosDecimal(): array
    {
        return [
            'formato BR com separador de milhar' => ['1.234,56', '1234.56'],
            'formato US padrão' => ['1234.56', '1234.56'],
            'só vírgula decimal' => ['123,45', '123.45'],
            'inteiro sem separador' => ['500', '500.00'],
            'lixo não numérico' => ['abc', null],
            'vazio' => ['', null],
        ];
    }

    #[DataProvider('casosDecimal')]
    public function testParseDeValorDecimal(string $entrada, ?string $esperado): void
    {
        $resultado = $this->normalizar(['CurrentBalance' => $entrada]);

        self::assertSame($esperado, $resultado['saldo_atual']);
    }

    /** @return list<array{0:string,1:?string}> */
    public static function casosData(): array
    {
        return [
            'ISO' => ['2026-03-15', '2026-03-15'],
            'BR com barra' => ['15/03/2026', '2026-03-15'],
            'BR com hífen' => ['15-03-2026', '2026-03-15'],
            'compacto Ymd' => ['20260315', '2026-03-15'],
            'data completamente inválida' => ['não é uma data', null],
        ];
    }

    #[DataProvider('casosData')]
    public function testParseDeData(string $entrada, ?string $esperado): void
    {
        $resultado = $this->normalizar(['CutOfdate' => $entrada]);

        self::assertSame($esperado, $resultado['data_corte']);
    }

    public function testTelefoneComCodigoDePaisEhRemovido(): void
    {
        $resultado = $this->normalizar(['CelularHot' => '5511987654321']);

        self::assertSame('11987654321', $resultado['telefone_1']);
    }

    public function testTelefoneComDigitosRepetidosEhDescartado(): void
    {
        $resultado = $this->normalizar(['CelularHot' => '11111111111']);

        self::assertNull($resultado['telefone_1']);
    }

    public function testTelefoneComTamanhoInvalidoEhDescartado(): void
    {
        $resultado = $this->normalizar(['CelularHot' => '123']);

        self::assertNull($resultado['telefone_1']);
    }

    /**
     * telefone_3 é o primeiro contato detectado que NÃO seja celular_hot
     * nem fixo_hot (esses já vão pra telefone_1/telefone_2) - prova que
     * contatosDetectados()/primeiroContatoExtra() não duplicam a mesma
     * informação em campos diferentes.
     */
    public function testTelefoneExtraIgnoraSubtiposJaMapeadosEmOutrosCampos(): void
    {
        $resultado = $this->normalizar([
            'CelularHot' => '11987654321',
            'FixoHot' => '1133334444',
            'Hot' => '11955556666',
        ]);

        self::assertSame('11987654321', $resultado['telefone_1']);
        self::assertSame('1133334444', $resultado['telefone_2']);
        self::assertSame('11955556666', $resultado['telefone_3']);
    }

    public function testEmailInvalidoNoTextoLivreEhIgnoradoEValidoEhExtraido(): void
    {
        $resultado = $this->normalizar(['EmailHot' => 'contato invalido, cliente@exemplo.com.br ; outro-texto']);

        self::assertSame('cliente@exemplo.com.br', $resultado['email_1']);
    }

    public function testCamposExtrasSaoPreservadosEmPayloadExtraQuandoPresentes(): void
    {
        $resultado = $this->normalizar(['Notas' => 'Cliente pediu ligação após 18h']);

        self::assertSame('Cliente pediu ligação após 18h', $resultado['payload_extra']['Notas']);
    }

    public function testUfComDuasLetrasEhNormalizadaEUfInvalidaVaiParaPayloadExtra(): void
    {
        $valida = $this->normalizar(['RegiOn' => 'sp']);
        self::assertSame('SP', $valida['uf']);
        self::assertArrayNotHasKey('RegiOn_observacao', $valida['payload_extra']);

        $invalida = $this->normalizar(['RegiOn' => 'São Paulo Capital']);
        self::assertNull($invalida['uf']);
        self::assertArrayHasKey('RegiOn_observacao', $invalida['payload_extra']);
    }

    /**
     * hash_negocio é a chave usada pra deduplicar linhas da mesma carga -
     * duas linhas com o mesmo documento/contrato/conta devem colidir
     * (mesmo hash) e uma diferença em qualquer um dos três campos deve
     * gerar hash diferente.
     */
    public function testHashNegocioEhEstavelParaMesmaChaveEDiferenteParaChaveDiferente(): void
    {
        $linha1 = $this->normalizar(['Number' => '12345678901', 'AccountNumber' => 'CTR-1', 'Accountid' => 'ACC-1']);
        $linha2 = $this->normalizar(['Number' => '12345678901', 'AccountNumber' => 'CTR-1', 'Accountid' => 'ACC-1']);
        $linha3 = $this->normalizar(['Number' => '12345678901', 'AccountNumber' => 'CTR-2', 'Accountid' => 'ACC-1']);

        self::assertSame($linha1['hash_negocio'], $linha2['hash_negocio']);
        self::assertNotSame($linha1['hash_negocio'], $linha3['hash_negocio']);
    }

    public function testCamposBasicosSaoMapeadosCorretamenteParaCredorECarteiraInformados(): void
    {
        $resultado = $this->normalizar(['Name' => 'Cliente Teste', 'AccountNumber' => 'CTR-99']);

        self::assertSame(42, $resultado['credor_id']);
        self::assertSame(7, $resultado['carteira_id']);
        self::assertSame(3, $resultado['layout_id']);
        self::assertSame('Cliente Teste', $resultado['nome']);
        self::assertSame('CTR-99', $resultado['codigo_contrato']);
    }
}
