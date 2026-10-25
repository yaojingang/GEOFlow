import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/assets/js/admin-link-pool-theme-adaptation.js', import.meta.url), 'utf8');

function harness(responses, { savedTask = false, readOnly = false, confirm = true, settings = null, valid = true } = {}) {
    const element = () => ({ hidden: true, disabled: false, dataset: {}, textContent: '', handlers: {}, addEventListener(type, callback) { this.handlers[type] = callback; } });
    const start = readOnly ? null : element();
    const rollback = readOnly ? null : element();
    const status = element();
    if (savedTask) {
        status.dataset = { taskUrl: '/tasks/1', taskId: '1' };
        status.textContent = '正在适配主题';
    }
    const actions = element();
    const root = { dataset: { storeUrl: '/tasks', siteKey: 'primary', rollbackBase: '/tasks' }, querySelector(selector) {
        return { '[data-adaptation-start]': start, '[data-adaptation-rollback]': rollback, '[data-adaptation-status]': status, '[data-adaptation-actions]': actions }[selector] || null;
    } };
    const settingsForm = settings ? {
        action: '/settings', dataset: { savedValues: JSON.stringify({ heading: '推荐内容', default_limit: 4, rotation_hours: 24, enabled: '0' }) },
        values: { site_key: 'primary', revision: '0', heading: '推荐内容', default_limit: '4', rotation_hours: '24', enabled: '0', ...settings },
        revision: { value: '0' }, events: [], reset() { this.events.push('reset'); }, inert: false, reportValidity: () => valid,
        querySelector: () => settingsForm.revision, dispatchEvent: event => settingsForm.events.push(event.type),
    } : null;
    const timers = new Map();
    const requests = [];
    let id = 0;
    let reloads = 0;
    const window = { location: { reload() { reloads++; } }, AdminActionDialog: { confirm: async () => confirm }, handlers: {}, addEventListener(type, callback) { this.handlers[type] = callback; } };
    vm.runInNewContext(source, {
        document: { querySelector: selector => selector === '[data-theme-adaptation]' ? root : selector === '[data-recommendation-settings]' ? settingsForm : { content: 'csrf-token' } },
        FormData: class { constructor(form) { return Object.entries(form.values)[Symbol.iterator](); } },
        CustomEvent: class { constructor(type) { this.type = type; } },
        window, AbortController, Date,
        setTimeout: (callback, delay) => { timers.set(++id, { callback, delay }); return id; },
        clearTimeout: timer => timers.delete(timer),
        fetch: async (url, options) => {
            requests.push({ url, options });
            const next = responses.shift();
            if (next instanceof Error) throw next;
            assert.ok(next, 'Unexpected additional request');
            return { status: next.status || 200, ok: !next.status || next.status < 400, redirected: next.redirected || false,
                headers: { get: () => next.contentType || 'application/json' }, json: async () => next.body };
        },
    });
    return { start, rollback, status, actions, settingsForm, requests, window, timers, get reloads() { return reloads; }, async poll() {
        const timer = [...timers.entries()][0];
        assert.ok(timer, 'Expected a pending status poll');
        timers.delete(timer[0]);
        await timer[1].callback();
    } };
}

