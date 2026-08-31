export function initializeProviderDeleteConfirmations(
    root,
    actionDialog = globalThis.window?.AdminActionDialog,
) {
    const controllerReady = typeof actionDialog === 'function' || typeof actionDialog?.confirm === 'function';
    if (!controllerReady) return;
    root.querySelectorAll('[data-provider-delete-form]').forEach((form) => {
        const submitButton = form.querySelector('[data-provider-delete-submit]');
        if (!submitButton || typeof submitButton.removeAttribute !== 'function') return;

        submitButton.disabled = false;
        submitButton.removeAttribute('aria-disabled');
    });
}

export function initializeAiSourceProvidersIndex(root) {
    initializeProviderDeleteConfirmations(root);

    const ownerDocument = root.ownerDocument || document;
    const csrfToken = ownerDocument.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const enableInitializedButton = (button) => {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
    };

    const postTestRequest = async (url, payload, resultElement, button) => {
        if (!url || !resultElement || !button) return;

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = root.dataset.testingLabel || originalText;
        resultElement.textContent = '';
        resultElement.className = 'mt-2 text-xs text-gray-500';

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(payload),
            });
            const data = await response.json();

            if (response.ok && data.success) {
                const sourceCount = data.meta && typeof data.meta.source_count === 'number'
                    ? ` (${data.meta.source_count})`
                    : '';
                const structured = data.meta?.structured_output
                    ? ` ${JSON.stringify(data.meta.structured_output).slice(0, 180)}`
                    : sourceCount;
                resultElement.textContent = `${root.dataset.testSuccessPrefix || ''}${data.message || ''}${structured}`;
                resultElement.className = 'mt-2 break-all text-xs text-emerald-700';
            } else {
                resultElement.textContent = `${root.dataset.testFailedPrefix || ''}${data.message || response.statusText}`;
                resultElement.className = 'mt-2 break-all text-xs text-red-700';
            }
        } catch {
            resultElement.textContent = root.dataset.testNetworkError || '';
            resultElement.className = 'mt-2 break-all text-xs text-red-700';
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    };

    root.querySelectorAll('[data-provider-test]').forEach((button) => {
        const resultElement = ownerDocument.getElementById(button.dataset.resultTarget || '');
        if (!button.dataset.testUrl || !resultElement) {
            throw new Error('Provider connection test markup is incomplete.');
        }

        button.addEventListener('click', () => {
            void postTestRequest(
                button.dataset.testUrl,
                {},
                resultElement,
                button,
            );
        });
        enableInitializedButton(button);
    });

    root.querySelectorAll('[data-model-test]').forEach((button) => {
        const modelInput = ownerDocument.getElementById(button.dataset.modelInput || '');
        const resultElement = ownerDocument.getElementById(button.dataset.resultTarget || '');
        if (!root.dataset.modelTestUrl || !modelInput || !resultElement) {
            throw new Error('Model connection test markup is incomplete.');
        }

        button.addEventListener('click', () => {
            const modelId = Number(modelInput?.value || 0);

            if (modelId <= 0) {
                resultElement.textContent = button.dataset.emptyMessage || '';
                resultElement.className = 'mt-2 text-xs text-amber-700';
                return;
            }

            void postTestRequest(
                root.dataset.modelTestUrl,
                { binding_type: button.dataset.bindingType, model_id: modelId },
                resultElement,
                button,
            );
        });
        enableInitializedButton(button);
    });
}

if (typeof document !== 'undefined') {
    const root = document.querySelector('[data-ai-source-providers-index]');
    if (root) initializeAiSourceProvidersIndex(root);
}
