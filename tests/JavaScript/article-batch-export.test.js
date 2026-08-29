import assert from 'node:assert/strict';
import test from 'node:test';

import { initializeArticleBatchExport } from '../../resources/js/admin/article-batch-export.js';

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(type, listener, options = false) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push({
            listener,
            capture: typeof options === 'object' ? Boolean(options.capture) : Boolean(options),
        });
        this.listeners.set(type, listeners);
    }

    async dispatch(type, properties = {}) {
        const event = {
            target: this,
            defaultPrevented: false,
            propagationStopped: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
            stopImmediatePropagation() {
                this.propagationStopped = true;
            },
            ...properties,
        };

        const listeners = [...(this.listeners.get(type) ?? [])]
            .sort((first, second) => Number(second.capture) - Number(first.capture));
        for (const { listener } of listeners) {
            await listener(event);
            if (event.propagationStopped) break;
        }

        return event;
    }
}

class FakeElement extends FakeEventTarget {
    constructor() {
        super();
        this.attributes = new Map();
        this.dataset = {};
        this.disabled = false;
        this.hidden = false;
        this.scrollTop = 0;
        this.textContent = '';
        this.value = '';
        this.checked = false;
        this.focused = false;
    }

    focus() {
        this.focused = true;
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }
}

class FakeDialog extends FakeElement {
    constructor(elements) {
        super();
        this.elements = elements;
        this.open = false;
        this.dataset = {
            prepareUrl: '/admin/articles/batch/export-markdown/prepare',
            maxArticles: '500',
            selectArticlesMessage: '请选择至少一篇文章',
            tooManyMessage: '每次最多导出 500 篇文章',
            invalidResponseMessage: '导出响应无效，请重试',
            networkErrorMessage: '网络异常，请重试',
            expiredMessage: '下载链接已失效，请重新导出',
            csrfExpiredMessage: '页面安全校验已失效，请刷新页面后重试',
            rateLimitedMessage: '操作过于频繁，请稍候再试',
            requestTooLargeMessage: '导出请求数据过大，请减少选择后重试',
        };
    }

    querySelector(selector) {
        return this.elements[selector] ?? null;
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
    }

    getBoundingClientRect() {
        return { left: 100, right: 500, top: 100, bottom: 500 };
    }
}

function response(status, payload, contentType = 'application/json') {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: {
            get(name) {
                return name.toLowerCase() === 'content-type' ? contentType : null;
            },
        },
        async json() {
            return payload;
        },
    };
}

function fixture({ fetchImpl, now = () => Date.parse('2026-08-27T08:00:00Z') } = {}) {
    const action = new FakeElement();
    action.value = 'export_markdown';
    const execute = new FakeElement();
    const exportOption = new FakeElement();
    exportOption.disabled = true;
    const cancel = new FakeElement();
    const selectAll = new FakeElement();
    const form = new FakeElement();
    form.dataset.csrfToken = 'csrf-token';
    const loading = new FakeElement();
    const success = new FakeElement();
    const error = new FakeElement();
    const loadingFocus = new FakeElement();
    const successFocus = new FakeElement();
    const errorFocus = new FakeElement();
    const selectedCount = new FakeElement();
    const filename = new FakeElement();
    const errorMessage = new FakeElement();
    const retry = new FakeElement();
    const closeButtons = [new FakeElement(), new FakeElement()];
    const dialog = new FakeDialog({
        '[data-export-state="loading"]': loading,
        '[data-export-state="success"]': success,
        '[data-export-state="error"]': error,
        '[data-export-loading-focus]': loadingFocus,
        '[data-export-success-focus]': successFocus,
        '[data-export-error-focus]': errorFocus,
        '[data-export-selected-count]': selectedCount,
        '[data-export-filename]': filename,
        '[data-export-error-message]': errorMessage,
        '[data-export-retry]': retry,
    });
    const checkboxes = [new FakeElement(), new FakeElement()];
    checkboxes[0].checked = true;
    checkboxes[0].value = '22';
    checkboxes[1].checked = true;
    checkboxes[1].value = '7';
    const controls = [action, execute, cancel, selectAll];
    const downloads = [];
    const formDataEntries = [];
    const notices = [];
    let genericSubmissions = 0;
    form.addEventListener('submit', () => {
        genericSubmissions++;
    });
    const root = {
        querySelector(selector) {
            const elements = {
                '[data-article-batch-export]': dialog,
                '#batch-form': form,
                '#batch-action': action,
                '[data-batch-execute]': execute,
                '[data-article-batch-export-option]': exportOption,
            };

            return elements[selector] ?? null;
        },
        querySelectorAll(selector) {
            if (selector === '.article-checkbox:checked') return checkboxes.filter((checkbox) => checkbox.checked);
            if (selector === '.article-checkbox') return checkboxes;
            if (selector === '[data-article-batch-control]') return controls;
            if (selector === '[data-export-close]') return closeButtons;

            return [];
        },
    };

    initializeArticleBatchExport(root, {
        fetchImpl,
        now,
        notify: (message) => notices.push(message),
        download: (url, downloadFilename) => downloads.push({ url, filename: downloadFilename }),
        formDataFactory: () => ({
            append: (key, value) => formDataEntries.push([key, value]),
        }),
        origin: 'http://localhost:18080',
    });

    return {
        action,
        checkboxes,
        closeButtons,
        controls,
        dialog,
        downloads,
        error,
        errorFocus,
        errorMessage,
        execute,
        exportOption,
        filename,
        formDataEntries,
        form,
        genericSubmissions: () => genericSubmissions,
        loading,
        loadingFocus,
        notices,
        retry,
        selectedCount,
        success,
        successFocus,
    };
}

