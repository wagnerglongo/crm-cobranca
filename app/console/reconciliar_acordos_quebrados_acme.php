<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AcordoSincronizacaoRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Services\CredorAdapterFactory;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Remediação solicitada pela operação em 2026-09-23, depois de confirmar em
 * conferência manual que vários acordos marcados 'quebrado' no CRM já
 * tinham sido pagos/estavam ativos na ACME - porque até 2026-09-23 a quebra
 * automática por carência nunca validava com o credor ao vivo, só usava
 * regra local ou cache antigo (ver a validação de pagamento em
 * AcordoOperacionalRepository). Este script roda UMA VEZ (não é
 * cron) e reconcilia TODOS os acordos hoje 'quebrado' contra o status
 * real da ACME, usando o MESMO caminho já em produção
 * (buscarDetalhe -> adapter->consultarAcordo -> sincronizarAcordoAcme),
 * que já sabe "reviver" um acordo pra 'formalizado'/'cumprido' se a ACME
 * confirmar que ainda está ativo ou foi pago, e deixa 'quebrado' os que a
 * ACME confirmar que realmente devem ficar quebrados.
 *
 * Dry-run por padrão. Precisa de --confirmar pra gravar.
 */
function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

/** @return array{confirmar:bool,sleep_ms:int,max:int} */
function parseOptionsReconciliarQuebrados(array $argv): array
{
    $options = ['confirmar' => false, 'sleep_ms' => 400, 'max' => 5000];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--confirmar') {
            $options['confirmar'] = true;
        } elseif (str_starts_with($arg, '--sleep-ms=')) {
            $options['sleep_ms'] = max(0, min(10000, (int) substr($arg, 11)));
        } elseif (str_starts_with($arg, '--max=')) {
            $options['max'] = max(1, min(5000, (int) substr($arg, 6)));
        } elseif ($arg === '-h' || $arg === '--help') {
            echo 'Uso: php console/reconciliar_acordos_quebrados_acme.php [--confirmar] [--sleep-ms=400] [--max=5000]' . PHP_EOL;
            echo 'Sem --confirmar roda em modo dry-run (só consulta e mostra o que faria).' . PHP_EOL;
            exit(0);
        }
    }
    return $options;
}

$pdo = Database::pdo();
$acordos = new AcordoOperacionalRepository($pdo);
$opcoes = parseOptionsReconciliarQuebrados($argv);

if (!$acordos->moduloDisponivel()) {
    out('Módulo de acordos operacionais indisponível.');
    exit(1);
}

$stmt = $pdo->prepare("SELECT id FROM acordos_operacionais WHERE status_operacional = 'quebrado' ORDER BY id ASC LIMIT :max");
$stmt->bindValue('max', $opcoes['max'], PDO::PARAM_INT);
$stmt->execute();
$acordoIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

