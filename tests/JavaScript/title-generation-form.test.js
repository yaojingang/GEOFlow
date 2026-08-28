import assert from 'node:assert/strict';
import test from 'node:test';

import {
    initializeTitleGenerationForm,
    requiresKeywordReuseConfirmation,
} from '../../resources/js/admin/title-generation-form.js';

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
    disabled = false;

    focus() {
        fakeDocument.activeElement = this;
    }
}

class FakeInput extends FakeEventTarget {
    constructor(value) {
        super();
        this.value = value;
    }
}

class FakeSelect extends FakeEventTarget {
    constructor(keywordCount) {
        super();
        this.options = [
            { dataset: {} },
            { dataset: { keywordCount: String(keywordCount) } },
        ];
        this.selectedIndex = 1;
    }

    get selectedOptions() {
        return [this.options[this.selectedIndex]];
    }
}

class FakeDialog extends FakeEventTarget {
    constructor(elements) {
        super();
        this.dataset = {
            summaryTemplate: '计划生成 __TITLE_COUNT__ 个标题，关键词数量为 __KEYWORD_COUNT__ 个。',
        };
        this.elements = elements;
        this.open = false;
        this.returnValue = '';
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }

    showModal() {
        this.open = true;
    }

    close(returnValue = '') {
        this.open = false;
        this.returnValue = returnValue;
        this.dispatch('close');
    }

    getBoundingClientRect() {
        return { left: 100, right: 500, top: 100, bottom: 400 };
    }
}

class FakeForm extends FakeEventTarget {
    constructor(elements) {
        super();
        this.elements = elements;
        this.submitCount = 0;
        this.attributes = new Map();
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }

    submit(button) {
        const event = this.dispatch('submit', { submitter: button });
        if (!event.defaultPrevented) this.submitCount += 1;
    }

    requestSubmit(button) {
        this.submit(button);
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }
}

const fakeDocument = { activeElement: null };

function fixture({ titleCount = 100, keywordCount = 9 } = {}) {
    const keywordSelect = new FakeSelect(keywordCount);
    const titleCountInput = new FakeInput(String(titleCount));
    const confirmationInput = new FakeInput('0');
    const submitButton = new FakeButton();
    const cancelButton = new FakeButton();
    const confirmButton = new FakeButton();
    const summary = { textContent: '' };
    const form = new FakeForm({
        '[name="keyword_library_id"]': keywordSelect,
        '[name="title_count"]': titleCountInput,
        '[data-keyword-reuse-confirmed]': confirmationInput,
        '[data-title-generation-submit]': submitButton,
    });
    const dialog = new FakeDialog({
        '[data-keyword-reuse-summary]': summary,
        '[data-keyword-reuse-cancel]': cancelButton,
        '[data-keyword-reuse-confirm]': confirmButton,
    });
    const root = {
        querySelector(selector) {
            if (selector === '[data-title-generation-form]') return form;
            if (selector === '[data-keyword-reuse-dialog]') return dialog;
            return null;
        },
    };

    initializeTitleGenerationForm(root);

    return {
        cancelButton,
        confirmationInput,
        confirmButton,
        dialog,
        form,
        keywordSelect,
        submitButton,
        summary,
        titleCountInput,
    };
}

test('requires confirmation only when a positive keyword count is exceeded', () => {
    assert.equal(requiresKeywordReuseConfirmation(9, 9), false);
    assert.equal(requiresKeywordReuseConfirmation(10, 9), true);
    assert.equal(requiresKeywordReuseConfirmation(10, 0), false);
});

test('submits directly when the title count does not exceed the keyword count', () => {
    const { dialog, form, submitButton } = fixture({ titleCount: 9, keywordCount: 9 });

    form.submit(submitButton);

    assert.equal(form.submitCount, 1);
    assert.equal(dialog.open, false);
});

test('opens a centered confirmation flow before keyword reuse', () => {
    const { cancelButton, dialog, form, submitButton, summary } = fixture();
    fakeDocument.activeElement = submitButton;

    form.submit(submitButton);

    assert.equal(form.submitCount, 0);
    assert.equal(dialog.open, true);
    assert.equal(fakeDocument.activeElement, cancelButton);
    assert.equal(summary.textContent, '计划生成 100 个标题，关键词数量为 9 个。');
});

test('cancel keeps the form unsubmitted and returns focus to the submit button', () => {
    const { cancelButton, dialog, form, submitButton } = fixture();
    fakeDocument.activeElement = submitButton;
    form.submit(submitButton);

    cancelButton.dispatch('click');

    assert.equal(dialog.open, false);
    assert.equal(form.submitCount, 0);
    assert.equal(fakeDocument.activeElement, submitButton);
});

test('confirm records consent and submits exactly once', () => {
    const { confirmationInput, confirmButton, form, submitButton } = fixture();
    form.submit(submitButton);

    confirmButton.dispatch('click');
    confirmButton.dispatch('click');

    assert.equal(confirmationInput.value, '1');
    assert.equal(form.submitCount, 1);
    assert.equal(confirmButton.disabled, true);
    assert.equal(submitButton.disabled, true);
    assert.equal(form.attributes.get('aria-busy'), 'true');
});

test('a repeated submit is ignored after the first request starts', () => {
    const { form, submitButton } = fixture({ titleCount: 9, keywordCount: 9 });

    form.submit(submitButton);
    form.submit(submitButton);

    assert.equal(form.submitCount, 1);
});

test('escape after reopening returns focus even after an earlier confirmation close', () => {
    const { dialog, form, submitButton } = fixture();
    dialog.returnValue = 'confirm';
    fakeDocument.activeElement = submitButton;

    form.submit(submitButton);
    dialog.close('cancel');

    assert.equal(fakeDocument.activeElement, submitButton);
});

test('changing the keyword library or title count clears previous consent', () => {
    const { confirmationInput, keywordSelect, titleCountInput } = fixture();
    confirmationInput.value = '1';

    keywordSelect.dispatch('change');
    assert.equal(confirmationInput.value, '0');

    confirmationInput.value = '1';
    titleCountInput.dispatch('input');
    assert.equal(confirmationInput.value, '0');
});
