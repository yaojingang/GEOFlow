import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { normalizeGeoflowBaseUrl, originPermissionPattern } from '../../browser-extension/src/lib/url-policy.js';
import { configureTrustedStorage } from '../../browser-extension/src/lib/storage.js';
import { hasConflictingActiveTask, publicationClipboardText, resumeClaimedTask } from '../../browser-extension/src/lib/task-state.js';
import { runZhihuAnswerAdapter } from '../../browser-extension/src/adapters/zhihu-answer.js';

test('GEOFlow base URL accepts HTTPS and local HTTP while rejecting unsafe shapes', () => {
    assert.equal(normalizeGeoflowBaseUrl('https://geo.example.com/'), 'https://geo.example.com');
    assert.equal(normalizeGeoflowBaseUrl('http://localhost:8000/'), 'http://localhost:8000');
    assert.throws(() => normalizeGeoflowBaseUrl('http://geo.example.com'), /HTTPS/);
    assert.throws(() => normalizeGeoflowBaseUrl('https://user:secret@geo.example.com'), /credentials/);
    assert.throws(() => normalizeGeoflowBaseUrl('https://geo.example.com/?tenant=1'), /query/);
});

test('target permission is reduced to its HTTPS origin', () => {
    assert.equal(
        originPermissionPattern('https://www.zhihu.com/question/123?utm_source=geoflow'),
        'https://www.zhihu.com/*',
    );
});

test('extension storage is restricted to trusted extension contexts', async () => {
    const levels = [];
    const storage = {
        local: { setAccessLevel: async (value) => levels.push(['local', value.accessLevel]) },
        session: { setAccessLevel: async (value) => levels.push(['session', value.accessLevel]) },
    };

    await configureTrustedStorage(storage);

    assert.deepEqual(levels, [
        ['local', 'TRUSTED_CONTEXTS'],
        ['session', 'TRUSTED_CONTEXTS'],
    ]);
});

test('claimed work can be resumed after Chrome session storage is cleared', () => {
    const publication = {
        id: 42,
        status: 'in_progress',
        claim: { claimed_at: '2026-08-24T08:00:00Z' },
    };

    assert.deepEqual(resumeClaimedTask(null, publication, '2026-08-24T08:01:00Z'), {
        publication,
        tabId: null,
        startedAt: '2026-08-24T08:00:00Z',
    });
});

test('one side panel does not replace a different active work order', () => {
    const currentTask = { publication: { id: 42, status: 'in_progress' } };

    assert.equal(hasConflictingActiveTask(currentTask, { id: 42 }), false);
    assert.equal(hasConflictingActiveTask(currentTask, { id: 84 }), true);
});

test('Zhihu adapter blocks a mismatched account before touching the editor', () => {
    let editorQueried = false;
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return null;
            if (selector.includes('/people/')) {
                return { href: 'https://www.zhihu.com/people/another-user' };
            }
            editorQueried = true;
            return null;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, false);
    assert.equal(result.code, 'account_mismatch');
    assert.equal(editorQueried, false);
});

test('Zhihu adapter ignores unrelated profile links outside the account header', () => {
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return null;
            if (selector === 'a[href*="//www.zhihu.com/people/"]') {
                return { href: 'https://www.zhihu.com/people/geoflow' };
            }
            return null;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, false);
    assert.equal(result.code, 'login_required');
});

test('Zhihu adapter stops when human verification is present', () => {
    let editorQueried = false;
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return {};
            editorQueried = true;
            return null;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, false);
    assert.equal(result.code, 'human_verification_required');
    assert.equal(editorQueried, false);
});

test('Zhihu adapter fills an empty answer editor for the expected account', () => {
    const commands = [];
    const editor = {
        textContent: '',
        focus() {},
        dispatchEvent() {},
    };
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return null;
            return selector.includes('/people/')
                ? { href: 'https://www.zhihu.com/people/geoflow/' }
                : editor;
        },
        execCommand(command, _ui, value) {
            commands.push([command, value]);
            return true;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, true);
    assert.equal(result.code, 'draft_filled');
    assert.deepEqual(commands.at(-1), ['insertText', '回答正文']);
});

test('browser client discovers recovery epoch before writes and preserves uncertain failures', async () => {
    const { GeoFlowApiClient } = await import('../../browser-extension/src/lib/api-client.js');
    const originalFetch = globalThis.fetch;
    const calls = [];
    globalThis.fetch = async (url, options) => {
        calls.push({ url, ...options });
        if (url.endsWith('/session')) return { ok: true, json: async () => ({ success: true, data: { recovery: { supported: true, epoch: 'a'.repeat(32) } } }) };
        throw new Error('response lost');
    };
    try {
        const client = new GeoFlowApiClient({ baseUrl: 'https://geo.example', token: 'test-token' });
        await assert.rejects(client.request('/api/v1/browser-operations/publications/1/claim', { method: 'POST', body: {}, idempotencyKey: 'original-key' }), /Could not reach/);
        assert.equal(calls.length, 2);
        assert.equal(calls[0].method, 'GET');
        assert.equal(calls[1].headers['X-GEOFlow-Recovery-Epoch'], 'a'.repeat(32));
        assert.equal(calls[1].headers['X-Idempotency-Key'], 'original-key');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('browser client rejects malformed recovery discovery before sending any mutation', async () => {
    const { GeoFlowApiClient } = await import('../../browser-extension/src/lib/api-client.js');
    const originalFetch = globalThis.fetch;
    let calls = 0;
    globalThis.fetch = async () => {
        calls++;
        return { ok: true, json: async () => ({ success: true, data: { recovery: { supported: true, epoch: 'bad' } } }) };
    };
    try {
        const client = new GeoFlowApiClient({ baseUrl: 'https://geo.example', token: 'test-token' });
        await assert.rejects(client.request('/api/v1/browser-operations/publications/1/claim', { method: 'POST' }), /recovery/);
        assert.equal(calls, 1);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('assisted manual publishing copies immutable title and body with optional safe source link', () => {
    const payload = { title: 'Saved title', body_plain: 'Saved body', append_source_link: true, source_url: 'https://geo.example/article/1' };
    assert.equal(publicationClipboardText(payload, true), 'Saved title');
    assert.equal(publicationClipboardText(payload), 'Saved body\n\nhttps://geo.example/article/1');
    assert.equal(publicationClipboardText({ ...payload, append_source_link: false }), 'Saved body');
    assert.equal(publicationClipboardText({ ...payload, source_url: 'javascript:alert(1)' }), 'Saved body');
});


test('assisted clipboard consumes the producer plain-text contract and retains literal code', () => {
    const fixture = JSON.parse(readFileSync(new URL('../Fixtures/platform-web/clipboard.json', import.meta.url), 'utf8'));
    const payload = { title: fixture.title, body_plain: fixture.plain, body_markdown: fixture.markdown,
        append_source_link: true, source_url: fixture.source_url };
    assert.equal(publicationClipboardText(payload), fixture.plain + '\n\n' + fixture.source_url);
    assert.equal(publicationClipboardText(payload, true), fixture.title);
    assert.equal(publicationClipboardText({ ...payload, append_source_link: false }), fixture.plain);
    assert.ok(publicationClipboardText(payload).includes('**literal** [code](value)'));
    assert.ok(!publicationClipboardText(payload).includes('## Heading'));
    assert.ok(!publicationClipboardText(payload).includes('![photo]'));
});