test('keeps the export option disabled when the handler cannot finish initializing', () => {
    const exportOption = new FakeElement();
    exportOption.disabled = true;

    initializeArticleBatchExport({
        querySelector(selector) {
            return selector === '[data-article-batch-export-option]' ? exportOption : null;
        },
    });

    assert.equal(exportOption.disabled, true);
});

test('prepares the selected articles, starts the download, and keeps a manual retry action', async () => {
    const requests = [];
    const ui = fixture({
        fetchImpl: async (url, options) => {
            requests.push({ url, options });

            return response(200, {
                data: {
                    count: 2,
                    filename: 'geoflow-articles-20260827-160000.zip',
                    download_url: '/admin/articles/batch/export-markdown/download/token?owner=1&signature=signed',
                    expires_at: '2026-08-27T08:10:00Z',
                },
            });
        },
    });

    const event = await ui.form.dispatch('submit');

    assert.equal(event.defaultPrevented, true);
    assert.equal(event.propagationStopped, true);
    assert.equal(ui.exportOption.disabled, false);
    assert.equal(ui.genericSubmissions(), 0);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/admin/articles/batch/export-markdown/prepare');
    assert.deepEqual(ui.formDataEntries, [
        ['_token', 'csrf-token'],
        ['article_ids[]', '22'],
        ['article_ids[]', '7'],
    ]);
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.deepEqual(ui.downloads, [{
        url: '/admin/articles/batch/export-markdown/download/token?owner=1&signature=signed',
        filename: 'geoflow-articles-20260827-160000.zip',
    }]);
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.loading.hidden, true);
    assert.equal(ui.success.hidden, false);
    assert.equal(ui.error.hidden, true);
    assert.equal(ui.selectedCount.textContent, '2');
    assert.equal(ui.filename.textContent, 'geoflow-articles-20260827-160000.zip');
    assert.equal(ui.successFocus.focused, true);
    assert.equal(ui.controls.every((control) => control.disabled === false), true);
    assert.equal(ui.checkboxes.every((checkbox) => checkbox.disabled === false), true);

    await ui.retry.dispatch('click');

    assert.equal(ui.downloads.length, 2);
    assert.deepEqual(ui.downloads[1], ui.downloads[0]);
});

