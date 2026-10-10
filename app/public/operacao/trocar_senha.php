<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\SegurancaOperacionalRepository;

require_once __DIR__ . '/includes/bootstrap.php';
// requireOperacaoLogin() deixa esta página abrir mesmo com troca obrigatória pendente.
requireOperacaoLogin();

$pageTitle = 'Trocar senha';
$pageSubtitle = 'Defina a sua senha pessoal de acesso ao CRM.';
$activePage = '';

$usuario = usuarioAtual() ?? [];
$obrigatoria = (int) ($usuario['forcar_troca_senha'] ?? 0) === 1;
$redirect = (string) ($_GET['redirect'] ?? ($_POST['redirect'] ?? '/operacao/works.php'));
if ($redirect === '' || !str_starts_with($redirect, '/operacao/') || str_starts_with($redirect, '/operacao/trocar_senha.php')) {
    $redirect = '/operacao/works.php';
}
$minTamanhoSenha = max(1, (int) ((new SegurancaOperacionalRepository(operacaoPdo()))->configuracoes()['min_tamanho_senha'] ?? 8));
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validarCsrf();
        authRepository()->alterarSenhaPropria(
            (int) $usuario['id'],
            (string) ($_POST['senha_atual'] ?? ''),
            (string) ($_POST['nova_senha'] ?? ''),
            (string) ($_POST['confirmacao'] ?? ''),
            $minTamanhoSenha
        );
        registrarAuditoriaSistema('usuario.senha_alterada', 'usuario', (int) $usuario['id'], 'Senha alterada pelo próprio usuário.', null, ['obrigatoria' => $obrigatoria]);
        session_regenerate_id(true);
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'senha=alterada');
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

require __DIR__ . '/includes/header.php';
?>
<main class="container">
    <?php if ($obrigatoria): ?>
        <div class="alert warning"><strong>Defina a sua senha antes de continuar.</strong> Sua conta foi criada (ou teve a senha redefinida) com uma senha provisória. Escolha uma senha pessoal - só você vai conhecê-la.</div>
    <?php endif; ?>
    <?php if ($erro !== null): ?><div class="alert danger"><?= h($erro) ?></div><?php endif; ?>

    <section class="card" style="max-width: 560px;">
        <div class="section-title"><div><h2>Trocar senha</h2><p><?= h((string) ($usuario['nome'] ?? '')) ?> · <?= h((string) ($usuario['login'] ?? '')) ?></p></div></div>
        <form method="post" class="form-card" autocomplete="off">
            <?= authCsrfInput() ?>
            <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
            <div class="form-grid">
                <label class="field"><span><?= $obrigatoria ? 'Senha provisória (a que você usou para entrar)' : 'Senha atual' ?></span><input type="password" name="senha_atual" required autocomplete="current-password"></label>
                <label class="field"><span>Nova senha</span><input type="password" name="nova_senha" required minlength="<?= $minTamanhoSenha ?>" autocomplete="new-password" placeholder="Mínimo de <?= $minTamanhoSenha ?> caracteres"></label>
                <label class="field"><span>Confirmar nova senha</span><input type="password" name="confirmacao" required minlength="<?= $minTamanhoSenha ?>" autocomplete="new-password"></label>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">Salvar nova senha</button>
                <?php if ($obrigatoria): ?>
                    <a class="button ghost" href="/operacao/logout.php">Sair</a>
                <?php else: ?>
                    <a class="button ghost" href="<?= h($redirect) ?>">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
