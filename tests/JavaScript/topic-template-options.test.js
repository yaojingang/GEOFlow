import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../public/admin/topic-editor.js', import.meta.url), 'utf8');

function harness() {
    const siteEvents = {}, formEvents = {}, responses = [];
    const site = { value: 'primary', addEventListener: (event, handler) => { siteEvents[event] = handler; } };
    const message = { textContent: '' };
    const changes = [];
    const template = { value: 'guide', disabled: false, options: [], replaceChildren() { this.options = []; }, append(option) { this.options.push(option); }, dispatchEvent() { changes.push({ value: this.value, disabled: this.disabled }); } };
    const form = { dataset: { templateCurrentSite: 'primary', templateUrl: 'http://localhost/admin/topics/settings' }, querySelector: selector => ({ '[data-topic-template-site]': site, '[data-topic-template-select]': template, '[data-topic-template-message]': message })[selector], addEventListener: (event, handler) => { formEvents[event] = handler; } };
    vm.runInNewContext(source, {
        document: { querySelectorAll: selector => selector === '[data-topic-template-form]' ? [form] : [], querySelector: () => null, createElement: () => ({}) },
        location: { href: 'http://localhost/admin/topics/batches/create' }, URL, Event,
        fetch: url => new Promise(resolve => { responses.push({ url: String(url), resolve: options => resolve({ ok: true, json: async () => ({ template_options: options }) }) }); }),
    });
    return { site, siteEvents, template, message, formEvents, responses, changes };
}

const flush = () => new Promise(resolve => setImmediate(resolve));

test('site switch loads only the latest site layouts and preserves an available selection', async () => {
    const state = harness();
    state.site.value = 'hosted:1'; state.siteEvents.change();
    state.site.value = 'hosted:2'; state.siteEvents.change();
    state.responses[0].resolve({ default: 'Standard', first: 'First site' }); await flush();
    assert.equal(state.template.options.length, 0);
    state.responses[1].resolve({ default: 'Standard', guide: 'Guide', custom: 'Second site' }); await flush();
    assert.ok(state.responses[1].url.includes('site=hosted%3A2'));
    assert.equal(state.template.value, 'guide');
    assert.equal(state.template.disabled, false);
    assert.deepEqual(state.changes, [{ value: 'guide', disabled: false }]);
});

test('site switch chooses default when the old layout is unavailable and waits before submit', async () => {
    const state = harness(); let prevented = false;
    state.site.value = 'hosted:3'; state.siteEvents.change();
    state.formEvents.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    state.responses[0].resolve({ default: 'Standard', custom: 'Available custom' }); await flush();
    assert.equal(state.template.value, 'default');
    assert.equal(state.template.options.length, 2);
});
