import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { initializeAdminActionDialog } from '../../resources/js/admin/action-dialog.js';

class FakeClassList {
    constructor() { this.values = new Set(); }
    add(...values) { values.forEach((value) => this.values.add(value)); }
    remove(...values) { values.forEach((value) => this.values.delete(value)); }
    toggle(value, force) {
        if (force === true) this.values.add(value);
        else if (force === false) this.values.delete(value);
        else if (this.values.has(value)) this.values.delete(value);
        else this.values.add(value);
    }
}

class FakeElement {
    constructor(documentRef = null) {
        this.ownerDocument = documentRef;
        this.attributes = new Map();
        this.children = [];
        this.classList = new FakeClassList();
        this.dataset = {};
        this.hidden = false;
        this.disabled = false;
        this.isConnected = true;
        this.listeners = new Map();
        this.parentElement = null;
        this.textContent = '';
        this.value = '';
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    append(...children) {
        children.forEach((child) => {
            child.parentElement = this;
            this.children.push(child);
        });
    }

    click() { this.dispatch('click'); }

    dispatch(type, properties = {}) {
        const event = {
            target: this,
            preventDefault() { this.defaultPrevented = true; },
            defaultPrevented: false,
            ...properties,
        };
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));
        return event;
    }

    focus() { this.ownerDocument.activeElement = this; }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    replaceChildren(...children) {
        this.children = [];
        this.append(...children);
    }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
}

class FakeButton extends FakeElement {}
class FakeInput extends FakeElement {}

class FakeForm extends FakeElement {
    constructor(documentRef) {
        super(documentRef);
        this.submitButton = null;
        this.submitCount = 0;
        this.requestedSubmitter = null;
        this.submitterExternal = false;
    }

    querySelector(selector) {
        return selector === '[type="submit"]' ? this.submitButton : null;
    }

    querySelectorAll(selector) {
        return selector === '[data-admin-confirm-submit][disabled]'
            && !this.submitterExternal
            && this.submitButton?.disabled
            && this.submitButton.hasAttribute('data-admin-confirm-submit')
            ? [this.submitButton]
            : [];
    }

    requestSubmit(submitter) {
        this.submitCount += 1;
        this.requestedSubmitter = submitter;
        this.ownerDocument.dispatch('submit', { target: this, submitter });
    }
}

class FakeDialog extends FakeElement {
    constructor(documentRef, elements) {
        super(documentRef);
        this.elements = elements;
        this.open = false;
    }
    close() { this.open = false; }
    getBoundingClientRect() { return { left: 100, right: 500, top: 100, bottom: 500 }; }
    querySelector(selector) { return this.elements[selector] ?? null; }
    querySelectorAll() { return [this.elements['[data-admin-action-cancel]'], this.elements['[data-admin-action-confirm]']].filter((item) => !item.hidden); }
    showModal() { this.open = true; }
}

class FakeDocument extends FakeElement {
    constructor() {
        super();
        this.ownerDocument = this;
        this.activeElement = null;
        this.body = new FakeElement(this);
        this.documentElement = new FakeElement(this);
        this.nodes = new Map();
        this.forms = [];
    }
    createElement(tag) {
        if (tag === 'input') return new FakeInput(this);
        if (tag === 'button') return new FakeButton(this);
        return new FakeElement(this);
    }
    querySelector(selector) { return this.nodes.get(selector) ?? null; }
    querySelectorAll(selector) {
        if (selector === '[data-admin-confirm-form]') return this.forms;
        if (selector === '[data-admin-confirm-form][aria-busy="true"]') {
            return this.forms.filter((form) => form.getAttribute('aria-busy') === 'true');
        }
        if (selector === '[data-admin-confirm-submit][disabled]') {
            return this.forms
                .map((form) => form.submitButton)
                .filter((button) => button?.disabled && button.hasAttribute('data-admin-confirm-submit'));
        }
        if (selector === '[data-admin-confirm-pending-submit]') {
            return this.forms
                .map((form) => form.submitButton)
                .filter((button) => button?.hasAttribute('data-admin-confirm-pending-submit'));
        }
        return [];
    }
}

