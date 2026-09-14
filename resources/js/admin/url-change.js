export function confirmationMatches(value, phrase) {
    return typeof phrase === 'string' && phrase !== '' && value.trim() === phrase;
}

async function readReportJson(windowRef, url) {
    const controller = new windowRef.AbortController();
    const timeout = windowRef.setTimeout(() => controller.abort(), 10000);
    try {
        const response = await windowRef.fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (!response.ok) throw new Error('Report response unavailable');
        return await response.json();
    } finally {
        windowRef.clearTimeout(timeout);
    }
}

export function initializeUrlRiskDialog(root, windowRef = window) {
    const dialog = root.querySelector('[data-url-risk-dialog]');
    const opener = root.querySelector('[data-url-risk-open]');
    if (!dialog || !opener || typeof dialog.showModal !== 'function' || typeof windowRef.fetch !== 'function') return null;
    const form = dialog.querySelector('[data-url-confirm-form]');
    const input = dialog.querySelector('[data-url-phrase]');
    const confirm = dialog.querySelector('[data-url-confirm]');
    const close = dialog.querySelector('[data-url-risk-close]');
    const error = dialog.querySelector('[data-url-confirm-error]');
    const notice = dialog.querySelector('[data-url-confirm-status]');
    let busy = false;
    let uncertain = false;
    let pointerOpen = false;
    let expiryTimer;
    const expired = () => Date.now() >= Date.parse(dialog.dataset.expires);
    const update = () => {
        confirm.disabled = busy || expired() || !confirmationMatches(input.value, dialog.dataset.phrase);
        if (expired()) {
            error.textContent = dialog.dataset.expired;
            error.hidden = false;
            const refresh = root.querySelector('[data-url-expiry-refresh]');
            if (refresh) refresh.hidden = false;
        }
    };
    opener.disabled = false;
    root.querySelector('[data-url-no-js]').hidden = true;
    opener.addEventListener('pointerdown', () => { pointerOpen = true; });
    opener.addEventListener('keydown', () => { pointerOpen = false; });
    opener.addEventListener('click', () => {
        input.value = '';
        error.hidden = true;
        notice.hidden = !uncertain;
        update();
        dialog.classList.toggle('is-pointer-open', pointerOpen && !windowRef.matchMedia('(prefers-reduced-motion: reduce)').matches);
        pointerOpen = false;
        dialog.showModal();
        root.ownerDocument.body.classList.add('admin-action-dialog-open');
        dialog.querySelector('#url-risk-title').focus({ preventScroll: true });
        expiryTimer = windowRef.setInterval(update, 1000);
    });
    const dismiss = () => { if (!busy || uncertain) dialog.close(); };
    close.addEventListener('click', dismiss);
    dialog.addEventListener('cancel', (event) => { if (busy && !uncertain) event.preventDefault(); });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dismiss();
    });
    dialog.addEventListener('close', () => {
        windowRef.clearInterval(expiryTimer);
        root.ownerDocument.body.classList.remove('admin-action-dialog-open');
        input.value = '';
        confirm.disabled = true;
        opener.focus({ preventScroll: true });
    });
    input.addEventListener('input', update);
    input.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        update();
        if (confirm.disabled || busy || event.submitter !== confirm) return;
        busy = true;
        input.readOnly = true;
        confirm.disabled = true;
        close.disabled = true;
        notice.textContent = dialog.dataset.applying;
        notice.hidden = false;
        error.hidden = true;
        const abort = typeof windowRef.AbortController === 'function' ? new windowRef.AbortController() : null;
        const timeout = abort ? windowRef.setTimeout(() => abort.abort(), 20000) : null;
        try {
            const response = await windowRef.fetch(form.action, { method: 'POST', body: new windowRef.FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', ...(abort ? { signal: abort.signal } : {}) });
            if (response.ok) {
                windowRef.location.reload();
                return;
            }
            if ([403, 409, 419, 422, 429].includes(response.status)) {
                const payload = await response.json().catch(() => ({}));
                const messages = Object.values(payload.errors ?? {}).flat().filter((value) => typeof value === 'string');
                error.textContent = messages.join(' ') || payload.message || dialog.dataset.failed;
                error.hidden = false;
                notice.hidden = true;
                busy = false;
                close.disabled = false;
                input.readOnly = false;
                input.value = '';
                update();
                return;
            }
            throw new Error('Unconfirmed response');
        } catch {
            uncertain = true;
            notice.textContent = dialog.dataset.uncertain;
            close.disabled = false;
            // Status polling resolves uncertain writes; the same dialog never submits twice.
            root.dispatchEvent(new windowRef.CustomEvent('url-change:uncertain'));
        } finally {
            if (timeout !== null) windowRef.clearTimeout(timeout);
        }
    });
    return { update, dismiss };
}

