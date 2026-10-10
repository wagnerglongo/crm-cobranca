<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Alertas\AlertaEmailService;
use CRM\Infrastructure\Security\CredencialCryptoService;
use CRM\Modules\Cargas\Repositories\RotinaCargaSftpRepository;
use CRM\Modules\Cargas\Services\ExecutarRotinaCargaSftpService;

require_once __DIR__ . '/_bootstrap.php';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

/** @return array{rotina_id:?int,usuario_id:?int} */
function parseOptionsRotinasCarga(array $argv): array
{
    $rotinaId = null;
    $usuarioId = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--rotina-id=')) {
            $rotinaId = (int) substr($arg, strlen('--rotina-id='));
        } elseif (str_starts_with($arg, '--usuario-id=')) {
            $usuarioId = (int) substr($arg, strlen('--usuario-id='));
        }
    }
    return ['rotina_id' => $rotinaId, 'usuario_id' => $usuarioId];
}

$pdo = Database::pdo();
$repository = new RotinaCargaSftpRepository($pdo, new CredencialCryptoService());
$service = new ExecutarRotinaCargaSftpService($pdo, $repository, __DIR__, AlertaEmailService::fromEnv());

if (!$repository->moduloDisponivel()) {
    out('Módulo de rotinas de carga indisponível. Execute a migration 091_rotinas_carga_sftp.sql.');
    exit(0);
}

$opcoes = parseOptionsRotinasCarga($argv);

out('============================================================');
out('ROTINAS DE CARGA - ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
out('============================================================');

/**
 * --rotina-id=N roda UMA rotina especifica na hora, independente de
 * estar ativa ou do horario agendado - e' o que a tela usa pro botao
 * "Executar agora" (disparado em background via proc_open, pra nao
 * travar o navegador do operador esperando uma conexao externa que
 * pode demorar ou nunca responder).
 */
if ($opcoes['rotina_id'] !== null) {
    $id = $opcoes['rotina_id'];
    out('Rotina #' . $id . ' (execução manual)');
    $resultado = $service->executar($id, $opcoes['usuario_id']);
    out('  Status: ' . $resultado['status']);
    out('  ' . $resultado['mensagem']);
    exit($resultado['status'] === 'falha' ? 1 : 0);
}

$agora = new DateTimeImmutable('now');
$devidas = $repository->listarAtivasDevidas($agora);

if ($devidas === []) {
    out('Nenhuma rotina ativa devida neste horário.');
    exit(0);
}

$codigoSaida = 0;
foreach ($devidas as $rotina) {
    $id = (int) $rotina['id'];
    out('');
    out('Rotina #' . $id . ' - ' . (string) $rotina['nome'] . ' (' . (string) $rotina['credor_nome'] . ')');
    $resultado = $service->executar($id, null);
    out('  Status: ' . $resultado['status']);
    out('  ' . $resultado['mensagem']);
    if ($resultado['status'] === 'falha') {
        $codigoSaida = 1;
    }
}

out('');
out('Concluído.');
exit($codigoSaida);
