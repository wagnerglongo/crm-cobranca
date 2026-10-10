<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\CredorConfiguracaoRepository;
use CRM\Modules\Operacao\Repositories\CredorIntegracaoRepository;

require_once __DIR__ . '/../includes/bootstrap.php';
requireOperacaoAlgumaPermissao(['credores.visualizar', 'credores.editar', 'acordos.configurar_carencia', 'usuarios.gerenciar']);

$pageTitle = 'Credores';
$pageSubtitle = 'Cadastro e parâmetros operacionais por credor e produto.';
$activePage = 'config_credores';

$pdo = operacaoPdo();
$credorRepository = new CredorConfiguracaoRepository($pdo);
$integracaoRepository = new CredorIntegracaoRepository($pdo);
$acordoRepository = new AcordoOperacionalRepository($pdo);

$credorIdsVisiveisEscopo = usuarioEscopoCredorIdsAcao('consultar');
$credorIdsConfiguraveisEscopo = usuarioEscopoCredorIdsAcao('configurar');
$podeEditarCredor = usuarioEhAdminOperacao() || usuarioPode('credores.editar');
$podeConfigurarCarencia = usuarioEhAdminOperacao() || usuarioPode('credores.editar') || usuarioPode('acordos.configurar_carencia');
$erro = null;
$mensagemSucesso = null;

/** @return string */
function credoresConfigValorExibicao(array $config): string
{
    $valor = (string) ($config['valor'] ?? '');
    if ((string) ($config['tipo'] ?? '') === 'booleano') {
        return in_array(mb_strtolower(trim($valor)), ['1', 'true', 'sim', 'yes', 'on'], true) ? 'Sim' : 'Não';
    }
    if ((string) ($config['tipo'] ?? '') === 'json' && mb_strlen($valor) > 120) {
        return mb_substr($valor, 0, 117) . '...';
    }
    return $valor !== '' ? $valor : '—';
}

