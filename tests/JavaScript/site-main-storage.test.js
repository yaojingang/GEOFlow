import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const code = readFileSync(new URL('../../public/assets/js/main.js', import.meta.url), 'utf8');
function fixture(storage) {
    let loaded;
    let icons = 0;
    const button = { dataset: { linkId: 'one' }, classList: { add() {}, remove() {} }, addEventListener() {}, disabled: false };
    const document = {
        readyState: 'loading',
        body: { appendChild() {} },
        createElement: () => ({}),
        querySelector: () => null,
        querySelectorAll: selector => selector === '.like-button' ? [button] : [],
        addEventListener: (type, handler) => { if (type === 'DOMContentLoaded') loaded = handler; },
    };
    const window = {};
    Object.defineProperty(window, 'localStorage', { get: () => storage() });
    const context = { document, window, console: { log() {} }, lucide: { createIcons() { icons++; } }, setTimeout, clearTimeout };
    Object.defineProperty(context, 'localStorage', { get: () => storage() });
    vm.runInNewContext(code, context);
    document.readyState = 'complete';
    return { button, window, start: () => loaded(), icons: () => icons };
}

test('sandbox denied storage still initializes the page and its icons', () => {
    const page = fixture(() => { throw new Error('SecurityError'); });
    assert.doesNotThrow(page.start);
    assert.equal(page.icons(), 1);
    assert.equal(page.button.disabled, false);
    assert.doesNotThrow(() => page.window.FeishuTreasure.LikeSystem.recordLike('one'));
});

test('malformed saved values leave the page usable', () => {
    for (const value of ['broken-json', '{}', 'null']) {
        const page = fixture(() => ({ getItem: () => value, setItem() {} }));
        assert.doesNotThrow(page.start);
        assert.equal(page.icons(), 1);
        assert.equal(page.button.disabled, false);
    }
});

test('normal saved likes are restored and write failures preserve the successful action', () => {
    const page = fixture(() => ({ getItem: () => '["one"]', setItem() { throw new Error('QuotaExceededError'); } }));
    page.start();
    assert.equal(page.button.disabled, true);
    assert.match(page.button.innerHTML, /已点赞/);
    assert.doesNotThrow(() => page.window.FeishuTreasure.LikeSystem.recordLike('two'));
});
