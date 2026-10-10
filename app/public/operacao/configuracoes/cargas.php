<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\CargasOperacaoRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoPermissao('cargas.visualizar');

$pageTitle = 'Gestão de Cargas 2.0';
$pageSubtitle = 'Acompanhe importações, erros, raw, staging e comandos seguros de reprocessamento.';
$activePage = 'cargas';

/**
 * cargas_arquivos.status usa o mesmo valor 'concluido'/'concluido_com_erros'
 * tanto para "importacao terminou" quanto para "merge nas tabelas
 * canonicas terminou" - sao fases distintas que so o campo
 * aplicacao_finalizada_em (NULL = merge ainda pendente) diferencia. Sem
 * isso a tela mostra "Concluído" para uma carga cujo merge nem rodou
 * ainda, o que já causou confusão real (carga #29 em 2026-09-15).
 * Layouts fora desta lista ainda não têm uma fase de aplicação separada
 * confirmada, então não exibimos o aviso para eles.
 *
 * @param array<string,mixed> $carga
 */
function situacaoCargaBadge(array $carga): string
{
    $layoutsComAplicacaoSeparada = ['ACME_COBRANCA_PIPE'];
    $status = (string) ($carga['status'] ?? '');
    $layout = (string) ($carga['layout_codigo'] ?? '');

    if (in_array($status, ['concluido', 'concluido_com_erros'], true)
        && in_array($layout, $layoutsComAplicacaoSeparada, true)
        && empty($carga['aplicacao_finalizada_em'])
    ) {
        return '<span class="badge badge-warning">Importado — merge pendente</span>';
    }

    return badge($status);
}

$repo = new CargasOperacaoRepository(operacaoPdo());
$mensagemSucesso = null;
$mensagemErro = null;
$filtros = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'credor_id' => (string) ($_GET['credor_id'] ?? ''),
    'status' => (string) ($_GET['status'] ?? 'todos'),
    'data_de' => (string) ($_GET['data_de'] ?? ''),
    'data_ate' => (string) ($_GET['data_ate'] ?? ''),
];
$cargaId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$escopo = usuarioEscopoCredoresAcao('consultar');
$moduloDisponivel = $repo->moduloDisponivel();
$resumo = $repo->resumo();
$cargas = [];
$credores = [];
$carga = null;
$erros = [];
$rawAmostra = [];
$stagingAmostra = [];
$statsCarga = ['raw' => 0, 'staging' => 0, 'erros' => 0];

