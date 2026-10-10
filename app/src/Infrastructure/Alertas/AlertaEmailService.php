<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Alertas;

use CRM\Config\Database;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Envia alertas por email quando um processo automatizado (carga diaria,
 * worker de longa duracao) falha sem ninguem olhando na hora - o mesmo
 * tipo de falha que ficou 19h sem ser notada em 2026-09-15
 * (integration-worker) e que so foi descoberta porque alguem checou
 * manualmente (disco cheio interrompendo a aplicacao da carga #28).
 *
 * De proposito best-effort: uma falha ao ENVIAR o alerta nunca deve
 * quebrar ou mascarar o erro original que gerou o alerta. Sem
 * SMTP_PASSWORD configurado (dev, ou antes do time de TI configurar o
 * servidor de email), o envio fica desativado silenciosamente.
 */
final class AlertaEmailService
{
    /** @param list<string> $destinatarios */
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromNome,
        private readonly array $destinatarios,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $host = Database::env('SMTP_HOST', '');
        $username = Database::env('SMTP_USERNAME', '');
        $password = Database::env('SMTP_PASSWORD', '');
        $from = Database::env('SMTP_FROM', '');
        $destinoRaw = Database::env('ALERTA_EMAIL_DESTINO', '');

        if ($host === '' || $username === '' || $password === '' || $from === '' || $destinoRaw === '') {
            return null;
        }

        $destinatarios = array_values(array_filter(array_map('trim', explode(',', $destinoRaw))));
        if ($destinatarios === []) {
            return null;
        }

        return new self(
            $host,
            (int) Database::env('SMTP_PORT', '587'),
            $username,
            $password,
            $from,
            (string) Database::env('SMTP_FROM_NOME', 'CRM Pactua - Alertas'),
            $destinatarios,
        );
    }

    /**
     * @return bool true se o email foi enviado (ou se os alertas estao
     *     desativados por falta de config - nesse caso nao ha nada
     *     errado, so nao ha o que fazer). false apenas quando havia
     *     config e o envio efetivamente falhou.
     */
    public static function alertarSeConfigurado(string $assunto, string $corpoTexto): bool
    {
        $service = self::fromEnv();
        if ($service === null) {
            return true;
        }

        return $service->enviar($assunto, $corpoTexto);
    }

    public function enviar(string $assunto, string $corpoTexto): bool
    {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 15;

            $mail->setFrom($this->from, $this->fromNome);
            foreach ($this->destinatarios as $destinatario) {
                $mail->addAddress($destinatario);
            }

            $mail->Subject = $assunto;
            $mail->isHTML(false);
            $mail->Body = $corpoTexto;

            $mail->send();
            return true;
        } catch (Throwable $e) {
            fwrite(STDERR, 'AVISO: falha ao enviar alerta por email: ' . $e->getMessage() . PHP_EOL);
            return false;
        }
    }
}
