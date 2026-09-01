function setDestructiveButtonEnabled(button, enabled) {
    button.disabled = !enabled;
    if (enabled) button.removeAttribute('aria-disabled');
    else button.setAttribute('aria-disabled', 'true');
}

export function initializeConfirmedLibraryActions(root, actionDialog = globalThis.window?.AdminActionDialog) {
    const controllerReady = typeof actionDialog === 'function' || typeof actionDialog?.confirm === 'function';
    if (!controllerReady) return;
    root.querySelectorAll('[data-library-confirm-form]').forEach((form) => {
        const submitButton = form.querySelector('[data-library-detail-destructive-submit]');
        if (!submitButton) throw new Error('Confirmed library action markup is incomplete.');

        setDestructiveButtonEnabled(submitButton, true);
    });
}

export function initializeKeywordBatchActions(root, actionDialog = globalThis.window?.AdminActionDialog) {
    const form = root.querySelector('[data-keyword-batch-form]');
    if (!form) return;

    const controllerReady = typeof actionDialog === 'function' || typeof actionDialog?.confirm === 'function';
    if (!controllerReady) return;

    const panel = root.querySelector('[data-keyword-batch-panel]');
    const submitButton = form.querySelector('[data-keyword-batch-submit]');
    const counter = form.querySelector('[data-keyword-batch-count]');
    const toggles = Array.from(root.querySelectorAll('[data-keyword-batch-toggle]'));
    const checkboxes = Array.from(root.querySelectorAll('[data-keyword-batch-checkbox]'));
    if (!panel || !submitButton || !counter || toggles.length === 0 || checkboxes.length === 0) {
        throw new Error('Keyword batch action markup is incomplete.');
    }

    const selectedCount = () => checkboxes.filter((checkbox) => checkbox.checked).length;
    const updateSelection = () => {
        const count = selectedCount();
        counter.textContent = (form.dataset.selectedTemplate || '{count}').replace('{count}', String(count));
        form.dataset.adminConfirmTitle = (form.dataset.confirmTemplate || '').replace('{count}', String(count));
        setDestructiveButtonEnabled(submitButton, count > 0);
    };
    const closePanel = () => {
        panel.classList.toggle('hidden', true);
        checkboxes.forEach((checkbox) => {
            checkbox.checked = false;
            checkbox.classList.toggle('hidden', true);
        });
        updateSelection();
    };

    form.addEventListener('submit', (event) => {
        if (selectedCount() === 0) event.preventDefault();
    });
    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const opening = panel.classList.contains('hidden');
            if (!opening) {
                closePanel();
                return;
            }

            panel.classList.toggle('hidden', false);
            checkboxes.forEach((checkbox) => checkbox.classList.toggle('hidden', false));
        });
    });
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelection));
    updateSelection();
}

export function initializeLibraryDetailActions(root, actionDialog = globalThis.window?.AdminActionDialog) {
    initializeConfirmedLibraryActions(root, actionDialog);
    initializeKeywordBatchActions(root, actionDialog);
}
