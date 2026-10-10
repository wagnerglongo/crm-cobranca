<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Support;

/**
 * Conversoes usadas apenas para persistencia textual do RAW.
 *
 * Importante: parsers posicionais devem trabalhar sobre os bytes originais.
 * Esta classe existe para que LONGTEXT/utf8mb4 consiga armazenar uma copia
 * legivel de linhas oriundas de arquivos Windows-1252 sem alterar os offsets
 * usados pelo parser nem o hash calculado sobre a linha original.
 */
final class CargaEncoding
{
    public static function toUtf8ForStorage(string $value, string $fallbackEncoding = 'Windows-1252'): string
    {
        if ($value === '' || self::isValidUtf8($value)) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $fallbackEncoding);
            if (is_string($converted) && self::isValidUtf8($converted)) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($fallbackEncoding, 'UTF-8//TRANSLIT', $value);
            if ($converted !== false && self::isValidUtf8($converted)) {
                return $converted;
            }

            $converted = @iconv($fallbackEncoding, 'UTF-8//IGNORE', $value);
            if ($converted !== false && self::isValidUtf8($converted)) {
                return $converted;
            }
        }

        // Ultimo recurso: impede que bytes invalidos derrubem a carga RAW.
        // O arquivo original continua preservado no storage e seu SHA-256 nao muda.
        return preg_replace('/[\x80-\xFF]/', '?', $value) ?? $value;
    }

    private static function isValidUtf8(string $value): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }

        return preg_match('//u', $value) === 1;
    }
}