/** @return string */
function credoresIntegracaoJsonExibicao(mixed $value): string
{
    if (is_array($value)) {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return '{}';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $raw;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

$credorIdSelecionado = isset($_GET['credor_id']) && ctype_digit((string) $_GET['credor_id']) ? (int) $_GET['credor_id'] : 0;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validarCsrf();
        $acao = trim((string) ($_POST['acao'] ?? ''));
        $credorIdPost = isset($_POST['credor_id']) && ctype_digit((string) $_POST['credor_id']) ? (int) $_POST['credor_id'] : 0;

        $acoesComCredor = [
            'atualizar_credor',
            'salvar_configuracao',
            'remover_configuracao',
            'salvar_integracao',
            'alternar_integracao',
            'salvar_carencia',
            'remover_carencia',
        ];
        if (in_array($acao, $acoesComCredor, true)) {
            if ($credorIdPost <= 0) {
                throw new RuntimeException('Credor inválido.');
            }
            if ($credorIdsConfiguraveisEscopo !== null && !in_array($credorIdPost, $credorIdsConfiguraveisEscopo, true)) {
                throw new RuntimeException('Seu usuário não possui escopo para configurar este credor.');
            }
        }

        if ($acao === 'criar_credor') {
            if (!$podeEditarCredor) {
                throw new RuntimeException('Seu perfil não possui permissão para cadastrar credores.');
            }
            if ($credorIdsConfiguraveisEscopo !== null) {
                throw new RuntimeException('Somente usuários com escopo para todos os credores podem cadastrar um novo credor.');
            }
            $novoId = $credorRepository->criarCredor((string) ($_POST['codigo'] ?? ''), (string) ($_POST['nome'] ?? ''));
            registrarAuditoriaSistema('credor.criado', 'credor', $novoId, 'Credor cadastrado pela administração.', null, ['codigo' => (string) ($_POST['codigo'] ?? ''), 'nome' => (string) ($_POST['nome'] ?? '')]);
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $novoId . '&ok=criado');
            exit;
        }

        if ($acao === 'atualizar_credor') {
            if (!$podeEditarCredor) {
                throw new RuntimeException('Seu perfil não possui permissão para editar credores.');
            }
            $credorRepository->atualizarCredor($credorIdPost, (string) ($_POST['codigo'] ?? ''), (string) ($_POST['nome'] ?? ''));
            registrarAuditoriaSistema('credor.atualizado', 'credor', $credorIdPost, 'Cadastro do credor atualizado.', null, ['codigo' => (string) ($_POST['codigo'] ?? ''), 'nome' => (string) ($_POST['nome'] ?? '')]);
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $credorIdPost . '&ok=atualizado');
            exit;
        }

        if ($acao === 'salvar_configuracao') {
            if (!$podeEditarCredor) {
                throw new RuntimeException('Seu perfil não possui permissão para editar configurações do credor.');
            }
            $credorRepository->salvarConfiguracao(
                $credorIdPost,
                (string) ($_POST['produto'] ?? ''),
                (string) ($_POST['chave'] ?? ''),
                $_POST['valor'] ?? '',
                (string) ($_POST['tipo'] ?? 'texto'),
                (string) ($_POST['descricao'] ?? ''),
                isset($_POST['ativo'])
            );
            registrarAuditoriaSistema('credor.configuracao_salva', 'credor', $credorIdPost, 'Parâmetro configurável do credor salvo.', null, ['chave' => (string) ($_POST['chave'] ?? ''), 'produto' => (string) ($_POST['produto'] ?? '')]);
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $credorIdPost . '&ok=config');
            exit;
        }

        if ($acao === 'remover_configuracao') {
            if (!$podeEditarCredor) {
                throw new RuntimeException('Seu perfil não possui permissão para remover configurações do credor.');
            }
            $configId = isset($_POST['configuracao_id']) && ctype_digit((string) $_POST['configuracao_id']) ? (int) $_POST['configuracao_id'] : 0;
            $credorRepository->removerConfiguracao($credorIdPost, $configId);
            registrarAuditoriaSistema('credor.configuracao_removida', 'credor', $credorIdPost, 'Parâmetro configurável do credor removido.', null, ['configuracao_id' => $configId]);
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $credorIdPost . '&ok=config_removida');
            exit;
        }

        if ($acao === 'salvar_integracao') {
            if (!$podeEditarCredor) {
                throw new RuntimeException('Seu perfil não possui permissão para configurar integrações.');
            }
            $adapter = strtoupper(trim((string) ($_POST['adapter'] ?? '')));
            $ativo = isset($_POST['ativo']);
            if ($ativo && $adapter !== 'ACME_ACIONAMENTOS') {
                throw new RuntimeException('Somente o adapter ACME_ACIONAMENTOS está implementado neste patch. Cadastre outros canais como inativos até o adapter correspondente ser desenvolvido.');
            }
            $integracaoId = $integracaoRepository->salvarIntegracao($credorIdPost, $_POST, usuarioAtualId());
            registrarAuditoriaSistema(
                'credor.integracao_salva',
                'credor_integracao',
                $integracaoId,
                'Integração de saída do credor salva.',
                null,
                [
                    'credor_id' => $credorIdPost,
                    'codigo' => (string) ($_POST['codigo'] ?? ''),
                    'evento_tipo' => (string) ($_POST['evento_tipo'] ?? ''),
                    'tipo_transporte' => (string) ($_POST['tipo_transporte'] ?? ''),
                    'adapter' => $adapter,
                    'ativo' => $ativo,
                ]
            );
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $credorIdPost . '&ok=integracao#credor-integracoes');
            exit;
        }

        if ($acao === 'alternar_integracao') {
            if (!$podeEditarCredor) {
                throw new RuntimeException('Seu perfil não possui permissão para configurar integrações.');
            }
            $integracaoId = isset($_POST['integracao_id']) && ctype_digit((string) $_POST['integracao_id']) ? (int) $_POST['integracao_id'] : 0;
            $integracaoAtual = $integracaoRepository->buscar($integracaoId);
            if ($integracaoAtual === null || (int) ($integracaoAtual['credor_id'] ?? 0) !== $credorIdPost) {
                throw new RuntimeException('Integração não encontrada para este credor.');
            }
            $novoStatus = (string) ($_POST['ativo'] ?? '0') === '1';
            if ($novoStatus && strtoupper((string) ($integracaoAtual['adapter'] ?? '')) !== 'ACME_ACIONAMENTOS') {
                throw new RuntimeException('Este adapter ainda não possui implementação de entrega e não pode ser ativado.');
            }
            $integracaoRepository->alternarIntegracao($credorIdPost, $integracaoId, $novoStatus, usuarioAtualId());
            registrarAuditoriaSistema('credor.integracao_status', 'credor_integracao', $integracaoId, 'Status da integração do credor alterado.', $integracaoAtual, ['ativo' => $novoStatus]);
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $credorIdPost . '&ok=integracao_status#credor-integracoes');
            exit;
        }

        if ($acao === 'salvar_carencia') {
            if (!$podeConfigurarCarencia) {
                throw new RuntimeException('Seu perfil não possui permissão para configurar carência.');
            }
            $produto = trim((string) ($_POST['produto'] ?? ''));
            $dias = isset($_POST['dias_carencia']) && is_numeric($_POST['dias_carencia']) ? (int) $_POST['dias_carencia'] : 0;
            $acordoRepository->salvarRegraCarencia($credorIdPost, $produto, $dias, isset($_POST['ativo']));
            registrarAuditoriaSistema('acordo.carencia_configurada', 'credor', $credorIdPost, 'Regra de carência configurada na administração do credor.', null, ['produto' => $produto, 'dias_carencia' => $dias]);
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $credorIdPost . '&ok=carencia');
            exit;
        }

        if ($acao === 'remover_carencia') {
            if (!$podeConfigurarCarencia) {
                throw new RuntimeException('Seu perfil não possui permissão para remover regra de carência.');
            }
            $regraId = isset($_POST['regra_id']) && ctype_digit((string) $_POST['regra_id']) ? (int) $_POST['regra_id'] : 0;
            $regraAtual = null;
            foreach ($acordoRepository->listarRegrasCarencia() as $regraCandidata) {
                if ((int) ($regraCandidata['id'] ?? 0) === $regraId) {
                    $regraAtual = $regraCandidata;
                    break;
                }
            }
            if ($regraAtual === null || (int) ($regraAtual['credor_id'] ?? 0) !== $credorIdPost) {
                throw new RuntimeException('Regra de carência não encontrada para este credor.');
            }
            $acordoRepository->removerRegraCarencia($regraId);
            registrarAuditoriaSistema('acordo.carencia_removida', 'credor', $credorIdPost, 'Regra de carência removida na administração do credor.', null, ['regra_id' => $regraId]);
            header('Location: /operacao/configuracoes/credores.php?credor_id=' . $credorIdPost . '&ok=carencia_removida');
            exit;
        }
    }

    $credores = $credorRepository->listarCredores();
    if ($credorIdsVisiveisEscopo !== null) {
        $credores = array_values(array_filter(
            $credores,
            static fn (array $credor): bool => in_array((int) ($credor['id'] ?? 0), $credorIdsVisiveisEscopo, true)
        ));
    }
    $credorIdsVisiveis = array_map(static fn (array $credor): int => (int) ($credor['id'] ?? 0), $credores);
    if ($credorIdSelecionado > 0 && !in_array($credorIdSelecionado, $credorIdsVisiveis, true)) {
        $credorIdSelecionado = 0;
    }
    if ($credorIdSelecionado <= 0 && $credores !== []) {
        $credorIdSelecionado = (int) ($credores[0]['id'] ?? 0);
    }
    $credorSelecionado = $credorIdSelecionado > 0 ? $credorRepository->credorPorId($credorIdSelecionado) : null;
    if ($credorIdSelecionado > 0 && $credorSelecionado === null) {
        $credorIdSelecionado = 0;
    }

    $configuracoes = $credorSelecionado !== null ? $credorRepository->listarConfiguracoes($credorIdSelecionado) : [];
    $integracoesCredor = $credorSelecionado !== null ? $integracaoRepository->listarPorCredor($credorIdSelecionado) : [];
    $carenciaDisponivel = $acordoRepository->moduloCarenciaDisponivel();
    $regrasCarencia = $carenciaDisponivel
        ? array_values(array_filter($acordoRepository->listarRegrasCarencia(), static fn (array $row): bool => (int) ($row['credor_id'] ?? 0) === $credorIdSelecionado))
        : [];
    $opcoesCarencia = $acordoRepository->opcoesRegrasCarencia();
    $produtosSelecionados = $opcoesCarencia['produtos_por_credor'][$credorIdSelecionado] ?? [];
} catch (Throwable $e) {
    $erro = $e->getMessage();
    $credores = isset($credores) && is_array($credores) ? $credores : [];
    $credorSelecionado = isset($credorSelecionado) && is_array($credorSelecionado) ? $credorSelecionado : null;
    $configuracoes = isset($configuracoes) && is_array($configuracoes) ? $configuracoes : [];
    $integracoesCredor = isset($integracoesCredor) && is_array($integracoesCredor) ? $integracoesCredor : [];
    $carenciaDisponivel = $acordoRepository->moduloCarenciaDisponivel();
    $regrasCarencia = [];
    $produtosSelecionados = [];
}

