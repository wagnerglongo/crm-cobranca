<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\IntegracaoEventoSaidaRepository;
use CRM\Modules\Operacao\Services\IntegracaoSaudeService;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('integracoes.visualizar');

$pageTitle = 'Infraestrutura e integrações';
$pageSubtitle = 'Saúde dos serviços internos e integrações externas do Pactua.';
$activePage = 'integracoes';

$service = new IntegracaoSaudeService();
$pdo = operacaoPdo();
$saidaRepository = new IntegracaoEventoSaidaRepository($pdo);
$escopoIntegracoesConsulta = usuarioEscopoCredoresAcao('consultar');
$escopoIntegracoesConfiguracao = usuarioEscopoCredoresAcao('configurar');
$credorIdsVisiveis = usuarioEscopoCredorIdsAcao('consultar');
$mensagemSucesso = null;
$mensagemErro = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!usuarioEhAdminOperacao()) {
            throw new RuntimeException('Somente um administrador pode reenviar eventos da fila de falhas.');
        }
        validarCsrf();
        $acao = trim((string) ($_POST['acao'] ?? ''));
        if ($acao === 'reprocessar_cdc_mortos') {
            $resultadoFila = $service->reprocessarFalhasCdc(500);
            registrarAuditoriaSistema(
                'infra.cdc_falhas_reprocessadas',
                'redis_queue',
                null,
                'Eventos da fila morta reenviados para reprocessamento.',
                null,
                $resultadoFila
            );
            $mensagemSucesso = sprintf(
                '%d evento(s) enviado(s) novamente para processamento%s.',
                (int) $resultadoFila['movidos'],
                (int) $resultadoFila['ignorados'] > 0 ? '; ' . (int) $resultadoFila['ignorados'] . ' registro(s) inválido(s) permaneceram para análise' : ''
            );
        }

        if ($acao === 'reprocessar_integracao_evento') {
            $eventoId = filter_var($_POST['evento_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            if ($eventoId <= 0) {
                throw new RuntimeException('Evento de integração inválido.');
            }
            $eventoAnterior = $saidaRepository->reprocessar($eventoId, $escopoIntegracoesConfiguracao);
            registrarAuditoriaSistema(
                'integracao.saida_reprocessada',
                'integracao_evento_saida',
                $eventoId,
                'Evento de saída reenviado manualmente para a fila.',
                [
                    'status' => $eventoAnterior['status'] ?? null,
                    'tentativas' => $eventoAnterior['tentativas'] ?? null,
                    'ultimo_http_status' => $eventoAnterior['ultimo_http_status'] ?? null,
                    'ultimo_erro' => $eventoAnterior['ultimo_erro'] ?? null,
                ],
                [
                    'status' => 'pendente',
                    'tentativas' => $eventoAnterior['tentativas'] ?? null,
                    'max_tentativas' => $eventoAnterior['novo_max_tentativas'] ?? null,
                ]
            );
            $mensagemSucesso = 'Evento #' . $eventoId . ' reenviado para processamento assíncrono.';
        }
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

$acmeCredorIdsStmt = $pdo->query("SELECT id FROM credores WHERE UPPER(codigo) IN ('ACME','ACMECREDOR','ACME_API')");
$acmeCredorIds = $acmeCredorIdsStmt !== false ? array_map('intval', $acmeCredorIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$podeVerAcme = $credorIdsVisiveis === null || array_intersect($credorIdsVisiveis, $acmeCredorIds) !== [];
$acme = $podeVerAcme ? $service->acme() : null;

$infraestrutura = $service->infraestrutura();
$filaCdc = ['pending' => 0, 'dead' => 0, 'mysql_disconnects' => 0, 'eventos' => []];
$filaSaida = ['pendentes' => 0, 'processando' => 0, 'retry' => 0, 'definitivos' => 0, 'enviados' => 0];
$eventosSaida = [];
try {
    $filaCdc = $service->filaCdc(12);
} catch (Throwable $e) {
    $mensagemErro ??= 'Não foi possível consultar os detalhes da fila CDC: ' . $e->getMessage();
}
try {
    $filaSaida = $saidaRepository->resumoFila($escopoIntegracoesConsulta);
    $eventosSaida = $saidaRepository->listarRecentes(80, $escopoIntegracoesConsulta);
} catch (Throwable $e) {
    $mensagemErro ??= 'Não foi possível consultar a fila de saídas dos credores: ' . $e->getMessage();
}
$infraOk = count(array_filter(
    $infraestrutura,
    static fn (array $item): bool => (bool) ($item['ok'] ?? false)
));
$infraTotal = count($infraestrutura);
$infraAtencao = max(0, $infraTotal - $infraOk);

function integracaoDataHora(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d/m/Y H:i:s');
    } catch (Throwable) {
        return $raw;
    }
}


/** @return array{label:string,class:string} */
function integracaoSaidaStatus(mixed $value): array
{
    return match ((string) $value) {
        'pendente' => ['label' => 'Pendente', 'class' => 'warning'],
        'processando' => ['label' => 'Processando', 'class' => 'info'],
        'erro_retry' => ['label' => 'Retry agendado', 'class' => 'warning'],
        'erro_definitivo' => ['label' => 'Erro definitivo', 'class' => 'danger'],
        'enviado' => ['label' => 'Enviado', 'class' => 'success'],
        'confirmado' => ['label' => 'Confirmado', 'class' => 'success'],
        'cancelado' => ['label' => 'Cancelado', 'class' => 'neutral'],
        default => ['label' => (string) $value !== '' ? (string) $value : 'Desconhecido', 'class' => 'neutral'],
    };
}

function integracaoDocumentoMascarado(mixed $value): string
{
    $documento = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
    if (strlen($documento) === 11) {
        return substr($documento, 0, 3) . '.***.***-' . substr($documento, -2);
    }
    if (strlen($documento) === 14) {
        return substr($documento, 0, 2) . '.***.***/****-' . substr($documento, -2);
    }
    return $documento !== '' ? $documento : '-';
}

require __DIR__ . '/../includes/header.php';
?>
<main class="main-content works-admin-clean config-page works-integrations-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>

    <?php if ($mensagemSucesso !== null): ?><div class="alert success"><?= h($mensagemSucesso) ?></div><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><div class="alert danger"><?= h($mensagemErro) ?></div><?php endif; ?>

    <header class="page-header works-page-header-clean">
        <div>
            <span class="eyebrow">Administração</span>
            <h1>Infraestrutura e integrações</h1>
            <p><?= $infraOk ?>/<?= $infraTotal ?> componentes operacionais · atualizado em <?= date('d/m/Y H:i:s') ?>.</p>
        </div>
        <div class="actions-row">
            <a class="button secondary" href="/operacao/configuracoes/integracoes.php">Atualizar diagnóstico</a>
            <a class="button secondary" href="/operacao/works.php">Call Center</a>
        </div>
    </header>

    <section class="config-summary-grid" aria-label="Resumo da infraestrutura">
        <article><small>Componentes</small><strong><?= $infraTotal ?></strong><span>serviços monitorados</span></article>
        <article class="is-success"><small>Operacionais</small><strong><?= $infraOk ?></strong><span>respondendo normalmente</span></article>
        <article class="<?= $infraAtencao > 0 ? 'is-warning' : 'is-success' ?>"><small>Precisam de atenção</small><strong><?= $infraAtencao ?></strong><span>verifique os diagnósticos</span></article>
        <article class="<?= ((int) $filaCdc['pending'] + (int) $filaCdc['dead']) > 0 ? 'is-warning' : 'is-info' ?>"><small>Fila CDC</small><strong><?= (int) $filaCdc['pending'] + (int) $filaCdc['dead'] ?></strong><span><?= (int) $filaCdc['pending'] ?> pendente(s) · <?= (int) $filaCdc['dead'] ?> em análise</span></article>
        <?php $totalSaidaAtiva = (int) $filaSaida['pendentes'] + (int) $filaSaida['processando'] + (int) $filaSaida['retry'] + (int) $filaSaida['definitivos']; ?>
        <article class="<?= ((int) $filaSaida['definitivos'] + (int) $filaSaida['retry']) > 0 ? 'is-warning' : 'is-info' ?>"><small>Saídas por credor</small><strong><?= $totalSaidaAtiva ?></strong><span><?= (int) $filaSaida['pendentes'] ?> pendente(s) · <?= (int) $filaSaida['definitivos'] ?> erro(s) definitivo(s)</span></article>
    </section>

    <section class="works-integration-overview works-infrastructure-grid">
        <?php foreach ($infraestrutura as $infra): ?>
            <article class="works-integration-card <?= $infra['ok'] ? 'ok' : 'warning' ?>">
                <div class="works-integration-card-head">
                    <div>
                        <small>Infraestrutura</small>
                        <h2><?= h($infra['nome']) ?></h2>
                    </div>
                    <span class="works-status-pill <?= $infra['ok'] ? 'success' : 'danger' ?>">
                        <?= $infra['ok'] ? 'Operacional' : 'Atenção' ?>
                    </span>
                </div>
                <div class="works-integration-facts">
                    <div><small>Uso</small><strong><?= h($infra['resumo']) ?></strong></div>
                    <div><small>Métrica</small><strong><?= h($infra['metrica']) ?></strong></div>
                </div>
                <div class="works-integration-last">
                    <div><small>Diagnóstico</small><strong><?= h($infra['detalhe']) ?></strong></div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ((int) $filaCdc['pending'] > 0 || (int) $filaCdc['dead'] > 0): ?>
        <section class="card works-cdc-diagnostic">
            <header class="works-section-heading">
                <div><small>Redis Queue</small><h2>Diagnóstico da fila CDC</h2><p>Eventos temporários e falhas conhecidas são recuperados pelo worker automaticamente. A fila de análise fica reservada para erros que realmente precisam de investigação.</p></div>
                <?php $cdcManual = max(0, (int) $filaCdc['dead'] - (int) ($filaCdc['auto_recoverable'] ?? 0)); ?>
                <?php if (usuarioEhAdminOperacao() && $cdcManual > 0): ?>
                    <form method="post" onsubmit="return confirm('Reenviar manualmente os eventos restantes da fila de análise?');">
                        <?= authCsrfInput() ?>
                        <input type="hidden" name="acao" value="reprocessar_cdc_mortos">
                        <button class="button secondary" type="submit">Reprocessar manualmente <?= $cdcManual ?></button>
                    </form>
                <?php endif; ?>
            </header>
            <?php if ((int) ($filaCdc['auto_recoverable'] ?? 0) > 0): ?>
                <div class="alert info">
                    <?= (int) $filaCdc['auto_recoverable'] ?> dos itens exibidos são recuperáveis automaticamente. O CDC Retry Worker verifica a fila de análise a cada minuto; não é necessário clicar em reprocessar.
                </div>
            <?php endif; ?>
            <?php if ((int) ($filaCdc['mysql_disconnects'] ?? 0) > 0): ?>
                <div class="alert warning">
                    <?= (int) $filaCdc['mysql_disconnects'] ?> dos itens exibidos registram perda antiga da conexão MySQL (2006/2013). Esses itens também entram na recuperação automática.
                </div>
            <?php endif; ?>
            <?php if ($filaCdc['eventos'] === []): ?>
                <div class="works-empty-state"><strong>Não há detalhes adicionais na fila de análise.</strong><span>O evento pendente será tratado pelo CDC Retry Worker.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table works-compact-table">
                        <thead><tr><th>Última tentativa</th><th>Tópico</th><th>Tentativas</th><th>Motivo</th></tr></thead>
                        <tbody>
                        <?php foreach ($filaCdc['eventos'] as $eventoFila): ?>
                            <tr>
                                <td><?= h(integracaoDataHora($eventoFila['last_attempt_at'] ?? null)) ?></td>
                                <td><code><?= h((string) ($eventoFila['topic'] ?? '-')) ?></code></td>
                                <td><?= (int) ($eventoFila['attempts'] ?? 0) ?></td>
                                <td><?= h((string) ($eventoFila['last_error'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card works-outbox-diagnostic">
        <header class="works-section-heading">
            <div>
                <small>Outbox transacional</small>
                <h2>Saídas por credor</h2>
                <p>O atendimento é salvo no MySQL e a entrega externa acontece no Integration Worker. Indisponibilidade da ACME não bloqueia a tela do operador.</p>
            </div>
            <div class="works-outbox-summary">
                <span><strong><?= (int) $filaSaida['pendentes'] ?></strong> pendentes</span>
                <span><strong><?= (int) $filaSaida['processando'] ?></strong> processando</span>
                <span><strong><?= (int) $filaSaida['retry'] ?></strong> em retry</span>
                <span><strong><?= (int) $filaSaida['definitivos'] ?></strong> definitivos</span>
                <span><strong><?= (int) $filaSaida['enviados'] ?></strong> enviados</span>
            </div>
        </header>

        <?php if (!$saidaRepository->moduloDisponivel()): ?>
            <div class="alert warning">Execute a migration <code>085_integracoes_acionamentos_credores.sql</code> para habilitar a fila de saída.</div>
        <?php elseif ($eventosSaida === []): ?>
            <div class="works-empty-state"><strong>Nenhum evento de saída registrado.</strong><span>Depois de associar uma tabulação a um código externo e registrar um atendimento, o evento aparecerá aqui.</span></div>
        <?php else: ?>
            <div class="works-outbox-list">
                <?php foreach ($eventosSaida as $eventoSaida): ?>
                    <?php $statusSaida = integracaoSaidaStatus($eventoSaida['status'] ?? null); ?>
                    <article class="works-outbox-card is-<?= h($statusSaida['class']) ?>">
                        <div class="works-outbox-card-head">
                            <div>
                                <small>#<?= (int) $eventoSaida['id'] ?> · <?= h((string) ($eventoSaida['credor_codigo'] ?? '-')) ?></small>
                                <h3><?= h((string) ($eventoSaida['integracao_nome'] ?? 'Integração')) ?></h3>
                                <span><?= h((string) ($eventoSaida['evento_tipo'] ?? '-')) ?> · <?= h(integracaoDataHora($eventoSaida['criado_em'] ?? null)) ?></span>
                            </div>
                            <span class="works-status-pill <?= h($statusSaida['class']) ?>"><?= h($statusSaida['label']) ?></span>
                        </div>
                        <div class="works-outbox-grid">
                            <div><small>Cliente</small><strong><?= h((string) ($eventoSaida['pessoa_nome'] ?? '-')) ?></strong><span><?= h(integracaoDocumentoMascarado($eventoSaida['documento'] ?? null)) ?></span></div>
                            <div><small>Contrato</small><strong><?= h((string) ($eventoSaida['codigo_contrato'] ?? '-')) ?></strong><span><?= h((string) ($eventoSaida['credor_nome'] ?? '-')) ?></span></div>
                            <?php if ((string) ($eventoSaida['evento_tipo'] ?? '') === 'contato.sincronizar'): ?>
                                <div><small>Contato enviado</small><strong><?= h((string) ($eventoSaida['contato_descricao'] ?? '-')) ?></strong><span><?= h((string) ($eventoSaida['contato_resultado'] ?? 'Aguardando envio')) ?></span></div>
                            <?php else: ?>
                                <div><small>Tabulação / de-para</small><strong><?= h((string) ($eventoSaida['resultado_nome'] ?? '-')) ?></strong><span><?= trim((string) ($eventoSaida['codigo_externo'] ?? '')) !== '' ? 'Código externo ' . h((string) $eventoSaida['codigo_externo']) : 'Sem código externo' ?></span></div>
                            <?php endif; ?>
                            <div><small>Tentativas</small><strong><?= (int) ($eventoSaida['tentativas'] ?? 0) ?>/<?= (int) ($eventoSaida['max_tentativas'] ?? 0) ?></strong><span>HTTP <?= $eventoSaida['ultimo_http_status'] !== null ? (int) $eventoSaida['ultimo_http_status'] : '-' ?></span></div>
                            <div><small>Operador</small><strong><?= h((string) ($eventoSaida['operador_nome'] ?? '-')) ?></strong><span><?= h((string) ($eventoSaida['tipo_transporte'] ?? '-')) ?> · <?= h((string) ($eventoSaida['adapter'] ?? '-')) ?></span></div>
                            <div><small>Conclusão / próxima tentativa</small><strong><?= h(integracaoDataHora($eventoSaida['enviado_em'] ?? null)) ?></strong><span><?= in_array((string) ($eventoSaida['status'] ?? ''), ['pendente', 'erro_retry'], true) ? h(integracaoDataHora($eventoSaida['disponivel_em'] ?? null)) : '—' ?></span></div>
                        </div>
                        <?php if (trim((string) ($eventoSaida['protocolo_externo'] ?? '')) !== ''): ?>
                            <p class="works-outbox-protocol"><strong>Protocolo externo:</strong> <?= h((string) $eventoSaida['protocolo_externo']) ?></p>
                        <?php endif; ?>
                        <?php if (trim((string) ($eventoSaida['ultimo_erro'] ?? '')) !== ''): ?>
                            <details class="works-outbox-error"><summary>Ver último erro</summary><pre><?= h((string) $eventoSaida['ultimo_erro']) ?></pre></details>
                        <?php endif; ?>
                        <div class="works-outbox-actions">
                            <?php if ((int) ($eventoSaida['contrato_id'] ?? 0) > 0): ?><a class="button secondary table-button" href="/operacao/works.php?contrato_id=<?= (int) $eventoSaida['contrato_id'] ?>">Abrir no Pactua</a><?php endif; ?>
                            <?php if (usuarioEhAdminOperacao() && in_array((string) ($eventoSaida['status'] ?? ''), ['erro_retry', 'erro_definitivo'], true)): ?>
                                <form method="post" onsubmit="return confirm('Reenviar este evento para uma nova tentativa?');">
                                    <?= authCsrfInput() ?>
                                    <input type="hidden" name="acao" value="reprocessar_integracao_evento">
                                    <input type="hidden" name="evento_id" value="<?= (int) $eventoSaida['id'] ?>">
                                    <button class="button secondary table-button" type="submit">Reprocessar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($acme !== null): ?>
    <section class="works-integration-overview">
        <article class="works-integration-card <?= $acme['configurado'] ? 'ok' : 'warning' ?>">
            <div class="works-integration-card-head">
                <div>
                    <small>Credor</small>
                    <h2>ACME</h2>
                </div>
                <span class="works-status-pill <?= $acme['configurado'] ? 'success' : 'warning' ?>">
                    <?= $acme['configurado'] ? 'Configurada' : 'Configuração incompleta' ?>
                </span>
            </div>
            <div class="works-integration-facts">
                <div><small>Base URL</small><strong><?= h((string) $acme['base_url']) ?></strong></div>
                <div><small>Timeout</small><strong><?= (int) $acme['timeout_segundos'] ?> s</strong></div>
                <div><small>Token em cache</small><strong><?= ($acme['token_cache']['valido'] ?? false) ? 'Válido' : (($acme['token_cache']['existe'] ?? false) ? 'Expirado' : 'Não gerado') ?></strong></div>
                <div><small>Expira em</small><strong><?= h(integracaoDataHora($acme['token_cache']['expira_em'] ?? null)) ?></strong></div>
                <div><small>Falhas 24h</small><strong><?= (int) $acme['falhas_24h'] ?></strong></div>
                <div><small>Latência média recente</small><strong><?= $acme['latencia_media_ms'] !== null ? (int) $acme['latencia_media_ms'] . ' ms' : '-' ?></strong></div>
            </div>
            <div class="works-integration-last">
                <div>
                    <small>Último sucesso</small>
                    <strong><?= h(integracaoDataHora($acme['ultimo_sucesso']['timestamp'] ?? null)) ?></strong>
                    <span><?= h((string) ($acme['ultimo_sucesso']['path'] ?? '')) ?></span>
                </div>
                <div>
                    <small>Última falha</small>
                    <strong><?= h(integracaoDataHora($acme['ultima_falha']['timestamp'] ?? null)) ?></strong>
                    <span><?= h((string) ($acme['ultima_falha']['mensagem'] ?? 'Sem falha registrada.')) ?></span>
                </div>
            </div>
        </article>
    </section>

    <section class="card works-integration-log">
        <header class="works-section-heading">
            <div><small>Diagnóstico</small><h2>Últimas chamadas ACME</h2></div>
            <span class="muted">Log estruturado sem bearer token</span>
        </header>
        <?php if (($acme['logs'] ?? []) === []): ?>
            <div class="works-empty-state"><strong>Ainda não há chamadas estruturadas registradas.</strong><span>Depois de aplicar este patch, as próximas chamadas ACME aparecerão aqui.</span></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table works-compact-table">
                    <thead><tr><th>Data/hora</th><th>Resultado</th><th>Método</th><th>Endpoint</th><th>HTTP</th><th>Tempo</th><th>Mensagem</th></tr></thead>
                    <tbody>
                    <?php foreach ($acme['logs'] as $log): ?>
                        <tr>
                            <td><?= h(integracaoDataHora($log['timestamp'] ?? null)) ?></td>
                            <td><span class="works-status-pill <?= ($log['resultado'] ?? '') === 'ok' ? 'success' : 'danger' ?>"><?= h((string) ($log['resultado'] ?? '-')) ?></span></td>
                            <td><?= h((string) ($log['method'] ?? '-')) ?></td>
                            <td><code><?= h((string) ($log['path'] ?? '-')) ?></code></td>
                            <td><?= h((string) ($log['status'] ?? '-')) ?></td>
                            <td><?= isset($log['duracao_ms']) ? (int) $log['duracao_ms'] . ' ms' : '-' ?></td>
                            <td><?= h((string) ($log['mensagem'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
