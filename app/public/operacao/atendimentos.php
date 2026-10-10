<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoRepository;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('atendimentos.visualizar');

$pageTitle = 'Atendimentos Recentes';
$pageSubtitle = 'Acompanhamento das últimas tentativas registradas na operação.';
$activePage = 'atendimentos';
$erro = null;
$moduloDisponivel = false;
$resultadoPadronizadoDisponivel = false;
$resultadosAtendimento = [];
$resumo = [
    'total_atendimentos' => 0,
    'total_hoje' => 0,
    'proximos_pendentes' => 0,
];
$atendimentos = [];
$filtros = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'canal' => trim((string) ($_GET['canal'] ?? '')),
    'resultado' => trim((string) ($_GET['resultado'] ?? '')),
    'resultado_id' => trim((string) ($_GET['resultado_id'] ?? '')),
    'data_inicio' => trim((string) ($_GET['data_inicio'] ?? '')),
    'data_fim' => trim((string) ($_GET['data_fim'] ?? '')),
];

try {
    $repository = new AtendimentoRepository(operacaoPdo());
    $escopoConsulta = usuarioEscopoCredoresAcao('consultar');
    $moduloDisponivel = $repository->moduloDisponivel();

    if ($moduloDisponivel) {
        $resultadoPadronizadoDisponivel = $repository->resultadoPadronizadoDisponivel();
        $resultadosAtendimento = $repository->listarResultadosAtivos();
        $resumo = $repository->resumoGeral();
        $atendimentos = $repository->listarRecentes($filtros, 80, $escopoConsulta);
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

http_response_code($erro === null ? 200 : 500);
require __DIR__ . '/includes/header.php';
?>
<main class="container">
    <?php if ($erro !== null): ?>
        <section class="card error">
            <strong>Erro ao carregar atendimentos:</strong> <?= h($erro) ?>
        </section>
    <?php endif; ?>

    <?php if (!$moduloDisponivel): ?>
        <section class="card error">
            <strong>Módulo de atendimento indisponível.</strong>
            Execute a migration <code>009_operacao_historico_atendimentos.sql</code> antes de usar esta tela.
        </section>
    <?php else: ?>
        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Resumo dos atendimentos</h2>
                    <p>Indicadores simples da tabela <code>atendimentos_historico</code>.</p>
                </div>
                <?= badgeSoft($resultadoPadronizadoDisponivel ? 'tabulação padronizada' : 'compatibilidade legado') ?>
            </div>

            <div class="metrics-grid">
                <div class="metric">
                    <small>Total registrado</small>
                    <strong><?= number_format($resumo['total_atendimentos'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Registrados hoje</small>
                    <strong><?= number_format($resumo['total_hoje'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Próximos acionamentos</small>
                    <strong><?= number_format($resumo['proximos_pendentes'], 0, ',', '.') ?></strong>
                </div>
                <div class="metric">
                    <small>Exibindo na lista</small>
                    <strong><?= number_format(count($atendimentos), 0, ',', '.') ?></strong>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Filtros</h2>
                    <p>Use filtros leves. A lista permanece limitada para não pesar o MySQL operacional.</p>
                </div>
            </div>

            <form class="form-card" method="get" action="/operacao/atendimentos.php">
                <div class="form-grid filters-grid">
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
                            <a class="button secondary" href="/operacao/atendimentos.php">Limpar</a>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <h2>Últimas tentativas</h2>
                    <p>Lista operacional dos atendimentos manuais registrados nas fichas dos clientes.</p>
                </div>
                <span class="badge"><?= count($atendimentos) ?> registro(s)</span>
            </div>

            <?php if ($atendimentos === []): ?>
                <div class="empty">Nenhum atendimento encontrado para os filtros atuais.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="atendimentos-table">
                        <thead>
                        <tr>
                            <th>Data/hora</th>
                            <th>Cliente</th>
                            <th>Documento</th>
                            <th>Canal</th>
                            <th>Resultado</th>
                            <th>Contato usado</th>
                            <th>Contrato</th>
                            <th>Operador</th>
                            <th>Próximo acionamento</th>
                            <th>Ação</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($atendimentos as $atendimento): ?>
                            <?php
                            $contratoLabel = trim((string) ($atendimento['codigo_contrato'] ?? ''));
                            if ($contratoLabel !== '' && !empty($atendimento['credor_nome'])) {
                                $contratoLabel .= ' / ' . (string) $atendimento['credor_nome'];
                            }
                            $contatoUsado = $atendimento['contato_utilizado'] ?: ($atendimento['contato_valor'] ?? null);
                            $operador = trim((string) ($atendimento['operador_nome'] ?? ''));
                            if ($operador === '') {
                                $operador = 'sistema/dev';
                            }
                            ?>
                            <tr>
                                <td class="nowrap"><?= h(dataHoraBr($atendimento['criado_em'])) ?></td>
                                <td><strong><?= h($atendimento['pessoa_nome']) ?></strong></td>
                                <td class="nowrap"><?= documentoFormatado((string) $atendimento['pessoa_documento']) ?></td>
                                <td><?= badge(AtendimentoRepository::canalLabel((string) $atendimento['canal'])) ?></td>
                                <td>
                                    <?= badge(AtendimentoRepository::resultadoLabelRegistro($atendimento)) ?>
                                    <?php if (!empty($atendimento['resultado_categoria'])): ?><?= badgeSoft((string) $atendimento['resultado_categoria']) ?><?php endif; ?>
                                </td>
                                <td><?= valorOuTraco($contatoUsado) ?></td>
                                <td><?= valorOuTraco($contratoLabel) ?></td>
                                <td><?= h($operador) ?></td>
                                <td class="nowrap"><?= h(dataHoraBr($atendimento['proximo_acionamento'])) ?></td>
                                <td>
                                    <a class="button secondary table-button" href="<?= h(worksAtendimentoUrl(!empty($atendimento['contrato_id']) ? (int) $atendimento['contrato_id'] : null, (int) $atendimento['pessoa_id'], 'acionamento')) ?>">Abrir no Pactua</a>
                                </td>
                            </tr>
                            <?php if (!empty($atendimento['observacao'])): ?>
                                <tr class="observation-row">
                                    <td colspan="10"><strong>Observação:</strong> <?= h($atendimento['observacao']) ?></td>
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
