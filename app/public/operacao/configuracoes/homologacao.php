<?php

declare(strict_types=1);

use CRM\Config\Database;
use CRM\Infrastructure\Homologacao\CopiaBancoHomologacaoRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoAdminTecnico();

$pageTitle = 'Homologação';
$pageSubtitle = 'Cópia dos dados de produção para o ambiente de testes: toda semana ou na hora.';
$activePage = 'homologacao';

$repo = new CopiaBancoHomologacaoRepository(operacaoPdo());
// A tela da homologação mostra a cópia da tabela, que ninguém executa: as ações só valem na produção.
$ehHomologacao = Database::env('APP_ENV') === 'homolog';
$urlHomologacao = (string) Database::env('HOMOLOG_URL', 'http://10.0.0.74:8081/operacao/');
$mensagemSucesso = null;
$mensagemErro = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        if ($ehHomologacao) {
            throw new RuntimeException('Esta tela só agenda cópias na produção.');
        }
        $acao = trim((string) ($_POST['acao'] ?? ''));

        if ($acao === 'salvar_agenda') {
            $ativo = (string) ($_POST['ativo'] ?? '0') === '1';
            $dia = (int) ($_POST['dia_semana'] ?? 0);
            $horario = (string) ($_POST['horario'] ?? '');
            $repo->salvarAgenda($ativo, $dia, $horario, usuarioAtualId());
            registrarAuditoriaSistema('homologacao.agenda_atualizada', 'homologacao_agenda', 1, 'Agendamento da cópia semanal da homologação alterado.', null, ['ativo' => $ativo, 'dia_semana' => $dia, 'horario' => $horario]);
            header('Location: /operacao/configuracoes/homologacao.php?ok=agenda');
            exit;
        }

        if ($acao === 'copiar_agora') {
            $id = $repo->solicitarAgora(usuarioAtualId());
            registrarAuditoriaSistema('homologacao.copia_solicitada', 'homologacao_copias', $id, 'Cópia do banco de produção para a homologação pedida pela tela.', null, []);
            header('Location: /operacao/configuracoes/homologacao.php?ok=solicitada');
            exit;
        }

        if ($acao === 'cancelar') {
            $id = isset($_POST['copia_id']) && ctype_digit((string) $_POST['copia_id']) ? (int) $_POST['copia_id'] : 0;
            $repo->cancelar($id);
            registrarAuditoriaSistema('homologacao.copia_cancelada', 'homologacao_copias', $id, 'Cópia da homologação cancelada antes de começar.', null, []);
            header('Location: /operacao/configuracoes/homologacao.php?ok=cancelada');
            exit;
        }
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

$moduloDisponivel = $repo->moduloDisponivel();
$agora = new DateTimeImmutable('now');
$agenda = $moduloDisponivel ? $repo->agenda() : null;
$andamento = $moduloDisponivel ? $repo->emAndamento() : null;
$ultima = $moduloDisponivel ? $repo->ultimaConcluida() : null;
$proxima = $moduloDisponivel ? $repo->proximaAgendada($agora) : null;
$copias = $moduloDisponivel ? $repo->listar(30) : [];

$ok = trim((string) ($_GET['ok'] ?? ''));
$mensagemSucesso ??= match ($ok) {
    'agenda' => 'Agendamento salvo.',
    'solicitada' => 'Cópia pedida. Ela começa em até 1 minuto e leva uns 2 minutos; esta página se atualiza sozinha.',
    'cancelada' => 'Cópia cancelada.',
    default => null,
};

