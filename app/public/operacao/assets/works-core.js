(function () {
    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.hidden = false;
        document.body.classList.add('works-modal-open');
        const firstInput = modal.querySelector('input, select, textarea, button');
        if (firstInput) firstInput.focus();
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        if (!document.querySelector('[data-works-modal]:not([hidden])')) {
            document.body.classList.remove('works-modal-open');
        }
    }

    document.addEventListener('click', function (event) {
        const opener = event.target.closest('[data-works-modal-open]');
        if (opener) {
            event.preventDefault();
            openModal(opener.getAttribute('data-works-modal-open'));
            return;
        }

        const closer = event.target.closest('[data-works-modal-close]');
        if (closer) {
            event.preventDefault();
            closeModal(closer.closest('[data-works-modal]'));
            return;
        }

        const backdrop = event.target.matches('[data-works-modal]') ? event.target : null;
        if (backdrop) {
            closeModal(backdrop);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[data-works-modal]:not([hidden])').forEach(closeModal);
    });
})();

(function () {
    const form = document.querySelector('[data-works-search-form]');
    const input = document.getElementById('adaptor-q');
    const tipo = document.getElementById('adaptor-tipo-busca');
    const main = document.querySelector('[data-works-main]');
    if (!input || !tipo) return;

    const aliases = [
        { palavras: ['documento', 'cnpj', 'cpf'], tipo: 'documento', qualquerPosicao: true },
        { palavras: ['contrato', 'dívida', 'divida'], tipo: 'contrato', qualquerPosicao: true },
        { palavras: ['whatsapp', 'telefone', 'celular'], tipo: 'telefone', qualquerPosicao: true },
        { palavras: ['cliente', 'nome'], tipo: 'nome', qualquerPosicao: true },
        { palavras: ['wpp', 'tel'], tipo: 'telefone', qualquerPosicao: false },
        { palavras: ['conta'], tipo: 'contrato', qualquerPosicao: false }
    ];

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[char];
        });
    }

    function escapeRegExp(value) {
        return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function interpretarAlias() {
        let value = input.value;
        for (const item of aliases) {
            for (const palavra of item.palavras) {
                const pattern = item.qualquerPosicao
                    ? new RegExp(escapeRegExp(palavra), 'i')
                    : new RegExp('(^|[\\s:;,\\-_=])' + escapeRegExp(palavra) + '(?=$|[\\s:;,\\-_=])', 'i');
                if (!pattern.test(value)) continue;
                tipo.value = item.tipo;
                value = value.replace(pattern, item.qualquerPosicao ? '' : ' ').replace(/[\s:;,\-_=]+/g, ' ').trim();
                input.value = value;
                setTimeout(function () { input.focus(); }, 0);
                return;
            }
        }
    }

    function resultCard(item) {
        const acordoTexto = item.tem_acordo_ativo ? 'Com acordo ativo' : 'Sem acordo ativo';
        const retiredClass = item.retirado_credor ? ' is-retired' : '';
        const retiredBadge = item.retirado_credor ? '<span class="works-retired-badge">Retirado pelo credor</span>' : '';
        return `
            <a class="adaptor-result-card${retiredClass}" href="${escapeHtml(item.url)}">
                <h2>${escapeHtml(item.pessoa_nome)}</h2>
                <p class="adaptor-result-status">${escapeHtml(item.status_contrato)}</p>
                ${retiredBadge}
                <div class="adaptor-mini-strip">
                    <span>Contrato Numero: <strong>${escapeHtml(item.codigo_contrato)}</strong></span>
                    <span>Valor da Divida: <strong>${escapeHtml(item.saldo_atual)}</strong></span>
                    <span>${escapeHtml(acordoTexto)}</span>
                </div>
                <span class="adaptor-result-situation ${escapeHtml(item.badge_class)}">Situacao da Divida: ${escapeHtml(item.status_contrato)}</span>
                <p>Contrato do credor <strong>${escapeHtml(item.credor_nome)}</strong>. Produto <strong>${escapeHtml(item.produto)}</strong>. Inscricao ${escapeHtml(item.documento)}.</p>
                <small class="works-search-meta">${Number(item.total_contatos || 0)} contato(s) · ${Number(item.total_parcelas || 0)} parcela(s) · ultimo atendimento: ${escapeHtml(item.ultimo_atendimento_em || '-')}</small>
            </a>`;
    }

    function renderHome() {
        if (!main) return;
        main.innerHTML = `
            <section class="adaptor-empty-home" data-works-empty-home>
                <p>Digite no campo superior. Exemplos: <strong>contrato ACM10000007</strong>, <strong>cpf 52998224725</strong>, <strong>telefone 11999998888</strong> ou <strong>nome maria</strong>.</p>
                <small>O resultado sempre abre por contrato, para o operador escolher a divida correta.</small>
            </section>`;
    }

    function renderLoading() {
        if (!main) return;
        main.innerHTML = `
            <section class="adaptor-result-panel works-loading-panel" data-works-results-panel>
                <div class="adaptor-panel-title"><strong>Resultado da busca</strong><a href="/operacao/works.php">× Cancelar Busca</a></div>
                <div class="works-loading-row"><span class="works-spinner" aria-hidden="true"></span><strong>Pesquisando contratos...</strong></div>
            </section>`;
    }

    function renderResults(payload, termo, tipoBusca) {
        if (!main) return;
        const cards = (payload.items || []).map(resultCard).join('');
        const empty = '<div class="adaptor-empty-box">Nenhum contrato encontrado para esta consulta.</div>';
        main.innerHTML = `
            <section class="adaptor-result-panel" data-works-results-panel>
                <div class="adaptor-panel-title">
                    <strong>Resultado da busca</strong>
                    <a href="/operacao/works.php" data-works-cancel>× Cancelar Busca</a>
                </div>
                <p class="adaptor-search-resume">Busca interpretada como <strong>${escapeHtml(payload.rotulo || tipoBusca)}</strong>. ${Number(payload.count || 0)} contrato(s) encontrado(s). O clique abre o contrato em foco.</p>
                <div class="adaptor-contract-results">${cards || empty}</div>
            </section>`;
        const url = new URL('/operacao/works.php', window.location.origin);
        if (termo) url.searchParams.set('q', termo);
        if (tipoBusca) url.searchParams.set('tipo', tipoBusca);
        window.history.pushState({worksSearch: true, q: termo, tipo: tipoBusca}, '', url.pathname + url.search);
    }

    async function executarBusca(event) {
        if (event) event.preventDefault();
        interpretarAlias();
        const q = input.value.trim();
        const tipoBusca = tipo.value || 'auto';
        if (!q) {
            renderHome();
            window.history.pushState({worksSearch: true}, '', '/operacao/works.php');
            return;
        }

        renderLoading();
        const params = new URLSearchParams({q, tipo: tipoBusca});
        try {
            const response = await fetch('/operacao/works_busca.php?' + params.toString(), {
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.erro || 'Falha ao pesquisar.');
            renderResults(payload, q, tipoBusca);
        } catch (error) {
            if (!main) return;
            main.innerHTML = `<section class="adaptor-alert adaptor-alert-danger"><strong>Erro:</strong> ${escapeHtml(error.message || error)}</section>`;
        }
    }

    input.addEventListener('input', interpretarAlias);
    if (form && window.fetch && window.history && main) {
        form.addEventListener('submit', executarBusca);
    }

    document.addEventListener('click', function (event) {
        const cancel = event.target.closest('[data-works-cancel]');
        if (!cancel) return;
        event.preventDefault();
        input.value = '';
        tipo.value = 'auto';
        renderHome();
        window.history.pushState({worksSearch: true}, '', '/operacao/works.php');
    });
})();

(function () {
    async function copyValue(button) {
        const value = button.getAttribute('data-copy-value') || '';
        if (!value) return;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
            } else {
                const area = document.createElement('textarea');
                area.value = value;
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }
            const original = button.textContent;
            button.textContent = button.getAttribute('data-copy-label') || 'Copiado';
            button.classList.add('is-copied');
            window.setTimeout(function () {
                button.textContent = original;
                button.classList.remove('is-copied');
            }, 1400);
        } catch (error) {
            if (typeof showPageAlert === 'function') {
                showPageAlert('Não foi possível copiar agora.');
            }
        }
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-copy-value]');
        if (!button) return;
        event.preventDefault();
        copyValue(button);
    });
})();
