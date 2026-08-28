import assert from 'node:assert/strict';
import test from 'node:test';

import {
    initializeTaskIndexReadiness,
    normalizeTaskIndexReadiness,
} from '../../resources/js/admin/task-index-readiness.js';

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatch(type, properties = {}) {
        const event = { target: this, ...properties };
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));
    }
}

class FakeClassList {
    constructor() {
        this.values = new Set();
    }

    toggle(value, force) {
        if (force) this.values.add(value);
        else this.values.delete(value);
    }
}

class FakeElement extends FakeEventTarget {
    constructor(tag = 'div') {
        super();
        this.tag = tag;
        this.children = [];
        this.classList = new FakeClassList();
        this.className = '';
        this.hidden = false;
        this.href = '';
        this.textContent = '';
    }

    append(...children) {
        this.children.push(...children);
    }

    replaceChildren(...children) {
        this.children = children;
    }

    focus() {
        fakeRoot.activeElement = this;
    }
}

class FakeDialog extends FakeElement {
    constructor(elements) {
        super('dialog');
        this.dataset = {
            blockedTitle: '标题库配置需要处理',
            warningTitle: '请确认标题库风险',
        };
        this.elements = elements;
        this.open = false;
        this.animationCount = 0;
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }

    querySelectorAll(selector) {
        return selector === '[data-task-index-readiness-close]' ? this.elements.closeButtons : [];
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
        this.dispatch('close');
    }

    animate() {
        this.animationCount += 1;
    }

    getBoundingClientRect() {
        return { left: 100, right: 700, top: 100, bottom: 700 };
    }
}

class FakeWindow extends FakeEventTarget {
    constructor(reducedMotion = true) {
        super();
        this.reducedMotion = reducedMotion;
    }

    matchMedia() {
        return { matches: this.reducedMotion };
    }
}

let fakeRoot;

function report(overrides = {}) {
    return {
        status: 'blocked',
        summary: '还缺少 2 个标题',
        recommendation: '请补充标题或调整任务上限',
        library: { total: 4, used: 3, available: 1 },
        task: { remaining: 3 },
        issues: [{
            severity: 'blocking',
            title: '当前标题库的可用标题已耗尽',
            message: '无法继续生成',
            impact: '任务会暂停',
            suggestions: ['补充标题', '调整上限'],
        }],
        edit_url: '/admin/tasks/12/edit',
        manage_url: '/admin/title-libraries/7',
        ...overrides,
    };
}

function fixture({ initial = null, reducedMotion = true } = {}) {
    const title = new FakeElement();
    const summary = new FakeElement();
    const recommendation = new FakeElement();
    const issues = new FakeElement();
    const iconWrap = new FakeElement();
    const editLink = new FakeElement('a');
    const manageLink = new FakeElement('a');
    const closeButtons = [new FakeElement('button'), new FakeElement('button')];
    const stats = Object.fromEntries(['remaining', 'total', 'used', 'available'].map((key) => [key, new FakeElement()]));
    const elements = {
        '[data-task-index-readiness-title]': title,
        '[data-task-index-readiness-summary]': summary,
        '[data-task-index-readiness-recommendation]': recommendation,
        '[data-task-index-readiness-issues]': issues,
        '[data-task-index-readiness-icon-wrap]': iconWrap,
        '[data-task-index-readiness-edit]': editLink,
        '[data-task-index-readiness-manage]': manageLink,
        closeButtons,
        ...Object.fromEntries(Object.entries(stats).map(([key, value]) => [`[data-task-index-readiness-stat="${key}"]`, value])),
    };
    const dialog = new FakeDialog(elements);
    const initialSource = initial ? { textContent: JSON.stringify(initial) } : null;
    fakeRoot = {
        activeElement: null,
        createElement: (tag) => new FakeElement(tag),
        querySelector: (selector) => {
            if (selector === '[data-task-index-readiness-dialog]') return dialog;
            if (selector === '[data-task-index-readiness-initial]') return initialSource;
            return null;
        },
    };
    const windowRef = new FakeWindow(reducedMotion);
    const api = initializeTaskIndexReadiness(fakeRoot, windowRef);

    return { api, closeButtons, dialog, editLink, issues, manageLink, recommendation, stats, summary, title, windowRef };
}

test('normalizes missing and malformed counts for stable rendering', () => {
    assert.deepEqual(normalizeTaskIndexReadiness({
        status: 'warning',
        library: { total: '8', used: null, available: 'bad' },
        task: { remaining: '3' },
    }).stats, { remaining: 3, total: 8, used: 0, available: 0 });
});

test('renders the structured readiness report from the batch execution event', () => {
    const view = fixture();
    const trigger = new FakeElement('button');
    fakeRoot.activeElement = trigger;

    view.windowRef.dispatch('geoflow:task-title-readiness', { detail: { report: report(), trigger } });

    assert.equal(view.dialog.open, true);
    assert.equal(view.title.textContent, '标题库配置需要处理');
    assert.equal(view.summary.textContent, '还缺少 2 个标题');
    assert.equal(view.recommendation.textContent, '请补充标题或调整任务上限');
    assert.equal(view.stats.available.textContent, '1');
    assert.equal(view.issues.children[0].children[0].textContent, '当前标题库的可用标题已耗尽');
    assert.equal(view.editLink.href, '/admin/tasks/12/edit');
    assert.equal(view.manageLink.href, '/admin/title-libraries/7');

    view.closeButtons[1].dispatch('click');
    assert.equal(view.dialog.open, false);
    assert.equal(fakeRoot.activeElement, trigger);
});

test('opens a server-flashed report on load and respects reduced motion', () => {
    const view = fixture({ initial: report({ status: 'warning' }), reducedMotion: true });

    assert.equal(view.dialog.open, true);
    assert.equal(view.title.textContent, '请确认标题库风险');
    assert.equal(view.dialog.animationCount, 0);
});
