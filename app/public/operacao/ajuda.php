<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requireOperacaoLogin();

$pageTitle = 'Ajuda Pactua';
$pageSubtitle = 'Guia rápido da operação: onde trabalhar, como negociar e o que cada termo significa.';
$activePage = 'ajuda';

$temEstrategia = usuarioPode('estrategias.visualizar');
$temRetornos = usuarioPode('retornos.gerenciar');
$temAtendimentos = usuarioPode('atendimentos.visualizar');

require __DIR__ . '/includes/header.php';
?>
<main class="main-content works-help-page">
    <header class="page-header works-page-header-clean">
        <div>
            <span class="eyebrow">Pactua Call Center</span>
            <h1>Ajuda rápida</h1>
            <p>O fluxo foi desenhado para o operador trabalhar principalmente no Call Center — mas todo dia começa escolhendo <strong>de onde</strong> puxar o próximo contrato.</p>
        </div>
        <a class="button" href="/operacao/works.php">Ir para o Call Center</a>
    </header>

    <section class="card works-help-start">
        <header class="works-section-heading"><div><small>Primeiros passos</small><h2>De onde vem o próximo contrato?</h2></div></header>
        <p>Antes de buscar manualmente, confira se já existe um contrato esperando por você — é mais rápido e segue a prioridade definida pela supervisão.</p>
        <div class="works-help-grid works-help-grid-start">
            <?php if ($temEstrategia): ?>
                <article class="card">
                    <span class="works-help-number">E</span>
                    <h2>Estratégia</h2>
                    <p>Contratos priorizados pela supervisão pra sua carteira. É o primeiro lugar pra olhar ao começar o dia — abre o contrato direto no Call Center.</p>
                </article>
            <?php endif; ?>
            <?php if ($temRetornos): ?>
                <article class="card">
                    <span class="works-help-number">R</span>
                    <h2>Retornos</h2>
                    <p>Fila de clientes com próximo acionamento agendado (promessa de retorno, horário combinado). Confira sempre no início do turno.</p>
                </article>
            <?php endif; ?>
            <?php if ($temAtendimentos): ?>
                <article class="card">
                    <span class="works-help-number">A</span>
                    <h2>Atendimentos</h2>
                    <p>Histórico das últimas tentativas registradas na operação — útil pra conferir o que já foi feito antes de ligar de novo.</p>
                </article>
            <?php endif; ?>
            <article class="card">
                <span class="works-help-number">B</span>
                <h2>Busca manual</h2>
                <p>Sem nada na fila? Use a busca do Call Center: nome, CPF/CNPJ, telefone ou contrato. O Pactua identifica o tipo sozinho.</p>
            </article>
        </div>
    </section>

    <section class="works-help-grid">
        <article class="card">
            <span class="works-help-number">1</span>
            <h2>Localizar cliente</h2>
            <p>Digite nome, CPF/CNPJ, telefone ou contrato. O Pactua identifica o tipo automaticamente e retorna os contratos encontrados.</p>
        </article>
        <article class="card">
            <span class="works-help-number">2</span>
            <h2>Escolher contrato</h2>
            <p>O atendimento sempre acontece sobre um contrato em foco. Se o cliente tiver mais de uma dívida, escolha a correta antes de acionar ou negociar.</p>
        </article>
        <article class="card">
            <span class="works-help-number">3</span>
            <h2>Registrar acionamento</h2>
            <p>Procure a tabulação pelo nome, escolha canal e contato usado. Use Ctrl+Enter para registrar com mais rapidez.</p>
        </article>
        <article class="card">
            <span class="works-help-number">4</span>
            <h2>Localização</h2>
            <p>Telefones, e-mails e endereços mostram categoria, situação e origem. Dados vindos do credor são preservados e complementados pelo Pactua.</p>
        </article>
        <article class="card">
            <span class="works-help-number">5</span>
            <h2>Financeiro</h2>
            <p>Consulte parcelas originais, acordo atual e opções de negociação. Contrato retirado pelo credor ou com acordo ativo não libera nova negociação.</p>
        </article>
        <article class="card">
            <span class="works-help-number">6</span>
            <h2>Carteira de acordos</h2>
            <p>Acompanhe vencimento, carência, pagamentos manuais e ciclo de vida até quitação, quebra ou cancelamento.</p>
        </article>
    </section>

    <section class="card works-help-glossary">
        <header class="works-section-heading"><div><small>Glossário</small><h2>Termos operacionais</h2></div></header>
        <dl>
            <div><dt>Age / atraso</dt><dd>Dias de atraso. Se não houver carga do dia, o Pactua pode projetar o valor; com acordo vigente, ele congela na data do acordo.</dd></div>
            <div><dt>Retirado pelo credor</dt><dd>O contrato não apareceu na carga mais recente do mesmo escopo. Fica disponível para histórico, mas não para nova negociação.</dd></div>
            <div><dt>Carência</dt><dd>Período após o vencimento em que o acordo ainda não é considerado quebrado.</dd></div>
            <div><dt>Promessa de acordo</dt><dd>Negociação enviada/registrada, ainda acompanhada até confirmação e pagamento.</dd></div>
            <div><dt>HOT</dt><dd>Contato ou endereço priorizado pelo credor/origem para tentativa.</dd></div>
            <div><dt>Responsável</dt><dd>Usuário que acompanha o acordo na carteira. Pode ser diferente de quem fechou, se o perfil permitir atribuição.</dd></div>
            <div><dt>Estratégia</dt><dd>Conjunto de contratos que a supervisão separou e priorizou pra um grupo de operadores trabalhar. Mais de um operador pode ver o mesmo contrato — não é exclusivo, só mostra quando já está sendo trabalhado.</dd></div>
            <div><dt>Carga</dt><dd>Arquivo enviado pelo credor com a base de contratos ou pagamentos atualizada. É a fonte de tudo — se um contrato "sumiu", provavelmente é porque não apareceu na carga mais recente.</dd></div>
        </dl>
    </section>

    <section class="card works-help-shortcuts">
        <header class="works-section-heading"><div><small>Produtividade</small><h2>Atalhos no Call Center</h2></div></header>
        <div><kbd>/</kbd><span>Focar a busca de tabulação quando estiver no Acionamento.</span></div>
        <div><kbd>Ctrl</kbd> + <kbd>Enter</kbd><span>Registrar o acionamento atual.</span></div>
        <div><kbd>Esc</kbd><span>Fechar modais abertos.</span></div>
    </section>

    <section class="card works-help-support">
        <header class="works-section-heading"><div><small>Precisa de mais ajuda?</small><h2>Fale com a supervisão</h2></div></header>
        <p>Este guia cobre o uso do dia a dia. Para acesso, permissões ou algo que pareça errado no sistema, procure seu supervisor ou administrador — não compartilhe login/senha entre operadores, cada pessoa deve ter o seu.</p>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
