class ArticleExportError extends Error {
    constructor(message) {
        super(message);
        this.name = 'ArticleExportError';
    }
}

function defaultDownload(url, filename) {
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.hidden = true;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
}

function responseMessage(payload, fallback) {
    if (typeof payload?.message === 'string' && payload.message.trim() !== '') {
        return payload.message.trim();
    }

    const errors = payload?.errors;
    if (errors && typeof errors === 'object') {
        for (const value of Object.values(errors)) {
            if (Array.isArray(value) && typeof value[0] === 'string' && value[0].trim() !== '') {
                return value[0].trim();
            }
        }
    }

    return fallback;
}

async function jsonResponse(response) {
    const contentType = response?.headers?.get?.('content-type') ?? '';
    if (!contentType.toLowerCase().includes('application/json')) return null;

    try {
        return await response.json();
    } catch {
        return null;
    }
}

function exportData(payload, origin, invalidResponseMessage, expectedCount) {
    const data = payload?.data;
    const downloadUrl = typeof data?.download_url === 'string' ? data.download_url : '';
    const filename = typeof data?.filename === 'string' ? data.filename : '';
    const expiresAt = typeof data?.expires_at === 'string' ? data.expires_at : '';

    if (!downloadUrl.startsWith('/') || downloadUrl.startsWith('//')) {
        throw new ArticleExportError(invalidResponseMessage);
    }

    let resolvedUrl;
    try {
        resolvedUrl = new URL(downloadUrl, origin);
    } catch {
        throw new ArticleExportError(invalidResponseMessage);
    }

    if (resolvedUrl.origin !== origin
        || !Number.isInteger(data?.count)
        || data.count !== expectedCount
        || filename === ''
        || filename.includes('/')
        || filename.includes('\\')
        || !filename.toLowerCase().endsWith('.zip')
        || !Number.isFinite(Date.parse(expiresAt))) {
        throw new ArticleExportError(invalidResponseMessage);
    }

    return {
        count: data.count,
        downloadUrl,
        expiresAt,
        filename,
    };
}

