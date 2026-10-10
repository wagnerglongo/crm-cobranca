<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_docs_auth.php';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pactua CRM API — Swagger UI</title>
    <link rel="icon" type="image/svg+xml" href="/operacao/assets/icons/logo-icon.svg">
    <style>
        :root{color-scheme:light;--navy:#0b2341;--blue:#1268d6;--blue2:#0b4fa8;--cyan:#15a7c8;--ink:#17243a;--muted:#64748b;--line:#d9e3f0;--bg:#f2f6fb;--card:#fff;--green:#14804a;--red:#c63535;--orange:#b85c00;--shadow:0 16px 42px rgba(12,42,82,.12)}
        *{box-sizing:border-box}body{margin:0;background:linear-gradient(145deg,#e9f2ff 0%,#f7f9fc 42%,#eaf4f8 100%);color:var(--ink);font:15px/1.5  'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;min-height:100vh}
        button,input,textarea{font:inherit}.top{position:sticky;top:0;z-index:10;background:linear-gradient(105deg,var(--navy),#0e4a83 58%,#116b91);color:#fff;box-shadow:0 8px 24px rgba(4,27,56,.22)}
        .top-inner{max-width:1240px;margin:auto;padding:16px 22px;display:flex;align-items:center;gap:16px}.logo{width:42px;height:42px;border-radius:11px;background:#fff;padding:6px;object-fit:contain}.brand{min-width:0}.brand strong{font-size:18px;letter-spacing:.01em}.brand small{display:block;color:#cce6ff}.top-actions{margin-left:auto;display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end}
        .btn{border:1px solid rgba(255,255,255,.4);border-radius:9px;padding:9px 13px;background:rgba(255,255,255,.1);color:inherit;text-decoration:none;cursor:pointer;font-weight:700}.btn:hover{background:rgba(255,255,255,.2)}.btn.primary{background:#fff;color:var(--blue2);border-color:#fff}.btn.danger{background:#fff1f1;color:#a62222;border-color:#ffcaca}
        main{max-width:1240px;margin:0 auto;padding:28px 22px 54px}.hero{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,.7fr);gap:18px;margin-bottom:22px}.hero-card,.status-card{background:rgba(255,255,255,.91);border:1px solid rgba(197,216,238,.9);border-radius:17px;box-shadow:var(--shadow);padding:23px}.eyebrow{color:var(--blue);font-size:12px;font-weight:900;letter-spacing:.11em;text-transform:uppercase}.hero h1{font-size:clamp(26px,4vw,42px);line-height:1.08;margin:8px 0 11px}.hero p{margin:0;color:var(--muted);max-width:760px}.badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:17px}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;background:#edf5ff;color:#164f91;font-size:12px;font-weight:800}.badge.ok{background:#e8f8ef;color:var(--green)}
        .status-card{display:grid;align-content:center;gap:10px}.auth-state{display:flex;align-items:center;gap:9px;font-weight:850}.dot{width:10px;height:10px;border-radius:50%;background:var(--orange);box-shadow:0 0 0 4px #fff0dc}.auth-state.on .dot{background:var(--green);box-shadow:0 0 0 4px #dff6e9}.muted{color:var(--muted)}
        .toolbar{display:flex;align-items:center;gap:12px;margin:20px 0 14px}.toolbar h2{margin:0;font-size:20px}.toolbar input{margin-left:auto;min-width:260px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:#fff;color:var(--ink);outline:none}.toolbar input:focus{border-color:#6ea8e8;box-shadow:0 0 0 3px #dbeeff}
        .tag{margin:18px 0 10px;color:#1b426f;font-size:15px;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.endpoint{background:var(--card);border:1px solid var(--line);border-radius:13px;margin:10px 0;box-shadow:0 8px 25px rgba(27,68,114,.07);overflow:hidden}.endpoint summary{cursor:pointer;list-style:none;display:grid;grid-template-columns:82px minmax(180px,.6fr) 1fr auto;align-items:center;gap:12px;padding:14px 16px}.endpoint summary::-webkit-details-marker{display:none}.method{display:inline-flex;justify-content:center;border-radius:7px;padding:5px 8px;color:#fff;font-size:12px;font-weight:900;letter-spacing:.04em}.GET{background:#1477c9}.POST{background:#14905a}.PUT{background:#b66b00}.DELETE{background:#c53c3c}.path{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-weight:800;color:#17385e;overflow-wrap:anywhere}.summary-text{color:#43546a}.lock{font-size:12px;color:#7b4a00;background:#fff4d9;border-radius:999px;padding:4px 8px}.lock.public{color:#27613d;background:#e7f7ed}.endpoint-body{border-top:1px solid var(--line);padding:18px;background:#fbfdff}.description{margin:0 0 14px;color:var(--muted)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.field label{display:block;font-size:12px;font-weight:850;color:#425a78;margin-bottom:6px}.field textarea{width:100%;min-height:170px;resize:vertical;border:1px solid var(--line);border-radius:10px;padding:11px;background:#0e1b2b;color:#dbeafe;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;outline:none}.try-actions{display:flex;align-items:center;gap:9px;margin-top:10px}.execute{border:0;border-radius:9px;background:linear-gradient(120deg,var(--blue),var(--blue2));color:#fff;font-weight:850;padding:10px 15px;cursor:pointer}.execute:disabled{opacity:.55;cursor:wait}.response{min-height:170px;margin:0;background:#0e1b2b;color:#dbeafe;border-radius:10px;padding:12px;white-space:pre-wrap;overflow:auto;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}.response.error{color:#fecaca}.response.ok{color:#bbf7d0}.response-status{font-size:12px;font-weight:850;color:var(--muted)}
        .params{display:grid;gap:9px;margin-bottom:12px}.param{display:grid!important;grid-template-columns:minmax(110px,.35fr) 1fr;gap:9px;align-items:center}.param span{font-size:12px;font-weight:850;color:#425a78}.param em{font-style:normal;color:var(--red)}.param input,.param select{width:100%;border:1px solid var(--line);border-radius:9px;padding:9px 10px;background:#fff;color:var(--ink)}.explain{border:1px solid #b9d4f1;border-radius:8px;background:#edf6ff;color:#174f87;padding:8px 11px;font-weight:800;cursor:pointer}.field-help{margin:10px 0 14px;border:1px solid var(--line);border-radius:10px;background:#fff;padding:12px}.field-help dl{margin:0;display:grid;gap:9px}.field-help dt{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-weight:900;color:#174f87}.field-help dd{margin:2px 0 0;color:var(--muted)}
        dialog{border:0;border-radius:15px;padding:0;max-width:560px;width:calc(100% - 30px);box-shadow:0 24px 80px rgba(0,20,55,.35)}dialog::backdrop{background:rgba(6,22,42,.66)}.modal-head{padding:18px 20px;background:linear-gradient(110deg,var(--navy),var(--blue2));color:#fff}.modal-head h2{margin:0;font-size:19px}.modal-body{padding:20px}.modal-body label{font-weight:800;display:block;margin-bottom:7px}.modal-body input{width:100%;border:1px solid var(--line);border-radius:10px;padding:11px}.modal-body p{color:var(--muted);font-size:13px}.modal-actions{display:flex;justify-content:flex-end;gap:9px;padding:0 20px 20px}.modal-actions .btn{color:var(--ink);border-color:var(--line);background:#fff}.modal-actions .primary{background:var(--blue);border-color:var(--blue);color:#fff}.empty{padding:30px;text-align:center;color:var(--muted);background:#fff;border:1px dashed var(--line);border-radius:12px}
        @media(max-width:800px){.hero{grid-template-columns:1fr}.endpoint summary{grid-template-columns:70px 1fr auto}.summary-text{grid-column:2/4}.grid{grid-template-columns:1fr}.top-inner{align-items:flex-start}.top-actions{max-width:190px}.toolbar{align-items:stretch;flex-direction:column}.toolbar input{margin-left:0;min-width:0;width:100%}}
        @media(max-width:520px){main{padding:18px 12px 38px}.top-inner{padding:12px}.brand small{display:none}.logo{width:36px;height:36px}.top-actions{gap:6px}.top-actions .btn{padding:7px 9px;font-size:12px}.hero-card,.status-card{padding:17px}.endpoint summary{padding:12px;gap:8px}.lock{display:none}.summary-text{grid-column:1/3}.endpoint-body{padding:13px}}
    </style>
</head>
<body>
<header class="top">
    <div class="top-inner">
        <img class="logo" src="/operacao/assets/icons/logo-icon.svg" alt="Pactua">
        <div class="brand"><strong>Pactua CRM API</strong><small>Swagger UI corporativa · OpenAPI 3.1</small></div>
        <div class="top-actions">
            <a class="btn" href="/api/openapi.json" target="_blank" rel="noopener">OpenAPI JSON</a>
            <button class="btn primary" type="button" id="authorize">Authorize</button>
        </div>
    </div>
</header>
<main>
    <section class="hero">
        <article class="hero-card">
            <span class="eyebrow">API corporativa · Documento e token simplificado</span>
            <h1>API segura, versionada e testável</h1>
            <p>Documentação pública dos endpoints existentes. Use um único Bearer token com expiração para consultar dados e executar operações por documento (CPF/CNPJ) ou contrato. Permissões, escopo e autoria são herdados do usuário autenticado.</p>
            <div class="badges"><span class="badge">/api/v1</span><span class="badge">OpenAPI 3.1</span><span class="badge ok">Token único com expiração</span></div>
        </article>
        <aside class="status-card">
            <div class="auth-state" id="auth-state"><span class="dot"></span><span>Bearer não informado</span></div>
            <div class="muted" id="api-meta">Carregando especificação...</div>
            <button class="btn danger" type="button" id="clear-token" hidden>Remover token desta aba</button>
        </aside>
    </section>

    <section class="toolbar">
        <h2>Endpoints implementados</h2>
        <input type="search" id="filter" placeholder="Filtrar por rota ou descrição" autocomplete="off">
    </section>
    <div id="routes" aria-live="polite"><div class="empty">Carregando OpenAPI...</div></div>
</main>

<dialog id="auth-dialog">
    <div class="modal-head"><h2>Autenticação Bearer</h2></div>
    <div class="modal-body">
        <label for="bearer-token">Access token</label>
        <input id="bearer-token" type="password" autocomplete="off" spellcheck="false" placeholder="wrk_api_at_...">
        <p>Informe somente o access_token. Ele fica restrito à aba atual e é enviado apenas ao executar métodos protegidos. Quando expirar, gere outro em POST /auth/token.</p>
    </div>
    <div class="modal-actions">
        <button class="btn" type="button" id="cancel-auth">Cancelar</button>
        <button class="btn primary" type="button" id="save-auth">Autorizar</button>
    </div>
</dialog>

<script>
(() => {
    'use strict';
    const state = {spec:null, token:sessionStorage.getItem('works_api_bearer') || ''};
    const routes = document.getElementById('routes');
    const filter = document.getElementById('filter');
    const dialog = document.getElementById('auth-dialog');
    const tokenInput = document.getElementById('bearer-token');
    const authState = document.getElementById('auth-state');
    const clearButton = document.getElementById('clear-token');

    function updateAuth(){
        const on = state.token !== '';
        authState.classList.toggle('on', on);
        authState.querySelector('span:last-child').textContent = on ? 'Bearer autorizado nesta aba' : 'Bearer não informado';
        clearButton.hidden = !on;
    }
    function exampleFor(operation){
        const content = operation.requestBody && operation.requestBody.content && operation.requestBody.content['application/json'];
        if (!content) return '';
        if (content.example) return JSON.stringify(content.example, null, 2);
        return '{}';
    }
    function responseExampleFor(operation){
        return operation['x-response-example'] ? JSON.stringify(operation['x-response-example'], null, 2) : 'Execute o método para obter a resposta real.';
    }
    function variableHelp(fields){
        const entries = Object.entries(fields);
        if (!entries.length) return '';
        return `<div class="field-help" hidden><dl>${entries.map(([name, description]) => `<div><dt>${escapeHtml(name)}</dt><dd>${escapeHtml(description)}</dd></div>`).join('')}</dl></div>`;
    }
    function examplePaths(value, prefix = '', result = []){
        if (Array.isArray(value)) {
            const arrayPath = `${prefix}[]`;
            if (value.length === 0) result.push(arrayPath);
            else examplePaths(value[0], arrayPath, result);
            return result;
        }
        if (value && typeof value === 'object') {
            const entries = Object.entries(value);
            if (!entries.length && prefix) result.push(prefix);
            entries.forEach(([key, child]) => examplePaths(child, prefix ? `${prefix}.${key}` : key, result));
            return result;
        }
        if (prefix) result.push(prefix);
        return result;
    }
    function responseVariableHelp(operation){
        const explicit = operation['x-response-variable-descriptions'] || {};
        const common = state.spec['x-response-variable-descriptions'] || {};
        const paths = examplePaths(operation['x-response-example'] || {});
        Object.keys(explicit).forEach(path => { if (!paths.includes(path)) paths.push(path); });
        const fields = {};
        paths.forEach(path => {
            const leaf = path.split('.').pop();
            const plainLeaf = leaf.replace(/\[\]$/, '');
            fields[path] = explicit[path] || common[path] || common[leaf] || common[plainLeaf]
                || 'Campo retornado por este método; consulte o modelo JSON ao lado para formato e posição.';
        });
        return variableHelp(fields);
    }
    function secured(operation){
        return Array.isArray(operation.security) && operation.security.length > 0;
    }
    function resolveRef(item){
        if (!item || !item.$ref) return item || {};
        return item.$ref.replace(/^#\//, '').split('/').reduce((value, key) => value && value[key], state.spec) || {};
    }
    function parameterFields(operation, id){
        return (operation.parameters || []).map(resolveRef).map((param, index) => {
            const schema = param.schema || {};
            const required = param.required === true;
            const value = param.example ?? schema.example ?? schema.default ?? '';
            const attrs = `id="${id}-param-${index}" data-param-name="${escapeHtml(param.name || '')}" data-param-in="${escapeHtml(param.in || 'query')}" ${required ? 'required' : ''}`;
            const control = Array.isArray(schema.enum)
                ? `<select ${attrs}>${schema.enum.map(option => `<option value="${escapeHtml(option)}" ${String(option)===String(value)?'selected':''}>${escapeHtml(option)}</option>`).join('')}</select>`
                : `<input ${attrs} type="${schema.type==='integer'?'number':'text'}" value="${escapeHtml(value)}" placeholder="${escapeHtml(param.description || '')}">`;
            return `<label class="param"><span>${escapeHtml(param.name || '')} <small>(${escapeHtml(param.in || 'query')})</small>${required?'<em> *</em>':''}</span>${control}</label>`;
        }).join('');
    }
    function escapeHtml(value){
        return String(value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    }
    function render(){
        if (!state.spec) return;
        const term = filter.value.trim().toLowerCase();
        const byTag = {};
        Object.entries(state.spec.paths || {}).forEach(([path, item]) => {
            Object.entries(item).forEach(([method, operation]) => {
                const verb = method.toUpperCase();
                if (!['GET','POST','PUT','PATCH','DELETE'].includes(verb)) return;
                const haystack = `${verb} ${path} ${operation.summary || ''} ${operation.description || ''}`.toLowerCase();
                if (term && !haystack.includes(term)) return;
                const tag = (operation.tags && operation.tags[0]) || 'Outros';
                (byTag[tag] ||= []).push({path, verb, operation});
            });
        });
        const chunks = [];
        Object.entries(byTag).forEach(([tag, endpoints]) => {
            chunks.push(`<div class="tag">${escapeHtml(tag)}</div>`);
            endpoints.forEach(({path, verb, operation}) => {
                const needsAuth = secured(operation);
                const body = exampleFor(operation);
                const id = `ep-${operation.operationId || Math.random().toString(16).slice(2)}`;
                const parameters = parameterFields(operation, id);
                const requestHelp = variableHelp(operation['x-variable-descriptions'] || {});
                const responseHelp = responseVariableHelp(operation);
                const responseExample = responseExampleFor(operation);
                chunks.push(`<details class="endpoint" data-search="${escapeHtml((path+' '+(operation.summary||'')).toLowerCase())}">
                    <summary><span class="method ${verb}">${verb}</span><span class="path">${escapeHtml(path)}</span><span class="summary-text">${escapeHtml(operation.summary || '')}</span><span class="lock ${needsAuth?'':'public'}">${needsAuth?'Bearer':'Público'}</span></summary>
                    <div class="endpoint-body">
                        <p class="description">${escapeHtml(operation.description || 'Endpoint implementado e documentado.')}</p>
                        <div class="grid">
                            <div class="field"><label>Parâmetros da requisição</label>${requestHelp ? `<button class="explain" type="button" data-help-label="Explicar entrada">Explicar entrada</button>${requestHelp}` : ''}${parameters ? `<div class="params">${parameters}</div>` : ''}${body ? `<textarea id="${id}-body" spellcheck="false">${escapeHtml(body)}</textarea>` : (!parameters ? `<pre class="response">${verb} ${(state.spec.servers?.[0]?.url || '') + path}</pre>` : '')}<div class="try-actions"><button class="execute" data-execute="${id}" data-method="${verb}" data-path="${escapeHtml(path)}" data-secured="${needsAuth?'1':'0'}">Execute</button><span class="response-status" id="${id}-status"></span></div></div>
                            <div class="field"><label>Modelo de resposta</label>${responseHelp ? `<button class="explain" type="button" data-help-label="Explicar resposta">Explicar resposta</button>${responseHelp}` : ''}<pre class="response" id="${id}-response">${escapeHtml(responseExample)}</pre></div>
                        </div>
                    </div>
                </details>`);
            });
        });
        routes.innerHTML = chunks.length ? chunks.join('') : '<div class="empty">Nenhum endpoint corresponde ao filtro.</div>';
    }
    async function execute(button){
        const id = button.dataset.execute;
        const output = document.getElementById(`${id}-response`);
        const status = document.getElementById(`${id}-status`);
        const securedRoute = button.dataset.secured === '1';
        if (securedRoute && !state.token){
            output.className = 'response error'; output.textContent = 'Use Authorize antes de executar este método.'; dialog.showModal(); return;
        }
        const localRequestId = (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function')
            ? globalThis.crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const headers = {'Accept':'application/json','X-Request-ID':'swagger-'+localRequestId};
        if (securedRoute) headers.Authorization = `Bearer ${state.token}`;
        const options = {method:button.dataset.method, headers};
        let requestPath = button.dataset.path;
        const query = new URLSearchParams();
        const fields = document.querySelectorAll(`[id^="${id}-param-"]`);
        for (const field of fields) {
            const value = field.value.trim();
            if (field.required && value === '') { output.className='response error'; output.textContent=`Preencha o parâmetro obrigatório ${field.dataset.paramName}.`; return; }
            if (value === '') continue;
            if (field.dataset.paramIn === 'path') requestPath = requestPath.replace(`{${field.dataset.paramName}}`, encodeURIComponent(value));
            else if (field.dataset.paramIn === 'query') query.append(field.dataset.paramName, value);
            else if (field.dataset.paramIn === 'header') headers[field.dataset.paramName] = value;
        }
        if (query.toString()) requestPath += `?${query.toString()}`;
        const bodyField = document.getElementById(`${id}-body`);
        if (bodyField){
            try { JSON.parse(bodyField.value); } catch(e){ output.className='response error'; output.textContent='JSON inválido: '+e.message; return; }
            headers['Content-Type'] = 'application/json'; options.body = bodyField.value;
        }
        button.disabled = true; status.textContent = 'Enviando...'; output.className='response';
        try{
            const base = state.spec.servers?.[0]?.url || '/api/v1';
            const response = await fetch(base + requestPath, options);
            const text = await response.text();
            let formatted = text;
            try { formatted = JSON.stringify(JSON.parse(text), null, 2); } catch(e) {}
            output.className = `response ${response.ok?'ok':'error'}`;
            output.textContent = formatted || '(resposta sem conteúdo)';
            status.textContent = `HTTP ${response.status} · request ${response.headers.get('X-Request-ID') || '-'}`;
        }catch(e){ output.className='response error'; output.textContent='Falha de comunicação: '+e.message; status.textContent=''; }
        finally{ button.disabled=false; }
    }

    routes.addEventListener('click', event => {
        const button = event.target.closest('[data-execute]');
        if(button) { execute(button); return; }
        const explain = event.target.closest('.explain');
        if(explain){ const help=explain.nextElementSibling; help.hidden=!help.hidden; explain.textContent=help.hidden?explain.dataset.helpLabel:'Ocultar explicação'; }
    });
    filter.addEventListener('input', render);
    document.getElementById('authorize').addEventListener('click', () => { tokenInput.value=state.token; dialog.showModal(); tokenInput.focus(); });
    document.getElementById('cancel-auth').addEventListener('click', () => dialog.close());
    document.getElementById('save-auth').addEventListener('click', () => { state.token=tokenInput.value.trim(); if(state.token) sessionStorage.setItem('works_api_bearer',state.token); else sessionStorage.removeItem('works_api_bearer'); updateAuth(); dialog.close(); });
    clearButton.addEventListener('click', () => { state.token=''; tokenInput.value=''; sessionStorage.removeItem('works_api_bearer'); updateAuth(); });
    tokenInput.addEventListener('keydown', event => { if(event.key==='Enter') document.getElementById('save-auth').click(); });

    updateAuth();
    fetch('/api/openapi.json', {credentials:'same-origin', cache:'no-store'})
        .then(response => { if(!response.ok) throw new Error(`HTTP ${response.status}`); return response.json(); })
        .then(spec => { state.spec=spec; document.getElementById('api-meta').textContent=`${spec.info.title} · ${spec.info.version}`; render(); })
        .catch(error => { routes.innerHTML=`<div class="empty">Não foi possível carregar a especificação: ${escapeHtml(error.message)}</div>`; document.getElementById('api-meta').textContent='OpenAPI indisponível'; });
})();
</script>
</body>
</html>
