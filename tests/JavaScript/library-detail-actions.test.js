import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    initializeLibraryDetailActions,
} from '../../resources/js/admin/library-detail-actions.js';
import {
    loadLibraryDetailActions,
} from '../../resources/js/admin/library-detail-actions-loader.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

class FakeClassList {
    constructor(values = []) {
        this.values = new Set(values);
    }

    contains(value) {
        return this.values.has(value);
    }

    toggle(value, force) {
        if (force) this.values.add(value);
        else this.values.delete(value);
    }
}

class FakeElement {
    constructor() {
        this.attributes = new Map();
        this.checked = false;
        this.classList = new FakeClassList();
        this.dataset = {};
        this.disabled = false;
        this.listeners = new Map();
        this.textContent = '';
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatch(type) {
        const event = {
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));

        return event;
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }
}

class FakeForm extends FakeElement {
    constructor(elements = {}) {
        super();
        this.elements = elements;
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }
}

function confirmedActionFixture(confirmAction) {
    const submitButton = new FakeElement();
    submitButton.disabled = true;
    submitButton.setAttribute('aria-disabled', 'true');
    const form = new FakeForm({
        '[data-library-detail-destructive-submit]': submitButton,
    });
    form.dataset.confirmMessage = 'Cancel this run?';
    const root = {
        querySelector() {
            return null;
        },
        querySelectorAll(selector) {
            return selector === '[data-library-confirm-form]' ? [form] : [];
        },
    };

    initializeLibraryDetailActions(root, confirmAction);

    return { form, submitButton };
}

test('confirmed detail actions stay locked until an explicit confirmation listener is ready', () => {
    const { form, submitButton } = confirmedActionFixture(() => true);

    assert.equal(submitButton.disabled, false);
    assert.equal(submitButton.attributes.has('aria-disabled'), false);
    assert.equal(form.dispatch('submit').defaultPrevented, false);
});

test('confirmed detail actions delegate confirmation to the shared central controller', () => {
    assert.equal(confirmedActionFixture(() => false).form.dispatch('submit').defaultPrevented, false);
    assert.equal(confirmedActionFixture(() => {
        throw new Error('confirmation unavailable');
    }).form.dispatch('submit').defaultPrevented, false);
});

function keywordBatchFixture(confirmAction) {
    const panel = new FakeElement();
    panel.classList = new FakeClassList(['hidden']);
    const toggle = new FakeElement();
    const checkbox = new FakeElement();
    checkbox.classList = new FakeClassList(['hidden']);
    const counter = new FakeElement();
    const submitButton = new FakeElement();
    submitButton.disabled = true;
    submitButton.setAttribute('aria-disabled', 'true');
    const form = new FakeForm({
        '[data-keyword-batch-submit]': submitButton,
        '[data-keyword-batch-count]': counter,
    });
    form.dataset.confirmTemplate = 'Delete {count} keywords?';
    form.dataset.selectedTemplate = '{count} selected';
    const root = {
        querySelector(selector) {
            return new Map([
                ['[data-keyword-batch-form]', form],
                ['[data-keyword-batch-panel]', panel],
            ]).get(selector) ?? null;
        },
        querySelectorAll(selector) {
            return new Map([
                ['[data-library-confirm-form]', []],
                ['[data-keyword-batch-toggle]', [toggle]],
                ['[data-keyword-batch-checkbox]', [checkbox]],
            ]).get(selector) ?? [];
        },
    };

    initializeLibraryDetailActions(root, confirmAction);

    return { checkbox, counter, form, panel, submitButton, toggle };
}

test('keyword batch deletion unlocks only after selection and requires confirmation', () => {
    const fixture = keywordBatchFixture(() => true);

    assert.equal(fixture.submitButton.disabled, true);
    fixture.toggle.dispatch('click');
    assert.equal(fixture.panel.classList.contains('hidden'), false);
    assert.equal(fixture.checkbox.classList.contains('hidden'), false);

    fixture.checkbox.checked = true;
    fixture.checkbox.dispatch('change');
    assert.equal(fixture.submitButton.disabled, false);
    assert.equal(fixture.submitButton.attributes.has('aria-disabled'), false);
    assert.equal(fixture.counter.textContent, '1 selected');
    assert.equal(fixture.form.dispatch('submit').defaultPrevented, false);
});

test('keyword batch deletion blocks an empty selection and delegates selected confirmation', () => {
    const empty = keywordBatchFixture(() => true);
    assert.equal(empty.form.dispatch('submit').defaultPrevented, true);

    const rejected = keywordBatchFixture(() => false);
    rejected.checkbox.checked = true;
    rejected.checkbox.dispatch('change');
    assert.equal(rejected.form.dispatch('submit').defaultPrevented, false);

    const unavailable = keywordBatchFixture(() => {
        throw new Error('confirmation unavailable');
    });
    unavailable.checkbox.checked = true;
    unavailable.checkbox.dispatch('change');
    assert.equal(unavailable.form.dispatch('submit').defaultPrevented, false);
});

test('import or initializer failures re-lock every destructive detail action', async () => {
    const button = new FakeElement();
    const root = {
        querySelectorAll(selector) {
            return selector === '[data-library-detail-destructive-submit]' ? [button] : [];
        },
    };

    assert.equal(await loadLibraryDetailActions(root, async () => {
        throw new Error('chunk unavailable');
    }), false);
    assert.equal(button.disabled, true);
    assert.equal(button.attributes.get('aria-disabled'), 'true');

    assert.equal(await loadLibraryDetailActions(root, async () => ({
        initializeLibraryDetailActions() {
            button.disabled = false;
            button.removeAttribute('aria-disabled');
            throw new Error('incomplete markup');
        },
    })), false);
    assert.equal(button.disabled, true);
    assert.equal(button.attributes.get('aria-disabled'), 'true');
});

test('the detail action loader is scoped to keyword and title library details', () => {
    assert.match(
        appSource,
        /loadPageModule\('\[data-library-detail-actions\]'[\s\S]*loadLibraryDetailActions[\s\S]*import\('\.\/admin\/library-detail-actions'\)/,
    );
});
