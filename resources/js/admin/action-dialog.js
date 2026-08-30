const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const TONE_ICONS = {
    danger: 'trash-2',
    error: 'circle-alert',
    info: 'info',
    success: 'circle-check',
    warning: 'triangle-alert',
};

const actionDialogState = {
    active: null,
    lastPointerAt: 0,
    noticeTimer: 0,
};

function visibleFocusableElements(element) {
    return Array.from(element.querySelectorAll(FOCUSABLE_SELECTOR))
        .filter((candidate) => !candidate.hidden && candidate.getAttribute('aria-hidden') !== 'true');
}

function setText(element, value) {
    if (!element) return;
    element.textContent = String(value ?? '');
}

function setOptionalText(element, value) {
    if (!element) return;
    const text = String(value ?? '').trim();
    element.hidden = text === '';
    element.textContent = text;
}

function refreshIcons(root) {
    if (globalThis.window?.GeoFlowAdminUi?.refreshIcons) {
        globalThis.window.GeoFlowAdminUi.refreshIcons(root);
        return;
    }

    globalThis.window?.lucide?.createIcons?.();
}

function iconForTone(tone) {
    return TONE_ICONS[tone] ?? TONE_ICONS.info;
}

function isSubmitControl(element, windowRef) {
    return element instanceof windowRef.HTMLButtonElement
        || element instanceof windowRef.HTMLInputElement;
}

function safeInternalUrl(value) {
    const url = String(value ?? '').trim();
    return url.startsWith('/')
        && !url.startsWith('//')
        && !/[\\\u0000-\u001F\u007F]/u.test(url)
        ? url
        : '';
}

