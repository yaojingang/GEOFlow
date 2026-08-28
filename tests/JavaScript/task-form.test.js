import assert from 'node:assert/strict';
import test from 'node:test';

import {
    applySuggestedLimit,
    buildReadinessActions,
    deriveInlineTitleStats,
    initializeTaskForm,
    shouldSubmitImmediately,
    syncSelectableCardState,
} from '../../resources/js/admin/task-form.js';

test('derives inline title statistics from the selected library and persisted progress', () => {
    assert.deepEqual(
        deriveInlineTitleStats({ total: '7', used: '3', available: '4' }, 9, 2),
        { total: 7, used: 3, available: 4, remaining: 7 },
    );
});

test('ready reports submit immediately while warnings and blockers open the dialog', () => {
    assert.equal(shouldSubmitImmediately({ status: 'ready', requires_acknowledgement: false }), true);
    assert.equal(shouldSubmitImmediately({ status: 'warning', requires_acknowledgement: true }), false);
    assert.equal(shouldSubmitImmediately({ status: 'blocked', requires_acknowledgement: true }), false);
});

test('dialog actions follow report context', () => {
    assert.deepEqual(
        buildReadinessActions({
            status: 'blocked',
            can_save: false,
            can_activate: false,
            requires_acknowledgement: true,
            suggested_article_limit: 6,
            library: { total: 8 },
            task: { status: 'active', is_loop: false, created_count: 4 },
        }),
        {
            adjust: true,
            enableLoop: true,
            savePaused: true,
            acknowledge: false,
            retry: false,
            serverCheck: false,
        },
    );

    assert.equal(buildReadinessActions({
        status: 'warning',
        can_save: true,
        can_activate: true,
        requires_acknowledgement: true,
        task: { status: 'active', is_loop: true },
        library: { total: 2 },
    }).acknowledge, true);
});

test('adjusting the suggested limit synchronizes article and draft limits', () => {
    const articleLimit = { value: '10' };
    const draftLimit = { value: '8', max: '10' };

    applySuggestedLimit(articleLimit, draftLimit, 6);

    assert.equal(articleLimit.value, '6');
    assert.equal(draftLimit.value, '6');
    assert.equal(draftLimit.max, '6');
});

test('distribution cards remove hover feedback while disabled and restore it when enabled', () => {
    const card = new FakeElement();

    syncSelectableCardState(card, true);
    assert.equal(card.classList.values.has('cursor-not-allowed'), true);
    assert.equal(card.classList.values.has('hover:border-blue-300'), false);
    assert.equal(card.classList.values.has('hover:bg-blue-50'), false);

    syncSelectableCardState(card, false);
    assert.equal(card.classList.values.has('cursor-pointer'), true);
    assert.equal(card.classList.values.has('hover:border-blue-300'), true);
    assert.equal(card.classList.values.has('hover:bg-blue-50'), true);
});

class FakeClassList {
    constructor() {
        this.values = new Set();
    }

    toggle(name, force) {
        if (force) this.values.add(name);
        else this.values.delete(name);
    }
}

class FakeElement {
    constructor(text = '') {
        this.textContent = text;
        this.value = '';
        this.checked = false;
        this.disabled = false;
        this.hidden = false;
        this.open = false;
        this.dataset = {};
        this.classList = new FakeClassList();
        this.className = '';
        this.children = [];
        this.listeners = new Map();
        this.attributes = new Map();
        this.selectors = new Map();
        this.selectorLists = new Map();
        this.parentElement = null;
    }

    querySelector(selector) {
        return this.selectors.get(selector) ?? null;
    }

    querySelectorAll(selector) {
        return this.selectorLists.get(selector) ?? [];
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatch(type, properties = {}) {
        const event = {
            target: this,
            submitter: null,
            clientX: 0,
            clientY: 0,
            defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
            ...properties,
        };
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));
        return event;
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    toggleAttribute(name, force) {
        if (force) this.attributes.set(name, '');
        else this.attributes.delete(name);
    }

    append(...children) {
        this.children.push(...children);
    }

    replaceChildren(...children) {
        this.children = [...children];
    }

    focus() {
        fakeActiveElement.value = this;
    }

    closest(selector) {
        return this.selectors.get(`closest:${selector}`) ?? null;
    }

    reportValidity() {}

    setCustomValidity() {}
}

