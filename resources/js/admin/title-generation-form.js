const toPositiveInteger = (value) => Math.max(0, Number.parseInt(String(value ?? 0), 10) || 0);

export function requiresKeywordReuseConfirmation(titleCount, keywordCount) {
    const normalizedTitleCount = toPositiveInteger(titleCount);
    const normalizedKeywordCount = toPositiveInteger(keywordCount);

    return normalizedKeywordCount > 0 && normalizedTitleCount > normalizedKeywordCount;
}

export function initializeTitleGenerationForm(root = document) {
    const form = root.querySelector('[data-title-generation-form]');
    const dialog = root.querySelector('[data-keyword-reuse-dialog]');
    if (!form || !dialog) return null;

    const keywordSelect = form.querySelector('[name="keyword_library_id"]');
    const titleCountInput = form.querySelector('[name="title_count"]');
    const confirmationInput = form.querySelector('[data-keyword-reuse-confirmed]');
    const submitButton = form.querySelector('[data-title-generation-submit]');
    const summary = dialog.querySelector('[data-keyword-reuse-summary]');
    const cancelButton = dialog.querySelector('[data-keyword-reuse-cancel]');
    const confirmButton = dialog.querySelector('[data-keyword-reuse-confirm]');
    if (!keywordSelect || !titleCountInput || !confirmationInput || !summary || !cancelButton || !confirmButton) {
        return null;
    }

    let pendingSubmitter = null;
    let submitting = false;

    const selectedKeywordCount = () => {
        const selectedOption = keywordSelect.selectedOptions?.[0]
            ?? keywordSelect.options?.[keywordSelect.selectedIndex]
            ?? null;

        return toPositiveInteger(selectedOption?.dataset?.keywordCount);
    };

    const resetConfirmation = () => {
        confirmationInput.value = '0';
    };

    const beginSubmission = () => {
        submitting = true;
        form.setAttribute?.('aria-busy', 'true');
        if (submitButton) submitButton.disabled = true;
        confirmButton.disabled = true;
    };

    const close = () => {
        if (dialog.open) dialog.close('cancel');
    };

    const open = (submitter) => {
        const titleCount = toPositiveInteger(titleCountInput.value);
        const keywordCount = selectedKeywordCount();
        pendingSubmitter = submitter || submitButton || null;
        dialog.returnValue = '';
        summary.textContent = String(dialog.dataset.summaryTemplate || '')
            .replaceAll('__TITLE_COUNT__', titleCount.toLocaleString())
            .replaceAll('__KEYWORD_COUNT__', keywordCount.toLocaleString());
        if (!dialog.open) dialog.showModal();
        cancelButton.focus({ preventScroll: true });
    };

    keywordSelect.addEventListener('change', resetConfirmation);
    titleCountInput.addEventListener('input', resetConfirmation);
    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        const needsConfirmation = requiresKeywordReuseConfirmation(
            titleCountInput.value,
            selectedKeywordCount(),
        );
        if (!needsConfirmation || confirmationInput.value === '1') {
            beginSubmission();
            return;
        }

        event.preventDefault();
        open(event.submitter);

        return;
    });

    cancelButton.addEventListener('click', close);
    confirmButton.addEventListener('click', () => {
        if (submitting) return;

        confirmationInput.value = '1';
        const submitter = pendingSubmitter;
        if (dialog.open) dialog.close('confirm');
        form.requestSubmit(submitter || undefined);
    });
    dialog.addEventListener('close', () => {
        const submitter = pendingSubmitter;
        pendingSubmitter = null;
        if (dialog.returnValue !== 'confirm') submitter?.focus?.({ preventScroll: true });
    });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        if (!inside) close();
    });

    return { close, open, resetConfirmation };
}

if (typeof document !== 'undefined') initializeTitleGenerationForm(document);