function fixture({ externalSubmitter = false, failClosedAttribute = true, submitterType = 'button', withForm = false } = {}) {
    const documentRef = new FakeDocument();
    const title = new FakeElement(documentRef);
    const message = new FakeElement(documentRef);
    const guidance = new FakeElement(documentRef);
    const icon = new FakeElement(documentRef);
    const field = new FakeElement(documentRef);
    const fields = new FakeElement(documentRef);
    const cancel = new FakeButton(documentRef);
    const confirm = new FakeButton(documentRef);
    const dialog = new FakeDialog(documentRef, {
        '[data-admin-action-title]': title,
        '[data-admin-action-message]': message,
        '[data-admin-action-guidance]': guidance,
        '[data-admin-action-icon]': icon,
        '[data-admin-action-field]': field,
        '[data-admin-action-fields]': fields,
        '[data-admin-action-cancel]': cancel,
        '[data-admin-action-confirm]': confirm,
    });
    const layer = new FakeElement(documentRef);
    layer.dataset.cancelLabel = 'Cancel';
    layer.dataset.closeLabel = 'Close';
    layer.dataset.confirmLabel = 'Confirm';
    layer.dataset.successTitle = 'Completed';
    layer.dataset.infoTitle = 'Please note';
    layer.dataset.errorTitle = 'Not completed';
    layer.querySelector = (selector) => selector === '[data-admin-action-dialog]' ? dialog : null;

    const noticeTitle = new FakeElement(documentRef);
    const noticeMessage = new FakeElement(documentRef);
    const noticeGuidance = new FakeElement(documentRef);
    const noticeAction = new FakeElement(documentRef);
    const noticeClose = new FakeButton(documentRef);
    const noticeIcon = new FakeElement(documentRef);
    const notice = new FakeElement(documentRef);
    notice.querySelector = (selector) => new Map([
        ['[data-admin-notice-title]', noticeTitle],
        ['[data-admin-notice-message]', noticeMessage],
        ['[data-admin-notice-guidance]', noticeGuidance],
        ['[data-admin-notice-action]', noticeAction],
        ['[data-admin-notice-close]', noticeClose],
        ['[data-admin-notice-icon]', noticeIcon],
    ]).get(selector) ?? null;

    documentRef.nodes.set('[data-admin-action-layer]', layer);
    documentRef.nodes.set('[data-admin-action-notice]', notice);
    let form = null;
    let submitter = null;
    if (withForm) {
        form = new FakeForm(documentRef);
        form.setAttribute('data-admin-confirm-form', '');
        form.dataset.adminConfirmTitle = 'Delete record';
        form.dataset.adminConfirmMessage = 'This cannot be recovered';
        form.dataset.adminConfirmTone = 'danger';
        form.dataset.adminConfirmLabel = 'Delete record';
        form.method = 'POST';
        form.action = '/admin/records/9';
        form.csrf = 'csrf-token';
        submitter = submitterType === 'input' ? new FakeInput(documentRef) : new FakeButton(documentRef);
        submitter.form = form;
        form.submitterExternal = externalSubmitter;
        submitter.disabled = failClosedAttribute;
        if (failClosedAttribute) {
            submitter.setAttribute('disabled', '');
            submitter.setAttribute('data-admin-confirm-submit', '');
        }
        form.submitButton = submitter;
        documentRef.forms.push(form);
    }
    const windowListeners = new Map();
    const windowRef = {
        HTMLElement: FakeElement,
        HTMLButtonElement: FakeButton,
        HTMLInputElement: FakeInput,
        HTMLDialogElement: FakeDialog,
        HTMLFormElement: FakeForm,
        clearTimeout,
        queueMicrotask,
        requestAnimationFrame(callback) { callback(); },
        setTimeout,
        addEventListener(type, listener) {
            const listeners = windowListeners.get(type) ?? [];
            listeners.push(listener);
            windowListeners.set(type, listeners);
        },
        dispatch(type, event = {}) {
            (windowListeners.get(type) ?? []).forEach((listener) => listener(event));
        },
    };
    globalThis.document = documentRef;
    globalThis.window = windowRef;

    const api = initializeAdminActionDialog(documentRef, windowRef);
    return { api, cancel, confirm, dialog, documentRef, field, fields, form, icon, notice, noticeAction, noticeIcon, noticeMessage, noticeTitle, submitter, title, windowRef };
}