test('one click stays disabled while polling and reloads after verified completion', async () => {
    const page = harness([{ body: { id: '1', status: 'queued', phase: 'queued', status_url: '/tasks/1' } },
        { body: { id: '1', status: 'completed', phase: 'completed' } }]);
    await page.start.handlers.click();
    assert.equal(page.start.disabled, true);
    assert.equal(page.requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(page.requests[0].options.credentials, 'same-origin');
    await page.poll();
    assert.equal(page.reloads, 1);
    assert.equal(page.requests[1].options.method, 'GET');
    assert.equal(page.timers.size, 0);
});

test('a failed task exposes its reason and enables a retry', async () => {
    const page = harness([{ body: { status: 'failed', error_message: 'AI 未能确定安全的插入位置' } }]);
    await page.start.handlers.click();
    assert.equal(page.status.textContent, 'AI 未能确定安全的插入位置');
    assert.equal(page.start.disabled, false);
    assert.equal(page.start.textContent, '重试适配并开启推荐');
});

test('an expired session stops polling and gives a refresh action', async () => {
    const page = harness([{ status: 419 }], { savedTask: true });
    await page.poll();
    assert.match(page.status.textContent, /登录状态已过期/);
    assert.equal(page.timers.size, 0);
});

test('temporary polling failure backs off and later completion succeeds', async () => {
    const page = harness([new Error('Temporary connection error'), { body: { status: 'completed', phase: 'completed' } }], { savedTask: true });
    await page.poll();
    assert.deepEqual([...page.timers.values()].map(timer => timer.delay), [10000]);
    await page.poll();
    assert.equal(page.reloads, 1);
});

test('canceling rollback sends no request and leaving the page cancels polling', async () => {
    const page = harness([], { savedTask: true, confirm: false });
    await page.rollback.handlers.click();
    assert.equal(page.requests.length, 0);
    page.window.handlers.pagehide();
    assert.equal(page.timers.size, 0);
});

test('a prolonged network interruption keeps checking until the task completes', async () => {
    const page = harness([...Array.from({ length: 7 }, () => new Error('Failed to fetch')),
        { body: { status: 'completed', phase: 'completed' } }], { savedTask: true });
    for (let attempt = 0; attempt < 7; attempt++) {
        await page.poll();
        assert.match(page.status.textContent, /正在重新查询状态/);
        assert.equal(page.start.disabled, true);
        assert.equal(page.timers.size, 1);
    }
    await page.poll();
    assert.equal(page.reloads, 1);
    assert.equal(page.timers.size, 0);
});

test('read-only status viewing never creates or rolls back a task', () => {
    const page = harness([], { readOnly: true });
    assert.equal(page.requests.length, 0);
    assert.equal(page.timers.size, 0);
});

test('a lost create response explains retry without promising an active poll', async () => {
    const page = harness([new Error('Failed to fetch')]);
    await page.start.handlers.click();
    assert.match(page.status.textContent, /再次点击或刷新/);
    assert.doesNotMatch(page.status.textContent, /正在重新查询/);
    assert.equal(page.start.disabled, false);
    assert.equal(page.timers.size, 0);
});


test('one click saves edited settings before creating the task and clears the dirty form guard', async () => {
    const page = harness([{ body: { revision: 1 } }, { body: { status: 'completed', phase: 'completed' } }], { settings: { heading: '为你推荐', default_limit: '2' } });
    await page.start.handlers.click();
    assert.deepEqual(page.requests.map(request => request.url), ['/settings', '/tasks']);
    assert.equal(JSON.parse(page.requests[0].options.body).heading, '为你推荐');
    assert.equal(page.settingsForm.revision.value, 1);
    assert.deepEqual(page.settingsForm.events, ['gf:saved']);
    assert.equal(page.reloads, 1);
});

test('stale settings keep edits and prevent task creation with an actionable reason', async () => {
    const page = harness([{ status: 422, body: { message: 'Validation failed', errors: { revision: ['站点设置已更新，请重新载入后保存。'] } } }], { settings: { heading: '保留标题' } });
    await page.start.handlers.click();
    assert.equal(page.requests.length, 1);
    assert.match(page.status.textContent, /站点设置已更新/);
    assert.equal(page.settingsForm.values.heading, '保留标题');
    assert.equal(page.settingsForm.events.length, 0);
    assert.equal(page.settingsForm.inert, false);
    assert.equal(page.start.disabled, false);
});

test('unchanged settings avoid an extra save and invalid fields prevent any request', async () => {
    const page = harness([{ body: { status: 'queued', phase: 'queued' } }], { settings: {} });
    await page.start.handlers.click();
    assert.deepEqual(page.requests.map(request => request.url), ['/tasks']);
    assert.equal(page.settingsForm.inert, true);
    assert.deepEqual(page.settingsForm.events, ['gf:saved']);
    const invalid = harness([], { settings: { heading: '' }, valid: false });
    await invalid.start.handlers.click();
    assert.equal(invalid.requests.length, 0);
    assert.equal(invalid.start.disabled, false);
});


test('rollback resets unsaved form edits after restoration and retains edits if restoration fails', async () => {
    const restored = harness([{ body: { status: 'rolled_back', phase: 'rolled_back' } }], { settings: { heading: '未保存标题' } });
    await restored.rollback.handlers.click();
    assert.deepEqual(restored.settingsForm.events, ['reset']);
    assert.equal(restored.reloads, 1);
    const failed = harness([{ status: 422, body: { errors: { task: ['主题已更新，请检查当前版本。'] } } }], { settings: { heading: '保留标题' } });
    await failed.rollback.handlers.click();
    assert.equal(failed.settingsForm.events.length, 0);
    assert.equal(failed.settingsForm.values.heading, '保留标题');
    assert.equal(failed.settingsForm.inert, false);
    assert.equal(failed.reloads, 0);
});
