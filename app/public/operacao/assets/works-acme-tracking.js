(function () {
    'use strict';

    const timeline = document.querySelector('[data-acme-unified-timeline]');
    if (!timeline) return;

    const baseUrl = timeline.getAttribute('data-acme-tracking-url') || '';
    if (!baseUrl) return;

    const status = document.querySelector('[data-acme-tracking-status]');
    const parsedBaseUrl = new URL(baseUrl, window.location.origin);
    const contractId = parsedBaseUrl.searchParams.get('contrato_id') || '';
    const cacheWindowMs = 10 * 60 * 1000;
    const syncKey = contractId ? 'works:acme:tracking:last-sync:' + contractId : '';
    const failureKey = contractId ? 'works:acme:tracking:last-failure:' + contractId : '';
    let requestSerial = 0;

    function text(value, fallback) {
        const normalized = value === null || value === undefined ? '' : String(value).trim();
        return normalized || (fallback || '');
    }

    function parseDate(value) {
        const raw = text(value);
        if (!raw) return null;
        const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(raw)
            ? raw.replace(' ', 'T')
            : raw;
        const date = new Date(normalized);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function dateParts(value) {
        const date = parseDate(value);
        if (!date) return {date: '-', time: ''};
        return {
            date: new Intl.DateTimeFormat('pt-BR').format(date),
            time: new Intl.DateTimeFormat('pt-BR', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            }).format(date)
        };
    }

    function dateTime(value) {
        const parts = dateParts(value);
        return parts.time ? parts.date + ' ' + parts.time : parts.date;
    }

    function setError(message) {
        if (!status) return;
        const msg = text(message);
        if (!msg) {
            status.hidden = true;
            status.textContent = '';
            return;
        }
        status.hidden = false;
        status.textContent = msg;
    }

    function readTimestamp(key) {
        if (!key) return 0;
        try {
            const value = Number(window.sessionStorage.getItem(key) || 0);
            return Number.isFinite(value) && value > 0 ? value : 0;
        } catch (error) {
            return 0;
        }
    }

    function writeTimestamp(key) {
        if (!key) return;
        try { window.sessionStorage.setItem(key, String(Date.now())); } catch (error) {}
    }

    function pill(label, className) {
        const node = document.createElement('span');
        node.className = className;
        node.textContent = label;
        return node;
    }

    function integrationState(item) {
        if (!item || item.fonte !== 'works' || !item.acme) return null;
        if (item.acme.confirmado_no_historico) {
            return pill('ACME sincronizado', 'works-history-acme-state is-confirmed');
        }

        const state = text(item.acme.status);
        if (!state) return null;
        const labels = {
            pendente: 'ACME pendente',
            processando: 'ACME enviando',
            enviado: 'Enviado \u00e0 ACME',
            confirmado: 'ACME confirmado',
            erro_retry: 'ACME aguardando retry',
            erro_definitivo: 'ACME com erro',
            cancelado: 'Envio ACME cancelado'
        };
        return pill(labels[state] || text(item.acme.status_label, 'ACME'), 'works-history-acme-state is-' + state);
    }

    function renderItem(item) {
        const article = document.createElement('article');
        const source = text(item.fonte, 'works').toLowerCase();
        article.className = 'adaptor-timeline-item works-history-item is-' + source;
        article.dataset.historySource = source;

        const parts = dateParts(item.criado_em);
        const date = document.createElement('div');
        date.className = 'adaptor-timeline-date';
        const strongDate = document.createElement('strong');
        strongDate.textContent = parts.date;
        const smallTime = document.createElement('small');
        smallTime.textContent = parts.time;
        date.append(strongDate, smallTime);

        const dot = document.createElement('div');
        dot.className = 'adaptor-timeline-dot';
        dot.textContent = '\u261f';

        const card = document.createElement('div');
        card.className = 'adaptor-timeline-card works-history-card is-' + source;

        const head = document.createElement('div');
        head.className = 'works-history-card-head';
        const title = document.createElement('h3');
        title.textContent = text(item.resultado, source === 'acme' ? 'ACIONAMENTO ACME' : 'ACIONAMENTO').toUpperCase();
        const sourceBadge = pill(source === 'acme' ? 'ACME' : 'Pactua', 'works-history-source is-' + source);
        head.append(title, sourceBadge);
        card.appendChild(head);

        const meta = document.createElement('p');
        meta.className = 'works-history-meta';
        if (source === 'acme') {
            const actionId = item.acme ? text(item.acme.action_id) : '';
            meta.textContent = actionId ? 'ActionID ' + actionId + ' \u00b7 origem ACME' : 'Origem ACME';
        } else {
            const channel = text(item.canal_label, text(item.canal, 'Canal n\u00e3o informado'));
            const contact = text(item.contato_utilizado);
            meta.textContent = channel + (contact ? ' - ' + contact : '');
        }
        card.appendChild(meta);

        const observation = text(item.observacao);
        if (observation) {
            const note = document.createElement('p');
            note.className = 'works-history-note';
            note.textContent = observation;
            card.appendChild(note);
        }

        const badges = document.createElement('div');
        badges.className = 'works-history-badges';
        const state = integrationState(item);
        if (state) badges.appendChild(state);

        if (item.proximo_acionamento) {
            const label = source === 'acme' ? 'Agendado: ' : 'Retorno: ';
            badges.appendChild(pill(label + dateTime(item.proximo_acionamento), 'adaptor-mini-btn'));
        }
        if (badges.childElementCount > 0) card.appendChild(badges);

        article.append(date, dot, card);
        return article;
    }

    function render(items) {
        const rows = Array.isArray(items) ? items : [];
        if (rows.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'adaptor-empty-box';
            empty.textContent = 'Nenhum acionamento registrado para este contrato/cliente.';
            timeline.replaceChildren(empty);
            return;
        }
        timeline.replaceChildren.apply(timeline, rows.map(renderItem));
    }

    function requestUrl(refresh) {
        const url = new URL(baseUrl, window.location.origin);
        url.searchParams.set('refresh', refresh ? '1' : '0');
        url.searchParams.set('force', '0');
        return url.toString();
    }

    async function request(refresh) {
        const response = await fetch(requestUrl(refresh), {
            headers: {'Accept': 'application/json'},
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const payload = await response.json().catch(function () { return {}; });
        if (!response.ok || !payload.ok) {
            throw new Error(payload.erro || 'Falha ao consultar acionamentos ACME.');
        }
        return payload;
    }

    async function refreshInBackground() {
        const serial = ++requestSerial;
        try {
            const payload = await request(true);
            if (serial !== requestSerial) return;
            render(payload.timeline || []);
            if (payload.api_erro) {
                writeTimestamp(failureKey);
                setError('ACME: n\u00e3o foi poss\u00edvel atualizar o hist\u00f3rico agora. ' + payload.api_erro);
            } else if (!payload.disponivel) {
                setError('ACME: a integra\u00e7\u00e3o de acionamentos n\u00e3o est\u00e1 dispon\u00edvel para este credor.');
            } else {
                writeTimestamp(syncKey);
                setError('');
            }
        } catch (error) {
            if (serial !== requestSerial) return;
            writeTimestamp(failureKey);
            setError('ACME: n\u00e3o foi poss\u00edvel atualizar o hist\u00f3rico agora. ' + (error && error.message ? error.message : String(error)));
        }
    }

    function shouldRefresh() {
        const now = Date.now();
        const lastSync = readTimestamp(syncKey);
        if (lastSync > 0 && (now - lastSync) < cacheWindowMs) return false;
        const lastFailure = readTimestamp(failureKey);
        if (lastFailure > 0 && (now - lastFailure) < 60000) return false;
        return true;
    }

    async function loadCachedTimeline() {
        try {
            const payload = await request(false);
            render(payload.timeline || []);
            if (payload.api_erro) {
                setError('ACME: ' + payload.api_erro);
            }
        } catch (error) {
            // O HTML ja contem o historico local. Se ate a leitura do cache
            // falhar, mantenha a ficha utilizavel e apenas sinalize o problema.
            setError('ACME: nao foi possivel carregar o historico externo salvo. ' + (error && error.message ? error.message : String(error)));
        }
    }

    // Uma chamada local e mantida para mesclar itens externos ja salvos (por
    // exemplo, Notas criadas diretamente na ACME). A chamada externa, que e a
    // parte lenta, so volta a acontecer depois da janela de 10 minutos.
    void loadCachedTimeline().then(function () {
        if (shouldRefresh()) {
            void refreshInBackground();
        }
    });
}());