class FakeForm extends FakeElement {
    constructor() {
        super();
        this.submitted = false;
        this.blockSubmission = false;
        this.dataset = {
            titleReadinessUrl: '/geo_admin/tasks/title-readiness',
            createdCount: '2',
            taskId: '69',
        };
    }

    requestSubmit(button) {
        if (this.blockSubmission) return;
        const event = this.dispatch('submit', { submitter: button });
        if (!event.defaultPrevented) this.submitted = true;
    }
}

class FakeDialog extends FakeElement {
    constructor() {
        super();
        this.animationCount = 0;
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
        this.dispatch('close');
    }

    animate() {
        this.animationCount += 1;
    }

    getBoundingClientRect() {
        return { left: 100, right: 700, top: 80, bottom: 720 };
    }
}

const fakeActiveElement = { value: null };
const flush = () => new Promise((resolve) => setImmediate(resolve));

function readinessReport(overrides = {}) {
    return {
        status: 'blocked',
        can_save: false,
        can_activate: false,
        requires_acknowledgement: true,
        library: { name: '测试标题库', total: 3, used: 2, available: 1 },
        task: { status: 'active', is_loop: false, created_count: 2, remaining: 4 },
        shortage: 3,
        suggested_article_limit: 3,
        summary: '标题库统计摘要',
        recommendation: '补充标题或调整上限',
        paused_hint: null,
        manage_url: '/geo_admin/title-libraries/7/detail',
        issues: [{
            code: 'title_library_shortage',
            severity: 'blocking',
            title: '可用标题不足',
            message: '还缺少 3 个标题',
            impact: '任务会自动暂停',
            suggestions: ['补充标题', '调整上限'],
        }],
        ...overrides,
    };
}

function fixture(fetchImpl, reducedMotion = true, options = {}) {
    const form = new FakeForm();
    const submitButton = new FakeElement('保存');
    const submitLabel = new FakeElement('保存');
    const titleLibrary = new FakeElement();
    titleLibrary.value = '7';
    titleLibrary.selectedOptions = [{
        dataset: {
            titleName: '测试标题库',
            titleTotal: '3',
            titleUsed: '2',
            titleAvailable: '1',
            titleManageUrl: '/geo_admin/title-libraries/7/detail',
        },
    }];
    const articleLimit = new FakeElement();
    articleLimit.value = '6';
    const draftLimit = new FakeElement();
    draftLimit.value = '5';
    const loopMode = new FakeElement();
    const status = new FakeElement();
    status.value = 'active';
    const csrf = new FakeElement();
    csrf.value = 'csrf-token';
    const stats = new FakeElement();
    ['total', 'used', 'available', 'remaining'].forEach((key) => {
        stats.selectors.set(`[data-task-title-stat="${key}"]`, new FakeElement());
    });

    Object.entries({
        '[data-task-form-submit]': submitButton,
        '[data-task-form-submit-label]': submitLabel,
        '#title_library_id': titleLibrary,
        '#article_limit': articleLimit,
        '#draft_limit': draftLimit,
        '#is_loop': loopMode,
        '#status': status,
        '[data-task-title-stats]': stats,
        'input[name="_token"]': csrf,
    }).forEach(([selector, element]) => form.selectors.set(selector, element));

    const dialog = new FakeDialog();
    const dialogElements = {
        title: new FakeElement(),
        summary: new FakeElement(),
        recommendation: new FakeElement(),
        pausedHint: new FakeElement(),
        issues: new FakeElement(),
        iconWrap: new FakeElement(),
        adjust: new FakeElement(),
        loop: new FakeElement(),
        manage: new FakeElement(),
        pause: new FakeElement(),
        acknowledge: new FakeElement(),
        retry: new FakeElement(),
        server: new FakeElement(),
        closeHeader: new FakeElement(),
        closeFooter: new FakeElement(),
    };
    Object.entries({
        '[data-task-readiness-title]': dialogElements.title,
        '[data-task-readiness-summary]': dialogElements.summary,
        '[data-task-readiness-recommendation]': dialogElements.recommendation,
        '[data-task-readiness-paused-hint]': dialogElements.pausedHint,
        '[data-task-readiness-issues]': dialogElements.issues,
        '[data-task-readiness-icon-wrap]': dialogElements.iconWrap,
        '[data-task-readiness-adjust]': dialogElements.adjust,
        '[data-task-readiness-loop]': dialogElements.loop,
        '[data-task-readiness-manage]': dialogElements.manage,
        '[data-task-readiness-pause]': dialogElements.pause,
        '[data-task-readiness-acknowledge]': dialogElements.acknowledge,
        '[data-task-readiness-retry]': dialogElements.retry,
        '[data-task-readiness-server]': dialogElements.server,
    }).forEach(([selector, element]) => dialog.selectors.set(selector, element));
    dialog.selectorLists.set('[data-task-readiness-close]', [dialogElements.closeHeader, dialogElements.closeFooter]);
    ['remaining', 'total', 'used', 'available'].forEach((key) => {
        dialog.selectors.set(`[data-task-readiness-stat="${key}"]`, new FakeElement());
    });

    const i18n = new FakeElement(JSON.stringify({
        checking: '正在检查配置',
        blockedTitle: '标题库配置需要处理',
        warningTitle: '请确认标题库风险',
        requestFailed: '检查请求失败',
        distributionCount: '已选择 __COUNT__',
        knowledgeBaseCount: '已选择 __COUNT__/5',
        adjustLimit: '调整为 __COUNT__ 篇',
        requestFailedIssue: {
            code: 'request_failed',
            severity: 'warning',
            title: '配置检查暂时不可用',
            message: '没有取得最新统计',
            impact: '服务器保存时仍会检查',
            suggestions: ['重试'],
        },
    }));
    const initial = new FakeElement('null');
    const root = {
        querySelector(selector) {
            if (selector === '[data-task-form]') return form;
            if (selector === '[data-task-title-readiness-dialog]') return dialog;
            if (selector === '[data-task-form-i18n]') return i18n;
            if (selector === '[data-task-title-readiness-initial]') return initial;
            return null;
        },
        createElement: () => new FakeElement(),
    };
    globalThis.window = {
        matchMedia: () => ({ matches: reducedMotion }),
        GeoFlowAdminUi: { refreshIcons: true },
    };

    initializeTaskForm(root, { fetchImpl, ...options });

    return { articleLimit, dialog, dialogElements, draftLimit, form, loopMode, status, submitButton, submitLabel, titleLibrary };
}

