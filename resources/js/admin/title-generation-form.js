const toPositiveInteger = (value) => Math.max(0, Number.parseInt(String(value ?? 0), 10) || 0);

export function requiresKeywordReuseConfirmation(titleCount, keywordCount) {
    const normalizedTitleCount = toPositiveInteger(titleCount);
    const normalizedKeywordCount = toPositiveInteger(keywordCount);

    return normalizedKeywordCount > 0 && normalizedTitleCount > normalizedKeywordCount;
}

export function initializeTitleGenerationForm(root = document, dependencies = {}) {
    const form = root.querySelector('[data-title-generation-form]');
    if (!form) return null;

    const keywordSelect = form.querySelector('[name="keyword_library_id"]');
    const titleCountInput = form.querySelector('[name="title_count"]');
    const confirmationInput = form.querySelector('[data-keyword-reuse-confirmed]');
    const submitButton = form.querySelector('[data-title-generation-submit]');
    const actionDialog = dependencies.actionDialog ?? globalThis.window?.AdminActionDialog;
    if (!keywordSelect || !titleCountInput || !confirmationInput || !actionDialog?.confirm) {
        return null;
    }
    if (submitButton) {
        submitButton.disabled = false;
        submitButton.removeAttribute?.('aria-disabled');
    }

    let pendingSubmitter = null;
    let submitting = false;
    let confirming = false;

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
    };

    const open = async (submitter) => {
        if (confirming || submitting) return false;
        confirming = true;
        const titleCount = toPositiveInteger(titleCountInput.value);
        const keywordCount = selectedKeywordCount();
        pendingSubmitter = submitter || submitButton || null;
        const summary = String(form.dataset.keywordReuseSummaryTemplate || '')
            .replaceAll('__TITLE_COUNT__', titleCount.toLocaleString())
            .replaceAll('__KEYWORD_COUNT__', keywordCount.toLocaleString());
        const confirmed = await actionDialog.confirm({
            title: form.dataset.keywordReuseTitle || '',
            message: summary,
            guidance: form.dataset.keywordReuseGuidance || '',
            tone: 'warning',
            confirmLabel: form.dataset.keywordReuseConfirmLabel || '',
            cancelLabel: form.dataset.keywordReuseCancelLabel || '',
            opener: pendingSubmitter,
        });
        confirming = false;
        if (confirmed !== true || submitting) {
            pendingSubmitter = null;
            return false;
        }

        confirmationInput.value = '1';
        const confirmedSubmitter = pendingSubmitter;
        pendingSubmitter = null;
        form.requestSubmit(confirmedSubmitter || undefined);
        return true;
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
        void open(event.submitter);
    });

    return { open, resetConfirmation };
}

if (typeof document !== 'undefined') initializeTitleGenerationForm(document);
