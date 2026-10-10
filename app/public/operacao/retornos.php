<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('retornos.gerenciar');

$pageTitle = 'Agenda de Retornos';
$pageSubtitle = 'Fila controlada de próximos acionamentos registrados nos atendimentos dos clientes.';
$activePage = 'retornos';
$erro = null;
$mensagemSucesso = null;
$moduloDisponivel = false;
$resultadoPadronizadoDisponivel = false;
$retornoStatusDisponivel = false;
$resultadosAtendimento = [];
$resumo = [
    'total_pendentes' => 0,
    'vencidos' => 0,
    'hoje' => 0,
    'futuros' => 0,
    'tratados' => 0,
    'reagendados' => 0,
    'cancelados' => 0,
    'total_geral' => 0,
];
$retornos = [];
$filtros = [
    'status' => trim((string) ($_GET['status'] ?? 'pendente')),
    'situacao' => trim((string) ($_GET['situacao'] ?? 'todos')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'canal' => trim((string) ($_GET['canal'] ?? '')),
    'resultado' => trim((string) ($_GET['resultado'] ?? '')),
    'resultado_id' => trim((string) ($_GET['resultado_id'] ?? '')),
    'data_inicio' => trim((string) ($_GET['data_inicio'] ?? '')),
    'data_fim' => trim((string) ($_GET['data_fim'] ?? '')),
];

// Compatibilidade com links antigos da agenda: situacao=pendentes agora significa status pendente sem filtro de prazo.
if ($filtros['situacao'] === 'pendentes') {
    $filtros['situacao'] = 'todos';
    $filtros['status'] = 'pendente';
}

if (!array_key_exists($filtros['status'], AtendimentoRepository::RETORNO_STATUS) && $filtros['status'] !== 'todos') {
    $filtros['status'] = 'pendente';
}

if (!in_array($filtros['situacao'], ['todos', 'vencidos', 'hoje', 'futuros'], true)) {
    $filtros['situacao'] = 'todos';
}

try {
    $repository = new AtendimentoRepository(operacaoPdo());
    $escopoOperar = usuarioEscopoCredoresAcao('operar');
    $moduloDisponivel = $repository->moduloDisponivel();

    if ($moduloDisponivel) {
        $retornoStatusDisponivel = $repository->retornoStatusDisponivel();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            validarCsrf();
            $acao = (string) ($_POST['acao'] ?? '');
            $retornoId = isset($_POST['retorno_id']) && ctype_digit((string) $_POST['retorno_id']) ? (int) $_POST['retorno_id'] : 0;
            $observacaoRetorno = trim((string) ($_POST['retorno_observacao'] ?? ''));
            $redirect = (string) ($_POST['redirect'] ?? '/operacao/retornos.php');
            if (!str_starts_with($redirect, '/operacao/retornos.php')) {
                $redirect = '/operacao/retornos.php';
            }

            if ($acao === 'marcar_tratado') {
                if (!empty($_POST['pessoa_id']) && ctype_digit((string) $_POST['pessoa_id'])) {
                requirePessoaNoEscopo((int) $_POST['pessoa_id'], 'operar');
            }
            $repository->atualizarStatusRetorno($retornoId, 'tratado', $observacaoRetorno);
                header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_retorno=tratado');
                exit;
            }

            if ($acao === 'cancelar_retorno') {
                if (!empty($_POST['pessoa_id']) && ctype_digit((string) $_POST['pessoa_id'])) {
                requirePessoaNoEscopo((int) $_POST['pessoa_id'], 'operar');
            }
            $repository->atualizarStatusRetorno($retornoId, 'cancelado', $observacaoRetorno);
                header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'ok_retorno=cancelado');
                exit;
            }
        }

        $resultadoPadronizadoDisponivel = $repository->resultadoPadronizadoDisponivel();
        $resultadosAtendimento = $repository->listarResultadosAtivos();
        $resumo = $repository->resumoRetornos($escopoOperar);
        $retornos = $repository->listarRetornos($filtros, 120, $escopoOperar);
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

if (isset($_GET['ok_retorno'])) {
    $mensagemSucesso = match ((string) $_GET['ok_retorno']) {
        'tratado' => 'Retorno marcado como tratado.',
        'cancelado' => 'Retorno cancelado.',
        default => null,
    };
}

$urlAtual = '/operacao/retornos.php' . queryString($filtros);

http_response_code($erro === null ? 200 : 500);
require __DIR__ . '/includes/header.php';
?>
<main class="container works-module-page retornos-page">
    <section class="card compact works-module-hero">
        <div>
            <strong>Retornos Pactua</strong>
            <p class="muted">Agenda de retornos conectada ao atendimento novo. O botão principal sempre abre o contrato no Pactua, não na ficha técnica antiga.</p>
        </div>
        <div class="actions-row">
            <a class="button secondary" href="/operacao/works.php">Atendimento Pactua</a>
        </div>
    </section>

    <?php if ($mensagemSucesso !== null): ?>
        <section class="alert success"><?= h($mensagemSucesso) ?></section>
    <?php endif; ?>

    <?php if ($erro !== null): ?>
        <section class="card error">
            <strong>Erro ao carregar agenda de retornos:</strong> <?= h($erro) ?>
        </section>
    <?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error">
            <strong>Módulo de atendimento indisponível.</strong>
            Execute a migration <code>009_operacao_historico_atendimentos.sql</code> antes de usar esta tela.
        </section>
    <?php else: ?>
        <?php if (!$retornoStatusDisponivel): ?>
            <section class="alert danger">
                O controle formal de status dos retornos ainda não está ativo. Execute a migration <code>011_retorno_status_atendimentos.sql</code>. Até lá, retornos com <code>proximo_acionamento</code> preenchido continuam sendo tratados como pendentes para compatibilidade.
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Fila de retornos</h2>
                    <p>Por padrão a tela mostra apenas retornos pendentes. Retornos já tratados ou cancelados continuam no histórico e podem ser consultados pelos filtros.</p>
                </div>
                <?= badgeSoft($resultadoPadronizadoDisponivel ? 'tabulação padronizada' : 'compatibilidade legado') ?>
            </div>

            <div class="metrics-grid">
                <a class="metric metric-link<?= $filtros['status'] === 'pendente' && $filtros['situacao'] === 'todos' ? ' metric-selected' : '' ?>" href="/operacao/retornos.php?status=pendente&situacao=todos">
                    <small>Pendentes</small>
                    <strong><?= number_format($resumo['total_pendentes'], 0, ',', '.') ?></strong>
                </a>
                <a class="metric metric-link metric-danger<?= $filtros['status'] === 'pendente' && $filtros['situacao'] === 'vencidos' ? ' metric-selected' : '' ?>" href="/operacao/retornos.php?status=pendente&situacao=vencidos">
                    <small>Pendentes vencidos</small>
                    <strong><?= number_format($resumo['vencidos'], 0, ',', '.') ?></strong>
                </a>
                <a class="metric metric-link metric-warning<?= $filtros['status'] === 'pendente' && $filtros['situacao'] === 'hoje' ? ' metric-selected' : '' ?>" href="/operacao/retornos.php?status=pendente&situacao=hoje">
                    <small>Pendentes hoje</small>
                    <strong><?= number_format($resumo['hoje'], 0, ',', '.') ?></strong>
                </a>
                <a class="metric metric-link<?= $filtros['status'] === 'pendente' && $filtros['situacao'] === 'futuros' ? ' metric-selected' : '' ?>" href="/operacao/retornos.php?status=pendente&situacao=futuros">
                    <small>Pendentes futuros</small>
                    <strong><?= number_format($resumo['futuros'], 0, ',', '.') ?></strong>
                </a>
                <a class="metric metric-link<?= $filtros['status'] === 'tratado' ? ' metric-selected' : '' ?>" href="/operacao/retornos.php?status=tratado&situacao=todos">
                    <small>Tratados</small>
                    <strong><?= number_format($resumo['tratados'], 0, ',', '.') ?></strong>
                </a>
                <a class="metric metric-link<?= $filtros['status'] === 'cancelado' ? ' metric-selected' : '' ?>" href="/operacao/retornos.php?status=cancelado&situacao=todos">
                    <small>Cancelados</small>
                    <strong><?= number_format($resumo['cancelados'], 0, ',', '.') ?></strong>
                </a>
            </div>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Filtros</h2>
                    <p>Filtre por status do retorno, prazo, cliente, canal, resultado ou período do próximo acionamento.</p>
                </div>
            </div>

            <form class="form-card" method="get" action="/operacao/retornos.php">
                <div class="form-grid filters-grid">
                    <label class="field">
                        <span>Status</span>
                        <select name="status">
                            <option value="pendente"<?= $filtros['status'] === 'pendente' ? ' selected' : '' ?>>Pendente</option>
                            <option value="tratado"<?= $filtros['status'] === 'tratado' ? ' selected' : '' ?>>Tratado</option>
                            <option value="reagendado"<?= $filtros['status'] === 'reagendado' ? ' selected' : '' ?>>Reagendado</option>
                            <option value="cancelado"<?= $filtros['status'] === 'cancelado' ? ' selected' : '' ?>>Cancelado</option>
                            <option value="todos"<?= $filtros['status'] === 'todos' ? ' selected' : '' ?>>Todos</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Prazo</span>
                        <select name="situacao">
                            <option value="todos"<?= $filtros['situacao'] === 'todos' ? ' selected' : '' ?>>Todos os prazos</option>
                            <option value="vencidos"<?= $filtros['situacao'] === 'vencidos' ? ' selected' : '' ?>>Vencidos</option>
                            <option value="hoje"<?= $filtros['situacao'] === 'hoje' ? ' selected' : '' ?>>Hoje</option>
                            <option value="futuros"<?= $filtros['situacao'] === 'futuros' ? ' selected' : '' ?>>Futuros</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Cliente, documento, contrato ou telefone</span>
                        <input type="search" name="q" value="<?= h($filtros['q']) ?>" placeholder="Ex.: Maria, CPF, contrato, telefone">
                    </label>

                    <label class="field">
                        <span>Canal</span>
                        <select name="canal">
                            <option value="">Todos</option>
                            <?php foreach (AtendimentoRepository::CANAIS as $valor => $label): ?>
                                <option value="<?= h($valor) ?>"<?= $filtros['canal'] === $valor ? ' selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Resultado</span>
                        <?php if ($resultadoPadronizadoDisponivel): ?>
                            <select name="resultado_id">
                                <option value="">Todos</option>
                                <?php foreach ($resultadosAtendimento as $resultadoOpcao): ?>
                                    <option value="<?= (int) $resultadoOpcao['id'] ?>"<?= $filtros['resultado_id'] === (string) $resultadoOpcao['id'] ? ' selected' : '' ?>>
                                        <?= h((string) $resultadoOpcao['nome']) ?><?= !empty($resultadoOpcao['categoria']) ? ' / ' . h((string) $resultadoOpcao['categoria']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <select name="resultado">
                                <option value="">Todos</option>
                                <?php foreach (AtendimentoRepository::RESULTADOS as $valor => $label): ?>
                                    <option value="<?= h($valor) ?>"<?= $filtros['resultado'] === $valor ? ' selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </label>

                    <label class="field">
                        <span>Retorno a partir de</span>
                        <input type="date" name="data_inicio" value="<?= h($filtros['data_inicio']) ?>">
                    </label>

                    <label class="field">
                        <span>Retorno até</span>
                        <input type="date" name="data_fim" value="<?= h($filtros['data_fim']) ?>">
                    </label>

                    <div class="field filter-actions">
                        <span>Ações</span>
                        <div class="actions-row">
                            <button class="button" type="submit">Filtrar</button>
                            <a class="button secondary" href="/operacao/retornos.php">Limpar</a>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Retornos encontrados</h2>
                    <p>Use a ficha para registrar o atendimento. Se abrir a ficha a partir de um retorno, o formulário permite marcar o retorno origem como tratado.</p>
                </div>
                <span class="badge"><?= count($retornos) ?> registro(s)</span>
            </div>

            <?php if ($retornos === []): ?>
                <div class="empty">Nenhum retorno encontrado para os filtros atuais.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="retornos-table retornos-status-table">
                        <thead>
                        <tr>
                            <th>Prazo</th>
                            <th>Status</th>
                            <th>Situação</th>
                            <th>Cliente</th>
                            <th>Documento</th>
                            <th>Resultado</th>
                            <th>Canal</th>
                            <th>Contato</th>
                            <th>Contrato</th>
                            <th>Operador</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($retornos as $retorno): ?>
                            <?php
                            $status = (string) ($retorno['retorno_status'] ?? 'pendente');
                            $situacao = (string) ($retorno['retorno_situacao'] ?? 'pendente');
                            $situacaoClasse = match ($situacao) {
                                'vencido' => 'badge-danger',
                                'hoje' => 'badge-warning',
                                default => 'badge-soft',
                            };
                            $statusClasse = match ($status) {
                                'tratado' => 'badge-ok',
                                'cancelado' => 'badge-danger',
                                'reagendado' => 'badge-warning',
                                default => 'badge-soft',
                            };
                            $contratoLabel = trim((string) ($retorno['codigo_contrato'] ?? ''));
                            if ($contratoLabel !== '' && !empty($retorno['credor_nome'])) {
                                $contratoLabel .= ' / ' . (string) $retorno['credor_nome'];
                            }
                            $contatoUsado = $retorno['contato_utilizado'] ?: ($retorno['contato_valor'] ?? null);
                            $operador = trim((string) ($retorno['operador_nome'] ?? ''));
                            if ($operador === '') {
                                $operador = 'sistema/dev';
                            }
                            $fichaUrl = worksAtendimentoUrl(!empty($retorno['contrato_id']) ? (int) $retorno['contrato_id'] : null, (int) $retorno['pessoa_id'], 'acionamento', ['retorno_id' => (int) $retorno['id']]);
                            ?>
                            <tr class="retorno-row retorno-<?= h($situacao) ?> retorno-status-<?= h($status) ?>">
                                <td class="nowrap"><strong><?= h(dataHoraBr($retorno['proximo_acionamento'])) ?></strong></td>
                                <td><span class="badge <?= h($statusClasse) ?>"><?= h(AtendimentoRepository::retornoStatusLabel($status)) ?></span></td>
                                <td><span class="badge <?= h($situacaoClasse) ?>"><?= h(AtendimentoRepository::retornoSituacaoLabel($situacao)) ?></span></td>
                                <td><strong><?= h($retorno['pessoa_nome']) ?></strong></td>
                                <td class="nowrap"><?= documentoFormatado((string) $retorno['pessoa_documento']) ?></td>
                                <td>
                                    <?= badge(AtendimentoRepository::resultadoLabelRegistro($retorno)) ?>
                                    <?php if (!empty($retorno['resultado_categoria'])): ?><?= badgeSoft((string) $retorno['resultado_categoria']) ?><?php endif; ?>
                                </td>
                                <td><?= badge(AtendimentoRepository::canalLabel((string) $retorno['canal'])) ?></td>
                                <td><?= valorOuTraco($contatoUsado) ?></td>
                                <td><?= valorOuTraco($contratoLabel) ?></td>
                                <td><?= h($operador) ?></td>
                                <td class="retorno-actions-cell">
                                    <a class="button secondary table-button" href="<?= h($fichaUrl) ?>">Abrir no Pactua</a>
                                    <?php if ($retornoStatusDisponivel && $status === 'pendente'): ?>
                                        <form class="retorno-action-form" method="post" action="/operacao/retornos.php">
                                            <?= authCsrfInput() ?>
                                            <input type="hidden" name="retorno_id" value="<?= (int) $retorno['id'] ?>">
                                            <input type="hidden" name="pessoa_id" value="<?= (int) $retorno['pessoa_id'] ?>">
                                            <input type="hidden" name="redirect" value="<?= h($urlAtual) ?>">
                                            <textarea name="retorno_observacao" rows="2" maxlength="1000" placeholder="Observação rápida"></textarea>
                                            <div class="actions-row retorno-action-buttons">
                                                <button class="button table-button" type="submit" name="acao" value="marcar_tratado">Tratado</button>
                                                <button class="button secondary table-button" type="submit" name="acao" value="cancelar_retorno">Cancelar</button>
                                            </div>
                                        </form>
                                    <?php elseif (!empty($retorno['retorno_tratado_em']) || !empty($retorno['retorno_observacao'])): ?>
                                        <div class="muted retorno-status-note">
                                            <?php if (!empty($retorno['retorno_tratado_em'])): ?>
                                                Atualizado em <?= h(dataHoraBr($retorno['retorno_tratado_em'])) ?>.
                                            <?php endif; ?>
                                            <?php if (!empty($retorno['retorno_observacao'])): ?>
                                                <?= h((string) $retorno['retorno_observacao']) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($retorno['observacao'])): ?>
                                <tr class="observation-row">
                                    <td colspan="11"><strong>Observação do atendimento:</strong> <?= h($retorno['observacao']) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
