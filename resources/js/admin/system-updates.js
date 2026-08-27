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

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    initializeSystemUpdaterAutoReload();
    initializeSystemUpdaterCopy();
}
