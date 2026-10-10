<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\DadosGeraisRepository;
use CRM\Modules\Operacao\Repositories\EstrategiaRepository;
use CRM\Modules\Operacao\Services\DadosGeraisFiltroService;

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoPermissao('relatorios.visualizar');

$pageTitle = 'Dados Gerais · Mailing';
$pageSubtitle = 'Segmente a base canônica e gere CSV para discadora, planejamento ou análise.';
$activePage = 'dados_gerais';

$defaults = [
    'formato' => 'mailing',
    'situacao_base' => 'ativos',
    'dias_sem_incluir_nunca' => '1',
];
$form = $defaults;
$preview = null;
$mensagemErro = null;
$mensagemSucesso = null;
$credores = [];
$carteiras = [];
$produtos = [];
$statusContratos = [];
$resultadosAtendimento = [];

try {
    $pdo = operacaoPdo();
    $dadosRepo = new DadosGeraisRepository($pdo);
    $estrategiaRepo = new EstrategiaRepository($pdo);
    $filtroService = new DadosGeraisFiltroService();
    $escopo = usuarioEscopoCredoresAcao('consultar');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = trim((string) ($_POST['acao'] ?? ''));
        $form = array_merge($defaults, $_POST);

        if ($acao === 'previsualizar') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ignore_user_abort(true);
            @set_time_limit(300);

            $filtros = $filtroService->normalizar($_POST, true);
            $preview = $dadosRepo->previsualizar($filtros, $escopo, 40);
            $mensagemSucesso = 'Prévia atualizada. Nenhum dado foi alterado.';
        }
    }

    $credores = $estrategiaRepo->listarCredores($escopo);
    $carteiras = $estrategiaRepo->listarCarteiras($escopo);
    $produtos = $estrategiaRepo->listarProdutos($escopo);
    $statusContratos = $estrategiaRepo->listarStatusContratos($escopo);
    $resultadosAtendimento = $estrategiaRepo->listarResultadosAtendimento();
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

function dadosGeraisValue(array $form, string $key, mixed $default = ''): string
{
    return h((string) ($form[$key] ?? $default));
}

function dadosGeraisChecked(array $form, string $key): string
{
    return (string) ($form[$key] ?? '') === '1' ? 'checked' : '';
}

function dadosGeraisSelected(array $form, string $key, mixed $value): string
{
    return (string) ($form[$key] ?? '') === (string) $value ? 'selected' : '';
}

