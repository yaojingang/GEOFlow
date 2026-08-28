import test from 'node:test';
import assert from 'node:assert/strict';

test('legacy admin pages install the shared localized icon runtime', async () => {
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    const createIcons = () => {};

    globalThis.document = {
        body: {
            classList: {
                contains: () => false,
            },
        },
        querySelector: () => null,
        querySelectorAll: () => [],
        readyState: 'complete',
    };
    globalThis.window = {
        lucide: {
            createIcons,
        },
    };

    try {
        await import(`../../resources/js/admin/ui-v3-shell.js?legacy-icons=${Date.now()}`);

        assert.equal(typeof globalThis.window.GeoFlowAdminUi?.refreshIcons, 'function');
        assert.notEqual(globalThis.window.lucide.createIcons, createIcons);
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
});

test('named submit buttons stay enabled while duplicate form submissions are blocked', async () => {
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;

    globalThis.document = {
        body: {
            classList: {
                contains: () => false,
            },
        },
        querySelector: () => null,
        querySelectorAll: () => [],
        readyState: 'complete',
    };
    globalThis.window = {
        lucide: {
            createIcons: () => {},
        },
    };

    try {
        const { markFormSubmitting } = await import(`../../resources/js/admin/ui-v3-shell.js?named-submitter=${Date.now()}`);
        const formAttributes = new Map();
        const submitterAttributes = new Map();
        const form = {
            dataset: {},
            setAttribute: (name, value) => formAttributes.set(name, value),
        };
        const submitter = {
            disabled: false,
            name: 'run_ai_quality_after_save',
            value: '1',
            setAttribute: (name, value) => submitterAttributes.set(name, value),
        };

        assert.equal(markFormSubmitting(form, submitter), true);
        assert.equal(submitter.disabled, false);
        assert.equal(submitter.name, 'run_ai_quality_after_save');
        assert.equal(submitter.value, '1');
        assert.equal(formAttributes.get('aria-busy'), 'true');
        assert.equal(submitterAttributes.get('aria-disabled'), 'true');
        assert.equal(markFormSubmitting(form, submitter), false);
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
});
