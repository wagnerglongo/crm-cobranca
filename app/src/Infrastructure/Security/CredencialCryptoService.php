<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Security;

use CRM\Config\Database;
use RuntimeException;
use SodiumException;

/**
 * Criptografia simetrica (libsodium/XSalsa20-Poly1305) para credenciais
 * que precisam ficar no banco - caso das rotinas de coleta de carga via
 * SFTP (usuario/senha do servidor de arquivos do credor). Diferente das
 * credenciais de API (credores), que continuam exclusivamente no
 * .env.local: aqui a fonte e' um servidor de arquivos por credor, cadastrado
 * pela tela, entao a senha precisa ser recuperavel pelo proprio sistema -
 * por isso criptografia reversivel com chave fora do banco, e nao hash.
 * A chave (ROTINAS_CARGA_CHAVE_CRIPTO, base64 de 32 bytes) fica so no
 * .env.local, nunca versionada.
 */
final class CredencialCryptoService
{
    private readonly string $chave;

    public function __construct(?string $chaveBase64 = null)
    {
        $chaveBase64 ??= Database::env('ROTINAS_CARGA_CHAVE_CRIPTO', '');
        $chaveBase64 = trim((string) $chaveBase64);
        if ($chaveBase64 === '') {
            throw new RuntimeException(
                'ROTINAS_CARGA_CHAVE_CRIPTO não configurada no .env.local. Gere com: php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"'
            );
        }

        $chave = base64_decode($chaveBase64, true);
        if ($chave === false || mb_strlen($chave, '8bit') !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('ROTINAS_CARGA_CHAVE_CRIPTO inválida: precisa ser base64 de exatamente 32 bytes.');
        }
        $this->chave = $chave;
    }

    public function criptografar(string $texto): string
    {
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cifrado = sodium_crypto_secretbox($texto, $nonce, $this->chave);
            return base64_encode($nonce . $cifrado);
        } catch (SodiumException $e) {
            throw new RuntimeException('Falha ao criptografar credencial: ' . $e->getMessage(), 0, $e);
        }
    }

    public function descriptografar(string $armazenado): string
    {
        $bin = base64_decode($armazenado, true);
        if ($bin === false || mb_strlen($bin, '8bit') <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Credencial armazenada em formato inválido.');
        }
        $nonce = mb_substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cifrado = mb_substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

        try {
            $texto = sodium_crypto_secretbox_open($cifrado, $nonce, $this->chave);
        } catch (SodiumException $e) {
            throw new RuntimeException('Falha ao descriptografar credencial: ' . $e->getMessage(), 0, $e);
        }
        if ($texto === false) {
            throw new RuntimeException('Credencial corrompida ou chave de criptografia incorreta.');
        }
        return $texto;
    }
}
