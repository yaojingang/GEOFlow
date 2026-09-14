import assert from 'node:assert/strict';
import test from 'node:test';
import { confirmationMatches, initializeUrlRiskDialog, initializeUrlChangeReport, initializeArticleUrlCategoryGuard, initializeSeparateUrlChecks } from '../../resources/js/admin/url-change.js';

class Element {
    constructor() {
        this.dataset = {};
        this.listeners = new Map();
        this.nodes = new Map();
        this.value = '';
        this.hidden = false;
        this.disabled = false;
        this.classes = new Set();
        this.classList = { add: (key) => this.classes.add(key), remove: (key) => this.classes.delete(key), toggle: (key, enabled) => enabled ? this.classes.add(key) : this.classes.delete(key) };
    }
    querySelector(key) { return this.nodes.get(key); }
    querySelectorAll(key) { return this.nodes.get(key) ?? []; }
    addEventListener(type, callback) { this.listeners.set(type, [...(this.listeners.get(type) ?? []), callback]); }
    async dispatch(type, data = {}) {
        const event = { target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...data };
        for (const callback of this.listeners.get(type) ?? []) await callback(event);
        return event;
    }
    dispatchEvent(event) { this.lastEvent = event; }
    focus() { this.focused = true; }
    showModal() { this.open = true; }
    close() { this.open = false; void this.dispatch('close'); }
    getBoundingClientRect() { return { top: 20, left: 20, right: 660, bottom: 700 }; }
}

function dialogFixture(fetch = async () => ({ ok: true })) {
    const root = new Element();
    root.ownerDocument = { body: new Element() };
    const dialog = new Element();
    dialog.dataset = { expires: new Date(Date.now() + 900000).toISOString(), phrase: 'CHANGE ARTICLE URLS', expired: 'Expired', applying: 'Applying', uncertain: 'Checking actual result', failed: 'Failed' };
    const opener = new Element();
    const noJs = new Element();
    root.nodes.set('[data-url-risk-dialog]', dialog);
    root.nodes.set('[data-url-risk-open]', opener);
    root.nodes.set('[data-url-no-js]', noJs);
    for (const key of ['[data-url-confirm-form]', '[data-url-phrase]', '[data-url-confirm]', '[data-url-risk-close]', '[data-url-confirm-error]', '[data-url-confirm-status]', '#url-risk-title']) dialog.nodes.set(key, new Element());
    const windowRef = { fetch, FormData: class {}, CustomEvent: class { constructor(type) { this.type = type; } }, matchMedia: () => ({ matches: false }), setInterval: () => 1, clearInterval: () => {}, location: { reload() { windowRef.reloaded = true; } } };
    const controller = initializeUrlRiskDialog(root, windowRef);
    return { root, dialog, opener, noJs, windowRef, controller, form: dialog.querySelector('[data-url-confirm-form]'), input: dialog.querySelector('[data-url-phrase]'), confirm: dialog.querySelector('[data-url-confirm]'), close: dialog.querySelector('[data-url-risk-close]'), error: dialog.querySelector('[data-url-confirm-error]') };
}

test('confirmation accepts outer whitespace and rejects partial or altered phrases', () => {
    assert.equal(confirmationMatches('  CHANGE ARTICLE URLS  ', 'CHANGE ARTICLE URLS'), true);
    assert.equal(confirmationMatches('CHANGE  ARTICLE URLS', 'CHANGE ARTICLE URLS'), false);
    assert.equal(confirmationMatches('CHANGE ARTICLE', 'CHANGE ARTICLE URLS'), false);
    assert.equal(confirmationMatches('', ''), false);
});

test('opening resets the phrase and focuses the title; closing returns focus', async () => {
    const ui = dialogFixture();
    ui.input.value = 'old phrase';
    await ui.opener.dispatch('click');
    assert.equal(ui.noJs.hidden, true);
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.input.value, '');
    assert.equal(ui.confirm.disabled, true);
    assert.equal(ui.dialog.querySelector('#url-risk-title').focused, true);
    await ui.close.dispatch('click');
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.opener.focused, true);
});

