<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Services\AcmeContatoMapeamento as M;
use PHPUnit\Framework\TestCase;

/** De/para CRM -> códigos de contato da ACME (conferidos na API em 2026-10-06). */
final class AcmeContatoMapeamentoTest extends TestCase
{
    public function testSituacaoDaTelaWorksValeMaisQueOStatus(): void
    {
        self::assertSame('I', M::statusContato('hot', 'negativado'));
        self::assertSame('O', M::statusContato('desconhecido', 'validado'));
        self::assertSame('H', M::statusContato('hot', 'falta_validar'), '"falta validar" não rebaixa o status');
    }

    public function testStatusDoCrmViraOStatusIgualDaAcme(): void
    {
        self::assertSame('H', M::statusContato('hot', null));
        self::assertSame('O', M::statusContato('correto', null));
        self::assertSame('I', M::statusContato('incorreto', null));
        self::assertSame('N', M::statusContato('desconhecido', null));
        self::assertSame('P', M::statusContato('nao_confirmado', null));
        self::assertSame('P', M::statusContato('novo', null));
        self::assertNull(M::statusContato('bloqueado', null), 'bloqueado não quer dizer número errado - não mexe na ACME');
    }

    public function testTipoDeTelefone(): void
    {
        self::assertSame(15, M::tipoTelefone('whatsapp', null));
        self::assertSame(15, M::tipoTelefone('telefone', 'whatsapp'));
        self::assertSame(4, M::tipoTelefone('telefone', 'celular'));
        self::assertSame(1, M::tipoTelefone('telefone', 'residencial'));
        self::assertSame(2, M::tipoTelefone('telefone', 'comercial'));
        self::assertSame(13, M::tipoTelefone('telefone', 'recado'));
        self::assertNull(M::tipoTelefone('telefone', 'hot'), 'sem categoria clara: mantém o tipo da ACME');
        self::assertSame(4, M::tipoTelefoneInclusao('telefone', null, '987654321'));
        self::assertSame(1, M::tipoTelefoneInclusao('telefone', null, '33334444'));
    }

    public function testTelefoneSeparaDddDoNumero(): void
    {
        self::assertSame(['ddd' => '11', 'numero' => '987654321'], M::telefone(null, '11987654321'));
        self::assertSame(['ddd' => '11', 'numero' => '987654321'], M::telefone(null, '+55 (11) 98765-4321'));
        self::assertSame(['ddd' => '21', 'numero' => '33334444'], M::telefone('21', '33334444'));
        self::assertSame(['ddd' => '48', 'numero' => '996126058'], M::telefone('48', '48996126058'));
        self::assertNull(M::telefone(null, '987654321'), 'sem DDD não dá pra enviar');
        self::assertNull(M::telefone(null, '01987654321'), 'DDD começando com 0 é inválido');
    }

    public function testNumeroDoEnderecoNuncaPerdeOTextoOriginal(): void
    {
        self::assertSame(['numero' => 100, 'complemento_extra' => null], M::numeroEndereco('100'));
        self::assertSame(['numero' => 100, 'complemento_extra' => 'Nº 100A'], M::numeroEndereco('100A'));
        self::assertSame(['numero' => 0, 'complemento_extra' => 'Nº S/N'], M::numeroEndereco('S/N'));
        self::assertSame(['numero' => 0, 'complemento_extra' => null], M::numeroEndereco(null));
    }

    public function testTipoDeEnderecoEEmail(): void
    {
        self::assertSame('R', M::tipoEndereco('residencial'));
        self::assertSame('C', M::tipoEndereco('comercial'));
        self::assertSame('E', M::tipoEndereco('cobranca'));
        self::assertNull(M::tipoEndereco('outro'));
        self::assertSame(1, M::tipoEmailInclusao(null));
        self::assertSame(3, M::tipoEmailInclusao('comercial'));
    }
}