export function initializeUrlChangeReport(root, windowRef = window) {
    if (!root || typeof windowRef.fetch !== 'function') return;
    initializeUrlRiskDialog(root, windowRef);
    let pollTimer;
    let polling = false;
    let uncertain = false;
    const statusError = root.querySelector('[data-url-poll-error]');
    const jobError = root.querySelector('[data-url-job-error]');
    const retry = root.querySelector('[data-url-poll-retry]');
    const poll = async () => {
        if (polling || root.ownerDocument.hidden) return schedule();
        polling = true;
        try {
            const data = await readReportJson(windowRef, root.dataset.statusUrl);
            if (data.status !== root.dataset.status) return windowRef.location.reload();
            if (jobError) {
                const message = typeof data.error === 'string' ? data.error : '';
                if (jobError.textContent !== message) jobError.textContent = message;
                jobError.hidden = message === '';
            }
            statusError.hidden = true;
            retry.hidden = true;
            root.querySelectorAll('[data-url-metric]').forEach((node) => {
                node.textContent = Number(data.summary?.[node.dataset.urlMetric] ?? 0).toLocaleString();
            });
        } catch {
            statusError.textContent = root.dataset.pollError;
            statusError.hidden = false;
            retry.hidden = false;
        } finally {
            polling = false;
            schedule();
        }
    };
    const schedule = () => {
        windowRef.clearTimeout(pollTimer);
        if (uncertain || ['checking', 'ready', 'applied', 'refreshing'].includes(root.dataset.status)) pollTimer = windowRef.setTimeout(poll, 3000);
    };
    retry.addEventListener('click', () => { void poll(); });
    root.addEventListener('url-change:uncertain', () => { uncertain = true; void poll(); });
    schedule();

    const details = root.querySelector('[data-url-details]');
    if (!details) return;
    const rows = details.querySelector('[data-url-rows]');
    const load = details.querySelector('[data-url-load]');
    const first = details.querySelector('[data-url-first]');
    const filter = details.querySelector('[data-url-site]');
    const loadError = details.querySelector('[data-url-details-error]');
    let cursor = null;
    let loading = false;
    const addText = (parent, tag, text, classes) => {
        const node = root.ownerDocument.createElement(tag);
        node.textContent = text;
        node.className = classes;
        parent.append(node);
        return node;
    };
    const fetchRows = async (reset = false) => {
        if (loading) return;
        loading = true;
        load.disabled = true;
        filter.disabled = true;
        load.textContent = details.dataset.loading;
        loadError.hidden = true;
        if (reset) { cursor = null; rows.replaceChildren(); }
        try {
            const url = new windowRef.URL(details.dataset.url, windowRef.location.href);
            if (cursor) Object.entries(cursor).forEach(([key, value]) => url.searchParams.set(key, value));
            if (filter.value) url.searchParams.set('site', filter.value);
            const data = await readReportJson(windowRef, url);
            if (!Array.isArray(data.rows)) throw new Error('Invalid details');
            rows.replaceChildren();
            if (data.rows.length === 0) addText(rows, 'p', details.dataset.empty, 'text-sm text-gray-600');
            data.rows.forEach((row) => {
                const item = addText(rows, 'article', '', 'border-t border-gray-100 pt-4');
                addText(item, 'h3', row.title, 'break-words text-sm font-medium text-gray-900');
                addText(item, 'p', `${row.label ?? row.site} · ${row.public ? details.dataset.public : details.dataset.potential}`, 'mt-1 text-xs text-gray-600');
                const list = addText(item, 'dl', '', 'mt-2 grid gap-2 text-xs sm:grid-cols-2');
                [[details.dataset.before, row.old_url], [details.dataset.after, row.new_url]].forEach(([label, address]) => {
                    const block = addText(list, 'div', '', 'min-w-0');
                    addText(block, 'dt', label, 'text-gray-500');
                    addText(block, 'dd', address, 'mt-1 break-all font-mono text-gray-800');
                });
            });
            cursor = data.next;
            load.hidden = cursor === null;
            if (first) first.hidden = !url.searchParams.has('segment');
        } catch {
            loadError.textContent = details.dataset.error;
            loadError.hidden = false;
            load.hidden = false;
        } finally {
            loading = false;
            load.disabled = false;
            filter.disabled = false;
            load.textContent = details.dataset.load;
        }
    };
    load.addEventListener('click', () => { void fetchRows(); });
    first?.addEventListener('click', () => { void fetchRows(true); });
    filter.addEventListener('change', () => { void fetchRows(true); });
    void fetchRows();
}

