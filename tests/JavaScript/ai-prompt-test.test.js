import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizePrompt, resolvePromptAnchor, createEventDecoder, requestJson, isRunProcessing, pollRunStatus, abortAfter, canStartDiagnosis } from '../../resources/js/admin/ai-prompt-test.js';

test('SSE survives byte-sized chunks, CRLF, unicode and multiline data', () => {
    const events = [];
    const parser = createEventDecoder((event) => events.push(event));
    const input = ': keepalive\r\ndata: {"type":"delta",\r\ndata: "text":"中文😀"}\r\n\r\ndata: {"type":"article_complete"}\n\n';
    for (const character of input) parser.push(character);
    parser.finish();
    assert.deepEqual(events, [{ type: 'delta', text: '中文😀' }, { type: 'article_complete' }]);
});

test('truncated SSE and malformed data are errors, never successful completion', () => {
    const parser = createEventDecoder(() => {});
    parser.push('data: {"type":"delta"}\n');
    assert.throws(() => parser.finish(), /incomplete_event/);
    assert.throws(() => createEventDecoder(() => {}).push('data: broken\n\n'));
});

test('prompt normalization preserves significant leading and trailing whitespace', () => {
    assert.equal(normalizePrompt('  \r\n中文\r\n '), '  \n中文\n ');
});

test('anchors use UTF16 ranges and uniquely relocate unchanged surrounding text', () => {
    const snapshot = '😀标题\n引用原句\n后一行';
    const anchor = { start: 5, end: 9, quote: '引用原句', before: '😀标题\n', after: '\n后一行' };
    assert.deepEqual(resolvePromptAnchor(snapshot, snapshot, anchor), { start: 5, end: 9 });
    assert.deepEqual(resolvePromptAnchor(`新增段\n${snapshot}`, snapshot, anchor), { start: 9, end: 13 });
    assert.equal(resolvePromptAnchor(`${snapshot}\n${snapshot}`, snapshot, anchor), null);
    assert.equal(resolvePromptAnchor(snapshot.replace('引用原句', '已修改'), snapshot, anchor), null);
    assert.equal(resolvePromptAnchor(snapshot, snapshot, { ...anchor, start: 4 }), null);
});

test('ambiguous repeated quotes and changed neighboring context require the snapshot', () => {
    const snapshot = '原句';
    const anchor = { start: 0, end: 2, quote: '原句', before: '', after: '' };
    assert.equal(resolvePromptAnchor('原句原句', snapshot, anchor), null);
    assert.deepEqual(resolvePromptAnchor('原句', snapshot, anchor), { start: 0, end: 2 });
});


test('control requests time out instead of leaving Stop permanently pending', async (t) => {
    t.mock.method(globalThis, 'fetch', async (_url, { signal }) => new Promise((_resolve, reject) => {
        signal.addEventListener('abort', () => reject(signal.reason), { once: true });
    }));
    await assert.rejects(requestJson('/cancel', {}, 10), { name: 'TimeoutError' });
});

test('control timeout also covers a response body that never finishes', async (t) => {
    t.mock.method(globalThis, 'fetch', async (_url, { signal }) => ({ ok: true, json: () => new Promise((_resolve, reject) => {
        signal.addEventListener('abort', () => reject(signal.reason), { once: true });
    }) }));
    await assert.rejects(requestJson('/status', {}, 10), { name: 'TimeoutError' });
});

test('expired sessions retain the HTTP status even when the server sends HTML', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => new Response('<html>expired</html>', { status: 419 }));
    await assert.rejects(requestJson('/cancel'), { status: 419 });
});

test('cancel confirmation is distinct from provider cleanup and settled cancellation', () => {
    assert.equal(isRunProcessing({ status: 'cancelled', processing: true }), true);
    assert.equal(isRunProcessing({ status: 'cancelled', processing: false }), false);
    assert.equal(isRunProcessing({ status: 'generating' }), true);
    assert.equal(isRunProcessing({ status: 'completed' }), false);
});

test('an unconfirmed stop suppresses automatic diagnosis while recovered articles allow a manual retry', () => {
    const recovered = { status: 'article_ready', cancel_requested: false };
    assert.equal(canStartDiagnosis(recovered, { stopped: true, automatic: true }), false);
    assert.equal(canStartDiagnosis(recovered, { stopped: true }), true);
    assert.equal(canStartDiagnosis({ ...recovered, cancel_requested: true }, { stopped: true }), false);
    assert.equal(canStartDiagnosis(recovered, { busy: true }), false);
});

test('an older stream deadline cannot abort the next run after status recovery', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let current = new AbortController();
    const oldStream = current;
    abortAfter(current, 100);
    t.mock.timers.tick(50);
    current = new AbortController();
    abortAfter(current, 100);
    t.mock.timers.tick(50);
    assert.equal(oldStream.signal.aborted, true);
    assert.equal(current.signal.aborted, false);
    t.mock.timers.tick(50);
    assert.equal(current.signal.aborted, true);
});

test('status polling recovers after a transient failure and drops stale replies', async () => {
    let attempts = 0; let retries = 0; let applied = null; let errors = 0;
    const callbacks = {
        read: async () => { if (++attempts === 1) throw new Error('offline'); return { status: 'completed' }; },
        isCurrent: () => true,
        onValue: (value) => { applied = value; }, onError: () => { errors++; }, retry: () => { retries++; },
    };
    await pollRunStatus(callbacks);
    assert.equal(retries, 1); assert.equal(errors, 1); assert.equal(applied, null);
    await pollRunStatus(callbacks);
    assert.deepEqual(applied, { status: 'completed' });
    await pollRunStatus({ ...callbacks, isCurrent: () => false, read: async () => ({ status: 'generating' }) });
    assert.deepEqual(applied, { status: 'completed' });
    await pollRunStatus({ ...callbacks, read: async () => { throw Object.assign(new Error('expired'), { status: 419 }); } });
    assert.equal(retries, 1); assert.equal(errors, 2);
});
