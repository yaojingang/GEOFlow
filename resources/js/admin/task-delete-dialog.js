export function initializeTaskDeleteDialog(root = document) {
    const dialog = root.querySelector('[data-task-delete-dialog]');
    const taskName = dialog?.querySelector('[data-task-delete-name]');
    const cancelButton = dialog?.querySelector('[data-task-delete-cancel]');
    const confirmButton = dialog?.querySelector('[data-task-delete-confirm]');
    const confirmLabel = dialog?.querySelector('[data-task-delete-confirm-label]');

    if (!(dialog instanceof HTMLDialogElement) || !taskName || !cancelButton || !confirmButton || !confirmLabel) {
        return;
    }

    let pendingForm = null;
    const defaultConfirmLabel = confirmLabel.textContent;

    const resetDialog = () => {
        pendingForm = null;
        confirmButton.disabled = false;
        confirmButton.removeAttribute('aria-busy');
        confirmLabel.textContent = defaultConfirmLabel;
    };

    const closeDialog = () => {
        if (dialog.open) dialog.close();
        resetDialog();
    };

    const openDialog = (form) => {
        pendingForm = form;
        taskName.textContent = form.dataset.taskName || '';
        dialog.showModal();

        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && typeof dialog.animate === 'function') {
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

    root.querySelectorAll('[data-task-delete-form]').forEach((form) => {
        form.querySelector('[data-task-delete-trigger]')?.addEventListener('click', () => openDialog(form));
        form.addEventListener('submit', (event) => {
            if (form.dataset.deleteConfirmed === 'true') return;

            event.preventDefault();
            openDialog(form);
        });
    });

    cancelButton.addEventListener('click', closeDialog);
    dialog.addEventListener('cancel', resetDialog);
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;

        const bounds = dialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;

        if (!inside) closeDialog();
    });
    confirmButton.addEventListener('click', () => {
        if (!(pendingForm instanceof HTMLFormElement)) return;

        const submitButton = pendingForm.querySelector('[data-task-delete-submit]');
        if (!(submitButton instanceof HTMLButtonElement)) return;

        confirmButton.disabled = true;
        confirmButton.setAttribute('aria-busy', 'true');
        confirmLabel.textContent = dialog.dataset.deletingLabel || defaultConfirmLabel;
        pendingForm.dataset.deleteConfirmed = 'true';
        pendingForm.requestSubmit(submitButton);
    });
}

if (typeof document !== 'undefined') initializeTaskDeleteDialog(document);
