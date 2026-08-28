import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    initializeTitleGenerationProgress,
    renderTitleGenerationProgress,
} from '../../resources/js/admin/title-generation-progress.js';
import { loadTitleGenerationProgress } from '../../resources/js/admin/title-generation-progress-loader.js';

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
        '[data-generation-status]',
        '[data-generation-progress-bar]',
        '[data-generation-progress-label]',
        '[data-generation-error]',
        '[data-generation-notice]',
        '[data-generation-retry]',
        '[data-generation-cancel]',
        '[data-generation-requested-count]',
        '[data-generation-generated-count]',
        '[data-generation-saved-count]',
        '[data-generation-duplicate-count]',
        '[data-generation-batch-count]',
    ]) elements.set(selector, new FakeElement());

    return {
        attributes: new Map(),
        dataset: {
            active: 'true',
            loadUnavailable: 'Progress controls could not be loaded. Refresh to try again.',
            pollUnavailable: 'Polling is temporarily unavailable.',
            sessionExpired: 'Session expired.',
            statusUrl: '/status',
            statusQueued: 'Queued',
            statusRunning: 'Running',
            statusCompleted: 'Completed',
            statusPartial: 'Partial',
            statusFailed: 'Failed',
            statusCancelled: 'Cancelled',
        },
        elements,
        querySelector(selector) {
            return elements.get(selector) ?? null;
        },
        setAttribute(name, value) {
            this.attributes.set(name, value);
        },
    };
}

test('poll payload announces notice and error while updating retry and cancel visibility', () => {
    const root = progressFixture();

    assert.equal(renderTitleGenerationProgress(root, {
        active: false,
        batch_count: 2,
        duplicate_count: 3,
        generated_count: 8,
        last_error: 'Provider unavailable.',
        notice: 'Saved titles remain available.',
        progress_percent: 50,
        requested_count: 10,
        retryable: true,
        saved_count: 5,
        status: 'partial',
    }), false);

    assert.equal(root.elements.get('[data-generation-error]').textContent, 'Provider unavailable.');
    assert.equal(root.elements.get('[data-generation-error]').classList.contains('hidden'), false);
    assert.equal(root.elements.get('[data-generation-notice]').textContent, 'Saved titles remain available.');
    assert.equal(root.elements.get('[data-generation-notice]').classList.contains('hidden'), false);
    assert.equal(root.elements.get('[data-generation-retry]').classList.contains('hidden'), false);
    assert.equal(root.elements.get('[data-generation-cancel]').classList.contains('hidden'), true);
    assert.equal(root.attributes.get('aria-busy'), 'false');

    assert.equal(renderTitleGenerationProgress(root, {
        active: true,
        progress_percent: 60,
        retryable: false,
        status: 'running',
    }), true);
    assert.equal(root.elements.get('[data-generation-retry]').classList.contains('hidden'), true);
    assert.equal(root.elements.get('[data-generation-cancel]').classList.contains('hidden'), false);
    assert.equal(root.attributes.get('aria-busy'), 'true');
});

test('two polling failures reveal the localized live error without exposing exception text', async () => {
    const root = progressFixture();
    const scheduled = [];
    const controller = initializeTitleGenerationProgress(root, {
        fetchAction: async () => {
            throw new Error('raw provider exception');
        },
        reloadAction() {},
        scheduleAction(callback) {
            scheduled.push(callback);
        },
    });

    await controller.poll();
    await controller.poll();

    const error = root.elements.get('[data-generation-error]');
    assert.equal(error.textContent, 'Polling is temporarily unavailable.');
    assert.equal(error.classList.contains('hidden'), false);
    assert.equal(root.attributes.get('aria-busy'), 'false');
    assert.doesNotMatch(error.textContent, /raw provider exception/);
    assert.ok(scheduled.length >= 3);
});

