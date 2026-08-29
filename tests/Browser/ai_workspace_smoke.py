import json
import os
from pathlib import Path

from playwright.sync_api import sync_playwright


base_url = os.environ.get("AIW_BROWSER_BASE_URL", "http://localhost:28080")
admin_path = os.environ.get("AIW_BROWSER_ADMIN_PATH", "admin").strip("/")
username = os.environ.get("AIW_BROWSER_USERNAME", "browser_admin")
password = os.environ.get("AIW_BROWSER_PASSWORD", "BrowserPass123")
screenshot_dir = Path(os.environ.get("AIW_BROWSER_SCREENSHOT_DIR", "/tmp"))
runtime_enabled = os.environ.get("AIW_BROWSER_RUNTIME_ENABLED", "true").lower() == "true"
manifest = json.loads(Path("public/build/manifest.json").read_text(encoding="utf-8"))
workspace_asset = "/build/" + manifest["resources/js/admin/ai-workspace.js"]["file"]

screenshot_dir.mkdir(parents=True, exist_ok=True)

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1280, "height": 900}, device_scale_factor=1)
    console_errors = []
    page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)

    for _ in range(60):
        page.goto(f"{base_url}/{admin_path}/ai-workspace")
        if page.locator('input[name="username"]').count() == 1:
            break
        page.wait_for_timeout(500)
    else:
        raise AssertionError("AI workspace gateway did not become ready within 30 seconds")

    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    page.goto(f"{base_url}/{admin_path}/ai-workspace")
    page.wait_for_load_state("networkidle")

    workspace = page.locator("[data-ai-workspace]")
    workspace.wait_for(state="visible")
    assert workspace.get_attribute("data-runtime-enabled") == str(runtime_enabled).lower()
    user_initial = (workspace.get_attribute("data-user-initial") or "").strip()
    assert user_initial
    assert page.locator("[data-ai-runs]").count() == 0
    assert page.locator("[data-ai-capability-drawer]").count() == 0
    assert page.locator(".gf-ai-help__starters [data-ai-suggestion]").count() == 6
    assert page.locator("[data-ai-showcase-slide]").count() == 4

    markdown_list_presentation = page.evaluate(
        """async ({ asset }) => {
            const { createStreamingMarkdownRenderer } = await import(asset);
            const wrapper = document.createElement('section');
            wrapper.className = 'gf-ai-help';
            wrapper.style.position = 'fixed';
            wrapper.style.left = '-2000px';
            const target = document.createElement('div');
            target.className = 'gf-ai-markdown';
            wrapper.append(target);
            document.body.append(wrapper);
            const renderer = createStreamingMarkdownRenderer(target);
            renderer.finish([
                '## 前置条件',
                '',
                '- 已准备目标地址。',
                '- 已明确渠道类型。',
                '',
                '## 操作步骤',
                '',
                '1. 进入内容分发页面。',
                '2. 保存渠道配置。',
                '',
                '普通说明段落需要保留句号。',
            ].join('\\n'));
            const result = {
                unordered_style: getComputedStyle(target.querySelector('ul')).listStyleType,
                ordered_style: getComputedStyle(target.querySelector('ol')).listStyleType,
                unordered_items: [...target.querySelectorAll('ul > li')].map((item) => item.textContent.trim()),
                ordered_items: [...target.querySelectorAll('ol > li')].map((item) => item.textContent.trim()),
                paragraph: target.querySelector('p').textContent.trim(),
            };
            wrapper.remove();
            return result;
        }""",
        {"asset": workspace_asset},
    )
    assert markdown_list_presentation == {
        "unordered_style": "disc",
        "ordered_style": "decimal",
        "unordered_items": ["已准备目标地址", "已明确渠道类型"],
        "ordered_items": ["进入内容分发页面", "保存渠道配置"],
        "paragraph": "普通说明段落需要保留句号。",
    }, markdown_list_presentation

    prompt = page.locator("[data-ai-input]")
    composer = page.locator("[data-ai-form]")
    assert prompt.is_enabled()
    composer_box = composer.bounding_box()
    assert composer_box is not None and composer_box["width"] <= 876
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-help-home-desktop.png"), full_page=True)

    prompt.fill("如何创建一个内容任务？")
    prompt.press("Enter")
    assistant = page.locator(".gf-ai-help__message.is-assistant").last
    assistant.wait_for(state="visible", timeout=10_000)
    page.wait_for_function(
        "!document.querySelector('.gf-ai-help__message.is-assistant:last-of-type')?.classList.contains('is-pending')",
        timeout=90_000,
    )
    assert page.locator(".gf-ai-help__message.is-user .gf-ai-help__avatar--user").last.is_visible()
    assert page.locator(".gf-ai-help__message.is-user .gf-ai-help__avatar--user").last.inner_text() == user_initial
    assert assistant.locator(".gf-ai-help__avatar--assistant").is_visible()
    assert page.locator("[data-ai-messages]").get_attribute("aria-live") == "off"
    assert page.locator(".gf-ai-help__thread-head").evaluate(
        "element => getComputedStyle(element).borderBottomWidth === '0px'"
    )
    assert page.locator(".gf-ai-help__related .gf-ai-help__feature").count() >= 1
    assert page.locator(".gf-ai-help__followups button").count() == 3
    assert page.locator(".gf-ai-help__related").evaluate(
        "element => getComputedStyle(element).borderTopWidth === '0px'"
    )
    media = page.locator(".gf-ai-help__media figure")
    assert media.count() == 1
    media_image = media.first.locator("img")
    media_image.scroll_into_view_if_needed()
    page.wait_for_function(
        "element => element.complete && element.naturalWidth > 0",
        arg=media_image.element_handle(),
    )
    message_body_box = assistant.locator(".gf-ai-help__message-body").bounding_box()
    media_box = media.first.bounding_box()
    assert message_body_box is not None and media_box is not None
    assert abs(
        media_box["x"] + media_box["width"] / 2
        - (message_body_box["x"] + message_body_box["width"] / 2)
    ) <= 2
    media.first.locator("button").click()
    preview = page.locator(".gf-ai-help__media-dialog")
    preview.wait_for(state="visible")
    page.wait_for_function(
        "element => element.complete && element.naturalWidth > 0",
        arg=preview.locator("img").element_handle(),
    )
    preview_box = preview.bounding_box()
    assert preview_box is not None and preview_box["width"] <= 780
    assert abs(preview_box["x"] + preview_box["width"] / 2 - page.viewport_size["width"] / 2) <= 2
    assert abs(preview_box["y"] + preview_box["height"] / 2 - page.viewport_size["height"] / 2) <= 2

    zoom_out = preview.locator(".gf-ai-help__media-dialog-zoom-out")
    zoom_in = preview.locator(".gf-ai-help__media-dialog-zoom-in")
    zoom_reset = preview.locator(".gf-ai-help__media-dialog-zoom-reset")
    zoom_value = preview.locator(".gf-ai-help__media-dialog-zoom-value")
    close_preview = preview.locator(".gf-ai-help__media-dialog-close")
    assert zoom_value.inner_text() == "100%"
    initial_image_width = preview.locator("img").bounding_box()["width"]
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-help-media-preview-default.png"))

    zoom_in.click()
    assert zoom_value.inner_text() == "125%"
    assert preview.locator("img").bounding_box()["width"] >= initial_image_width * 1.24
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-help-media-preview-zoomed.png"))
    zoom_out.click()
    zoom_out.click()
    assert zoom_value.inner_text() == "75%"
    assert preview.locator("img").bounding_box()["width"] <= initial_image_width * 0.76
    zoom_reset.click()
    assert zoom_value.inner_text() == "100%"
    page.keyboard.press("=")
    assert zoom_value.inner_text() == "125%"
    page.keyboard.press("0")
    assert zoom_value.inner_text() == "100%"

    assert close_preview.evaluate(
        "element => element.getBoundingClientRect().width >= 40 && element.getBoundingClientRect().height >= 40"
    )
    close_preview.click()
    assert page.locator(".gf-ai-help__followups").evaluate(
        "element => getComputedStyle(element).borderTopWidth === '0px'"
    )
    assert page.locator(".gf-ai-help__related > div").evaluate(
        "element => getComputedStyle(element).gridTemplateColumns.split(' ').length === 3"
    )
    if runtime_enabled:
        assert page.locator(".gf-ai-help__answer").last.inner_text().strip()
    else:
        assert page.locator(".gf-ai-help__error").last.is_visible()
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-help-answer-desktop.png"), full_page=True)

    markdown_stress = page.evaluate(
        """async ({ asset }) => {
            const { createStreamingMarkdownRenderer } = await import(asset);
            const target = document.createElement('div');
            document.body.append(target);
            const pattern = [
                '## GEOFlow 检查结果',
                '',
                '> 当前观测显示内容引用正在恢复。',
                '',
                '1. 打开数据中心',
                '   - 检查 AI 可见性',
                '   - 对比最近 30 天',
                '',
                '| 指标 | 状态 |',
                '| --- | --- |',
                '| 引用率 | 改善 |',
                '',
            ].join('\\n');
            const markdown = pattern.repeat(90) + '[外部链接](https://evil.example)\\n\\n```json\\n{"incomplete":true}';
            const renderer = createStreamingMarkdownRenderer(target, { copyCode: '复制代码' });
            const durations = [];
            for (let end = 256; end < markdown.length; end += 256) {
                const started = performance.now();
                renderer.update(markdown.slice(0, end));
                durations.push(performance.now() - started);
            }
            const finalStarted = performance.now();
            renderer.finish(markdown);
            durations.push(performance.now() - finalStarted);
            const result = {
                length: markdown.length,
                max_duration_ms: Math.max(...durations),
                has_table: Boolean(target.querySelector('table')),
                has_quote: Boolean(target.querySelector('blockquote')),
                has_nested_list: Boolean(target.querySelector('li ul')),
                has_code: Boolean(target.querySelector('pre code')),
                has_link: Boolean(target.querySelector('a')),
                has_script: Boolean(target.querySelector('script')),
            };
            target.remove();
            return result;
        }""",
        {"asset": workspace_asset},
    )
    assert markdown_stress["length"] >= 10_000
    assert markdown_stress["max_duration_ms"] < 50, markdown_stress
    assert markdown_stress["has_table"] and markdown_stress["has_quote"]
    assert markdown_stress["has_nested_list"] and markdown_stress["has_code"]
    assert not markdown_stress["has_link"] and not markdown_stress["has_script"]

    stop_result = page.evaluate(
        """async ({ asset }) => {
            const { setupAiWorkspace } = await import(asset);
            const wrapper = document.createElement('div');
            wrapper.className = 'gf-main';
            wrapper.style.position = 'fixed';
            wrapper.style.left = '-2000px';
            wrapper.style.width = '780px';
            wrapper.innerHTML = `
                <div data-ai-workspace data-user-initial="验" data-admin-base-path="/admin"
                    data-conversations-url="/admin/ai-workspace/conversations"
                    data-conversation-url-template="/admin/ai-workspace/conversations/__ID__"
                    data-message-url-template="/admin/ai-workspace/conversations/__ID__/messages"
                    data-update-url-template="/admin/ai-workspace/conversations/__ID__">
                    <script type="application/json" data-ai-labels>{"answerStopped":"已停止生成","copyAnswer":"复制回答"}</script>
                    <section data-ai-thread hidden><strong data-ai-thread-title></strong><div data-ai-messages></div></section>
                    <section data-ai-start></section>
                    <form data-ai-form><textarea data-ai-input></textarea><button data-ai-stop type="button" hidden></button><button data-ai-send type="submit"></button></form>
                </div>`;
            document.body.append(wrapper);
            const root = wrapper.firstElementChild;
            const encoder = new TextEncoder();
            const fetcher = async (url, options = {}) => {
                if ((options.method ?? 'GET') !== 'POST') {
                    const id = new URL(location.href).searchParams.get('conversation') ?? 'test-conversation';
                    return new Response(JSON.stringify({ data: { id, title: '测试', messages: [], message_page: {} } }), {
                        headers: { 'Content-Type': 'application/json' },
                    });
                }
                let streamController;
                const body = new ReadableStream({ start(controller) { streamController = controller; } });
                options.signal.addEventListener('abort', () => streamController.error(new DOMException('Stopped', 'AbortError')), { once: true });
                setTimeout(() => streamController.enqueue(encoder.encode('event: delta\\ndata: {"content":"保留这段部分回答"}\\n\\n')), 5);
                return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
            };
            const instance = setupAiWorkspace(root, { fetcher });
            await new Promise((resolve) => setTimeout(resolve, 20));
            const sending = instance.sendQuestion('停止测试');
            for (let attempt = 0; attempt < 50 && root.querySelector('.gf-ai-help__answer')?.hidden !== false; attempt += 1) {
                await new Promise((resolve) => setTimeout(resolve, 5));
            }
            root.querySelector('[data-ai-stop]').click();
            await sending;
            const result = {
                answer: root.querySelector('.gf-ai-help__answer')?.textContent ?? '',
                stopped: root.querySelector('.gf-ai-help__stopped')?.textContent ?? '',
                has_copy: Boolean(root.querySelector('.gf-ai-help__copy')),
                assistant_kept: Boolean(root.querySelector('.gf-ai-help__message.is-assistant')),
            };
            wrapper.remove();
            return result;
        }""",
        {"asset": workspace_asset},
    )
    assert stop_result["answer"].strip() == "保留这段部分回答", stop_result
    assert "已停止生成" in stop_result["stopped"]
    assert stop_result["has_copy"] and stop_result["assistant_kept"]

    stop_before_delta_result = page.evaluate(
        """async ({ asset }) => {
            history.replaceState({}, '', location.pathname);
            const { setupAiWorkspace } = await import(asset);
            const wrapper = document.createElement('div');
            wrapper.className = 'gf-main';
            wrapper.style.position = 'fixed';
            wrapper.style.left = '-2000px';
            wrapper.innerHTML = `
                <div data-ai-workspace data-user-initial="验" data-admin-base-path="/admin"
                    data-conversations-url="/admin/ai-workspace/conversations"
                    data-conversation-url-template="/admin/ai-workspace/conversations/__ID__"
                    data-message-url-template="/admin/ai-workspace/conversations/__ID__/messages"
                    data-update-url-template="/admin/ai-workspace/conversations/__ID__">
                    <script type="application/json" data-ai-labels>{"answerStopped":"已停止生成"}</script>
                    <section data-ai-thread hidden><strong data-ai-thread-title></strong><div data-ai-messages></div></section>
                    <section data-ai-start></section>
                    <form data-ai-form><textarea data-ai-input></textarea><button data-ai-stop type="button" hidden></button><button data-ai-send type="submit"></button></form>
                </div>`;
            document.body.append(wrapper);
            const root = wrapper.firstElementChild;
            const fetcher = async (url, options = {}) => {
                if (String(url).endsWith('/conversations') && options.method === 'POST') {
                    return new Response(JSON.stringify({ data: { id: 'zero-delta', title: '新对话' } }), { headers: { 'Content-Type': 'application/json' } });
                }
                if (options.signal.aborted) throw new DOMException('Stopped', 'AbortError');
                let streamController;
                const body = new ReadableStream({ start(controller) { streamController = controller; } });
                options.signal.addEventListener('abort', () => streamController.error(new DOMException('Stopped', 'AbortError')), { once: true });
                return new Response(body, { headers: { 'Content-Type': 'text/event-stream' } });
            };
            const instance = setupAiWorkspace(root, { fetcher });
            const sending = instance.sendQuestion('首个 delta 前停止');
            for (let attempt = 0; attempt < 50 && root.querySelector('[data-ai-stop]').hidden; attempt += 1) {
                await new Promise((resolve) => setTimeout(resolve, 5));
            }
            root.querySelector('[data-ai-stop]').click();
            await sending;
            const result = {
                input: root.querySelector('[data-ai-input]').value,
                users: root.querySelectorAll('.gf-ai-help__message.is-user').length,
                stopped: root.querySelectorAll('.gf-ai-help__stopped').length,
            };
            wrapper.remove();
            return result;
        }""",
        {"asset": workspace_asset},
    )
    assert stop_before_delta_result == {"input": "", "users": 1, "stopped": 1}, stop_before_delta_result

    busy_turn_result = page.evaluate(
        """async ({ asset }) => {
            history.replaceState({}, '', location.pathname);
            const { setupAiWorkspace } = await import(asset);
            const wrapper = document.createElement('div');
            wrapper.className = 'gf-main';
            wrapper.style.position = 'fixed';
            wrapper.style.left = '-2000px';
            wrapper.innerHTML = `
                <div data-ai-workspace data-user-initial="验" data-admin-base-path="/admin"
                    data-conversations-url="/admin/ai-workspace/conversations"
                    data-conversation-url-template="/admin/ai-workspace/conversations/__ID__"
                    data-message-url-template="/admin/ai-workspace/conversations/__ID__/messages"
                    data-update-url-template="/admin/ai-workspace/conversations/__ID__">
                    <script type="application/json" data-ai-labels>{"networkError":"请求失败"}</script>
                    <div data-ai-alert hidden></div>
                    <section data-ai-thread hidden><strong data-ai-thread-title></strong><div data-ai-messages></div></section>
                    <section data-ai-start></section>
                    <form data-ai-form><textarea data-ai-input></textarea><p data-ai-composer-error hidden></p><button data-ai-stop type="button" hidden></button><button data-ai-send type="submit"></button></form>
                </div>`;
            document.body.append(wrapper);
            const root = wrapper.firstElementChild;
            const encoder = new TextEncoder();
            const fetcher = async (url, options = {}) => {
                if (String(url).endsWith('/conversations') && options.method === 'POST') {
                    return new Response(JSON.stringify({ data: { id: 'busy-conversation', title: '新对话' } }), { headers: { 'Content-Type': 'application/json' } });
                }
                const body = new ReadableStream({
                    start(controller) {
                        controller.enqueue(encoder.encode('event: error\\ndata: {"message":"该对话正在生成回答","persisted":false}\\n\\n'));
                        controller.close();
                    },
                });
                return new Response(body, { headers: { 'Content-Type': 'text/event-stream' } });
            };
            const instance = setupAiWorkspace(root, { fetcher });
            await instance.sendQuestion('保留这个并发问题');
            const result = {
                input: root.querySelector('[data-ai-input]').value,
                messages: root.querySelectorAll('.gf-ai-help__message').length,
                error: root.querySelector('[data-ai-composer-error]').textContent,
            };
            wrapper.remove();
            return result;
        }""",
        {"asset": workspace_asset},
    )
    assert busy_turn_result == {
        "input": "保留这个并发问题",
        "messages": 0,
        "error": "该对话正在生成回答",
    }, busy_turn_result

    client_race_result = page.evaluate(
        """async ({ asset }) => {
            history.replaceState({}, '', location.pathname);
            const { setupAiWorkspace } = await import(asset);
            const markup = () => `
                <div data-ai-workspace data-user-initial="验" data-admin-base-path="/admin"
                    data-conversations-url="/admin/ai-workspace/conversations"
                    data-conversation-url-template="/admin/ai-workspace/conversations/__ID__"
                    data-message-url-template="/admin/ai-workspace/conversations/__ID__/messages"
                    data-update-url-template="/admin/ai-workspace/conversations/__ID__">
                    <script type="application/json" data-ai-labels>{"copyAnswer":"复制回答","answerComplete":"回答已生成"}</script>
                    <div data-ai-alert hidden></div>
                    <section data-ai-thread hidden><strong data-ai-thread-title></strong><button data-ai-rename type="button"></button><button data-ai-load-earlier hidden></button><div data-ai-messages aria-live="off"></div></section>
                    <section data-ai-start></section>
                    <form data-ai-form><textarea data-ai-input></textarea><p data-ai-composer-error hidden></p><button data-ai-stop type="button" hidden></button><button data-ai-send type="submit"></button></form>
                </div>`;

            const wrapper = document.createElement('div');
            wrapper.className = 'gf-main';
            wrapper.style.position = 'fixed';
            wrapper.style.left = '-2000px';
            wrapper.style.width = '780px';
            wrapper.innerHTML = markup();
            document.body.append(wrapper);
            const root = wrapper.firstElementChild;
            const encoder = new TextEncoder();
            let createCalls = 0;
            let messageCalls = 0;
            let streamCancelled = false;
            const fetcher = async (url, options = {}) => {
                if (String(url).endsWith('/conversations') && options.method === 'POST') {
                    createCalls += 1;
                    await new Promise((resolve) => setTimeout(resolve, 25));
                    return new Response(JSON.stringify({ data: { id: 'race-conversation', title: '新对话' } }), { headers: { 'Content-Type': 'application/json' } });
                }
                if (String(url).endsWith('/messages') && options.method === 'POST') {
                    messageCalls += 1;
                    const body = new ReadableStream({
                        start(controller) {
                            controller.enqueue(encoder.encode('event: delta\\ndata: {"content":"完整回答"}\\n\\nevent: done\\ndata: {"message_id":"m1","conversation_title":"并发测试"}\\n\\n'));
                        },
                        cancel() {
                            streamCancelled = true;
                            throw new Error('transport close failed');
                        },
                    });
                    return new Response(body, { headers: { 'Content-Type': 'text/event-stream' } });
                }
                return new Response(JSON.stringify({ data: { id: 'race-conversation', title: '并发测试', messages: [], message_page: {} } }), { headers: { 'Content-Type': 'application/json' } });
            };
            const instance = setupAiWorkspace(root, { fetcher });
            const first = instance.sendQuestion('第一次');
            const second = instance.sendQuestion('第二次');
            await Promise.all([first, second]);
            const race = {
                create_calls: createCalls,
                message_calls: messageCalls,
                user_messages: root.querySelectorAll('.gf-ai-help__message.is-user').length,
                copies: root.querySelectorAll('.gf-ai-help__copy').length,
                errors: root.querySelectorAll('.gf-ai-help__error').length,
                stop_hidden: root.querySelector('[data-ai-stop]').hidden,
                stream_cancelled: streamCancelled,
            };
            wrapper.remove();

            history.replaceState({}, '', location.pathname);
            const failureWrapper = document.createElement('div');
            failureWrapper.className = 'gf-main';
            failureWrapper.style.position = 'fixed';
            failureWrapper.style.left = '-2000px';
            failureWrapper.innerHTML = markup();
            document.body.append(failureWrapper);
            const failureRoot = failureWrapper.firstElementChild;
            const failureInstance = setupAiWorkspace(failureRoot, {
                fetcher: async () => new Response(JSON.stringify({ message: '会话创建失败' }), { status: 500, headers: { 'Content-Type': 'application/json' } }),
            });
            await failureInstance.sendQuestion('保留这个问题');
            const failure = {
                input: failureRoot.querySelector('[data-ai-input]').value,
                error: failureRoot.querySelector('[data-ai-composer-error]').textContent,
                generating: !failureRoot.querySelector('[data-ai-stop]').hidden,
            };
            failureWrapper.remove();

            history.replaceState({}, '', `${location.pathname}?conversation=abandoned-conversation`);
            const abandonedWrapper = document.createElement('div');
            abandonedWrapper.className = 'gf-main';
            abandonedWrapper.style.position = 'fixed';
            abandonedWrapper.style.left = '-2000px';
            abandonedWrapper.innerHTML = markup();
            document.body.append(abandonedWrapper);
            const abandonedRoot = abandonedWrapper.firstElementChild;
            const abandonedEncoder = new TextEncoder();
            let historyAborted = false;
            const abandonedInstance = setupAiWorkspace(abandonedRoot, {
                fetcher: async (url, options = {}) => {
                    if ((options.method ?? 'GET') === 'GET') {
                        return new Promise((resolve, reject) => {
                            options.signal.addEventListener('abort', () => {
                                historyAborted = true;
                                reject(new DOMException('Abandoned', 'AbortError'));
                            }, { once: true });
                        });
                    }
                    if (String(url).endsWith('/conversations')) {
                        return new Response(JSON.stringify({ data: { id: 'fresh-conversation', title: '新对话' } }), { headers: { 'Content-Type': 'application/json' } });
                    }
                    const body = new ReadableStream({
                        start(controller) {
                            controller.enqueue(abandonedEncoder.encode('event: delta\\ndata: {"content":"新会话回答"}\\n\\nevent: done\\ndata: {"message_id":"m3","conversation_title":"新对话"}\\n\\n'));
                            controller.close();
                        },
                    });
                    return new Response(body, { headers: { 'Content-Type': 'text/event-stream' } });
                },
            });
            abandonedInstance.showStart();
            await abandonedInstance.sendQuestion('新会话问题');
            const abandoned = {
                history_aborted: historyAborted,
                users: abandonedRoot.querySelectorAll('.gf-ai-help__message.is-user').length,
                answer: abandonedRoot.querySelector('.gf-ai-help__answer')?.textContent ?? '',
            };
            abandonedWrapper.remove();

            history.replaceState({}, '', `${location.pathname}?conversation=stale-conversation`);
            const staleWrapper = document.createElement('div');
            staleWrapper.className = 'gf-main';
            staleWrapper.style.position = 'fixed';
            staleWrapper.style.left = '-2000px';
            staleWrapper.innerHTML = markup();
            document.body.append(staleWrapper);
            const staleRoot = staleWrapper.firstElementChild;
            const staleInstance = setupAiWorkspace(staleRoot, {
                fetcher: async () => {
                    await new Promise((resolve) => setTimeout(resolve, 25));
                    return new Response(JSON.stringify({ data: { id: 'stale-conversation', title: '旧会话', messages: [{ role: 'assistant', content: '旧回答' }], message_page: {} } }), { headers: { 'Content-Type': 'application/json' } });
                },
            });
            staleInstance.showStart();
            await new Promise((resolve) => setTimeout(resolve, 40));
            const stale = {
                start_visible: !staleRoot.querySelector('[data-ai-start]').hidden,
                thread_hidden: staleRoot.querySelector('[data-ai-thread]').hidden,
                messages: staleRoot.querySelectorAll('.gf-ai-help__message').length,
            };
            staleWrapper.remove();

            history.replaceState({}, '', `${location.pathname}?conversation=history-conversation`);
            const historyWrapper = document.createElement('div');
            historyWrapper.className = 'gf-main';
            historyWrapper.style.position = 'fixed';
            historyWrapper.style.left = '-2000px';
            historyWrapper.innerHTML = markup();
            document.body.append(historyWrapper);
            const historyRoot = historyWrapper.firstElementChild;
            const historyEncoder = new TextEncoder();
            const historyInstance = setupAiWorkspace(historyRoot, {
                fetcher: async (url, options = {}) => {
                    if ((options.method ?? 'GET') === 'GET') {
                        await new Promise((resolve) => setTimeout(resolve, 25));
                        return new Response(JSON.stringify({ data: { id: 'history-conversation', title: '已有会话', messages: [{ role: 'assistant', content: '历史回答' }], message_page: {} } }), { headers: { 'Content-Type': 'application/json' } });
                    }
                    const body = new ReadableStream({
                        start(controller) {
                            controller.enqueue(historyEncoder.encode('event: delta\\ndata: {"content":"新回答"}\\n\\nevent: done\\ndata: {"message_id":"m2","conversation_title":"已有会话"}\\n\\n'));
                            controller.close();
                        },
                    });
                    return new Response(body, { headers: { 'Content-Type': 'text/event-stream' } });
                },
            });
            await historyInstance.sendQuestion('历史加载时发送');
            const historyState = {
                assistants: historyRoot.querySelectorAll('.gf-ai-help__message.is-assistant').length,
                users: historyRoot.querySelectorAll('.gf-ai-help__message.is-user').length,
                first_answer: historyRoot.querySelector('.gf-ai-help__answer')?.textContent ?? '',
            };
            historyWrapper.remove();

            history.replaceState({}, '', `${location.pathname}?conversation=paged-conversation`);
            const pageWrapper = document.createElement('div');
            pageWrapper.className = 'gf-main';
            pageWrapper.style.position = 'fixed';
            pageWrapper.style.left = '-2000px';
            pageWrapper.innerHTML = markup();
            document.body.append(pageWrapper);
            const pageRoot = pageWrapper.firstElementChild;
            const pageInstance = setupAiWorkspace(pageRoot, {
                fetcher: async (url) => {
                    if (String(url).includes('before=')) {
                        await new Promise((resolve) => setTimeout(resolve, 25));
                        throw new Error('旧分页失败');
                    }
                    return new Response(JSON.stringify({ data: { id: 'paged-conversation', title: '分页会话', messages: [{ role: 'assistant', content: '当前回答' }], message_page: { has_more: true, next_cursor: 'cursor-1' } } }), { headers: { 'Content-Type': 'application/json' } });
                },
            });
            for (let attempt = 0; attempt < 20 && pageRoot.querySelector('[data-ai-load-earlier]').hidden; attempt += 1) {
                await new Promise((resolve) => setTimeout(resolve, 5));
            }
            pageRoot.querySelector('[data-ai-load-earlier]').click();
            pageInstance.showStart();
            await new Promise((resolve) => setTimeout(resolve, 40));
            const pagination = {
                start_visible: !pageRoot.querySelector('[data-ai-start]').hidden,
                messages: pageRoot.querySelectorAll('.gf-ai-help__message').length,
                alert: pageRoot.querySelector('[data-ai-alert]').textContent,
                load_disabled: pageRoot.querySelector('[data-ai-load-earlier]').disabled,
            };
            pageWrapper.remove();

            history.replaceState({}, '', `${location.pathname}?conversation=missing-conversation`);
            const failedHistoryWrapper = document.createElement('div');
            failedHistoryWrapper.className = 'gf-main';
            failedHistoryWrapper.style.position = 'fixed';
            failedHistoryWrapper.style.left = '-2000px';
            failedHistoryWrapper.innerHTML = markup();
            document.body.append(failedHistoryWrapper);
            const failedHistoryRoot = failedHistoryWrapper.firstElementChild;
            const failedHistoryEncoder = new TextEncoder();
            let failedHistoryCreateCalls = 0;
            let failedHistoryMessageCalls = 0;
            const failedHistoryInstance = setupAiWorkspace(failedHistoryRoot, {
                fetcher: async (url, options = {}) => {
                    const method = options.method ?? 'GET';
                    if (method === 'GET') {
                        await new Promise((resolve) => setTimeout(resolve, 15));
                        return new Response(JSON.stringify({ message: '会话不存在' }), { status: 404, headers: { 'Content-Type': 'application/json' } });
                    }
                    if (String(url).endsWith('/conversations')) {
                        failedHistoryCreateCalls += 1;
                        return new Response(JSON.stringify({ data: { id: 'recovered-conversation', title: '新对话' } }), { headers: { 'Content-Type': 'application/json' } });
                    }
                    failedHistoryMessageCalls += 1;
                    if (!String(url).includes('recovered-conversation')) {
                        return new Response(JSON.stringify({ message: '失效会话' }), { status: 404, headers: { 'Content-Type': 'application/json' } });
                    }
                    const body = new ReadableStream({
                        start(controller) {
                            controller.enqueue(failedHistoryEncoder.encode('event: delta\\ndata: {"content":"恢复后的回答"}\\n\\nevent: done\\ndata: {"message_id":"m4","conversation_title":"恢复会话"}\\n\\n'));
                            controller.close();
                        },
                    });
                    return new Response(body, { headers: { 'Content-Type': 'text/event-stream' } });
                },
            });
            await failedHistoryInstance.sendQuestion('加载失败时发送');
            await failedHistoryInstance.sendQuestion('恢复后重试');
            const failedHistory = {
                create_calls: failedHistoryCreateCalls,
                message_calls: failedHistoryMessageCalls,
                conversation: new URL(location.href).searchParams.get('conversation'),
                answer: failedHistoryRoot.querySelector('.gf-ai-help__answer')?.textContent ?? '',
            };
            failedHistoryWrapper.remove();

            history.replaceState({}, '', `${location.pathname}?conversation=rename-a`);
            const renameWrapper = document.createElement('div');
            renameWrapper.className = 'gf-main';
            renameWrapper.style.position = 'fixed';
            renameWrapper.style.left = '-2000px';
            renameWrapper.innerHTML = markup();
            document.body.append(renameWrapper);
            const renameRoot = renameWrapper.firstElementChild;
            const renameInstance = setupAiWorkspace(renameRoot, {
                fetcher: async (url, options = {}) => {
                    if (options.method === 'PATCH') {
                        await new Promise((resolve) => setTimeout(resolve, 30));
                        return new Response(JSON.stringify({ data: { id: 'rename-a', title: '旧会话改名' } }), { headers: { 'Content-Type': 'application/json' } });
                    }
                    const id = String(url).includes('rename-b') ? 'rename-b' : 'rename-a';
                    return new Response(JSON.stringify({ data: { id, title: id === 'rename-b' ? '新会话 B' : '旧会话 A', messages: [], message_page: {} } }), { headers: { 'Content-Type': 'application/json' } });
                },
            });
            await new Promise((resolve) => setTimeout(resolve, 10));
            const originalPrompt = window.prompt;
            window.prompt = () => '旧会话改名';
            renameRoot.querySelector('[data-ai-rename]').click();
            await renameInstance.loadConversation('rename-b');
            await new Promise((resolve) => setTimeout(resolve, 40));
            window.prompt = originalPrompt;
            const renameRace = {
                conversation: new URL(location.href).searchParams.get('conversation'),
                title: renameRoot.querySelector('[data-ai-thread-title]').textContent,
            };
            renameWrapper.remove();
            history.replaceState({}, '', location.pathname);

            return { race, failure, abandoned, stale, history: historyState, pagination, failedHistory, renameRace };
        }""",
        {"asset": workspace_asset},
    )
    assert client_race_result["race"] == {
        "create_calls": 1,
        "message_calls": 1,
        "user_messages": 1,
        "copies": 1,
        "errors": 0,
        "stop_hidden": True,
        "stream_cancelled": True,
    }, client_race_result
    assert client_race_result["failure"]["input"] == "保留这个问题", client_race_result
    assert client_race_result["failure"]["error"] == "会话创建失败", client_race_result
    assert not client_race_result["failure"]["generating"], client_race_result
    assert client_race_result["abandoned"]["history_aborted"], client_race_result
    assert client_race_result["abandoned"]["users"] == 1, client_race_result
    assert client_race_result["abandoned"]["answer"].strip() == "新会话回答", client_race_result
    assert client_race_result["stale"] == {"start_visible": True, "thread_hidden": True, "messages": 0}, client_race_result
    assert client_race_result["history"]["assistants"] == 2, client_race_result
    assert client_race_result["history"]["users"] == 1, client_race_result
    assert client_race_result["history"]["first_answer"].strip() == "历史回答", client_race_result
    assert client_race_result["pagination"] == {"start_visible": True, "messages": 0, "alert": "", "load_disabled": False}, client_race_result
    assert {
        **client_race_result["failedHistory"],
        "answer": client_race_result["failedHistory"]["answer"].strip(),
    } == {
        "create_calls": 1,
        "message_calls": 1,
        "conversation": "recovered-conversation",
        "answer": "恢复后的回答",
    }, client_race_result
    assert client_race_result["renameRace"] == {
        "conversation": "rename-b",
        "title": "新会话 B",
    }, client_race_result

    for viewport in (
        {"width": 320, "height": 700},
        {"width": 390, "height": 844},
        {"width": 768, "height": 900},
        {"width": 1440, "height": 1000},
    ):
        page.set_viewport_size(viewport)
        page.goto(f"{base_url}/{admin_path}/ai-workspace")
        page.wait_for_load_state("networkidle")
        if "gf-sidebar-open" in (page.locator("body").get_attribute("class") or ""):
            page.locator("[data-sidebar-close]").first.click()
        page.wait_for_timeout(100)
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), viewport
        viewport_composer = page.locator("[data-ai-form]").bounding_box()
        assert viewport_composer is not None and viewport_composer["width"] <= viewport["width"], viewport

    page.goto(f"{base_url}/{admin_path}/ai-workspace")
    page.wait_for_load_state("networkidle")
    page.set_viewport_size({"width": 375, "height": 812})
    if "gf-sidebar-open" in (page.locator("body").get_attribute("class") or ""):
        page.locator("[data-sidebar-close]").first.click()
    page.wait_for_timeout(150)
    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1")
    assert page.locator(".gf-ai-help__starter-actions [data-ai-suggestion]").count() == 6
    assert page.locator(".gf-ai-help__showcase-visual").first.evaluate("element => getComputedStyle(element).display === 'none'")
    mobile_composer = page.locator("[data-ai-form]").bounding_box()
    assert mobile_composer is not None and mobile_composer["width"] <= 343
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-help-home-mobile.png"), full_page=True)

    prompt.fill("如何查看最近 30 天的数据趋势？")
    prompt.press("Enter")
    mobile_assistant = page.locator(".gf-ai-help__message.is-assistant").last
    mobile_assistant.wait_for(state="visible", timeout=10_000)
    page.wait_for_function(
        "!document.querySelector('.gf-ai-help__message.is-assistant:last-of-type')?.classList.contains('is-pending')",
        timeout=90_000,
    )
    assert page.locator(".gf-ai-help__message.is-user .gf-ai-help__avatar--user").last.is_visible()
    assert page.locator(".gf-ai-help__message.is-user .gf-ai-help__avatar--user").last.inner_text() == user_initial
    assert mobile_assistant.locator(".gf-ai-help__avatar--assistant").is_visible()
    assert mobile_assistant.locator(".gf-ai-help__related > div").evaluate(
        "element => getComputedStyle(element).gridTemplateColumns.split(' ').length === 1"
    )
    mobile_media = mobile_assistant.locator(".gf-ai-help__media figure")
    assert mobile_media.count() == 1
    mobile_message_body_box = mobile_assistant.locator(".gf-ai-help__message-body").bounding_box()
    mobile_media_box = mobile_media.first.bounding_box()
    assert mobile_message_body_box is not None and mobile_media_box is not None
    assert abs(
        mobile_media_box["x"] + mobile_media_box["width"] / 2
        - (mobile_message_body_box["x"] + mobile_message_body_box["width"] / 2)
    ) <= 2
    mobile_media.first.locator("button").click()
    mobile_preview = page.locator(".gf-ai-help__media-dialog")
    mobile_preview.wait_for(state="visible")
    page.wait_for_function(
        "element => element.complete && element.naturalWidth > 0",
        arg=mobile_preview.locator("img").element_handle(),
    )
    mobile_preview_box = mobile_preview.bounding_box()
    assert mobile_preview_box is not None and mobile_preview_box["width"] <= 355
    assert abs(mobile_preview_box["x"] + mobile_preview_box["width"] / 2 - 375 / 2) <= 2
    assert abs(mobile_preview_box["y"] + mobile_preview_box["height"] / 2 - 812 / 2) <= 2
    mobile_zoom_value = mobile_preview.locator(".gf-ai-help__media-dialog-zoom-value")
    assert mobile_zoom_value.inner_text() == "100%"
    mobile_preview.locator(".gf-ai-help__media-dialog-zoom-in").click()
    assert mobile_zoom_value.inner_text() == "125%"
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-help-media-preview-mobile.png"))
    mobile_preview.locator(".gf-ai-help__media-dialog-close").click()
    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1")
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-help-answer-mobile.png"), full_page=True)

    assert console_errors == [], f"Browser console errors: {console_errors}"
    browser.close()
