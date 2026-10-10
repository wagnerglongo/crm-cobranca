<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('tabulacoes.configurar');

use CRM\Modules\Operacao\Repositories\AtendimentoResultadoRepository;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;

$pdo = operacaoPdo();
$repo = new AtendimentoResultadoRepository($pdo);
$integracaoRepo = new CredorIntegracaoRepository($pdo);
$erro = null;
$sucesso = null;
$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? 'salvar');

    try {
        validarCsrf();

        if ($acao === 'salvar_mapeamento') {
            $resultadoId = isset($_POST['resultado_id']) && ctype_digit((string) $_POST['resultado_id']) ? (int) $_POST['resultado_id'] : 0;
            $integracaoSelecionada = $integracaoRepo->buscar((int) ($_POST['integracao_id'] ?? 0));
            if ($integracaoSelecionada === null) {
                throw new RuntimeException('Integração não encontrada.');
            }
            if (!usuarioPodeCredorId((int) ($integracaoSelecionada['credor_id'] ?? 0), 'configurar')) {
                throw new RuntimeException('Seu usuário não possui escopo para configurar esta integração.');
            }
            $mapeamentoId = $integracaoRepo->salvarMapeamento($resultadoId, $_POST, usuarioAtualId());
            registrarAuditoriaSistema('tabulacao.integracao_mapeada', 'integracao_tabulacao_mapeamento', $mapeamentoId, 'De/para externo da tabulação salvo.', null, [
                'resultado_id' => $resultadoId,
                'integracao_id' => (int) ($_POST['integracao_id'] ?? 0),
                'produto' => (string) ($_POST['produto'] ?? ''),
                'codigo_externo' => (string) ($_POST['codigo_externo'] ?? ''),
                'enviar' => isset($_POST['enviar']),
            ]);
            header('Location: /operacao/configuracoes/resultados_atendimento.php?ok=mapeamento&editar=' . $resultadoId . '#integracoes-tab');
            exit;
        }

        if ($acao === 'remover_mapeamento') {
            $resultadoId = isset($_POST['resultado_id']) && ctype_digit((string) $_POST['resultado_id']) ? (int) $_POST['resultado_id'] : 0;
            $mapeamentoId = isset($_POST['mapeamento_id']) && ctype_digit((string) $_POST['mapeamento_id']) ? (int) $_POST['mapeamento_id'] : 0;
            $mapeamento = $integracaoRepo->buscarMapeamento($mapeamentoId);
            if ($mapeamento === null || (int) ($mapeamento['resultado_id'] ?? 0) !== $resultadoId) {
                throw new RuntimeException('De/para não encontrado.');
            }
            if (!usuarioPodeCredorId((int) ($mapeamento['credor_id'] ?? 0), 'configurar')) {
                throw new RuntimeException('Seu usuário não possui escopo para remover este de/para.');
            }
            $integracaoRepo->removerMapeamento($resultadoId, $mapeamentoId, usuarioAtualId());
            registrarAuditoriaSistema('tabulacao.integracao_desativada', 'integracao_tabulacao_mapeamento', $mapeamentoId, 'De/para externo da tabulação desativado.', $mapeamento, null);
            header('Location: /operacao/configuracoes/resultados_atendimento.php?ok=mapeamento_desativado&editar=' . $resultadoId . '#integracoes-tab');
            exit;
        }

        if ($acao === 'alternar_ativo') {
            $id = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
            $ativo = (string) ($_POST['ativo'] ?? '0') === '1';
            $repo->alternarAtivo($id, $ativo);
            registrarAuditoriaSistema(
                'tabulacao.status_alterado',
                'atendimento_resultado',
                $id,
                'Status da tabulação alterado.',
                null,
                ['ativo' => $ativo]
            );

            header('Location: /operacao/configuracoes/resultados_atendimento.php?ok=status');
            exit;
        }

        $tabulacaoEditadaId = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
        $idSalvo = $repo->salvar($_POST);
        registrarAuditoriaSistema(
            $tabulacaoEditadaId > 0 ? 'tabulacao.atualizada' : 'tabulacao.criada',
            'atendimento_resultado',
            $idSalvo,
            $tabulacaoEditadaId > 0 ? 'Tabulação atualizada.' : 'Tabulação criada.',
            null,
            [
                'id' => $idSalvo,
                'codigo' => (string) ($_POST['codigo'] ?? ''),
                'nome' => (string) ($_POST['nome'] ?? ''),
                'categoria' => (string) ($_POST['categoria'] ?? ''),
                'resultado_legacy' => (string) ($_POST['resultado_legacy'] ?? ''),
                'ativo' => (string) ($_POST['ativo'] ?? ''),
            ]
        );
        header('Location: /operacao/configuracoes/resultados_atendimento.php?ok=salvo&editar=' . $idSalvo);
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        $resultadoPostId = isset($_POST['resultado_id']) && ctype_digit((string) $_POST['resultado_id']) ? (int) $_POST['resultado_id'] : 0;
        $editando = $resultadoPostId > 0 ? $repo->buscar($resultadoPostId) : $_POST;
    }
}