test('rejects an off-origin download URL and displays a recoverable error', async () => {
    const ui = fixture({
        fetchImpl: async () => response(200, {
            data: {
                count: 2,
                filename: 'articles.zip',
                download_url: 'https://attacker.example/export.zip',
                expires_at: '2026-08-27T08:10:00Z',
            },
        }),
    });

    await ui.form.dispatch('submit');

    assert.equal(ui.downloads.length, 0);
    assert.equal(ui.loading.hidden, true);
    assert.equal(ui.success.hidden, true);
    assert.equal(ui.error.hidden, false);
    assert.equal(ui.errorMessage.textContent, '导出响应无效，请重试');
    assert.equal(ui.errorFocus.focused, true);
});

test('rejects a response count that does not match the submitted selection', async () => {
    const ui = fixture({
        fetchImpl: async () => response(200, {
            data: {
                count: 1,
                filename: 'articles.zip',
                download_url: '/admin/articles/batch/export-markdown/download/token?signature=signed',
                expires_at: '2026-08-27T08:10:00Z',
            },
        }),
    });

    await ui.form.dispatch('submit');

    assert.equal(ui.downloads.length, 0);
    assert.equal(ui.error.hidden, false);
    assert.equal(ui.errorMessage.textContent, '导出响应无效，请重试');
});

test('shows the server message after a concurrent export conflict', async () => {
    const ui = fixture({
        fetchImpl: async () => response(409, { message: '已有导出任务正在处理，请稍候' }),
    });

    await ui.form.dispatch('submit');

    assert.equal(ui.error.hidden, false);
    assert.equal(ui.errorMessage.textContent, '已有导出任务正在处理，请稍候');
    assert.equal(ui.controls.every((control) => control.disabled === false), true);
});

for (const [status, serverMessage, expectedMessage] of [
    [413, 'Content Too Large.', '导出请求数据过大，请减少选择后重试'],
    [419, 'CSRF token mismatch.', '页面安全校验已失效，请刷新页面后重试'],
    [429, 'Too Many Attempts.', '操作过于频繁，请稍候再试'],
]) {
    test(`localizes the ${status} export error`, async () => {
        const ui = fixture({
            fetchImpl: async () => response(status, { message: serverMessage }),
        });

        await ui.form.dispatch('submit');

        assert.equal(ui.error.hidden, false);
        assert.equal(ui.errorMessage.textContent, expectedMessage);
        assert.equal(ui.downloads.length, 0);
    });
}

test('recovers from non-JSON and rejected prepare requests', async () => {
    for (const fetchImpl of [
        async () => response(502, {}, 'text/html'),
        async () => { throw new TypeError('network failed'); },
    ]) {
        const ui = fixture({ fetchImpl });

        await ui.form.dispatch('submit');

        assert.equal(ui.error.hidden, false);
        assert.equal(ui.errorMessage.textContent, '网络异常，请重试');
        assert.equal(ui.controls.every((control) => control.disabled === false), true);
    }
});

test('leaves non-export batch actions to the existing form handler', async () => {
    let calls = 0;
    const ui = fixture({
        fetchImpl: async () => {
            calls++;
            return response(500, {});
        },
    });
    ui.action.value = 'delete_articles';

    const event = await ui.form.dispatch('submit');

    assert.equal(event.defaultPrevented, false);
    assert.equal(event.propagationStopped, false);
    assert.equal(ui.genericSubmissions(), 1);
    assert.equal(calls, 0);
});

test('locks the batch controls and ignores a duplicate submission while preparing', async () => {
    let resolveRequest;
    let calls = 0;
    const ui = fixture({
        fetchImpl: async () => {
            calls++;

            return await new Promise((resolve) => {
                resolveRequest = resolve;
            });
        },
    });

    const firstSubmission = ui.form.dispatch('submit');
    await Promise.resolve();

    assert.equal(ui.loading.hidden, false);
    assert.equal(ui.controls.every((control) => control.disabled === true), true);
    assert.equal(ui.checkboxes.every((checkbox) => checkbox.disabled === true), true);

    await ui.dialog.dispatch('click', { target: ui.dialog });
    assert.equal(ui.dialog.open, true);

    await ui.form.dispatch('submit');
    assert.equal(calls, 1);

    ui.dialog.scrollTop = 240;
    resolveRequest(response(200, {
        data: {
            count: 2,
            filename: 'articles.zip',
            download_url: '/admin/articles/batch/export-markdown/download/token?signature=signed',
            expires_at: '2026-08-27T08:10:00Z',
        },
    }));
    await firstSubmission;
    assert.equal(ui.dialog.scrollTop, 0);
});

