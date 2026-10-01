import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../public/admin/topic-editor.js', import.meta.url), 'utf8');

function editor(payload = {}, formState = {}, restored = null, previous = null) {
    let activeElement = null;
    const documentEvents = {}; const windowEvents = {};
    class Node {
        constructor(tag = 'div') { this.tag = tag; this.children = []; this.dataset = {}; this.events = {}; this.value = ''; this.disabled = false; }
        append(...nodes) { for (const node of nodes) node.parent = this; this.children.push(...nodes); }
        prepend(...nodes) { for (const node of nodes) node.parent = this; this.children.unshift(...nodes); }
        remove() { if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this); }
        replaceChildren(...nodes) { this.children = nodes; }
        addEventListener(event, handler) { (this.events[event] ||= []).push(handler); }
        fire(event, input = {}) { for (const callback of this.events[event] || []) callback(input); }
        setAttribute(name, value) { this[name] = value; }
        focus() { activeElement = this; }
        get selectedOptions() { return this.children.filter(node => node.tag === 'option' && node.selected); }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        querySelectorAll(selector) {
            const nodes = this.children.flatMap(child => [child, ...child.querySelectorAll('*')]);
            if (selector === '*') return nodes;
            if (selector === 'button[name="action"][value="save"]') return nodes.filter(node => node.tag === 'button' && node.name === 'action' && node.value === 'save');
            const match = selector.match(/^\[([^=\]]+)(?:="?([^"\]]+)"?)?\]$/);
            if (!match) return [];
            return nodes.filter(node => {
                const key = match[1].startsWith('data-') ? match[1].slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()) : match[1];
                const value = match[1].startsWith('data-') ? node.dataset[key] : node[key];
                return match[2] === undefined ? value !== undefined : String(value) === match[2];
            });
        }
    }
    const form = new Node('form');
    form.dataset = { storageKey: 'topic-test', saved: 'false', hasErrors: 'false', ...formState };
    const initial = new Node('script'); initial.dataset.topicInitial = '';
    initial.textContent = JSON.stringify({ payload: { articles: [{ article_id: 1 }, { article_id: 2 }], ...payload }, articles: [{ article_id: 1, title: 'First article' }, { article_id: 2, title: 'Second article' }] });
    form.append(initial);
    for (const name of ['articles', 'facts', 'basic_info', 'faq', 'dimensions', 'evidence']) { const box = new Node(); box.dataset.topicArray = name; form.append(box); }
    const exclusions = new Node(); exclusions.dataset.topicExclusions = ''; form.append(exclusions);
    const count = new Node(); count.dataset.exclusionCount = ''; form.append(count);
    const save = new Node('button'); save.name = 'action'; save.value = 'save'; form.append(save);
    const listReturn = new Node('input'); listReturn.type = 'hidden'; listReturn.name = 'list_return'; listReturn.value = 'http://localhost/geo_admin/topics?site=primary&search=GEO&status=draft'; form.append(listReturn);
    const version = new Node('input'); version.type = 'hidden'; version.name = 'expected_version'; version.value = formState.version || '2'; form.append(version);
    const intro = new Node('textarea'); intro.name = 'intro'; intro.value = payload.intro || ''; form.append(intro);
    const cache = new Map([...(restored ? [['topic-test', JSON.stringify(restored)]] : []), ...(previous ? [['topic-test:previous', JSON.stringify(previous)]] : [])]); const submissions = [];
    Object.defineProperty(form, 'elements', { get: () => form.querySelectorAll('*').filter(node => ['input', 'textarea', 'select', 'button'].includes(node.tag)) });
    form.requestSubmit = button => { const event = { prevented: false, preventDefault() { this.prevented = true; } }; form.fire('submit', event); if (!event.prevented) submissions.push(button.value); };
    const document = { querySelectorAll: () => [], querySelector: selector => selector === '[data-topic-editor]' ? form : null, createElement: tag => new Node(tag), addEventListener: (event, callback) => { documentEvents[event] = callback; } };
    vm.runInNewContext(source, { document, window: { addEventListener(event, handler) { (windowEvents[event] ||= []).push(handler); } }, sessionStorage: { getItem: key => cache.get(key), setItem: (key, value) => cache.set(key, value), removeItem: key => cache.delete(key) }, FormData: function () { return { entries: () => form.elements.filter(node => node.name && node.tag !== 'button').map(node => [node.name, node.value]) }; } });
    return { form, submissions, cache, active: () => activeElement, keydown: event => documentEvents.keydown(event), hide: () => { for (const callback of windowEvents.pagehide || []) callback(); }, unload: event => { for (const callback of windowEvents.beforeunload || []) callback(event); } };
}

