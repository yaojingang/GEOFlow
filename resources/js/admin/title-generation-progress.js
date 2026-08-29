export function renderTitleGenerationProgress(root, payload) {
    const statusLabels = {
        queued: root.dataset.statusQueued,
        running: root.dataset.statusRunning,
        completed: root.dataset.statusCompleted,
        partial: root.dataset.statusPartial,
        failed: root.dataset.statusFailed,
        cancelled: root.dataset.statusCancelled,
    };
    const progress = Math.max(0, Math.min(100, Number(payload.progress_percent) || 0));
    const status = String(payload.status || 'queued');
    const statusElement = root.querySelector('[data-generation-status]');
    const progressBar = root.querySelector('[data-generation-progress-bar]');
    const progressLabel = root.querySelector('[data-generation-progress-label]');
    const errorElement = root.querySelector('[data-generation-error]');
    const noticeElement = root.querySelector('[data-generation-notice]');
    const retryElement = root.querySelector('[data-generation-retry]');
    const cancelElement = root.querySelector('[data-generation-cancel]');

    root.setAttribute('aria-busy', payload.active ? 'true' : 'false');
    if (statusElement) statusElement.textContent = statusLabels[status] || status;
    if (progressBar) progressBar.style.width = `${progress}%`;
    if (progressBar) progressBar.setAttribute('aria-valuenow', String(progress));
    if (progressLabel) progressLabel.textContent = `${progress}%`;

    for (const key of ['requested_count', 'generated_count', 'saved_count', 'duplicate_count', 'batch_count']) {
        const element = root.querySelector(`[data-generation-${key.replaceAll('_', '-')}]`);
        if (element) element.textContent = Number(payload[key] || 0).toLocaleString();
    }

    if (errorElement) {
        errorElement.textContent = payload.last_error || '';
        errorElement.classList.toggle('hidden', !payload.last_error);
    }
    if (noticeElement) {
        noticeElement.textContent = payload.notice || '';
        noticeElement.classList.toggle('hidden', !payload.notice);
    }
    if (retryElement) retryElement.classList.toggle('hidden', !payload.retryable);
    if (cancelElement) cancelElement.classList.toggle('hidden', !payload.active);

    return Boolean(payload.active);
}

async function fetchPayloadWithTimeout(fetchAction, url, options, timeoutMs, setTimerAction, clearTimerAction) {
    const abortController = new AbortController();
    let timeoutId;
    const timeout = new Promise((_, reject) => {
        timeoutId = setTimerAction(() => {
            abortController.abort();
            reject(new Error('title_generation_poll_timeout'));
        }, timeoutMs);
    });

    try {
        const request = (async () => {
            const response = await fetchAction(url, { ...options, signal: abortController.signal });
            const payload = response.ok ? await response.json() : null;

            return { response, payload };
        })();

        return await Promise.race([
            request,
            timeout,
        ]);
    } finally {
        clearTimerAction(timeoutId);
    }
}

export function initializeTitleGenerationProgress(root, {
    fetchAction = (...args) => window.fetch(...args),
    pollTimeoutMs = 15000,
    reloadAction = () => window.location.reload(),
    scheduleAction = (callback, delay) => window.setTimeout(callback, delay),
    setTimerAction = (callback, delay) => globalThis.setTimeout(callback, delay),
    clearTimerAction = (timerId) => globalThis.clearTimeout(timerId),
} = {}) {
    let consecutiveFailures = 0;

    const showPollingError = (message) => {
        const errorElement = root.querySelector('[data-generation-error]');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.toggle('hidden', false);
        }
    };

    const poll = async () => {
        try {
            const { response, payload } = await fetchPayloadWithTimeout(
                fetchAction,
                root.dataset.statusUrl,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                },
                Math.max(1, Number(pollTimeoutMs) || 15000),
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
            const active = renderTitleGenerationProgress(root, payload);
            if (active) {
                const nextPoll = Math.max(1000, Math.min(300000, Number(payload.next_poll_ms) || 2500));
                scheduleAction(poll, nextPoll);
            } else {
                scheduleAction(reloadAction, 800);
            }
        } catch {
            consecutiveFailures += 1;
            root.setAttribute('aria-busy', 'false');
            if (consecutiveFailures >= 2) showPollingError(root.dataset.pollUnavailable || '');

            const retryDelay = Math.min(60000, 5000 * (2 ** Math.min(4, consecutiveFailures - 1)));
            scheduleAction(poll, retryDelay);
        }
    };

    if (root.dataset.active === 'true') scheduleAction(poll, 1000);

    return {
        poll,
        update: (payload) => renderTitleGenerationProgress(root, payload),
    };
}
