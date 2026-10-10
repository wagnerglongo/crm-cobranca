<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Homologacao\CopiaBancoHomologacaoRepository;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Ponte do agendador do host (scripts/homologacao-agendador.sh) com a
 * agenda/pedidos da tela Administração > Homologação.
 *   php console/homologacao_copia.php reservar               -> imprime o id da cópia a fazer agora (ou nada)
 *   php console/homologacao_copia.php finalizar ID ok|erro   -> resumo pela entrada padrão
 */
if (Database::env('APP_ENV') === 'homolog') {
    fwrite(STDERR, "A cópia é controlada pela produção, não pela homologação.\n");
    exit(1);
}

$repo = new CopiaBancoHomologacaoRepository(Database::pdo());
if (!$repo->moduloDisponivel()) {
    fwrite(STDERR, "Tabelas da homologação não encontradas (migration 099).\n");
    exit(1);
}

$agora = new DateTimeImmutable('now');
switch ($argv[1] ?? '') {
    case 'reservar':
        $id = $repo->reservarProxima($agora);
        if ($id !== null) {
            echo $id, "\n";
        }
        exit(0);

    case 'finalizar':
        $id = ctype_digit((string) ($argv[2] ?? '')) ? (int) $argv[2] : 0;
        $resultado = (string) ($argv[3] ?? '');
        if ($id <= 0 || !in_array($resultado, ['ok', 'erro'], true)) {
            fwrite(STDERR, "uso: finalizar ID ok|erro\n");
            exit(2);
        }
        $repo->finalizar($id, $resultado === 'ok', (string) stream_get_contents(STDIN), $agora);
        exit(0);

    default:
        fwrite(STDERR, "uso: reservar | finalizar ID ok|erro\n");
        exit(2);
}
