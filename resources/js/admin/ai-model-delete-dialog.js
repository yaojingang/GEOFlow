export function initializeAiModelDeleteDialog(root = document) {
    const dialog = root.querySelector('[data-ai-model-delete-dialog]');
    const modelName = dialog?.querySelector('[data-ai-model-delete-name]');
    const editLink = dialog?.querySelector('[data-ai-model-delete-edit]');
    const cancelButton = dialog?.querySelector('[data-ai-model-delete-cancel]');
    const confirmButton = dialog?.querySelector('[data-ai-model-delete-confirm]');
    const confirmLabel = dialog?.querySelector('[data-ai-model-delete-confirm-label]');

    if (!(dialog instanceof HTMLDialogElement) || !modelName || !editLink || !cancelButton || !confirmButton || !confirmLabel) {
        return;
    }

    let pendingForm = null;
    let opener = null;
    const defaultConfirmLabel = confirmLabel.textContent;

    const resetDialog = () => {
        pendingForm = null;
        modelName.textContent = '';
        editLink.removeAttribute('href');
        confirmButton.disabled = false;
        confirmButton.removeAttribute('aria-busy');
        confirmLabel.textContent = defaultConfirmLabel;
    };

    const restoreFocus = () => {
        if (opener instanceof HTMLElement) opener.focus({ preventScroll: true });
        opener = null;
    };

    const closeDialog = () => {
        if (dialog.open) dialog.close();
    };

    const openDialog = (form, animate) => {
        pendingForm = form;
        opener = document.activeElement;
        modelName.textContent = form.dataset.modelName || '';
        editLink.setAttribute('href', form.dataset.modelEditUrl || '#');
        dialog.showModal();

        if (animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches && typeof dialog.animate === 'function') {
            dialog.animate(
                [
                    { opacity: 0, transform: 'translateY(8px) scale(.98)' },
                    { opacity: 1, transform: 'translateY(0) scale(1)' },
                ],
                { duration: 180, easing: 'cubic-bezier(.16,1,.3,1)' },
            );
        }

        cancelButton.focus({ preventScroll: true });
    };

    root.querySelectorAll('[data-ai-model-delete-form]').forEach((form) => {
        form.querySelector('[data-ai-model-delete-trigger]')?.addEventListener('click', (event) => {
            openDialog(form, event.detail !== 0);
        });
        form.addEventListener('submit', (event) => {
            if (form.dataset.deleteConfirmed === 'true') return;

            event.preventDefault();
            openDialog(form, false);
        });
    });

    cancelButton.addEventListener('click', closeDialog);
    dialog.addEventListener('close', () => {
        resetDialog();
        restoreFocus();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;

        const bounds = dialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;

        if (!inside) closeDialog();
    });
    confirmButton.addEventListener('click', () => {
        if (!(pendingForm instanceof HTMLFormElement)) return;

        const submitButton = pendingForm.querySelector('[data-ai-model-delete-submit]');
        if (!(submitButton instanceof HTMLButtonElement)) return;

        confirmButton.disabled = true;
        confirmButton.setAttribute('aria-busy', 'true');
        confirmLabel.textContent = dialog.dataset.deletingLabel || defaultConfirmLabel;
        pendingForm.dataset.deleteConfirmed = 'true';
        pendingForm.requestSubmit(submitButton);
    });
}

if (typeof document !== 'undefined') initializeAiModelDeleteDialog(document);
