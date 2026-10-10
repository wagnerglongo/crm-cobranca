<?php

declare(strict_types=1);

use CRM\Infrastructure\Alertas\AlertaEmailService;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Ponte simples pra scripts bash (cron) dispararem o mesmo alerta por
 * email usado pelos scripts PHP, sem duplicar logica de SMTP. Uso:
 *   php console/alertar_falha_cron.php "assunto" "corpo"
 */
$assunto = (string) ($argv[1] ?? '[CRM] falha em rotina de cron');
$corpo = (string) ($argv[2] ?? '');

$alerta = AlertaEmailService::fromEnv();
if ($alerta === null) {
    fwrite(STDERR, "SMTP não configurado (ALERTA_EMAIL_DESTINO/SMTP_*) - alerta não enviado.\n");
    exit(0);
}

exit($alerta->enviar($assunto, $corpo) ? 0 : 1);