const key = (overrides = {}) => ({ key: 's', ctrlKey: false, metaKey: false, altKey: false, isComposing: false, prevented: false, preventDefault() { this.prevented = true; }, ...overrides });

test('Ctrl and Cmd S submit the ordinary save action and suppress browser Save', () => {
    const state = editor(); const ctrl = key({ ctrlKey: true }), cmd = key({ metaKey: true, key: 'S' });
    state.keydown(ctrl); state.keydown(cmd);
    assert.deepEqual(state.submissions, ['save', 'save']);
    assert.equal(ctrl.prevented, true); assert.equal(cmd.prevented, true);
    assert.ok(state.cache.has('topic-test'));
});

test('IME input neither submits on Enter nor uses the save shortcut until composition ends', () => {
    const state = editor(); state.form.fire('compositionstart');
    const enter = key({ key: 'Enter', isComposing: true, keyCode: 229 }); state.form.fire('keydown', enter);
    state.keydown(key({ metaKey: true, isComposing: true }));
    state.form.requestSubmit({ value: 'save' });
    assert.equal(enter.prevented, true); assert.deepEqual(state.submissions, []);
    state.form.fire('compositionend'); state.keydown(key({ ctrlKey: true }));
    assert.deepEqual(state.submissions, ['save']);
});

test('article sorting disables boundaries and restores focus on the moved article controls', () => {
    const state = editor(), box = state.form.querySelector('[data-topic-array="articles"]');
    assert.equal(box.children[0].querySelector('[data-topic-move="up"]').disabled, true);
    assert.equal(box.children[1].querySelector('[data-topic-move="down"]').disabled, true);
    box.children[1].querySelector('[data-topic-move="up"]').fire('click');
    assert.equal(String(box.children[0].querySelector('[data-column="article_id"]').value), '2');
    assert.equal(state.active(), box.children[0].querySelector('[data-topic-move="down"]'));
    assert.equal(box.children[0].querySelector('[data-topic-move="up"]').disabled, true);
});


test('existing factual evidence survives input persistence and submits the original locator fields', () => {
    const evidence = { article_id: 1, field: 'content', start: 0, end: 2, sha256: 'a'.repeat(64), text: '原文' };
    const state = editor({ summary: { facts: [{ text: 'Fact', article_ids: [], evidence: [evidence] }] } });
    state.form.fire('input');
    const cached = JSON.parse(state.cache.get('topic-test'));
    assert.deepEqual(cached.arrays.facts[0].evidence, [evidence]);
    const locator = state.form.elements.find(node => node.name === 'summary[facts][0][evidence][0][sha256]');
    assert.equal(locator.value, evidence.sha256);
});


test('unsaved source changes warn before leaving and a valid save releases that warning', () => {
    const state = editor();
    const firstSource = state.form.querySelector('[data-topic-array="articles"]').children[0].querySelector('[data-column="article_id"]');
    firstSource.value = '3'; state.form.fire('input');
    const dirty = key(); state.unload(dirty);
    assert.equal(dirty.prevented, true);
    state.form.requestSubmit({ value: 'save' });
    const saved = key(); state.unload(saved);
    assert.equal(saved.prevented, false);
});

test('validation errors retain an unsaved warning while a saved publication failure remains clean', () => {
    const unsaved = editor({}, { hasErrors: 'true', saved: 'false' });
    const warning = key(); unsaved.unload(warning);
    assert.equal(warning.prevented, true);
    const saved = editor({}, { hasErrors: 'true', saved: 'true' });
    const clean = key(); saved.unload(clean);
    assert.equal(clean.prevented, false);
});


test('cached editing content keeps the current filtered list return address', () => {
    const state = editor({}, {}, { entries: [['list_return', 'http://localhost/geo_admin/topics?site=primary']], arrays: {} });
    const current = state.form.elements.find(node => node.name === 'list_return');
    assert.equal(current.value, 'http://localhost/geo_admin/topics?site=primary&search=GEO&status=draft');
    state.form.fire('input');
    assert.equal(JSON.parse(state.cache.get('topic-test')).entries.some(([name]) => name === 'list_return'), false);
});


test('an older cached form keeps the latest AI content and current version until explicitly restored', () => {
    const state = editor({ intro: 'Latest AI introduction' }, {}, { entries: [['expected_version', '1'], ['intro', 'Old input']], arrays: { articles: [] } });
    assert.equal(state.form.elements.find(node => node.name === 'expected_version').value, '2');
    assert.equal(state.form.elements.find(node => node.name === 'intro').value, 'Latest AI introduction');
    assert.equal(state.form.querySelector('[data-topic-array="articles"]').children.length, 2);
    const notice = state.form.querySelector('[data-topic-cache-conflict]');
    assert.ok(notice);
    assert.equal(notice.querySelector('[data-topic-cache-use-latest]').textContent, '使用最新内容');
    notice.querySelector('[data-topic-cache-use-latest]').fire('click');
    assert.equal(state.form.querySelector('[data-topic-cache-conflict]'), null);
    assert.equal(state.cache.has('topic-test'), false);
});

