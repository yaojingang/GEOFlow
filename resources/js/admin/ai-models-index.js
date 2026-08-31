function readCopy(root) {
    const source = root.querySelector('[data-ai-model-test-copy]');

    try {
        return JSON.parse(source?.textContent || '{}');
    } catch {
        return {};
    }
}

function normalizeDiagnosis(diagnosis, fallback = {}) {
    return {
        code: String(diagnosis?.code || fallback?.code || 'unexpected_error'),
        title: String(diagnosis?.title || fallback?.title || ''),
        reason: String(diagnosis?.reason || fallback?.reason || ''),
        steps: Array.isArray(diagnosis?.steps)
            ? diagnosis.steps.map((step) => String(step)).filter(Boolean)
            : (Array.isArray(fallback?.steps) ? fallback.steps.map((step) => String(step)).filter(Boolean) : []),
        severity: String(diagnosis?.severity || fallback?.severity || 'error'),
    };
}

function setVisible(element, visible, flex = false) {
    if (!element) return;

    element.hidden = !visible;
    element.classList.toggle('hidden', !visible);
    if (flex) element.classList.toggle('inline-flex', visible);
}

function setButtonReady(button, label) {
    button.disabled = false;
    button.removeAttribute('aria-disabled');
    button.textContent = label;
}

function setButtonLoading(button, label) {
    button.disabled = true;
    button.setAttribute('aria-disabled', 'true');
    button.textContent = label;
}

