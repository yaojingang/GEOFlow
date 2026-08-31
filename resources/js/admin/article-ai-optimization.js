const readJson = (root, selector, fallback = {}) => {
    const node = root.querySelector(selector);
    if (!node) return fallback;
    try {
        return JSON.parse(node.textContent || 'null') ?? fallback;
    } catch {
        return fallback;
    }
};

const replaceTokens = (template, values) => Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`__${key}__`, String(value ?? '')),
    String(template || ''),
);

const createRequestKey = () => window.crypto?.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (token) => {
    const random = Math.floor(Math.random() * 16);
    return (token === 'x' ? random : ((random & 0x3) | 0x8)).toString(16);
});

const progressByState = {
    awaiting_quality: 8,
    queued: 18,
    planning: 28,
    rewriting: 45,
    validating: 62,
    evaluating: 78,
    candidate_ready: 100,
    applying: 95,
    completed: 100,
    needs_review: 100,
    failed: 100,
    stale: 100,
    cancelled: 100,
};

function initializeArticleAiOptimization(root) {
    const panel = root.querySelector('[data-ai-optimization-panel]');
    const openButton = root.querySelector('[data-ai-optimization-open]');
    const form = root.closest('#article-edit-form') || root.querySelector('#article-edit-form');
    if (!panel || !openButton || !form) return;

    const i18n = readJson(panel, '[data-ai-optimization-i18n]', {});
    const csrfToken = form.querySelector('input[name="_token"]')?.value || '';
    const notice = panel.querySelector('[data-ai-optimization-notice]');
    const progress = panel.querySelector('[data-ai-optimization-progress]');
    const statusLabel = panel.querySelector('[data-ai-optimization-status]');
    const roundsLabel = panel.querySelector('[data-ai-optimization-rounds]');
    const progressBar = panel.querySelector('[data-ai-optimization-progress-bar]');
    const candidatePanel = panel.querySelector('[data-ai-optimization-candidate]');
    const candidateScore = panel.querySelector('[data-ai-optimization-score]');
    const modifications = panel.querySelector('[data-ai-optimization-modifications]');
    const startButton = panel.querySelector('[data-ai-optimization-start]');
    const applyButton = panel.querySelector('[data-ai-optimization-apply]');
    const cancelButton = panel.querySelector('[data-ai-optimization-cancel]');
    const rollbackButton = panel.querySelector('[data-ai-optimization-rollback]');
    const closeButton = panel.querySelector('[data-ai-optimization-close]');
    const modelSelect = panel.querySelector('#article-ai-optimization-model');
    const qualityButton = root.querySelector('[data-ai-quality-submit]');
    let articleDirty = false;
    let current = readJson(panel, '[data-ai-optimization-initial]', null);
    let pollTimer = null;
    let candidateLoadedFor = '';
    let candidateRequestGeneration = 0;
    let editorReady = Boolean(window.geoArticleEditorAssistantBridge);
    let requestKey = createRequestKey();
    let qualitySubmitConfirmed = false;
    let qualitySubmitConfirming = false;

    const setNotice = (message, tone = 'neutral') => {
        if (!notice) return;
        notice.textContent = message || '';
        notice.classList.toggle('text-red-700', tone === 'error');
        notice.classList.toggle('ring-red-200', tone === 'error');
        notice.classList.toggle('text-gray-600', tone !== 'error');
        notice.classList.toggle('ring-blue-100', tone !== 'error');
    };

    const confirmOptimizationAction = async ({ title, message, guidance, tone, confirmLabel, opener }) => {
        if (!window.AdminActionDialog?.confirm) return false;

        return await window.AdminActionDialog.confirm({
            title,
            message,
            guidance,
            tone,
            confirmLabel,
            opener,
        }) === true;
    };

    const reportActionError = (error, opener) => {
        const message = error?.message || i18n.requestFailed || '';
        setNotice(message, 'error');
        void window.AdminActionDialog?.alert?.({
            title: i18n.actionErrorTitle || '',
            message,
            guidance: i18n.actionErrorGuidance || '',
            tone: 'error',
            confirmLabel: i18n.closeLabel || '',
            opener,
        });
    };

    const lockArticleForm = (locked) => {
        if (locked) document.activeElement?.blur?.();
        form.inert = locked;
        form.toggleAttribute('aria-busy', locked);
    };

    const endpoint = (runId, action) => `${panel.dataset.startUrl}/${runId}/${action}`;

    const request = async (url, options = {}) => {
        const response = await window.fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                ...(options.headers || {}),
            },
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            throw new Error(payload?.error?.message || i18n.requestFailed || `status_${response.status}`);
        }

        return payload?.data ?? payload;
    };

    const createText = (tag, text, className) => {
        const node = document.createElement(tag);
        node.textContent = String(text || '');
        node.className = className;
        return node;
    };

    const renderCandidate = (candidate) => {
        modifications?.replaceChildren();
        if (candidateScore) {
            candidateScore.textContent = replaceTokens(i18n.score, {
                BEFORE: candidate.before_score ?? '-',
                AFTER: candidate.after_score ?? '-',
            });
        }
        (candidate.modifications || []).forEach((change) => {
            const card = document.createElement('article');
            card.className = 'rounded-md border border-gray-200 bg-gray-50 p-3';
            card.append(createText('p', replaceTokens(i18n.field, {
                FIELD: change.field,
                ROUND: change.round,
            }), 'text-xs font-semibold text-gray-700'));
            const comparison = document.createElement('div');
            comparison.className = 'mt-2 grid gap-2 lg:grid-cols-2';
            [['before', change.before_text], ['after', change.after_text]].forEach(([key, value]) => {
                const box = document.createElement('div');
                box.className = key === 'after'
                    ? 'rounded-md bg-emerald-50 p-2.5 ring-1 ring-emerald-100'
                    : 'rounded-md bg-white p-2.5 ring-1 ring-gray-200';
                box.append(createText('p', i18n[key], 'text-[11px] font-semibold uppercase tracking-wide text-gray-500'));
                box.append(createText('pre', value, 'mt-1 whitespace-pre-wrap break-words font-sans text-xs leading-5 text-gray-700'));
                comparison.append(box);
            });
            card.append(comparison);
            if (change.reason) card.append(createText('p', change.reason, 'mt-2 text-xs leading-5 text-gray-600'));
            modifications?.append(card);
        });
        candidatePanel?.classList.remove('hidden');
    };

    const currentCandidateSignature = () => [
        Number(current?.run_id || 0),
        current?.best_score,
        current?.completed_rounds,
        current?.candidate_hash,
    ].join(':');

    const invalidateCandidatePreview = () => {
        candidateRequestGeneration += 1;
        candidateLoadedFor = '';
    };

    const loadCandidate = async () => {
        const runId = Number(current?.run_id || 0);
        const candidateSignature = [runId, current?.best_score, current?.completed_rounds, current?.candidate_hash].join(':');
        if (runId <= 0 || candidateLoadedFor === candidateSignature) return;
        const requestGeneration = ++candidateRequestGeneration;
        const candidate = await request(endpoint(runId, 'candidate'), { method: 'GET', headers: { 'Content-Type': 'application/json' } });
        if (requestGeneration !== candidateRequestGeneration
            || candidateSignature !== currentCandidateSignature()) return;
        candidateLoadedFor = candidateSignature;
        renderCandidate(candidate);
    };

    const renderStatus = async () => {
        const status = String(current?.status || '');
        const active = current?.active === true;
        const shouldPoll = current?.should_poll === true;
        const featureEnabled = panel.dataset.featureEnabled === 'true';
        progress?.classList.toggle('hidden', status === '');
        let statusText = i18n.states?.[status] || status;
        const lastAttempt = current?.last_attempt;
        const targetScoreReached = status === 'needs_review'
            && Number(current?.best_score || 0) >= Number(current?.target_score || 0);
        if (targetScoreReached) {
            statusText = i18n.stateTargetScoreReview || statusText;
        } else if (status === 'needs_review'
            && current?.stop_reason === 'candidate_not_improved'
            && lastAttempt?.before_score !== null
            && lastAttempt?.before_score !== undefined
            && lastAttempt?.after_score !== null
            && lastAttempt?.after_score !== undefined) {
            statusText = `${statusText} · ${replaceTokens(i18n.score, {
                BEFORE: lastAttempt.before_score,
                AFTER: lastAttempt.after_score,
            })}`;
        }
        if (statusLabel) statusLabel.textContent = statusText;
        const canApply = current?.can_apply === true;
        if (roundsLabel) roundsLabel.textContent = replaceTokens(i18n.rounds, {
            CURRENT: Number(current?.completed_rounds || 0),
            TOTAL: Number(current?.max_rounds || 0),
        });
        if (progressBar) progressBar.style.width = `${progressByState[status] ?? 0}%`;
        startButton.disabled = articleDirty || active || !featureEnabled;
        startButton.classList.toggle('hidden', status === 'candidate_ready' || status === 'applying');
        applyButton?.classList.toggle('hidden', !canApply);
        if (applyButton) applyButton.disabled = articleDirty || !canApply;
        cancelButton?.classList.toggle('hidden', !active && status !== 'candidate_ready');
        if (cancelButton) {
            cancelButton.textContent = status === 'candidate_ready'
                ? i18n.discardCandidate
                : i18n.cancelActive;
        }
        rollbackButton?.classList.toggle('hidden', current?.can_rollback !== true);
        if (rollbackButton) rollbackButton.disabled = articleDirty || current?.can_rollback !== true;
        if (current?.can_preview === true) {
            try {
                await loadCandidate();
            } catch (error) {
                setNotice(error.message || i18n.requestFailed, 'error');
            }
        } else {
            invalidateCandidatePreview();
            modifications?.replaceChildren();
            candidatePanel?.classList.add('hidden');
        }
        if (!shouldPoll && pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    };

    const poll = async () => {
        try {
            const payload = await request(panel.dataset.statusUrl, { method: 'GET', headers: { 'Content-Type': 'application/json' } });
            current = payload?.optimization ?? null;
            await renderStatus();
            if (current?.should_poll === true) pollTimer = window.setTimeout(poll, 2000);
        } catch (error) {
            setNotice(error.message || i18n.requestFailed, 'error');
            pollTimer = window.setTimeout(poll, 5000);
        }
    };

    const beginPolling = () => {
        if (pollTimer) window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(poll, 1200);
    };

    const markDirty = (event) => {
        if (event?.target?.closest?.('[data-ai-optimization-panel]')) return;
        articleDirty = true;
        setNotice(i18n.dirty, 'error');
        void renderStatus();
    };

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', async (event) => {
        if (!qualityButton || event.submitter !== qualityButton) return;
        if (qualitySubmitConfirmed) {
            qualitySubmitConfirmed = false;
            return;
        }

        event.preventDefault();
        if (qualitySubmitConfirming) return;
        qualitySubmitConfirming = true;
        const confirmed = await confirmOptimizationAction({
            title: i18n.dialogs?.qualityTitle || '',
            message: i18n.dialogs?.qualityMessage || '',
            guidance: i18n.dialogs?.qualityGuidance || '',
            tone: 'success',
            confirmLabel: i18n.dialogs?.qualityLabel || '',
            opener: qualityButton,
        });
        qualitySubmitConfirming = false;
        if (!confirmed || !form.isConnected) return;
        qualitySubmitConfirmed = true;
        form.requestSubmit(qualityButton);
    });
    window.addEventListener('geo-article-editor-ready', () => { editorReady = true; });
    window.addEventListener('geo-article-editor-input', (event) => {
        if (editorReady) markDirty(event);
    });

    openButton.addEventListener('click', () => {
        panel.classList.toggle('hidden');
        if (articleDirty) setNotice(i18n.dirty, 'error');
        void renderStatus();
    });
    closeButton?.addEventListener('click', () => {
        panel.classList.add('hidden');
        openButton.focus();
    });

    startButton?.addEventListener('click', async () => {
        if (articleDirty) {
            setNotice(i18n.dirty, 'error');
            return;
        }
        const strategy = panel.querySelector('input[name="article_ai_optimization_level"]:checked')?.value || 'excellent_80';
        const modelId = Number(modelSelect?.value || 0);
        if (panel.dataset.modelRequired === 'true' && modelId <= 0) {
            setNotice(i18n.modelRequired, 'error');
            return;
        }
        if (!await confirmOptimizationAction({
            title: i18n.dialogs?.startTitle || '',
            message: i18n.dialogs?.startMessage || '',
            guidance: i18n.dialogs?.startGuidance || '',
            tone: 'success',
            confirmLabel: i18n.dialogs?.startLabel || '',
            opener: startButton,
        })) return;
        if (articleDirty) {
            setNotice(i18n.dirty, 'error');
            return;
        }
        startButton.disabled = true;
        try {
            current = await request(panel.dataset.startUrl, {
                method: 'POST',
                body: JSON.stringify({ request_key: requestKey, strategy, optimization_model_id: modelId || null }),
            });
            requestKey = createRequestKey();
            setNotice(i18n.states?.[current?.status] || '');
            await renderStatus();
            if (current?.should_poll === true) beginPolling();
        } catch (error) {
            reportActionError(error, startButton);
            startButton.disabled = false;
        }
    });

    applyButton?.addEventListener('click', async () => {
        if (articleDirty) {
            setNotice(i18n.dirty, 'error');
            return;
        }
        if (!await confirmOptimizationAction({
            title: i18n.dialogs?.applyTitle || '',
            message: i18n.dialogs?.applyMessage || '',
            guidance: i18n.dialogs?.applyGuidance || '',
            tone: 'warning',
            confirmLabel: i18n.dialogs?.applyLabel || '',
            opener: applyButton,
        })) return;
        if (articleDirty) {
            setNotice(i18n.dirty, 'error');
            return;
        }
        applyButton.disabled = true;
        lockArticleForm(true);
        try {
            await request(endpoint(current.run_id, 'apply'), {
                method: 'POST',
                body: JSON.stringify({ candidate_hash: current.candidate_hash }),
            });
            window.location.reload();
        } catch (error) {
            lockArticleForm(false);
            reportActionError(error, applyButton);
            applyButton.disabled = false;
        }
    });

    cancelButton?.addEventListener('click', async () => {
        const discardingCandidate = String(current?.status || '') === 'candidate_ready';
        if (!await confirmOptimizationAction({
            title: discardingCandidate ? i18n.dialogs?.discardTitle : i18n.dialogs?.cancelTitle,
            message: discardingCandidate ? i18n.dialogs?.discardMessage : i18n.dialogs?.cancelMessage,
            guidance: i18n.dialogs?.cancelGuidance || '',
            tone: 'warning',
            confirmLabel: discardingCandidate ? i18n.dialogs?.discardLabel : i18n.dialogs?.cancelLabel,
            opener: cancelButton,
        })) return;
        cancelButton.disabled = true;
        try {
            current = await request(endpoint(current.run_id, 'cancel'), { method: 'POST', body: '{}' });
            await renderStatus();
        } catch (error) {
            reportActionError(error, cancelButton);
        } finally {
            cancelButton.disabled = false;
        }
    });

    rollbackButton?.addEventListener('click', async () => {
        if (articleDirty) {
            setNotice(i18n.dirty, 'error');
            return;
        }
        if (!await confirmOptimizationAction({
            title: i18n.dialogs?.rollbackTitle || '',
            message: i18n.dialogs?.rollbackMessage || '',
            guidance: i18n.dialogs?.rollbackGuidance || '',
            tone: 'warning',
            confirmLabel: i18n.dialogs?.rollbackLabel || '',
            opener: rollbackButton,
        })) return;
        if (articleDirty) {
            setNotice(i18n.dirty, 'error');
            return;
        }
        rollbackButton.disabled = true;
        lockArticleForm(true);
        try {
            await request(endpoint(current.run_id, 'rollback'), { method: 'POST', body: '{}' });
            window.location.reload();
        } catch (error) {
            lockArticleForm(false);
            reportActionError(error, rollbackButton);
            rollbackButton.disabled = false;
        }
    });

    void renderStatus();
    if (current?.should_poll === true) beginPolling();
}

document.querySelectorAll('#ai-quality-result').forEach(initializeArticleAiOptimization);