test('explicit cache restoration preserves the current save version and marks restored content as unsaved', () => {
    const state = editor({ intro: 'Latest introduction' }, {}, { baseVersion: '1', dirty: true, entries: [['expected_version', '1'], ['intro', 'My previous input']], arrays: { articles: [{ article_id: 1 }] } });
    state.form.querySelector('[data-topic-cache-restore]').fire('click');
    assert.equal(state.form.elements.find(node => node.name === 'expected_version').value, '2');
    assert.equal(state.form.elements.find(node => node.name === 'intro').value, 'My previous input');
    assert.equal(state.form.querySelector('[data-topic-array="articles"]').children.length, 1);
    const cached = JSON.parse(state.cache.get('topic-test'));
    assert.equal(cached.baseVersion, '2');
    assert.equal(cached.dirty, true);
    const warning = key(); state.unload(warning);
    assert.equal(warning.prevented, true);
});

test('an untouched old form is discarded quietly while same-version unsaved changes are restored', () => {
    const old = editor({ intro: 'Latest introduction' }, {}, { baseVersion: '1', dirty: false, entries: [['expected_version', '1'], ['intro', 'Old introduction']], arrays: { articles: [] } });
    assert.equal(old.form.elements.find(node => node.name === 'intro').value, 'Latest introduction');
    assert.equal(old.form.querySelector('[data-topic-array="articles"]').children.length, 2);
    assert.equal(old.form.querySelector('[data-topic-cache-conflict]'), null);
    assert.equal(old.cache.has('topic-test'), false);
    const current = editor({ intro: 'Server introduction' }, {}, { baseVersion: '2', dirty: true, entries: [['expected_version', '2'], ['intro', 'Unsaved current input']], arrays: { articles: [{ article_id: 1 }] } });
    assert.equal(current.form.elements.find(node => node.name === 'intro').value, 'Unsaved current input');
    assert.equal(current.form.querySelector('[data-topic-array="articles"]').children.length, 1);
    assert.equal(current.form.querySelector('[data-topic-cache-conflict]'), null);
});


test('leaving an unresolved cache notice keeps the old human input available on return', () => {
    const old = { baseVersion: '1', dirty: true, entries: [['expected_version', '1'], ['intro', 'Previous human input']], arrays: { articles: [{ article_id: 1 }] } };
    const first = editor({ intro: 'Latest AI content' }, {}, old);
    first.hide();
    const next = editor({ intro: 'Latest AI content' }, {}, JSON.parse(first.cache.get('topic-test') || 'null'), JSON.parse(first.cache.get('topic-test:previous') || 'null'));
    assert.ok(next.form.querySelector('[data-topic-cache-conflict]'));
    next.form.querySelector('[data-topic-cache-restore]').fire('click');
    assert.equal(next.form.elements.find(node => node.name === 'intro').value, 'Previous human input');
    assert.equal(next.form.elements.find(node => node.name === 'expected_version').value, '2');
    assert.equal(next.cache.has('topic-test:previous'), false);
});

test('new unsaved edits and the previous version input survive together until a choice is made', () => {
    const old = { baseVersion: '1', dirty: true, entries: [['expected_version', '1'], ['intro', 'Previous human input']], arrays: { articles: [{ article_id: 1 }] } };
    const first = editor({ intro: 'Latest AI content' }, {}, old);
    first.form.elements.find(node => node.name === 'intro').value = 'New human input';
    first.form.fire('input'); first.hide();
    const next = editor({ intro: 'Latest AI content' }, {}, JSON.parse(first.cache.get('topic-test')), JSON.parse(first.cache.get('topic-test:previous') || 'null'));
    assert.equal(next.form.elements.find(node => node.name === 'intro').value, 'New human input');
    assert.ok(next.form.querySelector('[data-topic-cache-conflict]'));
    next.form.querySelector('[data-topic-cache-use-latest]').fire('click');
    assert.equal(next.form.elements.find(node => node.name === 'intro').value, 'New human input');
    assert.equal(next.cache.has('topic-test:previous'), false);
    const saved = editor({}, { saved: 'true' }, old, old);
    assert.equal(saved.cache.has('topic-test'), false);
    assert.equal(saved.cache.has('topic-test:previous'), false);
});