$statusRotulo = [
    'pendente' => ['aguardando', 'badge-soft'],
    'executando' => ['copiando…', 'badge-warning'],
    'concluida' => ['concluída', 'badge-ok'],
    'erro' => ['erro', 'badge-danger'],
    'cancelada' => ['cancelada', 'badge-soft'],
];
$dataHora = static fn (?string $valor): string => $valor !== null && $valor !== '' ? date('d/m/Y H:i', (int) strtotime($valor)) : '—';
$duracao = static function (array $copia): string {
    if ($copia['iniciado_em'] === null || $copia['finalizado_em'] === null) {
        return '—';
    }
    $segundos = max(0, (int) strtotime((string) $copia['finalizado_em']) - (int) strtotime((string) $copia['iniciado_em']));
    return $segundos >= 60 ? intdiv($segundos, 60) . ' min ' . ($segundos % 60) . ' s' : $segundos . ' s';
};

http_response_code($mensagemErro === null ? 200 : 500);
require __DIR__ . '/../includes/header.php';
?>
<main class="container config-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <?php if ($mensagemSucesso !== null): ?><section class="alert success"><?= h($mensagemSucesso) ?></section><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><section class="alert danger"><strong>Não foi possível concluir a operação.</strong> <?= h($mensagemErro) ?></section><?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error"><strong>Tabelas da homologação não encontradas.</strong><p>Execute a migration <code>099_homologacao_copia_banco.sql</code>.</p></section>
    <?php else: ?>
        <?php if ($ehHomologacao): ?>
            <section class="alert warning"><strong>Você está na homologação.</strong> A cópia é agendada e pedida pela produção; aqui é só consulta.</section>
        <?php endif; ?>

        <section class="metrics-grid metrics-grid-wide">
            <div class="metric metric-success"><small>Última cópia</small><strong><?= $ultima !== null ? h($dataHora((string) $ultima['finalizado_em'])) : '—' ?></strong></div>
            <div class="metric"><small>Próxima agendada</small><strong><?= $proxima !== null ? h(mb_strtolower(CopiaBancoHomologacaoRepository::DIAS_SEMANA[(int) $proxima->format('N')]) . ' ' . $proxima->format('d/m H:i')) : 'desligada' ?></strong></div>
            <div class="metric<?= $andamento !== null ? ' metric-warning' : '' ?>"><small>Agora</small><strong><?= $andamento === null ? 'nenhuma cópia' : ($andamento['status'] === 'pendente' ? 'aguardando início' : 'copiando…') ?></strong></div>
            <div class="metric"><small>Endereço da homologação</small><strong><a href="<?= h($urlHomologacao) ?>" target="_blank" rel="noopener">abrir</a></strong></div>
        </section>

        <section class="grid-2 grid-2-wide">
            <div class="card">
                <div class="section-title"><div><h2>Copiar agora</h2><p>Troca os dados da homologação pelos de produção de agora.</p></div></div>
                <ul>
                    <li>Copia tudo, menos as tabelas temporárias de carga (staging). Leva uns 2 minutos.</li>
                    <li>A produção não para nem é alterada.</li>
                    <li>Na cópia, integrações com credores e rotinas SFTP ficam <strong>desligadas</strong>: nada sai para fora.</li>
                    <li>O que foi feito de teste na homologação é apagado.</li>
                </ul>
                <?php if ($andamento !== null): ?>
                    <p><span class="badge <?= h($statusRotulo[(string) $andamento['status']][1]) ?>"><?= h($statusRotulo[(string) $andamento['status']][0]) ?></span>
                        Cópia #<?= (int) $andamento['id'] ?> <?= $andamento['status'] === 'pendente' ? 'pedida às ' . h($dataHora((string) $andamento['solicitado_em'])) : 'começou às ' . h($dataHora((string) $andamento['iniciado_em'])) ?>.</p>
                    <?php if ($andamento['status'] === 'pendente' && !$ehHomologacao): ?>
                        <form method="post" onsubmit="return confirm('Cancelar esta cópia?');">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="cancelar">
                            <input type="hidden" name="copia_id" value="<?= (int) $andamento['id'] ?>">
                            <button class="button secondary" type="submit">Cancelar</button>
                        </form>
                    <?php endif; ?>
                <?php elseif (!$ehHomologacao): ?>
                    <form method="post" onsubmit="return confirm('Apagar os dados da homologação e copiar os de produção agora?');">
                        <?= authCsrfInput() ?>
                        <input type="hidden" name="acao" value="copiar_agora">
                        <button class="button" type="submit">Copiar agora</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="section-title"><div><h2>Cópia semanal</h2><p>Roda sozinha no dia e hora escolhidos.</p></div><?= $agenda['ativo'] ? '<span class="badge badge-ok">ligada</span>' : '<span class="badge badge-warning">desligada</span>' ?></div>
                <form method="post" class="form-card">
                    <?= authCsrfInput() ?>
                    <input type="hidden" name="acao" value="salvar_agenda">
                    <div class="form-grid">
                        <label class="field"><span>Situação</span>
                            <select name="ativo"<?= $ehHomologacao ? ' disabled' : '' ?>>
                                <option value="1"<?= $agenda['ativo'] ? ' selected' : '' ?>>Ligada</option>
                                <option value="0"<?= !$agenda['ativo'] ? ' selected' : '' ?>>Desligada</option>
                            </select>
                        </label>
                        <label class="field"><span>Dia da semana</span>
                            <select name="dia_semana"<?= $ehHomologacao ? ' disabled' : '' ?>>
                                <?php foreach (CopiaBancoHomologacaoRepository::DIAS_SEMANA as $numero => $nome): ?>
                                    <option value="<?= $numero ?>"<?= $agenda['dia_semana'] === $numero ? ' selected' : '' ?>><?= h($nome) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field"><span>Horário</span><input type="time" name="horario" required value="<?= h($agenda['horario']) ?>"<?= $ehHomologacao ? ' disabled' : '' ?>></label>
                    </div>
                    <small class="works-field-help">Sugestão: domingo de madrugada, depois do backup (03:30) e sem carga da ACME.<?php if ($agenda['atualizado_por'] !== null): ?> Última alteração: <?= h($agenda['atualizado_por']) ?>, <?= h($dataHora($agenda['atualizado_em'])) ?>.<?php endif; ?></small>
                    <?php if (!$ehHomologacao): ?>
                        <div class="actions-row form-actions"><button class="button" type="submit">Salvar agendamento</button></div>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="section-title"><div><h2>Cópias feitas</h2><p>As 30 últimas. Se der erro, o administrador recebe e-mail.</p></div><span class="badge"><?= count($copias) ?></span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>Origem</th><th>Pedida</th><th>Início</th><th>Duração</th><th>Situação</th><th>Detalhe</th></tr></thead>
                    <tbody>
                        <?php if ($copias === []): ?><tr><td colspan="7" class="empty">Nenhuma cópia ainda.</td></tr><?php endif; ?>
                        <?php foreach ($copias as $copia): ?>
                            <?php [$rotulo, $classe] = $statusRotulo[(string) $copia['status']] ?? [(string) $copia['status'], 'badge-soft']; ?>
                            <tr>
                                <td><?= (int) $copia['id'] ?></td>
                                <td><?= $copia['origem'] === 'agendada' ? 'agendada' : 'manual' ?><?php if ($copia['solicitado_por'] !== null): ?><div class="muted"><?= h((string) $copia['solicitado_por']) ?></div><?php endif; ?></td>
                                <td><?= h($dataHora((string) $copia['solicitado_em'])) ?></td>
                                <td><?= h($dataHora($copia['iniciado_em'] !== null ? (string) $copia['iniciado_em'] : null)) ?></td>
                                <td><?= h($duracao($copia)) ?></td>
                                <td><span class="badge <?= h($classe) ?>"><?= h($rotulo) ?></span></td>
                                <td><?php if ($copia['mensagem'] !== null): ?><div class="muted" style="white-space:pre-line"><?= h((string) $copia['mensagem']) ?></div><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php if ($andamento !== null): ?>
<script>setTimeout(function () { window.location.href = '/operacao/configuracoes/homologacao.php'; }, 15000);</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
