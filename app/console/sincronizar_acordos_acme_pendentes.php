<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Adapters\AcmeCredorAdapter;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;

require_once __DIR__ . '/_bootstrap.php';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

/** @return array{max:int,dry_run:bool,sleep_ms:int} */
function parseOptionsSincronizarAcordosAcme(array $argv): array
{
    $options = ['max' => 50, 'dry_run' => false, 'sleep_ms' => 400];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif (str_starts_with($arg, '--max=')) {
            $options['max'] = max(1, min(2000, (int) substr($arg, 6)));
        } elseif (str_starts_with($arg, '--sleep-ms=')) {
            $options['sleep_ms'] = max(0, min(10000, (int) substr($arg, 11)));
        } elseif ($arg === '-h' || $arg === '--help') {
            echo 'Uso: php console/sincronizar_acordos_acme_pendentes.php [--max=50] [--sleep-ms=400] [--dry-run]' . PHP_EOL;
            exit(0);
        }
    }
    return $options;
}

/**
 * Contratos ACME que chegaram na carga com status indicando acordo (ex.:
 * "Em Acordo") mas que ainda nao tem um acordo_operacional registrado
 * no CRM - situacoes vindas de fora do CRM (acordo fechado direto com a
 * ACME, ou anterior a virada pra este sistema). So esses, e so uma vez
 * (LEFT JOIN ... IS NULL) - pra nao bater na API pra contrato que ja
 * esta conciliado.
 * @return list<array<string,mixed>>
 */
function buscarContratosAcmeComAcordoPendente(PDO $pdo, int $max): array
{
    $stmt = $pdo->prepare("SELECT c.id, c.pessoa_id, c.codigo_contrato, c.credor_id, c.status_contrato,
            p.documento AS pessoa_documento
        FROM contratos c
        INNER JOIN pessoas p ON p.id = c.pessoa_id
        INNER JOIN credores cr ON cr.id = c.credor_id
        LEFT JOIN acordos_operacionais ao ON ao.contrato_id = c.id
            AND ao.status_operacional IN ('pendente', 'formalizado', 'cumprido')
        WHERE cr.codigo = 'ACME'
          AND c.status_contrato LIKE '%Acordo%'
          AND ao.id IS NULL
        ORDER BY c.id ASC
        LIMIT :max");
    $stmt->bindValue('max', $max, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pdo = Database::pdo();
$acordoRepository = new AcordoOperacionalRepository($pdo);
$adapter = new AcmeCredorAdapter();
$opcoes = parseOptionsSincronizarAcordosAcme($argv);

if (!$acordoRepository->moduloDisponivel()) {
    out('Módulo de acordos operacionais indisponível.');
    exit(1);
}

$pendentes = buscarContratosAcmeComAcordoPendente($pdo, $opcoes['max']);

out('============================================================');
out('SINCRONIZAR ACORDOS ACME PENDENTES - ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
out('============================================================');
out('Contratos ACME com status de acordo e sem registro no CRM: ' . count($pendentes) . ' (limite desta execução: ' . $opcoes['max'] . ')');
if ($opcoes['dry_run']) {
    out('Modo dry-run: nenhuma chamada à API ACME será feita.');
}
out('');

$totalRegistrado = 0;
$totalAtualizado = 0;
$totalSemAcordoDetalhado = 0;
$totalErro = 0;

foreach ($pendentes as $contrato) {
    $rotulo = 'Contrato #' . $contrato['id'] . ' (' . $contrato['codigo_contrato'] . ', status "' . $contrato['status_contrato'] . '")';

    if ($opcoes['dry_run']) {
        out($rotulo . ' -> seria consultado agora.');
        continue;
    }

    try {
        $resposta = $adapter->consultarAcordo((string) $contrato['pessoa_documento'], (string) $contrato['codigo_contrato']);
        $resultado = $acordoRepository->sincronizarAcordoAcme($contrato, $resposta);
        $status = (string) ($resultado['acordo_crm'] ?? '');

        if ($status === 'registrado') {
            $totalRegistrado++;
            out($rotulo . ' -> registrado no CRM (acordo #' . $resultado['acordo_crm_id'] . ', status ' . $resultado['status_operacional'] . ').');
        } elseif ($status === 'atualizado') {
            $totalAtualizado++;
            out($rotulo . ' -> já existia e foi atualizado (acordo #' . $resultado['acordo_crm_id'] . ').');
        } elseif (in_array($status, ['sem_acordo_retornado', 'sem_acordo_local_para_conciliar', 'nao_registrado_sem_valor'], true)) {
            $totalSemAcordoDetalhado++;
            out($rotulo . ' -> API ACME não retornou um acordo detalhado (' . $status . ').');
        } else {
            $totalErro++;
            out($rotulo . ' -> ' . $status . ' ' . (string) ($resultado['acordo_crm_erro'] ?? ''));
        }
    } catch (Throwable $e) {
        $totalErro++;
        out($rotulo . ' -> ERRO: ' . $e->getMessage());
    }

    if ($opcoes['sleep_ms'] > 0) {
        usleep($opcoes['sleep_ms'] * 1000);
    }
}

out('');
out('Registrados: ' . $totalRegistrado . ' | Atualizados: ' . $totalAtualizado . ' | Sem acordo detalhado: ' . $totalSemAcordoDetalhado . ' | Erro: ' . $totalErro);
exit($totalErro > 0 ? 1 : 0);
