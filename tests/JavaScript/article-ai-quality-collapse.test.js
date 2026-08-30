import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    ARTICLE_AI_QUALITY_COLLAPSE_STORAGE_KEY,
    setupArticleAiQualityCollapse,
} from '../../resources/js/admin/article-ai-quality-collapse.js';

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
    constructor({ classes = [], dataset = {} } = {}) {
        this.attributes = new Map();
        this.classList = new FakeClassList(classes);
        this.dataset = { ...dataset };
        this.hidden = false;
        this.listeners = new Map();
        this.textContent = '';
        this.title = '';
        this.elements = new Map();
    }

    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }

    click() {
        this.listeners.get('click')?.({ target: this });
    }

    querySelector(selector) {
        return this.elements.get(selector) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }
}

function collapseFixture(initialStorage = '1') {
    const root = new FakeElement({ dataset: { collapsed: 'false' } });
    const header = new FakeElement({ classes: ['border-b', 'px-6', 'py-5'] });
    const body = new FakeElement();
    const expandedCopy = new FakeElement();
    const compactSummary = new FakeElement();
    compactSummary.hidden = true;
    const optimizationOpen = new FakeElement();
    const toggle = new FakeElement({
        dataset: {
            collapseLabel: '收起质检',
            expandLabel: '展开质检',
        },
    });
    const label = new FakeElement();
    const icon = new FakeElement();

    for (const [selector, element] of [
        ['[data-ai-quality-collapse-header]', header],
        ['[data-ai-quality-collapse-body]', body],
        ['[data-ai-quality-expanded-copy]', expandedCopy],
        ['[data-ai-quality-compact-summary]', compactSummary],
        ['[data-ai-quality-collapse-toggle]', toggle],
        ['[data-ai-quality-collapse-label]', label],
        ['[data-ai-quality-collapse-icon]', icon],
        ['[data-ai-optimization-open]', optimizationOpen],
    ]) root.elements.set(selector, element);

    const values = new Map([[ARTICLE_AI_QUALITY_COLLAPSE_STORAGE_KEY, initialStorage]]);
    const windowRef = {
        localStorage: {
            getItem(key) {
                return values.get(key) ?? null;
            },
            setItem(key, value) {
                values.set(key, value);
            },
        },
    };
    const documentRef = {
        querySelector(selector) {
            return selector === '[data-ai-quality-collapsible]' ? root : null;
        },
    };

    return { body, compactSummary, documentRef, expandedCopy, header, icon, label, optimizationOpen, root, toggle, values, windowRef };
}

test('the article quality panel restores a compact two-row state and can be expanded again', () => {
    const fixture = collapseFixture('1');

    const controller = setupArticleAiQualityCollapse(fixture);

    assert.equal(controller.collapsed, true);
    assert.equal(fixture.root.dataset.collapsed, 'true');
    assert.equal(fixture.body.hidden, true);
    assert.equal(fixture.expandedCopy.hidden, true);
    assert.equal(fixture.compactSummary.hidden, false);
    assert.equal(fixture.toggle.attributes.get('aria-expanded'), 'false');
    assert.equal(fixture.toggle.attributes.get('aria-label'), '展开质检');
    assert.equal(fixture.label.textContent, '展开质检');
    assert.equal(fixture.header.classList.contains('px-4'), true);
    assert.equal(fixture.header.classList.contains('py-3'), true);
    assert.equal(fixture.icon.classList.contains('rotate-180'), true);

    fixture.toggle.click();

    assert.equal(controller.collapsed, false);
    assert.equal(fixture.root.dataset.collapsed, 'false');
    assert.equal(fixture.body.hidden, false);
    assert.equal(fixture.expandedCopy.hidden, false);
    assert.equal(fixture.compactSummary.hidden, true);
    assert.equal(fixture.toggle.attributes.get('aria-expanded'), 'true');
    assert.equal(fixture.label.textContent, '收起质检');
    assert.equal(fixture.values.get(ARTICLE_AI_QUALITY_COLLAPSE_STORAGE_KEY), '0');
});

test('the article quality collapse behavior is loaded only on pages that render the panel', () => {
    assert.match(
        appSource,
        /loadPageModule\('\[data-ai-quality-collapsible\]'[\s\S]*import\('\.\/admin\/article-ai-quality-collapse'\)/,
    );
});

test('opening AI optimization reveals a quality panel that was previously collapsed', () => {
    const fixture = collapseFixture('1');
    const controller = setupArticleAiQualityCollapse(fixture);

    fixture.optimizationOpen.click();

    assert.equal(controller.collapsed, false);
    assert.equal(fixture.body.hidden, false);
    assert.equal(fixture.toggle.attributes.get('aria-expanded'), 'true');
    assert.equal(fixture.values.get(ARTICLE_AI_QUALITY_COLLAPSE_STORAGE_KEY), '0');
});

test('the quality panel remains usable when browser storage is unavailable', () => {
    const fixture = collapseFixture('0');
    Object.defineProperty(fixture.windowRef, 'localStorage', {
        get() {
            throw new Error('storage blocked');
        },
    });

    const controller = setupArticleAiQualityCollapse(fixture);
    fixture.toggle.click();

    assert.equal(controller.collapsed, true);
    assert.equal(fixture.body.hidden, true);
    assert.equal(fixture.toggle.attributes.get('aria-expanded'), 'false');
});
