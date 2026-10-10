<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\CarteiraAcordosRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoAlgumaPermissao(['acordos.visualizar', 'acordos.carteira.visualizar']);

$pageTitle = 'Detalhe do Acordo';
$pageSubtitle = 'Parcelas, pagamentos, situação da carteira e histórico reversível de correções.';
$activePage = 'acordos_controle';

$acordoId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$erro = null;
$mensagemSucesso = null;
$moduloDisponivel = false;
$carteiraDisponivel = false;
$carteiraRepository = null;
$acordo = null;
$parcelas = [];
$integracoes = [];
$auditoria = [];
$movimentosCarteira = [];
$acompanhamento = null;
$resumoParcelas = [];
$acordoMetaAcme = [];
$podeAlterarStatusDetalhe = usuarioEhAdminOperacao() || usuarioPode('acordos.status') || usuarioPode('acordos.corrigir') || usuarioPode('acordos.gerenciar');
$podeIntegrarDetalhe = usuarioEhAdminOperacao() || usuarioPode('acordos.integracao') || usuarioPode('acordos.gerenciar');
$podeCorrigir = usuarioEhAdminOperacao() || usuarioPode('acordos.corrigir') || usuarioPode('acordos.carteira.editar') || usuarioPode('acordos.gerenciar') || usuarioPode('acordos.parcelas');
$podeDesfazer = usuarioEhAdminOperacao() || usuarioPode('acordos.desfazer') || usuarioPode('acordos.gerenciar');

