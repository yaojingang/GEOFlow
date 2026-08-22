import QRCode from 'qrcode';
import { enhanceFormAccessibility } from './form-accessibility';

const SHELL_SELECTOR = '[data-gf-shell]';
const SIDEBAR_STORAGE_KEY = 'geoflow.admin.ui-v3.sidebar-collapsed';

function runtimeConfig() {
    const element = document.querySelector('#geoflow-runtime-config');
    if (!element) return {};

    try {
        return JSON.parse(element.textContent ?? '{}');
    } catch {
        return {};
    }
}

function refreshIcons() {
    window.lucide?.createIcons?.();
}

function showToast(message) {
    const toast = document.querySelector('[data-gf-toast]');
    if (!toast || !message) return;

    toast.textContent = message;
    toast.classList.add('is-visible');
    window.clearTimeout(showToast.timeoutId);
    showToast.timeoutId = window.setTimeout(() => toast.classList.remove('is-visible'), 2200);
}

showToast.timeoutId = 0;

function setupSidebar() {
    const shell = document.querySelector(SHELL_SELECTOR);
    if (!shell) return;

    const body = document.body;
    const collapseButton = document.querySelector('[data-sidebar-collapse]');
    const applyCollapsedState = (collapsed) => {
        body.classList.toggle('gf-sidebar-collapsed', collapsed);
        collapseButton?.setAttribute('aria-expanded', String(!collapsed));
        try {
            window.localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? '1' : '0');
        } catch {
            // The layout remains functional when browser storage is unavailable.
        }
    };

    let storedCollapsed = false;
    try {
        storedCollapsed = window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1';
    } catch {
        storedCollapsed = false;
    }
    applyCollapsedState(storedCollapsed);
    collapseButton?.addEventListener('click', () => applyCollapsedState(!body.classList.contains('gf-sidebar-collapsed')));
    document.querySelectorAll('[data-sidebar-open]').forEach((button) => button.addEventListener('click', () => body.classList.add('gf-sidebar-open')));
    document.querySelectorAll('[data-sidebar-close]').forEach((button) => button.addEventListener('click', () => body.classList.remove('gf-sidebar-open')));
    document.querySelectorAll('.gf-sidebar a').forEach((link) => link.addEventListener('click', () => body.classList.remove('gf-sidebar-open')));
}

function closePopovers(except = null) {
    document.querySelectorAll('[data-popover]').forEach((popover) => {
        if (popover === except) return;
        popover.hidden = true;
        const name = popover.dataset.popover;
        document.querySelector(`[data-popover-button="${CSS.escape(name)}"]`)?.setAttribute('aria-expanded', 'false');
    });
}

function setupPopovers() {
    document.querySelectorAll('[data-popover-button]').forEach((button) => {
        const name = button.dataset.popoverButton;
        const popover = document.querySelector(`[data-popover="${CSS.escape(name)}"]`);
        if (!popover) return;

        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-haspopup', 'true');
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const shouldOpen = popover.hidden;
            closePopovers(shouldOpen ? popover : null);
            popover.hidden = !shouldOpen;
            button.setAttribute('aria-expanded', String(shouldOpen));
        });
        popover.addEventListener('click', (event) => event.stopPropagation());
    });

    document.addEventListener('click', () => closePopovers());
}

let activeModal = null;
let modalOpener = null;
const modalCloseTimers = new WeakMap();
const modalOpenFrames = new WeakMap();