test('successful readiness check submits once and locks duplicate submission', async () => {
    let resolveFetch;
    let fetchCount = 0;
    const pendingResponse = new Promise((resolve) => { resolveFetch = resolve; });
    const view = fixture(() => {
        fetchCount += 1;
        return pendingResponse;
    });

    view.form.dispatch('submit', { submitter: view.submitButton });
    view.form.dispatch('submit', { submitter: view.submitButton });
    assert.equal(fetchCount, 1);
    assert.equal(view.submitButton.disabled, true);
    assert.equal(view.titleLibrary.disabled, true);
    assert.equal(view.articleLimit.disabled, true);
    assert.equal(view.submitLabel.textContent, '正在检查配置');

    resolveFetch({ ok: true, json: async () => readinessReport({ status: 'ready', can_save: true, can_activate: true, requires_acknowledgement: false, issues: [] }) });
    await flush();
    await flush();

    assert.equal(view.form.submitted, true);
    assert.equal(view.titleLibrary.disabled, false);
    assert.equal(fetchCount, 1);
});

test('a stalled readiness request times out and opens the server-check fallback', async () => {
    const view = fixture(() => new Promise(() => {}), true, { readinessTimeoutMs: 5 });

    view.form.dispatch('submit', { submitter: view.submitButton });
    await new Promise((resolve) => setTimeout(resolve, 15));

    assert.equal(view.dialog.open, true);
    assert.equal(view.dialogElements.retry.hidden, false);
    assert.equal(view.dialogElements.server.hidden, false);
    assert.equal(view.submitButton.disabled, false);
    assert.equal(view.titleLibrary.disabled, false);
});

test('a report for stale field values is discarded and the current configuration is rechecked', async () => {
    let resolveFirst;
    let fetchCount = 0;
    const view = fixture(() => {
        fetchCount += 1;
        if (fetchCount === 1) return new Promise((resolve) => { resolveFirst = resolve; });

        return Promise.resolve({
            ok: true,
            json: async () => readinessReport({
                status: 'ready',
                can_save: true,
                can_activate: true,
                requires_acknowledgement: false,
                issues: [],
            }),
        });
    });

    view.form.dispatch('submit', { submitter: view.submitButton });
    view.articleLimit.value = '4';
    resolveFirst({ ok: true, json: async () => readinessReport() });
    await flush();
    await flush();

    assert.equal(fetchCount, 2);
    assert.equal(view.form.submitted, true);
});