test('an expired polling session is announced and stops further polling', async () => {
    const root = progressFixture();
    const scheduled = [];
    const controller = initializeTitleGenerationProgress(root, {
        fetchAction: async () => ({ ok: false, status: 419 }),
        reloadAction() {},
        scheduleAction(callback) {
            scheduled.push(callback);
        },
    });

    await controller.poll();

    assert.equal(root.elements.get('[data-generation-error]').textContent, 'Session expired.');
    assert.equal(root.elements.get('[data-generation-error]').classList.contains('hidden'), false);
    assert.equal(root.attributes.get('aria-busy'), 'false');
    assert.equal(scheduled.length, 1);
});

test('chunk and initializer failures stop busy state and announce a localized recoverable error', async () => {
    for (const loader of [
        async () => {
            throw new Error('raw chunk failure');
        },
        async () => ({
            initializeTitleGenerationProgress() {
                throw new Error('raw initializer failure');
            },
        }),
    ]) {
        const root = progressFixture();
        const retry = root.elements.get('[data-generation-retry]');
        const cancel = root.elements.get('[data-generation-cancel]');
        retry.classList.toggle('hidden', false);
        cancel.classList.toggle('hidden', false);

        assert.equal(await loadTitleGenerationProgress(root, loader), false);
        assert.equal(root.attributes.get('aria-busy'), 'false');
        assert.equal(root.elements.get('[data-generation-error]').textContent, root.dataset.loadUnavailable);
        assert.equal(root.elements.get('[data-generation-error]').classList.contains('hidden'), false);
        assert.equal(retry.classList.contains('hidden'), false);
        assert.equal(cancel.classList.contains('hidden'), false);
        assert.doesNotMatch(root.elements.get('[data-generation-error]').textContent, /raw/);
    }
});

test('the progress chunk is loaded through the recoverable loader', () => {
    assert.match(
        appSource,
        /loadPageModule\('\[data-title-generation-progress\]'[\s\S]*loadTitleGenerationProgress[\s\S]*import\('\.\/admin\/title-generation-progress'\)/,
    );
});

test('a fetch that never resolves times out, retries, and can later reveal the retry action', async () => {
    const root = progressFixture();
    const scheduled = [];
    let requestCount = 0;
    const controller = initializeTitleGenerationProgress(root, {
        fetchAction: async () => {
            requestCount += 1;
            if (requestCount <= 2) return new Promise(() => {});

            return {
                ok: true,
                status: 200,
                async json() {
                    return {
                        active: false,
                        last_error: 'The previous run can be continued.',
                        progress_percent: 25,
                        retryable: true,
                        status: 'partial',
                    };
                },
            };
        },
        pollTimeoutMs: 5,
        reloadAction() {},
        scheduleAction(callback) {
            scheduled.push(callback);
        },
    });

    await controller.poll();
    await controller.poll();
    assert.equal(root.elements.get('[data-generation-error]').textContent, root.dataset.pollUnavailable);
    assert.ok(scheduled.length >= 3);

    await controller.poll();
    assert.equal(root.elements.get('[data-generation-retry]').classList.contains('hidden'), false);
    assert.equal(root.elements.get('[data-generation-cancel]').classList.contains('hidden'), true);
    assert.equal(root.attributes.get('aria-busy'), 'false');
});

test('a response body that never resolves is covered by the polling timeout', async () => {
    const root = progressFixture();
    const scheduled = [];
    const controller = initializeTitleGenerationProgress(root, {
        fetchAction: async () => ({
            ok: true,
            status: 200,
            json: async () => new Promise(() => {}),
        }),
        pollTimeoutMs: 5,
        reloadAction() {},
        scheduleAction(callback) {
            scheduled.push(callback);
        },
    });

    const settled = await Promise.race([
        controller.poll().then(() => true),
        new Promise((resolve) => setTimeout(() => resolve(false), 50)),
    ]);

    assert.equal(settled, true);
    assert.ok(scheduled.length >= 2);
});
