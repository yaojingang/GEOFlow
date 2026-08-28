function setDestructiveButtonEnabled(button, enabled) {
    button.disabled = !enabled;
    if (enabled) button.removeAttribute('aria-disabled');
    else button.setAttribute('aria-disabled', 'true');
}

function confirmed(confirmAction, message) {
    if (!message) return false;

    try {
        return confirmAction(message) === true;
    } catch {
        return false;
    }
}

export function initializeConfirmedLibraryActions(
    root,
    confirmAction = (message) => window.confirm(message),
) {
    root.querySelectorAll('[data-library-confirm-form]').forEach((form) => {
        const submitButton = form.querySelector('[data-library-detail-destructive-submit]');
        if (!submitButton) throw new Error('Confirmed library action markup is incomplete.');

        form.addEventListener('submit', (event) => {
            if (!confirmed(confirmAction, form.dataset.confirmMessage || '')) event.preventDefault();
        });
        setDestructiveButtonEnabled(submitButton, true);
    });
}

export function initializeKeywordBatchActions(
    root,
    confirmAction = (message) => window.confirm(message),
) {
    const form = root.querySelector('[data-keyword-batch-form]');
    if (!form) return;

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
        const count = selectedCount();
        const message = (form.dataset.confirmTemplate || '').replace('{count}', String(count));
        if (count === 0 || !confirmed(confirmAction, message)) event.preventDefault();
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

export function initializeLibraryDetailActions(
    root,
    confirmAction = (message) => window.confirm(message),
) {
    initializeConfirmedLibraryActions(root, confirmAction);
    initializeKeywordBatchActions(root, confirmAction);
}
