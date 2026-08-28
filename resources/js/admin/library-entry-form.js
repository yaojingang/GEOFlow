export function initializeLibraryEntryForm(
    form,
    pageTarget = typeof window !== 'undefined' ? window : null,
) {
    if (!form) return;

    const submitButton = form.querySelector('[data-library-entry-submit]');
    const submitLabel = form.querySelector('[data-library-entry-submit-label]');
    const status = form.querySelector('[data-library-entry-status]');
    const processingLabel = form.dataset.processingLabel ?? '';
    const initialSubmitLabel = submitLabel?.textContent ?? '';
    let submitting = false;

    const restore = () => {
        submitting = false;
        form.removeAttribute('aria-busy');
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.removeAttribute('aria-disabled');
        }
        if (submitLabel) submitLabel.textContent = initialSubmitLabel;
        if (status) status.textContent = '';
    };

    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (submitting) {
            event.preventDefault();

            return;
        }

        submitting = true;
        form.setAttribute('aria-busy', 'true');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.setAttribute('aria-disabled', 'true');
        }
        if (submitLabel && processingLabel !== '') submitLabel.textContent = processingLabel;
        if (status) status.textContent = processingLabel;
    });
    pageTarget?.addEventListener('pageshow', (event) => {
        if (event.persisted) restore();
    });

    return { restore };
}

export function initializeLibraryEntryForms(
    root,
    pageTarget = typeof window !== 'undefined' ? window : null,
) {
    if (!root) return;

    root.querySelectorAll('[data-library-entry-form]').forEach((form) => {
        initializeLibraryEntryForm(form, pageTarget);
    });
}

if (typeof document !== 'undefined') {
    initializeLibraryEntryForms(document);
}
