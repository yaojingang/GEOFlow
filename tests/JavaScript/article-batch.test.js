import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/views/admin/articles/_batch-submit.blade.php', import.meta.url), 'utf8');

function fixture({ action = 'batch_update_status', confirmed = true } = {}) {
    const submissions = [];
    const notices = [];
    const checkboxes = [{ value: '12', checked: true, dataset: { workflowVersion: '3' } }];
    const nodes = {
        'batch-action': { value: action },
        'status-select': { value: 'published' },
        'review-select': { value: 'approved' },
        'batch-selected-ids': { children: [], replaceChildren() { this.children = []; }, appendChild(node) { this.children.push(node); } },
    };
    const execute = { disabled: false };
    const form = { dataset: {}, action: '', addEventListener(name, handler) { this.handler = handler; } };
    nodes['batch-form'] = form;
    const payload = () => nodes['batch-selected-ids'].children.map(node => [node.name, node.value]);
    const document = {
        getElementById: id => nodes[id],
        querySelector: () => execute,
        querySelectorAll: () => checkboxes.filter(node => node.checked),
        createElement: () => ({}),
    };
    const context = {
        document,
        window: { addEventListener(name, fn) { if (name === 'pageshow') this.reset = fn; } },
        HTMLFormElement: { prototype: { submit() { submissions.push(payload()); } } },
        ARTICLE_BATCH_ROUTES: { batch_update_status: '/status', batch_update_review: '/review', delete_articles: '/delete', batch_restore: '/restore' },
        ARTICLES_I18N: { selectArticles: 'Select articles', selectAction: 'Select action', selectStatus: 'Select status', selectReview: 'Select review', confirmDeleteSelected: 'Delete __COUNT__' },
        TRASH_I18N: { confirmBatchRestore: 'Restore __COUNT__' },
        IS_TRASH_VIEW: false,
        showArticleNotice: message => notices.push(message),
        confirmArticleAction: async () => typeof confirmed === 'function' ? confirmed() : confirmed,
        updateSelectedCount() { execute.disabled = checkboxes.filter(node => node.checked).length === 0 || form.dataset.articleBatchBusy === 'true'; },
    };
    vm.runInNewContext(source, context);
    return { form, nodes, checkboxes, submissions, notices, context, execute, async submit() {
        const event = { defaultPrevented: false, submitter: execute, preventDefault() { this.defaultPrevented = true; } };
        const pending = form.handler(event);
        if (!event.defaultPrevented) submissions.push(payload());
        await pending;
        return event;
    } };
}

test('normal status and review operations submit the current selection on the first click', async () => {
    for (const action of ['batch_update_status', 'batch_update_review']) {
        const page = fixture({ action });
        await page.submit();
        assert.equal(page.submissions.length, 1);
        assert.deepEqual(page.submissions[0], [['article_ids[]', '12'], ['workflow_versions[12]', '3']]);
    }
});

test('a second click cannot submit again while navigation is pending', async () => {
    const page = fixture();
    await page.submit();
    page.checkboxes[0].value = '33';
    await page.submit();
    assert.equal(page.submissions.length, 1);
});

test('an empty selection clears old IDs and does not submit', async () => {
    const page = fixture();
    page.nodes['batch-selected-ids'].children.push({ name: 'article_ids[]', value: '99' });
    page.checkboxes[0].checked = false;
    await page.submit();
    assert.equal(page.submissions.length, 0);
    assert.equal(page.nodes['batch-selected-ids'].children.length, 0);
    assert.deepEqual(page.notices, ['Select articles']);
});

test('cancelled confirmation releases the form and leaves no stale selected IDs', async () => {
    const page = fixture({ action: 'delete_articles', confirmed: false });
    await page.submit();
    assert.equal(page.submissions.length, 0);
    assert.equal(page.form.dataset.articleBatchBusy, undefined);
    assert.equal(page.execute.disabled, false);
    assert.equal(page.nodes['batch-selected-ids'].children.length, 0);
});

test('changing selection while confirmation is open cancels that attempt', async () => {
    let finish;
    const page = fixture({ action: 'delete_articles', confirmed: () => new Promise(resolve => { finish = resolve; }) });
    const pending = page.submit();
    page.checkboxes[0].value = '33';
    finish(true);
    await pending;
    assert.equal(page.submissions.length, 0);
    page.nodes['batch-action'].value = 'batch_update_status';
    await page.submit();
    assert.deepEqual(page.submissions[0], [['article_ids[]', '33'], ['workflow_versions[33]', '3']]);
});

test('closing the batch panel invalidates its open confirmation', async () => {
    let finish;
    const page = fixture({ action: 'delete_articles', confirmed: () => new Promise(resolve => { finish = resolve; }) });
    const pending = page.submit();
    page.form.dataset.articleBatchGeneration = '1';
    finish(true);
    await pending;
    assert.equal(page.submissions.length, 0);
});

test('confirmed destructive action sends one freshly built payload', async () => {
    const page = fixture({ action: 'delete_articles' });
    await page.submit();
    assert.equal(page.submissions.length, 1);
    assert.equal(page.form.action, '/delete');
});

test('back navigation clears submission state so a new selection can submit', async () => {
    const page = fixture();
    await page.submit();
    page.context.window.reset();
    page.checkboxes[0].value = '35';
    await page.submit();
    assert.equal(page.submissions.length, 2);
    assert.deepEqual(page.submissions[1], [['article_ids[]', '35'], ['workflow_versions[35]', '3']]);
});
