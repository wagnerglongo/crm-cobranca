(function () {
    'use strict';

    let overlay = null;
    let titleNode = null;
    let detailNode = null;
    let visible = false;
    let downloadInterval = null;
    let downloadTimeout = null;

    function ensureOverlay() {
        if (overlay) {
            return overlay;
        }

        overlay = document.createElement('div');
        overlay.className = 'global-request-loading';
        overlay.setAttribute('data-global-request-loading', '');
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-busy', 'true');
        overlay.hidden = true;
        overlay.innerHTML = [
            '<div class="global-request-loading-card">',
            '<span class="works-spinner global-request-spinner" aria-hidden="true"></span>',
            '<strong data-global-loading-title>Processando solicitação...</strong>',
            '<small data-global-loading-detail>O CRM continuará trabalhando sem permitir envios duplicados.</small>',
            '<div class="global-request-progress" aria-hidden="true"><span></span></div>',
            '</div>'
        ].join('');

        document.body.appendChild(overlay);
        titleNode = overlay.querySelector('[data-global-loading-title]');
        detailNode = overlay.querySelector('[data-global-loading-detail]');
        return overlay;
    }

    function show(message, detail) {
        const element = ensureOverlay();
        if (titleNode) {
            titleNode.textContent = message || 'Processando solicitação...';
        }
        if (detailNode) {
            detailNode.textContent = detail || 'Aguarde a conclusão para evitar envios duplicados.';
        }
        element.hidden = false;
        document.documentElement.classList.add('global-request-is-loading');
        visible = true;
    }

    function hide() {
        if (overlay) {
            overlay.hidden = true;
        }
        document.documentElement.classList.remove('global-request-is-loading');
        visible = false;
    }

    function messageFrom(element, fallback) {
        if (!(element instanceof Element)) {
            return fallback;
        }
        return element.getAttribute('data-loading-message') || fallback;
    }

    function detailFrom(element, fallback) {
        if (!(element instanceof Element)) {
            return fallback;
        }
        return element.getAttribute('data-loading-detail') || fallback;
    }

    function samePageHashOnly(link) {
        if (!link.hash) {
            return false;
        }
        return link.origin === window.location.origin
            && link.pathname === window.location.pathname
            && link.search === window.location.search;
    }

    function isDownloadRequest(form, submitter) {
        return form.matches('[data-download-request]')
            || (submitter instanceof Element && submitter.matches('[data-download-request]'));
    }

    function downloadToken() {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint32Array(4);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, function (value) {
                return value.toString(16).padStart(8, '0');
            }).join('');
        }

        return String(Date.now()) + String(Math.random()).replace(/\D/g, '').slice(0, 16);
    }

    function setDownloadToken(form, token) {
        let input = form.querySelector('input[name="_download_token"]');
        if (!(input instanceof HTMLInputElement)) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_download_token';
            form.appendChild(input);
        }
        input.value = token;
    }

    function cookieValue(name) {
        const prefix = name + '=';
        const parts = document.cookie ? document.cookie.split(';') : [];
        for (const raw of parts) {
            const part = raw.trim();
            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.slice(prefix.length));
            }
        }
        return null;
    }

    function deleteCookie(name) {
        document.cookie = name + '=; Path=/operacao; Max-Age=0; SameSite=Lax';
    }

    function resetSubmitButtons(form) {
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.removeAttribute('aria-disabled');
            button.classList.remove('is-submitting');
        });
    }

    function stopDownloadWatch() {
        if (downloadInterval !== null) {
            window.clearInterval(downloadInterval);
            downloadInterval = null;
        }
        if (downloadTimeout !== null) {
            window.clearTimeout(downloadTimeout);
            downloadTimeout = null;
        }
    }

    function watchDownload(form, token) {
        stopDownloadWatch();
        const cookieName = 'works_download_' + token;
        deleteCookie(cookieName);

        const finish = function () {
            stopDownloadWatch();
            deleteCookie(cookieName);
            hide();
            resetSubmitButtons(form);
        };

        downloadInterval = window.setInterval(function () {
            const status = cookieValue(cookieName);
            if (status === 'ready' || status === 'error') {
                finish();
            }
        }, 250);

        // Protecao contra overlay eterno se a rede cair antes de o endpoint responder.
        downloadTimeout = window.setTimeout(function () {
            finish();
        }, 20 * 60 * 1000);
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.matches('[data-no-loading]')) {
            return;
        }
        if (form.target && form.target !== '_self') {
            return;
        }

        const submitter = event.submitter instanceof Element ? event.submitter : null;
        if (submitter && submitter.matches('[data-no-loading]')) {
            return;
        }

        const isDownload = isDownloadRequest(form, submitter);
        if (isDownload) {
            const token = downloadToken();
            setDownloadToken(form, token);
            watchDownload(form, token);
        }

        const message = messageFrom(submitter, messageFrom(form, form.method.toLowerCase() === 'get' ? 'Carregando resultados...' : 'Processando solicitação...'));
        const detail = detailFrom(submitter, detailFrom(form, 'Aguarde a conclusão para evitar envios duplicados.'));

        window.setTimeout(function () {
            if (!event.defaultPrevented) {
                show(message, detail);
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
                    button.setAttribute('aria-disabled', 'true');
                    button.classList.add('is-submitting');
                });
            }
        }, 0);
    }, true);

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!(link instanceof HTMLAnchorElement)
            || link.matches('[data-no-loading]')
            || link.target === '_blank'
            || link.hasAttribute('download')
            || link.protocol === 'javascript:'
            || link.origin !== window.location.origin
            || samePageHashOnly(link)) {
            return;
        }

        window.setTimeout(function () {
            if (!event.defaultPrevented) {
                show(
                    messageFrom(link, 'Carregando página...'),
                    detailFrom(link, 'Preparando os dados solicitados.')
                );
            }
        }, 0);
    }, true);

    window.addEventListener('pageshow', function () {
        stopDownloadWatch();
        hide();
    });
    window.addEventListener('load', function () {
        if (visible) {
            hide();
        }
    });

    window.WORKSLoading = Object.freeze({show: show, hide: hide});
})();
