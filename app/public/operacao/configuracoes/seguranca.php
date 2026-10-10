<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\SegurancaOperacionalRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('seguranca.visualizar');

$pageTitle = 'Segurança Operacional';
$pageSubtitle = 'Login, bloqueios e duração máxima das sessões operacionais.';
$activePage = 'seguranca';

$repo = new SegurancaOperacionalRepository(operacaoPdo());
$mensagemSucesso = null;
$mensagemErro = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireOperacaoPermissao('seguranca.configurar');
        validarCsrf();
        $repo->salvar($_POST, usuarioAtualId());
        registrarAuditoriaSistema('seguranca.configuracoes_atualizadas', 'seguranca_configuracoes', null, 'Configurações de segurança atualizadas.', null, $_POST);
        $mensagemSucesso = 'Configurações de segurança salvas.';
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

$config = $repo->configuracoes();
$resumo = $repo->resumo();
$eventos = $repo->ultimosEventosSeguranca(80);

require __DIR__ . '/../includes/header.php';
?>
<main class="container config-page config-security-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <?php if ($mensagemSucesso !== null): ?><div class="alert success"><?= h($mensagemSucesso) ?></div><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><div class="alert danger"><?= h($mensagemErro) ?></div><?php endif; ?>

    <?php if (!$repo->moduloDisponivel()): ?>
        <section class="card error"><strong>Módulo de segurança ainda não ativo.</strong><p>Execute a migration 033_037.</p></section>
    <?php else: ?>
        <?php if (!empty($resumo['admin_padrao_existe']) && (string) ($config['avisar_admin_padrao'] ?? '1') === '1'): ?>
            <div class="alert warning"><strong>Atenção:</strong> o login padrão <code>admin</code> ainda existe ativo. Troque a senha em Usuários antes de homologação/produção.</div>
        <?php endif; ?>

        <section class="metrics-grid metrics-grid-wide">
            <div class="metric"><small>Usuários ativos</small><strong><?= number_format((int) $resumo['usuarios_ativos'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-warning"><small>Bloqueados agora</small><strong><?= number_format((int) $resumo['usuarios_bloqueados'], 0, ',', '.') ?></strong></div>
            <div class="metric"><small>Troca de senha</small><strong><?= number_format((int) $resumo['usuarios_forcar_troca'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-danger"><small>Falhas login 24h</small><strong><?= number_format((int) $resumo['falhas_login_24h'], 0, ',', '.') ?></strong></div>
            <div class="metric metric-success"><small>Logins 24h</small><strong><?= number_format((int) $resumo['sucessos_login_24h'], 0, ',', '.') ?></strong></div>
        </section>

        <section class="grid-2 grid-2-wide">
            <div class="card">
                <div class="section-title"><div><h2>Configurações de login</h2><p>Primeira camada de segurança operacional.</p></div><?= badgeSoft('segurança inicial') ?></div>
                <form method="post" class="form-card">
                    <?= authCsrfInput() ?>
                    <div class="form-grid">
                        <label class="field"><span>Máx. tentativas inválidas</span><input type="number" min="1" max="20" name="max_tentativas_login" value="<?= h($config['max_tentativas_login'] ?? '5') ?>"></label>
                        <label class="field"><span>Minutos de bloqueio</span><input type="number" min="1" max="1440" name="minutos_bloqueio_login" value="<?= h($config['minutos_bloqueio_login'] ?? '15') ?>"></label>
                        <label class="field"><span>Tamanho mínimo de senha</span><input type="number" min="6" max="64" name="min_tamanho_senha" value="<?= h($config['min_tamanho_senha'] ?? '8') ?>"></label>
                        <label class="field"><span>Duração máxima da sessão (horas)</span><input type="number" min="1" max="24" name="sessao_expira_horas" value="<?= h($config['sessao_expira_horas'] ?? '10') ?>"><small>Tempo absoluto desde o login. O uso do CRM não renova esse prazo. Alterações passam a valer no próximo login.</small></label>
                    </div>
                    <div class="alert info"><strong>Novo login diário:</strong> mesmo dentro do limite configurado, a sessão é encerrada na virada do dia. Assim um operador não permanece autenticado de um dia para o outro.</div>
                    <div class="switch-grid">
                        <label class="switch-card"><input type="checkbox" name="avisar_admin_padrao" value="1" <?= (string)($config['avisar_admin_padrao'] ?? '1') === '1' ? 'checked' : '' ?>><span><strong>Avisar sobre admin padrão</strong><small>Mostra alerta enquanto o login admin estiver ativo.</small></span></label>
                    </div>
                    <div class="alert info"><strong>CSRF:</strong> validação de token é sempre aplicada nos formulários sensíveis da operação, sem opção de desligar.</div>
                    <?php if (usuarioPode('seguranca.configurar')): ?><div class="actions-row form-actions"><button class="button" type="submit">Salvar segurança</button></div><?php endif; ?>
                </form>
            </div>
            <div class="card">
                <div class="section-title"><div><h2>Checklist antes de produção</h2><p>Pontos que ainda devem ser tratados quando sair do DEV.</p></div></div>
                <div class="checklist"><div>Trocar senha do admin inicial</div><div>Separar .env DEV/HOMOLOG/PROD</div><div>Desligar exibição de erros na tela</div><div>Configurar backup do MySQL</div><div>Configurar backup do storage de cargas</div><div>Revisar permissões por perfil</div></div>
            </div>
        </section>

        <section class="card">
            <div class="section-title"><div><h2>Eventos recentes de segurança</h2><p>Login, falhas, usuários e perfis.</p></div><span class="badge"><?= count($eventos) ?></span></div>
            <div class="table-wrap"><table><thead><tr><th>Data</th><th>Evento</th><th>Usuário</th><th>Descrição</th><th>IP</th></tr></thead><tbody><?php foreach ($eventos as $e): ?><tr><td><?= h(dataHoraBr($e['criado_em'] ?? null)) ?></td><td><?= badge((string)$e['evento']) ?></td><td><?= h((string)($e['usuario_nome'] ?? '-')) ?></td><td><?= h((string)($e['descricao'] ?? '-')) ?></td><td><?= h((string)($e['ip'] ?? '-')) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