test('does not call the server when no article is selected', async () => {
    let calls = 0;
    const ui = fixture({
        fetchImpl: async () => {
            calls++;
            return response(500, {});
        },
    });
    ui.checkboxes.forEach((checkbox) => checkbox.checked = false);

    await ui.form.dispatch('submit');

    assert.equal(calls, 0);
    assert.deepEqual(ui.notices, ['请选择至少一篇文章']);
    assert.equal(ui.dialog.open, false);
});

test('blocks selections above the configured maximum before requesting an export', async () => {
    let calls = 0;
    const ui = fixture({
        fetchImpl: async () => {
            calls++;
            return response(500, {});
        },
    });
    for (let index = ui.checkboxes.length; index < 501; index++) {
        const checkbox = new FakeElement();
        checkbox.checked = true;
        checkbox.value = String(index + 1);
        ui.checkboxes.push(checkbox);
    }

    await ui.form.dispatch('submit');

    assert.equal(calls, 0);
    assert.equal(ui.error.hidden, false);
    assert.equal(ui.errorMessage.textContent, '每次最多导出 500 篇文章');
});

test('closing a completed export returns focus to the Execute button', async () => {
    const ui = fixture({
        fetchImpl: async () => response(200, {
            data: {
                count: 2,
                filename: 'articles.zip',
                download_url: '/admin/articles/batch/export-markdown/download/token?signature=signed',
                expires_at: '2026-08-27T08:10:00Z',
            },
        }),
    });
    await ui.form.dispatch('submit');

    await ui.closeButtons[0].dispatch('click');

    assert.equal(ui.dialog.open, false);
    assert.equal(ui.execute.focused, true);
});

test('clicking outside the dialog closes it while clicking its border keeps it open', async () => {
    const ui = fixture({
        fetchImpl: async () => response(200, {
            data: {
                count: 2,
                filename: 'articles.zip',
                download_url: '/admin/articles/batch/export-markdown/download/token?signature=signed',
                expires_at: '2026-08-27T08:10:00Z',
            },
        }),
    });
    await ui.form.dispatch('submit');

    await ui.dialog.dispatch('click', { target: new FakeElement() });
    assert.equal(ui.dialog.open, true);

    await ui.dialog.dispatch('click', { target: ui.dialog, clientX: 100, clientY: 250 });
    assert.equal(ui.dialog.open, true);

    await ui.dialog.dispatch('click', { target: ui.dialog, clientX: 50, clientY: 50 });

    assert.equal(ui.dialog.open, false);
    assert.equal(ui.execute.focused, true);
});

test('manual retry reports an expired signed link', async () => {
    let currentTime = Date.parse('2026-08-27T08:00:00Z');
    const ui = fixture({
        now: () => currentTime,
        fetchImpl: async () => response(200, {
            data: {
                count: 2,
                filename: 'articles.zip',
                download_url: '/admin/articles/batch/export-markdown/download/token?signature=signed',
                expires_at: '2026-08-27T08:10:00Z',
            },
        }),
    });

    await ui.form.dispatch('submit');
    currentTime = Date.parse('2026-08-27T08:11:00Z');
    await ui.retry.dispatch('click');

    assert.equal(ui.error.hidden, false);
    assert.equal(ui.errorMessage.textContent, '下载链接已失效，请重新导出');
    assert.equal(ui.downloads.length, 1);
});

test('does not start the first download when the signed link has already expired', async () => {
    const ui = fixture({
        now: () => Date.parse('2026-08-27T08:11:00Z'),
        fetchImpl: async () => response(200, {
            data: {
                count: 2,
                filename: 'articles.zip',
                download_url: '/admin/articles/batch/export-markdown/download/token?signature=signed',
                expires_at: '2026-08-27T08:10:00Z',
            },
        }),
    });

    await ui.form.dispatch('submit');

    assert.equal(ui.error.hidden, false);
    assert.equal(ui.errorMessage.textContent, '下载链接已失效，请重新导出');
    assert.equal(ui.downloads.length, 0);
});