try {
    if ($moduloDisponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = (string) ($_POST['acao'] ?? '');
        if ($acao === 'preparar_reprocessamento') {
            requireOperacaoPermissao('cargas.reprocessar');
            $id = isset($_POST['carga_id']) && ctype_digit((string) $_POST['carga_id']) ? (int) $_POST['carga_id'] : 0;
            $repo->prepararReprocessamento($id, usuarioAtualId() ?? 0);
            registrarAuditoriaSistema('carga.reprocessamento_preparado', 'cargas_arquivos', $id, 'Carga marcada para reprocessamento pela tela.', null, ['carga_id' => $id]);
            $mensagemSucesso = 'Carga #' . $id . ' marcada para reprocessamento.';
            $cargaId = $id;
        }
    }

    if ($moduloDisponivel) {
        $credores = $repo->listarCredores($escopo);
        $cargas = $repo->listarCargas($filtros, $escopo, 120);
        if ($cargaId > 0) {
            $carga = $repo->buscarCarga($cargaId);
            $erros = $repo->listarErros($cargaId, 120);
            $pdo = operacaoPdo();
            foreach ([
                'raw' => ['table' => 'cargas_linhas_raw', 'where' => 'carga_arquivo_id'],
                'staging' => ['table' => 'stg_carga_canonica', 'where' => 'carga_arquivo_id'],
                'erros' => ['table' => 'cargas_erros', 'where' => 'carga_arquivo_id'],
            ] as $key => $def) {
                try {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $def['table'] . ' WHERE ' . $def['where'] . ' = :id');
                    $stmt->bindValue('id', $cargaId, PDO::PARAM_INT);
                    $stmt->execute();
                    $statsCarga[$key] = (int) $stmt->fetchColumn();
                } catch (Throwable) { $statsCarga[$key] = 0; }
            }
            try {
                $stmtRaw = $pdo->prepare('SELECT id, numero_linha, linha_bruta, status, criado_em FROM cargas_linhas_raw WHERE carga_arquivo_id = :id ORDER BY numero_linha ASC LIMIT 20');
                $stmtRaw->bindValue('id', $cargaId, PDO::PARAM_INT);
                $stmtRaw->execute();
                $rawAmostra = $stmtRaw->fetchAll();
            } catch (Throwable) { $rawAmostra = []; }
            try {
                $stmtStg = $pdo->prepare('SELECT id, linha_raw_id AS numero_linha, documento, nome, codigo_contrato, saldo_atual, status FROM stg_carga_canonica WHERE carga_arquivo_id = :id ORDER BY numero_linha ASC LIMIT 20');
                $stmtStg->bindValue('id', $cargaId, PDO::PARAM_INT);
                $stmtStg->execute();
                $stagingAmostra = $stmtStg->fetchAll();
            } catch (Throwable) { $stagingAmostra = []; }
        }
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

$comandosCarga = [];
$avisoCarga = null;
if ($carga !== null) {
    $layoutCodigo = strtoupper((string) ($carga['layout_codigo'] ?? ''));
    if (strtoupper((string) ($carga['credor_nome'] ?? '')) === 'ACME') {
        $comandosCarga = [
            'docker exec crm_php php console/importar_acme.php ' . (string) ($carga['caminho_storage'] ?? '/CAMINHO/ARQUIVO') . ' --force',
            'docker exec crm_php php console/aplicar_carga_acme.php --carga=' . (int) $carga['id'],
        ];
    }
}

require __DIR__ . '/../includes/header.php';
?>
<main class="container config-page config-loads-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <?php if ($mensagemSucesso !== null): ?><div class="alert success"><?= h($mensagemSucesso) ?></div><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><div class="alert danger"><?= h($mensagemErro) ?></div><?php endif; ?>
    <?php if (!$moduloDisponivel): ?>
        <section class="card error"><strong>As tabelas de carga ainda não foram encontradas.</strong><p>Confirme as migrations base de cargas.</p></section>
    <?php else: ?>
        <section class="metrics-grid metrics-grid-wide"><div class="metric"><small>Total</small><strong><?= number_format((int) $resumo['total'],0,',','.') ?></strong></div><div class="metric metric-success"><small>Concluídas</small><strong><?= number_format((int) $resumo['sucesso'],0,',','.') ?></strong></div><div class="metric metric-danger"><small>Erro</small><strong><?= number_format((int) $resumo['erro'],0,',','.') ?></strong></div><div class="metric"><small>Processando</small><strong><?= number_format((int) $resumo['processando'],0,',','.') ?></strong></div><div class="metric metric-warning"><small>Pendentes</small><strong><?= number_format((int) $resumo['pendentes'],0,',','.') ?></strong></div></section>
        <section class="card"><form class="filter-form" method="get"><label class="field"><span>Busca</span><input name="q" value="<?= h($filtros['q']) ?>" placeholder="arquivo, hash ou erro"></label><label class="field"><span>Credor</span><select name="credor_id"><option value="">Todos</option><?php foreach ($credores as $credor): ?><option value="<?= (int) $credor['id'] ?>" <?= $filtros['credor_id']==(string)$credor['id']?'selected':'' ?>><?= h((string)$credor['nome']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Status</span><select name="status"><?php foreach (['todos'=>'Todos','recebido'=>'Recebido','aguardando_processamento'=>'Aguardando','concluido'=>'Concluído','concluido_com_erros'=>'Concluído com erros','erro'=>'Erro'] as $v=>$l): ?><option value="<?= h($v) ?>" <?= $filtros['status']===$v?'selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select></label><label class="field"><span>De</span><input type="date" name="data_de" value="<?= h($filtros['data_de']) ?>"></label><label class="field"><span>Até</span><input type="date" name="data_ate" value="<?= h($filtros['data_ate']) ?>"></label><button class="button" type="submit">Filtrar</button><a class="button secondary" href="/operacao/configuracoes/cargas.php">Limpar</a></form></section>
        <section class="grid-2 grid-2-wide">
            <div class="card"><div class="section-title"><div><h2>Cargas recentes</h2><p>Clique em uma carga para ver detalhes.</p></div><span class="badge"><?= count($cargas) ?></span></div><div class="table-wrap"><table><thead><tr><th>ID</th><th>Arquivo</th><th>Credor</th><th>Status</th><th>Linhas</th><th>Erros</th><th></th></tr></thead><tbody><?php foreach ($cargas as $row): ?><tr class="<?= $cargaId===(int)$row['id']?'selected-row':'' ?>"><td>#<?= (int)$row['id'] ?></td><td><strong><?= h((string)$row['nome_original']) ?></strong><div class="muted"><?= h((string)($row['hash_arquivo'] ?? '')) ?></div></td><td><?= h((string)($row['credor_nome'] ?? '-')) ?></td><td><?= situacaoCargaBadge($row) ?></td><td><?= number_format((int)($row['total_linhas'] ?? 0),0,',','.') ?></td><td><?= number_format((int)($row['erros_total'] ?? 0),0,',','.') ?></td><td><a class="button secondary table-button" href="/operacao/configuracoes/cargas.php?id=<?= (int)$row['id'] ?>">Detalhar</a></td></tr><?php endforeach; ?></tbody></table></div></div>
            <div class="card"><div class="section-title"><div><h2>Detalhe da carga</h2><p>Raw, staging, erros e comandos sugeridos.</p></div><?= $carga ? badge('#'.(string)$carga['id']) : badgeSoft('selecione') ?></div><?php if ($carga === null): ?><div class="empty">Selecione uma carga na tabela.</div><?php else: ?><div class="kv-list"><div><span>Arquivo</span><strong><?= h((string)$carga['nome_original']) ?></strong></div><div><span>Status</span><strong><?= situacaoCargaBadge($carga) ?></strong></div><div><span>Raw</span><strong><?= number_format((int)$statsCarga['raw'],0,',','.') ?></strong></div><div><span>Staging</span><strong><?= number_format((int)$statsCarga['staging'],0,',','.') ?></strong></div><div><span>Erros</span><strong><?= number_format((int)$statsCarga['erros'],0,',','.') ?></strong></div></div><div class="code-box"><?php if ($comandosCarga !== []): ?><?php foreach ($comandosCarga as $i => $comando): ?><?= $i > 0 ? '<br>' : '' ?><code><?= h($comando) ?></code><?php endforeach; ?><?php else: ?><code>Consulte o importador específico do layout <?= h((string)($carga['layout_codigo'] ?? 'não identificado')) ?>.</code><?php endif; ?></div><?php if ($avisoCarga !== null): ?><div class="alert info" style="margin-top:12px"><?= h($avisoCarga) ?></div><?php endif; ?><?php if (usuarioPode('cargas.reprocessar')): ?><form method="post" class="actions-row form-actions"><?= authCsrfInput() ?><input type="hidden" name="acao" value="preparar_reprocessamento"><input type="hidden" name="carga_id" value="<?= (int)$carga['id'] ?>"><button class="button secondary" type="submit">Preparar reprocessamento</button></form><?php endif; ?><?php endif; ?></div>
        </section>
        <?php if ($carga !== null): ?><section class="grid-2 grid-2-wide"><div class="card"><div class="section-title"><div><h2>Erros da carga</h2></div><span class="badge"><?= count($erros) ?></span></div><div class="table-wrap"><table><thead><tr><th>Linha</th><th>Campo</th><th>Erro</th></tr></thead><tbody><?php foreach ($erros as $e): ?><tr><td><?= (int)($e['numero_linha'] ?? 0) ?></td><td><?= h((string)($e['campo'] ?? '-')) ?></td><td><?= h((string)($e['mensagem'] ?? $e['erro'] ?? '-')) ?></td></tr><?php endforeach; ?></tbody></table></div></div><div class="card"><div class="section-title"><div><h2>Amostra staging</h2></div><span class="badge"><?= count($stagingAmostra) ?></span></div><div class="table-wrap"><table><thead><tr><th>Linha</th><th>Documento</th><th>Nome</th><th>Contrato</th><th>Status</th></tr></thead><tbody><?php foreach ($stagingAmostra as $s): ?><tr><td><?= (int)($s['numero_linha'] ?? 0) ?></td><td><?= documentoFormatado((string)($s['documento'] ?? '')) ?></td><td><?= h((string)($s['nome'] ?? '-')) ?></td><td><?= h((string)($s['codigo_contrato'] ?? '-')) ?></td><td><?= h((string)($s['status'] ?? '-')) ?></td></tr><?php endforeach; ?></tbody></table></div></div></section><?php endif; ?>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
