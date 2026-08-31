import { enhanceFormAccessibility } from './form-accessibility.js';
import {
    SIDEBAR_DEFAULT_WIDTH,
    SIDEBAR_MAX_WIDTH,
    SIDEBAR_MIN_WIDTH,
    normalizeSidebarWidth,
} from './sidebar-width.js';
import { setupSidebarRecent } from './sidebar-recent.js';
import { refreshIconPlaceholders, stabilizeLucideRuntime } from './ui-v3-icons.js';

const SHELL_SELECTOR = '[data-gf-shell]';
const SIDEBAR_STORAGE_KEY = 'geoflow.admin.ui-v3.sidebar-collapsed';
const SIDEBAR_WIDTH_STORAGE_KEY = 'geoflow.admin.ui-v3.sidebar-width';
const SIDEBAR_KEYBOARD_STEP = 16;

function runtimeConfig() {
    const element = document.querySelector('#geoflow-runtime-config');
    if (!element) return {};

    try {
        return JSON.parse(element.textContent ?? '{}');
    } catch {
        return {};
    }
}

function refreshIcons(root = document) {
    return refreshIconPlaceholders(root, window.lucide, document);
}

function showToast(message, tone = 'success') {
    if (window.AdminActionDialog?.notice) {
        window.AdminActionDialog.notice({ message, tone });
        return;
    }

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

    const root = document.documentElement;
    const body = document.body;
    const collapseButton = document.querySelector('[data-sidebar-collapse]');
    const resizeHandle = document.querySelector('[data-sidebar-resize]');
    let sidebarWidth = SIDEBAR_DEFAULT_WIDTH;
    let activePointerId = null;

    const applySidebarWidth = (value, persist = true) => {
        sidebarWidth = normalizeSidebarWidth(value);
        root.style.setProperty('--gf-sidebar-width-value', `${sidebarWidth}px`);
        resizeHandle?.setAttribute('aria-valuenow', String(sidebarWidth));
        if (!persist) return sidebarWidth;
        try {
            window.localStorage.setItem(SIDEBAR_WIDTH_STORAGE_KEY, String(sidebarWidth));
        } catch {
            // The layout remains functional when browser storage is unavailable.
        }
        return sidebarWidth;
    };

    const applyCollapsedState = (collapsed, persist = true) => {
        root.setAttribute('data-gf-sidebar-state', collapsed ? 'collapsed' : 'expanded');
        body.classList.toggle('gf-sidebar-collapsed', collapsed);
        collapseButton?.setAttribute('aria-expanded', String(!collapsed));
        resizeHandle?.setAttribute('aria-disabled', String(collapsed));
        if (resizeHandle) resizeHandle.tabIndex = collapsed ? -1 : 0;
        if (!persist) return;
        try {
            window.localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? '1' : '0');
        } catch {
            // The layout remains functional when browser storage is unavailable.
        }
    };

    try {
        sidebarWidth = normalizeSidebarWidth(window.localStorage.getItem(SIDEBAR_WIDTH_STORAGE_KEY));
    } catch {
        sidebarWidth = SIDEBAR_DEFAULT_WIDTH;
    }
    applySidebarWidth(sidebarWidth, false);

    const initialCollapsed = root.getAttribute('data-gf-sidebar-state') === 'collapsed';
    applyCollapsedState(initialCollapsed, false);
    collapseButton?.addEventListener('click', () => {
        applyCollapsedState(root.getAttribute('data-gf-sidebar-state') !== 'collapsed');
    });

    const finishResize = (event) => {
        if (activePointerId === null) return;
        if (event?.pointerId !== undefined && event.pointerId !== activePointerId) return;
        const pointerId = activePointerId;
        activePointerId = null;
        if (resizeHandle?.hasPointerCapture?.(pointerId)) resizeHandle.releasePointerCapture(pointerId);
        root.removeAttribute('data-gf-sidebar-resizing');
        applySidebarWidth(sidebarWidth);
    };

    resizeHandle?.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || root.getAttribute('data-gf-sidebar-state') === 'collapsed') return;
        if (window.matchMedia?.('(max-width: 767px)').matches) return;

        event.preventDefault();
        activePointerId = event.pointerId;
        root.setAttribute('data-gf-sidebar-resizing', '');
        resizeHandle.setPointerCapture?.(event.pointerId);
        applySidebarWidth(event.clientX, false);
    });
    resizeHandle?.addEventListener('pointermove', (event) => {
        if (event.pointerId !== activePointerId) return;
        applySidebarWidth(event.clientX, false);
    });
    resizeHandle?.addEventListener('pointerup', finishResize);
    resizeHandle?.addEventListener('pointercancel', finishResize);
    resizeHandle?.addEventListener('lostpointercapture', finishResize);
    window.addEventListener('blur', () => finishResize());
    resizeHandle?.addEventListener('keydown', (event) => {
        let nextWidth = sidebarWidth;
        if (event.key === 'ArrowLeft') nextWidth -= SIDEBAR_KEYBOARD_STEP;
        else if (event.key === 'ArrowRight') nextWidth += SIDEBAR_KEYBOARD_STEP;
        else if (event.key === 'Home') nextWidth = SIDEBAR_MIN_WIDTH;
        else if (event.key === 'End') nextWidth = SIDEBAR_MAX_WIDTH;
        else return;

        event.preventDefault();
        root.setAttribute('data-gf-sidebar-resizing', '');
        applySidebarWidth(nextWidth);
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => root.removeAttribute('data-gf-sidebar-resizing')));
    });

    document.querySelectorAll('[data-sidebar-open]').forEach((button) => button.addEventListener('click', () => body.classList.add('gf-sidebar-open')));
    document.querySelectorAll('[data-sidebar-close]').forEach((button) => button.addEventListener('click', () => body.classList.remove('gf-sidebar-open')));
    document.querySelectorAll('.gf-sidebar a').forEach((link) => link.addEventListener('click', () => body.classList.remove('gf-sidebar-open')));

    const recent = setupSidebarRecent({ refreshIcons });
    if (recent) {
        window.GeoFlowAdminUi = {
            ...(window.GeoFlowAdminUi ?? {}),
            refreshRecentConversations: recent.refresh,
            setRecentConversationActive: recent.setActiveConversation,
        };
    }
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
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const expandedButton = document.querySelector('[data-popover-button][aria-expanded="true"]');
        if (!expandedButton) return;
        closePopovers();
        expandedButton.focus();
    });
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

    document.addEventListener('geoflow:modal:open', (event) => {
        const name = event instanceof CustomEvent ? event.detail?.name : null;
        if (typeof name !== 'string' || name === '') return;
        const opener = event.detail?.opener instanceof HTMLElement ? event.detail.opener : null;
        openModal(name, opener);
    });
    document.addEventListener('geoflow:modal:close', (event) => {
        const name = event instanceof CustomEvent ? event.detail?.name : null;
        if (typeof name === 'string' && activeModal?.dataset.gfModal !== name) return;
        closeModal();
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

export function markSubmitControlsPending(controls) {
    Array.from(controls ?? []).forEach((control) => {
        if (typeof control?.setAttribute !== 'function') return;

        control.setAttribute('aria-disabled', 'true');
        control.setAttribute('data-gf-submit-pending', '');
    });
}

function markFormSubmitting(form, submitter = null) {
    if (form.dataset.gfSubmitting === 'true') return false;

    form.dataset.gfSubmitting = 'true';
    form.setAttribute('aria-busy', 'true');
    markSubmitControlsPending(submitter ? [submitter] : []);

    return true;
}

export function handleTrackedFormSubmit(event, forms, dirtyForms) {
    if (!(event.target instanceof HTMLFormElement) || !forms.includes(event.target) || event.defaultPrevented) return;

    if (!markFormSubmitting(event.target, event.submitter)) {
        event.preventDefault();
        return;
    }

    dirtyForms.delete(event.target);
}

function resetFormSubmitting(form) {
    delete form.dataset.gfSubmitting;
    form.removeAttribute('aria-busy');
    form.querySelectorAll('[data-gf-submit-pending]').forEach((submitter) => {
        submitter.removeAttribute('aria-disabled');
        submitter.removeAttribute('data-gf-submit-pending');
    });
}

export function resetTrackedFormSubmissions(forms) {
    forms.forEach(resetFormSubmitting);
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

    window.addEventListener('submit', (event) => handleTrackedFormSubmit(event, forms, dirtyForms));

    window.addEventListener('pageshow', () => resetTrackedFormSubmissions(forms));

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

function setupIcons() {
    window.GeoFlowAdminUi = {
        ...(window.GeoFlowAdminUi ?? {}),
        markSubmitControlsPending,
        refreshIcons,
        showToast,
    };

    if (window.lucide) {
        stabilizeLucideRuntime(window.lucide, document);
        refreshIcons(document);
        return;
    }

    document.querySelector('[data-lucide-runtime]')?.addEventListener('load', () => {
        stabilizeLucideRuntime(window.lucide, document);
        refreshIcons(document);
    }, { once: true });
}

function finishFirstPaint() {
    window.requestAnimationFrame(() => {
        document.documentElement.removeAttribute('data-gf-ui-booting');
    });
}

function initialize() {
    setupIcons();
    if (!document.body.classList.contains('gf-admin-v3')) return;
    setupSidebar();
    setupPopovers();
    setupDialogs();
    setupClipboard();
    setupUnsavedChanges();
    setupFormAccessibility();
    focusFirstError();
    finishFirstPaint();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
else initialize();
