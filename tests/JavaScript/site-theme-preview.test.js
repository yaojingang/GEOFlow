import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const code = readFileSync(new URL('../../public/js/site-theme-preview.js', import.meta.url), 'utf8');
const base = 'https://site.example/custom-admin/site-settings/theme-packages/installed/demo/preview/frame';
function setup(isShell, anchors = [], controls = []) {
    const handlers = {};
    const frame = { contentWindow: {}, src: '' };
    const sent = [];
    const marker = { dataset: { frameBase: base }, querySelector: () => frame };
    const window = { addEventListener: (type, handler) => handlers[type] = handler, parent: { postMessage: (...args) => sent.push(args) } };
    const document = { querySelectorAll: (selector) => selector === 'a[href]' ? anchors : controls, querySelector: (selector) => selector === (isShell ? '[data-theme-preview-shell]' : '[data-theme-preview-bridge]') ? marker : null, addEventListener: (type, handler) => handlers[type] = handler };
    vm.runInNewContext(code, { window, document, MutationObserver: class { constructor(callback) { handlers.mutations = callback; } observe() {} }, location: { href: `${base}/?page=1` }, URL, URLSearchParams, FormData: class { constructor(form, submitter) { return [...form.fields, ...(submitter?.name ? [[submitter.name, submitter.value]] : [])]; } } });
    return { handlers, frame, sent };
}
test('outer frame accepts its own child and keeps navigation in the preview namespace', () => {
    const { handlers, frame } = setup(true);
    const send = (url, source = frame.contentWindow) => handlers.message({ source, data: { type: 'geoflow-theme-preview:navigate', url } });
    send(`${base}/article/sample?q=one&page=2#heading`);
    assert.equal(frame.src, `${base}/article/sample?q=one&page=2#heading`);
    const before = frame.src;
    for (const url of ['https://evil.example/', 'https://site.example/custom-admin', `${base}/article/x%2fy`, `${base}/article/%2e%2e`, 'javascript:alert(1)']) send(url);
    send(`${base}/about`, {});
    assert.equal(frame.src, before);
});
test('inner bridges hardcoded links and GET search while preventing POST forms', () => {
    const { handlers, sent } = setup(false);
    let prevented = 0;
    const anchor = { href: 'https://site.example/archive/2026/09?page=2', getAttribute: () => '/archive/2026/09?page=2' };
    handlers.click({ target: { closest: () => anchor }, button: 0, preventDefault: () => prevented++ });
    assert.equal(sent[0][0].url, `${base}/archive/2026/09?page=2`);
    assert.equal(sent[0][1], 'https://site.example');
    handlers.submit({ target: { method: 'get', action: `${base}/`, fields: [['q', 'legal knowledge']] }, preventDefault: () => prevented++ });
    assert.equal(sent[1][0].url, `${base}/?q=legal+knowledge`);
    handlers.submit({ target: { method: 'post', action: 'https://site.example/forms/contact' }, preventDefault: () => prevented++ });
    assert.equal(sent.length, 2);
    assert.equal(prevented, 3);
});
test('external links open separately and fragment links keep native navigation', () => {
    const { handlers, sent } = setup(false);
    const anchor = { href: 'https://outside.example/about', getAttribute: () => 'https://outside.example/about' };
    handlers.click({ target: { closest: () => anchor }, button: 0, preventDefault: () => assert.fail('external link prevented') });
    assert.equal(anchor.target, '_blank');
    assert.equal(anchor.rel, 'noopener noreferrer');
    handlers.click({ target: { closest: () => ({ getAttribute: () => '#section' }) }, button: 0, preventDefault: () => assert.fail('fragment prevented') });
    assert.equal(sent.length, 0);
});

test('initial and dynamic hardcoded links are rewritten even after another link loses href', () => {
    const link = (path) => ({ href: `https://site.example${path}`, getAttribute() { return this.raw ?? path; }, matches() { return true; } });
    const initial = link('/article/first');
    const { handlers } = setup(false, [initial]);
    assert.equal(initial.href, `${base}/article/first`);
    const dynamic = link('/category/general');
    const deleted = { getAttribute: () => null, matches: () => false };
    handlers.mutations([{ type: 'attributes', target: deleted }, { type: 'childList', addedNodes: [dynamic] }]);
    assert.equal(dynamic.href, `${base}/category/general`);
});

test('sandboxed search works from a submit button or Enter without native form submission', () => {
    const { handlers, sent } = setup(false);
    const form = { method: 'get', action: `${base}/`, fields: [['q', 'legal']], reportValidity: () => true };
    const control = { type: 'submit', form, getAttribute: () => null };
    let prevented = 0;
    handlers.click({ target: { closest: (selector) => selector === 'button, input' ? control : null }, button: 0, preventDefault: () => prevented++ });
    handlers.keydown({ key: 'Enter', target: { tagName: 'INPUT', type: 'search', form }, preventDefault: () => prevented++ });
    assert.equal(sent.length, 2);
    assert.equal(sent[0][0].url, `${base}/?q=legal`);
    assert.equal(sent[1][0].url, `${base}/?q=legal`);
    form.method = 'post';
    handlers.click({ target: { closest: () => control }, button: 0, preventDefault: () => prevented++ });
    handlers.keydown({ key: 'Enter', target: { tagName: 'INPUT', type: 'text', form }, preventDefault: () => prevented++ });
    assert.equal(sent.length, 2);
    assert.equal(prevented, 4);
});

test('Enter honors the default submit button method, destination and value overrides', () => {
    const form = { method: 'get', action: `${base}/`, fields: [['q', 'legal']] };
    const overrides = { formmethod: 'post', formaction: `${base}/category/law` };
    const button = { form, type: 'submit', name: 'scope', value: 'law', getAttribute: (key) => overrides[key] || null };
    const { handlers, sent } = setup(false, [], [button]);
    const enter = () => handlers.keydown({ key: 'Enter', target: { tagName: 'INPUT', type: 'search', form }, preventDefault() {} });
    enter();
    assert.equal(sent.length, 0);
    form.method = 'post';
    overrides.formmethod = 'get';
    enter();
    assert.equal(sent[0][0].url, `${base}/category/law?q=legal&scope=law`);
    button.disabled = true;
    enter();
    assert.equal(sent.length, 1);
});