export function initializeArticleBatchExport(root = document, dependencies = {}) {
    const dialog = root.querySelector('[data-article-batch-export]');
    const form = root.querySelector('#batch-form');
    const actionSelect = root.querySelector('#batch-action');
    const executeButton = root.querySelector('[data-batch-execute]');
    const exportOption = root.querySelector('[data-article-batch-export-option]');
    if (!dialog || !form || !actionSelect || !executeButton || !exportOption) return;

    const loadingState = dialog.querySelector('[data-export-state="loading"]');
    const successState = dialog.querySelector('[data-export-state="success"]');
    const errorState = dialog.querySelector('[data-export-state="error"]');
    const loadingFocus = dialog.querySelector('[data-export-loading-focus]');
    const successFocus = dialog.querySelector('[data-export-success-focus]');
    const errorFocus = dialog.querySelector('[data-export-error-focus]');
    const selectedCount = dialog.querySelector('[data-export-selected-count]');
    const filename = dialog.querySelector('[data-export-filename]');
    const errorMessage = dialog.querySelector('[data-export-error-message]');
    const retryButton = dialog.querySelector('[data-export-retry]');
    const closeButtons = root.querySelectorAll('[data-export-close]');
    if (!loadingState || !successState || !errorState || !loadingFocus || !successFocus
        || !errorFocus || !selectedCount || !filename || !errorMessage || !retryButton) return;

    const fetchImpl = dependencies.fetchImpl ?? globalThis.fetch?.bind(globalThis);
    const notify = dependencies.notify ?? ((message) => globalThis.window?.AdminActionDialog?.notice?.({
        tone: 'info',
        title: '',
        message,
    }));
    const download = dependencies.download ?? defaultDownload;
    const formDataFactory = dependencies.formDataFactory ?? (() => new FormData());
    const now = dependencies.now ?? (() => Date.now());
    const origin = dependencies.origin ?? globalThis.location?.origin ?? '';
    if (typeof fetchImpl !== 'function' || origin === '') return;

    const prepareUrl = dialog.dataset.prepareUrl || '';
    const configuredMaxArticles = Number.parseInt(dialog.dataset.maxArticles || '500', 10);
    const maxArticles = Number.isInteger(configuredMaxArticles) && configuredMaxArticles > 0
        ? configuredMaxArticles
        : 500;
    const invalidResponseMessage = dialog.dataset.invalidResponseMessage || 'Invalid export response.';
    const networkErrorMessage = dialog.dataset.networkErrorMessage || 'The export could not be prepared.';
    const expiredMessage = dialog.dataset.expiredMessage || 'The download link has expired.';
    const csrfExpiredMessage = dialog.dataset.csrfExpiredMessage || networkErrorMessage;
    const rateLimitedMessage = dialog.dataset.rateLimitedMessage || networkErrorMessage;
    const requestTooLargeMessage = dialog.dataset.requestTooLargeMessage || networkErrorMessage;
    let activeState = 'idle';
    let latestExport = null;
    let opener = null;
    let controlStates = [];

    const showState = (state) => {
        activeState = state;
        loadingState.hidden = state !== 'loading';
        successState.hidden = state !== 'success';
        errorState.hidden = state !== 'error';
        dialog.scrollTop = 0;

        const focusTarget = state === 'loading' ? loadingFocus : state === 'success' ? successFocus : errorFocus;
        focusTarget?.focus?.({ preventScroll: true });
    };

    const openDialog = () => {
        opener = executeButton;
        if (!dialog.open) dialog.showModal?.();
    };

    const lockControls = () => {
        const controls = [
            ...root.querySelectorAll('[data-article-batch-control]'),
            ...root.querySelectorAll('.article-checkbox'),
        ];
        controlStates = controls.map((control) => [control, Boolean(control.disabled)]);
        controls.forEach((control) => {
            control.disabled = true;
        });
        form.setAttribute?.('aria-busy', 'true');
    };

    const unlockControls = () => {
        controlStates.forEach(([control, wasDisabled]) => {
            control.disabled = wasDisabled;
        });
        controlStates = [];
        form.removeAttribute?.('aria-busy');
    };

    const showError = (message) => {
        errorMessage.textContent = message;
        openDialog();
        showState('error');
    };

    const closeDialog = () => {
        dialog.close?.();
        activeState = 'idle';
        opener?.focus?.({ preventScroll: true });
    };

    const prepare = async (articleIds) => {
        selectedCount.textContent = String(articleIds.length);
        openDialog();
        showState('loading');
        lockControls();

        try {
            const requestBody = formDataFactory();
            requestBody.append('_token', form.dataset.csrfToken || '');
            articleIds.forEach((articleId) => requestBody.append('article_ids[]', String(articleId)));
            const response = await fetchImpl(prepareUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': form.dataset.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: requestBody,
            });
            const payload = await jsonResponse(response);
            if (!response.ok) {
                const localizedStatusMessage = {
                    413: requestTooLargeMessage,
                    419: csrfExpiredMessage,
                    429: rateLimitedMessage,
                }[response.status];
                const message = localizedStatusMessage ?? responseMessage(payload, networkErrorMessage);
                throw new ArticleExportError(message);
            }
            if (!payload) throw new ArticleExportError(invalidResponseMessage);

            latestExport = exportData(payload, origin, invalidResponseMessage, articleIds.length);
            if (Date.parse(latestExport.expiresAt) <= now()) {
                latestExport = null;
                throw new ArticleExportError(expiredMessage);
            }
            filename.textContent = latestExport.filename;
            download(latestExport.downloadUrl, latestExport.filename);
            showState('success');
        } catch (error) {
            latestExport = null;
            showError(error instanceof ArticleExportError ? error.message : networkErrorMessage);
        } finally {
            unlockControls();
        }
    };

    form.addEventListener('submit', async (event) => {
        if (actionSelect.value !== 'export_markdown') return;

        event.preventDefault();
        event.stopImmediatePropagation?.();
        if (activeState === 'loading') return;

        const articleIds = [...root.querySelectorAll('.article-checkbox:checked')]
            .map((checkbox) => Number.parseInt(checkbox.value, 10))
            .filter((articleId) => Number.isInteger(articleId) && articleId > 0);

        if (articleIds.length === 0) {
            notify(dialog.dataset.selectArticlesMessage || 'Select at least one article.');
            return;
        }
        if (articleIds.length > maxArticles) {
            showError(dialog.dataset.tooManyMessage || `You can export up to ${maxArticles} articles at a time.`);
            return;
        }

        await prepare(articleIds);
    }, true);

    retryButton.addEventListener('click', () => {
        if (!latestExport || Date.parse(latestExport.expiresAt) <= now()) {
            latestExport = null;
            showError(expiredMessage);
            return;
        }

        download(latestExport.downloadUrl, latestExport.filename);
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', closeDialog);
    });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog || activeState === 'loading') return;

        const bounds = dialog.getBoundingClientRect?.();
        if (!bounds) return;

        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        if (!inside) closeDialog();
    });
    dialog.addEventListener('cancel', (event) => {
        if (activeState === 'loading') event.preventDefault();
    });
    dialog.addEventListener('close', () => {
        activeState = 'idle';
        opener?.focus?.({ preventScroll: true });
    });
    exportOption.disabled = false;
}

if (typeof document !== 'undefined') initializeArticleBatchExport(document);
