export function renderArticleAiQualityProgress(root, payload) {
    const progress = Math.max(0, Math.min(100, Number(payload.progress_percent) || 0));
    const messageElement = root.querySelector('[data-ai-quality-progress-message]');
    const detailElement = root.querySelector('[data-ai-quality-progress-detail]');
    const progressBar = root.querySelector('[data-ai-quality-progress-bar]');
    const progressPercent = root.querySelector('[data-ai-quality-progress-percent]');
    const segmentsElement = root.querySelector('[data-ai-quality-progress-segments]');
    const elapsedElement = root.querySelector('[data-ai-quality-progress-elapsed]');
    const errorElement = root.querySelector('[data-ai-quality-progress-error]');
    const resultLabelElement = document.querySelector('[data-ai-quality-result-label]');
    const qualityCard = root.closest?.('[data-ai-quality-collapsible]');
    const compactProgress = qualityCard?.querySelector('[data-ai-quality-compact-progress]');
    const compactMessage = qualityCard?.querySelector('[data-ai-quality-compact-message]');

    const waitingForTerminalState = Boolean(payload.active || payload.reconciling);
    root.setAttribute('aria-busy', waitingForTerminalState ? 'true' : 'false');
    if (messageElement && typeof payload.message === 'string') messageElement.textContent = payload.message;
    if (detailElement && typeof payload.detail === 'string') {
        detailElement.textContent = typeof payload.deadline_warning === 'string'
            ? payload.deadline_warning
            : payload.detail;
    }
    if (progressBar) {
        progressBar.value = progress;
        progressBar.setAttribute('aria-valuenow', String(progress));
    }
    if (progressPercent) progressPercent.textContent = `${progress}%`;
    if (compactProgress) compactProgress.textContent = `${progress}%`;
    if (compactMessage && typeof payload.message === 'string') compactMessage.textContent = payload.message;
    if (segmentsElement && typeof payload.segments_label === 'string') {
        segmentsElement.textContent = payload.segments_label;
    }
    if (elapsedElement && typeof payload.elapsed_label === 'string') {
        elapsedElement.textContent = payload.elapsed_label;
    }
    if (resultLabelElement && typeof payload.result_label === 'string') {
        resultLabelElement.textContent = payload.result_label;
    }
    if (errorElement) {
        errorElement.textContent = '';
        errorElement.classList.toggle('hidden', true);
    }

    return waitingForTerminalState;
}

async function fetchPayloadWithTimeout(fetchAction, url, options, timeoutMs, setTimerAction, clearTimerAction) {
    const abortController = new AbortController();
    let timeoutId;
    const timeout = new Promise((_, reject) => {
        timeoutId = setTimerAction(() => {
            abortController.abort();
            reject(new Error('article_ai_quality_poll_timeout'));
        }, timeoutMs);
    });

    try {
        const request = (async () => {
            const response = await fetchAction(url, { ...options, signal: abortController.signal });
            const payload = response.ok ? await response.json() : null;

            return { response, payload };
        })();

        return await Promise.race([request, timeout]);
    } finally {
        clearTimerAction(timeoutId);
    }
}

export function initializeArticleAiQualityProgress(root, {
    fetchAction = (...args) => window.fetch(...args),
    pollTimeoutMs = 12000,
    reloadAction = () => window.location.reload(),
    nowAction = () => Date.now(),
    scheduleAction = (callback, delay) => window.setTimeout(callback, delay),
    setTimerAction = (callback, delay) => globalThis.setTimeout(callback, delay),
    clearTimerAction = (timerId) => globalThis.clearTimeout(timerId),
} = {}) {
    let consecutiveFailures = 0;

    const showPollingError = (message) => {
        const errorElement = root.querySelector('[data-ai-quality-progress-error]');
        if (!errorElement) return;

        errorElement.textContent = message;
        errorElement.classList.toggle('hidden', false);
    };

    const deadlineTimestamp = Date.parse(root.dataset.deadlineAt || '');
    const hardDeadlineExpired = () => Number.isFinite(deadlineTimestamp)
        && nowAction() >= deadlineTimestamp + 65000;
    const stopForDeadline = () => {
        root.setAttribute('aria-busy', 'false');
        showPollingError(root.dataset.deadlineExceeded || '');
    };

    const poll = async () => {
        if (hardDeadlineExpired()) {
            stopForDeadline();

            return;
        }

        try {
            const { response, payload } = await fetchPayloadWithTimeout(
                fetchAction,
                root.dataset.statusUrl,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                },
                Math.max(1, Number(pollTimeoutMs) || 12000),
                setTimerAction,
                clearTimerAction,
            );

            if ([401, 403, 419].includes(response.status)) {
                root.setAttribute('aria-busy', 'false');
                showPollingError(root.dataset.sessionExpired || '');

                return;
            }
            if (response.status === 404) {
                root.setAttribute('aria-busy', 'false');

                return;
            }
            if (!response.ok) throw new Error(`status_${response.status}`);

            consecutiveFailures = 0;
            const active = renderArticleAiQualityProgress(root, payload);
            if (active) {
                const nextPoll = Math.max(1500, Math.min(30000, Number(payload.next_poll_ms) || 2000));
                scheduleAction(poll, nextPoll);
            } else if (payload.reload) {
                scheduleAction(reloadAction, 650);
            }
        } catch {
            if (hardDeadlineExpired()) {
                stopForDeadline();

                return;
            }
            consecutiveFailures += 1;
            root.setAttribute('aria-busy', 'false');
            if (consecutiveFailures >= 2) showPollingError(root.dataset.pollUnavailable || '');

            const retryDelay = Math.min(30000, 3000 * (2 ** Math.min(3, consecutiveFailures - 1)));
            scheduleAction(poll, retryDelay);
        }
    };

    if (root.dataset.active === 'true') scheduleAction(poll, 800);

    return {
        poll,
        update: (payload) => renderArticleAiQualityProgress(root, payload),
    };
}