export function initializeAiModelsIndex(root, options = {}) {
    if (!root) return null;

    const ownerDocument = root.ownerDocument || document;
    const windowRef = options.windowRef || globalThis.window;
    const fetchImpl = options.fetchImpl || globalThis.fetch;
    const now = options.now || (() => Date.now());
    const setIntervalImpl = options.setIntervalImpl || globalThis.setInterval;
    const clearIntervalImpl = options.clearIntervalImpl || globalThis.clearInterval;
    const setTimeoutImpl = options.setTimeoutImpl || globalThis.setTimeout;
    const clearTimeoutImpl = options.clearTimeoutImpl || globalThis.clearTimeout;
    const AbortControllerClass = options.AbortControllerClass || globalThis.AbortController;
    const copy = readCopy(root);
    const labels = copy.labels || {};
    const clientDiagnoses = copy.clientDiagnoses || {};
    const timeoutMs = Math.max(1000, Number(root.dataset.clientTimeoutMs || 100000));
    const csrfToken = ownerDocument.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const dialog = root.querySelector('[data-ai-model-test-dialog]');
    const buttons = Array.from(root.querySelectorAll('[data-ai-model-test-button]'));

    if (!dialog || typeof fetchImpl !== 'function' || typeof AbortControllerClass !== 'function') {
        throw new Error('AI model test markup or browser support is incomplete.');
    }

    const elements = {
        title: dialog.querySelector('[data-ai-model-test-title]'),
        announcement: dialog.querySelector('[data-ai-model-test-announcement]'),
        iconWrap: dialog.querySelector('[data-ai-model-test-icon-wrap]'),
        modelName: dialog.querySelector('[data-ai-model-test-model-name]'),
        modelId: dialog.querySelector('[data-ai-model-test-model-id]'),
        loading: dialog.querySelector('[data-ai-model-test-loading]'),
        success: dialog.querySelector('[data-ai-model-test-success]'),
        failure: dialog.querySelector('[data-ai-model-test-failure]'),
        waitingCopy: dialog.querySelector('[data-ai-model-test-waiting-copy]'),
        elapsed: dialog.querySelector('[data-ai-model-test-elapsed]'),
        successMessage: dialog.querySelector('[data-ai-model-test-success-message]'),
        httpStatus: dialog.querySelector('[data-ai-model-test-http-status]'),
        duration: dialog.querySelector('[data-ai-model-test-duration]'),
        modelType: dialog.querySelector('[data-ai-model-test-model-type]'),
        workspace: dialog.querySelector('[data-ai-model-test-workspace]'),
        diagnosisTitle: dialog.querySelector('[data-ai-model-test-diagnosis-title]'),
        diagnosisReason: dialog.querySelector('[data-ai-model-test-diagnosis-reason]'),
        steps: dialog.querySelector('[data-ai-model-test-steps]'),
        log: dialog.querySelector('[data-ai-model-test-log]'),
        edit: dialog.querySelector('[data-ai-model-test-edit]'),
        retry: dialog.querySelector('[data-ai-model-test-retry]'),
        closeButtons: Array.from(dialog.querySelectorAll('[data-ai-model-test-close]')),
    };
    const requiredElements = Object.entries(elements)
        .filter(([key]) => key !== 'closeButtons')
        .map(([, element]) => element);
    if (requiredElements.some((element) => !element) || elements.closeButtons.length === 0) {
        throw new Error('AI model test dialog markup is incomplete.');
    }
    const states = new Map();
    let activeModelId = '';
    let lastFocus = null;

    const refreshIcons = (target = dialog) => {
        if (windowRef?.GeoFlowAdminUi?.refreshIcons) {
            windowRef.GeoFlowAdminUi.refreshIcons(target);
        }
    };

    const setIcon = (name, tone) => {
        if (elements.iconWrap) {
            elements.iconWrap.className = {
                success: 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700',
                failure: 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-700',
                loading: 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700',
            }[tone] || '';
            const icon = ownerDocument.createElement('i');
            icon.className = 'h-5 w-5';
            icon.setAttribute('data-lucide', name);
            elements.iconWrap.replaceChildren(icon);
        }
        refreshIcons(elements.iconWrap);
    };

    const announce = (...parts) => {
        const next = parts
            .map((part) => String(part || '').trim())
            .filter(Boolean)
            .map((part) => /[.!?。！？]$/u.test(part)
                ? part
                : `${part}${/[\u3400-\u9fff]/u.test(part) ? '。' : '.'}`)
            .join(' ');
        if (elements.announcement.textContent !== next) elements.announcement.textContent = next;
    };

    const renderIdentity = (context) => {
        elements.modelName.textContent = context.modelName;
        elements.modelId.textContent = context.providerModelId;
        if (elements.edit) elements.edit.href = context.editUrl || '#';
    };

    const waitingLabel = (elapsedSeconds) => {
        if (elapsedSeconds >= 20) return labels.waitingExtended || '';
        if (elapsedSeconds >= 8) return labels.waitingChecking || '';

        return labels.waitingInitial || '';
    };

    const renderLoading = (state) => {
        elements.title.textContent = labels.testingTitle || '';
        setIcon('activity', 'loading');
        setVisible(elements.loading, true);
        setVisible(elements.success, false);
        setVisible(elements.failure, false);
        setVisible(elements.edit, false, true);
        setVisible(elements.retry, false, true);
        const elapsedSeconds = Math.max(0, Math.floor((now() - state.startedAt) / 1000));
        elements.waitingCopy.textContent = waitingLabel(elapsedSeconds);
        elements.elapsed.textContent = String(labels.waitingSeconds || '__SECONDS__').replace('__SECONDS__', String(elapsedSeconds));
        announce(labels.testingTitle, waitingLabel(elapsedSeconds));
    };

    const renderSuccess = (state) => {
        const meta = state.result.meta || {};
        elements.title.textContent = labels.successTitle || '';
        setIcon('circle-check', 'success');
        setVisible(elements.loading, false);
        setVisible(elements.success, true);
        setVisible(elements.failure, false);
        setVisible(elements.edit, true, true);
        setVisible(elements.retry, true, true);
        elements.successMessage.textContent = String(state.result.message || '');
        elements.httpStatus.textContent = meta.http_status == null ? (labels.unknown || '-') : String(meta.http_status);
        elements.duration.textContent = String(labels.milliseconds || '__DURATION__').replace('__DURATION__', String(meta.duration_ms ?? 0));
        elements.modelType.textContent = meta.model_type === 'embedding' ? (labels.embedding || 'Embedding') : (labels.chat || 'Chat');
        elements.workspace.textContent = meta.workspace_ready === true ? (labels.workspaceReady || '') : (labels.workspaceBasic || '');
        announce(labels.successTitle, state.result.message);
    };

    const renderSteps = (steps) => {
        elements.steps.replaceChildren();
        steps.forEach((step, index) => {
            const item = ownerDocument.createElement('li');
            item.className = 'flex gap-3 rounded-lg bg-gray-50 px-3 py-2.5';
            const number = ownerDocument.createElement('span');
            number.className = 'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-semibold text-gray-700 ring-1 ring-gray-200';
            number.textContent = String(index + 1);
            const text = ownerDocument.createElement('span');
            text.className = 'min-w-0 text-pretty';
            text.textContent = String(step);
            item.append(number, text);
            elements.steps.append(item);
        });
    };

    const renderFailure = (state) => {
        const diagnosis = normalizeDiagnosis(state.result?.meta?.diagnosis, clientDiagnoses.unexpected_error);
        elements.title.textContent = labels.failureTitle || '';
        setIcon('triangle-alert', 'failure');
        setVisible(elements.loading, false);
        setVisible(elements.success, false);
        setVisible(elements.failure, true);
        setVisible(elements.edit, true, true);
        setVisible(elements.retry, true, true);
        elements.diagnosisTitle.textContent = diagnosis.title;
        elements.diagnosisReason.textContent = diagnosis.reason;
        elements.log.textContent = String(state.result?.message || diagnosis.reason || '');
        renderSteps(diagnosis.steps);
        announce(labels.failureTitle, diagnosis.title, diagnosis.reason);
    };

    const renderState = (state) => {
        renderIdentity(state.context);
        if (state.phase === 'loading') {
            renderLoading(state);
        } else if (state.phase === 'success') {
            renderSuccess(state);
        } else {
            renderFailure(state);
        }
    };

    const openState = (state, trigger = null) => {
        activeModelId = state.context.modelId;
        lastFocus = trigger && !trigger.disabled
            ? trigger
            : (state.context.focusFallback || ownerDocument.activeElement || null);
        renderState(state);
        if (!dialog.open) dialog.showModal();
        elements.closeButtons[0]?.focus?.({ preventScroll: true });
    };

    const clientFailure = (code, message = '') => ({
        success: false,
        message,
        meta: {
            diagnosis: normalizeDiagnosis({ code, ...(clientDiagnoses[code] || {}) }, clientDiagnoses.unexpected_error),
        },
    });

    const finish = (state, result, phase) => {
        clearIntervalImpl(state.intervalId);
        clearTimeoutImpl(state.timeoutId);
        state.phase = phase;
        state.result = result;
        setButtonReady(state.button, labels.viewResult || labels.test || '');
        state.button.dataset.testState = phase;
        if (dialog.open && activeModelId === state.context.modelId) {
            lastFocus = state.button;
            renderState(state);
        }
    };

    const runTest = async (context, button) => {
        const existing = states.get(context.modelId);
        if (existing?.phase === 'loading') {
            openState(existing, button);
            return;
        }

        const controller = new AbortControllerClass();
        const state = {
            phase: 'loading',
            context,
            button,
            startedAt: now(),
            result: null,
            intervalId: null,
            timeoutId: null,
            timedOut: false,
        };
        states.set(context.modelId, state);
        setButtonLoading(button, labels.testing || labels.test || '');
        button.dataset.testState = 'loading';
        openState(state, button);
        state.intervalId = setIntervalImpl(() => {
            if (dialog.open && activeModelId === context.modelId && state.phase === 'loading') renderLoading(state);
        }, 1000);
        state.timeoutId = setTimeoutImpl(() => {
            state.timedOut = true;
            controller.abort();
        }, timeoutMs);

        try {
            const response = await fetchImpl(context.testUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({}),
                signal: controller.signal,
            });
            let data;
            try {
                data = await response.json();
            } catch {
                const clientCode = response.status === 419 || response.status === 401
                    ? 'session_expired'
                    : (response.status === 429 ? 'web_rate_limited' : 'invalid_json');
                finish(state, clientFailure(clientCode, response.statusText || ''), 'failure');
                return;
            }

            if (response.ok && data?.success === true) {
                finish(state, data, 'success');
                return;
            }

            if (!data?.meta?.diagnosis) {
                const clientCode = response.status === 419 || response.status === 401 || data?.code === 'unauthenticated'
                    ? 'session_expired'
                    : (response.status === 429 ? 'web_rate_limited' : 'unexpected_error');
                data = clientFailure(clientCode, data?.message || response.statusText || '');
            }
            finish(state, data, 'failure');
        } catch {
            finish(
                state,
                clientFailure(state.timedOut ? 'client_timeout' : 'network_failed'),
                'failure',
            );
        }
    };

    buttons.forEach((button) => {
        const context = {
            modelId: String(button.dataset.modelId || ''),
            modelName: String(button.dataset.modelName || ''),
            providerModelId: String(button.dataset.providerModelId || ''),
            modelType: String(button.dataset.modelType || ''),
            testUrl: String(button.dataset.testUrl || ''),
            editUrl: String(button.dataset.editUrl || ''),
            focusFallback: root.querySelector(`[data-ai-model-test-fallback="${String(button.dataset.modelId || '')}"]`),
        };
        if (!context.modelId || !context.testUrl || !context.editUrl || !context.focusFallback) {
            throw new Error('AI model test button markup is incomplete.');
        }

        button.addEventListener('click', () => {
            const state = states.get(context.modelId);
            if (state && state.phase !== 'idle') {
                openState(state, button);
                return;
            }
            void runTest(context, button);
        });
        setButtonReady(button, labels.test || button.textContent);
    });

    elements.retry?.addEventListener('click', () => {
        const state = states.get(activeModelId);
        if (!state || state.phase === 'loading') return;
        void runTest(state.context, state.button);
    });
    elements.closeButtons.forEach((button) => button.addEventListener('click', () => {
        if (dialog.open) dialog.close();
    }));
    dialog.addEventListener('close', () => {
        const restoreFocus = () => {
            const state = states.get(activeModelId);
            const target = lastFocus && !lastFocus.disabled
                ? lastFocus
                : state?.context?.focusFallback;
            target?.focus?.({ preventScroll: true });
        };
        restoreFocus();
        setTimeoutImpl(restoreFocus, 0);
    });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        if (!inside && dialog.open) dialog.close();
    });

    return {
        open(modelId, trigger = null) {
            const state = states.get(String(modelId));
            if (state) openState(state, trigger);
        },
        states,
    };
}