require __DIR__ . '/includes/header.php';
?>
<main class="container dados-gerais-page">
    <?php if ($mensagemSucesso !== null): ?><div class="alert success"><?= h($mensagemSucesso) ?></div><?php endif; ?>
    <?php if ($mensagemErro !== null): ?><div class="alert danger"><strong>Não foi possível concluir a consulta.</strong><br><?= h($mensagemErro) ?></div><?php endif; ?>

    <section class="strategy-supervision-hero card dados-gerais-hero">
        <div>
            <span class="eyebrow">Dados e planejamento</span>
            <h2>Construtor de base e mailing</h2>
            <p>Use somente os filtros necessários. O CSV é gerado diretamente da base canônica oficial e respeita o escopo de credor/carteira do usuário.</p>
        </div>
        <div class="strategy-hero-rule">
            <strong>Padrão seguro</strong>
            <span>Por padrão entram apenas contratos presentes na última carga operacional. Para mailing, use também “Com telefone válido”.</span>
        </div>
    </section>

    <section class="card" id="construtor">
        <div class="section-title">
            <div>
                <h2>Selecionar dados</h2>
                <p>Faça uma prévia para conferir quantidade e saldo ou baixe diretamente o CSV.</p>
            </div>
            <a class="button secondary small" href="/operacao/relatorios.php">Relatórios operacionais</a>
        </div>

        <form class="strategy-builder" method="post" action="/operacao/dados_gerais.php#construtor" data-dados-gerais-form>
            <?= authCsrfInput() ?>

            <details class="strategy-builder-section" open>
                <summary><span>1. Saída e situação da base</span><small>Formato do CSV e contratos que podem entrar</small></summary>
                <div class="strategy-builder-body">
                    <div class="form-grid-wide">
                        <label class="field">
                            <span>Formato do CSV</span>
                            <select name="formato">
                                <?php foreach (DadosGeraisFiltroService::FORMATOS as $codigo => $label): ?>
                                    <option value="<?= h($codigo) ?>" <?= dadosGeraisSelected($form, 'formato', $codigo) ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>Mailing: enxuto e com telefones em colunas. Base geral: maior quantidade de campos canônicos.</small>
                        </label>
                        <label class="field">
                            <span>Situação na carga</span>
                            <select name="situacao_base">
                                <?php foreach (DadosGeraisFiltroService::SITUACOES_BASE as $codigo => $label): ?>
                                    <option value="<?= h($codigo) ?>" <?= dadosGeraisSelected($form, 'situacao_base', $codigo) ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>“Ativos” evita enviar à discadora contratos já retirados pelo credor.</small>
                        </label>
                    </div>
                </div>
            </details>

            <details class="strategy-builder-section" open>
                <summary><span>2. Cliente, contrato, credor e produto</span><small>Identificação e dados recebidos na base</small></summary>
                <div class="strategy-builder-body strategy-filter-grid">
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_credor" value="1" <?= dadosGeraisChecked($form, 'usar_credor') ?> data-filter-toggle><span><strong>Credor</strong><small>Restringe a um credor permitido.</small></span></label><label class="field" data-filter-field><span>Credor</span><select name="credor_id"><option value="">Selecione</option><?php foreach ($credores as $item): ?><option value="<?= (int) $item['id'] ?>" <?= dadosGeraisSelected($form, 'credor_id', $item['id']) ?>><?= h((string) $item['nome']) ?></option><?php endforeach; ?></select></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_carteira" value="1" <?= dadosGeraisChecked($form, 'usar_carteira') ?> data-filter-toggle><span><strong>Carteira</strong><small>Restringe a uma carteira específica.</small></span></label><label class="field" data-filter-field><span>Carteira</span><select name="carteira_id"><option value="">Selecione</option><?php foreach ($carteiras as $item): ?><option value="<?= (int) $item['id'] ?>" <?= dadosGeraisSelected($form, 'carteira_id', $item['id']) ?>><?= h((string) $item['credor_nome']) ?> · <?= h((string) $item['nome']) ?></option><?php endforeach; ?></select></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_produto" value="1" <?= dadosGeraisChecked($form, 'usar_produto') ?> data-filter-toggle><span><strong>Produto</strong><small>Nome ou parte do produto.</small></span></label><label class="field" data-filter-field><span>Produto contém</span><input type="text" name="produto" list="dados-products" maxlength="180" value="<?= dadosGeraisValue($form, 'produto') ?>"></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_subproduto" value="1" <?= dadosGeraisChecked($form, 'usar_subproduto') ?> data-filter-toggle><span><strong>Subproduto</strong><small>Nome ou parte do subproduto.</small></span></label><label class="field" data-filter-field><span>Subproduto contém</span><input type="text" name="subproduto" maxlength="180" value="<?= dadosGeraisValue($form, 'subproduto') ?>"></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_status_contrato" value="1" <?= dadosGeraisChecked($form, 'usar_status_contrato') ?> data-filter-toggle><span><strong>Status do contrato</strong><small>Status canônico atual.</small></span></label><label class="field" data-filter-field><span>Status</span><select name="status_contrato"><option value="">Selecione</option><?php foreach ($statusContratos as $status): ?><option value="<?= h($status) ?>" <?= dadosGeraisSelected($form, 'status_contrato', $status) ?>><?= h($status) ?></option><?php endforeach; ?></select></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_codigo_contrato" value="1" <?= dadosGeraisChecked($form, 'usar_codigo_contrato') ?> data-filter-toggle><span><strong>Contrato</strong><small>Código ou parte do contrato.</small></span></label><label class="field" data-filter-field><span>Contrato contém</span><input type="text" name="codigo_contrato" maxlength="120" value="<?= dadosGeraisValue($form, 'codigo_contrato') ?>"></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_cliente_nome" value="1" <?= dadosGeraisChecked($form, 'usar_cliente_nome') ?> data-filter-toggle><span><strong>Nome do cliente</strong><small>Busca parcial no nome.</small></span></label><label class="field" data-filter-field><span>Nome contém</span><input type="text" name="cliente_nome" maxlength="255" value="<?= dadosGeraisValue($form, 'cliente_nome') ?>"></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_documento" value="1" <?= dadosGeraisChecked($form, 'usar_documento') ?> data-filter-toggle><span><strong>CPF/CNPJ</strong><small>Documento exato, com ou sem pontuação.</small></span></label><label class="field" data-filter-field><span>CPF/CNPJ</span><input type="text" name="documento" maxlength="20" value="<?= dadosGeraisValue($form, 'documento') ?>"></label></div>
                </div>
                <datalist id="dados-products"><?php foreach ($produtos as $produto): ?><option value="<?= h($produto) ?>"><?php endforeach; ?></datalist>
            </details>

            <details class="strategy-builder-section">
                <summary><span>3. Origem e classificação</span><small>Lote, fila, conta, filial e status do devedor</small></summary>
                <div class="strategy-builder-body strategy-filter-grid">
                    <?php foreach ([
                        ['conta_id','Conta ID','Conta contém','conta_id',120],
                        ['lote','Lote','Lote contém','lote',180],
                        ['fila','Fila','Fila contém','fila',180],
                        ['status_devedor','Status do devedor','Status contém','status_devedor',120],
                        ['filial','Filial','Filial contém','filial',180],
                        ['unidade_cobranca','Unidade de cobrança','Unidade contém','unidade_cobranca',120],
                    ] as [$key,$titulo,$label,$name,$max]): ?>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_<?= h($key) ?>" value="1" <?= dadosGeraisChecked($form, 'usar_' . $key) ?> data-filter-toggle><span><strong><?= h($titulo) ?></strong><small>Campo canônico recebido na carga.</small></span></label><label class="field" data-filter-field><span><?= h($label) ?></span><input type="text" name="<?= h($name) ?>" maxlength="<?= (int) $max ?>" value="<?= dadosGeraisValue($form, $name) ?>"></label></div>
                    <?php endforeach; ?>
                </div>
            </details>

            <details class="strategy-builder-section" open>
                <summary><span>4. Datas, vencimento e aging</span><small>Faixas inclusivas</small></summary>
                <div class="strategy-builder-body strategy-filter-grid">
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_entrada" value="1" <?= dadosGeraisChecked($form, 'usar_data_entrada') ?> data-filter-toggle><span><strong>Entrada na base</strong><small>Recebimento da carga de origem.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="data_entrada_de" value="<?= dadosGeraisValue($form, 'data_entrada_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="data_entrada_ate" value="<?= dadosGeraisValue($form, 'data_entrada_ate') ?>"></label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_atualizacao" value="1" <?= dadosGeraisChecked($form, 'usar_data_atualizacao') ?> data-filter-toggle><span><strong>Atualização do contrato</strong><small>Última atualização canônica.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="data_atualizacao_de" value="<?= dadosGeraisValue($form, 'data_atualizacao_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="data_atualizacao_ate" value="<?= dadosGeraisValue($form, 'data_atualizacao_ate') ?>"></label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_vencimento_original" value="1" <?= dadosGeraisChecked($form, 'usar_vencimento_original') ?> data-filter-toggle><span><strong>Vencimento original</strong><small>Data inicial ou primeira parcela.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="vencimento_original_de" value="<?= dadosGeraisValue($form, 'vencimento_original_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="vencimento_original_ate" value="<?= dadosGeraisValue($form, 'vencimento_original_ate') ?>"></label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_dia_vencimento" value="1" <?= dadosGeraisChecked($form, 'usar_dia_vencimento') ?> data-filter-toggle><span><strong>Dia do vencimento</strong><small>Ex.: 1 a 20 de qualquer mês.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Dia inicial</span><input type="number" min="1" max="31" name="dia_vencimento_de" value="<?= dadosGeraisValue($form, 'dia_vencimento_de') ?>"></label><label class="field"><span>Dia final</span><input type="number" min="1" max="31" name="dia_vencimento_ate" value="<?= dadosGeraisValue($form, 'dia_vencimento_ate') ?>"></label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_aging" value="1" <?= dadosGeraisChecked($form, 'usar_aging') ?> data-filter-toggle><span><strong>Aging</strong><small>Dias de atraso pelo vencimento original.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="number" min="0" name="aging_min" value="<?= dadosGeraisValue($form, 'aging_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" min="0" name="aging_max" value="<?= dadosGeraisValue($form, 'aging_max') ?>"></label></div></div>
                </div>
            </details>

            <details class="strategy-builder-section" open>
                <summary><span>5. Valores, saldo e parcelas</span><small>Faixas financeiras independentes</small></summary>
                <div class="strategy-builder-body strategy-filter-grid">
                    <?php foreach ([
                        ['saldo_atual','Saldo atual','saldo_atual_min','saldo_atual_max'],
                        ['valor_original','Valor original','valor_original_min','valor_original_max'],
                        ['valor_pago','Valor já pago','valor_pago_min','valor_pago_max'],
                        ['valor_faltante','Valor faltante','valor_faltante_min','valor_faltante_max'],
                    ] as [$key,$titulo,$min,$max]): ?>
                        <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_<?= h($key) ?>" value="1" <?= dadosGeraisChecked($form, 'usar_' . $key) ?> data-filter-toggle><span><strong><?= h($titulo) ?></strong><small>Informe mínimo, máximo ou ambos.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="text" inputmode="decimal" name="<?= h($min) ?>" value="<?= dadosGeraisValue($form, $min) ?>"></label><label class="field"><span>Máximo</span><input type="text" inputmode="decimal" name="<?= h($max) ?>" value="<?= dadosGeraisValue($form, $max) ?>"></label></div></div>
                    <?php endforeach; ?>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_total_parcelas" value="1" <?= dadosGeraisChecked($form, 'usar_total_parcelas') ?> data-filter-toggle><span><strong>Total de parcelas</strong><small>Quantidade vinculada ao contrato.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="number" min="0" name="total_parcelas_min" value="<?= dadosGeraisValue($form, 'total_parcelas_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" min="0" name="total_parcelas_max" value="<?= dadosGeraisValue($form, 'total_parcelas_max') ?>"></label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_ultimo_pagamento" value="1" <?= dadosGeraisChecked($form, 'usar_data_ultimo_pagamento') ?> data-filter-toggle><span><strong>Último pagamento</strong><small>Data do pagamento importado.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="ultimo_pagamento_de" value="<?= dadosGeraisValue($form, 'ultimo_pagamento_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="ultimo_pagamento_ate" value="<?= dadosGeraisValue($form, 'ultimo_pagamento_ate') ?>"></label></div></div>
                </div>
            </details>

            <details class="strategy-builder-section" open>
                <summary><span>6. Tentativas e resultado</span><small>Histórico operacional do cliente/contrato</small></summary>
                <div class="strategy-builder-body strategy-filter-grid">
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_situacao_tentativas" value="1" <?= dadosGeraisChecked($form, 'usar_situacao_tentativas') ?> data-filter-toggle><span><strong>Com ou sem tentativa</strong><small>Separe quem nunca foi trabalhado.</small></span></label><label class="field" data-filter-field><span>Situação</span><select name="situacao_tentativas"><option value="nunca" <?= dadosGeraisSelected($form, 'situacao_tentativas', 'nunca') ?>>Nunca acionado</option><option value="com" <?= dadosGeraisSelected($form, 'situacao_tentativas', 'com') ?>>Com ao menos uma tentativa</option></select></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_quantidade_tentativas" value="1" <?= dadosGeraisChecked($form, 'usar_quantidade_tentativas') ?> data-filter-toggle><span><strong>Quantidade de tentativas</strong><small>Faixa de acionamentos.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>Mínimo</span><input type="number" min="0" name="tentativas_min" value="<?= dadosGeraisValue($form, 'tentativas_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" min="0" name="tentativas_max" value="<?= dadosGeraisValue($form, 'tentativas_max') ?>"></label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_data_ultima_tentativa" value="1" <?= dadosGeraisChecked($form, 'usar_data_ultima_tentativa') ?> data-filter-toggle><span><strong>Data da última tentativa</strong><small>Período da última tabulação.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>De</span><input type="date" name="ultima_tentativa_de" value="<?= dadosGeraisValue($form, 'ultima_tentativa_de') ?>"></label><label class="field"><span>Até</span><input type="date" name="ultima_tentativa_ate" value="<?= dadosGeraisValue($form, 'ultima_tentativa_ate') ?>"></label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_dias_sem_tentativa" value="1" <?= dadosGeraisChecked($form, 'usar_dias_sem_tentativa') ?> data-filter-toggle><span><strong>Dias sem tentativa</strong><small>Tempo desde o último acionamento.</small></span></label><div data-filter-field><div class="strategy-range"><label class="field"><span>Mínimo</span><input type="number" min="0" name="dias_sem_tentativa_min" value="<?= dadosGeraisValue($form, 'dias_sem_tentativa_min') ?>"></label><label class="field"><span>Máximo</span><input type="number" min="0" name="dias_sem_tentativa_max" value="<?= dadosGeraisValue($form, 'dias_sem_tentativa_max') ?>"></label></div><label class="strategy-inline-check"><input type="checkbox" name="dias_sem_incluir_nunca" value="1" <?= dadosGeraisChecked($form, 'dias_sem_incluir_nunca') ?>> Incluir nunca acionados no mínimo</label></div></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_ultimo_resultado" value="1" <?= dadosGeraisChecked($form, 'usar_ultimo_resultado') ?> data-filter-toggle><span><strong>Último resultado</strong><small>Tabulação mais recente.</small></span></label><label class="field" data-filter-field><span>Resultado</span><select name="ultimo_resultado_id"><option value="">Selecione</option><?php foreach ($resultadosAtendimento as $resultado): ?><option value="<?= (int) $resultado['id'] ?>" <?= dadosGeraisSelected($form, 'ultimo_resultado_id', $resultado['id']) ?>><?= h((string) $resultado['nome']) ?> · <?= h((string) $resultado['categoria']) ?></option><?php endforeach; ?></select></label></div>
                </div>
            </details>

            <details class="strategy-builder-section" open>
                <summary><span>7. Acordos, contatos e localidade</span><small>Filtros úteis para mailing e planejamento</small></summary>
                <div class="strategy-builder-body strategy-filter-grid">
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_situacao_acordo" value="1" <?= dadosGeraisChecked($form, 'usar_situacao_acordo') ?> data-filter-toggle><span><strong>Situação de acordo</strong><small>Evite, por exemplo, contratos já negociados.</small></span></label><label class="field" data-filter-field><span>Situação</span><select name="situacao_acordo"><option value="sem_acordo" <?= dadosGeraisSelected($form, 'situacao_acordo', 'sem_acordo') ?>>Nunca teve acordo</option><option value="sem_ativo" <?= dadosGeraisSelected($form, 'situacao_acordo', 'sem_ativo') ?>>Sem acordo ativo</option><option value="ativo" <?= dadosGeraisSelected($form, 'situacao_acordo', 'ativo') ?>>Com acordo ativo</option><option value="quebrado" <?= dadosGeraisSelected($form, 'situacao_acordo', 'quebrado') ?>>Com acordo quebrado</option><option value="cumprido" <?= dadosGeraisSelected($form, 'situacao_acordo', 'cumprido') ?>>Com acordo cumprido</option><option value="cancelado" <?= dadosGeraisSelected($form, 'situacao_acordo', 'cancelado') ?>>Com acordo cancelado</option></select></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_tipo_contato" value="1" <?= dadosGeraisChecked($form, 'usar_tipo_contato') ?> data-filter-toggle><span><strong>Disponibilidade de contato</strong><small>Para discadora, prefira “Com telefone válido”.</small></span></label><label class="field" data-filter-field><span>Contato</span><select name="tipo_contato"><option value="telefone_valido" <?= dadosGeraisSelected($form, 'tipo_contato', 'telefone_valido') ?>>Com telefone/WhatsApp válido</option><option value="sem_telefone_valido" <?= dadosGeraisSelected($form, 'tipo_contato', 'sem_telefone_valido') ?>>Sem telefone válido</option><option value="whatsapp" <?= dadosGeraisSelected($form, 'tipo_contato', 'whatsapp') ?>>Com WhatsApp válido</option><option value="email" <?= dadosGeraisSelected($form, 'tipo_contato', 'email') ?>>Com e-mail válido</option><option value="qualquer_valido" <?= dadosGeraisSelected($form, 'tipo_contato', 'qualquer_valido') ?>>Com qualquer contato válido</option></select></label></div>
                    <div class="strategy-filter-card" data-filter-card><label class="strategy-filter-switch"><input type="checkbox" name="usar_localidade" value="1" <?= dadosGeraisChecked($form, 'usar_localidade') ?> data-filter-toggle><span><strong>Localidade</strong><small>Endereço principal.</small></span></label><div class="strategy-range" data-filter-field><label class="field"><span>UF</span><input type="text" name="uf" maxlength="2" value="<?= dadosGeraisValue($form, 'uf') ?>" placeholder="SP"></label><label class="field"><span>Cidade contém</span><input type="text" name="cidade" maxlength="120" value="<?= dadosGeraisValue($form, 'cidade') ?>"></label></div></div>
                </div>
            </details>

            <details class="strategy-builder-section" open>
                <summary><span>8. Segurança da seleção</span><small>Evita baixar toda a carteira sem querer</small></summary>
                <div class="strategy-builder-body">
                    <label class="strategy-confirm-all"><input type="checkbox" name="confirmar_todos_escopo" value="1" <?= dadosGeraisChecked($form, 'confirmar_todos_escopo') ?>><span><strong>Confirmo a consulta de toda a base do meu escopo caso nenhum filtro acima esteja ativo.</strong><small>A situação “Ativos/Retirados” não conta como filtro de segmentação.</small></span></label>
                </div>
            </details>

            <div class="strategy-builder-actions">
                <button class="button secondary" type="submit" name="acao" value="previsualizar" data-loading-message="Gerando prévia da base..." data-loading-detail="Calculando contratos, saldo e disponibilidade de contato sem gravar nada.">Gerar prévia</button>
                <button class="button primary" type="submit" name="acao" value="exportar" formaction="/operacao/dados_gerais_exportar.php" data-download-request data-loading-message="Gerando CSV..." data-loading-detail="Aguarde o início do download. Depois você poderá continuar usando a tela normalmente.">Baixar CSV</button>
            </div>
        </form>
    </section>

    <?php if (is_array($preview)): ?>
        <?php $m = $preview['metricas']; ?>
        <section class="card dados-gerais-preview">
            <div class="section-title"><div><h2>Prévia da seleção</h2><p>Indicadores calculados com os filtros atuais.</p></div><?= badgeSoft('somente leitura') ?></div>
            <div class="metrics-grid metrics-grid-wide">
                <div class="metric"><small>Contratos</small><strong><?= number_format((int) $m['total_contratos'], 0, ',', '.') ?></strong></div>
                <div class="metric"><small>Clientes únicos</small><strong><?= number_format((int) $m['clientes_unicos'], 0, ',', '.') ?></strong></div>
                <div class="metric"><small>Saldo</small><strong><?= dinheiro($m['saldo_total']) ?></strong></div>
                <div class="metric"><small>Aging médio</small><strong><?= number_format((float) $m['aging_medio'], 1, ',', '.') ?> dias</strong></div>
                <div class="metric metric-success"><small>Com telefone</small><strong><?= number_format((int) $m['com_telefone'], 0, ',', '.') ?></strong></div>
                <div class="metric"><small>Com WhatsApp</small><strong><?= number_format((int) $m['com_whatsapp'], 0, ',', '.') ?></strong></div>
                <div class="metric metric-warning"><small>Sem telefone</small><strong><?= number_format((int) $m['sem_telefone'], 0, ',', '.') ?></strong></div>
                <div class="metric"><small>Com acordo ativo</small><strong><?= number_format((int) $m['com_acordo_ativo'], 0, ',', '.') ?></strong></div>
            </div>

            <?php if ($preview['amostra'] === []): ?>
                <div class="empty"><strong>Nenhum contrato encontrado.</strong><p>Ajuste os filtros e gere a prévia novamente.</p></div>
            <?php else: ?>
                <div class="table-wrap dados-gerais-table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Cliente</th><th>Contrato</th><th>Credor</th><th>Produto</th><th>Vencimento</th><th>Aging</th><th>Saldo</th><th>Telefone</th><th>Último resultado</th></tr></thead>
                        <tbody>
                        <?php foreach ($preview['amostra'] as $row): ?>
                            <tr>
                                <td><strong><?= h((string) $row['cliente']) ?></strong><small><?= h((string) $row['documento']) ?></small></td>
                                <td><?= h((string) $row['contrato']) ?></td>
                                <td><?= h((string) $row['credor']) ?><small><?= h((string) ($row['carteira'] ?? '')) ?></small></td>
                                <td><?= h((string) ($row['produto'] ?? '-')) ?></td>
                                <td><?= dataBr($row['data_vencimento_original'] ?? null) ?></td>
                                <td><?= number_format((int) ($row['aging'] ?? 0), 0, ',', '.') ?></td>
                                <td><?= dinheiro($row['valor_faltante'] ?? 0) ?></td>
                                <td><?= h((string) (($row['telefone_1'] ?? '') ?: ($row['whatsapp_1'] ?? '-'))) ?></td>
                                <td><?= h((string) (($row['ultimo_resultado_nome'] ?? '') ?: '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script src="/operacao/assets/segmentacao-filtros.js" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