try {
    if ($acordoId <= 0) {
        throw new RuntimeException('ID do acordo não informado ou inválido.');
    }

    $pdoOperacao = operacaoPdo();
    $repository = new AcordoOperacionalRepository($pdoOperacao);
    $carteiraRepository = new CarteiraAcordosRepository($pdoOperacao, $repository);
    $carteiraDisponivel = $carteiraRepository->moduloDisponivel();
    $escopoConsulta = usuarioEscopoCredoresAcao('consultar');
    $moduloDisponivel = $repository->moduloDisponivel();
    if (!$moduloDisponivel) {
        throw new RuntimeException('Módulo de acordos operacionais com parcelas indisponível. Execute as migrations 014 e 015.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = (string) ($_POST['acao'] ?? '');
        $observacao = trim((string) ($_POST['observacao_status'] ?? ''));

        if ($acao === 'atualizar_status_acordo') {
            if (!(usuarioEhAdminOperacao() || usuarioPode('acordos.status') || usuarioPode('acordos.corrigir') || usuarioPode('acordos.gerenciar'))) {
                throw new RuntimeException('Seu perfil não possui permissão para alterar o status do acordo.');
            }
            requireAcordoNoEscopo($acordoId, 'fechar_acordo');
            $statusAcordo = (string) ($_POST['status'] ?? '');
            $carteiraDisponivel
                ? $carteiraRepository->alterarStatusManual($acordoId, $statusAcordo, $observacao, usuarioAtualId(), usuarioAtualNome())
                : $repository->atualizarStatusOperacional($acordoId, $statusAcordo, $observacao);
            $eventoStatus = match ($statusAcordo) {
                'quebrado' => 'acordo.quebrado',
                'cancelado' => 'acordo.cancelado',
                default => 'acordo.status_atualizado',
            };
            registrarAuditoriaSistema(
                $eventoStatus,
                'acordo_operacional',
                $acordoId,
                'Status do acordo operacional atualizado pelo detalhe.',
                null,
                ['status' => $statusAcordo, 'observacao' => $observacao]
            );
            header('Location: /operacao/acordo_detalhe.php?id=' . $acordoId . '&ok_status=1');
            exit;
        }

        if ($acao === 'preparar_integracao') {
            requireOperacaoPermissao('acordos.integracao');
            requireAcordoNoEscopo($acordoId, 'fechar_acordo');
            $repository->marcarPendenteEnvioCredor($acordoId, $observacao ?: 'Acordo marcado para envio futuro ao credor. API ainda não implementada.');
            registrarAuditoriaSistema(
                'acordo.integracao_preparada',
                'acordo_operacional',
                $acordoId,
                'Acordo preparado para envio futuro ao credor pelo detalhe.',
                null,
                ['status_integracao' => 'pendente_envio', 'observacao' => $observacao]
            );
            header('Location: /operacao/acordo_detalhe.php?id=' . $acordoId . '&ok_integracao=1');
            exit;
        }

        if ($acao === 'atualizar_status_parcela') {
            if (!$podeCorrigir) {
                throw new RuntimeException('Seu perfil não possui permissão para corrigir parcelas.');
            }
            requireAcordoNoEscopo($acordoId, 'fechar_acordo');
            $parcelaId = isset($_POST['parcela_id']) && ctype_digit((string) $_POST['parcela_id']) ? (int) $_POST['parcela_id'] : 0;
            $statusParcela = (string) ($_POST['status_parcela'] ?? '');
            if ($carteiraDisponivel) {
                $carteiraRepository->corrigirParcelaManual(
                    $acordoId,
                    $parcelaId,
                    $statusParcela,
                    $_POST['data_pagamento'] ?? null,
                    $_POST['valor_pago'] ?? null,
                    $observacao,
                    usuarioAtualId(),
                    usuarioAtualNome()
                );
            } else {
                $repository->atualizarStatusParcela(
                    $acordoId,
                    $parcelaId,
                    $statusParcela,
                    $_POST['data_pagamento'] ?? null,
                    $_POST['valor_pago'] ?? null,
                    $observacao
                );
            }
            registrarAuditoriaSistema(
                $statusParcela === 'paga' ? 'acordo.parcela_paga' : 'acordo.parcela_atualizada',
                'acordo_operacional_parcela',
                $parcelaId,
                $statusParcela === 'paga' ? 'Parcela marcada como paga.' : 'Status da parcela do acordo atualizado.',
                null,
                [
                    'acordo_id' => $acordoId,
                    'parcela_id' => $parcelaId,
                    'status' => $statusParcela,
                    'data_pagamento' => $_POST['data_pagamento'] ?? null,
                    'valor_pago' => $_POST['valor_pago'] ?? null,
                    'observacao' => $observacao,
                ]
            );
            header('Location: /operacao/acordo_detalhe.php?id=' . $acordoId . '&ok_parcela=1#parcelas');
            exit;
        }

        if ($acao === 'desfazer_movimento') {
            if (!$podeDesfazer || !$carteiraDisponivel) {
                throw new RuntimeException('Seu perfil não possui permissão para desfazer correções.');
            }
            requireAcordoNoEscopo($acordoId, 'fechar_acordo');
            $movimentoId = isset($_POST['movimento_id']) && ctype_digit((string) $_POST['movimento_id']) ? (int) $_POST['movimento_id'] : 0;
            $carteiraRepository->desfazerMovimento($movimentoId, usuarioAtualId(), usuarioAtualNome(), $acordoId);
            registrarAuditoriaSistema('acordo.movimento_desfeito', 'acordo_operacional', $acordoId, 'Correção manual desfeita pela carteira.', null, ['movimento_id' => $movimentoId]);
            header('Location: /operacao/acordo_detalhe.php?id=' . $acordoId . '&ok_desfazer=1#movimentos');
            exit;
        }

        if ($acao === 'desfazer_cadastro_manual') {
            if (!$podeDesfazer || !$carteiraDisponivel) {
                throw new RuntimeException('Seu perfil não possui permissão para desfazer cadastro manual.');
            }
            requireAcordoNoEscopo($acordoId, 'fechar_acordo');
            $carteiraRepository->desfazerCadastroManual($acordoId, $observacao, usuarioAtualId(), usuarioAtualNome());
            header('Location: /operacao/acordo_detalhe.php?id=' . $acordoId . '&ok_cadastro_desfeito=1');
            exit;
        }
    }

    $acordo = $repository->buscarDetalhe($acordoId, $escopoConsulta);
    if ($acordo === null) {
        http_response_code(404);
        throw new RuntimeException('Acordo operacional não encontrado.');
    }
    $payloadAcordo = json_decode((string) ($acordo['payload_extra'] ?? ''), true);
    $acordoMetaAcme = is_array($payloadAcordo) ? $payloadAcordo : [];

    $parcelas = $repository->listarParcelas($acordoId);
    $resumoParcelas = $repository->resumoParcelas($acordoId);
    $integracoes = $repository->listarIntegracoes($acordoId);
    $auditoria = $repository->listarAuditoria($acordoId, 120);
    if ($carteiraDisponivel) {
        $carteiraRepository->atualizarCarteiraGeral(1, $acordoId);
        $acompanhamento = $carteiraRepository->acompanhamento($acordoId);
        $movimentosCarteira = $carteiraRepository->listarMovimentos($acordoId, 150);
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

if (isset($_GET['ok_status'])) {
    $mensagemSucesso = 'Status do acordo atualizado.';
}
if (isset($_GET['ok_integracao'])) {
    $mensagemSucesso = 'Acordo marcado como pendente de envio ao credor. Nenhuma API foi chamada.';
}
if (isset($_GET['ok_parcela'])) {
    $mensagemSucesso = 'Parcela atualizada. O status geral do acordo pode ter sido recalculado automaticamente.';
}
if (isset($_GET['ok_desfazer'])) {
    $mensagemSucesso = 'Correção desfeita e carteira recalculada.';
}
if (isset($_GET['ok_cadastro_desfeito'])) {
    $mensagemSucesso = 'Cadastro manual desfeito sem apagar o histórico.';
}
if (isset($_GET['ok_manual'])) {
    $mensagemSucesso = 'Acordo manual cadastrado e incluído na carteira.';
}

http_response_code($erro === null ? 200 : ($acordo === null ? 404 : 500));
require __DIR__ . '/includes/header.php';
?>
<main class="container">
    <?php if ($mensagemSucesso !== null): ?>
        <section class="alert success"><?= h($mensagemSucesso) ?></section>
    <?php endif; ?>

    <?php if ($erro !== null): ?>
        <section class="card error">
            <strong>Erro ao carregar acordo:</strong> <?= h($erro) ?>
        </section>
        <div class="actions-row">
            <a class="button secondary" href="/operacao/acordos.php">Voltar para acordos</a>
        </div>
    <?php elseif ($acordo !== null): ?>
        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Acordo #<?= (int) $acordo['id'] ?></h2>
                    <p><?= h((string) $acordo['pessoa_nome']) ?> · <?= documentoFormatado((string) $acordo['pessoa_documento']) ?></p>
                </div>
                <div class="actions-row">
                    <a class="button secondary" href="<?= h(worksAtendimentoUrl(!empty($acordo['contrato_id']) ? (int) $acordo['contrato_id'] : null, (int) $acordo['pessoa_id'], 'financeiro')) ?>">Abrir no Pactua</a>
                    <a class="button secondary" href="/operacao/acordos.php">Voltar</a>
                </div>
            </div>

            <div class="metrics-grid metrics-grid-wide">
                <div class="metric">
                    <small>Status</small>
                    <strong><?= h(AcordoOperacionalRepository::statusLabel((string) $acordo['status_operacional'])) ?></strong>
                </div>
                <div class="metric">
                    <small>Integração</small>
                    <strong><?= h(AcordoOperacionalRepository::integracaoLabel((string) $acordo['status_integracao'])) ?></strong>
                </div>
                <div class="metric">
                    <small>Valor total</small>
                    <strong><?= h(dinheiro($acordo['valor_acordo'])) ?></strong>
                </div>
                <div class="metric">
                    <small>Entrada</small>
                    <strong><?= h(dinheiro($acordo['valor_entrada'])) ?></strong>
                </div>
                <div class="metric">
                    <small>Parcelas</small>
                    <strong><?= (int) ($acordo['quantidade_parcelas'] ?? 1) ?>x</strong>
                </div>
                <div class="metric">
                    <small>Forma</small>
                    <strong><?= h(AcordoOperacionalRepository::formaPagamentoLabel((string) ($acordo['forma_pagamento'] ?? 'nao_informado'))) ?></strong>
                </div>
                <div class="metric">
                    <small>Situação na carteira</small>
                    <strong><?= is_array($acompanhamento) ? h(CarteiraAcordosRepository::situacaoLabel((string) $acompanhamento['situacao_carteira'])) : 'Não calculada' ?></strong>
                </div>
                <div class="metric">
                    <small>Próxima ação</small>
                    <strong><?= is_array($acompanhamento) ? h((string) ($acompanhamento['proxima_acao'] ?? '-')) : '-' ?></strong>
                </div>
                <?php if (mb_strtoupper((string) ($acordoMetaAcme['credor_api'] ?? '')) === 'ACME'): ?>
                <div class="metric">
                    <small>Status da dívida na ACME</small>
                    <strong><?= h((string) ($acordoMetaAcme['debt_status'] ?? '-')) ?></strong>
                </div>
                <div class="metric">
                    <small>Conciliação ACME</small>
                    <strong><?= h((string) ($acordoMetaAcme['status_conciliado'] ?? '-')) ?></strong>
                    <span><?= h((string) ($acordoMetaAcme['status_conciliado_motivo'] ?? '')) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card detail-grid-card">
            <div class="detail-grid">
                <div>
                    <span class="muted">Contrato</span>
                    <strong><?= !empty($acordo['codigo_contrato']) ? h((string) $acordo['codigo_contrato']) : '-' ?></strong>
                    <?php if (!empty($acordo['produto'])): ?><small><?= h((string) $acordo['produto']) ?></small><?php endif; ?>
                </div>
                <div>
                    <span class="muted">Credor / Carteira</span>
                    <strong><?= !empty($acordo['credor_nome']) ? h((string) $acordo['credor_nome']) : '-' ?></strong>
                    <?php if (!empty($acordo['carteira_nome'])): ?><small><?= h((string) $acordo['carteira_nome']) ?></small><?php endif; ?>
                </div>
                <div>
                    <span class="muted">Data da entrada</span>
                    <strong><?= h(dataBr($acordo['data_entrada'])) ?></strong>
                </div>
                <div>
                    <span class="muted">Primeira parcela</span>
                    <strong><?= h(dataBr($acordo['data_primeira_parcela'])) ?></strong>
                </div>
                <div>
                    <span class="muted">Periodicidade</span>
                    <strong><?= h(AcordoOperacionalRepository::periodicidadeLabel((string) ($acordo['periodicidade'] ?? 'mensal'))) ?></strong>
                </div>
                <div>
                    <span class="muted">Origem</span>
                    <strong><?= h(AcordoOperacionalRepository::origemLabel((string) $acordo['origem'])) ?></strong>
                </div>
                <div>
                    <span class="muted">Operador</span>
                    <strong><?= valorOuTraco($acordo['operador_nome']) ?></strong>
                </div>
                <div>
                    <span class="muted">Criado em</span>
                    <strong><?= h(dataHoraBr($acordo['criado_em'])) ?></strong>
                </div>
            </div>

            <?php if (!empty($acordo['observacao'])): ?>
                <div class="placeholder-card">
                    <strong>Observação</strong>
                    <p><?= nl2br(h($acordo['observacao'])) ?></p>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Resumo das parcelas</h2>
                    <p>Valores previstos, pagos, em aberto e situação operacional do acordo.</p>
                </div>
                <?= badgeSoft('controle operacional') ?>
            </div>
            <div class="metrics-grid metrics-grid-wide">
                <div class="metric">
                    <small>Previsto</small>
                    <strong><?= h(dinheiro($resumoParcelas['valor_previsto'] ?? 0)) ?></strong>
                </div>
                <div class="metric">
                    <small>Pago</small>
                    <strong><?= h(dinheiro($resumoParcelas['valor_pago'] ?? 0)) ?></strong>
                </div>
                <div class="metric">
                    <small>Em aberto</small>
                    <strong><?= h(dinheiro($resumoParcelas['valor_aberto'] ?? 0)) ?></strong>
                </div>
                <div class="metric">
                    <small>Pagas</small>
                    <strong><?= (int) ($resumoParcelas['pagas'] ?? 0) ?> / <?= (int) ($resumoParcelas['total'] ?? 0) ?></strong>
                </div>
                <div class="metric metric-danger">
                    <small>Vencidas</small>
                    <strong><?= (int) ($resumoParcelas['vencidas'] ?? 0) ?></strong>
                </div>
                <div class="metric">
                    <small>Próximo vencimento</small>
                    <strong><?= h(dataBr($resumoParcelas['proximo_vencimento'] ?? null)) ?></strong>
                </div>
            </div>
        </section>

        <section id="parcelas" class="card">
            <div class="section-title">
                <div>
                    <h2>Parcelas do acordo</h2>
                    <p>Controle operacional das parcelas previstas. Agora é possível registrar pagamento manual, vencimento, cancelamento ou renegociação da parcela.</p>
                </div>
                <?= badgeSoft((string) count($parcelas) . ' item(ns)') ?>
            </div>

            <?php if ($parcelas === []): ?>
                <div class="empty">Nenhuma parcela encontrada para este acordo.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="acordo-parcelas-table">
                        <thead>
                        <tr>
                            <th>Nº</th>
                            <th>Tipo</th>
                            <th>Vencimento</th>
                            <th>Prazo de quebra ACME</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Boleto / Linha digitável</th>
                            <th>Pagamento</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($parcelas as $parcela): ?>
                            <?php
                            $parcelaVencidaPorData = ((string) $parcela['status'] === 'pendente') && !empty($parcela['data_vencimento']) && (string) $parcela['data_vencimento'] < date('Y-m-d');
                            $deadlineAcme = AcordoOperacionalRepository::deadlineCredorParcela($parcela);
                            ?>
                            <tr class="parcela-status-<?= h((string) $parcela['status']) ?><?= $parcelaVencidaPorData ? ' parcela-vencida-por-data' : '' ?>">
                                <td><?= (int) $parcela['numero_parcela'] === 0 ? 'Entrada' : '#' . (int) $parcela['numero_parcela'] ?></td>
                                <td><?= badgeSoft((string) $parcela['tipo']) ?></td>
                                <td><?= h(dataBr($parcela['data_vencimento'])) ?></td>
                                <td><?= $deadlineAcme !== null ? h(dataBr($deadlineAcme)) : '<span class="muted">Regra local</span>' ?></td>
                                <td><strong><?= h(dinheiro($parcela['valor_parcela'])) ?></strong></td>
                                <td>
                                    <?= badge(AcordoOperacionalRepository::statusParcelaLabel((string) $parcela['status'])) ?>
                                    <?php if ($parcelaVencidaPorData): ?><small class="danger-text">Vencida por data</small><?php endif; ?>
                                </td>
                                <td>
                                    <?= valorOuTraco($parcela['codigo_boleto']) ?>
                                    <?php if (!empty($parcela['linha_digitavel'])): ?><small><?= h((string) $parcela['linha_digitavel']) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($parcela['data_pagamento'])): ?>
                                        <?= h(dataBr($parcela['data_pagamento'])) ?> · <?= h(dinheiro($parcela['valor_pago'])) ?>
                                    <?php else: ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="acordo-actions-cell">
                                    <?php if ($podeCorrigir): ?>
                                    <form class="inline-form stacked" method="post" action="/operacao/acordo_detalhe.php?id=<?= (int) $acordo['id'] ?>#parcelas"><?= authCsrfInput() ?>
                                        <input type="hidden" name="acao" value="atualizar_status_parcela">
                                        <input type="hidden" name="parcela_id" value="<?= (int) $parcela['id'] ?>">
                                        <select name="status_parcela" aria-label="Status da parcela">
                                            <?php foreach (AcordoOperacionalRepository::STATUS_PARCELA as $valor => $label): ?>
                                                <option value="<?= h($valor) ?>" <?= ((string) $parcela['status'] === $valor) ? 'selected' : '' ?>><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="date" name="data_pagamento" value="<?= h((string) ($parcela['data_pagamento'] ?? '')) ?>" aria-label="Data de pagamento">
                                        <input type="text" name="valor_pago" value="<?= !empty($parcela['valor_pago']) ? h(number_format((float) $parcela['valor_pago'], 2, ',', '.')) : '' ?>" placeholder="Valor pago" inputmode="decimal">
                                        <input type="text" name="observacao_status" maxlength="2000" placeholder="Observação opcional">
                                        <button class="button secondary table-button" type="submit">Atualizar parcela</button>
                                    </form>
                                    <?php else: ?><span class="muted">Somente leitura</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($carteiraDisponivel): ?>
        <section id="movimentos" class="card works-agreement-movements">
            <div class="section-title">
                <div>
                    <h2>Histórico de correções da carteira</h2>
                    <p>Cadastros e correções manuais são auditados. Movimentos reversíveis podem ser desfeitos sem excluir registros.</p>
                </div>
                <?= badgeSoft((string) count($movimentosCarteira) . ' movimento(s)') ?>
            </div>
            <?php if ($movimentosCarteira === []): ?><div class="empty">Nenhuma correção manual registrada.</div><?php else: ?>
                <div class="works-movement-list">
                    <?php foreach ($movimentosCarteira as $movimento): ?>
                        <article class="works-movement-item<?= !empty($movimento['desfeito_em']) ? ' is-undone' : '' ?>">
                            <div><strong><?= h(str_replace('_', ' ', (string) $movimento['acao'])) ?></strong><span><?= h(dataHoraBr($movimento['criado_em'])) ?> · <?= h((string) ($movimento['usuario_nome'] ?: 'Sistema')) ?></span><?php if (!empty($movimento['observacao'])): ?><small><?= h((string) $movimento['observacao']) ?></small><?php endif; ?></div>
                            <div><?php if (!empty($movimento['desfeito_em'])): ?><span class="badge soft">Desfeito em <?= h(dataHoraBr($movimento['desfeito_em'])) ?></span><?php elseif ($podeDesfazer && (int) ($movimento['reversivel'] ?? 0) === 1): ?><form method="post" action="/operacao/acordo_detalhe.php?id=<?= (int) $acordo['id'] ?>#movimentos" onsubmit="return confirm('Desfazer esta correção?');"><?= authCsrfInput() ?><input type="hidden" name="acao" value="desfazer_movimento"><input type="hidden" name="movimento_id" value="<?= (int) $movimento['id'] ?>"><button class="button secondary table-button" type="submit">Desfazer</button></form><?php endif; ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Ações do acordo</h2>
                    <p>Atualize o status geral ou marque o acordo para futura fila de envio ao credor.</p>
                </div>
            </div>
            <div class="split-actions-grid">
                <?php if ($podeAlterarStatusDetalhe): ?>
                <form class="form-card" method="post" action="/operacao/acordo_detalhe.php?id=<?= (int) $acordo['id'] ?>"><?= authCsrfInput() ?>
                    <input type="hidden" name="acao" value="atualizar_status_acordo">
                    <label class="field">
                        <span>Novo status do acordo</span>
                        <select name="status">
                            <?php foreach (AcordoOperacionalRepository::STATUS_OPERACIONAL as $valor => $label): ?>
                                <option value="<?= h($valor) ?>" <?= ((string) $acordo['status_operacional'] === $valor) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Observação</span>
                        <textarea name="observacao_status" rows="3" maxlength="2000" placeholder="Observação opcional"></textarea>
                    </label>
                    <button class="button" type="submit">Atualizar status geral</button>
                </form>
                <?php else: ?><div class="placeholder-card muted">Seu perfil possui acesso somente de leitura ao status geral.</div><?php endif; ?>

                <?php if ($podeIntegrarDetalhe && ($acordo['status_integracao'] ?? '') === 'nao_enviado'): ?>
                    <form class="form-card" method="post" action="/operacao/acordo_detalhe.php?id=<?= (int) $acordo['id'] ?>"><?= authCsrfInput() ?>
                        <input type="hidden" name="acao" value="preparar_integracao">
                        <label class="field">
                            <span>Preparar envio ao credor</span>
                            <textarea name="observacao_status" rows="3" maxlength="2000" placeholder="Observação para a futura integração">Marcado para envio futuro ao credor. API ainda não implementada.</textarea>
                        </label>
                        <button class="button secondary" type="submit">Preparar envio credor</button>
                        <span class="muted">Esta ação só cria o registro técnico de integração. Nenhuma API externa é chamada.</span>
                    </form>
                <?php elseif ($podeIntegrarDetalhe): ?>
                    <div class="placeholder-card muted">Este acordo já está com status de integração: <?= h(AcordoOperacionalRepository::integracaoLabel((string) $acordo['status_integracao'])) ?>.</div>
                <?php else: ?>
                    <div class="placeholder-card muted">Integração disponível somente para perfis autorizados.</div>
                <?php endif; ?>
            </div>
            <?php if ($podeDesfazer && $carteiraDisponivel && $carteiraRepository->acordoEhManual((int) $acordo['id']) && !in_array((string) $acordo['status_operacional'], ['cancelado','cumprido'], true)): ?>
                <form class="form-card danger-zone" method="post" action="/operacao/acordo_detalhe.php?id=<?= (int) $acordo['id'] ?>" onsubmit="return confirm('Desfazer o cadastro manual? O registro não será apagado.');">
                    <?= authCsrfInput() ?>
                    <input type="hidden" name="acao" value="desfazer_cadastro_manual">
                    <label class="field"><span>Motivo da correção</span><input type="text" name="observacao_status" required maxlength="500"></label>
                    <button class="button danger" type="submit">Desfazer cadastro manual</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Auditoria do acordo</h2>
                    <p>Registro operacional das principais mudanças: criação, status geral, parcelas, recálculo automático e preparação de integração.</p>
                </div>
                <?= badgeSoft((string) count($auditoria) . ' evento(s)') ?>
            </div>
            <?php if ($auditoria === []): ?>
                <div class="empty">Nenhum evento de auditoria encontrado. Execute a migration 018 para habilitar a trilha histórica.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="auditoria-acordos-table">
                        <thead>
                        <tr>
                            <th>Data/hora</th>
                            <th>Evento</th>
                            <th>Parcela</th>
                            <th>Origem</th>
                            <th>Descrição</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($auditoria as $evento): ?>
                            <tr>
                                <td><?= h(dataHoraBr($evento['criado_em'])) ?></td>
                                <td><?= badge((string) $evento['evento']) ?></td>
                                <td>
                                    <?php if (!empty($evento['parcela_id'])): ?>
                                        <?= ((int) ($evento['numero_parcela'] ?? 0) === 0) ? 'Entrada' : '#' . (int) $evento['numero_parcela'] ?>
                                        <small><?= h(dataBr($evento['parcela_vencimento'] ?? null)) ?> · <?= h(dinheiro($evento['valor_parcela'] ?? null)) ?></small>
                                    <?php else: ?>
                                        <span class="muted">Acordo</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h((string) ($evento['origem'] ?? 'sistema')) ?></td>
                                <td><?= !empty($evento['descricao']) ? nl2br(h($evento['descricao'])) : '<span class="muted">-</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Histórico de integração</h2>
                    <p>Fila técnica para APIs de credores. Ainda sem worker real.</p>
                </div>
            </div>
            <?php if ($integracoes === []): ?>
                <div class="empty">Nenhum registro de integração para este acordo.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="integracoes-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ação</th>
                            <th>Status</th>
                            <th>Tentativas</th>
                            <th>Criado em</th>
                            <th>Erro</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($integracoes as $integracao): ?>
                            <tr>
                                <td>#<?= (int) $integracao['id'] ?></td>
                                <td><?= h((string) $integracao['acao']) ?></td>
                                <td><?= badge((string) $integracao['status']) ?></td>
                                <td><?= (int) $integracao['tentativas'] ?></td>
                                <td><?= h(dataHoraBr($integracao['criado_em'])) ?></td>
                                <td><?= !empty($integracao['erro_mensagem']) ? nl2br(h($integracao['erro_mensagem'])) : '<span class="muted">-</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
