<?php

declare(strict_types=1);

namespace CRM\Modules\Cargas\Support;

use RuntimeException;

/**
 * Protecao de espaco em disco reutilizavel para rotinas de carga longas.
 *
 * Esta classe existe para que a APLICACAO (merge do snapshot/complementar) tenha
 * a mesma protecao entre lotes, evitando repetir o incidente em que o
 * filesystem chegou a 100% durante uma carga massiva.
 */
final class DiskSpaceGuard
{
    public static function freeSpaceMb(): int
    {
        $path = is_dir('/var/www/html') ? '/var/www/html' : '/';
        $bytes = @disk_free_space($path);
        if ($bytes === false) {
            return 0;
        }
        return (int) floor($bytes / 1024 / 1024);
    }

    /**
     * @throws RuntimeException quando o espaco livre cair abaixo do minimo.
     *     O lote atual ainda nao foi iniciado (a checagem roda antes do
     *     BEGIN de cada lote), entao os lotes ja commitados permanecem
     *     aplicados e a mesma execucao pode ser retomada depois de liberar
     *     espaco.
     */
    public static function assertFreeSpace(int $minFreeMb): void
    {
        if ($minFreeMb <= 0) {
            return;
        }

        $free = self::freeSpaceMb();
        if ($free > 0 && $free < $minFreeMb) {
            throw new RuntimeException(
                'Aplicacao interrompida preventivamente: apenas ' . $free
                . ' MB livres em disco; minimo configurado=' . $minFreeMb . ' MB. '
                . 'Os lotes ja commitados permanecem aplicados; libere espaco e reexecute '
                . 'o mesmo comando para retomar de onde parou.'
            );
        }
    }
}
