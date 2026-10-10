<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\RelatorioApiTokenRepository;
use CRM\Modules\Operacao\Repositories\RelatorioOperacionalRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('relatorios.visualizar');

$pageTitle = 'Relatórios CSV';
$pageSubtitle = 'Exportações operacionais leves para análise, planejamento e Excel.';
$activePage = 'relatorios_csv';

$pdo = operacaoPdo();
$repo = new RelatorioOperacionalRepository($pdo);
$tokenRepo = new RelatorioApiTokenRepository($pdo);
$escopo = usuarioEscopoCredoresAcao('consultar');
$tipos = RelatorioOperacionalRepository::tipos();
$credorOptions = [];
$erro = null;
$sucesso = null;
$tokenGerado = null;
$podeGerenciarApi = usuarioEhAdminOperacao() || usuarioPode('relatorios.api.gerenciar');
$usuarioId = usuarioAtualId() ?? 0;

try {
    $credorOptions = $repo->listarCredores($escopo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = trim((string) ($_POST['acao'] ?? ''));

        if ($acao === 'criar_token') {
            if (!$podeGerenciarApi) {
                throw new RuntimeException('Seu perfil não pode gerar token para Excel/API.');
            }
            if ($usuarioId <= 0) {
                throw new RuntimeException('Não foi possível identificar o usuário logado.');
            }
            $nomeToken = trim((string) ($_POST['nome_token'] ?? 'Excel / Planejamento'));
            $validade = filter_var($_POST['validade_dias'] ?? 365, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3650]]);
            if ($validade === false) {
                throw new RuntimeException('Validade inválida. Use de 0 a 3650 dias; 0 significa sem expiração.');
            }
            $tokenGerado = $tokenRepo->criar($usuarioId, $nomeToken, (int) $validade);
            registrarAuditoriaSistema('relatorios.api_token.criado', 'relatorios_api_tokens', (int) $tokenGerado['id'], 'Token read-only de relatórios criado.');
            $sucesso = 'Token criado. Copie agora: por segurança ele não será exibido novamente.';
        } elseif ($acao === 'revogar_token') {
            if (!$podeGerenciarApi) {
                throw new RuntimeException('Seu perfil não pode revogar tokens de relatórios.');
            }
            $tokenId = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tokenId === false || $usuarioId <= 0) {
                throw new RuntimeException('Token inválido.');
            }
            $tokenRepo->revogar((int) $tokenId, $usuarioId);
            registrarAuditoriaSistema('relatorios.api_token.revogado', 'relatorios_api_tokens', (int) $tokenId, 'Token de relatórios revogado.');
            $sucesso = 'Token revogado.';
        }
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

$filtros = [
    'tipo' => isset($tipos[(string) ($_GET['tipo'] ?? '')]) ? (string) $_GET['tipo'] : 'acionamentos',
    'data_inicio' => trim((string) ($_GET['data_inicio'] ?? date('Y-m-01'))),
    'data_fim' => trim((string) ($_GET['data_fim'] ?? date('Y-m-d'))),
    'credor_id' => trim((string) ($_GET['credor_id'] ?? '')),
];

$tokens = [];
if ($podeGerenciarApi && $usuarioId > 0 && $tokenRepo->moduloDisponivel()) {
    try {
        $tokens = $tokenRepo->listarPorUsuario($usuarioId);
    } catch (Throwable) {
        $tokens = [];
    }
}

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$baseUrl = $scheme . '://' . $host;

