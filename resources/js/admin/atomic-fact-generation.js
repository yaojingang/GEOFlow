function readCopy(root) {
    try {
        return JSON.parse(root.querySelector('[data-atomic-fact-generation-copy]')?.textContent || '{}');
    } catch {
        return {};
    }
}

function replaceCount(template, count) {
    return String(template || '').replace('__COUNT__', Number(count || 0).toLocaleString());
}

export function atomicFactGenerationPresentation(run, copy = {}) {
    const stage = String(run?.stage || run?.status || 'starting');
    const status = String(run?.status || 'starting');
    const successful = ['completed', 'partial'].includes(status);
    const terminal = !Boolean(run?.active);
    const candidateCount = Number(run?.candidate_count || 0);
    const title = copy.title?.[stage] || copy.title?.[status] || copy.title?.starting || '';
    const messageTemplate = copy.message?.[stage] || copy.message?.[status] || copy.message?.starting || '';

    return {
        stage,
        status,
        title,
        message: replaceCount(messageTemplate, candidateCount),
        statusLabel: copy.status?.[stage] || copy.status?.[status] || '',
        successful,
        terminal,
        tone: successful ? 'success' : (terminal ? 'failure' : 'loading'),
        stepIndex: successful ? 2 : (['running', 'finalizing'].includes(stage) ? 1 : 0),
    };
}

function firstError(payload, fallback) {
    const errors = payload?.errors;
    if (errors && typeof errors === 'object') {
        const message = Object.values(errors).flat().find(Boolean);
        if (message) return String(message);
    }

    return String(payload?.message || fallback || '');
}

async function fetchWithTimeout(fetchAction, url, options, timeoutMs) {
    const controller = new AbortController();
    const timeoutId = globalThis.setTimeout(() => controller.abort(), timeoutMs);

    try {
        return await fetchAction(url, { ...options, signal: controller.signal });
    } finally {
        globalThis.clearTimeout(timeoutId);
    }
}

