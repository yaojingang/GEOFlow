import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    initializeArticleAiQualityProgress,
    renderArticleAiQualityProgress,
} from '../../resources/js/admin/article-ai-quality-progress.js';
import { loadArticleAiQualityProgress } from '../../resources/js/admin/article-ai-quality-progress-loader.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

class FakeClassList {
    constructor(values = ['hidden']) {
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
    constructor() {
        this.attributes = new Map();
        this.classList = new FakeClassList();
        this.style = {};
        this.textContent = '';
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }
}

function progressFixture() {
    const elements = new Map();
    for (const selector of [
        '[data-ai-quality-progress-message]',
        '[data-ai-quality-progress-detail]',
        '[data-ai-quality-progress-bar]',
        '[data-ai-quality-progress-percent]',
        '[data-ai-quality-progress-segments]',
        '[data-ai-quality-progress-elapsed]',
        '[data-ai-quality-progress-error]',
    ]) elements.set(selector, new FakeElement());
    const cardElements = new Map([
        ['[data-ai-quality-compact-progress]', new FakeElement()],
        ['[data-ai-quality-compact-message]', new FakeElement()],
    ]);

    return {
        attributes: new Map(),
        dataset: {
            active: 'true',
            deadlineAt: '',
            deadlineExceeded: 'AI 质检未在约定时间内完成，请稍后重试。',
            loadUnavailable: '进度组件加载失败，请刷新页面查看最新结果。',
            pollUnavailable: '暂时无法读取最新进度，系统会自动重试。',
            sessionExpired: '登录状态已失效，请刷新页面后重新登录。',
            statusUrl: '/admin/articles/504/ai-quality/status',
        },
        elements,
        cardElements,
        closest(selector) {
            if (selector !== '[data-ai-quality-collapsible]') return null;

            return {
                querySelector(cardSelector) {
                    return cardElements.get(cardSelector) ?? null;
                },
            };
        },
        querySelector(selector) {
            return elements.get(selector) ?? null;
        },
        setAttribute(name, value) {
            this.attributes.set(name, value);
        },
    };
}

const resultLabel = new FakeElement();
globalThis.document = {
    querySelector(selector) {
        return selector === '[data-ai-quality-result-label]' ? resultLabel : null;
    },
};

test('the quality progress renderer updates the live phase, truthful percentage and segment count', () => {
    const root = progressFixture();

    assert.equal(renderArticleAiQualityProgress(root, {
        active: true,
        completed_segments: 2,
        detail: 'AI 正在逐段检查事实、数据、广告法风险和知识库一致性。',
        elapsed_label: '已用时 20 秒，常规文章最长约 1 分钟',
        message: '已完成 2/4 个内容分段',
        progress_percent: 56,
        result_label: '抽样质检中',
        segments_label: '已完成 2 / 4 个分段',
        status: 'running',
    }), true);

    assert.equal(root.elements.get('[data-ai-quality-progress-message]').textContent, '已完成 2/4 个内容分段');
    assert.equal(root.elements.get('[data-ai-quality-progress-detail]').textContent, 'AI 正在逐段检查事实、数据、广告法风险和知识库一致性。');
    assert.equal(root.elements.get('[data-ai-quality-progress-percent]').textContent, '56%');
    assert.equal(root.elements.get('[data-ai-quality-progress-bar]').value, 56);
    assert.equal(root.elements.get('[data-ai-quality-progress-bar]').attributes.get('aria-valuenow'), '56');
    assert.equal(root.elements.get('[data-ai-quality-progress-segments]').textContent, '已完成 2 / 4 个分段');
    assert.equal(root.elements.get('[data-ai-quality-progress-elapsed]').textContent, '已用时 20 秒，常规文章最长约 1 分钟');
    assert.equal(root.cardElements.get('[data-ai-quality-compact-progress]').textContent, '56%');
    assert.equal(root.cardElements.get('[data-ai-quality-compact-message]').textContent, '已完成 2/4 个内容分段');
    assert.equal(resultLabel.textContent, '抽样质检中');
    assert.equal(root.attributes.get('aria-busy'), 'true');
});

test('active progress keeps polling and a terminal response refreshes the finished result', async () => {
    const root = progressFixture();
    const scheduled = [];
    let reloadCount = 0;
    let payload = {
        active: true,
        detail: '正在检查',
        message: '正在质检',
        next_poll_ms: 2000,
        progress_percent: 56,
        segments_label: '2 / 4',
    };
    const controller = initializeArticleAiQualityProgress(root, {
        fetchAction: async () => ({ ok: true, status: 200, json: async () => payload }),
        reloadAction() {
            reloadCount += 1;
        },
        scheduleAction(callback, delay) {
            scheduled.push({ callback, delay });
        },
    });

    await controller.poll();
    assert.equal(scheduled.at(-1).delay, 2000);

    payload = {
        active: false,
        detail: '结果已生成',
        message: '质检完成',
        progress_percent: 100,
        reload: true,
        segments_label: '4 / 4',
    };
    await controller.poll();
    assert.equal(root.attributes.get('aria-busy'), 'false');
    assert.equal(scheduled.at(-1).delay, 650);

    scheduled.at(-1).callback();
    assert.equal(reloadCount, 1);
});

test('two polling failures reveal a localized retry message without raw error details', async () => {
    const root = progressFixture();
    const controller = initializeArticleAiQualityProgress(root, {
        fetchAction: async () => {
            throw new Error('secret provider response');
        },
        scheduleAction() {},
    });

    await controller.poll();
    await controller.poll();

    const error = root.elements.get('[data-ai-quality-progress-error]');
    assert.equal(error.textContent, root.dataset.pollUnavailable);
    assert.equal(error.classList.contains('hidden'), false);
    assert.doesNotMatch(error.textContent, /secret provider/);
});

test('an expired session stops polling and tells the administrator what to do', async () => {
    const root = progressFixture();
    const scheduled = [];
    const controller = initializeArticleAiQualityProgress(root, {
        fetchAction: async () => ({ ok: false, status: 419 }),
        scheduleAction(callback, delay) {
            scheduled.push({ callback, delay });
        },
    });

    await controller.poll();

    const error = root.elements.get('[data-ai-quality-progress-error]');
    assert.equal(error.textContent, root.dataset.sessionExpired);
    assert.equal(error.classList.contains('hidden'), false);
    assert.equal(scheduled.length, 1);
});

test('the browser keeps asking for the authoritative terminal state after the persisted deadline', async () => {
    const root = progressFixture();
    root.dataset.deadlineAt = '2026-08-29T00:00:00.000Z';
    let fetchCount = 0;
    const scheduled = [];
    const controller = initializeArticleAiQualityProgress(root, {
        fetchAction: async () => {
            fetchCount += 1;

            return {
                ok: true,
                status: 200,
                json: async () => ({ active: false, reconciling: true, next_poll_ms: 5000 }),
            };
        },
        nowAction: () => Date.parse('2026-08-29T00:00:06.000Z'),
        scheduleAction(callback, delay) {
            scheduled.push({ callback, delay });
        },
    });

    await controller.poll();

    assert.equal(fetchCount, 1);
    assert.equal(root.attributes.get('aria-busy'), 'true');
    assert.equal(scheduled.at(-1).delay, 5000);
});

test('the browser stops deadline reconciliation after a bounded extra minute', async () => {
    const root = progressFixture();
    root.dataset.deadlineAt = '2026-08-29T00:00:00.000Z';
    let fetchCount = 0;
    const controller = initializeArticleAiQualityProgress(root, {
        fetchAction: async () => {
            fetchCount += 1;

            return { ok: true, status: 200, json: async () => ({ active: true }) };
        },
        nowAction: () => Date.parse('2026-08-29T00:01:06.000Z'),
        scheduleAction() {},
    });

    await controller.poll();

    assert.equal(fetchCount, 0);
    assert.equal(root.attributes.get('aria-busy'), 'false');
    assert.equal(root.elements.get('[data-ai-quality-progress-error]').textContent, root.dataset.deadlineExceeded);
});

test('the progress chunk has a localized recovery path and is wired into the page loader', async () => {
    const root = progressFixture();

    assert.equal(await loadArticleAiQualityProgress(root, async () => {
        throw new Error('chunk failed');
    }), false);
    assert.equal(root.attributes.get('aria-busy'), 'false');
    assert.equal(root.elements.get('[data-ai-quality-progress-error]').textContent, root.dataset.loadUnavailable);
    assert.match(
        appSource,
        /loadPageModule\('\[data-ai-quality-progress\]'[\s\S]*loadArticleAiQualityProgress[\s\S]*import\('\.\/admin\/article-ai-quality-progress'\)/,
    );
});