export function initializeAdminActionDialog(root = document, windowRef = window) {
    const layer = root.querySelector('[data-admin-action-layer]');
    const dialog = layer?.querySelector('[data-admin-action-dialog]');
    if (!(dialog instanceof windowRef.HTMLDialogElement)) return null;

    const elements = {
        title: dialog.querySelector('[data-admin-action-title]'),
        message: dialog.querySelector('[data-admin-action-message]'),
        guidance: dialog.querySelector('[data-admin-action-guidance]'),
        icon: dialog.querySelector('[data-admin-action-icon]'),
        field: dialog.querySelector('[data-admin-action-field]'),
        fields: dialog.querySelector('[data-admin-action-fields]'),
        cancel: dialog.querySelector('[data-admin-action-cancel]'),
        confirm: dialog.querySelector('[data-admin-action-confirm]'),
    };

    if (Object.values(elements).some((element) => !element)) return null;

    const notice = root.querySelector('[data-admin-action-notice]');
    const noticeElements = notice ? {
        title: notice.querySelector('[data-admin-notice-title]'),
        message: notice.querySelector('[data-admin-notice-message]'),
        guidance: notice.querySelector('[data-admin-notice-guidance]'),
        action: notice.querySelector('[data-admin-notice-action]'),
        close: notice.querySelector('[data-admin-notice-close]'),
        icon: notice.querySelector('[data-admin-notice-icon]'),
    } : null;

    let current = null;
    let opener = null;
    let busy = false;
    let renderedFields = [];

    const setIcon = (target, name) => {
        if (!target) return;
        const icon = root.createElement('i');
        icon.setAttribute('data-lucide', name);
        icon.setAttribute('aria-hidden', 'true');
        target.replaceChildren(icon);
        refreshIcons(target);
    };

    const resetField = () => {
        elements.field.hidden = true;
        elements.fields.replaceChildren();
        renderedFields = [];
    };

    const renderFields = (options) => {
        const fieldOptions = Array.isArray(options.fields) && options.fields.length > 0
            ? options.fields
            : [{
                name: 'value',
                label: options.fieldLabel,
                help: options.fieldHelp,
                value: options.value,
                type: options.inputType,
                inputMode: options.inputMode,
                autocomplete: options.autocomplete,
                maxLength: options.maxLength,
                pattern: options.pattern,
                readOnly: options.readOnly,
                required: options.required,
                requiredMessage: options.requiredMessage,
                validate: options.validate,
            }];

        renderedFields = fieldOptions.map((spec, index) => {
            const group = root.createElement('div');
            const label = root.createElement('label');
            const input = root.createElement('input');
            const help = root.createElement('p');
            const error = root.createElement('p');
            const name = String(spec.name ?? `field_${index}`);
            const inputId = `admin-action-dialog-input-${index}`;
            const helpId = `${inputId}-help`;
            const errorId = `${inputId}-error`;

            group.className = 'admin-action-dialog__field-group';
            label.setAttribute('for', inputId);
            label.textContent = String(spec.label ?? '');
            input.id = inputId;
            input.name = name;
            input.type = spec.type ?? 'text';
            input.value = String(spec.value ?? '');
            input.readOnly = spec.readOnly === true;
            input.autocomplete = spec.autocomplete ?? 'off';
            if (spec.inputMode) input.inputMode = spec.inputMode;
            if (Number.isInteger(spec.maxLength) && spec.maxLength > 0) input.maxLength = spec.maxLength;
            if (spec.pattern) input.pattern = spec.pattern;
            help.id = helpId;
            help.dataset.adminActionFieldHelp = '';
            setOptionalText(help, spec.help);
            error.id = errorId;
            error.className = 'admin-action-dialog__field-error';
            error.dataset.adminActionFieldError = '';
            error.setAttribute('role', 'alert');
            error.hidden = true;
            if (!help.hidden) input.setAttribute('aria-describedby', helpId);
            input.setAttribute('aria-errormessage', errorId);
            group.append(label, input, help, error);
            elements.fields.append(group);
            input.addEventListener('input', () => {
                input.removeAttribute('aria-invalid');
                setOptionalText(error, '');
            });

            return { error, input, name, spec };
        });
    };

    const finish = (result) => {
        if (!current || busy) return;
        const resolve = current.resolve;
        current = null;
        actionDialogState.active = null;
        if (dialog.open) dialog.close();
        layer.hidden = true;
        root.body?.classList.remove('admin-action-dialog-open');
        const focusTarget = opener;
        opener = null;
        if (focusTarget?.isConnected !== false) focusTarget?.focus?.({ preventScroll: true });
        resolve(result);
    };

    const validatePrompt = () => {
        if (current?.kind !== 'prompt') return true;
        let firstInvalid = null;
        renderedFields.forEach(({ error, input, spec }) => {
            let validationMessage = '';
            if (spec.required && input.value.trim() === '') validationMessage = spec.requiredMessage ?? current.options.requiredMessage ?? '';
            else if (spec.pattern && input.value !== '') {
                try {
                    if (!new RegExp(`^(?:${spec.pattern})$`, 'u').test(input.value)) validationMessage = spec.patternMessage ?? spec.requiredMessage ?? current.options.requiredMessage ?? '';
                } catch {
                    validationMessage = spec.patternMessage ?? spec.requiredMessage ?? current.options.requiredMessage ?? '';
                }
            }
            if (validationMessage === '' && typeof spec.validate === 'function') {
                validationMessage = spec.validate(input.value) ?? '';
            }
            setOptionalText(error, validationMessage);
            if (validationMessage) {
                input.setAttribute('aria-invalid', 'true');
                firstInvalid ??= input;
            } else {
                input.removeAttribute('aria-invalid');
            }
        });
        if (!firstInvalid) return true;
        firstInvalid.focus({ preventScroll: true });
        return false;
    };

    const open = (kind, options = {}) => new Promise((resolve) => {
        const nextOpener = options.opener ?? opener ?? root.activeElement;
        if (current) {
            const previous = current;
            current = null;
            if (dialog.open) dialog.close();
            layer.hidden = true;
            previous.resolve(previous.kind === 'confirm' ? false : null);
        }
        current = { kind, options, resolve };
        actionDialogState.active = current;
        opener = nextOpener;
        busy = false;
        resetField();

        const tone = options.tone ?? (kind === 'alert' ? 'error' : 'info');
        dialog.dataset.tone = tone;
        dialog.setAttribute('role', kind === 'prompt' ? 'dialog' : 'alertdialog');
        dialog.classList.toggle('is-pointer-open', options.animate ?? (Date.now() - actionDialogState.lastPointerAt < 800));
        setText(elements.title, options.title ?? '');
        setText(elements.message, options.message ?? '');
        setOptionalText(elements.guidance, options.guidance);
        setIcon(elements.icon, iconForTone(tone));

        const isAlert = kind === 'alert';
        elements.cancel.hidden = isAlert || options.showCancel === false;
        setText(elements.cancel, options.cancelLabel ?? layer.dataset.cancelLabel ?? 'Cancel');
        setText(elements.confirm, options.confirmLabel ?? (isAlert ? layer.dataset.closeLabel : layer.dataset.confirmLabel));

        if (kind === 'prompt') {
            elements.field.hidden = false;
            renderFields(options);
        }

        layer.hidden = false;
        root.body?.classList.add('admin-action-dialog-open');
        dialog.showModal();
        windowRef.requestAnimationFrame?.(() => {
            if (kind === 'prompt') {
                const firstInput = renderedFields[0]?.input;
                firstInput?.focus({ preventScroll: true });
                if (firstInput?.readOnly) firstInput.select?.();
            }
            else if (tone === 'danger' || tone === 'warning') elements.cancel.focus({ preventScroll: true });
            else if (isAlert) elements.title.focus({ preventScroll: true });
            else elements.confirm.focus({ preventScroll: true });
        });
    });

    const confirm = (options = {}) => open('confirm', options);
    const alert = async (options = {}) => { await open('alert', options); };
    const prompt = (options = {}) => open('prompt', options);

    const closeNotice = () => {
        windowRef.clearTimeout(actionDialogState.noticeTimer);
        actionDialogState.noticeTimer = 0;
        if (notice) notice.hidden = true;
    };

    const showNotice = (options = {}) => {
        if (!notice || !noticeElements) return;
        const tone = options.tone ?? 'success';
        notice.dataset.tone = tone;
        notice.setAttribute('role', tone === 'error' || tone === 'danger' ? 'alert' : 'status');
        const defaultTitle = tone === 'error' || tone === 'danger'
            ? layer.dataset.errorTitle
            : (tone === 'success' ? layer.dataset.successTitle : layer.dataset.infoTitle);
        setText(noticeElements.title, options.title ?? defaultTitle ?? '');
        setText(noticeElements.message, options.message ?? '');
        setOptionalText(noticeElements.guidance, options.guidance);
        setIcon(noticeElements.icon, iconForTone(tone));

        const actionUrl = safeInternalUrl(options.actionUrl ?? options.action_url);
        const actionLabel = String(options.actionLabel ?? options.action_label ?? '').trim();
        noticeElements.action.hidden = actionUrl === '' || actionLabel === '';
        if (!noticeElements.action.hidden) {
            noticeElements.action.href = actionUrl;
            noticeElements.action.textContent = actionLabel;
        } else {
            noticeElements.action.removeAttribute('href');
            noticeElements.action.textContent = '';
        }

        notice.hidden = false;
        windowRef.clearTimeout(actionDialogState.noticeTimer);
        const duration = Number(options.duration ?? (tone === 'info' ? 5000 : 4000));
        if (duration > 0) actionDialogState.noticeTimer = windowRef.setTimeout(closeNotice, duration);
    };

    elements.cancel.addEventListener('click', () => finish(current?.kind === 'confirm' ? false : null));
    elements.confirm.addEventListener('click', () => {
        if (!current || busy) return;
        if (current.kind === 'prompt') {
            if (!validatePrompt()) return;
            const values = Object.fromEntries(renderedFields.map(({ input, name }) => [name, input.value]));
            finish(Array.isArray(current.options.fields) ? values : values.value);
            return;
        }
        finish(current.kind === 'confirm');
    });
    elements.fields.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.target?.readOnly) return;
        event.preventDefault();
        elements.confirm.click();
    });
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (busy) return;
        finish(current?.kind === 'confirm' ? false : null);
    });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog || current?.kind !== 'confirm' || busy) return;
        const bounds = dialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right
            && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        if (!inside) finish(false);
    });
    dialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        const focusable = visibleFocusableElements(dialog);
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && root.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && root.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    noticeElements?.close?.addEventListener('click', closeNotice);
    notice?.addEventListener('mouseenter', () => windowRef.clearTimeout(actionDialogState.noticeTimer));
    notice?.addEventListener('mouseleave', () => {
        if (!notice.hidden) actionDialogState.noticeTimer = windowRef.setTimeout(closeNotice, 1800);
    });
    notice?.addEventListener('focusin', () => windowRef.clearTimeout(actionDialogState.noticeTimer));
    notice?.addEventListener('focusout', () => {
        if (!notice.hidden) actionDialogState.noticeTimer = windowRef.setTimeout(closeNotice, 1800);
    });

    const bypassForms = new WeakSet();
    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof windowRef.HTMLFormElement) || !form.hasAttribute('data-admin-confirm-form')) return;
        if (bypassForms.has(form)) {
            bypassForms.delete(form);
            form.setAttribute('aria-busy', 'true');
            windowRef.queueMicrotask?.(() => {
                if (event.defaultPrevented) {
                    form.removeAttribute('aria-busy');
                    return;
                }
                if (isSubmitControl(event.submitter, windowRef)) {
                    event.submitter.disabled = true;
                    event.submitter.setAttribute('data-admin-confirm-pending-submit', '');
                }
            });
            return;
        }

        event.preventDefault();
        const submitter = event.submitter instanceof windowRef.HTMLElement
            ? event.submitter
            : form.querySelector('[type="submit"]');
        const accepted = await confirm({
            title: form.dataset.adminConfirmTitle ?? '',
            message: form.dataset.adminConfirmMessage ?? '',
            guidance: form.dataset.adminConfirmGuidance ?? '',
            tone: form.dataset.adminConfirmTone ?? 'danger',
            confirmLabel: form.dataset.adminConfirmLabel ?? '',
            cancelLabel: form.dataset.adminConfirmCancelLabel ?? layer.dataset.cancelLabel,
            opener: submitter,
        });
        if (!accepted || !form.isConnected) return;
        bypassForms.add(form);
        form.requestSubmit(isSubmitControl(submitter, windowRef) ? submitter : undefined);
    }, true);

    root.querySelectorAll('[data-admin-confirm-submit][disabled]').forEach((button) => {
        const form = button.form ?? button.closest?.('form');
        if (form?.hasAttribute('data-admin-confirm-form')) {
            button.disabled = false;
            button.removeAttribute('aria-disabled');
        }
    });

    root.documentElement?.setAttribute('data-admin-action-ready', 'true');
    root.addEventListener('pointerdown', () => { actionDialogState.lastPointerAt = Date.now(); }, true);
    windowRef.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        root.querySelectorAll('[data-admin-confirm-form][aria-busy="true"]').forEach((form) => form.removeAttribute('aria-busy'));
        root.querySelectorAll('[data-admin-confirm-pending-submit]').forEach((button) => {
            button.disabled = false;
            button.removeAttribute('data-admin-confirm-pending-submit');
        });
    });

    const api = { alert, confirm, notice: showNotice, prompt };
    windowRef.AdminActionDialog = api;
    windowRef.GeoFlowAdminUi = {
        ...(windowRef.GeoFlowAdminUi ?? {}),
        actionDialog: api,
        showToast(message, tone = 'success') {
            showNotice({ tone, message });
        },
    };

    const initialNotice = root.querySelector('[data-admin-action-initial-notice]');
    if (initialNotice) {
        try {
            showNotice(JSON.parse(initialNotice.textContent || '{}'));
        } catch {
            // Ignore malformed server feedback and keep the page usable.
        }
    }

    return api;
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    initializeAdminActionDialog(document, window);
}