out('============================================================');
out('RECONCILIAR ACORDOS QUEBRADOS COM A ACME - ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
out('============================================================');
out('Acordos com status_operacional=quebrado encontrados: ' . count($acordoIds));
if (!$opcoes['confirmar']) {
    out('Modo dry-run: nenhuma gravação será feita (rode com --confirmar pra aplicar).');
}
out('');

if ($acordoIds === []) {
    exit(0);
}

$adapterFactory = new CredorAdapterFactory($pdo, new CredorConfiguracaoRepository($pdo));
$sincronizacaoRepository = new AcordoSincronizacaoRepository($pdo);
$atendimentoAdaptor = new AtendimentoAdaptorRepository($pdo);

$totalRevividoAtivo = 0;
$totalRevividoQuitado = 0;
$totalConfirmadoQuebrado = 0;
$totalSemAdapter = 0;
$totalSemRetorno = 0;
$totalErro = 0;

foreach ($acordoIds as $acordoId) {
    $detalhe = $acordos->buscarDetalhe($acordoId);
    if ($detalhe === null) {
        out("Acordo #{$acordoId} -> não encontrado, pulando.");
        continue;
    }

    $rotulo = "Acordo #{$acordoId} (contrato " . (string) ($detalhe['codigo_contrato'] ?? '?') . ', pessoa ' . (string) ($detalhe['pessoa_nome'] ?? '?') . ')';

    $adapter = $adapterFactory->paraContrato($detalhe);
    if ($adapter === null || !$adapter->configurado()) {
        $totalSemAdapter++;
        out($rotulo . ' -> sem adapter de validação ao vivo pra este credor, mantém quebrado.');
        continue;
    }

    if (!$opcoes['confirmar']) {
        out($rotulo . ' -> seria consultado agora.');
        continue;
    }

    try {
        $payload = $adapter->consultarAcordo(
            (string) ($detalhe['pessoa_documento'] ?? ''),
            (string) ($detalhe['codigo_contrato'] ?? '')
        );

        if ((string) $adapter->codigo() === 'ACME' && !empty($detalhe['contrato_id'])) {
            $atendimentoAdaptor->atualizarContratoAcordoAcme((int) $detalhe['contrato_id'], $payload);
        }

        $conciliacao = $acordos->sincronizarAcordoAcme($detalhe, $payload);
        $acordos->registrarValidacaoCredor($acordoId);

        $statusCrm = (string) ($conciliacao['acordo_crm'] ?? '');
        $statusNovo = (string) ($conciliacao['status_operacional'] ?? '');

        // Todo acordo processado por este script já ESTAVA 'quebrado' antes de
        // consultar a ACME. "Sem retorno detalhado" aqui é o resultado normal
        // e esperado pra acordo já encerrado (nada mudou, nada pra revisar) -
        // não é a mesma coisa que o cron diário achar isso num acordo que o
        // CRM ainda considera ativo. Por isso só marca "divergência" quando
        // algo realmente mudou de figura (revivido) ou deu erro de verdade.
        if (in_array($statusCrm, ['atualizado', 'registrado'], true)) {
            if ($statusNovo === 'quebrado') {
                $totalConfirmadoQuebrado++;
                $statusSincronizacao = 'ok';
                $mensagemSincronizacao = 'ACME confirma quebrado (' . (string) ($conciliacao['status_motivo'] ?? '') . '), mantém.';
                out($rotulo . ' -> ' . $mensagemSincronizacao);
            } elseif ($statusNovo === 'cumprido') {
                $totalRevividoQuitado++;
                $statusSincronizacao = 'divergencia';
                $mensagemSincronizacao = 'REVIVIDO: ACME diz que está QUITADO/PAGO. ' . (string) ($conciliacao['status_motivo'] ?? '');
                out($rotulo . ' -> ' . $mensagemSincronizacao);
            } else {
                $totalRevividoAtivo++;
                $statusSincronizacao = 'divergencia';
                $mensagemSincronizacao = "REVIVIDO: ACME diz que está ATIVO ({$statusNovo}). " . (string) ($conciliacao['status_motivo'] ?? '');
                out($rotulo . ' -> ' . $mensagemSincronizacao);
            }
        } elseif ($statusCrm === 'sem_acordo_retornado') {
            $totalSemRetorno++;
            $statusSincronizacao = 'ok';
            $mensagemSincronizacao = 'ACME não retornou dados detalhados deste acordo (normal para acordo antigo já encerrado); mantém quebrado sem alteração.';
            out($rotulo . ' -> ' . $mensagemSincronizacao);
        } else {
            $totalErro++;
            $statusSincronizacao = 'divergencia';
            $mensagemSincronizacao = $statusCrm . ' ' . (string) ($conciliacao['acordo_crm_erro'] ?? '');
            out($rotulo . ' -> ' . $mensagemSincronizacao);
        }

        $sincronizacaoRepository->registrar(
            $acordoId,
            !empty($detalhe['contrato_id']) ? (int) $detalhe['contrato_id'] : null,
            !empty($detalhe['credor_id']) ? (int) $detalhe['credor_id'] : null,
            $adapter->codigo(),
            $statusSincronizacao,
            'Reconciliação em massa dos acordos quebrados antes da validação ao vivo existir (requisito de 2026-09-23). '
                . $mensagemSincronizacao,
            $payload,
            null,
            'Reconciliação 2026-09-23'
        );
    } catch (Throwable $e) {
        $totalErro++;
        out($rotulo . ' -> ERRO: ' . $e->getMessage());
    }

    if ($opcoes['sleep_ms'] > 0) {
        usleep($opcoes['sleep_ms'] * 1000);
    }
}

out('');
out('============================================================');
out('Revividos p/ ativo (formalizado/pendente): ' . $totalRevividoAtivo);
out('Revividos p/ quitado (cumprido): ' . $totalRevividoQuitado);
out('Confirmados quebrados (mantidos): ' . $totalConfirmadoQuebrado);
out('Sem retorno detalhado da ACME (mantidos sem alteração): ' . $totalSemRetorno);
out('Sem adapter de validação: ' . $totalSemAdapter);
out('Erros: ' . $totalErro);
exit($totalErro > 0 ? 1 : 0);
