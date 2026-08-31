import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { initializeAiModelsIndex } from '../../resources/js/admin/ai-models-index.js';
import { loadAiModelsIndex } from '../../resources/js/admin/ai-models-index-loader.js';

const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

class FakeClassList {
    constructor(className = '') {
        this.values = new Set(className.split(/\s+/).filter(Boolean));
    }

    toggle(value, force) {
        if (force === true) this.values.add(value);
        else if (force === false) this.values.delete(value);
        else if (this.values.has(value)) this.values.delete(value);
        else this.values.add(value);
    }

    remove(value) {
        this.values.delete(value);
    }

    contains(value) {
        return this.values.has(value);
    }
}

class FakeElement {
    constructor(className = '') {
        this.listeners = new Map();
        this.attributes = new Map();
        this.classList = new FakeClassList(className);
        this.dataset = {};
        this.textContent = '';
        this.hidden = false;
        this.disabled = false;
        this.children = [];
        this.href = '';
        this.focused = false;
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) || [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatch(type, event = {}) {
        const payload = { target: this, ...event };
        for (const listener of this.listeners.get(type) || []) listener(payload);
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    append(...children) {
        this.children.push(...children);
    }

    replaceChildren(...children) {
        this.children = [...children];
    }

    focus() {
        if (this.disabled) return;
        this.focused = true;
    }
}

class FakeDialog extends FakeElement {
    constructor(elements, closeButtons) {
        super();
        this.elements = elements;
        this.closeButtons = closeButtons;
        this.open = false;
    }

    querySelector(selector) {
        return this.elements.get(selector) || null;
    }

    querySelectorAll(selector) {
        return selector === '[data-ai-model-test-close]' ? this.closeButtons : [];
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
        this.dispatch('close');
    }

    getBoundingClientRect() {
        return { left: 100, right: 700, top: 100, bottom: 700 };
    }
}

class FakeAbortController {
    constructor() {
        this.signal = { aborted: false, onabort: null };
    }

    abort() {
        this.signal.aborted = true;
        this.signal.onabort?.();
    }
}

const copy = {
    labels: {
        test: 'Test',
        testing: 'Testing...',
        viewResult: 'View result',
        testingTitle: 'Testing connection',
        successTitle: 'Test passed',
        failureTitle: 'Needs attention',
        waitingSeconds: 'Waiting __SECONDS__ seconds',
        waitingInitial: 'Connecting',
        waitingChecking: 'Provider processing',
        waitingExtended: 'Still waiting',
        workspaceReady: 'Workspace ready',
        workspaceBasic: 'Basic connection ready',
        chat: 'Chat model',
        embedding: 'Embedding model',
        milliseconds: '__DURATION__ ms',
        unknown: 'Unknown',
    },
    clientDiagnoses: {
        session_expired: { title: 'Session expired', reason: 'Sign in again.', steps: ['Refresh'] },
        web_rate_limited: { title: 'Too many tests', reason: 'Wait.', steps: ['Wait'] },
        invalid_json: { title: 'Unreadable response', reason: 'Invalid JSON.', steps: ['Retry'] },
        network_failed: { title: 'Page request failed', reason: 'Network error.', steps: ['Check network'] },
        client_timeout: { title: 'Waited over 100 seconds', reason: 'The server may still be processing.', steps: ['Check usage'] },
        unexpected_error: { title: 'Unexpected error', reason: 'Try later.', steps: ['Retry'] },
    },
};

function fixture(fetchImpl, overrides = {}) {
    const selectors = [
        '[data-ai-model-test-title]',
        '[data-ai-model-test-announcement]',
        '[data-ai-model-test-icon-wrap]',
        '[data-ai-model-test-icon]',
        '[data-ai-model-test-model-name]',
        '[data-ai-model-test-model-id]',
        '[data-ai-model-test-loading]',
        '[data-ai-model-test-success]',
        '[data-ai-model-test-failure]',
        '[data-ai-model-test-waiting-copy]',
        '[data-ai-model-test-elapsed]',
        '[data-ai-model-test-success-message]',
        '[data-ai-model-test-http-status]',
        '[data-ai-model-test-duration]',
        '[data-ai-model-test-model-type]',
        '[data-ai-model-test-workspace]',
        '[data-ai-model-test-diagnosis-title]',
        '[data-ai-model-test-diagnosis-reason]',
        '[data-ai-model-test-steps]',
        '[data-ai-model-test-log]',
        '[data-ai-model-test-edit]',
        '[data-ai-model-test-retry]',
    ];
    const elements = new Map(selectors.map((selector) => [selector, new FakeElement('hidden')]));
    const initialIcon = elements.get('[data-ai-model-test-icon]');
    initialIcon.tagName = 'I';
    initialIcon.setAttribute('data-lucide', 'activity');
    elements.get('[data-ai-model-test-icon-wrap]').replaceChildren(initialIcon);
    const closeButtons = [new FakeElement(), new FakeElement()];
    const dialog = new FakeDialog(elements, closeButtons);
    const button = new FakeElement();
    const editFallback = new FakeElement();
    button.disabled = true;
    button.setAttribute('aria-disabled', 'true');
    button.textContent = 'Test';
    button.dataset = {
        modelId: '7',
        modelName: 'DeepSeek V4 Flash',
        providerModelId: 'deepseek-v4-flash',
        modelType: 'chat',
        testUrl: '/admin/ai-models/7/test',
        editUrl: '/admin/ai-models/7/edit',
    };
    const status = new FakeElement('hidden');
    const copyElement = new FakeElement();
    copyElement.textContent = JSON.stringify(copy);
    const meta = new FakeElement();
    meta.setAttribute('content', 'csrf-token-value');
    const ownerDocument = {
        activeElement: null,
        createElement(tagName = '') {
            const element = new FakeElement();
            element.tagName = String(tagName).toUpperCase();

            return element;
        },
        querySelector(selector) {
            return selector === 'meta[name="csrf-token"]' ? meta : null;
        },
    };
    const root = {
        dataset: { clientTimeoutMs: '100000', testInitializationError: 'Module failed.' },
        ownerDocument,
        querySelector(selector) {
            if (selector === '[data-ai-model-test-copy]') return copyElement;
            if (selector === '[data-ai-model-test-dialog]') return dialog;
            if (selector === '[data-ai-model-test-fallback="7"]') return editFallback;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '[data-ai-model-test-button]') return [button];
            if (selector === '[data-ai-model-test-status]') return [status];
            return [];
        },
    };
    const timers = { interval: null, timeout: null };
    let currentTime = 0;
    const controller = initializeAiModelsIndex(root, {
        fetchImpl,
        windowRef: { GeoFlowAdminUi: { refreshIcons(target) {
            const iconWrap = elements.get('[data-ai-model-test-icon-wrap]');
            const scope = target === dialog ? iconWrap : target;
            const placeholder = scope?.children?.find((child) => child.tagName === 'I' && child.getAttribute('data-lucide'));
            if (!placeholder) return;
            const svg = new FakeElement();
            svg.tagName = 'SVG';
            svg.setAttribute('data-icon-name', placeholder.getAttribute('data-lucide'));
            scope.replaceChildren(svg);
        } } },
        AbortControllerClass: FakeAbortController,
        now: () => currentTime,
        setIntervalImpl(callback) {
            timers.interval = callback;
            return 1;
        },
        clearIntervalImpl() {},
        setTimeoutImpl(callback) {
            timers.timeout = callback;
            return 2;
        },
        clearTimeoutImpl() {},
        ...overrides,
    });

    return {
        button,
        closeButtons,
        controller,
        dialog,
        editFallback,
        elements,
        root,
        status,
        timers,
        setCurrentTime(value) {
            currentTime = value;
        },
    };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('loads the page module through the fail-closed loader', () => {
    assert.match(appSource, /loadAiModelsIndex\([\s\S]*import\('\.\/admin\/ai-models-index'\)/);
});

test('opens immediately, counts real wait time, sends one CSRF-protected request, and caches success', async () => {
    let resolveRequest;
    const requests = [];
    const pending = new Promise((resolve) => { resolveRequest = resolve; });
    const view = fixture((url, options) => {
        requests.push({ url, options });
        return pending;
    });

    assert.equal(view.button.disabled, false);
    view.button.dispatch('click');
    view.button.dispatch('click');

    assert.equal(view.dialog.open, true);
    assert.equal(view.button.disabled, true);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/admin/ai-models/7/test');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-token-value');
    view.setCurrentTime(9000);
    view.timers.interval();
    assert.equal(view.elements.get('[data-ai-model-test-waiting-copy]').textContent, 'Provider processing');
    assert.equal(view.elements.get('[data-ai-model-test-elapsed]').textContent, 'Waiting 9 seconds');

    resolveRequest({
        ok: true,
        status: 200,
        async json() {
            return { success: true, message: 'Connection healthy', meta: { http_status: 200, duration_ms: 324, model_type: 'chat', workspace_ready: true } };
        },
    });
    await flush();

    assert.equal(view.button.textContent, 'View result');
    assert.equal(view.elements.get('[data-ai-model-test-http-status]').textContent, '200');
    assert.equal(view.elements.get('[data-ai-model-test-duration]').textContent, '324 ms');
    assert.equal(view.elements.get('[data-ai-model-test-workspace]').textContent, 'Workspace ready');
    assert.equal(view.elements.get('[data-ai-model-test-announcement]').textContent, 'Test passed. Connection healthy.');
    assert.equal(view.elements.get('[data-ai-model-test-icon-wrap]').children[0]?.getAttribute('data-icon-name'), 'circle-check');
    view.button.dispatch('click');
    assert.equal(requests.length, 1);
});

test('closing a pending test keeps the request running and does not force the dialog open again', async () => {
    let resolveRequest;
    let requestCount = 0;
    const pending = new Promise((resolve) => { resolveRequest = resolve; });
    const view = fixture(() => {
        requestCount += 1;
        return pending;
    });

    view.button.dispatch('click');
    view.closeButtons[0].dispatch('click');
    assert.equal(view.dialog.open, false);
    assert.equal(view.button.focused, false);
    assert.equal(view.editFallback.focused, true);
    view.editFallback.focused = false;
    view.timers.timeout();
    assert.equal(view.editFallback.focused, true);

    resolveRequest({
        ok: true,
        status: 200,
        async json() {
            return { success: true, message: 'Healthy', meta: { http_status: 200, duration_ms: 10, model_type: 'chat' } };
        },
    });
    await flush();

    assert.equal(view.dialog.open, false);
    assert.equal(view.button.textContent, 'View result');
    view.button.dispatch('click');
    assert.equal(view.dialog.open, true);
    assert.equal(requestCount, 1);
});

test('renders backend diagnosis and technical logs as text, exposes edit and retest, then restores focus', async () => {
    let calls = 0;
    const response = {
        ok: false,
        status: 401,
        async json() {
            return {
                success: false,
                message: '<img src=x onerror=alert(1)> Authentication failed',
                meta: {
                    diagnosis: {
                        code: 'authentication_failed',
                        title: 'API credential failed',
                        reason: 'The provider rejected it.',
                        steps: ['Copy the real key', 'Check the model ID'],
                        severity: 'error',
                    },
                },
            };
        },
    };
    const view = fixture(async () => {
        calls += 1;
        return response;
    });

    view.button.dispatch('click');
    await flush();

    assert.equal(view.elements.get('[data-ai-model-test-diagnosis-title]').textContent, 'API credential failed');
    assert.equal(view.elements.get('[data-ai-model-test-announcement]').textContent, 'Needs attention. API credential failed. The provider rejected it.');
    assert.equal(view.elements.get('[data-ai-model-test-log]').textContent, '<img src=x onerror=alert(1)> Authentication failed');
    assert.equal(view.elements.get('[data-ai-model-test-steps]').children.length, 2);
    assert.equal(view.elements.get('[data-ai-model-test-edit]').href, '/admin/ai-models/7/edit');
    view.elements.get('[data-ai-model-test-retry]').dispatch('click');
    await flush();
    assert.equal(calls, 2);
    view.closeButtons[0].dispatch('click');
    assert.equal(view.dialog.open, false);
    assert.equal(view.button.focused, true);
});

test('classifies web-layer and browser failures with actionable client diagnoses', async (t) => {
    const scenarios = [
        ['expired session', async () => ({ ok: false, status: 419, statusText: 'Page Expired', async json() { return { message: 'Page Expired' }; } }), 'Session expired'],
        ['expired admin login', async () => ({ ok: false, status: 401, statusText: 'Unauthorized', async json() { return { code: 'unauthenticated', message: 'Unauthenticated' }; } }), 'Session expired'],
        ['web rate limit', async () => ({ ok: false, status: 429, statusText: 'Too Many Requests', async json() { return { message: 'Too Many Requests' }; } }), 'Too many tests'],
        ['non JSON', async () => ({ ok: false, status: 502, statusText: 'Bad Gateway', async json() { throw new Error('invalid'); } }), 'Unreadable response'],
        ['network failure', async () => { throw new Error('offline'); }, 'Page request failed'],
    ];

    for (const [name, request, expectedTitle] of scenarios) {
        await t.test(name, async () => {
            const view = fixture(request);
            view.button.dispatch('click');
            await flush();
            assert.equal(view.elements.get('[data-ai-model-test-diagnosis-title]').textContent, expectedTitle);
        });
    }
});

test('stops page waiting at the client timeout and explains that server processing may continue', async () => {
    const view = fixture((url, options) => new Promise((resolve, reject) => {
        options.signal.onabort = () => reject(new Error('aborted'));
    }));

    view.button.dispatch('click');
    view.timers.timeout();
    await flush();

    assert.equal(view.elements.get('[data-ai-model-test-diagnosis-title]').textContent, 'Waited over 100 seconds');
    assert.match(view.elements.get('[data-ai-model-test-diagnosis-reason]').textContent, /still be processing/);
});

test('keeps test buttons disabled and announces a lazy module failure', async () => {
    const button = new FakeElement();
    const status = new FakeElement('hidden');
    const root = {
        dataset: { testInitializationError: 'Module failed.' },
        querySelectorAll(selector) {
            if (selector === '[data-ai-model-test-button]') return [button];
            if (selector === '[data-ai-model-test-status]') return [status];
            return [];
        },
    };

    const loaded = await loadAiModelsIndex(root, () => Promise.reject(new Error('chunk failed')));

    assert.equal(loaded, false);
    assert.equal(button.disabled, true);
    assert.equal(button.getAttribute('aria-disabled'), 'true');
    assert.equal(status.textContent, 'Module failed.');
    assert.equal(status.hidden, false);
});