export function initializeArticleUrlCategoryGuard(root, windowRef = window) {
    const select = root.querySelector('#category_id');
    const normalForm = select?.form;
    const panel = root.querySelector('[data-url-category-warning]');
    const check = root.querySelector('[data-url-category-check]');
    const target = root.querySelector('[data-url-category-value]');
    const changeForm = root.querySelector('[data-url-category-form]');
    const restore = root.querySelector('[data-url-category-restore]');
    if (!select || !normalForm || !panel) return;
    const original = root.dataset.originalCategory;
    const protectedIds = JSON.parse(root.dataset.protectedCategories || '[]').map(String);
    const fields = () => [...new windowRef.FormData(normalForm).entries()].filter(([key]) => !['_token', 'category_id'].includes(key));
    let initialFields = fields();
    const snapshot = () => JSON.stringify(fields());
    let edited = root.dataset.hasUnsavedInput === '1';
    const hasEdits = () => edited || snapshot() !== JSON.stringify(initialFields);
    const protectedChange = () => select.value !== original && protectedIds.includes(select.value);
    const update = () => {
        const guarded = protectedChange();
        panel.hidden = !guarded;
        if (target) target.value = select.value;
        if (check) check.disabled = !guarded || hasEdits();
        normalForm.querySelectorAll('[type="submit"]').forEach((button) => {
            if (button.form !== normalForm) return;
            if (guarded) {
                if (!button.disabled) { button.dataset.urlGuardDisabled = '1'; button.disabled = true; }
            } else if (button.dataset.urlGuardDisabled === '1') {
                delete button.dataset.urlGuardDisabled;
                button.disabled = false;
            }
        });
    };
    normalForm.addEventListener('input', (event) => { if (event.target !== select) edited = true; update(); });
    normalForm.addEventListener('change', (event) => { if (event.target !== select) edited = true; update(); });
    windowRef.addEventListener?.('geo-article-editor-ready', () => {
        const content = fields().find(([key]) => key === 'content');
        if (!edited && content) initialFields = initialFields.map((entry) => entry[0] === 'content' ? content : entry);
        update();
    }, { once: true });
    windowRef.addEventListener?.('geo-article-editor-input', () => { edited = true; update(); });
    select.addEventListener('change', update);
    normalForm.addEventListener('submit', (event) => {
        if (!protectedChange()) return;
        event.preventDefault();
        panel.hidden = false;
        panel.focus({ preventScroll: false });
    }, true);
    changeForm?.addEventListener('submit', (event) => {
        if (hasEdits() || !protectedChange()) { event.preventDefault(); update(); }
        else normalForm.dispatchEvent(new root.ownerDocument.defaultView.CustomEvent('gf:saved'));
    });
    restore?.addEventListener('click', () => { select.value = original; update(); select.focus(); });
    update();
}

export function initializeSeparateUrlChecks(root, windowRef = window) {
    const sources = [...root.querySelectorAll('[data-url-source-form]')];
    const checks = [...root.querySelectorAll('[data-url-separate-check]')];
    if (sources.length === 0 || checks.length === 0) return;
    const snapshot = () => JSON.stringify(sources.map((form) => [...new windowRef.FormData(form).entries()].filter(([key]) => key !== '_token')));
    const initial = snapshot();
    const hasOldInput = root.dataset.hasUnsavedInput === '1';
    const update = () => {
        const dirty = hasOldInput || snapshot() !== initial;
        checks.forEach((form) => {
            form.querySelector('[data-url-separate-submit]').disabled = dirty;
            const hint = form.querySelector('[data-url-unsaved-hint]');
            if (hint) hint.hidden = !dirty;
        });
        return dirty;
    };
    sources.forEach((form) => {
        form.addEventListener('input', update);
        form.addEventListener('change', update);
    });
    checks.forEach((form) => form.addEventListener('submit', (event) => {
        if (update()) event.preventDefault();
    }));
    root.querySelectorAll('[data-url-separate-nojs]').forEach((hint) => { hint.hidden = true; });
    update();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-url-change-report]').forEach((root) => initializeUrlChangeReport(root));
    document.querySelectorAll('[data-url-category-guard]').forEach((root) => initializeArticleUrlCategoryGuard(root));
    document.querySelectorAll('[data-url-separate-editor]').forEach((root) => initializeSeparateUrlChecks(root));
}
