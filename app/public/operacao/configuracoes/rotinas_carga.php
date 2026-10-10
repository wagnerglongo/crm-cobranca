<?php

declare(strict_types=1);

use CRM\Infrastructure\Security\CredencialCryptoService;
use CRM\Modules\Cargas\Repositories\RotinaCargaSftpRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('cargas.gerenciar_rotinas');

$pageTitle = 'Rotinas de Carga';
$pageSubtitle = 'Coleta automática de arquivos de carga via SFTP, por credor.';
$activePage = 'rotinas_carga';

$pdo = operacaoPdo();
$repository = new RotinaCargaSftpRepository($pdo, new CredencialCryptoService());
$mensagemSucesso = null;
$mensagemErro = null;

$periodicidadeLabel = ['diario' => 'Diário', 'seg_sex' => 'Seg–Sex', 'manual' => 'Manual'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = trim((string) ($_POST['acao'] ?? ''));

        if ($acao === 'criar_rotina') {
            $novoId = $repository->criar($_POST, usuarioAtualId());
            registrarAuditoriaSistema('rotina_carga.criada', 'rotinas_carga_sftp', $novoId, 'Rotina de coleta de carga via SFTP cadastrada.', null, ['nome' => (string) ($_POST['nome'] ?? '')]);
            header('Location: /operacao/configuracoes/rotinas_carga.php?ok=criada');
            exit;
        }

        if ($acao === 'atualizar_rotina') {
            $id = isset($_POST['rotina_id']) && ctype_digit((string) $_POST['rotina_id']) ? (int) $_POST['rotina_id'] : 0;
            $repository->atualizar($id, $_POST, usuarioAtualId());
            registrarAuditoriaSistema('rotina_carga.atualizada', 'rotinas_carga_sftp', $id, 'Rotina de coleta de carga via SFTP atualizada.', null, ['nome' => (string) ($_POST['nome'] ?? '')]);
            header('Location: /operacao/configuracoes/rotinas_carga.php?ok=atualizada');
            exit;
        }

        if ($acao === 'alternar_status') {
            $id = isset($_POST['rotina_id']) && ctype_digit((string) $_POST['rotina_id']) ? (int) $_POST['rotina_id'] : 0;
            $ativa = (string) ($_POST['ativa'] ?? '0') === '1';
            $repository->alternarStatus($id, $ativa, usuarioAtualId());
            registrarAuditoriaSistema('rotina_carga.status_alterado', 'rotinas_carga_sftp', $id, 'Status da rotina de coleta alterado.', null, ['ativa' => $ativa]);
            header('Location: /operacao/configuracoes/rotinas_carga.php?ok=status');
            exit;
        }

        if ($acao === 'executar_agora') {
            $id = isset($_POST['rotina_id']) && ctype_digit((string) $_POST['rotina_id']) ? (int) $_POST['rotina_id'] : 0;
            if ($repository->buscarPorId($id) === null) {
                throw new InvalidArgumentException('Rotina não encontrada.');
            }
            $repository->marcarProcessando($id);
            // Roda em segundo plano (proc_open detached, sem esperar terminar) -
            // a conexao SFTP pode demorar ou nunca responder (host fora do ar,
            // firewall descartando pacote), e isso nao pode travar a tela do
            // operador esperando. O resultado aparece na tabela quando terminar
            // (ExecutarRotinaCargaSftpService grava direto no banco).
            $script = __DIR__ . '/../../../console/executar_rotinas_carga.php';
            $usuarioIdAtual = usuarioAtualId() ?? 0;
            $cmd = 'php ' . escapeshellarg($script) . ' --rotina-id=' . $id . ' --usuario-id=' . $usuarioIdAtual;
            $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
            $processo = proc_open($cmd, $descriptorSpec, $pipes);
            if ($processo !== false) {
                // Fecha o stdin e solta o handle sem chamar proc_close()/esperar -
                // o processo filho continua rodando sozinho depois que essa
                // requisicao termina (e' exatamente o "segundo plano" pedido).
                fclose($pipes[0]);
            }
            registrarAuditoriaSistema('rotina_carga.execucao_disparada', 'rotinas_carga_sftp', $id, 'Execução manual disparada em segundo plano pela tela.', null, []);
            header('Location: /operacao/configuracoes/rotinas_carga.php?ok=disparada');
            exit;
        }
    }

    $rotinas = $repository->listar();
    $credores = $pdo->query('SELECT id, codigo, nome FROM credores ORDER BY nome ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rotinaIdEditar = isset($_GET['editar']) && ctype_digit((string) $_GET['editar']) ? (int) $_GET['editar'] : 0;
    $rotinaEditar = $rotinaIdEditar > 0 ? $repository->buscarPorId($rotinaIdEditar) : null;
} catch (Throwable $e) {
    $mensagemErro = $mensagemErro ?? $e->getMessage();
    $rotinas = isset($rotinas) && is_array($rotinas) ? $rotinas : [];
    $credores = isset($credores) && is_array($credores) ? $credores : [];
    $rotinaEditar = null;
}