test('danger confirmation focuses cancel, resolves cancellation, and restores opener focus', async () => {
    const { api, cancel, dialog, documentRef, title } = fixture();
    const opener = new FakeButton(documentRef);
    documentRef.activeElement = opener;
    const result = api.confirm({ title: 'Delete item', message: 'Cannot be restored', tone: 'danger', opener });

    assert.equal(dialog.open, true);
    assert.equal(title.textContent, 'Delete item');
    assert.equal(documentRef.activeElement, cancel);
    assert.equal(documentRef.body.classList.values.has('admin-action-dialog-open'), true);
    cancel.click();
    assert.equal(await result, false);
    assert.equal(documentRef.activeElement, opener);
    assert.equal(documentRef.body.classList.values.has('admin-action-dialog-open'), false);
});

test('tone changes replace Lucide glyphs inside the live icon containers', async () => {
    const { api, cancel, documentRef, icon, noticeIcon } = fixture();
    const first = api.confirm({ title: 'Delete item', tone: 'danger' });
    assert.equal(icon.children[0].getAttribute('data-lucide'), 'trash-2');

    const renderedSvg = new FakeElement(documentRef);
    icon.replaceChildren(renderedSvg);
    const second = api.confirm({ title: 'Review item', tone: 'warning' });
    assert.equal(await first, false);
    assert.notEqual(icon.children[0], renderedSvg);
    assert.equal(icon.children[0].getAttribute('data-lucide'), 'triangle-alert');
    cancel.click();
    assert.equal(await second, false);

    api.notice({ message: 'Saved', tone: 'success', duration: 0 });
    assert.equal(noticeIcon.children[0].getAttribute('data-lucide'), 'circle-check');
    const renderedNoticeSvg = new FakeElement(documentRef);
    noticeIcon.replaceChildren(renderedNoticeSvg);
    api.notice({ message: 'Failed', tone: 'error', duration: 0 });
    assert.notEqual(noticeIcon.children[0], renderedNoticeSvg);
    assert.equal(noticeIcon.children[0].getAttribute('data-lucide'), 'circle-alert');
});

test('confirmation resolves true and prompt enforces required input', async () => {
    const { api, confirm, dialog, fields } = fixture();
    const confirmation = api.confirm({ title: 'Start task', tone: 'success' });
    confirm.click();
    assert.equal(await confirmation, true);

    const prompt = api.prompt({ title: 'Rename', fieldLabel: 'Name', required: true, requiredMessage: 'Required' });
    const input = fields.children[0].children[1];
    confirm.click();
    assert.equal(dialog.open, true);
    assert.equal(input.getAttribute('aria-invalid'), 'true');
    input.value = 'Weekly report';
    input.dispatch('input');
    confirm.click();
    assert.equal(await prompt, 'Weekly report');
});

test('opening a new dialog safely cancels the previous request', async () => {
    const { api, cancel, dialog, title } = fixture();
    const first = api.confirm({ title: 'First action' });
    const second = api.confirm({ title: 'Second action' });

    assert.equal(await first, false);
    assert.equal(dialog.open, true);
    assert.equal(title.textContent, 'Second action');
    cancel.click();
    assert.equal(await second, false);
});