test('blocking report renders details, focuses close, honors reduced motion, and contextual actions recheck', async () => {
    const reports = [
        readinessReport(),
        readinessReport({ status: 'warning', can_save: true, can_activate: true, requires_acknowledgement: true, task: { status: 'active', is_loop: true, created_count: 2, remaining: 4 } }),
    ];
    const view = fixture(async () => ({ ok: true, json: async () => reports.shift() }), true);

    view.form.dispatch('submit', { submitter: view.submitButton });
    await flush();

    assert.equal(view.dialog.open, true);
    assert.equal(view.dialogElements.summary.textContent, '标题库统计摘要');
    assert.equal(view.dialogElements.issues.children[0].children[0].textContent, '可用标题不足');
    assert.equal(fakeActiveElement.value, view.dialogElements.closeHeader);
    assert.equal(view.dialog.animationCount, 0);
    assert.equal(view.dialogElements.adjust.hidden, false);
    assert.equal(view.dialogElements.loop.hidden, false);
    assert.equal(view.dialogElements.pause.hidden, false);

    view.dialogElements.adjust.dispatch('click');
    await flush();
    assert.equal(view.articleLimit.value, '3');
    assert.equal(view.draftLimit.value, '3');
    assert.equal(view.dialogElements.acknowledge.hidden, false);

    view.dialogElements.acknowledge.dispatch('click');
    assert.equal(view.form.submitted, true);
});

test('closing the readiness dialog returns focus to the form submitter', async () => {
    const view = fixture(async () => ({ ok: true, json: async () => readinessReport() }));

    view.form.dispatch('submit', { submitter: view.submitButton });
    await flush();
    view.dialogElements.closeHeader.dispatch('click');

    assert.equal(view.dialog.open, false);
    assert.equal(fakeActiveElement.value, view.submitButton);
});

test('loop, paused save, warning acknowledgement, and request failure fallback remain actionable', async () => {
    const reports = [readinessReport(), readinessReport({
        status: 'warning',
        can_save: true,
        can_activate: true,
        requires_acknowledgement: true,
        task: { status: 'active', is_loop: true, created_count: 2, remaining: 4 },
    })];
    const loopView = fixture(async () => ({ ok: true, json: async () => reports.shift() }));
    loopView.form.dispatch('submit', { submitter: loopView.submitButton });
    await flush();
    loopView.dialogElements.loop.dispatch('click');
    await flush();
    assert.equal(loopView.loopMode.checked, true);
    loopView.dialogElements.acknowledge.dispatch('click');
    assert.equal(loopView.form.submitted, true);

    const pausedView = fixture(async () => ({ ok: true, json: async () => readinessReport() }));
    pausedView.form.dispatch('submit', { submitter: pausedView.submitButton });
    await flush();
    pausedView.dialogElements.pause.dispatch('click');
    assert.equal(pausedView.status.value, 'paused');
    assert.equal(pausedView.form.submitted, true);

    const failedView = fixture(async () => { throw new Error('offline'); });
    failedView.form.dispatch('submit', { submitter: failedView.submitButton });
    await flush();
    assert.equal(failedView.dialog.open, true);
    assert.equal(failedView.dialogElements.retry.hidden, false);
    assert.equal(failedView.dialogElements.server.hidden, false);
    failedView.dialogElements.server.dispatch('click');
    assert.equal(failedView.form.submitted, true);
});

test('a confirmation blocked by native validation cannot bypass the next readiness check', async () => {
    let fetchCount = 0;
    const report = readinessReport({
        status: 'warning',
        can_save: true,
        can_activate: true,
        requires_acknowledgement: true,
        task: { status: 'active', is_loop: true, created_count: 2, remaining: 4 },
    });
    const view = fixture(async () => {
        fetchCount += 1;

        return { ok: true, json: async () => report };
    });

    view.form.dispatch('submit', { submitter: view.submitButton });
    await flush();
    view.form.blockSubmission = true;
    view.dialogElements.acknowledge.dispatch('click');
    await flush();
    view.form.blockSubmission = false;
    view.form.dispatch('submit', { submitter: view.submitButton });
    await flush();

    assert.equal(fetchCount, 2);
});
