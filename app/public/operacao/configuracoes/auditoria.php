<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AuditoriaSistemaRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('auditoria.visualizar');

$pageTitle = 'Auditoria Geral';
$pageSubtitle = 'Consulta central de ações sensíveis executadas por usuários no CRM.';
$activePage = 'auditoria';

$erro = null;
$moduloDisponivel = false;
$resumoGeral = [
    'total' => 0,
    'total_hoje' => 0,
    'usuarios' => 0,
    'eventos' => 0,
];
$resumoFiltros = ['total' => 0];
$eventosDisponiveis = [];
$entidadesDisponiveis = [];
$usuariosDisponiveis = [];
$registros = [];

$filtros = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'usuario_id' => trim((string) ($_GET['usuario_id'] ?? '')),
    'evento' => trim((string) ($_GET['evento'] ?? '')),
    'entidade_tipo' => trim((string) ($_GET['entidade_tipo'] ?? '')),
    'entidade_id' => trim((string) ($_GET['entidade_id'] ?? '')),
    'ip' => trim((string) ($_GET['ip'] ?? '')),
    'data_inicio' => trim((string) ($_GET['data_inicio'] ?? '')),
    'data_fim' => trim((string) ($_GET['data_fim'] ?? '')),
];

try {
    $repo = new AuditoriaSistemaRepository(operacaoPdo());
    $moduloDisponivel = $repo->moduloDisponivel();

    if ($moduloDisponivel) {
        $resumoGeral = $repo->resumoGeral();
        $resumoFiltros = $repo->resumoFiltros($filtros);
        $eventosDisponiveis = $repo->eventosDisponiveis();
        $entidadesDisponiveis = $repo->entidadesDisponiveis();
        $usuariosDisponiveis = $repo->usuariosDisponiveis();
        $registros = $repo->listar($filtros, 120);
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

function auditoriaPayloadFormatado(mixed $json): ?string
{
    $texto = trim((string) ($json ?? ''));
    if ($texto === '') {
        return null;
    }

    $decoded = json_decode($texto, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $texto;
    }

    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $pretty === false ? $texto : $pretty;
}

function auditoriaUsuarioLabel(array $registro): string
{
    $nome = trim((string) ($registro['usuario_nome'] ?? ''));
    $login = trim((string) ($registro['usuario_login'] ?? ''));

    if ($nome === '' && $login === '') {
        return 'sistema/sem usuário';
    }

    if ($login === '') {
        return $nome;
    }

    if ($nome === '') {
        return $login;
    }

    return $nome . ' (' . $login . ')';
}

function auditoriaEntidadeLabel(array $registro): string
{
    $tipo = trim((string) ($registro['entidade_tipo'] ?? ''));
    $id = trim((string) ($registro['entidade_id'] ?? ''));

    if ($tipo === '' && $id === '') {
        return '-';
    }

    if ($id === '') {
        return $tipo;
    }

    if ($tipo === '') {
        return '#' . $id;
    }

    return $tipo . ' #' . $id;
}

http_response_code($erro === null ? 200 : 500);
require __DIR__ . '/../includes/header.php';
?>
<main class="container">
    <?php if ($erro !== null): ?>
        <section class="card error">
            <strong>Erro ao carregar auditoria:</strong> <?= h($erro) ?>
        </section>
    <?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error">
            <strong>Auditoria geral indisponível.</strong>
            Execute a migration <code>024_auditoria_geral_sistema.sql</code> e depois a migration <code>025_tela_auditoria_geral.sql</code>.
        </section>
    <?php else: ?>
        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Resumo da auditoria</h2>
                    <p>Eventos gravados em <code>auditoria_sistema</code>.</p>
                </div>
                <?= badgeSoft('consulta administrativa') ?>
            </div>

            <div class="metrics-grid">
                <div class="metric">
                    <small>Total registrado</small>
                    <strong><?= number_format((int) $resumoGeral['total'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Hoje</small>
                    <strong><?= number_format((int) $resumoGeral['total_hoje'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Usuários auditados</small>
                    <strong><?= number_format((int) $resumoGeral['usuarios'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Tipos de evento</small>
                    <strong><?= number_format((int) $resumoGeral['eventos'], 0, ',', '.') ?></strong>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Filtros</h2>
                    <p>A consulta é limitada aos últimos 120 registros encontrados para manter a tela leve.</p>
                </div>
                <span class="badge"><?= number_format((int) $resumoFiltros['total'], 0, ',', '.') ?> registro(s) nos filtros</span>
            </div>

            <form class="form-card" method="get" action="/operacao/configuracoes/auditoria.php">
                <div class="form-grid filters-grid">
                    <label class="field">
                        <span>Busca livre</span>
                        <input type="search" name="q" value="<?= h($filtros['q']) ?>" placeholder="evento, descrição, usuário ou entidade">
                    </label>

                    <label class="field">
                        <span>Usuário</span>
                        <select name="usuario_id">
                            <option value="">Todos</option>
                            <?php foreach ($usuariosDisponiveis as $usuario): ?>
                                <option value="<?= (int) $usuario['id'] ?>"<?= $filtros['usuario_id'] === (string) $usuario['id'] ? ' selected' : '' ?>>
                                    <?= h((string) $usuario['nome']) ?> (<?= h((string) $usuario['login']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Evento</span>
                        <select name="evento">
                            <option value="">Todos</option>
                            <?php foreach ($eventosDisponiveis as $evento): ?>
                                <option value="<?= h((string) $evento['evento']) ?>"<?= $filtros['evento'] === (string) $evento['evento'] ? ' selected' : '' ?>>
                                    <?= h((string) $evento['evento']) ?> (<?= (int) $evento['total'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Entidade</span>
                        <select name="entidade_tipo">
                            <option value="">Todas</option>
                            <?php foreach ($entidadesDisponiveis as $entidade): ?>
                                <option value="<?= h((string) $entidade['entidade_tipo']) ?>"<?= $filtros['entidade_tipo'] === (string) $entidade['entidade_tipo'] ? ' selected' : '' ?>>
                                    <?= h((string) $entidade['entidade_tipo']) ?> (<?= (int) $entidade['total'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>ID da entidade</span>
                        <input type="number" min="1" name="entidade_id" value="<?= h($filtros['entidade_id']) ?>" placeholder="Ex.: 123">
                    </label>

                    <label class="field">
                        <span>IP</span>
                        <input type="search" name="ip" value="<?= h($filtros['ip']) ?>" placeholder="Ex.: 172.18">
                    </label>

                    <label class="field">
                        <span>Data inicial</span>
                        <input type="date" name="data_inicio" value="<?= h($filtros['data_inicio']) ?>">
                    </label>

                    <label class="field">
                        <span>Data final</span>
                        <input type="date" name="data_fim" value="<?= h($filtros['data_fim']) ?>">
                    </label>

                    <div class="field filter-actions">
                        <span>Ações</span>
                        <div class="actions-row">
                            <button class="button" type="submit">Filtrar</button>
                            <a class="button secondary" href="/operacao/configuracoes/auditoria.php">Limpar</a>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Registros de auditoria</h2>
                    <p>Últimos eventos sensíveis conforme os filtros aplicados.</p>
                </div>
                <span class="badge"><?= count($registros) ?> exibido(s)</span>
            </div>

            <?php if ($registros === []): ?>
                <div class="empty">Nenhum registro de auditoria encontrado.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="auditoria-sistema-table">
                        <thead>
                        <tr>
                            <th>Data/hora</th>
                            <th>Usuário</th>
                            <th>Evento</th>
                            <th>Entidade</th>
                            <th>Descrição</th>
                            <th>Origem</th>
                            <th>Payload</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($registros as $registro): ?>
                            <?php
                            $payloadAntes = auditoriaPayloadFormatado($registro['payload_antes'] ?? null);
                            $payloadDepois = auditoriaPayloadFormatado($registro['payload_depois'] ?? null);
                            ?>
                            <tr>
                                <td class="nowrap">
                                    <strong>#<?= (int) $registro['id'] ?></strong>
                                    <small><?= h(dataHoraBr($registro['criado_em'])) ?></small>
                                </td>
                                <td>
                                    <strong><?= h(auditoriaUsuarioLabel($registro)) ?></strong>
                                    <?php if (!empty($registro['usuario_id'])): ?>
                                        <small>ID <?= (int) $registro['usuario_id'] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= badge((string) $registro['evento']) ?></td>
                                <td><?= h(auditoriaEntidadeLabel($registro)) ?></td>
                                <td><?= valorOuTraco($registro['descricao'] ?? null) ?></td>
                                <td>
                                    <strong><?= valorOuTraco($registro['ip'] ?? null) ?></strong>
                                    <?php if (!empty($registro['user_agent'])): ?>
                                        <small title="<?= h((string) $registro['user_agent']) ?>"><?= h(mb_substr((string) $registro['user_agent'], 0, 80)) ?><?= mb_strlen((string) $registro['user_agent']) > 80 ? '...' : '' ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($payloadAntes === null && $payloadDepois === null): ?>
                                        <span class="muted">-</span>
                                    <?php else: ?>
                                        <?php if ($payloadAntes !== null): ?>
                                            <details class="json-details">
                                                <summary>Antes</summary>
                                                <pre class="json-box"><?= h($payloadAntes) ?></pre>
                                            </details>
                                        <?php endif; ?>
                                        <?php if ($payloadDepois !== null): ?>
                                            <details class="json-details">
                                                <summary>Depois</summary>
                                                <pre class="json-box"><?= h($payloadDepois) ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
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