$ok = trim((string) ($_GET['ok'] ?? ''));
$mensagemSucesso ??= match ($ok) {
    'criada' => 'Rotina cadastrada com sucesso.',
    'atualizada' => 'Rotina atualizada com sucesso.',
    'status' => 'Status da rotina atualizado.',
    'disparada' => 'Execução iniciada em segundo plano. O resultado aparece na tabela abaixo assim que terminar — atualize a página em alguns instantes.',
    default => null,
};

$totalRotinas = count($rotinas);
$totalAtivas = count(array_filter($rotinas, static fn (array $r): bool => (string) $r['status'] === 'ativa'));
$totalPausadas = $totalRotinas - $totalAtivas;
$proximaExecucao = null;
foreach ($rotinas as $r) {
    if ((string) $r['status'] !== 'ativa' || $r['horario'] === null) {
        continue;
    }
    if ($proximaExecucao === null || (string) $r['horario'] < $proximaExecucao) {
        $proximaExecucao = (string) $r['horario'];
    }
}

http_response_code($mensagemErro === null ? 200 : 500);
require __DIR__ . '/../includes/header.php';
?>
<main class="container config-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <?php if ($mensagemSucesso !== null): ?><section class="alert success"><?= h($mensagemSucesso) ?></section><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><section class="alert danger"><strong>Não foi possível concluir a operação.</strong> <?= h($mensagemErro) ?></section><?php endif; ?>
    <?php if (!$repository->moduloDisponivel()): ?>
        <section class="card error"><strong>Tabelas de rotinas de carga não encontradas.</strong><p>Execute a migration <code>091_rotinas_carga_sftp.sql</code>.</p></section>
    <?php else: ?>

        <section class="metrics-grid metrics-grid-wide">
            <div class="metric"><small>Total de rotinas</small><strong><?= $totalRotinas ?></strong></div>
            <div class="metric metric-success"><small>Ativas</small><strong><?= $totalAtivas ?></strong></div>
            <div class="metric"><small>Pausadas</small><strong><?= $totalPausadas ?></strong></div>
            <div class="metric"><small>Próxima execução</small><strong><?= $proximaExecucao !== null ? h(mb_substr($proximaExecucao, 0, 5)) : '—' ?></strong></div>
        </section>

        <section class="card">
            <div class="section-title"><div><h2><?= $rotinaEditar !== null ? 'Editar rotina' : 'Nova rotina' ?></h2><p>Cadastre a origem SFTP, credenciais e agendamento de coleta.</p></div></div>
            <form method="post" action="/operacao/configuracoes/rotinas_carga.php" class="form-card">
                <?= authCsrfInput() ?>
                <input type="hidden" name="acao" value="<?= $rotinaEditar !== null ? 'atualizar_rotina' : 'criar_rotina' ?>">
                <?php if ($rotinaEditar !== null): ?><input type="hidden" name="rotina_id" value="<?= (int) $rotinaEditar['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <label class="field"><span>Nome da rotina</span><input type="text" name="nome" maxlength="160" required placeholder="Ex.: ACME - Carga diária" value="<?= h((string) ($rotinaEditar['nome'] ?? '')) ?>"></label>
                    <label class="field"><span>Credor</span>
                        <select name="credor_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($credores as $credor): ?>
                                <option value="<?= (int) $credor['id'] ?>"<?= (int) ($rotinaEditar['credor_id'] ?? 0) === (int) $credor['id'] ? ' selected' : '' ?>><?= h((string) $credor['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field"><span>Descrição</span><input type="text" name="descricao" maxlength="500" placeholder="Descreva o objetivo desta rotina" value="<?= h((string) ($rotinaEditar['descricao'] ?? '')) ?>"></label>
                    <label class="field"><span>Protocolo</span><select name="protocolo"><option value="sftp" selected>SFTP</option></select></label>
                    <label class="field"><span>Host de origem</span><input type="text" name="origem_host" maxlength="255" required placeholder="sftp.credor.com.br" value="<?= h((string) ($rotinaEditar['origem_host'] ?? '')) ?>"></label>
                    <label class="field"><span>Porta</span><input type="number" name="origem_porta" min="1" max="65535" value="<?= (int) ($rotinaEditar['origem_porta'] ?? 22) ?>"></label>
                    <label class="field"><span>Pasta de origem</span><input type="text" name="origem_caminho" maxlength="500" placeholder="/cargas" value="<?= h((string) ($rotinaEditar['origem_caminho'] ?? '/')) ?>"></label>
                    <label class="field"><span>Destino</span><input type="text" value="<?= h($rotinaEditar !== null ? (string) $rotinaEditar['destino_caminho'] : 'definido pelo credor escolhido') ?>" disabled></label>
                    <label class="field"><span>Usuário</span><input type="text" name="usuario" maxlength="150" required value="<?= h((string) ($rotinaEditar['usuario'] ?? '')) ?>"></label>
                    <label class="field"><span>Senha</span><input type="password" name="senha" maxlength="255" autocomplete="new-password" placeholder="<?= $rotinaEditar !== null ? 'Deixe em branco para manter a atual' : '' ?>"<?= $rotinaEditar === null ? ' required' : '' ?>></label>
                    <label class="field"><span>Periodicidade</span>
                        <select name="periodicidade">
                            <?php foreach ($periodicidadeLabel as $valor => $rotulo): ?>
                                <option value="<?= h($valor) ?>"<?= (string) ($rotinaEditar['periodicidade'] ?? 'diario') === $valor ? ' selected' : '' ?>><?= h($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field"><span>Horário</span><input type="time" name="horario" value="<?= h(mb_substr((string) ($rotinaEditar['horario'] ?? '05:00:00'), 0, 5)) ?>"></label>
                    <label class="field"><span>Padrão do arquivo (regex, opcional)</span><input type="text" name="padrao_arquivo" maxlength="255" placeholder="Ex.: ^ACME_.*\.txt$" value="<?= h((string) ($rotinaEditar['padrao_arquivo'] ?? '')) ?>"></label>
                </div>
                <small class="works-field-help">A senha é criptografada antes de ir para o banco e nunca é mostrada de novo — para trocá-la, digite uma nova.</small>
                <div class="actions-row form-actions">
                    <button class="button" type="submit"><?= $rotinaEditar !== null ? 'Salvar alterações' : 'Salvar rotina' ?></button>
                    <?php if ($rotinaEditar !== null): ?><a class="button secondary" href="/operacao/configuracoes/rotinas_carga.php">Cancelar edição</a><?php endif; ?>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-title"><div><h2>Rotinas cadastradas</h2><p>Visualização das configurações e horários de execução.</p></div><span class="badge"><?= $totalRotinas ?></span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Data/Hora</th><th>Nome</th><th>Origem</th><th>Usuário</th><th>Destino</th><th>Status</th><th>Última execução</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php if ($rotinas === []): ?><tr><td colspan="8" class="empty">Nenhuma rotina cadastrada.</td></tr><?php endif; ?>
                        <?php foreach ($rotinas as $rotina): ?>
                            <?php $rotinaAtiva = (string) $rotina['status'] === 'ativa'; ?>
                            <tr>
                                <td><?= h($periodicidadeLabel[(string) $rotina['periodicidade']] ?? (string) $rotina['periodicidade']) ?><?php if ($rotina['horario'] !== null): ?><div class="muted"><?= h(mb_substr((string) $rotina['horario'], 0, 5)) ?></div><?php endif; ?></td>
                                <td><strong><?= h((string) $rotina['nome']) ?></strong><div class="muted"><?= h((string) $rotina['credor_nome']) ?></div></td>
                                <td><code><?= h((string) $rotina['origem_host']) ?>:<?= (int) $rotina['origem_porta'] ?></code><div class="muted"><?= h((string) $rotina['origem_caminho']) ?></div></td>
                                <td><?= h((string) $rotina['usuario']) ?></td>
                                <td><code><?= h((string) $rotina['destino_caminho']) ?></code></td>
                                <td>
                                    <?= $rotinaAtiva ? '<span class="badge badge-ok">Ativa</span>' : '<span class="badge badge-warning">Pausada</span>' ?>
                                    <?php if (!$rotina['comando_disponivel']): ?><div class="muted">sem processamento automático</div><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rotina['ultima_execucao_em'] !== null): ?>
                                        <?php
                                        $statusExecucao = (string) $rotina['ultima_execucao_status'];
                                        $segundosDesde = time() - strtotime((string) $rotina['ultima_execucao_em']);
                                        $processandoTravado = $statusExecucao === 'processando' && $segundosDesde > 180;
                                        ?>
                                        <?php if ($processandoTravado): ?>
                                            <span class="badge badge-warning">travou?</span>
                                            <div class="muted">iniciou há <?= (int) ($segundosDesde / 60) ?> min e não terminou — tente executar de novo</div>
                                        <?php elseif ($statusExecucao === 'processando'): ?>
                                            <span class="badge badge-soft">processando…</span>
                                            <div class="muted">iniciou <?= h((string) $rotina['ultima_execucao_em']) ?></div>
                                        <?php else: ?>
                                            <span class="badge <?= $statusExecucao === 'sucesso' ? 'badge-ok' : 'badge-danger' ?>"><?= h($statusExecucao) ?></span>
                                            <div class="muted"><?= h((string) $rotina['ultima_execucao_em']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?><span class="muted">nunca executada</span><?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <a class="button secondary table-button" href="/operacao/configuracoes/rotinas_carga.php?editar=<?= (int) $rotina['id'] ?>">Editar</a>
                                    <form method="post" style="display:inline">
                                        <?= authCsrfInput() ?>
                                        <input type="hidden" name="acao" value="executar_agora">
                                        <input type="hidden" name="rotina_id" value="<?= (int) $rotina['id'] ?>">
                                        <button class="button secondary table-button" type="submit">Executar</button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('<?= $rotinaAtiva ? 'Pausar' : 'Ativar' ?> esta rotina?');">
                                        <?= authCsrfInput() ?>
                                        <input type="hidden" name="acao" value="alternar_status">
                                        <input type="hidden" name="rotina_id" value="<?= (int) $rotina['id'] ?>">
                                        <input type="hidden" name="ativa" value="<?= $rotinaAtiva ? '0' : '1' ?>">
                                        <button class="button secondary table-button" type="submit"><?= $rotinaAtiva ? 'Pausar' : 'Ativar' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
