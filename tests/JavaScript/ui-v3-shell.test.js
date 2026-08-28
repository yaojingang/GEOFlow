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
    const originalHtmlFormElement = globalThis.HTMLFormElement;
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
        const { handleTrackedFormSubmit, resetTrackedFormSubmissions } = await import(`../../resources/js/admin/ui-v3-shell.js?named-submitter=${Date.now()}`);
        const formAttributes = new Map();
        const submitterAttributes = new Map();
        globalThis.HTMLFormElement = class {};
        const form = new globalThis.HTMLFormElement();
        form.dataset = {};
        form.setAttribute = (name, value) => formAttributes.set(name, value);
        form.removeAttribute = (name) => formAttributes.delete(name);
        const submitter = {
            disabled: false,
            name: 'run_ai_quality_after_save',
            value: '1',
            setAttribute: (name, value) => submitterAttributes.set(name, value),
            removeAttribute: (name) => submitterAttributes.delete(name),
        };
        form.querySelectorAll = () => [submitter];
        const dirtyForms = new Set([form]);
        const firstSubmission = {
            target: form,
            submitter,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        const duplicateSubmission = {
            ...firstSubmission,
            defaultPrevented: false,
        };

        handleTrackedFormSubmit(firstSubmission, [form], dirtyForms);

        assert.equal(firstSubmission.defaultPrevented, false);
        assert.equal(dirtyForms.has(form), false);
        assert.equal(submitter.disabled, false);
        assert.equal(submitter.name, 'run_ai_quality_after_save');
        assert.equal(submitter.value, '1');
        assert.equal(formAttributes.get('aria-busy'), 'true');
        assert.equal(submitterAttributes.get('aria-disabled'), 'true');

        handleTrackedFormSubmit(duplicateSubmission, [form], dirtyForms);

        assert.equal(duplicateSubmission.defaultPrevented, true);

        resetTrackedFormSubmissions([form]);

        assert.equal(form.dataset.gfSubmitting, undefined);
        assert.equal(formAttributes.has('aria-busy'), false);
        assert.equal(submitterAttributes.has('aria-disabled'), false);
        assert.equal(submitterAttributes.has('data-gf-submit-pending'), false);
    } finally {
        globalThis.document = originalDocument;
        globalThis.HTMLFormElement = originalHtmlFormElement;
        globalThis.window = originalWindow;
    }
});

test('knowledge import progress preserves the clicked import action', async () => {
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
        const { markSubmitControlsPending } = await import(`../../resources/js/admin/ui-v3-shell.js?import-submitter=${Date.now()}`);
        const controls = ['save', 'save_and_chunk'].map((value) => {
            const attributes = new Map();

            return {
                attributes,
                disabled: false,
                name: 'import_action',
                value,
                setAttribute: (name, attributeValue) => attributes.set(name, attributeValue),
            };
        });

        markSubmitControlsPending(controls);

        controls.forEach((control) => {
            assert.equal(control.disabled, false);
            assert.equal(control.name, 'import_action');
            assert.equal(control.attributes.get('aria-disabled'), 'true');
            assert.equal(control.attributes.get('data-gf-submit-pending'), '');
        });
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
});