test('Enter in the input cannot submit and one explicit confirmation cannot duplicate', async () => {
    let calls = 0;
    let finish;
    const ui = dialogFixture(() => { calls++; return new Promise((resolve) => { finish = resolve; }); });
    ui.input.value = 'CHANGE ARTICLE URLS';
    await ui.input.dispatch('input');
    assert.equal(ui.confirm.disabled, false);
    assert.equal((await ui.input.dispatch('keydown', { key: 'Enter' })).defaultPrevented, true);
    await ui.form.dispatch('submit', { submitter: ui.input });
    assert.equal(calls, 0);
    const pending = ui.form.dispatch('submit', { submitter: ui.confirm });
    await ui.form.dispatch('submit', { submitter: ui.confirm });
    assert.equal(calls, 1);
    finish({ ok: true });
    await pending;
    assert.equal(ui.windowRef.reloaded, true);
});

test('expired confirmation cannot be enabled with a matching phrase', async () => {
    const ui = dialogFixture();
    ui.dialog.dataset.expires = new Date(Date.now() - 1000).toISOString();
    ui.input.value = 'CHANGE ARTICLE URLS';
    await ui.input.dispatch('input');
    assert.equal(ui.confirm.disabled, true);
    assert.equal(ui.error.textContent, 'Expired');
});

test('server validation errors stay in the modal and require a fresh phrase', async () => {
    const ui = dialogFixture(async () => ({ ok: false, status: 422, json: async () => ({ errors: { confirmation: ['Report changed'] } }) }));
    ui.input.value = 'CHANGE ARTICLE URLS';
    await ui.form.dispatch('submit', { submitter: ui.confirm });
    assert.equal(ui.error.textContent, 'Report changed');
    assert.equal(ui.error.hidden, false);
    assert.equal(ui.input.value, '');
    assert.equal(ui.close.disabled, false);
    assert.equal(ui.confirm.disabled, true);
});

test('uncertain writes trigger status recovery and remain protected against duplicate submissions', async () => {
    let calls = 0;
    const ui = dialogFixture(async () => { calls++; throw new Error('Network lost'); });
    ui.input.value = 'CHANGE ARTICLE URLS';
    await ui.form.dispatch('submit', { submitter: ui.confirm });
    assert.equal(ui.root.lastEvent.type, 'url-change:uncertain');
    await ui.form.dispatch('submit', { submitter: ui.confirm });
    assert.equal(calls, 1);
    await ui.close.dispatch('click');
    assert.equal(ui.dialog.open, false);
});

test('keyboard opening skips pointer motion and backdrop cancellation is safe', async () => {
    const ui = dialogFixture();
    await ui.opener.dispatch('pointerdown');
    await ui.opener.dispatch('keydown');
    await ui.opener.dispatch('click');
    assert.equal(ui.dialog.classes.has('is-pointer-open'), false);
    await ui.dialog.dispatch('click', { clientX: 0, clientY: 0 });
    assert.equal(ui.dialog.open, false);
});

