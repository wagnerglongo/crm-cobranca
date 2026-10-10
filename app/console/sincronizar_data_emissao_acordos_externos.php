<?php

declare(strict_types=1);

use CRM\Config\Database;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Achado (2026-09-29): acordo "que veio de fora" (identificado e
 * conciliado automaticamente pela ACME via sincronizarAcordoAcme() - cron
 * atualizar_carteira_acordos.php ou o Financeiro ACME) tinha criado_em =
 * o momento em que o CRM ficou sabendo do acordo, não a data real em que
 * ele foi gerado na ACME (AgreementDate). Isso já foi corrigido pra frente
 * (AcordoOperacionalRepository::registrar() aceita criado_em opcional);
 * este script corrige os já existentes usando a mesma data que já está
 * guardada em payload_extra.acme_agreement_snapshot/acme_ultima_liquidacao_snapshot
 * (a resposta da ACME já foi salva ali desde que o acordo foi conciliado -
 * não precisa consultar a ACME de novo).
 *
 * Só mexe em acordo identificado/conciliado automaticamente pela ACME (marca
 * payload_extra.origem_externa_credor=true OU o texto fixo que
 * sincronizarAcordoAcme() grava em observacao - achado 2026-09-29: até essa
 * correção, o passo de UPDATE logo após criar o acordo reescrevia
 * payload_extra do zero e derrubava origem_externa_credor, então os já
 * existentes só dá pra achar pela observação). Acordo feito ao vivo no CRM
 * (Pactua, ficha do cliente) nunca tem nenhuma das duas marcas, então nunca
 * é tocado aqui, mesmo que tenha sido reconciliado com a ACME depois (o
 * criado_em dele já está certo: é a data real do fechamento).
 *
 * Uso: php console/sincronizar_data_emissao_acordos_externos.php [--dry-run] [--limit=5000]
 */

function out(string $mensagem = ''): void
{
    echo $mensagem . PHP_EOL;
}

$dryRun = in_array('--dry-run', $argv, true);
$limite = 5000;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limite = max(1, min(50000, (int) substr($arg, 8)));
    }
}

$pdo = Database::pdo();

$stmt = $pdo->prepare("SELECT id, criado_em, payload_extra
    FROM acordos_operacionais
    WHERE JSON_EXTRACT(payload_extra, '$.origem_externa_credor') = true
       OR observacao LIKE 'Acordo identificado e conciliado automaticamente pela API ACME%'
    ORDER BY id ASC
    LIMIT :limite");
$stmt->bindValue('limite', $limite, PDO::PARAM_INT);
$stmt->execute();
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

out('Acordos identificados/conciliados automaticamente pela ACME encontrados: ' . count($linhas));

$corrigidos = 0;
$semDataValida = 0;
$jaCertos = 0;
$update = $pdo->prepare('UPDATE acordos_operacionais SET criado_em = :criado_em WHERE id = :id LIMIT 1');

foreach ($linhas as $linha) {
    $payload = json_decode((string) $linha['payload_extra'], true);
    $payload = is_array($payload) ? $payload : [];
    $snapshot = $payload['acme_agreement_snapshot'] ?? $payload['acme_ultima_liquidacao_snapshot'] ?? null;
    $dataAcordoRaw = is_array($snapshot) ? trim((string) ($snapshot['data_acordo'] ?? '')) : '';

    if ($dataAcordoRaw === '') {
        $semDataValida++;
        continue;
    }

    try {
        $dataAcordo = new DateTimeImmutable($dataAcordoRaw);
    } catch (Throwable) {
        $semDataValida++;
        continue;
    }
    if ($dataAcordo > new DateTimeImmutable('now') || $dataAcordo->format('Y') < '2020') {
        $semDataValida++;
        continue;
    }

    $criadoEmAtual = new DateTimeImmutable((string) $linha['criado_em']);
    // Só corrige se o dia for diferente - não fica reescrevendo hora/minuto
    // à toa em acordos que já estão no dia certo (ex.: sincronizado no
    // mesmo dia em que foi gerado).
    if ($criadoEmAtual->format('Y-m-d') === $dataAcordo->format('Y-m-d')) {
        $jaCertos++;
        continue;
    }

    $novoValor = $dataAcordo->format('Y-m-d H:i:s');
    out(sprintf(
        'Acordo #%d: criado_em %s -> %s (AgreementDate da ACME)',
        (int) $linha['id'],
        $criadoEmAtual->format('Y-m-d H:i:s'),
        $novoValor
    ));

    if (!$dryRun) {
        $update->bindValue('criado_em', $novoValor);
        $update->bindValue('id', (int) $linha['id'], PDO::PARAM_INT);
        $update->execute();
    }
    $corrigidos++;
}

out('');
out(($dryRun ? '[DRY-RUN] ' : '') . 'Corrigidos: ' . $corrigidos);
out('Já estavam no dia certo: ' . $jaCertos);
out('Sem data válida no snapshot (não mexidos): ' . $semDataValida);
