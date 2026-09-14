import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/admin-friend-links.js', import.meta.url), 'utf8');

function editor({ reloadRequired = false, invalidField = null, readyState = 'complete' } = {}) {
    const timers = [];
    const element = (extra = {}) => ({
        listeners: {},
        addEventListener(type, listener) { this.listeners[type] = listener; },
        focus() {},
        ...extra,
    });
    const controls = Object.fromEntries([
        '[data-friend-rows]', '[data-friend-add]', '[data-friend-undo]', '[data-link-count]',
        '[data-friend-empty]', '[data-friend-dirty]', '[data-friend-reload]', '[type="submit"]',
    ].map(selector => [selector, element({ children: [], textContent: 'Save friend links' })]));
    const form = element({
        dataset: { reloadRequired: reloadRequired ? '1' : '0', hasErrors: '1', leaveMessage: 'Unsaved changes', reloadMessage: 'Replace draft?', savingLabel: 'Saving' },
        querySelector(selector) {
            if (selector === '[aria-invalid="true"]') return invalidField;
            assert.ok(controls[selector], selector);
            return controls[selector];
        },
    });
    const document = element({
        readyState,
        getElementById(id) { return ({'friend-links-form': form, 'friend-links-errors': element()})[id]; },
    });
    let confirmations = 0;
    let reloads = 0;
    const window = element({
        location: { reload() { reloads++; } },
        confirm() { confirmations++; return true; },
    });
    class FormData { entries() { return [['friend_links[link_count]', '0']]; } }
    controls['[data-link-count]'].value = '2';
    vm.runInNewContext(source, { document, window, FormData, setTimeout: callback => timers.push(callback) });
    const event = (extra = {}) => ({
        target: form, prevented: false,
        get defaultPrevented() { return this.prevented; },
        preventDefault() { this.prevented = true; },
        stopImmediatePropagation() {},
        ...extra,
    });
    return { controls, form, document, window, event, confirmations: () => confirmations, reloads: () => reloads, runTimers: () => timers.splice(0).forEach(callback => callback()) };
}

test('loading the latest config performs a real reload on the management page', () => {
    const ui = editor();
    const click = ui.event();
    ui.controls['[data-friend-reload]'].listeners.click(click);
    assert.equal(click.prevented, true);
    assert.equal(ui.confirmations(), 1);
    assert.equal(ui.reloads(), 1);
});

test('canceling latest config reload retains the draft and leave protection', () => {
    const ui = editor();
    ui.window.confirm = () => false;
    const click = ui.event();
    ui.controls['[data-friend-reload]'].listeners.click(click);
    assert.equal(ui.reloads(), 0);
    const leave = ui.event();
    ui.window.listeners.beforeunload(leave);
    assert.equal(leave.prevented, true);
});

test('back-forward cache restoration enables saving again and restores draft protection', () => {
    const ui = editor();
    ui.document.listeners.submit(ui.event());
    assert.equal(ui.controls['[type="submit"]'].disabled, true);
    const duplicate = ui.event();
    ui.document.listeners.submit(duplicate);
    assert.equal(duplicate.prevented, true);
    ui.window.listeners.pageshow({ persisted: true });
    assert.equal(ui.controls['[type="submit"]'].disabled, false);
    assert.equal(ui.controls['[type="submit"]'].textContent, 'Save friend links');
    const leave = ui.event();
    ui.window.listeners.beforeunload(leave);
    assert.equal(leave.prevented, true);
    const submit = ui.event();
    ui.document.listeners.submit(submit);
    assert.equal(submit.prevented, false);
});

test('declining another settings form submission preserves the current draft', () => {
    const ui = editor();
    ui.window.confirm = () => false;
    const submit = ui.event({ target: {} });
    ui.document.listeners.submit(submit);
    assert.equal(submit.prevented, true);
    const leave = ui.event();
    ui.window.listeners.beforeunload(leave);
    assert.equal(leave.prevented, true);
});


test('a cancelled leave warning from another form restores friend-link submission', () => {
    const ui = editor();
    ui.document.listeners.submit(ui.event());
    assert.equal(ui.controls['[type="submit"]'].disabled, true);
    const leave = ui.event();
    ui.window.listeners.beforeunload(leave);
    // The existing shell's listener runs after this feature's listener.
    leave.preventDefault();
    ui.runTimers();
    assert.equal(ui.controls['[type="submit"]'].disabled, false);
    const retry = ui.event();
    ui.document.listeners.submit(retry);
    assert.equal(retry.prevented, false);
});

test('an incomplete server draft preserves declared count and blocks retry until reloaded', () => {
    const ui = editor({ reloadRequired: true });
    assert.equal(ui.controls['[data-link-count]'].value, '2');
    const retry = ui.event();
    ui.document.listeners.submit(retry);
    assert.equal(retry.prevented, true);
    ui.window.listeners.pageshow({ persisted: true });
    assert.equal(ui.controls['[type="submit"]'].disabled, true);
});

test('validation returns keyboard focus and viewport to the first invalid row field', () => {
    const actions = [];
    editor({ invalidField: {
        focus() { actions.push('focus'); },
        scrollIntoView(options) { actions.push(options.block); },
    } });
    assert.deepEqual(actions, ['focus', 'center']);
});

test('error focus waits for initial fragment navigation to finish', () => {
    let focused = false;
    const ui = editor({ readyState: 'interactive', invalidField: {
        focus() { focused = true; },
        scrollIntoView() {},
    } });
    assert.equal(focused, false);
    ui.window.listeners.load();
    assert.equal(focused, true);
});