if ($erro === null && isset($_GET['editar']) && ctype_digit((string) $_GET['editar'])) {
    $editando = $repo->buscar((int) $_GET['editar']);
    if ($editando === null) {
        $erro = 'Tabulação não encontrada para edição.';
    }
}

if (isset($_GET['ok'])) {
    $sucesso = match ((string) $_GET['ok']) {
        'salvo' => 'Tabulação salva com sucesso.',
        'status' => 'Status da tabulação atualizado com sucesso.',
        'mapeamento' => 'De/para da integração salvo com sucesso.',
        'mapeamento_desativado' => 'De/para desativado. Essa tabulação não será enviada por essa integração.',
        default => 'Operação concluída com sucesso.',
    };
}

$resultados = $repo->listarTodos();
$legacyOptions = $repo->resultadosLegacy();
$resultadoEditadoId = isset($editando['id']) ? (int) $editando['id'] : 0;
$integracoesDisponiveis = $integracaoRepo->listarTodas(true);
$credorIdsConfiguracao = usuarioEscopoCredorIdsAcao('configurar');
if ($credorIdsConfiguracao !== null) {
    $integracoesDisponiveis = array_values(array_filter(
        $integracoesDisponiveis,
        static fn (array $item): bool => in_array((int) ($item['credor_id'] ?? 0), $credorIdsConfiguracao, true)
    ));
}
$mapeamentosResultado = $resultadoEditadoId > 0 ? $integracaoRepo->listarMapeamentosResultado($resultadoEditadoId) : [];
if ($credorIdsConfiguracao !== null) {
    $mapeamentosResultado = array_values(array_filter(
        $mapeamentosResultado,
        static fn (array $item): bool => in_array((int) ($item['credor_id'] ?? 0), $credorIdsConfiguracao, true)
    ));
}

// Achado (2026-10-01): "padrão pra acordo fechado" precisa poder
// mudar por credor (cada credor tem seu próprio catálogo de tabulações e
// pode usar um ActionID/evento diferente como padrão).
$credoresDisponiveis = $pdo->query('SELECT id, codigo, nome FROM credores ORDER BY nome ASC')->fetchAll();
$credorNomePorId = array_column($credoresDisponiveis, 'nome', 'id');
if ($credorIdsConfiguracao !== null) {
    $credoresDisponiveis = array_values(array_filter(
        $credoresDisponiveis,
        static fn (array $item): bool => in_array((int) ($item['id'] ?? 0), $credorIdsConfiguracao, true)
    ));
}

