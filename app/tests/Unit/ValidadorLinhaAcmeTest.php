<?php

declare(strict_types=1);

use CRM\Modules\Cargas\Validators\ValidadorLinhaAcme;
use PHPUnit\Framework\TestCase;

/**
 * ValidadorLinhaAcme.php (94 linhas) - continuação da auditoria de
 * cobertura 2026-09-17/18. Testado isolado (sem passar pela
 * normalização) de propósito: ao escrever ProcessarCargaServiceTest.php,
 * achei que os 2 caminhos de ALERTA (e-mail mal formatado, ano de
 * veículo fora da faixa) nunca disparam de verdade no pipeline real da
 * ACME, porque NormalizarLinhaAcmeService já descarta esses valores ANTES
 * do Validador rodar (mais estrito, não é bug). Testar o Validador
 * isolado prova que a lógica dele em si está correta - só não é
 * exercitada hoje por esse pipeline específico (pode passar a ser se um
 * dia outra fonte de dado chamar o Validador direto, sem normalizar
 * antes).
 */
final class ValidadorLinhaAcmeTest extends TestCase
{
    private ValidadorLinhaAcme $validator;

    protected function setUp(): void
    {
        $this->validator = new ValidadorLinhaAcme();
    }

    /** @param array<string,mixed> $overrides */
    private function linhaValida(array $overrides = []): array
    {
        return array_merge([
            'documento' => '11122233344',
            'nome' => 'Cliente Válido',
            'codigo_contrato' => 'CTR-1',
        ], $overrides);
    }

    public function testLinhaCompletamenteValidaNaoGeraNenhumaPendencia(): void
    {
        $issues = $this->validator->validate($this->linhaValida());

        self::assertSame([], $issues);
        self::assertFalse($this->validator->hasBlockingError($issues));
    }

    public function testDocumentoAusenteEhErroBloqueante(): void
    {
        $issues = $this->validator->validate($this->linhaValida(['documento' => null]));

        self::assertSame('DOCUMENTO_OBRIGATORIO', $issues[0]['codigo']);
        self::assertSame('erro', $issues[0]['severidade']);
        self::assertTrue($this->validator->hasBlockingError($issues));
    }

    public function testDocumentoComTamanhoInvalidoEhErroBloqueante(): void
    {
        $issues = $this->validator->validate($this->linhaValida(['documento' => '123']));

        self::assertSame('DOCUMENTO_TAMANHO_INVALIDO', $issues[0]['codigo']);
        self::assertTrue($this->validator->hasBlockingError($issues));
    }

    public function testDocumentoComDigitosRepetidosEhErroBloqueante(): void
    {
        $issues = $this->validator->validate($this->linhaValida(['documento' => '11111111111']));

        $codigos = array_column($issues, 'codigo');
        self::assertContains('DOCUMENTO_REPETIDO_INVALIDO', $codigos);
        self::assertTrue($this->validator->hasBlockingError($issues));
    }

    public function testNomeAusenteEhErroBloqueante(): void
    {
        $issues = $this->validator->validate($this->linhaValida(['nome' => null]));

        self::assertSame('NOME_OBRIGATORIO', $issues[0]['codigo']);
        self::assertTrue($this->validator->hasBlockingError($issues));
    }

    public function testContratoAusenteEhErroBloqueante(): void
    {
        $issues = $this->validator->validate($this->linhaValida(['codigo_contrato' => null]));

        self::assertSame('CONTRATO_OBRIGATORIO', $issues[0]['codigo']);
        self::assertTrue($this->validator->hasBlockingError($issues));
    }

    /**
     * Alerta, não erro - a linha deve continuar podendo ir pro staging
     * como 'valido' mesmo com essa pendência.
     */
    public function testEmailComFormatoInvalidoEhAlertaNaoBloqueante(): void
    {
        $issues = $this->validator->validate($this->linhaValida(['email_1' => 'nao-e-um-email']));

        self::assertSame('EMAIL_FORMATO_INVALIDO', $issues[0]['codigo']);
        self::assertSame('alerta', $issues[0]['severidade']);
        self::assertFalse($this->validator->hasBlockingError($issues));
    }

    public function testAnoDeFabricacaoForaDaFaixaEhAlertaNaoBloqueante(): void
    {
        $issues = $this->validator->validate($this->linhaValida(['ano_fabricacao' => 1850]));

        self::assertSame('ANO_VEICULO_FORA_FAIXA', $issues[0]['codigo']);
        self::assertSame('alerta', $issues[0]['severidade']);
        self::assertFalse($this->validator->hasBlockingError($issues));
    }
}
