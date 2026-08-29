import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    initializeLibraryEntryForm,
    initializeLibraryEntryForms,
} from '../../resources/js/admin/library-entry-form.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

class FakeForm {
    constructor() {
        this.dataset = { processingLabel: '处理中...' };
        this.listeners = new Map();
        this.attributes = new Map();
        this.attributeWrites = 0;
        this.submitButton = {
            attributes: new Map(),
            disabled: false,
            removeAttribute(name) {
                this.attributes.delete(name);
            },
            setAttribute(name, value) {
                this.attributes.set(name, value);
            },
        };
        this.submitLabel = { textContent: '导入标题' };
        this.status = { textContent: '' };
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    querySelector(selector) {
        return new Map([
            ['[data-library-entry-submit]', this.submitButton],
            ['[data-library-entry-submit-label]', this.submitLabel],
            ['[data-library-entry-status]', this.status],
        ]).get(selector) ?? null;
    }

    setAttribute(name, value) {
        this.attributeWrites += 1;
        this.attributes.set(name, value);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    submit() {
        const event = {
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        this.listeners.get('submit')?.(event);

        return event;
    }
}

test('standalone library entry forms announce and lock the in-progress state', () => {
    const form = new FakeForm();

    initializeLibraryEntryForm(form);
    form.submit();

    assert.equal(form.attributes.get('aria-busy'), 'true');
    assert.equal(form.submitButton.disabled, true);
    assert.equal(form.submitButton.attributes.get('aria-disabled'), 'true');
    assert.equal(form.submitLabel.textContent, '处理中...');
    assert.equal(form.status.textContent, '处理中...');
});

test('rapid repeated submits lock once and prevent the duplicate submission', () => {
    const form = new FakeForm();

    initializeLibraryEntryForm(form);
    const first = form.submit();
    const second = form.submit();

    assert.equal(first.defaultPrevented, false);
    assert.equal(second.defaultPrevented, true);
    assert.equal(form.attributeWrites, 1);
    assert.equal(form.submitButton.disabled, true);
});

test('a back-forward cache restore unlocks the form and restores its labels', () => {
    const form = new FakeForm();
    let pageShowListener;
    const pageTarget = {
        addEventListener(type, listener) {
            if (type === 'pageshow') pageShowListener = listener;
        },
    };

    initializeLibraryEntryForm(form, pageTarget);
    form.submit();
    pageShowListener({ persisted: true });

    assert.equal(form.attributes.has('aria-busy'), false);
    assert.equal(form.submitButton.disabled, false);
    assert.equal(form.submitButton.attributes.has('aria-disabled'), false);
    assert.equal(form.submitLabel.textContent, '导入标题');
    assert.equal(form.status.textContent, '');
    assert.equal(form.submit().defaultPrevented, false);
});

test('collection initialization does not pass form indexes as lifecycle targets', () => {
    const forms = [new FakeForm(), new FakeForm()];
    let pageShowListeners = 0;
    const pageTarget = {
        addEventListener(type) {
            if (type === 'pageshow') pageShowListeners += 1;
        },
    };

    initializeLibraryEntryForms({
        querySelectorAll() {
            return forms;
        },
    }, pageTarget);
    const events = forms.map((form) => form.submit());

    assert.deepEqual(events.map((event) => event.defaultPrevented), [false, false]);
    assert.equal(pageShowListeners, 2);
});

test('the entry form guard is bundled synchronously without a lazy-listener window', () => {
    assert.match(
        appSource,
        /import '\.\/admin\/library-entry-form';/,
    );
    assert.doesNotMatch(appSource, /import\('\.\/admin\/library-entry-form'\)/);
});
