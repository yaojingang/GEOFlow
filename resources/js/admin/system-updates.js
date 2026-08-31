export function updaterReloadDelay(value) {
    const delay = Number.parseInt(String(value), 10);

    return Number.isInteger(delay) && delay >= 1000 && delay <= 60000 ? delay : null;
}

export function initializeSystemUpdaterAutoReload(
    root = document,
    schedule = window.setTimeout.bind(window),
    reload = () => window.location.reload(),
) {
    const element = root.querySelector('[data-system-updater-auto-reload]');
    const delay = updaterReloadDelay(element?.dataset.systemUpdaterAutoReload);
    if (delay === null) {
        return null;
    }

    return schedule(reload, delay);
}

async function writeSystemUpdaterClipboard(value) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);

        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        if (!document.execCommand('copy')) {
            throw new Error('Clipboard copy was rejected.');
        }
    } finally {
        textarea.remove();
    }
}

export async function copySystemUpdaterCommand(
    button,
    root = document,
    writeText = writeSystemUpdaterClipboard,
) {
    const selector = button?.dataset?.systemUpdaterCopy;
    const target = typeof selector === 'string' ? root.querySelector(selector) : null;
    const value = target?.textContent?.trim();
    if (!value) {
        return false;
    }

    await writeText(value);
    const label = button.querySelector('[data-system-updater-copy-label]');
    if (label && button.dataset.copiedLabel) {
        label.textContent = button.dataset.copiedLabel;
    }

    return true;
}

export function initializeSystemUpdaterCopy(root = document) {
    root.addEventListener('click', async (event) => {
        const origin = event.target;
        const button = origin instanceof Element
            ? origin.closest('[data-system-updater-copy]')
            : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        try {
            await copySystemUpdaterCommand(button, root);
        } catch {
            const label = button.querySelector('[data-system-updater-copy-label]');
            if (label && button.dataset.copyFailedLabel) {
                label.textContent = button.dataset.copyFailedLabel;
            }
        }
    });
}

export function initializeSystemUpdaterErrorDialog(root = document) {
    const dialog = root.querySelector('[data-system-updater-error-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function' || typeof dialog.close !== 'function') {
        return null;
    }

    const close = () => {
        if (dialog.open) {
            dialog.close();
        }
    };
    const closeButtons = Array.from(dialog.querySelectorAll('[data-system-updater-error-close]'));

    if (dialog.open) {
        dialog.close();
    }
    dialog.showModal();
    closeButtons[0]?.focus();

    return { close };
}

export function initializeSystemUpdaterAuthorizationDialogs(root = document, windowRef = window) {
    const bypass = new WeakSet();
    const isSubmitControl = (element) => element instanceof windowRef.HTMLButtonElement
        || element instanceof windowRef.HTMLInputElement;

    root.addEventListener('submit', async (event) => {
        const form = event.target instanceof windowRef.Element
            ? event.target.closest('[data-system-updater-authorized-action]')
            : null;
        if (!(form instanceof windowRef.HTMLFormElement)) return;
        if (bypass.has(form)) {
            bypass.delete(form);
            return;
        }

        event.preventDefault();
        const authorizationInput = form.querySelector('input[name="updater_authorization_code"]');
        const passwordInput = form.querySelector('input[name="current_admin_password"]');
        if (!(authorizationInput instanceof windowRef.HTMLInputElement)) return;

        const fields = [{
            name: 'authorization',
            label: form.dataset.authorizationLabel ?? '',
            type: 'text',
            inputMode: 'numeric',
            autocomplete: 'one-time-code',
            maxLength: 6,
            pattern: '[0-9]{6}',
            required: true,
            requiredMessage: form.dataset.requiredMessage ?? '',
            patternMessage: form.dataset.authorizationPatternMessage ?? '',
        }];
        if (form.dataset.passwordRequired === 'true') {
            fields.push({
                name: 'password',
                label: form.dataset.passwordLabel ?? '',
                type: 'password',
                autocomplete: 'current-password',
                required: true,
                requiredMessage: form.dataset.requiredMessage ?? '',
            });
        }

        const values = await windowRef.AdminActionDialog?.prompt?.({
            title: form.dataset.dialogTitle ?? '',
            message: form.dataset.dialogMessage ?? '',
            guidance: form.dataset.dialogGuidance ?? '',
            tone: form.dataset.dialogTone ?? 'warning',
            confirmLabel: form.dataset.dialogConfirmLabel ?? '',
            opener: event.submitter,
            requiredMessage: form.dataset.requiredMessage ?? '',
            fields,
        });
        if (!values || typeof values !== 'object') return;

        authorizationInput.value = String(values.authorization ?? '');
        if (passwordInput instanceof windowRef.HTMLInputElement) passwordInput.value = String(values.password ?? '');
        bypass.add(form);
        form.requestSubmit(isSubmitControl(event.submitter) ? event.submitter : undefined);
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    initializeSystemUpdaterAutoReload();
    initializeSystemUpdaterCopy();
    initializeSystemUpdaterErrorDialog();
    initializeSystemUpdaterAuthorizationDialogs();
}
