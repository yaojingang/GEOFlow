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

class FakeActionDialog {
    constructor() {
        this.calls = [];
        this.resolve = null;
    }

    confirm(options) {
        this.calls.push(options);
        return new Promise((resolve) => { this.resolve = resolve; });
    }

    finish(value) {
        const resolve = this.resolve;
        this.resolve = null;
        resolve?.(value);
    }
}

class FakeForm extends FakeEventTarget {
    constructor(elements) {
        super();
        this.elements = elements;
        this.submitCount = 0;
        this.attributes = new Map();
        this.dataset = {
            keywordReuseTitle: '确认复用关键词',
            keywordReuseSummaryTemplate: '计划生成 __TITLE_COUNT__ 个标题，关键词数量为 __KEYWORD_COUNT__ 个。',
            keywordReuseGuidance: '超出关键词数量后会复用关键词。',
            keywordReuseConfirmLabel: '继续生成',
            keywordReuseCancelLabel: '取消',
        };
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
    const actionDialog = new FakeActionDialog();
    const form = new FakeForm({
        '[name="keyword_library_id"]': keywordSelect,
        '[name="title_count"]': titleCountInput,
        '[data-keyword-reuse-confirmed]': confirmationInput,
        '[data-title-generation-submit]': submitButton,
    });
    const root = {
        querySelector(selector) {
            if (selector === '[data-title-generation-form]') return form;
            return null;
        },
    };

    initializeTitleGenerationForm(root, { actionDialog });

    return {
        actionDialog,
        confirmationInput,
        form,
        keywordSelect,
        submitButton,
        titleCountInput,
    };
}

test('requires confirmation only when a positive keyword count is exceeded', () => {
    assert.equal(requiresKeywordReuseConfirmation(9, 9), false);
    assert.equal(requiresKeywordReuseConfirmation(10, 9), true);
    assert.equal(requiresKeywordReuseConfirmation(10, 0), false);
});

test('submits directly when the title count does not exceed the keyword count', () => {
    const { actionDialog, form, submitButton } = fixture({ titleCount: 9, keywordCount: 9 });

    form.submit(submitButton);

    assert.equal(form.submitCount, 1);
    assert.equal(actionDialog.calls.length, 0);
});

test('opens a centered confirmation flow before keyword reuse', () => {
    const { actionDialog, form, submitButton } = fixture();
    fakeDocument.activeElement = submitButton;

    form.submit(submitButton);

    assert.equal(form.submitCount, 0);
    assert.equal(actionDialog.calls.length, 1);
    assert.equal(actionDialog.calls[0].message, '计划生成 100 个标题，关键词数量为 9 个。');
    assert.equal(actionDialog.calls[0].tone, 'warning');
    assert.equal(actionDialog.calls[0].opener, submitButton);
});

test('cancel keeps the form unsubmitted', async () => {
    const { actionDialog, form, submitButton } = fixture();
    fakeDocument.activeElement = submitButton;
    form.submit(submitButton);

    actionDialog.finish(false);
    await Promise.resolve();

    assert.equal(form.submitCount, 0);
});

test('confirm records consent and submits exactly once', async () => {
    const { actionDialog, confirmationInput, form, submitButton } = fixture();
    form.submit(submitButton);

    actionDialog.finish(true);
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(confirmationInput.value, '1');
    assert.equal(form.submitCount, 1);
    assert.equal(submitButton.disabled, true);
    assert.equal(form.attributes.get('aria-busy'), 'true');
});

test('a repeated submit is ignored after the first request starts', () => {
    const { form, submitButton } = fixture({ titleCount: 9, keywordCount: 9 });

    form.submit(submitButton);
    form.submit(submitButton);

    assert.equal(form.submitCount, 1);
});

test('a cancelled shared confirmation can be opened again', async () => {
    const { actionDialog, form, submitButton } = fixture();
    form.submit(submitButton);
    actionDialog.finish(false);
    await Promise.resolve();
    form.submit(submitButton);

    assert.equal(actionDialog.calls.length, 2);
    assert.equal(form.submitCount, 0);
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
