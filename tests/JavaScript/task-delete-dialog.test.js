import assert from 'node:assert/strict';
import test from 'node:test';

import { initializeTaskDeleteDialog } from '../../resources/js/admin/task-delete-dialog.js';

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatch(type, properties = {}) {
        const event = {
            target: this,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
            ...properties,
        };

        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));

        return event;
    }
}

class FakeButton extends FakeEventTarget {
    constructor(text = '') {
        super();
        this.attributes = new Map();
        this.disabled = false;
        this.hidden = false;
        this.textContent = text;
    }

    focus() {
        fakeDocument.activeElement = this;
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }
}

class FakeForm extends FakeEventTarget {
    constructor(name) {
        super();
        this.dataset = { taskName: name };
        this.trigger = new FakeButton('删除任务');
        this.submitButton = new FakeButton();
        this.submitted = false;
    }

    querySelector(selector) {
        if (selector === '[data-task-delete-trigger]') return this.trigger;
        if (selector === '[data-task-delete-submit]') return this.submitButton;

        return null;
    }

    requestSubmit(button) {
        assert.equal(button, this.submitButton);
        const event = this.dispatch('submit');
        if (!event.defaultPrevented) this.submitted = true;
    }
}

class FakeDialog extends FakeEventTarget {
    constructor(elements) {
        super();
        this.dataset = { deletingLabel: '正在删除...' };
        this.elements = elements;
        this.open = false;
        this.opener = null;
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }

    showModal() {
        this.opener = fakeDocument.activeElement;
        this.open = true;
    }

    close() {
        this.open = false;
        this.opener?.focus();
    }

    animate() {
        return null;
    }

    getBoundingClientRect() {
        return { left: 100, right: 500, top: 100, bottom: 400 };
    }

    dispatch(type, properties = {}) {
        const event = super.dispatch(type, properties);
        if (type === 'cancel' && !event.defaultPrevented) this.close();

        return event;
    }
}

const fakeDocument = { activeElement: null };

function fixture(taskNames = ['测试12']) {
    const taskName = { textContent: '' };
    const cancelButton = new FakeButton('取消');
    const confirmButton = new FakeButton('删除任务');
    const confirmLabel = { textContent: '删除任务' };
    const dialog = new FakeDialog({
        '[data-task-delete-name]': taskName,
        '[data-task-delete-cancel]': cancelButton,
        '[data-task-delete-confirm]': confirmButton,
        '[data-task-delete-confirm-label]': confirmLabel,
    });
    const forms = taskNames.map((name) => new FakeForm(name));
    const root = {
        querySelector: (selector) => selector === '[data-task-delete-dialog]' ? dialog : null,
        querySelectorAll: (selector) => selector === '[data-task-delete-form]' ? forms : [],
    };

    globalThis.HTMLButtonElement = FakeButton;
    globalThis.HTMLDialogElement = FakeDialog;
    globalThis.HTMLFormElement = FakeForm;
    globalThis.window = {
        matchMedia: () => ({ matches: true }),
    };

    initializeTaskDeleteDialog(root);

    return { cancelButton, confirmButton, confirmLabel, dialog, forms, taskName };
}

test('opens the dialog for the selected task and focuses Cancel', () => {
    const { cancelButton, dialog, forms, taskName } = fixture();
    fakeDocument.activeElement = forms[0].trigger;

    forms[0].trigger.dispatch('click');

    assert.equal(dialog.open, true);
    assert.equal(taskName.textContent, '测试12');
    assert.equal(fakeDocument.activeElement, cancelButton);
    assert.equal(forms[0].submitted, false);
});

test('Cancel and Escape close the dialog and return focus to the delete trigger', () => {
    const { cancelButton, dialog, forms } = fixture();
    fakeDocument.activeElement = forms[0].trigger;
    forms[0].trigger.dispatch('click');

    cancelButton.dispatch('click');

    assert.equal(dialog.open, false);
    assert.equal(fakeDocument.activeElement, forms[0].trigger);

    forms[0].trigger.dispatch('click');
    dialog.dispatch('cancel');

    assert.equal(dialog.open, false);
    assert.equal(fakeDocument.activeElement, forms[0].trigger);
});

test('confirmation submits only the task that opened the dialog', () => {
    const { confirmButton, confirmLabel, forms } = fixture(['第一个任务', '第二个任务']);
    fakeDocument.activeElement = forms[1].trigger;
    forms[1].trigger.dispatch('click');

    confirmButton.dispatch('click');

    assert.equal(forms[0].submitted, false);
    assert.equal(forms[1].submitted, true);
    assert.equal(forms[1].dataset.deleteConfirmed, 'true');
    assert.equal(confirmButton.disabled, true);
    assert.equal(confirmButton.attributes.get('aria-busy'), 'true');
    assert.equal(confirmLabel.textContent, '正在删除...');
});
