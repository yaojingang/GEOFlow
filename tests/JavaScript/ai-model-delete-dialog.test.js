import assert from 'node:assert/strict';
import test from 'node:test';

import { initializeAiModelDeleteDialog } from '../../resources/js/admin/ai-model-delete-dialog.js';

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

class FakeElement extends FakeEventTarget {
    constructor(text = '') {
        super();
        this.attributes = new Map();
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

class FakeButton extends FakeElement {
    constructor(text = '') {
        super(text);
        this.disabled = false;
    }
}

class FakeForm extends FakeEventTarget {
    constructor(name, editUrl) {
        super();
        this.dataset = { modelName: name, modelEditUrl: editUrl };
        this.trigger = new FakeButton('删除');
        this.submitButton = new FakeButton();
        this.submitted = false;
    }

    querySelector(selector) {
        if (selector === '[data-ai-model-delete-trigger]') return this.trigger;
        if (selector === '[data-ai-model-delete-submit]') return this.submitButton;

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
        this.animationCount = 0;
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
        this.dispatch('close');
    }

    animate() {
        this.animationCount += 1;
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

function fixture(modelNames = ['Dreamto Claude Opus 4.6']) {
    const modelName = new FakeElement();
    const editLink = new FakeElement();
    const cancelButton = new FakeButton('保留模型');
    const confirmButton = new FakeButton('永久删除');
    const confirmLabel = new FakeElement('永久删除');
    const dialog = new FakeDialog({
        '[data-ai-model-delete-name]': modelName,
        '[data-ai-model-delete-edit]': editLink,
        '[data-ai-model-delete-cancel]': cancelButton,
        '[data-ai-model-delete-confirm]': confirmButton,
        '[data-ai-model-delete-confirm-label]': confirmLabel,
    });
    const forms = modelNames.map((name, index) => new FakeForm(name, `/admin/ai-models/${index + 1}/edit`));
    const root = {
        querySelector: (selector) => selector === '[data-ai-model-delete-dialog]' ? dialog : null,
        querySelectorAll: (selector) => selector === '[data-ai-model-delete-form]' ? forms : [],
    };

    globalThis.HTMLElement = FakeElement;
    globalThis.HTMLButtonElement = FakeButton;
    globalThis.HTMLDialogElement = FakeDialog;
    globalThis.HTMLFormElement = FakeForm;
    globalThis.document = fakeDocument;
    globalThis.window = {
        matchMedia: () => ({ matches: false }),
    };

    initializeAiModelDeleteDialog(root);

    return { cancelButton, confirmButton, confirmLabel, dialog, editLink, forms, modelName };
}

test('opens for the selected model, exposes the edit path, and focuses the safe action', () => {
    const { cancelButton, dialog, editLink, forms, modelName } = fixture();
    fakeDocument.activeElement = forms[0].trigger;

    forms[0].trigger.dispatch('click', { detail: 0 });

    assert.equal(dialog.open, true);
    assert.equal(dialog.animationCount, 0);
    assert.equal(modelName.textContent, 'Dreamto Claude Opus 4.6');
    assert.equal(editLink.attributes.get('href'), '/admin/ai-models/1/edit');
    assert.equal(fakeDocument.activeElement, cancelButton);
    assert.equal(forms[0].submitted, false);
});

test('Cancel, Escape, and backdrop clicks close the dialog and restore focus', () => {
    const { cancelButton, dialog, forms } = fixture();
    fakeDocument.activeElement = forms[0].trigger;
    forms[0].trigger.dispatch('click', { detail: 1 });
    assert.equal(dialog.animationCount, 1);

    cancelButton.dispatch('click');
    assert.equal(dialog.open, false);
    assert.equal(fakeDocument.activeElement, forms[0].trigger);

    forms[0].trigger.dispatch('click', { detail: 0 });
    dialog.dispatch('cancel');
    assert.equal(dialog.open, false);
    assert.equal(fakeDocument.activeElement, forms[0].trigger);

    forms[0].trigger.dispatch('click', { detail: 0 });
    dialog.dispatch('click', { clientX: 50, clientY: 50 });
    assert.equal(dialog.open, false);
    assert.equal(fakeDocument.activeElement, forms[0].trigger);
});

test('confirmation submits only the chosen model and locks the destructive action', () => {
    const { confirmButton, confirmLabel, forms } = fixture(['第一个模型', '第二个模型']);
    fakeDocument.activeElement = forms[1].trigger;
    forms[1].trigger.dispatch('click', { detail: 1 });

    confirmButton.dispatch('click');

    assert.equal(forms[0].submitted, false);
    assert.equal(forms[1].submitted, true);
    assert.equal(forms[1].dataset.deleteConfirmed, 'true');
    assert.equal(confirmButton.disabled, true);
    assert.equal(confirmButton.attributes.get('aria-busy'), 'true');
    assert.equal(confirmLabel.textContent, '正在删除...');
});
