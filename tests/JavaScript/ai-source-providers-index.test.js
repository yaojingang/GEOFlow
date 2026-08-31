import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    initializeAiSourceProvidersIndex,
    initializeProviderDeleteConfirmations,
} from '../../resources/js/admin/ai-source-providers-index.js';
import { loadAiSourceProvidersIndex } from '../../resources/js/admin/ai-source-providers-loader.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
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
}

class FakeButton extends FakeEventTarget {
    constructor() {
        super();
        this.attributes = new Map([['aria-disabled', 'true']]);
        this.dataset = {};
        this.disabled = true;
        this.textContent = 'Test';
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }
}

class FakeForm extends FakeEventTarget {
    constructor(confirmMessage, submitButton = new FakeButton()) {
        super();
        this.dataset = { confirmMessage };
        this.submitButton = submitButton;
    }

    querySelector(selector) {
        return selector === '[data-provider-delete-submit]' ? this.submitButton : null;
    }
}

function fixture(confirmAction, forms = [new FakeForm('Delete this provider?')]) {
    const root = {
        querySelectorAll(selector) {
            return selector === '[data-provider-delete-form]' ? forms : [];
        },
    };

    initializeProviderDeleteConfirmations(root, confirmAction);

    return forms;
}

test('keeps delete disabled until the confirmation handler is initialized', () => {
    const form = new FakeForm('Delete this provider?');

    assert.equal(form.submitButton.disabled, true);
    assert.equal(form.submitButton.attributes.get('aria-disabled'), 'true');

    fixture(() => false, [form]);

    assert.equal(form.submitButton.disabled, false);
    assert.equal(form.submitButton.attributes.has('aria-disabled'), false);
});

test('routes the lazy page import through the fail-closed loader', () => {
    assert.match(
        appSource,
        /loadPageModule\('\[data-ai-source-providers-index\]'[\s\S]*loadAiSourceProvidersIndex\([\s\S]*import\('\.\/admin\/ai-source-providers-index'\)/,
    );
});

test('delegates provider deletion confirmation to the shared central controller', () => {
    const declinedForm = fixture(() => false)[0];
    const failedForm = fixture(() => {
        throw new Error('confirmation unavailable');
    })[0];

    assert.equal(declinedForm.dispatch('submit').defaultPrevented, false);
    assert.equal(failedForm.dispatch('submit').defaultPrevented, false);
});

test('does not install a second synchronous provider confirmation listener', () => {
    const form = fixture(() => true)[0];

    assert.equal(form.dispatch('submit').defaultPrevented, false);
});

test('keeps malformed delete forms disabled', () => {
    const form = new FakeForm('Delete this provider?', null);

    fixture(() => true, [form]);

    assert.equal(form.submitButton, null);
});

test('enables connection tests only after their listeners are installed', () => {
    const providerButton = new FakeButton();
    providerButton.dataset = { testUrl: '/provider/test', resultTarget: 'provider-result' };
    const modelButton = new FakeButton();
    modelButton.dataset = {
        modelInput: 'model-id',
        resultTarget: 'model-result',
        bindingType: 'ark',
    };
    const elements = new Map([
        ['provider-result', { className: '', textContent: '' }],
        ['model-result', { className: '', textContent: '' }],
        ['model-id', { value: '1' }],
    ]);
    const root = {
        dataset: { modelTestUrl: '/model/test' },
        ownerDocument: {
            getElementById(id) {
                return elements.get(id) ?? null;
            },
            querySelector() {
                return null;
            },
        },
        querySelectorAll(selector) {
            if (selector === '[data-provider-delete-form]') return [];
            if (selector === '[data-provider-test]') return [providerButton];
            if (selector === '[data-model-test]') return [modelButton];
            return [];
        },
    };

    initializeAiSourceProvidersIndex(root);

    for (const button of [providerButton, modelButton]) {
        assert.equal(button.disabled, false);
        assert.equal(button.attributes.has('aria-disabled'), false);
        assert.equal(button.listeners.get('click')?.length, 1);
    }
});

test('keeps all source actions disabled and announces a dynamic import failure', async () => {
    const testButtons = [new FakeButton(), new FakeButton()];
    const deleteButton = new FakeButton();
    deleteButton.disabled = false;
    deleteButton.removeAttribute('aria-disabled');
    const results = [
        { className: '', textContent: '' },
        { className: '', textContent: '' },
    ];
    const root = {
        dataset: { testInitializationError: 'Connection tests could not load.' },
        querySelectorAll(selector) {
            if (selector === '[data-connection-test-button]') return testButtons;
            if (selector === '[data-provider-delete-submit]') return [deleteButton];
            if (selector === '[data-connection-test-result]') return results;
            return [];
        },
    };

    const loaded = await loadAiSourceProvidersIndex(root, () => Promise.reject(new Error('chunk failed')));

    assert.equal(loaded, false);
    for (const button of [...testButtons, deleteButton]) {
        assert.equal(button.disabled, true);
        assert.equal(button.attributes.get('aria-disabled'), 'true');
    }
    for (const result of results) {
        assert.equal(result.textContent, 'Connection tests could not load.');
        assert.match(result.className, /text-red-700/);
    }
});

test('uses the same fail-closed contract when module initialization throws', async () => {
    const testButton = new FakeButton();
    testButton.disabled = false;
    testButton.removeAttribute('aria-disabled');
    const deleteButton = new FakeButton();
    deleteButton.disabled = false;
    deleteButton.removeAttribute('aria-disabled');
    const result = { className: '', textContent: '' };
    const root = {
        dataset: { testInitializationError: 'Connection tests could not load.' },
        querySelectorAll(selector) {
            if (selector === '[data-connection-test-button]') return [testButton];
            if (selector === '[data-provider-delete-submit]') return [deleteButton];
            if (selector === '[data-connection-test-result]') return [result];
            return [];
        },
    };

    const loaded = await loadAiSourceProvidersIndex(root, () => {
        throw new Error('initializer failed');
    });

    assert.equal(loaded, false);
    assert.equal(testButton.disabled, true);
    assert.equal(testButton.attributes.get('aria-disabled'), 'true');
    assert.equal(deleteButton.disabled, true);
    assert.equal(deleteButton.attributes.get('aria-disabled'), 'true');
    assert.equal(result.textContent, 'Connection tests could not load.');
});