test('replacing an open dialog preserves the original page opener for final focus restoration', async () => {
    const { api, cancel, documentRef } = fixture();
    const opener = new FakeButton(documentRef);
    documentRef.activeElement = opener;

    const first = api.confirm({ title: 'First action', opener });
    const second = api.confirm({ title: 'Second action' });

    assert.equal(await first, false);
    cancel.click();
    assert.equal(await second, false);
    assert.equal(documentRef.activeElement, opener);
});

test('prompt combines pattern and custom validation and links help and error copy to the field', async () => {
    const { api, confirm, dialog, fields } = fixture();
    const prompt = api.prompt({
        title: 'Authorization',
        fieldLabel: 'Code',
        fieldHelp: 'Use the code from the update page.',
        pattern: '[0-9]{6}',
        patternMessage: 'Use six digits.',
        validate: (value) => value === '123456' ? '' : 'The code does not match.',
    });
    const input = fields.children[0].children[1];
    const help = fields.children[0].children[2];
    const error = fields.children[0].children[3];

    assert.equal(input.getAttribute('aria-describedby'), help.id);
    assert.equal(input.getAttribute('aria-errormessage'), error.id);
    input.value = '654321';
    confirm.click();
    assert.equal(dialog.open, true);
    assert.equal(error.textContent, 'The code does not match.');
    assert.equal(input.getAttribute('aria-invalid'), 'true');

    input.value = '123456';
    confirm.click();
    assert.equal(await prompt, '123456');
});

test('read-only prompt can use a single close action', async () => {
    const { api, cancel, confirm, fields } = fixture();
    const prompt = api.prompt({
        title: 'Copy token',
        fieldLabel: 'Token',
        value: 'secret-token',
        readOnly: true,
        showCancel: false,
    });

    assert.equal(cancel.hidden, true);
    assert.equal(fields.children[0].children[1].value, 'secret-token');
    confirm.click();
    assert.equal(await prompt, 'secret-token');
});

test('escape, backdrop cancellation, and focus looping keep confirmation keyboard safe', async () => {
    const { api, cancel, confirm, dialog, documentRef } = fixture();
    const escaped = api.confirm({ title: 'Stop task', tone: 'warning' });
    dialog.dispatch('cancel');
    assert.equal(await escaped, false);

    const backdrop = api.confirm({ title: 'Delete task', tone: 'danger' });
    dialog.dispatch('click', { clientX: 0, clientY: 0 });
    assert.equal(await backdrop, false);

    const looped = api.confirm({ title: 'Delete task', tone: 'danger' });
    assert.equal(documentRef.activeElement, cancel);
    dialog.dispatch('keydown', { key: 'Tab', shiftKey: true });
    assert.equal(documentRef.activeElement, confirm);
    dialog.dispatch('keydown', { key: 'Tab', shiftKey: false });
    assert.equal(documentRef.activeElement, cancel);
    cancel.click();
    assert.equal(await looped, false);
});

test('confirmed forms preserve the submitter and request contract and recover after bfcache', async () => {
    const { confirm, documentRef, form, submitter, windowRef } = fixture({ withForm: true });
    assert.equal(submitter.disabled, false);

    const original = { action: form.action, csrf: form.csrf, method: form.method };
    const firstSubmit = documentRef.dispatch('submit', { target: form, submitter });
    assert.equal(firstSubmit.defaultPrevented, true);
    confirm.click();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(form.submitCount, 1);
    assert.equal(form.requestedSubmitter, submitter);
    assert.deepEqual({ action: form.action, csrf: form.csrf, method: form.method }, original);
    assert.equal(form.getAttribute('aria-busy'), 'true');
    await Promise.resolve();
    assert.equal(submitter.disabled, true);

    windowRef.dispatch('pageshow', { persisted: true });
    assert.equal(form.hasAttribute('aria-busy'), false);
    assert.equal(submitter.disabled, false);
});

