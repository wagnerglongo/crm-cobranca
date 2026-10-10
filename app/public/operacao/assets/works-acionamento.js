(function () {
    const picker = document.querySelector('[data-event-picker]');
    if (!picker) return;

    const escapeHtml = function (value) {
        return String(value ?? '').replace(/[&<>'"]/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[char];
        });
    };

    const search = picker.querySelector('[data-event-search]');
    const select = picker.querySelector('[data-event-select]');
    const selected = picker.querySelector('[data-event-selected]');
    const suggestions = picker.querySelector('[data-event-suggestions]');
    const quick = picker.querySelector('[data-event-quick]');
    const form = picker.closest('form');
    const motivoField = form ? form.querySelector('[data-motivo-field]') : null;
    const motivoSelect = form ? form.querySelector('[data-motivo-select]') : null;
    const reagendaField = form ? form.querySelector('[data-reagenda-field]') : null;
    const reagendaInput = form ? form.querySelector('[data-reagenda-input]') : null;
    const userId = picker.getAttribute('data-user-id') || '0';
    const favKey = 'works:eventos:favoritos:' + userId;
    const recentKey = 'works:eventos:recentes:' + userId;

    const normalize = function (value) {
        return (value || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    };

    const favoriteCurrent = picker.querySelector('[data-event-favorite-current]');
    const options = Array.from(select.options).filter(function (option) {
        return option.value !== '';
    }).map(function (option) {
        return {
            value: option.value,
            label: option.dataset.label || option.textContent.trim(),
            category: option.dataset.category || '',
            cpc: option.dataset.cpc === '1',
            reagenda: option.dataset.reagenda === '1',
            search: normalize((option.dataset.label || option.textContent) + ' ' + (option.dataset.category || '') + ' ' + option.value),
        };
    });

    const atualizarCampoReagenda = function (reagenda) {
        if (!reagendaField) return;
        reagendaField.classList.toggle('is-required', !!reagenda);
        if (reagendaInput) {
            if (reagenda) {
                reagendaInput.setAttribute('required', 'required');
            } else {
                reagendaInput.removeAttribute('required');
            }
        }
    };

    const atualizarCampoMotivo = function (cpc) {
        if (!motivoField) return;
        if (cpc) {
            motivoField.hidden = false;
            if (motivoSelect) motivoSelect.setAttribute('required', 'required');
        } else {
            motivoField.hidden = true;
            if (motivoSelect) {
                motivoSelect.removeAttribute('required');
                motivoSelect.value = '';
            }
        }
    };

    const readList = function (key) {
        try {
            const parsed = JSON.parse(localStorage.getItem(key) || '[]');
            return Array.isArray(parsed) ? parsed.filter(Boolean).slice(0, 8) : [];
        } catch (e) {
            return [];
        }
    };
    const writeList = function (key, values) {
        try { localStorage.setItem(key, JSON.stringify(values.slice(0, 8))); } catch (e) {}
    };

    let favorites = readList(favKey);
    let recents = readList(recentKey);
    let suppressFocusSuggestions = false;

    const byValue = function (value) {
        return options.find(function (item) { return item.value === value; }) || null;
    };

    const setSelected = function (value, remember) {
        const item = byValue(value);
        if (!item) return;
        select.value = item.value;
        if (search) {
            search.value = item.label;
            search.setCustomValidity('');
            search.setAttribute('aria-expanded', 'false');
        }
        if (selected) {
            const fav = favorites.includes(item.value);
            selected.textContent = 'Resultado selecionado: ' + item.label + (item.category ? ', categoria ' + item.category : '');
            if (favoriteCurrent) {
                favoriteCurrent.hidden = false;
                favoriteCurrent.classList.toggle('active', fav);
                favoriteCurrent.textContent = fav ? '★' : '☆';
                favoriteCurrent.setAttribute('aria-label', fav ? 'Remover resultado dos favoritos' : 'Favoritar resultado');
                favoriteCurrent.setAttribute('title', fav ? 'Remover dos favoritos' : 'Favoritar resultado');
            }
        }
        if (remember) {
            recents = [item.value].concat(recents.filter(function (v) { return v !== item.value; })).slice(0, 6);
            writeList(recentKey, recents);
        }
        if (suggestions) suggestions.hidden = true;
        atualizarCampoMotivo(item.cpc);
        atualizarCampoReagenda(item.reagenda);
        renderQuick();
    };

    const clearSelected = function () {
        select.value = '';
        if (selected) selected.textContent = '';
        if (favoriteCurrent) favoriteCurrent.hidden = true;
        atualizarCampoMotivo(false);
        atualizarCampoReagenda(false);
    };

    const renderQuick = function () {
        if (!quick) return;
        const values = favorites.concat(recents.filter(function (v) { return !favorites.includes(v); })).slice(0, 6);
        if (!values.length) {
            quick.innerHTML = '<span class="muted">Favoritos e últimos usados aparecerão aqui.</span>';
            return;
        }
        quick.innerHTML = values.map(function (value) {
            const item = byValue(value);
            if (!item) return '';
            const fav = favorites.includes(value);
            return '<button type="button" class="works-event-chip ' + (fav ? 'favorite' : '') + '" data-event-pick="' + escapeHtml(value) + '">' +
                (fav ? '★ ' : '') + escapeHtml(item.label) + '</button>';
        }).join('');
    };

    const renderSuggestions = function (showAll) {
        if (!search || !suggestions) return;
        const term = showAll ? '' : normalize(search.value);
        const found = term
            ? options.filter(function (item) { return item.search.includes(term); })
            : options;
        if (!found.length) {
            suggestions.innerHTML = '<div class="works-event-no-result">Nenhuma tabulação disponível neste contexto.</div>';
            suggestions.hidden = false;
            search.setAttribute('aria-expanded', 'true');
            return;
        }
        suggestions.innerHTML = found.map(function (item) {
            return '<button type="button" role="option" data-event-pick="' + escapeHtml(item.value) + '">' +
                '<strong>' + escapeHtml(item.label) + '</strong>' +
                (item.category ? '<small>' + escapeHtml(item.category) + '</small>' : '') +
                '</button>';
        }).join('');
        suggestions.hidden = false;
        search.setAttribute('aria-expanded', 'true');
    };

    if (select.value) setSelected(select.value, false);

    if (search) {
        search.addEventListener('input', function () {
            const current = byValue(select.value);
            if (!current || normalize(search.value) !== normalize(current.label)) {
                clearSelected();
            }
            search.setCustomValidity('');
            renderSuggestions();
        });
        search.addEventListener('focus', function () {
            if (!suppressFocusSuggestions) renderSuggestions(select.value !== '');
        });
        search.addEventListener('click', function () {
            if (suggestions && suggestions.hidden) {
                renderSuggestions(select.value !== '');
            }
        });
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && suggestions && !suggestions.hidden) {
                suggestions.hidden = true;
                search.setAttribute('aria-expanded', 'false');
                return;
            }
            if (event.key === 'Enter' && suggestions && !suggestions.hidden) {
                const first = suggestions.querySelector('[data-event-pick]');
                if (first) {
                    event.preventDefault();
                    setSelected(first.getAttribute('data-event-pick') || '', false);
                }
            }
        });
    }

    picker.addEventListener('click', function (event) {
        const pick = event.target instanceof Element ? event.target.closest('[data-event-pick]') : null;
        if (pick) {
            setSelected(pick.getAttribute('data-event-pick') || '', false);
            if (search) {
                suppressFocusSuggestions = true;
                search.focus({preventScroll: true});
                suppressFocusSuggestions = false;
            }
            return;
        }

        const favorite = event.target instanceof Element ? event.target.closest('[data-event-favorite-current]') : null;
        if (favorite && select.value) {
            const value = select.value;
            if (favorites.includes(value)) {
                favorites = favorites.filter(function (v) { return v !== value; });
            } else {
                favorites = [value].concat(favorites.filter(function (v) { return v !== value; })).slice(0, 6);
            }
            writeList(favKey, favorites);
            setSelected(value, false);
        }
    });

    document.addEventListener('click', function (event) {
        if (suggestions && !picker.contains(event.target)) {
            suggestions.hidden = true;
            if (search) search.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', function (event) {
        const target = event.target;
        const isTyping = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement;
        if (event.key === '/' && !isTyping && search) {
            event.preventDefault();
            search.focus();
        }
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && form) {
            event.preventDefault();
            if (select.value) {
                setSelected(select.value, true);
                form.requestSubmit();
            }
        }
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            if (!select.value) {
                event.preventDefault();
                if (search) {
                    search.setCustomValidity('Selecione um resultado na lista.');
                    search.reportValidity();
                    search.focus();
                    renderSuggestions();
                }
                return;
            }
            const item = byValue(select.value);
            if (item && item.cpc && motivoSelect && !motivoSelect.value) {
                event.preventDefault();
                motivoSelect.reportValidity();
                motivoSelect.focus();
                return;
            }
            if (item && item.reagenda && reagendaInput && !reagendaInput.value) {
                event.preventDefault();
                reagendaInput.reportValidity();
                reagendaInput.focus();
                return;
            }
            setSelected(select.value, true);
        });
    }

    renderQuick();
})();
