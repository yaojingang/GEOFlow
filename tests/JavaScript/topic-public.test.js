import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../public/assets/js/topics.js', import.meta.url), 'utf8');

function page(initialWidth, hideAbove = 900) {
    let width = initialWidth;
    const queries = new Map(), events = new Map();
    const summary = {};
    const auxiliary = { open: true, querySelector: () => summary };
    const window = {
        location: { hash: '' },
        matchMedia(query) {
            const limit = Number(query.match(/max-width:(\d+)/)[1]);
            const handlers = [];
            const media = { matches: width <= limit, addEventListener: (event, callback) => handlers.push(callback), handlers, limit };
            queries.set(query, media);
            return media;
        },
        getComputedStyle: () => ({ display: width > hideAbove ? 'none' : 'flex', visibility: 'visible' }),
        addEventListener: (event, callback) => events.set(event, callback),
    };
    const document = {
        addEventListener: (event, callback) => callback(),
        querySelectorAll: selector => selector === '[data-topic-auxiliary]' ? [auxiliary] : [],
    };
    vm.runInNewContext(source, { window, document });
    return {
        auxiliary,
        resize(nextWidth) {
            width = nextWidth;
            for (const media of queries.values()) {
                const next = width <= media.limit;
                if (next !== media.matches) {
                    media.matches = next;
                    for (const callback of media.handlers) callback({ matches: next });
                }
            }
            events.get('resize')?.();
        },
    };
}

test('expanding a compact topic reveals auxiliary information when its toggle disappears', () => {
    const state = page(800);
    assert.equal(state.auxiliary.open, false);
    state.resize(1000);
    assert.equal(state.auxiliary.open, true);
    state.resize(650);
    assert.equal(state.auxiliary.open, false);
    state.resize(1000);
    assert.equal(state.auxiliary.open, true);
});

test('a legacy theme hiding its toggle at 700px keeps auxiliary content accessible', () => {
    const state = page(800, 700);
    assert.equal(state.auxiliary.open, true);
    state.resize(650);
    state.auxiliary.open = false;
    state.resize(800);
    assert.equal(state.auxiliary.open, true);
});

test('resizing within a compact layout preserves a readers expanded information', () => {
    const state = page(375);
    state.auxiliary.open = true;
    state.resize(390);
    assert.equal(state.auxiliary.open, true);
});
