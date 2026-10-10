(function () {
    const main = document.querySelector('[data-works-main]');
    const finance = document.querySelector('[data-financeiro-perfil]');
    const apiUrl = (finance ? finance.getAttribute('data-acme-api-url') : '') || (main ? main.getAttribute('data-acme-api-url') : '') || '';
    const contratoRetiradoCredor = finance && finance.getAttribute('data-retirado-credor') === '1';
    const financeContractId = finance ? (finance.getAttribute('data-contrato-id') || '') : '';
    const financeRefreshWindowSeconds = finance
        ? Math.max(60, Number(finance.getAttribute('data-acme-refresh-window-seconds') || 600))
        : 600;
    const financeForceAgreement = finance && finance.getAttribute('data-acme-force-agreement') === '1';
    const financeCacheKey = financeContractId ? 'works:acme:finance:last-ok:' + financeContractId : '';
    const financeFailureKey = financeContractId ? 'works:acme:finance:last-failure:' + financeContractId : '';
    const statusBox = document.querySelector('[data-acme-api-status]');
    const pageOverlay = document.querySelector('[data-contract-loading]');
    const pageAlert = document.querySelector('[data-acme-api-page-alert]');
    const form = document.querySelector('[data-works-proposal-form]');
    const proposalSelect = document.querySelector('[data-proposal-select]');
    const dateSelect = document.querySelector('[data-date-select]');
    const agreementTypeSelect = document.querySelector('[data-agreement-type-select]');
    const paymentTypeSelect = document.querySelector('[data-payment-type-select]');
    const slipReceiveSelect = document.querySelector('[data-slip-receive-select]');
    const selectedProposal = document.querySelector('[data-selected-proposal]');
    const submitButton = document.querySelector('[data-proposal-submit]');
    const modal = document.getElementById('works-promessa-modal');
    const modalSummary = document.querySelector('[data-promise-summary]');
    const modalChannel = document.querySelector('[data-promise-channel]');
    const modalResponsavel = document.querySelector('[data-promise-responsavel]');
    const modalConfirm = document.querySelector('[data-promise-confirm]');
    const currentAgreementPanel = document.querySelector('[data-current-agreement-panel]');
    const currentAgreementSubtitle = document.querySelector('[data-current-agreement-subtitle]');
    const currentAgreementStatus = document.querySelector('[data-current-agreement-status]');
    const currentAgreementSummary = document.querySelector('[data-current-agreement-summary]');
    const currentAgreementEmpty = document.querySelector('[data-current-agreement-empty]');
    const currentAgreementInstallmentsCard = document.querySelector('[data-current-agreement-installments-card]');
    const currentAgreementInstallments = document.querySelector('[data-current-agreement-installments]');
    const boletoCopyBox = document.querySelector('[data-boleto-copy-box]');
    const boletoLine = document.querySelector('[data-boleto-line]');
    const copyBoletoLine = document.querySelector('[data-copy-boleto-line]');
    const copyProposalOptions = document.querySelector('[data-copy-proposal-options]');
    const copyProposalFeedback = document.querySelector('[data-copy-proposal-feedback]');
    const podeCopiarBoleto = finance && finance.getAttribute('data-pode-copiar-boleto') === '1';
    const podeBaixarBoleto = finance && finance.getAttribute('data-pode-baixar-boleto') === '1';
    const newAgreementCard = document.querySelector('[data-new-agreement-card]');
    let allowSubmit = false;
    let acmePayload = null;

    if (!apiUrl && !finance) return;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[char];
        });
    }

    function money(value) {
        const number = Number(value || 0);
        if (!Number.isFinite(number)) return '-';
        return number.toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    }

    function hideOverlay() {
        if (pageOverlay) pageOverlay.hidden = true;
    }

    function showPageAlert(message) {
        if (!pageAlert) return;
        pageAlert.hidden = false;
        pageAlert.innerHTML = '<strong>Atenção:</strong> ' + escapeHtml(message);
    }

    function setStatus(type, text) {
        if (!statusBox) return;
        statusBox.className = 'works-finance-api-status ' + (type || '');
        statusBox.innerHTML = escapeHtml(text);
    }

    function storageTimestamp(key) {
        if (!key) return 0;
        try {
            const value = Number(window.sessionStorage.getItem(key) || 0);
            return Number.isFinite(value) && value > 0 ? value : 0;
        } catch (error) {
            return 0;
        }
    }

    function rememberTimestamp(key) {
        if (!key) return;
        try { window.sessionStorage.setItem(key, String(Date.now())); } catch (error) {}
    }

    function financeRequestUrl() {
        if (!apiUrl) return '';
        const url = new URL(apiUrl, window.location.origin);
        if (financeForceAgreement) {
            url.searchParams.set('refresh', '1');
            url.searchParams.set('force_agreement', '1');
            return url.toString();
        }

        const now = Date.now();
        const lastOk = storageTimestamp(financeCacheKey);
        const lastFailure = storageTimestamp(financeFailureKey);
        const freshInTab = lastOk > 0 && (now - lastOk) < (financeRefreshWindowSeconds * 1000);
        const failureBackoff = lastFailure > 0 && (now - lastFailure) < 60000;
        url.searchParams.set('refresh', freshInTab || failureBackoff ? '0' : '1');
        return url.toString();
    }

    function setField(name, value) {
        if (!form) return;
        const input = form.querySelector(`[data-field="${name}"]`);
        if (input) input.value = value ?? '';
    }

    function option(text, value, dataset) {
        const opt = document.createElement('option');
        opt.textContent = text || value || 'Não informado';
        opt.value = value ?? '';
        Object.entries(dataset || {}).forEach(([key, val]) => { opt.dataset[key] = val ?? ''; });
        return opt;
    }

    function fillSelect(select, items, emptyText, mapper) {
        if (!select) return;
        select.innerHTML = '';
        if (!items || !items.length) {
            select.appendChild(option(emptyText || 'Não retornado', '', {}));
            select.disabled = true;
            return;
        }
        select.disabled = false;
        items.forEach(function (item) { select.appendChild(mapper(item)); });
    }

    function updateGlobalFinance(payload) {
        if (!payload) return;
        if (payload.valor_divida !== null && payload.valor_divida !== undefined && payload.valor_divida !== '') {
            document.querySelectorAll('[data-acme-debt]').forEach(function (el) {
                el.textContent = money(payload.valor_divida);
            });
        }
        if (payload.atraso_atual_label) {
            document.querySelectorAll('[data-acme-delay]').forEach(function (el) {
                el.textContent = payload.atraso_atual_label;
            });
        }
        if (payload.atraso_nota) {
            document.querySelectorAll('[data-acme-delay-note]').forEach(function (el) {
                el.textContent = payload.atraso_nota;
            });
        }
        if (payload.status_divida) {
            document.querySelectorAll('[data-acme-debt-status]').forEach(function (el) {
                el.textContent = payload.status_divida;
            });
        }
        const sync = payload.sync || {};
        const acordo = payload.acordo_atual || {};
        const statusAcordo = sync.status_operacional || acordo.status_operacional || '';
        const acordoId = sync.acordo_crm_id || acordo.id || '';
        const labels = {
            pendente: 'Pendente',
            formalizado: 'Ativo',
            cumprido: 'Quitado',
            quebrado: 'Quebrado',
            cancelado: 'Cancelado',
        };
        if (statusAcordo) {
            const textoAcordo = (labels[statusAcordo] || statusAcordo) + (acordoId ? ' #' + acordoId : '');
            document.querySelectorAll('[data-acme-agreement-status]').forEach(function (el) {
                el.textContent = textoAcordo;
            });
        }
    }


    function agreementFromPayload(payload) {
        if (!payload) return null;
        if (payload.acordo_atual && payload.acordo_atual.id) return payload.acordo_atual;
        return payload.acordo_atual || null;
    }

    function renderCurrentAgreement(acordo) {
        if (!currentAgreementPanel) return;

        currentAgreementPanel.hidden = false;

        if (!acordo || (!acordo.id && !acordo.divida_liquidada)) {
            if (newAgreementCard) newAgreementCard.hidden = false;
            if (currentAgreementSubtitle) currentAgreementSubtitle.textContent = 'Nenhum acordo vigente retornado pela ACME para este contrato.';
            if (currentAgreementStatus) currentAgreementStatus.textContent = 'Sem acordo atual';
            if (currentAgreementEmpty) {
                currentAgreementEmpty.hidden = false;
                currentAgreementEmpty.textContent = 'Contrato sem acordo atual identificado na ACME. A montagem de nova promessa permanece liberada conforme regras do contrato.';
            }
            if (currentAgreementSummary) {
                currentAgreementSummary.hidden = true;
                currentAgreementSummary.innerHTML = '';
            }
            if (currentAgreementInstallmentsCard) currentAgreementInstallmentsCard.hidden = true;
            if (currentAgreementInstallments) currentAgreementInstallments.innerHTML = '';
            return;
        }

        if (newAgreementCard) newAgreementCard.hidden = Boolean(acordo.impede_nova_proposta);
        if (currentAgreementEmpty) currentAgreementEmpty.hidden = true;
        if (currentAgreementSummary) currentAgreementSummary.hidden = false;
        if (currentAgreementInstallmentsCard) currentAgreementInstallmentsCard.hidden = false;

        if (currentAgreementSubtitle) {
            const identificador = acordo.id ? 'Acordo ' + acordo.id : 'Histórico de acordo ACME';
            currentAgreementSubtitle.textContent = identificador + ' · ' + (acordo.status_label || 'situação não informada') + ' · ' + (acordo.total_parcelas || 1) + ' parcela(s) · ' + (acordo.payment_type_tag || 'forma não informada');
        }
        if (currentAgreementStatus) currentAgreementStatus.textContent = acordo.status_label || acordo.status || 'Acordo vigente';
        if (currentAgreementSummary) {
            const primeiraParcela = acordo.parcelas && acordo.parcelas[0] ? acordo.parcelas[0] : null;
            currentAgreementSummary.innerHTML = `
                <div><small>Data feita</small><strong>${escapeHtml(acordo.data_acordo_label || '-')}</strong></div>
                <div><small>Valor do acordo</small><strong>${escapeHtml(money(acordo.valor_acordo))}</strong></div>
                <div><small>1º vencimento</small><strong>${escapeHtml((primeiraParcela ? primeiraParcela.vencimento_label : '-') || '-')}</strong></div>
                <div><small>Total de parcelas</small><strong>${escapeHtml(acordo.total_instalments_raw || ('0/' + (acordo.total_parcelas || 1)))}</strong></div>
                <div><small>Situação conciliada</small><strong>${escapeHtml(acordo.status_label || '-')}</strong></div>
                <div><small>Status acordo ACME</small><strong>${escapeHtml(acordo.status || '-')}</strong></div>
                <div><small>Dívida ACME</small><strong>${escapeHtml(money(acordo.debt ? acordo.debt.amount : null))}</strong></div>
                <div><small>Status dívida</small><strong>${escapeHtml(acordo.debt ? (acordo.debt.status || '-') : '-')}</strong></div>
                <div><small>Prazo de quebra ACME</small><strong>${escapeHtml(primeiraParcela ? (primeiraParcela.deadline_label || '-') : '-')}</strong></div>
                <div><small>Motivo da situação</small><strong>${escapeHtml(acordo.status_motivo || '-')}</strong></div>
                <div><small>Negociação</small><strong>${escapeHtml(acordo.negotiation_notes || acordo.debt?.negotiation_type || '-')}</strong></div>`;
        }
        if (currentAgreementInstallments) {
            currentAgreementInstallments.innerHTML = '';
            (acordo.parcelas || []).forEach(function (parcela) {
                const tr = document.createElement('tr');
                let boletoHtml = '<span class="works-boleto-unavailable">' + escapeHtml(parcela.boleto_motivo || 'Boleto ainda não liberado pela ACME') + '</span>';
                const linhaAtual = parcela.boleto_linha_digitavel || '';
                if (parcela.boleto_disponivel && parcela.boleto_download_url && parcela.boleto_json_url) {
                    const copiarBotao = podeCopiarBoleto ? `<button type="button" class="works-secondary-btn tiny" data-boleto-json="${escapeHtml(parcela.boleto_json_url)}" data-boleto-row="1">Copiar linha</button>` : `<small class="muted block">Sem permissão para copiar linha.</small>`;
                    const baixarBotao = podeBaixarBoleto ? `<a class="adaptor-green-btn tiny" href="${escapeHtml(parcela.boleto_download_url)}" target="_blank" rel="noopener">Baixar boleto</a>` : `<small class="muted block">Sem permissão para baixar boleto.</small>`;
                    boletoHtml = `<div class="works-boleto-actions">
                        ${linhaAtual ? `<div class="works-line-inline"><small>Linha digitável</small><code>${escapeHtml(linhaAtual)}</code></div>` : `<small class="muted block">Linha digitável ainda não carregada neste CRM.</small>`}
                        ${copiarBotao}
                        ${baixarBotao}
                    </div>`;
                } else if (String(parcela.status_label || parcela.status || '').toLowerCase().includes('pago')) {
                    boletoHtml = '<span class="works-paid-badge">Pago</span>';
                }
                tr.innerHTML = `
                    <td>${escapeHtml(parcela.descricao || parcela.numero || '-')}</td>
                    <td>${escapeHtml(parcela.valor !== null && parcela.valor !== undefined ? money(parcela.valor) : '-')}</td>
                    <td>${escapeHtml(parcela.vencimento_label || '-')}</td>
                    <td>${escapeHtml(parcela.pagamento_label || '-')}</td>
                    <td>${escapeHtml(parcela.valor_pago !== null && parcela.valor_pago !== undefined ? money(parcela.valor_pago) : '-')}</td>
                    <td>${escapeHtml(parcela.deadline_label || '-')}</td>
                    <td><span class="works-status-pill ${escapeHtml(parcela.status_classe || '')}" title="${escapeHtml(parcela.status_motivo || '')}">${escapeHtml(parcela.status_label || parcela.status || '-')}</span></td>
                    <td>${boletoHtml}${parcela.observacao ? '<small class="muted block">' + escapeHtml(parcela.observacao) + '</small>' : ''}</td>`;
                currentAgreementInstallments.appendChild(tr);
            });
        }
    }

    async function carregarLinhaDigitavel(url, event) {
        if (!url) return;
        try {
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok || !payload.ok) throw new Error(payload.erro || 'Falha ao consultar boleto.');
            const linha = payload.linha_digitavel || '';
            if (boletoLine) boletoLine.value = linha;
            if (boletoCopyBox) boletoCopyBox.hidden = false;
            if (linha && navigator.clipboard) {
                try { await navigator.clipboard.writeText(linha); } catch (e) {}
            }
            if (linha && event && event.target) {
                const cell = event.target.closest('td');
                let inline = cell ? cell.querySelector('.works-line-inline code') : null;
                if (!inline && cell) {
                    const actions = cell.querySelector('.works-boleto-actions');
                    if (actions) {
                        const box = document.createElement('div');
                        box.className = 'works-line-inline';
                        box.innerHTML = '<small>Linha digitável</small><code></code>';
                        actions.insertBefore(box, actions.firstChild);
                        inline = box.querySelector('code');
                    }
                }
                if (inline) inline.textContent = linha;
            }
            showPageAlert(linha ? 'Linha digitável carregada, salva no CRM e copiada quando permitido pelo navegador.' : 'Boleto retornou sem linha digitável.');
        } catch (error) {
            showPageAlert('Não foi possível buscar a linha digitável agora. Detalhe: ' + ((error && error.message) ? error.message : String(error)));
        }
    }

    function syncSelected() {
        if (!form) return;
        const proposal = proposalSelect && proposalSelect.selectedOptions[0] ? proposalSelect.selectedOptions[0] : null;
        const date = dateSelect && dateSelect.selectedOptions[0] ? dateSelect.selectedOptions[0] : null;
        const agreement = agreementTypeSelect && agreementTypeSelect.selectedOptions[0] ? agreementTypeSelect.selectedOptions[0] : null;
        const payment = paymentTypeSelect && paymentTypeSelect.selectedOptions[0] ? paymentTypeSelect.selectedOptions[0] : null;
        const slip = slipReceiveSelect && slipReceiveSelect.selectedOptions[0] ? slipReceiveSelect.selectedOptions[0] : null;

        setField('proposta_id', proposal ? proposal.value : '');
        setField('proposta_descricao', proposal ? proposal.textContent.trim() : '');
        setField('valor_acordo', proposal ? proposal.dataset.valor : '');
        setField('quantidade_parcelas', proposal ? proposal.dataset.parcelas || '1' : '1');
        setField('primeiro_vencimento_iso', date ? date.dataset.iso : '');
        setField('data_primeira_parcela', date ? date.dataset.data : '');
        setField('tipo_acordo_id', agreement ? agreement.value : '');
        setField('tipo_acordo_descricao', agreement ? agreement.textContent.trim() : '');
        setField('tipo_pagamento_tag', payment ? payment.value : '');
        setField('tipo_pagamento_descricao', payment ? payment.textContent.trim() : '');
        setField('forma_recebimento_tag', slip ? slip.value : '');
        setField('forma_recebimento_descricao', slip ? slip.textContent.trim() : '');

        if (selectedProposal && proposal) {
            selectedProposal.innerHTML = `
                <strong>${escapeHtml(proposal.textContent.trim())}</strong>
                <span>Valor estimado: ${escapeHtml(money(proposal.dataset.valor))} · Parcelas: ${escapeHtml(proposal.dataset.parcelas || '1')} · 1º vencimento: ${escapeHtml(date ? date.textContent.trim() : '-')}</span>`;
        }
    }

    async function loadAcme() {
        if (!apiUrl) {
            hideOverlay();
            [proposalSelect, dateSelect, agreementTypeSelect, paymentTypeSelect, slipReceiveSelect].forEach(function (select) {
                if (!select) return;
                select.innerHTML = '<option value="">' + (contratoRetiradoCredor ? 'Contrato retirado pelo credor' : 'Perfil sem API configurada') + '</option>';
                select.disabled = true;
            });
            if (submitButton) submitButton.disabled = true;
            if (contratoRetiradoCredor) {
                setStatus('warning', 'Contrato retirado pelo credor. Nova negociação bloqueada.');
            }
            return;
        }
        try {
            const requestUrl = financeRequestUrl();
            const response = await fetch(requestUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok || !payload.ok) throw new Error(payload.erro || 'Falha ao consultar financeiro do credor.');
            rememberTimestamp(financeCacheKey);
            acmePayload = payload;
            updateGlobalFinance(payload);
            renderCurrentAgreement(agreementFromPayload(payload));
            if ((!payload.acordo_atual || !payload.acordo_atual.id) && payload.acordo_diagnostico && payload.acordo_diagnostico.tem_payload && currentAgreementEmpty) {
                const diag = payload.acordo_diagnostico;
                const msg = diag.tem_agreement
                    ? 'A ACME retornou Agreement, mas sem AgreementID válido. Verifique o retorno/log da integração.'
                    : 'Consulta de acordo executada, mas a ACME não retornou Agreement vigente para este contrato.';
                currentAgreementEmpty.textContent = msg;
            }
            hideOverlay();

            if (payload.api_erro) {
                showPageAlert('Não foi possível atualizar pela API ACME agora. Dados salvos no CRM foram mantidos. Detalhe: ' + payload.api_erro);
            } else if (payload.sync && (payload.sync.acordo_acme_erro || payload.sync.acordo_crm_erro)) {
                showPageAlert('Financeiro carregado, mas a Central de Acordos não foi atualizada. Detalhe: ' + (payload.sync.acordo_crm_erro || payload.sync.acordo_acme_erro));
            }

            const hasProposalData = Array.isArray(payload.propostas) && payload.propostas.length > 0;
            if (!payload.api_disponivel && !hasProposalData) {
                setStatus('warning', payload.mensagem || 'Dados do credor ainda não disponíveis.');
                [proposalSelect, dateSelect, agreementTypeSelect, paymentTypeSelect, slipReceiveSelect].forEach(function (select) {
                    if (!select) return;
                    select.innerHTML = '<option value="">Dados não disponíveis</option>';
                    select.disabled = true;
                });
                if (submitButton) submitButton.disabled = true;
                return;
            }

            if (payload.fonte === 'api') {
                setStatus('success', payload.mensagem || 'Dados ACME atualizados e salvos no CRM.');
            } else {
                setStatus('success', payload.mensagem || 'Dados ACME reutilizados do CRM sem nova chamada externa.');
            }

            fillSelect(proposalSelect, payload.propostas, 'Nenhuma proposta retornada', function (item) {
                return option(item.descricao, item.id, {valor: item.valor_estimado || payload.valor_divida || '', parcelas: item.parcelas || 1});
            });
            fillSelect(dateSelect, payload.datas, 'Nenhuma data retornada', function (item) {
                return option(item.label, item.data, {iso: item.iso, data: item.data});
            });
            fillSelect(agreementTypeSelect, payload.tipos_acordo, 'Nenhum tipo retornado', function (item) {
                return option(item.descricao, item.id, {});
            });
            fillSelect(paymentTypeSelect, payload.tipos_pagamento, 'Nenhum pagamento retornado', function (item) {
                return option(item.descricao, item.tag, {});
            });
            fillSelect(slipReceiveSelect, payload.formas_boleto, 'Nenhum recebimento retornado', function (item) {
                return option(item.descricao, item.tag, {paymentTypeTag: item.payment_type_tag || ''});
            });
            if (submitButton) submitButton.disabled = false;
            syncSelected();
        } catch (error) {
            rememberTimestamp(financeFailureKey);
            hideOverlay();
            const message = (error && error.message ? error.message : String(error)) || 'Falha de comunicação com a API ACME.';
            setStatus('danger', message);
            showPageAlert('Não foi possível atualizar pela API ACME agora. Os dados da base foram mantidos. Detalhe: ' + message);
            if (submitButton) submitButton.disabled = true;
        }
    }

    [proposalSelect, dateSelect, agreementTypeSelect, paymentTypeSelect, slipReceiveSelect].forEach(function (select) {
        if (select) select.addEventListener('change', syncSelected);
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            if (allowSubmit) return;
            event.preventDefault();
            syncSelected();
            const proposal = form.querySelector('[data-field="proposta_descricao"]')?.value || '';
            if (!proposal) {
                setStatus('danger', 'Selecione uma proposta antes de concluir.');
                return;
            }
            const email = form.querySelector('[name="email_promessa"]')?.value || '-';
            const telefone = form.querySelector('[name="telefone_promessa"]')?.value || '-';
            const vencimento = form.querySelector('[data-field="data_primeira_parcela"]')?.value || '-';
            const valor = form.querySelector('[data-field="valor_acordo"]')?.value || '-';
            if (modalSummary) {
                modalSummary.innerHTML = `
                    <dl>
                        <dt>Proposta</dt><dd>${escapeHtml(proposal)}</dd>
                        <dt>Valor estimado</dt><dd>${escapeHtml(valor)}</dd>
                        <dt>1º vencimento</dt><dd>${escapeHtml(vencimento)}</dd>
                        <dt>Email</dt><dd>${escapeHtml(email)}</dd>
                        <dt>Telefone</dt><dd>${escapeHtml(telefone)}</dd>
                        <dt>Responsável</dt><dd>${escapeHtml(modalResponsavel && modalResponsavel.selectedOptions[0] ? modalResponsavel.selectedOptions[0].textContent.trim() : 'Usuário logado')}</dd>
                    </dl>`;
            }
            if (modal) {
                modal.hidden = false;
                document.body.classList.add('works-modal-open');
            }
        });
    }

    if (modalConfirm) {
        modalConfirm.addEventListener('click', function () {
            setField('canal_acionamento', modalChannel ? modalChannel.value : 'ligacao');
            if (modalResponsavel && modalResponsavel.selectedOptions[0]) {
                setField('operador_responsavel_id', modalResponsavel.value || '');
                setField('operador_responsavel_nome', modalResponsavel.selectedOptions[0].dataset.nome || modalResponsavel.selectedOptions[0].textContent.trim());
            }
            allowSubmit = true;
            form.submit();
        });
    }

    if (currentAgreementPanel) {
        currentAgreementPanel.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target.closest('[data-boleto-json]') : null;
            if (!target) return;
            event.preventDefault();
            carregarLinhaDigitavel(target.getAttribute('data-boleto-json') || '', event);
        });
    }

    if (copyBoletoLine) {
        copyBoletoLine.addEventListener('click', async function () {
            const linha = boletoLine ? boletoLine.value : '';
            if (!linha) return;
            if (navigator.clipboard) {
                try { await navigator.clipboard.writeText(linha); } catch (e) {}
            }
        });
    }

    function textoOpcoesParaCliente() {
        const linhas = [];
        if (proposalSelect && !proposalSelect.disabled) {
            const propostas = Array.from(proposalSelect.options).filter(function (opt) { return opt.value !== ''; });
            if (propostas.length) {
                linhas.push('Opções de proposta:');
                propostas.forEach(function (opt, idx) {
                    const valor = opt.dataset.valor ? ' - ' + money(opt.dataset.valor) : '';
                    const parcelasNum = Number(opt.dataset.parcelas || 1);
                    const parcelas = parcelasNum > 1 ? ' em ' + parcelasNum + 'x' : '';
                    linhas.push((idx + 1) + ') ' + opt.textContent + valor + parcelas);
                });
            }
        }
        if (dateSelect && !dateSelect.disabled) {
            const datas = Array.from(dateSelect.options).filter(function (opt) { return opt.value !== ''; });
            if (datas.length) {
                if (linhas.length) linhas.push('');
                linhas.push('Opções de 1º vencimento:');
                datas.forEach(function (opt, idx) {
                    linhas.push((idx + 1) + ') ' + opt.textContent);
                });
            }
        }
        if (!linhas.length) return '';
        linhas.unshift('Seguem as opções disponíveis para o seu acordo. Me diga qual você prefere:');
        return linhas.join('\n');
    }

    function mostrarFeedbackCopia(texto) {
        if (!copyProposalFeedback) return;
        copyProposalFeedback.textContent = texto;
        copyProposalFeedback.hidden = false;
        setTimeout(function () { copyProposalFeedback.hidden = true; }, 2500);
    }

    if (copyProposalOptions) {
        copyProposalOptions.addEventListener('click', async function () {
            const texto = textoOpcoesParaCliente();
            if (!texto) {
                mostrarFeedbackCopia('Ainda não há opções carregadas.');
                return;
            }
            if (navigator.clipboard) {
                try {
                    await navigator.clipboard.writeText(texto);
                    mostrarFeedbackCopia('Copiado!');
                    return;
                } catch (error) {}
            }
            window.prompt('Copie o texto abaixo e envie ao cliente:', texto);
        });
    }

    loadAcme();
})();
