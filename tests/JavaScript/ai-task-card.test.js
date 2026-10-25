import assert from 'node:assert/strict';
import test from 'node:test';
import { renderTaskCard, syncTaskCardActions, taskDraftContext } from '../../resources/js/admin/ai-workspace/task-card.js';
import { trustedFeatureUrl } from '../../resources/js/admin/ai-workspace.js';
import * as workspace from '../../resources/js/admin/ai-workspace.js';

class Element {
    constructor(tag) {
        this.tag = tag; this.children = []; this.dataset = {}; this.listeners = {};
        this.classList = { toggle: (name, active) => { this[name] = active; } };
    }
    append(...children) { this.children.push(...children); }
    addEventListener(name, handler) { this.listeners[name] = handler; }
    querySelectorAll(selector) {
        const all = this.children.flatMap((child) => [child, ...child.querySelectorAll('*')]);
        if (selector === '*') return all;
        if (selector === '[data-task-draft]') return all.filter((item) => item.dataset.taskDraft);
        return all.filter((item) => item.tag === selector);
    }
}

function surface(status = 'ready') {
    const root = new Element('root');
    const actions = [];
    const data = {
        id: 'draft-one', revision: 3, status, title: 'Task creation assistant',
        rows: [{ label: 'Title', value: '<script>never execute</script>' }],
        questions: [{ label: 'Which category?', options: [{ label: '<b>Industry</b>', prompt: 'Use Industry' }] }],
        links: [{ label: 'View', url: '/admin/tasks/5/edit' }, { label: 'Bad', url: 'https://evil.test/admin/tasks' }],
    };
    renderTaskCard(root, data, {
        documentRef: { createElement: (tag) => new Element(tag) },
        safeUrl: (url) => trustedFeatureUrl(url, 'https://geoflow.test', '/admin'),
        onPrompt: (prompt) => actions.push(prompt), onConfirm: (card) => actions.push(taskDraftContext(card)),
        onAdjust: () => actions.push('adjust'),
    });
    return { root, data, actions, card: root.children[0] };
}

test('task confirmation binds the rendered revision, preserves literal text, and filters links', () => {
    const { root, data, actions, card } = surface();
    assert.equal(card.querySelectorAll('dd')[0].textContent, '<script>never execute</script>');
    assert.equal(card.querySelectorAll('a').length, 1);
    const buttons = card.querySelectorAll('button');
    buttons.find((button) => button.textContent === 'Create task').listeners.click();
    assert.deepEqual(actions[0], { task_draft_id: data.id, task_draft_revision: 3 });
    syncTaskCardActions(root, data, true);
    assert.ok(buttons.every((button) => button.disabled));
    syncTaskCardActions(root, data, false);
    assert.ok(buttons.every((button) => !button.disabled));
});

test('older versions and other conversations cannot reuse live card actions', () => {
    const { root, data, card } = surface();
    for (const current of [{ ...data, revision: 4 }, { ...data, id: 'other' }, { ...data, status: 'created' }, null]) {
        syncTaskCardActions(root, current, false);
        assert.ok(card.querySelectorAll('button').every((button) => button.disabled));
        assert.equal(card['is-old'], true);
    }
});

test('collecting and completed task cards do not expose a create action', () => {
    for (const status of ['collecting', 'created', 'cancelled']) {
        const { card } = surface(status);
        assert.equal(card.querySelectorAll('button').some((button) => button.textContent === 'Create task'), false);
    }
    assert.deepEqual(taskDraftContext(null), {});
    assert.deepEqual(taskDraftContext({ id: 'draft', revision: 0 }), {});
});

test('task guidance scrolls to the question above a long summary within the actual scroll container', () => {
    const calls = [];
    const scrollRoot = {scrollTop: 2939.5, scrollHeight: 5000, getBoundingClientRect: () => ({top: 64}), scrollTo: (options) => calls.push(options)};
    const card = {getBoundingClientRect: () => ({top: -114.5})};
    workspace.scrollTaskStepIntoView(scrollRoot, card);
    assert.deepEqual(calls, [{top: 2745, behavior: 'auto'}]);
    assert.notEqual(calls[0].top, scrollRoot.scrollHeight);
});