$podeEditarCredorSelecionado = $podeEditarCredor
    && $credorIdSelecionado > 0
    && ($credorIdsConfiguraveisEscopo === null || in_array($credorIdSelecionado, $credorIdsConfiguraveisEscopo, true));
$podeConfigurarCarenciaSelecionada = $podeConfigurarCarencia
    && $credorIdSelecionado > 0
    && ($credorIdsConfiguraveisEscopo === null || in_array($credorIdSelecionado, $credorIdsConfiguraveisEscopo, true));

$ok = trim((string) ($_GET['ok'] ?? ''));
$mensagemSucesso = match ($ok) {
    'criado' => 'Credor cadastrado com sucesso.',
    'atualizado' => 'Cadastro do credor atualizado.',
    'config' => 'Configuração salva.',
    'config_removida' => 'Configuração removida.',
    'carencia' => 'Regra de carência salva.',
    'carencia_removida' => 'Regra de carência removida.',
    'integracao' => 'Integração do credor salva.',
    'integracao_status' => 'Status da integração atualizado.',
    default => null,
};

http_response_code($erro === null ? 200 : 500);
require __DIR__ . '/../includes/header.php';
?>
<main class="container works-creditors-page config-page">
    <?php require __DIR__ . '/../includes/config_nav.php'; ?>
    <?php if ($mensagemSucesso !== null): ?><section class="alert success"><?= h($mensagemSucesso) ?></section><?php endif; ?>
    <?php if ($erro !== null): ?><section class="alert error"><strong>Não foi possível concluir a operação.</strong> <?= h($erro) ?></section><?php endif; ?>

    <section class="works-creditors-toolbar">
        <div>
            <h2>Credores</h2>
            <p>Centralize cadastro, carência e parâmetros operacionais sem espalhar configurações pelas telas de atendimento.</p>
        </div>
        <?php if ($podeEditarCredor && $credorIdsConfiguraveisEscopo === null): ?>
            <details class="works-creditor-new">
                <summary>+ Novo credor</summary>
                <form method="post" action="/operacao/configuracoes/credores.php">
                    <?= authCsrfInput() ?>
                    <input type="hidden" name="acao" value="criar_credor">
                    <label>Código<input type="text" name="codigo" maxlength="60" required placeholder="Ex.: GLOBEX"></label>
                    <label>Nome<input type="text" name="nome" maxlength="150" required placeholder="Nome do credor"></label>
                    <button class="button" type="submit">Cadastrar</button>
                </form>
            </details>
        <?php endif; ?>
    </section>

    <div class="works-creditors-layout">
        <aside class="works-creditor-list" aria-label="Lista de credores">
            <?php if ($credores === []): ?><div class="empty">Nenhum credor cadastrado.</div><?php endif; ?>
            <?php foreach ($credores as $credor): ?>
                <a class="works-creditor-list-item<?= (int) $credor['id'] === $credorIdSelecionado ? ' is-active' : '' ?>" href="/operacao/configuracoes/credores.php?credor_id=<?= (int) $credor['id'] ?>">
                    <span><?= h((string) $credor['codigo']) ?></span>
                    <strong><?= h((string) $credor['nome']) ?></strong>
                    <small><?= (int) ($credor['configuracoes_total'] ?? 0) ?> parâmetro(s)<?= ($credor['habilitado'] ?? true) ? '' : ' · desabilitado' ?></small>
                </a>
            <?php endforeach; ?>
        </aside>

        <section class="works-creditor-content">
            <?php if ($credorSelecionado === null): ?>
                <div class="card empty">Selecione ou cadastre um credor para configurar.</div>
            <?php else: ?>
                <section class="works-creditor-section">
                    <header><div><small>Cadastro</small><h3><?= h((string) $credorSelecionado['nome']) ?></h3></div><span class="badge soft"><?= h((string) $credorSelecionado['codigo']) ?></span></header>
                    <form class="works-creditor-basic-form" method="post" action="/operacao/configuracoes/credores.php?credor_id=<?= $credorIdSelecionado ?>">
                        <?= authCsrfInput() ?>
                        <input type="hidden" name="acao" value="atualizar_credor">
                        <input type="hidden" name="credor_id" value="<?= $credorIdSelecionado ?>">
                        <label>Código<input type="text" name="codigo" maxlength="60" value="<?= h((string) $credorSelecionado['codigo']) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                        <label>Nome<input type="text" name="nome" maxlength="150" value="<?= h((string) $credorSelecionado['nome']) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                        <?php if ($podeEditarCredorSelecionado): ?><button class="button secondary" type="submit">Salvar cadastro</button><?php endif; ?>
                    </form>
                </section>

                <section class="works-creditor-section">
                    <header><div><small>Acordos</small><h3>Carência por credor/produto</h3></div><span class="muted">Usada na quebra automática</span></header>
                    <?php if (!$carenciaDisponivel): ?>
                        <div class="alert warning">Execute a migration <code>074_acordo_carencia_credor_produto.sql</code> para habilitar esta configuração.</div>
                    <?php else: ?>
                        <?php if ($podeConfigurarCarenciaSelecionada): ?>
                            <form class="works-creditor-grace-form" method="post" action="/operacao/configuracoes/credores.php?credor_id=<?= $credorIdSelecionado ?>">
                                <?= authCsrfInput() ?>
                                <input type="hidden" name="acao" value="salvar_carencia">
                                <input type="hidden" name="credor_id" value="<?= $credorIdSelecionado ?>">
                                <label>Produto <small>vazio = todos</small><input type="text" name="produto" maxlength="180" list="credor-produtos" placeholder="Todos os produtos"></label>
                                <datalist id="credor-produtos"><?php foreach ($produtosSelecionados as $produto): ?><option value="<?= h((string) $produto) ?>"><?php endforeach; ?></datalist>
                                <label>Dias de carência<input type="number" name="dias_carencia" min="0" max="365" value="0" required></label>
                                <label class="works-check-line"><input type="checkbox" name="ativo" value="1" checked> Ativa</label>
                                <button class="button" type="submit">Salvar regra</button>
                            </form>
                        <?php endif; ?>
                        <div class="works-creditor-rules">
                            <?php if ($regrasCarencia === []): ?><div class="empty">Nenhuma regra específica. Vale o fallback do ambiente: <?= (int) $acordoRepository->diasCarenciaPadrao() ?> dia(s).</div><?php endif; ?>
                            <?php foreach ($regrasCarencia as $regra): ?>
                                <article>
                                    <div><strong><?= trim((string) ($regra['produto'] ?? '')) !== '' ? h((string) $regra['produto']) : 'Todos os produtos' ?></strong><small><?= (int) $regra['dias_carencia'] ?> dia(s) · <?= (int) $regra['ativo'] === 1 ? 'ativa' : 'inativa' ?></small></div>
                                    <?php if ($podeConfigurarCarenciaSelecionada): ?><form method="post" action="/operacao/configuracoes/credores.php?credor_id=<?= $credorIdSelecionado ?>" onsubmit="return confirm('Remover esta regra de carência?');"><?= authCsrfInput() ?><input type="hidden" name="acao" value="remover_carencia"><input type="hidden" name="credor_id" value="<?= $credorIdSelecionado ?>"><input type="hidden" name="regra_id" value="<?= (int) $regra['id'] ?>"><button class="button secondary table-button" type="submit">Remover</button></form><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="works-creditor-section" id="credor-integracoes">
                    <header>
                        <div><small>Integrações</small><h3>Canais de saída do credor</h3></div>
                        <span class="muted">API, arquivo, SFTP, pasta ou webhook</span>
                    </header>
                    <?php if (!$integracaoRepository->moduloDisponivel()): ?>
                        <div class="alert warning"><strong>Execute a migration 085_integracoes_acionamentos_credores.sql.</strong> Ela habilita o de/para, a outbox assíncrona e os canais padronizados por credor.</div>
                    <?php else: ?>
                        <div class="works-creditor-integration-intro">
                            <div>
                                <strong>Uma arquitetura para todos os credores</strong>
                                <span>O CRM registra um evento canônico e cada adapter transforma esse evento no formato exigido pelo credor. Credenciais continuam exclusivamente no <code>.env.local</code>.</span>
                            </div>
                            <div>
                                <strong>Envio controlado pela tabulação</strong>
                                <span>Um canal ativo não envia tudo sozinho. O acionamento somente entra na fila quando a tabulação possui um de/para ativo marcado para envio.</span>
                            </div>
                        </div>

                        <div class="works-creditor-integrations-list">
                            <?php if ($integracoesCredor === []): ?>
                                <div class="empty">Nenhum canal cadastrado para este credor. Cadastre um abaixo; ele pode permanecer inativo até o layout ou a API serem homologados.</div>
                            <?php endif; ?>
                            <?php foreach ($integracoesCredor as $integracao): ?>
                                <?php
                                $integracaoAtiva = (int) ($integracao['ativo'] ?? 0) === 1;
                                $adapterIntegracao = strtoupper((string) ($integracao['adapter'] ?? ''));
                                $arquivoPendente = $adapterIntegracao === 'ARQUIVO_GENERICO';
                                ?>
                                <article class="works-creditor-integration-card<?= $integracaoAtiva ? ' is-active' : ' is-inactive' ?>">
                                    <div class="works-creditor-integration-card-head">
                                        <div>
                                            <small><?= h((string) ($integracao['evento_tipo'] ?? 'evento')) ?></small>
                                            <h4><?= h((string) ($integracao['nome'] ?? 'Integração')) ?></h4>
                                            <span><?= h((string) ($integracao['tipo_transporte'] ?? '-')) ?> · <code><?= h($adapterIntegracao) ?></code></span>
                                        </div>
                                        <div class="works-creditor-integration-badges">
                                            <span class="badge soft"><?= $integracaoAtiva ? 'Ativa' : 'Inativa' ?></span>
                                            <span class="badge soft"><?= (int) ($integracao['total_mapeamentos'] ?? 0) ?> de/para</span>
                                            <span class="badge soft"><?= (int) ($integracao['total_pendentes'] ?? 0) ?> na fila</span>
                                        </div>
                                    </div>

                                    <?php if ($arquivoPendente): ?>
                                        <div class="alert warning">Canal reservado para o próximo credor. Ele permanece bloqueado para ativação até cadastrarmos o layout real do arquivo, nome, periodicidade e pasta de entrega.</div>
                                    <?php endif; ?>

                                    <details class="works-creditor-integration-editor">
                                        <summary>Configurar canal</summary>
                                        <form method="post" action="/operacao/configuracoes/credores.php?credor_id=<?= $credorIdSelecionado ?>#credor-integracoes" class="works-creditor-integration-form">
                                            <?= authCsrfInput() ?>
                                            <input type="hidden" name="acao" value="salvar_integracao">
                                            <input type="hidden" name="credor_id" value="<?= $credorIdSelecionado ?>">
                                            <input type="hidden" name="integracao_id" value="<?= (int) $integracao['id'] ?>">
                                            <label>Código<input type="text" name="codigo" maxlength="80" required value="<?= h((string) ($integracao['codigo'] ?? '')) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Nome<input type="text" name="nome" maxlength="160" required value="<?= h((string) ($integracao['nome'] ?? '')) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Evento<input type="text" name="evento_tipo" maxlength="100" required value="<?= h((string) ($integracao['evento_tipo'] ?? 'acionamento.registrado')) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Transporte
                                                <select name="tipo_transporte"<?= $podeEditarCredorSelecionado ? '' : ' disabled' ?>>
                                                    <?php foreach (['api' => 'API', 'arquivo' => 'Arquivo', 'sftp' => 'SFTP', 'pasta_compartilhada' => 'Pasta compartilhada', 'webhook' => 'Webhook'] as $valor => $rotulo): ?>
                                                        <option value="<?= h($valor) ?>"<?= (string) ($integracao['tipo_transporte'] ?? '') === $valor ? ' selected' : '' ?>><?= h($rotulo) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>Adapter<input type="text" name="adapter" maxlength="100" list="credor-integracao-adapters" required value="<?= h($adapterIntegracao) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Prefixo no .env<input type="text" name="segredo_env_prefixo" maxlength="80" placeholder="Ex.: ACME_API" value="<?= h((string) ($integracao['segredo_env_prefixo'] ?? '')) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Timeout (s)<input type="number" name="timeout_segundos" min="3" max="120" required value="<?= (int) ($integracao['timeout_segundos'] ?? 45) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Máx. tentativas<input type="number" name="max_tentativas" min="1" max="100" required value="<?= (int) ($integracao['max_tentativas'] ?? 8) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Retry inicial (s)<input type="number" name="retry_inicial_segundos" min="5" max="86400" required value="<?= (int) ($integracao['retry_inicial_segundos'] ?? 60) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label>Retry máximo (s)<input type="number" name="retry_max_segundos" min="5" max="604800" required value="<?= (int) ($integracao['retry_max_segundos'] ?? 3600) ?>"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>></label>
                                            <label class="works-creditor-integration-json">Configuração não sensível (JSON)<textarea name="configuracao_json" rows="7" maxlength="20000"<?= $podeEditarCredorSelecionado ? '' : ' readonly' ?>><?= h(credoresIntegracaoJsonExibicao($integracao['configuracao_json'] ?? null)) ?></textarea></label>
                                            <small class="works-field-help">No adapter ACME_ACIONAMENTOS, os endpoints são definidos no código e não devem ser informados neste JSON.</small>
                                            <label class="works-check-line"><input type="checkbox" name="ativo" value="1"<?= $integracaoAtiva ? ' checked' : '' ?><?= $podeEditarCredorSelecionado ? '' : ' disabled' ?>> Ativa</label>
                                            <?php if ($podeEditarCredorSelecionado): ?><button class="button" type="submit">Salvar canal</button><?php endif; ?>
                                        </form>
                                    </details>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <datalist id="credor-integracao-adapters">
                            <option value="ACME_ACIONAMENTOS">
                            <option value="ARQUIVO_GENERICO">
                        </datalist>

                        <?php if ($podeEditarCredorSelecionado): ?>
                            <details class="works-creditor-config-new works-creditor-integration-new">
                                <summary>+ Novo canal de integração</summary>
                                <form method="post" action="/operacao/configuracoes/credores.php?credor_id=<?= $credorIdSelecionado ?>#credor-integracoes" class="works-creditor-integration-form">
                                    <?= authCsrfInput() ?>
                                    <input type="hidden" name="acao" value="salvar_integracao">
                                    <input type="hidden" name="credor_id" value="<?= $credorIdSelecionado ?>">
                                    <label>Código<input type="text" name="codigo" maxlength="80" required placeholder="Ex.: ACIONAMENTOS"></label>
                                    <label>Nome<input type="text" name="nome" maxlength="160" required placeholder="Ex.: Saída de acionamentos"></label>
                                    <label>Evento<input type="text" name="evento_tipo" maxlength="100" required value="acionamento.registrado"></label>
                                    <label>Transporte<select name="tipo_transporte"><option value="api">API</option><option value="arquivo">Arquivo</option><option value="sftp">SFTP</option><option value="pasta_compartilhada">Pasta compartilhada</option><option value="webhook">Webhook</option></select></label>
                                    <label>Adapter<input type="text" name="adapter" maxlength="100" list="credor-integracao-adapters" required placeholder="Ex.: ARQUIVO_GENERICO"></label>
                                    <label>Prefixo no .env<input type="text" name="segredo_env_prefixo" maxlength="80" placeholder="Ex.: NOVO_CREDOR_API"></label>
                                    <label>Timeout (s)<input type="number" name="timeout_segundos" min="3" max="120" value="45" required></label>
                                    <label>Máx. tentativas<input type="number" name="max_tentativas" min="1" max="100" value="8" required></label>
                                    <label>Retry inicial (s)<input type="number" name="retry_inicial_segundos" min="5" max="86400" value="60" required></label>
                                    <label>Retry máximo (s)<input type="number" name="retry_max_segundos" min="5" max="604800" value="3600" required></label>
                                    <label class="works-creditor-integration-json">Configuração não sensível (JSON)<textarea name="configuracao_json" rows="6">{}</textarea></label>
                                    <small class="works-field-help">No adapter ACME_ACIONAMENTOS, os endpoints são definidos no código e não devem ser informados neste JSON.</small>
                                    <label class="works-check-line"><input type="checkbox" name="ativo" value="1"> Ativar agora</label>
                                    <button class="button" type="submit">Cadastrar canal</button>
                                </form>
                            </details>
                        <?php endif; ?>
                        <p class="works-creditor-security-note"><strong>Segredos:</strong> não informe usuário, senha, token ou chave privada neste JSON. Use somente variáveis do <code>.env.local</code> com o prefixo indicado.</p>
                    <?php endif; ?>
                </section>

                <section class="works-creditor-section">
                    <header><div><small>Parâmetros</small><h3>Configurações do credor</h3></div><span class="muted">Escopo geral ou por produto</span></header>
                    <?php if (!$credorRepository->moduloDisponivel()): ?>
                        <div class="alert warning"><strong>Execute a migration 076_configuracoes_credores.sql.</strong> Ela habilita parâmetros configuráveis por credor/produto.</div>
                    <?php else: ?>
                        <?php if ($podeEditarCredorSelecionado): ?>
                            <details class="works-creditor-config-new">
                                <summary>+ Adicionar parâmetro</summary>
                                <form method="post" action="/operacao/configuracoes/credores.php?credor_id=<?= $credorIdSelecionado ?>">
                                    <?= authCsrfInput() ?>
                                    <input type="hidden" name="acao" value="salvar_configuracao">
                                    <input type="hidden" name="credor_id" value="<?= $credorIdSelecionado ?>">
                                    <label>Chave<input type="text" name="chave" maxlength="120" list="credor-config-chaves" required placeholder="ex.: api.timeout_segundos"></label>
                                    <datalist id="credor-config-chaves">
                                        <option value="credor.ativo"><option value="acordos.habilitado"><option value="acordos.cadastro_manual_habilitado"><option value="integracao.adapter"><option value="financeiro.perfil"><option value="acionamento.resultados_permitidos"><option value="acionamento.resultados_bloqueados"><option value="api.habilitada"><option value="api.base_url"><option value="api.timeout_segundos"><option value="sincronizacao.intervalo_minutos"><option value="cargas.habilitadas">
                                    </datalist>
                                    <label>Produto <small>opcional</small><input type="text" name="produto" maxlength="180" list="credor-produtos" placeholder="Vazio = configuração geral"></label>
                                    <label>Tipo<select name="tipo"><option value="texto">Texto</option><option value="inteiro">Inteiro</option><option value="decimal">Decimal</option><option value="booleano">Sim/Não</option><option value="url">URL</option><option value="json">JSON</option></select></label>
                                    <label class="works-creditor-config-value">Valor<textarea name="valor" rows="2" maxlength="20000" placeholder="Valor da configuração"></textarea></label>
                                    <label class="works-creditor-config-description">Descrição<input type="text" name="descricao" maxlength="500" placeholder="Explique para que esse parâmetro será usado"></label>
                                    <label class="works-check-line"><input type="checkbox" name="ativo" value="1" checked> Ativa</label>
                                    <button class="button" type="submit">Salvar parâmetro</button>
                                </form>
                            </details>
                        <?php endif; ?>

                        <div class="works-creditor-config-list">
                            <?php if ($configuracoes === []): ?><div class="empty">Nenhum parâmetro adicional cadastrado.</div><?php endif; ?>
                            <?php foreach ($configuracoes as $config): ?>
                                <article>
                                    <div class="works-creditor-config-key"><strong><?= h((string) $config['chave']) ?></strong><small><?= trim((string) $config['produto']) !== '' ? 'Produto: ' . h((string) $config['produto']) : 'Configuração geral' ?> · <?= h((string) $config['tipo']) ?></small></div>
                                    <div class="works-creditor-config-value-read"><span><?= h(credoresConfigValorExibicao($config)) ?></span><?php if (trim((string) ($config['descricao'] ?? '')) !== ''): ?><small><?= h((string) $config['descricao']) ?></small><?php endif; ?></div>
                                    <span class="badge soft"><?= (int) $config['ativo'] === 1 ? 'Ativa' : 'Inativa' ?></span>
                                    <?php if ($podeEditarCredorSelecionado): ?><form method="post" action="/operacao/configuracoes/credores.php?credor_id=<?= $credorIdSelecionado ?>" onsubmit="return confirm('Remover este parâmetro?');"><?= authCsrfInput() ?><input type="hidden" name="acao" value="remover_configuracao"><input type="hidden" name="credor_id" value="<?= $credorIdSelecionado ?>"><input type="hidden" name="configuracao_id" value="<?= (int) $config['id'] ?>"><button class="button secondary table-button" type="submit">Remover</button></form><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="works-creditor-security-note"><strong>Segredos:</strong> usuário, senha, token e chaves privadas continuam no <code>.env</code>. Esta tela é para parâmetros operacionais não sensíveis.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