$form = [
    'id' => $editando['id'] ?? '',
    'codigo' => $editando['codigo'] ?? '',
    'nome' => $editando['nome'] ?? '',
    'categoria' => $editando['categoria'] ?? '',
    'credor_id' => $editando['credor_id'] ?? '',
    'resultado_legacy' => $editando['resultado_legacy'] ?? 'outro',
    'descricao' => $editando['descricao'] ?? '',
    'gera_reagendamento' => (int) ($editando['gera_reagendamento'] ?? 0),
    'padrao_fechamento_acordo' => (int) ($editando['padrao_fechamento_acordo'] ?? 0),
    'considera_sucesso' => (int) ($editando['considera_sucesso'] ?? 0),
    'considera_contato_efetivo' => (int) ($editando['considera_contato_efetivo'] ?? 0),
    'considera_alo' => (int) ($editando['considera_alo'] ?? 0),
    'considera_telefone_ruim' => (int) ($editando['considera_telefone_ruim'] ?? 0),
    'ativo' => (int) ($editando['ativo'] ?? 1),
    'ordem' => $editando['ordem'] ?? 100,
];

$pageTitle = 'Configuração de tabulações';
$pageSubtitle = 'Gerencie os resultados usados no registro e nos indicadores de atendimento.';
$activePage = 'config_resultados';