test('fail-closed submit controls linked to a confirmation form from outside the form are enabled after initialization', () => {
    const { submitter } = fixture({ externalSubmitter: true, withForm: true });

    assert.equal(submitter.disabled, false);
    assert.equal(submitter.hasAttribute('aria-disabled'), false);
});

test('confirmed forms preserve input submitters and recover when a downstream handler cancels submission', async () => {
    const { confirm, documentRef, form, submitter } = fixture({ withForm: true, submitterType: 'input' });
    const firstSubmit = documentRef.dispatch('submit', { target: form, submitter });
    assert.equal(firstSubmit.defaultPrevented, true);
    confirm.click();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(form.requestedSubmitter, submitter);
    assert.equal(submitter.disabled, true);

    submitter.disabled = false;
    form.removeAttribute('aria-busy');
    form.requestSubmit = (resubmitter) => {
        form.requestedSubmitter = resubmitter;
        documentRef.dispatch('submit', { target: form, submitter: resubmitter, defaultPrevented: true });
    };
    documentRef.dispatch('submit', { target: form, submitter });
    confirm.click();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(form.requestedSubmitter, submitter);
    assert.equal(form.hasAttribute('aria-busy'), false);
    assert.equal(submitter.disabled, false);
});

test('bfcache recovery unlocks the exact submitted control even when it uses a page-specific fail-closed marker', async () => {
    const { confirm, documentRef, form, submitter, windowRef } = fixture({
        failClosedAttribute: false,
        withForm: true,
    });
    documentRef.dispatch('submit', { target: form, submitter });
    confirm.click();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(submitter.disabled, true);
    assert.equal(submitter.hasAttribute('data-admin-confirm-pending-submit'), true);
    windowRef.dispatch('pageshow', { persisted: true });
    assert.equal(form.hasAttribute('aria-busy'), false);
    assert.equal(submitter.disabled, false);
    assert.equal(submitter.hasAttribute('data-admin-confirm-pending-submit'), false);
});

test('multi-field prompt returns authorization values and notices reject external action URLs', async () => {
    const { api, confirm, fields, notice, noticeAction, noticeMessage, noticeTitle } = fixture();
    const prompt = api.prompt({
        title: 'Update system',
        fields: [
            { name: 'authorization', label: 'Code', required: true, requiredMessage: 'Required' },
            { name: 'password', label: 'Password', type: 'password', required: true, requiredMessage: 'Required' },
        ],
    });
    fields.children[0].children[1].value = '123456';
    fields.children[1].children[1].value = 'secret';
    confirm.click();
    assert.deepEqual(await prompt, { authorization: '123456', password: 'secret' });

    api.notice({ title: 'Done', message: 'Updated', actionLabel: 'Open', actionUrl: 'https://example.com', duration: 0 });
    assert.equal(notice.hidden, false);
    assert.equal(noticeTitle.textContent, 'Done');
    assert.equal(noticeMessage.textContent, 'Updated');
    assert.equal(noticeAction.hidden, true);

    api.notice({ message: 'Updated', actionLabel: 'Open', actionUrl: '/\\evil.test', duration: 0 });
    assert.equal(noticeAction.hidden, true);

    api.notice({ message: 'Default title', tone: 'error', duration: 0 });
    assert.equal(noticeTitle.textContent, 'Not completed');
});

test('owned admin sources contain no native browser action dialogs', () => {
    const sources = [
        '../../resources/views/admin/articles/index.blade.php',
        '../../resources/views/admin/tasks/index.blade.php',
        '../../resources/views/admin/api-tokens/index.blade.php',
        '../../resources/js/admin/article-create-assistant.js',
        '../../resources/js/admin/ai-workspace.js',
        '../../resources/js/admin/materials-standalone.js',
    ];
    sources.forEach((path) => {
        const source = readFileSync(new URL(path, import.meta.url), 'utf8');
        assert.doesNotMatch(source, /(?<![.\w])(?:window\.|globalThis\.)?(?:confirm|alert|prompt)\s*\(/u);
    });
});
