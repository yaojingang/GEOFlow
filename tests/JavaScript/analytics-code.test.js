import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/admin-analytics-code.js', import.meta.url), 'utf8');

function formEncoder() {
    const listeners = {};
    const form = { addEventListener: (name, callback) => { listeners[name] = callback; } };
    vm.runInNewContext(source, { document: { querySelector: () => form }, TextEncoder, btoa });
    return data => listeners.formdata({ formData: data });
}

for (const code of [
    '<script defer src="https://tongji.liehe.com/script.js" data-website-id="00000000-0000-4000-8000-000000000001"></script>',
    '<!-- 中文统计 📊 -->\n<script>window.analyticsName = "访问统计";</script>\n<script async src="https://example.com/analytics.js"></script>',
    '',
]) {
    test(`encodes analytics without changing other fields (${code ? code.slice(0, 24) : 'empty'})`, () => {
        const encode = formEncoder();
        const data = new FormData();
        data.set('_token', 'csrf-token');
        data.set('site_name', 'Test site');
        data.set('analytics_code', code);

        encode(data);

        assert.equal(data.has('analytics_code'), false);
        assert.equal(Buffer.from(data.get('analytics_code_base64'), 'base64').toString('utf8'), code);
        assert.equal(data.get('_token'), 'csrf-token');
        assert.equal(data.get('site_name'), 'Test site');
        assert.equal([...data.values()].some(value => /<script\b/i.test(value)), false);
    });
}

test('keeps analytics absent when the textarea is disabled for a standard admin', () => {
    const data = new FormData();
    data.set('site_name', 'Test site');

    formEncoder()(data);

    assert.equal(data.has('analytics_code_base64'), false);
    assert.equal(data.get('site_name'), 'Test site');
});

test('repeated form snapshots retain the same encoded content', () => {
    const encode = formEncoder();
    const snapshot = () => {
        const data = new FormData();
        data.set('analytics_code', '<script>analytics()</script>');
        encode(data);
        encode(data);
        return [...data.entries()];
    };

    assert.deepEqual(snapshot(), snapshot());
    assert.equal(snapshot()[0][0], 'analytics_code_base64');
});

test('loads safely on pages without the analytics form', () => {
    vm.runInNewContext(source, { document: { querySelector: () => null } });
});
