<?php

declare(strict_types=1);

namespace CRM\Modules\Operacao\Services;

/**
 * De/para do contato do CRM para os códigos da ACME (WsContactDetails).
 * Códigos conferidos na própria API em 2026-10-06 (ContactTypeInfo/1, /2,
 * /3 e ContactStatusInfo); Swagger em docs/integracoes/.
 *
 * Status da ACME = os mesmos do CRM: H Hot, O Correto, I Incorreto,
 * P Não Confirmado, N Desconhecido.
 */
final class AcmeContatoMapeamento
{
    public const STATUS_HOT = 'H';
    public const STATUS_CORRETO = 'O';
    public const STATUS_INCORRETO = 'I';
    public const STATUS_NAO_CONFIRMADO = 'P';
    public const STATUS_DESCONHECIDO = 'N';

    // Tipos de telefone (ContactTypeInfo/1).
    public const TELEFONE_RESIDENCIAL = 1;
    public const TELEFONE_COMERCIAL = 2;
    public const TELEFONE_CELULAR = 4;
    public const TELEFONE_TERCEIRO = 13;
    public const TELEFONE_CELULAR_WHATS = 15;
    public const TELEFONE_NAO_DEFINIDO = 16;

    // Tipos de e-mail (ContactTypeInfo/3).
    public const EMAIL_FINANCIADO = 1;
    public const EMAIL_TERCEIRO = 2;
    public const EMAIL_COMERCIAL = 3;

    // Tipos de endereço (ContactTypeInfo/2).
    public const ENDERECO_RESIDENCIAL = 'R';
    public const ENDERECO_COMERCIAL = 'C';
    public const ENDERECO_COBRANCA = 'E';
    public const ENDERECO_NAO_DEFINIDO = 'N';

    /**
     * Status do contato no CRM -> status ACME. A "situação" da tela Pactua
     * (negativado/validado) vale mais que a coluna status. null = sem
     * equivalente (ex.: "bloqueado" não quer dizer número errado): não muda
     * o status que está na ACME.
     */
    public static function statusContato(?string $status, ?string $situacao): ?string
    {
        $situacao = strtolower(trim((string) $situacao));
        if ($situacao === 'negativado') {
            return self::STATUS_INCORRETO;
        }
        if ($situacao === 'validado') {
            return self::STATUS_CORRETO;
        }

        return match (strtolower(trim((string) $status))) {
            'hot' => self::STATUS_HOT,
            'correto' => self::STATUS_CORRETO,
            'incorreto' => self::STATUS_INCORRETO,
            'desconhecido' => self::STATUS_DESCONHECIDO,
            'novo', 'nao_confirmado', '' => self::STATUS_NAO_CONFIRMADO,
            default => null,
        };
    }

    /** Tipo de telefone ACME pela categoria do CRM; null = sem categoria clara (mantém o da ACME). */
    public static function tipoTelefone(?string $tipo, ?string $subtipo): ?int
    {
        $subtipo = strtolower(trim((string) $subtipo));
        if (strtolower(trim((string) $tipo)) === 'whatsapp' || $subtipo === 'whatsapp') {
            return self::TELEFONE_CELULAR_WHATS;
        }

        return match ($subtipo) {
            'celular' => self::TELEFONE_CELULAR,
            'residencial' => self::TELEFONE_RESIDENCIAL,
            'comercial' => self::TELEFONE_COMERCIAL,
            'recado', 'referencia' => self::TELEFONE_TERCEIRO,
            default => null,
        };
    }

    /** Tipo pra INCLUIR telefone: a categoria do CRM ou, sem ela, deduzido pelo número. */
    public static function tipoTelefoneInclusao(?string $tipo, ?string $subtipo, string $numeroSemDdd): int
    {
        $tipoAcme = self::tipoTelefone($tipo, $subtipo);
        if ($tipoAcme !== null) {
            return $tipoAcme;
        }
        if (strlen($numeroSemDdd) === 9 && $numeroSemDdd[0] === '9') {
            return self::TELEFONE_CELULAR;
        }

        return strlen($numeroSemDdd) === 8 ? self::TELEFONE_RESIDENCIAL : self::TELEFONE_NAO_DEFINIDO;
    }

    public static function tipoEmailInclusao(?string $subtipo): int
    {
        return match (strtolower(trim((string) $subtipo))) {
            'comercial' => self::EMAIL_COMERCIAL,
            'recado', 'referencia' => self::EMAIL_TERCEIRO,
            default => self::EMAIL_FINANCIADO,
        };
    }

    /** Tipo de endereço ACME pela categoria do CRM; null = sem equivalente (mantém o da ACME). */
    public static function tipoEndereco(?string $tipo): ?string
    {
        return match (strtolower(trim((string) $tipo))) {
            'residencial' => self::ENDERECO_RESIDENCIAL,
            'comercial' => self::ENDERECO_COMERCIAL,
            'cobranca', 'correspondencia' => self::ENDERECO_COBRANCA,
            default => null,
        };
    }

    /**
     * A ACME recebe DDD e número em campos separados. Aceita o número com
     * DDD (10/11 dígitos, com ou sem 55 na frente) ou sem DDD + DDD à parte.
     *
     * @return array{ddd:string,numero:string}|null null = não dá pra enviar (sem DDD válido)
     */
    public static function telefone(?string $ddd, ?string $numero): ?array
    {
        $digitos = preg_replace('/\D+/', '', (string) $numero) ?? '';
        $ddd = preg_replace('/\D+/', '', (string) $ddd) ?? '';
        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }

        if (strlen($digitos) === 10 || strlen($digitos) === 11) {
            $ddd = substr($digitos, 0, 2);
            $digitos = substr($digitos, 2);
        } elseif (!in_array(strlen($digitos), [8, 9], true) || strlen($ddd) !== 2) {
            return null;
        }

        if ($ddd[0] === '0') {
            return null;
        }

        return ['ddd' => $ddd, 'numero' => $digitos];
    }

    /**
     * O número do endereço na ACME é inteiro. "100" vai como 100; "100A",
     * "S/N", "KM 5" vão com os dígitos iniciais (ou 0) e o texto original
     * no complemento, pra não perder informação.
     *
     * @return array{numero:int,complemento_extra:?string}
     */
    public static function numeroEndereco(?string $numero): array
    {
        $texto = trim((string) $numero);
        if ($texto === '') {
            return ['numero' => 0, 'complemento_extra' => null];
        }
        if (ctype_digit($texto) && strlen($texto) <= 9) {
            return ['numero' => (int) $texto, 'complemento_extra' => null];
        }

        $inicio = preg_match('/^\d{1,9}/', $texto, $m) === 1 ? (int) $m[0] : 0;
        return ['numero' => $inicio, 'complemento_extra' => 'Nº ' . $texto];
    }
}