test('article category checks preserve unsaved content and allow non-URL category edits', async () => {
    const root = new Element();
    root.dataset = { originalCategory: '1', protectedCategories: '[3]' };
    const select = new Element();
    const form = new Element();
    form.fields = [['content', 'Saved content']];
    select.form = form;
    const save = new Element();
    save.form = form;
    const check = new Element();
    check.form = new Element();
    form.nodes.set('[type="submit"]', [save, check]);
    const panel = new Element();
    root.nodes.set('#category_id', select);
    root.nodes.set('[data-url-category-warning]', panel);
    root.nodes.set('[data-url-category-check]', check);
    root.nodes.set('[data-url-category-value]', new Element());
    root.nodes.set('[data-url-category-form]', check.form);
    root.nodes.set('[data-url-category-restore]', new Element());
    const editorEvents = new Map();
    initializeArticleUrlCategoryGuard(root, { addEventListener: (type, callback) => editorEvents.set(type, callback), FormData: class { constructor(form) { this.form = form; } entries() { return this.form.fields; } } });
    form.fields = [['content', 'Saved content\n']];
    editorEvents.get('geo-article-editor-ready')();
    select.value = '2';
    await select.dispatch('change');
    assert.equal(save.disabled, false);
    assert.equal(panel.hidden, true);
    select.value = '3';
    await select.dispatch('change');
    assert.equal(save.disabled, true);
    assert.equal(check.disabled, false);
    form.fields = [['content', 'Inserted heading\n\nSaved content']];
    assert.equal((await check.form.dispatch('submit')).defaultPrevented, true);
    assert.equal(check.disabled, true);
    assert.equal(form.lastEvent, undefined);
    form.fields = [['content', 'Saved content\n']];
    await select.dispatch('change');
    assert.equal(check.disabled, false);
    await form.dispatch('input', { target: new Element() });
    assert.equal(check.disabled, true);
    assert.equal((await check.form.dispatch('submit')).defaultPrevented, true);
    await root.querySelector('[data-url-category-restore]').dispatch('click');
    assert.equal(select.value, '1');
    assert.equal(save.disabled, false);
});

test('separate URL checks wait for other edits to be saved or restored', async () => {
    const root = new Element();
    const source = new Element();
    source.fields = [['name', 'Original name'], ['_token', 'csrf']];
    const check = new Element();
    const button = new Element();
    const hint = new Element();
    check.nodes.set('[data-url-separate-submit]', button);
    check.nodes.set('[data-url-unsaved-hint]', hint);
    root.nodes.set('[data-url-source-form]', [source]);
    root.nodes.set('[data-url-separate-check]', [check]);
    const windowRef = { FormData: class { constructor(form) { this.form = form; } entries() { return this.form.fields; } } };
    initializeSeparateUrlChecks(root, windowRef);
    assert.equal(button.disabled, false);
    source.fields[0][1] = 'Unsaved name';
    await source.dispatch('input');
    assert.equal(button.disabled, true);
    assert.equal(hint.hidden, false);
    assert.equal((await check.dispatch('submit')).defaultPrevented, true);
    source.fields[0][1] = 'Original name';
    await source.dispatch('input');
    assert.equal(button.disabled, false);
    assert.equal((await check.dispatch('submit')).defaultPrevented, false);
});

test('refresh errors become visible even when the status remains refreshing', async () => {
    const root = new Element();
    root.ownerDocument = { hidden: false };
    root.dataset = { status: 'refreshing', statusUrl: '/status', pollError: 'Status unavailable' };
    const failure = new Element();
    failure.hidden = true;
    root.nodes.set('[data-url-job-error]', failure);
    root.nodes.set('[data-url-poll-error]', new Element());
    root.nodes.set('[data-url-poll-retry]', new Element());
    const timers = new Map();
    let jobError = 'The refresh stopped. It will resume from the saved checkpoint.';
    const windowRef = {
        AbortController,
        setTimeout: (callback, delay) => { timers.set(delay, callback); return delay; },
        clearTimeout: (id) => timers.delete(id),
        fetch: async () => ({ ok: true, json: async () => ({ status: 'refreshing', error: jobError, summary: {} }) }),
        location: { reload: () => { throw new Error('Same status should update inline'); } },
    };
    initializeUrlChangeReport(root, windowRef);
    await timers.get(3000)();
    assert.equal(failure.hidden, false);
    assert.equal(failure.textContent, jobError);
    jobError = null;
    await timers.get(3000)();
    assert.equal(failure.hidden, true);
    assert.equal(failure.textContent, '');
});