function focusableElements(modal) {
    return [...modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((element) => !element.hidden && element.getClientRects().length > 0);
}

function closeModal() {
    if (!activeModal) return;
    const modal = activeModal;
    const pendingOpen = modalOpenFrames.get(modal);
    if (pendingOpen) {
        window.cancelAnimationFrame(pendingOpen);
        modalOpenFrames.delete(modal);
    }
    const pendingClose = modalCloseTimers.get(modal);
    if (pendingClose) window.clearTimeout(pendingClose);
    modal.classList.remove('is-open');
    document.body.classList.remove('gf-modal-open');
    const closeTimer = window.setTimeout(() => {
        modalCloseTimers.delete(modal);
        if (modal.classList.contains('is-open')) return;
        modal.hidden = true;
        if (activeModal === modal) activeModal = null;
    }, 170);
    modalCloseTimers.set(modal, closeTimer);
    modalOpener?.focus();
    modalOpener = null;
}

async function renderQrCode(modal) {
    const canvas = modal.querySelector('[data-qr-canvas]');
    const value = modal.dataset.qrValue;
    if (!canvas || !value || canvas.dataset.rendered === 'true') return;

    try {
        await QRCode.toCanvas(canvas, value, {
            width: 132,
            margin: 1,
            color: { dark: '#111827', light: '#ffffff' },
            errorCorrectionLevel: 'M',
        });
        canvas.dataset.rendered = 'true';
    } catch {
        canvas.setAttribute('aria-label', value);
    }
}

function openModal(name, opener) {
    const modal = document.querySelector(`[data-gf-modal="${CSS.escape(name)}"]`);
    if (!modal) return;

    const pendingOpen = modalOpenFrames.get(modal);
    if (pendingOpen) {
        window.cancelAnimationFrame(pendingOpen);
        modalOpenFrames.delete(modal);
    }
    const pendingClose = modalCloseTimers.get(modal);
    if (pendingClose) {
        window.clearTimeout(pendingClose);
        modalCloseTimers.delete(modal);
    }
    closePopovers();
    if (activeModal && activeModal !== modal) closeModal();
    activeModal = modal;
    modalOpener = opener;
    modal.hidden = false;
    document.body.classList.add('gf-modal-open');
    const openFrame = window.requestAnimationFrame(() => {
        modalOpenFrames.delete(modal);
        if (activeModal !== modal || modal.hidden) return;
        modal.classList.add('is-open');
        focusableElements(modal)[0]?.focus();
    });
    modalOpenFrames.set(modal, openFrame);
    if (name === 'qr') renderQrCode(modal);
}

function setupDialogs() {
    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => openModal(button.dataset.dialogOpen, button));
    });
    document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', closeModal));
    document.querySelectorAll('[data-gf-modal]').forEach((backdrop) => {
        backdrop.addEventListener('mousedown', (event) => {
            if (event.target === backdrop) closeModal();
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (activeModal) closeModal();
            closePopovers();
            document.body.classList.remove('gf-sidebar-open');
            return;
        }

        if (event.key !== 'Tab' || !activeModal) return;
        const focusable = focusableElements(activeModal);
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}

function setupClipboard() {
    const config = runtimeConfig();
    document.querySelectorAll('[data-copy-value]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copyValue ?? '');
                showToast(config.copySuccess);
            } catch {
                const value = button.dataset.copyValue ?? '';
                const input = document.createElement('textarea');
                try {
                    input.value = value;
                    input.style.position = 'fixed';
                    input.style.opacity = '0';
                    document.body.append(input);
                    input.select();
                    if (!document.execCommand('copy')) throw new Error('copy-failed');
                    showToast(config.copySuccess);
                } catch {
                    showToast(config.copyFailed);
                } finally {
                    input.remove();
                }
            }
        });
    });
}

function setupLocaleSwitch() {
    document.querySelectorAll('[data-locale-select]').forEach((select) => {
        select.addEventListener('change', () => {
            if (select.value) window.location.assign(select.value);
        });
    });
}

function setupUnsavedChanges() {
    const dirtyForms = new Set();
    const forms = [...document.querySelectorAll('form')].filter((form) => {
        if (form.matches('[data-no-unsaved], [data-ai-form], [data-ai-followup-form]')) return false;
        if (form.matches('[data-admin-unsaved]')) return true;
        const method = (form.getAttribute('method') ?? 'GET').toUpperCase();
        return method !== 'GET' && (form.querySelector('textarea, input[type="file"]') !== null || form.elements.length >= 6);
    });

    forms.forEach((form) => {
        const markDirty = () => { dirtyForms.add(form); };
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('reset', () => { dirtyForms.delete(form); });
        form.addEventListener('gf:saved', () => { dirtyForms.delete(form); });
    });

    window.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement && forms.includes(event.target) && !event.defaultPrevented) {
            dirtyForms.delete(event.target);
            event.target.setAttribute('aria-busy', 'true');
            if (event.submitter instanceof HTMLButtonElement || event.submitter instanceof HTMLInputElement) {
                event.submitter.disabled = true;
            }
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirtyForms.size === 0) return;
        event.preventDefault();
        event.returnValue = '';
    });
}

function focusFirstError() {
    const invalid = document.querySelector('[aria-invalid="true"], .border-red-500, .is-invalid');
    if (invalid instanceof HTMLElement) {
        invalid.focus({ preventScroll: true });
        invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    document.querySelector('[data-admin-errors]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function setupFormAccessibility() {
    const shell = document.querySelector(SHELL_SELECTOR);
    if (!shell) return;

    enhanceFormAccessibility(shell);
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) enhanceFormAccessibility(node);
            });
        });
    });
    observer.observe(shell, { childList: true, subtree: true });
}

function initialize() {
    if (!document.body.classList.contains('gf-admin-v3')) return;
    setupSidebar();
    setupPopovers();
    setupDialogs();
    setupClipboard();
    setupLocaleSwitch();
    setupUnsavedChanges();
    setupFormAccessibility();
    focusFirstError();
    refreshIcons();
    window.setTimeout(refreshIcons, 80);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
else initialize();