require __DIR__ . '/../includes/header.php';
?>
<main class="container config-page config-results-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <section class="card">
        <div class="section-title">
            <div>
                <h2>Resultados de atendimento</h2>
                <p>Essas opções alimentam a ficha do cliente, os atendimentos recentes e os indicadores da operação.</p>
            </div>
            <div class="actions-row">
                <a class="button secondary" href="/operacao/atendimentos.php">Ver atendimentos</a>
                <a class="button secondary" href="/operacao/configuracoes/resultados_atendimento.php">Nova tabulação</a>
            </div>
        </div>

        <?php if ($sucesso !== null): ?>
            <div class="alert success"><?= h($sucesso) ?></div>
        <?php endif; ?>

        <?php if ($erro !== null): ?>
            <div class="alert danger"><?= h($erro) ?></div>
        <?php endif; ?>

        <?php if (!$repo->moduloDisponivel()): ?>
            <div class="alert danger">
                A tabela <strong>atendimento_resultados</strong> ainda não existe. Execute a migration
                <code>010_atendimento_resultados_padronizados.sql</code> antes de usar esta tela.
            </div>
        <?php else: ?>
            <form method="post" class="form-card config-form">
                <?= authCsrfInput() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= h($form['id']) ?>">
                <input type="hidden" name="ativo" value="<?= h((string) $form['ativo']) ?>">

                <div class="subsection-title compact-title">
                    <div>
                        <h3><?= $form['id'] !== '' ? 'Editar tabulação #' . h($form['id']) : 'Nova tabulação' ?></h3>
                        <p>O código é interno e deve ser único. Se deixar em branco, o CRM gera a partir do nome.</p>
                    </div>
                </div>

                <div class="form-grid">
                    <label class="field">
                        <span>Nome exibido</span>
                        <input type="text" name="nome" maxlength="120" required value="<?= h($form['nome']) ?>" placeholder="Ex.: Promessa de pagamento">
                    </label>

                    <label class="field">
                        <span>Código interno</span>
                        <input type="text" name="codigo" maxlength="60" value="<?= h($form['codigo']) ?>" placeholder="Ex.: PROMESSA_PAGAMENTO">
                    </label>

                    <label class="field">
                        <span>Categoria</span>
                        <input type="text" name="categoria" maxlength="80" value="<?= h($form['categoria']) ?>" placeholder="Ex.: Negociação">
                    </label>

                    <label class="field">
                        <span>Credor</span>
                        <select name="credor_id">
                            <option value="">Vale para qualquer credor</option>
                            <?php foreach ($credoresDisponiveis as $credorOpcao): ?>
                                <option value="<?= (int) $credorOpcao['id'] ?>" <?= ((string) $form['credor_id'] === (string) $credorOpcao['id']) ? 'selected' : '' ?>>
                                    <?= h((string) $credorOpcao['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Compatibilidade legado</span>
                        <select name="resultado_legacy" required>
                            <?php foreach ($legacyOptions as $codigoLegacy => $labelLegacy): ?>
                                <option value="<?= h($codigoLegacy) ?>"<?= $codigoLegacy === (string) $form['resultado_legacy'] ? ' selected' : '' ?>>
                                    <?= h($labelLegacy) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Ordem</span>
                        <input type="number" name="ordem" min="0" max="65535" step="1" value="<?= h($form['ordem']) ?>">
                    </label>

                    <label class="field">
                        <span>Status</span>
                        <select name="ativo">
                            <option value="1"<?= (int) $form['ativo'] === 1 ? ' selected' : '' ?>>Ativo</option>
                            <option value="0"<?= (int) $form['ativo'] === 0 ? ' selected' : '' ?>>Inativo</option>
                        </select>
                    </label>
                </div>

                <label class="field field-full">
                    <span>Descrição</span>
                    <textarea name="descricao" rows="2" maxlength="255" placeholder="Explique quando essa tabulação deve ser usada."><?= h($form['descricao']) ?></textarea>
                </label>

                <div class="switch-grid" aria-label="Comportamentos da tabulação">
                    <label class="switch-card">
                        <input type="checkbox" name="gera_reagendamento" value="1"<?= (int) $form['gera_reagendamento'] === 1 ? ' checked' : '' ?>>
                        <span>
                            <strong>Gera reagendamento</strong>
                            <small>Usado para cobrar próximo acionamento e fila futura.</small>
                        </span>
                    </label>

                    <label class="switch-card">
                        <input type="checkbox" name="considera_alo" value="1"<?= (int) $form['considera_alo'] === 1 ? ' checked' : '' ?>>
                        <span>
                            <strong>Alô</strong>
                            <small>Alguém atendeu (funil de acionamentos e dashboard).</small>
                        </span>
                    </label>

                    <label class="switch-card">
                        <input type="checkbox" name="considera_contato_efetivo" value="1"<?= (int) $form['considera_contato_efetivo'] === 1 ? ' checked' : '' ?>>
                        <span>
                            <strong>Contato efetivo</strong>
                            <small>Conta como contato real nos indicadores.</small>
                        </span>
                    </label>

                    <label class="switch-card">
                        <input type="checkbox" name="considera_sucesso" value="1"<?= (int) $form['considera_sucesso'] === 1 ? ' checked' : '' ?>>
                        <span>
                            <strong>Sucesso operacional</strong>
                            <small>Entra nas métricas de sucesso/conversão.</small>
                        </span>
                    </label>

                    <label class="switch-card">
                        <input type="checkbox" name="considera_telefone_ruim" value="1"<?= (int) $form['considera_telefone_ruim'] === 1 ? ' checked' : '' ?>>
                        <span>
                            <strong>Telefone ruim</strong>
                            <small>Ajuda a limpar contatos inválidos futuramente.</small>
                        </span>
                    </label>

                    <label class="switch-card">
                        <input type="checkbox" name="padrao_fechamento_acordo" value="1"<?= (int) $form['padrao_fechamento_acordo'] === 1 ? ' checked' : '' ?>>
                        <span>
                            <strong>Padrão para acordo fechado</strong>
                            <small>Usada automaticamente quando um acordo é fechado pelo Pactua (e é o ActionID enviado à integração do credor). Só uma por credor - marcar esta desmarca a anterior.</small>
                        </span>
                    </label>
                </div>

                <div class="actions-row form-actions">
                    <button class="button" type="submit">Salvar tabulação</button>
                    <a class="button secondary" href="/operacao/configuracoes/resultados_atendimento.php">Limpar formulário</a>
                </div>
            </form>

            <?php if ($resultadoEditadoId > 0): ?>
                <section class="config-integration-map" id="integracoes-tab">
                    <div class="subsection-title compact-title">
                        <div>
                            <h3>De/para por credor</h3>
                            <p>Associe esta tabulação ao código externo de cada credor. <strong>Sem mapeamento ativo, o atendimento fica no CRM e não é enviado.</strong></p>
                        </div>
                    </div>

                    <?php if (!$integracaoRepo->moduloDisponivel()): ?>
                        <div class="alert warning">Execute a migration <code>085_integracoes_acionamentos_credores.sql</code> para habilitar integrações.</div>
                    <?php elseif ($integracoesDisponiveis === []): ?>
                        <div class="alert warning">Nenhuma integração ativa foi encontrada no seu escopo. Configure o credor primeiro.</div>
                    <?php else: ?>
                        <form method="post" class="form-card config-map-form">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="salvar_mapeamento">
                            <input type="hidden" name="resultado_id" value="<?= $resultadoEditadoId ?>">
                            <div class="form-grid">
                                <label class="field">
                                    <span>Credor / integração</span>
                                    <select name="integracao_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($integracoesDisponiveis as $integracao): ?>
                                            <option value="<?= (int) $integracao['id'] ?>"><?= h($integracao['credor_nome'] . ' · ' . $integracao['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Produto específico (opcional)</span>
                                    <input type="text" name="produto" maxlength="180" placeholder="Vazio = todos os produtos">
                                </label>
                                <label class="field">
                                    <span>Código externo / ActionID</span>
                                    <input type="text" name="codigo_externo" maxlength="120" placeholder="Ex.: 191">
                                </label>
                                <label class="field">
                                    <span>Descrição no credor</span>
                                    <input type="text" name="descricao_externa" maxlength="255" placeholder="Ex.: Telefone Ocupado">
                                </label>
                            </div>
                            <div class="switch-grid compact-switch-grid">
                                <label class="switch-card">
                                    <input type="checkbox" name="enviar" value="1" checked>
                                    <span><strong>Enviar ao credor</strong><small>Desmarcado mantém o de/para documentado, mas não gera evento de saída.</small></span>
                                </label>
                                <label class="switch-card">
                                    <input type="checkbox" name="mapeamento_ativo" value="1" checked>
                                    <span><strong>Mapeamento ativo</strong><small>Permite suspender sem apagar o histórico.</small></span>
                                </label>
                            </div>
                            <div class="actions-row form-actions">
                                <button class="button" type="submit">Salvar de/para</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div class="config-map-list">
                        <?php if ($mapeamentosResultado === []): ?>
                            <div class="empty">Nenhum de/para configurado para esta tabulação.</div>
                        <?php else: ?>
                            <?php foreach ($mapeamentosResultado as $map): ?>
                                <article class="config-map-card">
                                    <div>
                                        <strong><?= h($map['credor_nome'] . ' · ' . $map['integracao_nome']) ?></strong>
                                        <span><?= h($map['produto'] !== '' ? 'Produto: ' . $map['produto'] : 'Todos os produtos') ?></span>
                                    </div>
                                    <div class="config-map-code">
                                        <small>Código externo</small>
                                        <strong><?= h($map['codigo_externo'] ?: '-') ?></strong>
                                        <span><?= h($map['descricao_externa'] ?: 'Sem descrição externa') ?></span>
                                    </div>
                                    <div class="badge-list">
                                        <?= (int) $map['enviar'] === 1 ? '<span class="badge badge-ok">Envia</span>' : '<span class="badge badge-off">Não envia</span>' ?>
                                        <?= (int) $map['ativo'] === 1 && (int) $map['integracao_ativa'] === 1 ? '<span class="badge badge-ok">Ativo</span>' : '<span class="badge badge-off">Inativo</span>' ?>
                                        <span class="badge badge-soft">v<?= (int) $map['versao'] ?></span>
                                    </div>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Desativar este de/para? Novos acionamentos deixarão de ser enviados por esta integração.');">
                                        <?= authCsrfInput() ?>
                                        <input type="hidden" name="acao" value="remover_mapeamento">
                                        <input type="hidden" name="resultado_id" value="<?= $resultadoEditadoId ?>">
                                        <input type="hidden" name="mapeamento_id" value="<?= (int) $map['id'] ?>">
                                        <button class="button secondary table-button" type="submit">Desativar</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php else: ?>
                <div class="alert info">Salve a tabulação primeiro. Depois, a área de de/para por credor ficará disponível nesta mesma tela.</div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="section-title">
            <div>
                <h2>Tabulações cadastradas</h2>
                <p><?= count($resultados) ?> resultado(s) disponíveis para configuração.</p>
            </div>
        </div>

        <?php if ($resultados === []): ?>
            <div class="empty">Nenhuma tabulação cadastrada ou tabela ainda não disponível.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="config-results-table">
                    <thead>
                        <tr>
                            <th>Ordem</th>
                            <th>Resultado</th>
                            <th>Categoria</th>
                            <th>Credor</th>
                            <th>Comportamento</th>
                            <th>Legado</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($resultados as $resultado): ?>
                        <tr>
                            <td class="nowrap"><?= h($resultado['ordem']) ?></td>
                            <td>
                                <strong><?= h($resultado['nome']) ?></strong><br>
                                <small class="muted"><?= h($resultado['codigo']) ?></small>
                                <?php if (!empty($resultado['descricao'])): ?>
                                    <br><small class="muted"><?= h($resultado['descricao']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= valorOuTraco($resultado['categoria']) ?></td>
                            <td><?= !empty($resultado['credor_id']) ? h((string) ($credorNomePorId[(int) $resultado['credor_id']] ?? '?')) : '<span class="muted">Qualquer</span>' ?></td>
                            <td>
                                <div class="badge-list">
                                    <?php if ((int) $resultado['padrao_fechamento_acordo'] === 1): ?><?= badgeSoft('padrão p/ acordo fechado') ?><?php endif; ?>
                                    <?php if ((int) $resultado['gera_reagendamento'] === 1): ?><?= badgeSoft('reagendamento') ?><?php endif; ?>
                                    <?php if ((int) $resultado['considera_alo'] === 1): ?><?= badgeSoft('alô') ?><?php endif; ?>
                                    <?php if ((int) $resultado['considera_contato_efetivo'] === 1): ?><?= badgeSoft('contato efetivo') ?><?php endif; ?>
                                    <?php if ((int) $resultado['considera_sucesso'] === 1): ?><?= badgeSoft('sucesso') ?><?php endif; ?>
                                    <?php if ((int) $resultado['considera_telefone_ruim'] === 1): ?><?= badgeSoft('telefone ruim') ?><?php endif; ?>
                                    <?php if ((int) $resultado['gera_reagendamento'] !== 1 && (int) $resultado['considera_contato_efetivo'] !== 1 && (int) $resultado['considera_sucesso'] !== 1 && (int) $resultado['considera_telefone_ruim'] !== 1): ?>
                                        <span class="muted">Sem marcador</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= h($legacyOptions[(string) $resultado['resultado_legacy']] ?? $resultado['resultado_legacy']) ?></td>
                            <td>
                                <?= (int) $resultado['ativo'] === 1 ? '<span class="badge badge-ok">Ativo</span>' : '<span class="badge badge-off">Inativo</span>' ?>
                            </td>
                            <td>
                                <div class="actions-row table-actions">
                                    <a class="button secondary table-button" href="/operacao/configuracoes/resultados_atendimento.php?editar=<?= h($resultado['id']) ?>">Editar</a>
                                    <form method="post" class="inline-form">
                                        <?= authCsrfInput() ?>
                                        <input type="hidden" name="acao" value="alternar_ativo">
                                        <input type="hidden" name="id" value="<?= h($resultado['id']) ?>">
                                        <input type="hidden" name="ativo" value="<?= (int) $resultado['ativo'] === 1 ? '0' : '1' ?>">
                                        <button class="button secondary table-button" type="submit">
                                            <?= (int) $resultado['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
