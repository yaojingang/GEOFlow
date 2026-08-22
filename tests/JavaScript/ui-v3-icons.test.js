import test from 'node:test';
import assert from 'node:assert/strict';

import { refreshIconPlaceholders, stabilizeLucideRuntime } from '../../resources/js/admin/ui-v3-icons.js';

test('refreshIconPlaceholders converts pending icons once and clears conversion markers', () => {
    let pending = true;
    let conversions = 0;
    const renderedIcons = [
        { removeAttribute: (name) => assert.equal(name, 'data-lucide') },
        { removeAttribute: (name) => assert.equal(name, 'data-lucide') },
    ];
    const documentRoot = {
        querySelectorAll: (selector) => selector === 'svg[data-lucide]' ? renderedIcons : [],
    };
    const root = {
        matches: () => false,
        querySelectorAll: (selector) => selector === 'i[data-lucide]' && pending ? [{}, {}] : [],
    };
    const lucide = {
        createIcons: () => {
            conversions += 1;
            pending = false;
        },
    };

    assert.equal(refreshIconPlaceholders(root, lucide, documentRoot), 2);
    assert.equal(refreshIconPlaceholders(root, lucide, documentRoot), 0);
    assert.equal(conversions, 1);
});

test('refreshIconPlaceholders does not scan when the requested region has no pending icons', () => {
    let conversions = 0;
    const root = { matches: () => false, querySelectorAll: () => [] };
    const lucide = { createIcons: () => { conversions += 1; } };

    assert.equal(refreshIconPlaceholders(root, lucide, root), 0);
    assert.equal(conversions, 0);
});

test('stabilizeLucideRuntime clears icon markers after legacy page refreshes', () => {
    let conversions = 0;
    let markersCleared = 0;
    const lucide = { createIcons: () => { conversions += 1; } };
    const documentRoot = {
        querySelectorAll: () => [{ removeAttribute: () => { markersCleared += 1; } }],
    };

    stabilizeLucideRuntime(lucide, documentRoot);
    lucide.createIcons();
    stabilizeLucideRuntime(lucide, documentRoot);
    lucide.createIcons();

    assert.equal(conversions, 2);
    assert.equal(markersCleared, 2);
});

test('refreshIconPlaceholders leaves pending icons outside the requested region untouched', () => {
    const icon = (name) => {
        const attributes = new Map([['data-lucide', name]]);
        return {
            getAttribute: (key) => attributes.get(key) ?? null,
            setAttribute: (key, value) => attributes.set(key, value),
            removeAttribute: (key) => attributes.delete(key),
        };
    };
    const inside = icon('menu');
    const outside = icon('bell');
    const root = { matches: () => false, querySelectorAll: () => [inside] };
    const documentRoot = {
        querySelectorAll: (selector) => selector === 'i[data-lucide]'
            ? [inside, outside].filter((node) => node.getAttribute('data-lucide'))
            : [],
    };
    let convertedNames = [];
    const lucide = {
        createIcons: () => {
            convertedNames = documentRoot.querySelectorAll('i[data-lucide]').map((node) => node.getAttribute('data-lucide'));
        },
    };

    assert.equal(refreshIconPlaceholders(root, lucide, documentRoot), 1);
    assert.deepEqual(convertedNames, ['menu']);
    assert.equal(outside.getAttribute('data-lucide'), 'bell');
});
