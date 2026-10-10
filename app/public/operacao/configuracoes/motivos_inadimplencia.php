<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('motivos_inadimplencia.configurar');

use CRM\Modules\Operacao\Repositories\MotivoInadimplenciaRepository;

$pdo = operacaoPdo();
$repo = new MotivoInadimplenciaRepository($pdo);
$erro = null;
$sucesso = null;
$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? 'salvar');

    try {
        validarCsrf();

        if ($acao === 'alternar_ativo') {
            $id = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
            $ativo = (string) ($_POST['ativo'] ?? '0') === '1';
            $repo->alternarAtivo($id, $ativo);
            registrarAuditoriaSistema(
                'motivo_inadimplencia.status_alterado',
                'motivo_inadimplencia',
                $id,
                'Status do motivo de inadimplência alterado.',
                null,
                ['ativo' => $ativo]
            );

            header('Location: /operacao/configuracoes/motivos_inadimplencia.php?ok=status');
            exit;
        }

        $motivoEditadoId = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
        $idSalvo = $repo->salvar($_POST);
        registrarAuditoriaSistema(
            $motivoEditadoId > 0 ? 'motivo_inadimplencia.atualizado' : 'motivo_inadimplencia.criado',
            'motivo_inadimplencia',
            $idSalvo,
            $motivoEditadoId > 0 ? 'Motivo de inadimplência atualizado.' : 'Motivo de inadimplência criado.',
            null,
            ['id' => $idSalvo, 'nome' => (string) ($_POST['nome'] ?? ''), 'ativo' => (string) ($_POST['ativo'] ?? '')]
        );
        header('Location: /operacao/configuracoes/motivos_inadimplencia.php?ok=salvo');
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        $motivoPostId = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
        $editando = $motivoPostId > 0 ? $repo->buscar($motivoPostId) : $_POST;
    }
}

if ($erro === null && isset($_GET['editar']) && ctype_digit((string) $_GET['editar'])) {
    $editando = $repo->buscar((int) $_GET['editar']);
    if ($editando === null) {
        $erro = 'Motivo de inadimplência não encontrado para edição.';
    }
}

if (isset($_GET['ok'])) {
    $sucesso = match ((string) $_GET['ok']) {
        'salvo' => 'Motivo de inadimplência salvo com sucesso.',
        'status' => 'Status atualizado com sucesso.',
        default => 'Operação concluída com sucesso.',
    };
}

$motivos = $repo->listarTodos();
$form = [
    'id' => $editando['id'] ?? '',
    'nome' => $editando['nome'] ?? '',
    'ativo' => (int) ($editando['ativo'] ?? 1),
    'ordem' => $editando['ordem'] ?? 100,
];

$pageTitle = 'Motivos de inadimplência';
$pageSubtitle = 'Opções perguntadas ao operador quando o acionamento é CPC (contato com a pessoa certa) e o acordo não fecha.';
$activePage = 'config_motivos_inadimplencia';

require __DIR__ . '/../includes/header.php';
?>
<main class="container config-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <section class="card">
        <div class="section-title">
            <div>
                <h2>Motivos de inadimplência</h2>
                <p>Quando o operador tem contato com a pessoa certa (CPC) e o acordo não fecha, o sistema pergunta o motivo. Essas são as opções disponíveis - edite, desative ou crie novas a qualquer momento.</p>
            </div>
            <div class="actions-row">
                <a class="button secondary" href="/operacao/configuracoes/motivos_inadimplencia.php">Novo motivo</a>
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
                A tabela <strong>motivos_inadimplencia</strong> ainda não existe. Execute a migration
                <code>095_motivos_inadimplencia.sql</code> antes de usar esta tela.
            </div>
        <?php else: ?>
            <form method="post" class="form-card config-form">
                <?= authCsrfInput() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= h((string) $form['id']) ?>">

                <div class="subsection-title compact-title">
                    <div>
                        <h3><?= $form['id'] !== '' ? 'Editar motivo #' . h((string) $form['id']) : 'Novo motivo' ?></h3>
                    </div>
                </div>

                <div class="form-grid">
                    <label class="field">
                        <span>Nome exibido</span>
                        <input type="text" name="nome" maxlength="120" required value="<?= h((string) $form['nome']) ?>" placeholder="Ex.: Perdeu a renda">
                    </label>

                    <label class="field">
                        <span>Ordem</span>
                        <input type="number" name="ordem" min="0" max="65535" step="1" value="<?= h((string) $form['ordem']) ?>">
                    </label>

                    <label class="field">
                        <span>Status</span>
                        <select name="ativo">
                            <option value="1"<?= (int) $form['ativo'] === 1 ? ' selected' : '' ?>>Ativo</option>
                            <option value="0"<?= (int) $form['ativo'] === 0 ? ' selected' : '' ?>>Inativo</option>
                        </select>
                    </label>
                </div>

                <div class="actions-row">
                    <button class="button primary" type="submit">Salvar</button>
                    <?php if ($form['id'] !== ''): ?>
                        <a class="button secondary" href="/operacao/configuracoes/motivos_inadimplencia.php">Cancelar edição</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($motivos === []): ?>
                <div class="empty">Nenhum motivo cadastrado.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Ordem</th>
                                <th>Motivo</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($motivos as $motivo): ?>
                            <tr>
                                <td class="nowrap"><?= h((string) $motivo['ordem']) ?></td>
                                <td><strong><?= h((string) $motivo['nome']) ?></strong></td>
                                <td><?= (int) $motivo['ativo'] === 1 ? '<span class="badge badge-ok">Ativo</span>' : '<span class="badge badge-off">Inativo</span>' ?></td>
                                <td>
                                    <div class="actions-row table-actions">
                                        <a class="button secondary table-button" href="/operacao/configuracoes/motivos_inadimplencia.php?editar=<?= (int) $motivo['id'] ?>">Editar</a>
                                        <form method="post" class="inline-form">
                                            <?= authCsrfInput() ?>
                                            <input type="hidden" name="acao" value="alternar_ativo">
                                            <input type="hidden" name="id" value="<?= (int) $motivo['id'] ?>">
                                            <input type="hidden" name="ativo" value="<?= (int) $motivo['ativo'] === 1 ? '0' : '1' ?>">
                                            <button class="button secondary table-button" type="submit">
                                                <?= (int) $motivo['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>
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
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