export function initializeAtomicFactGeneration(root, options = {}) {
    if (!root) return null;

    const form = root.querySelector('[data-atomic-fact-generation-form]');
    const dialog = root.querySelector('[data-atomic-fact-generation-dialog]');
    if (!form || !dialog || typeof dialog.showModal !== 'function') return null;

    const fetchAction = options.fetchAction || globalThis.fetch;
    const scheduleAction = options.scheduleAction || globalThis.setTimeout;
    const copy = readCopy(root);
    const submitButton = form.querySelector('[data-atomic-fact-generation-submit]');
    const elements = {
        title: dialog.querySelector('[data-atomic-fact-generation-title]'),
        announcement: dialog.querySelector('[data-atomic-fact-generation-announcement]'),
        iconWrap: dialog.querySelector('[data-atomic-fact-generation-icon-wrap]'),
        statusIcon: dialog.querySelector('[data-atomic-fact-generation-status-icon]'),
        status: dialog.querySelector('[data-atomic-fact-generation-status]'),
        message: dialog.querySelector('[data-atomic-fact-generation-message]'),
        note: dialog.querySelector('[data-atomic-fact-generation-note]'),
        error: dialog.querySelector('[data-atomic-fact-generation-error]'),
        review: dialog.querySelector('[data-atomic-fact-generation-review]'),
        cancel: dialog.querySelector('[data-atomic-fact-generation-cancel]'),
        metrics: Object.fromEntries(Array.from(dialog.querySelectorAll('[data-atomic-fact-generation-metric]')).map((element) => [element.dataset.atomicFactGenerationMetric, element])),
        closeButtons: Array.from(dialog.querySelectorAll('[data-atomic-fact-generation-close]')),
        steps: Array.from(dialog.querySelectorAll('[data-atomic-fact-generation-step]')),
    };
    let lastFocus = null;
    let pollTimer = null;
    let consecutiveFailures = 0;
    let activeRun = null;

    const rotateRequestKey = () => {
        const requestKey = form.querySelector('input[name="request_key"]');
        const nextKey = globalThis.crypto?.randomUUID?.();
        if (requestKey && nextKey) requestKey.value = nextKey;
    };

    const refreshIcons = (target = dialog) => globalThis.window?.GeoFlowAdminUi?.refreshIcons?.(target);

    const setIcon = (container, name, className = 'h-4 w-4') => {
        const icon = root.ownerDocument.createElement('i');
        icon.className = className;
        icon.setAttribute('data-lucide', name);
        container?.replaceChildren(icon);
        refreshIcons(container);
    };

    const setVisible = (element, visible, displayClass = 'inline-flex') => {
        if (!element) return;
        element.classList.toggle('hidden', !visible);
        element.classList.toggle(displayClass, visible);
    };

    const renderSteps = (presentation) => {
        elements.steps.forEach((step, index) => {
            const marker = step.querySelector('[data-atomic-fact-generation-step-marker]');
            const title = step.querySelector('[data-atomic-fact-generation-step-title]');
            const done = index < presentation.stepIndex || (presentation.successful && index === presentation.stepIndex);
            const active = index === presentation.stepIndex && !done;

            marker.className = done
                ? 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white'
                : (active
                    ? 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-700 ring-2 ring-orange-500 ring-offset-2'
                    : 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 ring-1 ring-slate-200');
            title.className = `text-sm font-semibold ${done ? 'text-emerald-800' : (active ? 'text-slate-950' : 'text-slate-700')}`;
            if (done) setIcon(marker, 'check', 'h-4 w-4');
            else marker.textContent = String(index + 1);
        });
    };

    const render = (run) => {
        const presentation = atomicFactGenerationPresentation(run, copy);
        elements.title.textContent = presentation.title;
        elements.status.textContent = presentation.statusLabel;
        elements.message.textContent = presentation.message;
        elements.announcement.textContent = [presentation.title, presentation.message].filter(Boolean).join('。');
        elements.note.textContent = copy.background_note || '';
        elements.note.classList.toggle('hidden', presentation.terminal);
        elements.error.classList.add('hidden');
        elements.error.textContent = '';
        setVisible(elements.review, presentation.successful);
        setVisible(elements.cancel, Boolean(run?.active && run?.cancel_url));
        if (elements.metrics.progress) elements.metrics.progress.textContent = `${Number(run?.progress_percent || 0)}%`;
        if (elements.metrics.candidates) elements.metrics.candidates.textContent = Number(run?.candidate_count || 0).toLocaleString();
        if (elements.metrics.conflicts) elements.metrics.conflicts.textContent = Number(run?.conflict_count || 0).toLocaleString();
        if (elements.metrics.elapsed) elements.metrics.elapsed.textContent = `${Number(run?.elapsed_seconds || 0)} 秒`;

        if (presentation.tone === 'success') {
            elements.iconWrap.className = 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700';
            elements.statusIcon.className = 'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700';
            setIcon(elements.iconWrap, 'circle-check', 'h-5 w-5');
            setIcon(elements.statusIcon, 'check', 'h-4 w-4');
        } else if (presentation.tone === 'failure') {
            elements.iconWrap.className = 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-700';
            elements.statusIcon.className = 'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-700';
            setIcon(elements.iconWrap, 'triangle-alert', 'h-5 w-5');
            setIcon(elements.statusIcon, 'x', 'h-4 w-4');
        } else {
            elements.iconWrap.className = 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-700';
            elements.statusIcon.className = 'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-orange-700 ring-1 ring-slate-200';
            setIcon(elements.iconWrap, 'sparkles', 'h-5 w-5');
            setIcon(elements.statusIcon, 'loader-circle', 'h-4 w-4 animate-spin');
        }

        renderSteps(presentation);
        if (run?.actionable_error) {
            elements.error.textContent = String(run.actionable_error);
            elements.error.classList.remove('hidden');
        }
        if (presentation.terminal && submitButton) submitButton.disabled = false;
        if (presentation.terminal && !presentation.successful && run?.id) rotateRequestKey();

        return presentation;
    };

    const showError = (message) => {
        const run = { status: 'failed', stage: 'failed', active: false };
        render(run);
        elements.error.textContent = String(message || copy.start_failed || '');
        elements.error.classList.remove('hidden');
        elements.announcement.textContent = elements.error.textContent;
    };

    const showConnectionError = (message) => {
        elements.error.textContent = String(message || copy.poll_unavailable || '');
        elements.error.classList.remove('hidden');
        elements.announcement.textContent = elements.error.textContent;
        if (submitButton) submitButton.disabled = true;
    };

    const open = () => {
        lastFocus = root.ownerDocument.activeElement;
        if (!dialog.open) dialog.showModal();
        elements.closeButtons[0]?.focus?.({ preventScroll: true });
    };

    const poll = async () => {
        if (!activeRun?.status_url) return;
        try {
            const response = await fetchWithTimeout(fetchAction, activeRun.status_url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            }, 12000);
            if ([401, 403, 419].includes(response.status)) {
                showConnectionError(copy.poll_unavailable || '');
                return;
            }
            if (!response.ok) throw new Error(`status_${response.status}`);

            const payload = await response.json();
            activeRun = payload?.data?.run || null;
            consecutiveFailures = 0;
            const presentation = render(activeRun);
            if (!presentation.terminal) {
                pollTimer = scheduleAction(poll, Math.max(1200, Math.min(15000, Number(activeRun?.next_poll_ms) || 2000)));
            }
        } catch {
            consecutiveFailures += 1;
            if (consecutiveFailures >= 2) {
                elements.error.textContent = copy.poll_unavailable || '';
                elements.error.classList.remove('hidden');
            }
            pollTimer = scheduleAction(poll, Math.min(30000, 3000 * (2 ** Math.min(3, consecutiveFailures - 1))));
        }
    };

    const start = async () => {
        if (pollTimer) globalThis.clearTimeout(pollTimer);
        activeRun = { status: 'starting', stage: 'starting', active: true };
        consecutiveFailures = 0;
        if (submitButton) submitButton.disabled = true;
        render(activeRun);
        open();

        try {
            const response = await fetchWithTimeout(fetchAction, form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }, 20000);
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                showError(response.status === 422 ? firstError(payload, copy.start_failed) : copy.start_failed);
                return;
            }

            activeRun = payload?.data?.run || null;
            const presentation = render(activeRun);
            if (!presentation.terminal) pollTimer = scheduleAction(poll, 800);
        } catch {
            showError(copy.start_failed || '');
        }
    };

    const cancel = async () => {
        if (!activeRun?.cancel_url) return;
        elements.cancel.disabled = true;
        try {
            const token = form.querySelector('input[name="_token"]')?.value || '';
            const response = await fetchWithTimeout(fetchAction, activeRun.cancel_url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                credentials: 'same-origin',
            }, 12000);
            if (!response.ok) throw new Error(`cancel_${response.status}`);
            activeRun = payloadRun(await response.json());
            render(activeRun);
        } catch {
            elements.error.textContent = copy.cancel_failed || copy.poll_unavailable || '';
            elements.error.classList.remove('hidden');
        } finally {
            elements.cancel.disabled = false;
        }
    };

    const payloadRun = (payload) => payload?.data?.run || null;

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void start();
    });
    elements.closeButtons.forEach((button) => button.addEventListener('click', () => dialog.close()));
    elements.review?.addEventListener('click', () => {
        globalThis.window.location.hash = 'atomic-facts';
        globalThis.window.location.reload();
    });
    elements.cancel?.addEventListener('click', () => void cancel());
    dialog.addEventListener('close', () => lastFocus?.focus?.({ preventScroll: true }));

    try {
        const recovered = JSON.parse(root.dataset.activeGenerationRun || 'null');
        if (recovered?.active && recovered?.status_url) {
            activeRun = recovered;
            render(activeRun);
            open();
            pollTimer = scheduleAction(poll, 400);
        }
    } catch {
        // Invalid recovery data is ignored; the user can start a fresh task.
    }

    return { cancel, poll, render, start };
}
