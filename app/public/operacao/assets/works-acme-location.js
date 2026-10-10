(function () {
    'use strict';

    const panel = document.querySelector('[data-acme-location-sync]');
    if (!panel) return;

    const button = panel.querySelector('[data-acme-location-refresh]');
    const status = panel.querySelector('[data-acme-location-status]');
    const endpoint = panel.getAttribute('data-acme-location-url') || '';
    if (!button || !status || !endpoint) return;

    function setStatus(kind, text, loading) {
        status.className = 'works-location-api-status' + (kind ? ' is-' + kind : '');
        status.replaceChildren();
        if (loading) {
            const spinner = document.createElement('span');
            spinner.className = 'works-spinner tiny';
            spinner.setAttribute('aria-hidden', 'true');
            status.appendChild(spinner);
        }
        const strong = document.createElement('strong');
        strong.textContent = text;
        status.appendChild(strong);
    }

    async function refresh() {
        button.disabled = true;
        setStatus('loading', 'Consultando localização ACME em segundo plano...', true);
        try {
            const response = await fetch(endpoint, {
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok || !payload.ok) {
                throw new Error(payload.erro || 'Não foi possível consultar a localização ACME.');
            }

            if (!payload.disponivel) {
                setStatus('warning', payload.mensagem || 'Localização ACME indisponível neste ambiente.', false);
                return;
            }

            const inserted = Number(payload.telefones_inseridos || 0)
                + Number(payload.emails_inseridos || 0)
                + Number(payload.enderecos_inseridos || 0);
            if (inserted > 0) {
                setStatus('success', 'Localização atualizada. Recarregando os contatos conciliados...', true);
                const url = new URL(window.location.href);
                url.searchParams.set('acme_location_synced', '1');
                window.location.replace(url.toString());
                return;
            }

            setStatus('success', payload.mensagem || 'Localização ACME conferida; nenhum dado novo foi encontrado.', false);
        } catch (error) {
            setStatus('warning', (error && error.message) ? error.message : String(error), false);
        } finally {
            button.disabled = false;
        }
    }

    button.addEventListener('click', refresh);

    const url = new URL(window.location.href);
    if (url.searchParams.get('acme_location_synced') === '1') {
        setStatus('success', 'Dados de localização ACME atualizados no CRM.', false);
        url.searchParams.delete('acme_location_synced');
        window.history.replaceState({}, '', url.toString());
    }
}());
