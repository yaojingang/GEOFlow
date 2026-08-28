import test from 'node:test';
import assert from 'node:assert/strict';

import { normalizeGeoflowBaseUrl, originPermissionPattern } from '../../browser-extension/src/lib/url-policy.js';
import { configureTrustedStorage } from '../../browser-extension/src/lib/storage.js';
import { hasConflictingActiveTask, resumeClaimedTask } from '../../browser-extension/src/lib/task-state.js';
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
