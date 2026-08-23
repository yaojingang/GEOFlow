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