require __DIR__ . '/includes/header.php';
?>
<main class="container reports-csv-page">
    <?php if ($erro !== null): ?>
        <section class="card error"><strong>Não foi possível concluir a ação:</strong> <?= h($erro) ?></section>
    <?php endif; ?>
    <?php if ($sucesso !== null): ?>
        <section class="card success"><strong><?= h($sucesso) ?></strong></section>
    <?php endif; ?>

    <section class="card reports-csv-main">
        <div class="section-title reports-csv-title">
            <div>
                <h2>Exportar relatório</h2>
                <p>Escolha o relatório, o período e o credor. O CSV é gerado em UTF-8 e separado por ponto e vírgula para facilitar o uso no Excel.</p>
            </div>
            <div class="reports-csv-title-actions">
                <a class="button secondary small" href="/operacao/dados_gerais.php">Dados Gerais / Mailing</a>
                <span class="badge badge-soft">CSV</span>
            </div>
        </div>

        <form class="reports-csv-filter" method="get" action="/operacao/relatorios.php">
            <label class="field">
                <span>Tipo de relatório</span>
                <select name="tipo" required>
                    <?php foreach ($tipos as $codigo => $tipo): ?>
                        <option value="<?= h($codigo) ?>"<?= $filtros['tipo'] === $codigo ? ' selected' : '' ?>><?= h($tipo['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>De</span>
                <input type="date" name="data_inicio" value="<?= h($filtros['data_inicio']) ?>" required>
            </label>
            <label class="field">
                <span>Até</span>
                <input type="date" name="data_fim" value="<?= h($filtros['data_fim']) ?>" required>
            </label>
            <label class="field">
                <span>Credor</span>
                <select name="credor_id">
                    <option value="">Todos permitidos</option>
                    <?php foreach ($credorOptions as $credor): ?>
                        <option value="<?= (int) $credor['id'] ?>"<?= $filtros['credor_id'] === (string) $credor['id'] ? ' selected' : '' ?>>
                            <?= h($credor['nome']) ?><?= $credor['codigo'] !== '' ? ' (' . h($credor['codigo']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="reports-csv-actions">
                <button class="button primary" type="submit" formaction="/operacao/relatorios_exportar.php" data-download-request data-loading-message="Gerando CSV..." data-loading-detail="Aguarde o início do download. Depois você poderá continuar usando a tela normalmente.">Baixar CSV</button>
            </div>
        </form>

        <div class="reports-csv-types">
            <?php foreach ($tipos as $codigo => $tipo): ?>
                <article class="reports-csv-type<?= $filtros['tipo'] === $codigo ? ' is-selected' : '' ?>">
                    <strong><?= h($tipo['label']) ?></strong>
                    <span><?= h($tipo['descricao']) ?></span>
                    <small>Período: <?= h($tipo['periodo']) ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card reports-api-card">
        <div class="section-title">
            <div>
                <h2>Excel / Power Query</h2>
                <p>Cada relatório possui um endpoint read-only. O token herda o escopo de credores e as permissões do usuário que o criou.</p>
            </div>
            <?= $tokenRepo->moduloDisponivel() ? badgeSoft('API pronta') : badgeSoft('migration 078 pendente') ?>
        </div>

        <div class="reports-endpoints">
            <?php foreach ($tipos as $codigo => $tipo): ?>
                <?php $endpoint = $baseUrl . '/operacao/api/relatorios/' . $tipo['endpoint']; ?>
                <div class="reports-endpoint-row">
                    <div>
                        <strong><?= h($tipo['label']) ?></strong>
                        <code><?= h($endpoint) ?></code>
                    </div>
                    <small>?data_inicio=2026-08-01&amp;data_fim=2026-08-31&amp;credor_id=1&amp;token=SEU_TOKEN</small>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="reports-api-note">
            <strong>Excel:</strong> Dados → Obter Dados → Da Web. Cole a URL do endpoint com período e token. Para integrações permanentes, trate o token como senha e não compartilhe a URL.
        </div>

        <?php if ($podeGerenciarApi): ?>
            <div class="reports-token-grid">
                <div class="reports-token-create">
                    <h3>Gerar token para este usuário</h3>
                    <?php if (!$tokenRepo->moduloDisponivel()): ?>
                        <p class="muted">Execute <code>078_relatorios_csv_api.sql</code> para habilitar tokens.</p>
                    <?php else: ?>
                        <form method="post" class="form-card reports-token-form">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="criar_token">
                            <label class="field">
                                <span>Identificação</span>
                                <input type="text" name="nome_token" maxlength="120" value="Excel / Planejamento" required>
                            </label>
                            <label class="field">
                                <span>Validade em dias</span>
                                <input type="number" name="validade_dias" min="0" max="3650" value="365">
                                <small>0 = sem expiração.</small>
                            </label>
                            <button class="button primary" type="submit">Gerar token</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($tokenGerado !== null): ?>
                        <div class="reports-token-once">
                            <strong>Copie agora</strong>
                            <div class="reports-copy-line">
                                <code id="works-report-token"><?= h($tokenGerado['token']) ?></code>
                                <button type="button" class="button secondary table-button" data-copy-report-token>Copiar</button>
                            </div>
                            <small>O banco guarda somente o hash do token. Não é possível consultar o texto dele depois.</small>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="reports-token-list">
                    <h3>Tokens deste usuário</h3>
                    <?php if ($tokens === []): ?>
                        <div class="empty">Nenhum token criado.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Nome</th><th>Prefixo</th><th>Status</th><th>Último uso</th><th>Expira</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($tokens as $token): ?>
                                    <?php $ativo = (int) ($token['ativo'] ?? 0) === 1 && empty($token['revogado_em']); ?>
                                    <tr>
                                        <td><strong><?= h($token['nome']) ?></strong></td>
                                        <td><code><?= h($token['token_prefixo']) ?>...</code></td>
                                        <td><?= $ativo ? badgeSoft('ativo') : badge('revogado') ?></td>
                                        <td><?= h(dataHoraBr($token['ultimo_uso_em'] ?? null)) ?></td>
                                        <td><?= h(dataHoraBr($token['expira_em'] ?? null)) ?></td>
                                        <td>
                                            <?php if ($ativo): ?>
                                                <form method="post" onsubmit="return confirm('Revogar este token? O Excel deixará de conseguir consultar imediatamente.');">
                                                    <?= authCsrfInput() ?>
                                                    <input type="hidden" name="acao" value="revogar_token">
                                                    <input type="hidden" name="token_id" value="<?= (int) $token['id'] ?>">
                                                    <button class="button danger table-button" type="submit">Revogar</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <p class="muted">Seu perfil pode baixar relatórios pelo Pactua, mas não pode criar credenciais externas para Excel/API.</p>
        <?php endif; ?>
    </section>
</main>
<script>
(() => {
    const button = document.querySelector('[data-copy-report-token]');
    const token = document.getElementById('works-report-token');
    if (!button || !token) return;
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(token.textContent.trim());
            const old = button.textContent;
            button.textContent = 'Copiado';
            setTimeout(() => button.textContent = old, 1400);
        } catch (_) {
            window.prompt('Copie o token:', token.textContent.trim());
        }
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
