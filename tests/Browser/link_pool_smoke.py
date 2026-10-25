"""Exercise link-pool import, publishing, public HTML, metrics and withdrawal in an isolated browser.

Run after building assets: python3 tests/Browser/link_pool_smoke.py --output-dir /tmp/geoflow-link-pool-proof
"""
import argparse
import base64
import json
import math
import os
from pathlib import Path
import secrets
import signal
import socket
import sqlite3
import statistics
import subprocess
import tempfile
import time
from urllib.parse import urlparse
from urllib.request import urlopen

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ADMIN = "geo_browser"


def value(database, query):
    with sqlite3.connect(database) as connection:
        return connection.execute(query).fetchone()[0]


def wait_for_value(database, query, expected, timeout=5):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        actual = value(database, query)
        if actual >= expected:
            return actual
        time.sleep(0.1)
    raise AssertionError(f"Expected {query} >= {expected}, got {actual}")


def run_browser(base_url, database, fixture, output, navigation_only=False):
    evidence = {"checks": [], "screenshots": [], "event_requests": [], "page_errors": []}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1280, "height": 720}, locale="zh-CN", service_workers="block",
                                      user_agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36")
        context.route("**/*", lambda route: route.continue_() if urlparse(route.request.url).hostname == "127.0.0.1" else route.abort())
        page = context.new_page()
        page.on("pageerror", lambda error: evidence["page_errors"].append(str(error)))
        context.on("request", lambda request: evidence["event_requests"].append({"url": request.url, "body": request.post_data})
                   if urlparse(request.url).path == "/link-pool/events" else None)
        context.on("response", lambda response: evidence.setdefault("event_statuses", []).append({"url": response.url, "status": response.status})
                   if urlparse(response.url).path == "/link-pool/events" else None)

        def goto(path):
            response = page.goto(base_url + path)
            assert response and response.status == 200, (path, response.status if response else None)
            page.wait_for_load_state("networkidle")

        def screenshot(name):
            path = output / name
            page.screenshot(path=str(path), animations="disabled")
            evidence["screenshots"].append(str(path))

        def screenshot_import_form(name):
            path = output / name
            page.locator("[data-link-pool-import-form]").screenshot(path=str(path), animations="disabled")
            evidence["screenshots"].append(str(path))

        def check(message):
            evidence["checks"].append(message)

        def article_timings():
            durations = []
            for _ in range(40):
                started = time.perf_counter()
                response = page.request.get(base_url + "/article/source")
                assert response.status == 200
                durations.append(round((time.perf_counter() - started) * 1000, 1))
            ordered = sorted(durations)
            return {"samples_ms": durations, "median_ms": round(statistics.median(ordered), 1),
                    "p95_ms": ordered[math.ceil(len(ordered) * .95) - 1]}

        try:
            goto(f"/{ADMIN}/login")
            page.locator('input[name="username"]').fill("browser_link_pool")
            page.locator('input[name="password"]').fill("browser-link-pool-test-only")
            with page.expect_navigation():
                page.locator('button[type="submit"]').click()
            for width, height in ((1920, 1080), (1280, 720), (375, 812), (320, 812)):
                page.set_viewport_size({"width": width, "height": height})
                goto(f"/{ADMIN}/distribution")
                page.wait_for_function("document.documentElement.scrollWidth <= innerWidth", timeout=3000)
                entry = page.locator('[data-distribution-navigation-item="link-pool"]')
                expect(entry).to_be_visible()
                assert entry.get_attribute("href") == f"/{ADMIN}/link-pool"
                bounds = entry.bounding_box()
                assert bounds and bounds["x"] >= 0 and bounds["x"] + bounds["width"] <= width + 1
                expect(page.locator('.gf-sidebar__primary a').filter(has_text="内链投放")).to_have_count(0)
                screenshot(f"navigation-distribution-{width}.png")
                with page.expect_navigation():
                    entry.click()
                page.wait_for_load_state("networkidle")
                expect(page.locator("[data-link-pool-tabs]")).to_be_visible()
                if page.locator(".gf-sidebar__primary").count():
                    expect(page.locator('.gf-sidebar__primary a[aria-current="page"]')).to_have_attribute("href", f"/{ADMIN}/distribution")
                back = page.locator("[data-link-pool-distribution-return]")
                expect(back).to_be_visible()
                screenshot(f"navigation-link-pool-{width}.png")
                with page.expect_navigation():
                    back.click()
                expect(page.locator("[data-default-site-management]")).to_be_visible()
                assert page.url == base_url + f"/{ADMIN}/distribution"
                check(f"Distribution entry and return path work at {width}px without horizontal overflow")
            assert not evidence["page_errors"], evidence["page_errors"]
            if navigation_only:
                return evidence
            page.set_viewport_size({"width": 1280, "height": 720})
            goto(f"/{ADMIN}/link-pool/settings?site=primary")
            expect(page.locator("#site-context-title")).to_be_visible()
            expect(page.locator("[data-adaptation-start]")).to_be_enabled()
            screenshot("00-settings-desktop.png")
            for width in (375, 320):
                page.set_viewport_size({"width": width, "height": 812})
                evidence.setdefault("responsive_layout", []).append(page.evaluate("""() => ({width:innerWidth, scroll:document.documentElement.scrollWidth, animations:document.getAnimations().map(a => ({target:a.effect?.target?.className, timing:a.effect?.getTiming()}))})"""))
                page.wait_for_function("document.documentElement.scrollWidth <= innerWidth", timeout=3000)
                assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), page.evaluate("""() => ({width: innerWidth, scroll: document.documentElement.scrollWidth, overflow: [...document.querySelectorAll('body *')].map(el => ({tag:el.tagName, cls:el.className, right:el.getBoundingClientRect().right, width:el.getBoundingClientRect().width})).filter(el => el.width && el.right > innerWidth + 1).slice(0, 20)})""")
                screenshot(f"00-settings-{width}.png")
            page.set_viewport_size({"width": 1280, "height": 720})
            check("Site settings status card and controls fit desktop and narrow mobile viewports")
            page.locator('input[name="heading"]').fill("为你推荐")
            page.locator('input[name="default_limit"]').fill("2")
            page.locator('input[name="rotation_hours"]').fill("12")
            with page.expect_navigation():
                page.locator("[data-adaptation-start]").click()
            page.wait_for_load_state("networkidle")
            expect(page.locator("[data-adaptation-actions]")).to_be_visible(timeout=15000)
            assert value(database, "select count(*) from link_pool_theme_adaptations where status = 'completed'") == 1
            assert value(database, "select revision_id from site_theme_bindings where site_key = 'primary'") != fixture["base_revision_id"]
            expect(page.locator('input[name="heading"]')).to_have_value("为你推荐")
            expect(page.locator('input[name="default_limit"]')).to_have_value("2")
            expect(page.locator('input[name="rotation_hours"]')).to_have_value("12")
            expect(page.locator('input[type="checkbox"][name="enabled"]')).to_be_checked()
            assert value(database, "select json_extract(setting_value, '$.heading') from site_settings where setting_key = 'article_recommendations'") == "为你推荐"
            page.get_by_role("link", name="投放活动", exact=True).click()
            expect(page.get_by_role("heading", name="投放活动", exact=True)).to_be_visible()
            goto(f"/{ADMIN}/link-pool/settings?site=primary")
            screenshot("00-settings-adapted.png")
            check("One-click adaptation saves edited heading, limit and rotation; completion reloads without a dirty-form trap")
            check("One click creates, validates and publishes a theme version and enables recommendations")
            goto("/article/source")
            expect(page.locator("[data-link-pool]")).to_have_count(0)
            check("Empty target pool renders no recommendation module")
            evidence["http_timings_ms"] = {"empty_pool": article_timings()}

            goto(f"/{ADMIN}/link-pool/import")
            expect(page.locator("[data-link-pool-upload-zone]")).to_be_visible()
            assert page.evaluate("getComputedStyle(document.querySelector('.link-pool-site-context')).backgroundColor") == "rgb(239, 246, 255)"
            screenshot("00-import-desktop.png")
            screenshot_import_form("00-import-form-desktop.png")
            page.locator('input[name="file"]').set_input_files({
                "name": "links.csv", "mimeType": "text/csv",
                "buffer": b"url,title\nhttps://127.0.0.1/article/target,Target browser article",
            })
            expect(page.locator("[data-link-pool-file-name]")).to_have_text("links.csv")
            expect(page.locator("[data-link-pool-selected-file]")).to_be_visible()
            screenshot("00-import-file-selected.png")
            page.locator("[data-link-pool-file-clear]").click()
            expect(page.locator("[data-link-pool-selected-file]")).to_be_hidden()
            page.evaluate("""() => {
                const data = new DataTransfer();
                data.items.add(new File(['url,title\\nhttps://127.0.0.1/article/target,Target browser article'], 'dropped.csv', {type: 'text/csv'}));
                document.querySelector('[data-link-pool-upload-zone]').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
            }""")
            expect(page.locator("[data-link-pool-file-name]")).to_have_text("dropped.csv")
            page.locator("[data-link-pool-file-clear]").click()
            page.locator('input[name="file"]').set_input_files({
                "name": "invalid.png", "mimeType": "image/png", "buffer": b"invalid",
            })
            expect(page.locator("[data-link-pool-file-error]")).to_have_text("请选择 .csv 或 .txt 文件。")
            screenshot_import_form("00-import-invalid-file.png")
            page.locator('input[name="file"]').set_input_files({
                "name": "links.csv", "mimeType": "text/csv", "buffer": b"url,title",
            })
            page.locator("[data-link-pool-file-clear]").click()
            page.evaluate("document.activeElement?.blur()")
            page.set_viewport_size({"width": 375, "height": 812})
            page.wait_for_function("!document.querySelector('.gf-shell__body') || Math.abs(document.querySelector('.gf-shell__body').getBoundingClientRect().left) < 1")
            assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), "Import page overflows at 375px"
            site_context = page.locator(".link-pool-site-context").bounding_box()
            assert site_context and site_context["x"] >= 0 and site_context["x"] + site_context["width"] <= 375
            screenshot("00-import-mobile.png")
            screenshot_import_form("00-import-form-mobile.png")
            context_path = output / "00-import-site-context-mobile.png"
            page.locator(".link-pool-site-context").screenshot(path=str(context_path), animations="disabled")
            evidence["screenshots"].append(str(context_path))
            page.set_viewport_size({"width": 375, "height": 1900})
            page.evaluate("document.querySelector('.gf-main')?.scrollTo(0, 0); window.scrollTo(0, 0)")
            screenshot("00-import-mobile-tall.png")
            page.set_viewport_size({"width": 1280, "height": 720})
            with page.expect_navigation():
                page.locator("#link-pool-site").select_option("primary")
            expect(page.locator("#link-pool-site")).to_have_value("primary")
            assert "/link-pool/import?site=primary" in page.url
            assert page.locator('[data-link-pool-import-form]').get_attribute("action").endswith("/link-pool/import/preflight?site=primary")
            check("Import page shows file selection, removal, file errors, source context and mobile layout")
            page.locator('input[name="file"]').set_input_files({
                "name": "upload.csv", "mimeType": "text/csv",
                "buffer": b"url,title\nhttps://127.0.0.1/article/target,Target browser article\njavascript:alert(1),Invalid",
            })
            with page.expect_navigation():
                page.get_by_role("button", name="逐行预检").click()
            expect(page.get_by_text("预检结果 · 2 行")).to_be_visible()
            assert page.locator("#link-pool-site").input_value() == "primary"
            check("Selected CSV file reaches the server-side row preflight")
            goto(f"/{ADMIN}/link-pool/import?site=primary")
            page.locator('textarea[name="links"]').fill(
                "url,title\nhttps://127.0.0.1/article/target,Target browser article\njavascript:alert(1),Invalid"
            )
            with page.expect_navigation():
                page.get_by_role("button", name="逐行预检").click()
            expect(page.get_by_text("预检结果 · 2 行")).to_be_visible()
            expect(page.get_by_text("可新增 1 · 可更新 0 · 重复 0 · 错误 1")).to_be_visible()
            assert page.locator("#link-pool-site").input_value() == "primary"
            assert page.locator(".link-pool-site-context").get_attribute("action").endswith("/link-pool/import")
            screenshot("01-import-preflight.png")
            with page.expect_navigation():
                page.get_by_role("button", name="确认导入有效行").click()
            expect(page.get_by_text("导入完成：新增 1，更新 0，重复 0，错误 1。")).to_be_visible()
            assert value(database, "SELECT COUNT(*) FROM link_pool_targets") == 1
            check("Admin import preflight rejects an invalid URL and saves the valid target")

            goto(f"/{ADMIN}/link-pool/import?site=primary")
            custom_csv = "https://127.0.0.1/article/target,Custom target title"
            page.locator('textarea[name="links"]').fill(custom_csv)
            with page.expect_navigation():
                page.get_by_role("button", name="逐行预检").click()
            with page.expect_navigation():
                page.get_by_role("button", name="确认导入有效行").click()
            assert "/link-pool/import?site=primary" in page.url and "/preflight" not in page.url
            expect(page.locator('#link-pool-import-links')).to_have_value(custom_csv)
            expect(page.get_by_text("请核对并确认与文章标题不同的展示标题。")).to_be_visible()
            screenshot("10-import-validation-recovery.png")
            page.locator('input[name="use_article_title_lines[]"]').check()
            with page.expect_navigation():
                page.get_by_role("button", name="确认导入有效行").click()
            expect(page.get_by_text("导入完成：新增 0，更新 1，重复 0，错误 0。")).to_be_visible()
            assert page.get_by_role("link", name="配置投放活动").get_attribute("href").endswith("?site=primary")
            check("Import title validation returns an editable GET page with CSV and preflight preserved; retry succeeds")

            goto(f"/{ADMIN}/link-pool/settings?site=primary")
            page.locator('input[name="heading"]').fill("保留推荐标题")
            page.locator('input[name="default_limit"]').fill("5")
            page.locator('input[name="rotation_hours"]').fill("36")
            page.locator('input[name="revision"]').evaluate("el => el.value = 999")
            with page.expect_navigation():
                page.get_by_role("button", name="保存站点设置").click()
            expect(page.locator('input[name="heading"]')).to_have_value("保留推荐标题")
            expect(page.locator('input[name="default_limit"]')).to_have_value("5")
            expect(page.locator('input[name="rotation_hours"]')).to_have_value("36")
            expect(page.get_by_role("link", name="载入最新设置")).to_be_visible()
            screenshot("11-settings-conflict-preserved.png")
            check("Settings revision conflict preserves edited values and offers the current settings")

            goto(f"/{ADMIN}/link-pool/campaigns/create?site=primary")
            page.locator('input[name="name"]').fill("Browser manual recommendations")
            page.locator('input[name="target_ids[]"]').check()
            page.locator('input[name="pinned_ids[]"]').check()
            page.locator('input[name="target_q"]').fill("NoMatchingTarget123")
            page.get_by_role("button", name="查找目标", exact=True).click()
            expect(page.locator('[data-link-pool-target-search-status]')).to_have_text("目标列表已更新，当前选择和活动设置已保留。")
            expect(page.locator('input[name="target_ids[]"]')).to_be_checked()
            expect(page.locator('input[name="pinned_ids[]"]')).to_be_checked()
            expect(page.locator('input[name="name"]')).to_have_value("Browser manual recommendations")
            page.route("**/link-pool/campaigns/create?*", lambda route: route.fulfill(status=503, body="Temporary unavailable", content_type="text/plain"))
            page.get_by_role("button", name="查找目标", exact=True).click()
            expect(page.locator('[data-link-pool-target-search-status]')).to_have_text("暂时无法查询目标，请重试。当前编辑内容已保留。")
            expect(page.locator('input[name="target_ids[]"]')).to_be_checked()
            expect(page.locator('input[name="name"]')).to_have_value("Browser manual recommendations")
            page.unroute("**/link-pool/campaigns/create?*")
            check("Target lookup and temporary search failure preserve unsaved campaign fields, selected links and pins")
            page.locator('select[name="scope"]').select_option("category")
            expect(page.locator('select[name="category_id"]')).to_be_visible()
            assert "Source browser article category" in page.locator('select[name="category_id"]').inner_text()
            page.locator('select[name="scope"]').select_option("articles")
            source_choice = page.get_by_role("checkbox", name="投放 · Source browser article", exact=True)
            exclusion_choice = page.get_by_role("checkbox", name="排除 · Source browser article", exact=True)
            source_choice.check()
            exclusion_choice.check()
            expect(source_choice).not_to_be_checked()
            source_choice.check()
            expect(exclusion_choice).not_to_be_checked()
            page.locator('input[name="source_q"]').fill("Target")
            page.get_by_role("button", name="查找来源文章", exact=True).click()
            expect(page.locator('[data-link-pool-source-search-status]')).to_have_text("来源文章列表已更新，当前选择和活动设置已保留。")
            expect(source_choice).to_be_checked()
            expect(page.locator('input[name="name"]')).to_have_value("Browser manual recommendations")
            for width in (1280, 375, 320):
                page.set_viewport_size({"width": width, "height": 812})
                page.wait_for_function("document.documentElement.scrollWidth <= innerWidth", timeout=3000)
                page.locator('[data-source-chooser]').scroll_into_view_if_needed()
                screenshot(f"14-campaign-source-{width}.png")
            page.set_viewport_size({"width": 1280, "height": 720})
            check("Category and source article choosers use names, preserve unsaved selections during search and fit desktop/mobile")
            page.locator('select[name="scope"]').select_option("keyword")
            with page.expect_navigation():
                page.get_by_role("button", name="保存草稿").click()
            expect(page.locator('input[name="target_ids[]"]')).to_be_checked()
            expect(page.locator('input[name="pinned_ids[]"]')).to_be_checked()
            expect(page.locator('input[name="name"]')).to_have_value("Browser manual recommendations")
            screenshot("12-campaign-validation-preserved.png")
            page.locator('select[name="scope"]').select_option("all")
            page.locator('input[name="pinned_ids[]"]').uncheck()
            check("Campaign validation preserves target, pin and basic fields for a direct correction")
            page.locator('input[name="target_ids[]"]').check()
            with page.expect_navigation():
                page.get_by_role("button", name="保存草稿").click()
            expect(page.get_by_text("草稿已保存")).to_be_visible()
            page.locator('input[name="target_ids[]"]').uncheck()
            page.locator('input[name="target_q"]').fill("Target browser article")
            page.get_by_role("button", name="查找目标", exact=True).click()
            expect(page.locator('[data-link-pool-target-search-status]')).to_have_text("目标列表已更新，当前选择和活动设置已保留。")
            expect(page.locator('input[name="target_ids[]"]')).not_to_be_checked()
            page.locator('input[name="target_ids[]"]').check()
            check("Searching an existing draft preserves a target's unsaved deselection")
            page.get_by_role("button", name="发布活动").click()
            expect(page.locator("[data-admin-action-dialog]")).to_be_visible()
            with page.expect_navigation():
                page.locator("[data-admin-action-confirm]").click()
            assert value(database, "SELECT status FROM link_campaigns LIMIT 1") == "active"
            check("Admin draft and publish actions produce an active campaign")
            with page.expect_navigation():
                page.get_by_role("link", name="预览文章").click()
            expect(page.locator('select[name="article_id"]')).not_to_have_value("")
            expect(page.get_by_role("heading", name="Source browser article")).to_be_visible()
            expect(page.get_by_role("link", name="返回活动，继续配置", exact=False)).to_be_visible()
            assert "Browser manual recommendations" in page.locator('select[name="campaign_id"]').inner_text()
            preview_url = page.url
            page.locator('input[name="article_q"]').fill("NoSuchArticle123456")
            with page.expect_navigation():
                page.get_by_role("button", name="查找文章", exact=True).click()
            expect(page.get_by_text("未找到该标题的文章，请调整搜索词。")).to_be_visible()
            expect(page.locator('select[name="article_id"]')).to_have_value("")
            check("Title search with no matching article shows an empty result and a clear correction")
            page.goto(preview_url)
            page.wait_for_load_state("networkidle")
            assert value(database, "SELECT COUNT(*) FROM link_metrics_daily") == 0
            screenshot("13-preview-auto-desktop.png")
            for width in (375, 320):
                page.set_viewport_size({"width": width, "height": 812})
                page.wait_for_function("document.documentElement.scrollWidth <= innerWidth", timeout=3000)
                page.wait_for_function("!document.querySelector('.gf-shell__body') || Math.abs(document.querySelector('.gf-shell__body').getBoundingClientRect().left) < 1")
                preview_select = page.locator('select[name="article_id"]').bounding_box()
                assert preview_select and preview_select["width"] >= 200, page.evaluate("""() => { const select = document.querySelector('select[name="article_id"]'); return {viewport:innerWidth, elements:[select,select.parentElement,select.form].map(el => ({tag:el.tagName,cls:el.className, rect:el.getBoundingClientRect().toJSON(),display:getComputedStyle(el).display,grid:getComputedStyle(el).gridTemplateColumns, span:getComputedStyle(el).gridColumn,width:getComputedStyle(el).width,min:getComputedStyle(el).minWidth}))}; }""")
                screenshot(f"13-preview-auto-{width}.png")
            page.set_viewport_size({"width": 1280, "height": 720})
            check("Campaign preview automatically selects a matching article and leaves delivery metrics unchanged")
            evidence["http_timings_ms"]["active_pool"] = article_timings()

            source = page.request.get(base_url + "/article/source")
            assert source.status == 200 and 'href="https://127.0.0.1/article/target"' in source.text()
            check("Real HTTP article response contains a crawlable canonical anchor")
            evidence["article_cache_control"] = source.headers.get("cache-control")
            evidence["html_served_after_get"] = value(database, "SELECT COALESCE(SUM(html_served), 0) FROM link_metrics_daily")
            evidence["html_delivery_rows"] = value(database, "SELECT COUNT(*) FROM link_pool_html_deliveries")
            assert evidence["html_served_after_get"] >= 1, evidence

            nojs = browser.new_context(viewport={"width": 1280, "height": 720}, java_script_enabled=False,
                                       storage_state=context.storage_state())
            nojs.route("**/*", lambda route: route.continue_() if urlparse(route.request.url).hostname == "127.0.0.1" else route.abort())
            nojs_page = nojs.new_page()
            nojs_page.goto(base_url + "/article/source")
            expect(nojs_page.locator('.link-pool__link[href="https://127.0.0.1/article/target"]')).to_have_count(1)
            nojs_page.screenshot(path=str(output / "02-no-js-desktop.png"), full_page=True)
            evidence["screenshots"].append(str(output / "02-no-js-desktop.png"))
            assert nojs_page.evaluate("document.documentElement.scrollWidth <= innerWidth")
            desktop_block = nojs_page.locator("[data-link-pool]").bounding_box()
            assert desktop_block and desktop_block["x"] >= 45 and desktop_block["width"] <= 1180
            check("JavaScript-disabled 1280px page contains the real link, aligned to content width without overflow")
            nojs_page.goto(base_url + f"/{ADMIN}/link-pool/import?site=primary")
            expect(nojs_page.locator('input[name="file"]')).to_be_visible()
            check("JavaScript-disabled import keeps the native file picker available")
            nojs.close()

            goto("/article/source")
            card = page.locator("[data-link-pool] [data-placement-id]")
            card.scroll_into_view_if_needed()
            expect(card).to_be_visible()
            page.wait_for_timeout(1300)
            wait_for_value(database, "SELECT COALESCE(SUM(viewable), 0) FROM link_metrics_daily", 1)
            check("One second of at least 50% visibility sends an accepted viewable event")
            page.evaluate("document.querySelector('.link-pool__link').addEventListener('click', event => event.preventDefault())")
            page.locator(".link-pool__link").click()
            wait_for_value(database, "SELECT COALESCE(SUM(human_clicks), 0) FROM link_metrics_daily", 1)
            assert value(database, "SELECT COALESCE(SUM(human_clicks), 0) FROM link_metrics_daily") == 1
            assert len(evidence["event_requests"]) >= 2
            check("A real browser click sends one accepted human click event")

            goto(f"/{ADMIN}/link-pool/dashboard?site=primary")
            expect(page.get_by_text("按日趋势图")).to_be_visible()
            screenshot("03-dashboard-desktop.png")
            check("Dashboard loads measured delivery, visibility and click data")

            mobile = browser.new_context(viewport={"width": 375, "height": 812}, java_script_enabled=False)
            mobile.route("**/*", lambda route: route.continue_() if urlparse(route.request.url).hostname == "127.0.0.1" else route.abort())
            mobile_page = mobile.new_page()
            mobile_page.goto(base_url + "/article/source")
            expect(mobile_page.locator(".link-pool__link")).to_have_count(1)
            assert mobile_page.evaluate("document.documentElement.scrollWidth <= innerWidth")
            mobile_block = mobile_page.locator("[data-link-pool]").bounding_box()
            assert mobile_block and mobile_block["x"] >= 12 and mobile_block["width"] <= 350
            mobile_page.screenshot(path=str(output / "04-no-js-mobile.png"), full_page=True)
            evidence["screenshots"].append(str(output / "04-no-js-mobile.png"))
            check("JavaScript-disabled 375px page contains the link and has no horizontal overflow")
            mobile.close()

            goto(f"/{ADMIN}/link-pool/targets")
            page.locator('[data-link-list="targets"] details summary').first.click()
            form = page.locator('[data-link-list="targets"] form[method="POST"]').first
            form.locator('select[name="status"]').select_option("paused")
            with page.expect_navigation():
                form.get_by_role("button").click()
            goto("/article/source")
            expect(page.locator("[data-link-pool]")).to_have_count(0)
            check("Pausing the target removes it from the next public article response")
            long_campaign_name = "BrowserManualRecommendationsForQuarterlyPartnerCampaign" * 2
            long_site_name = "BrowserHostedSiteForQuarterlyPartnerCampaign" * 2
            with sqlite3.connect(database) as connection:
                updated = connection.execute("UPDATE link_campaigns SET name = ? WHERE name = ?",
                                             (long_campaign_name, "Browser manual recommendations"))
                assert updated.rowcount == 1
                original_site_name = connection.execute("SELECT name FROM distribution_channels WHERE id = ?",
                                                        (fixture["hosted_channel_id"],)).fetchone()[0]
                connection.execute("UPDATE distribution_channels SET name = ? WHERE id = ?",
                                   (long_site_name, fixture["hosted_channel_id"]))
            for width in (375, 320):
                page.set_viewport_size({"width": width, "height": 812})
                page.wait_for_function("!document.querySelector('.gf-shell__body') || Math.abs(document.querySelector('.gf-shell__body').getBoundingClientRect().left) < 1")
                goto(f"/{ADMIN}/link-pool/dashboard?site=primary")
                expect(page.locator('select[name="campaign_id"] option').filter(has_text=long_campaign_name)).to_have_count(1)
                expect(page.locator('select[name="target_site"] option').filter(has_text=long_site_name)).to_have_count(1)
                assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), page.evaluate("""() => ({
                    width: innerWidth, scroll: document.documentElement.scrollWidth,
                    campaignSelect: document.querySelector('select[name="campaign_id"]').getBoundingClientRect().toJSON()
                })""")
                assert page.evaluate("""() => {
                    const nav = document.querySelector('[data-link-pool-tabs]');
                    const tab = nav?.querySelector('[aria-current="page"]');
                    if (!tab) return false;
                    const a = tab.getBoundingClientRect(), n = nav.getBoundingClientRect();
                    return a.left >= n.left - 1 && a.right <= n.right + 1;
                }"""), f"Active dashboard tab is clipped at {width}px"
                screenshot(f"05-dashboard-{width}.png")
                check(f"Admin dashboard with long campaign and site names fits a {width}px isolated viewport")
                goto(f"/{ADMIN}/link-pool/targets?site=primary")
                expect(page.locator('select[name="target_site"] option').filter(has_text=long_site_name)).to_have_count(1)
                assert page.evaluate("document.documentElement.scrollWidth <= innerWidth"), f"Target filters overflow at {width}px"
                screenshot(f"05-targets-{width}.png")
                check(f"Target filters with a long site name fit a {width}px isolated viewport")
            with sqlite3.connect(database) as connection:
                connection.execute("UPDATE distribution_channels SET name = ? WHERE id = ?",
                                   (original_site_name, fixture["hosted_channel_id"]))
            page.set_viewport_size({"width": 1280, "height": 720})
            goto(f"/{ADMIN}/link-pool/settings?site=primary")
            page.locator('input[name="heading"]').fill("撤回时放弃的未保存标题")
            page.locator("[data-adaptation-rollback]").click()
            with page.expect_navigation():
                page.get_by_role("button", name="撤回适配", exact=True).click()
            page.wait_for_load_state("networkidle")
            expect(page.locator("[data-adaptation-start]")).to_be_visible(timeout=10000)
            assert value(database, "select revision_id from site_theme_bindings where site_key = 'primary'") == fixture["base_revision_id"]
            assert value(database, "select count(*) from link_pool_theme_adaptations where status = 'rolled_back'") == 1
            saved_heading = page.locator('input[name="heading"]').input_value()
            page.locator('input[name="heading"]').fill("临时编辑")
            page.locator('input[name="heading"]').fill(saved_heading)
            with page.expect_navigation():
                page.locator("[data-adaptation-start]").click()
            page.wait_for_load_state("networkidle")
            expect(page.locator("[data-adaptation-actions]")).to_be_visible(timeout=15000)
            assert value(database, "select count(*) from link_pool_theme_adaptations") == 2
            check("Rollback clears unsaved form edits; editing then reverting a setting does not block the next one-click adaptation")
            goto(f"/{ADMIN}/link-pool/settings?site={fixture['hosted_site_key']}")
            expect(page.locator("#site-context-title")).to_have_text("浏览器托管站")
            page.locator('input[name="heading"]').fill("托管站精选推荐")
            page.locator('input[name="default_limit"]').fill("3")
            with page.expect_navigation():
                page.locator("[data-adaptation-start]").click()
            page.wait_for_load_state("networkidle")
            expect(page.locator("[data-adaptation-actions]")).to_be_visible(timeout=15000)
            expect(page.get_by_text("主题样例已通过检查。发布首篇公开文章后，即可在文章页展示推荐内容。")).to_be_visible()
            expect(page.locator('input[name="heading"]')).to_have_value("托管站精选推荐")
            expect(page.locator('input[name="default_limit"]')).to_have_value("3")
            check("Hosted one-click adaptation preserves edited recommendation settings and enables the correct site")
            hosted_revision = value(database, f"select revision_id from site_theme_bindings where site_key = '{fixture['hosted_site_key']}'")
            response = page.request.get(base_url + "/", headers={"Host": "adaptation.sites.test"})
            assert response.status == 200 and response.headers["x-geoflow-theme-revision"] == hosted_revision
            asset = page.request.get(base_url + f"/theme-assets/{hosted_revision}/theme.css", headers={"Host": "adaptation.sites.test"})
            assert asset.status == 200 and asset.headers["content-type"].startswith("text/css") and "color" in asset.text()
            unauthorized = page.request.get(base_url + f"/theme-assets/{hosted_revision}/theme.css")
            assert unauthorized.status == 404
            check("Hosted revision CSS returns HTTP 200 through the public whitelist; another site cannot read it")
            settings = json.loads(value(database, f"select site_settings from distribution_channels where id = {fixture['hosted_channel_id']}"))
            assert settings["article_recommendations"]["enabled"] is True
            screenshot("06-hosted-settings-adapted.png")
            page.locator("[data-adaptation-rollback]").click()
            with page.expect_navigation():
                page.get_by_role("button", name="撤回适配", exact=True).click()
            page.wait_for_load_state("networkidle")
            response = page.request.get(base_url + "/", headers={"Host": "adaptation.sites.test"})
            assert response.status == 200 and response.headers["x-geoflow-theme-revision"] == fixture["hosted_base_revision_id"]
            check("Hosted site with no public articles adapts through real HTTP readback and rollback restores its baseline")
            goto(f"/{ADMIN}/link-pool/import?site=primary")
            page.locator('textarea[name="links"]').fill(
                "url\ttitle\ttopic\nhttps://www.aichafen.com/\t爱查分\t教育\nhttps://example.com/product?q=1&lang=zh#spec\t产品 & 详情\t教育"
            )
            with page.expect_navigation():
                page.get_by_role("button", name="逐行预检").click()
            expect(page.get_by_text("可新增 2 · 可更新 0 · 重复 0 · 错误 0")).to_be_visible()
            screenshot("07-external-import-preflight.png")
            with page.expect_navigation():
                page.get_by_role("button", name="确认导入有效行").click()
            assert value(database, "select count(*) from link_pool_targets where target_type = 'external'") == 2
            goto(f"/{ADMIN}/link-pool/targets?target_site=external")
            expect(page.get_by_text("可以访问（HTTP 200）")).to_be_visible()
            expect(page.get_by_text("网站限制自动检查（HTTP 403），可人工打开核对。")).to_be_visible()
            screenshot("08-external-link-library.png")
            check("The exact aichafen homepage and a query/fragment URL import without authorization and show background health results")
            goto(f"/{ADMIN}/link-pool/campaigns/create?site=primary")
            page.locator('input[name="name"]').fill("Browser external recommendations")
            for checkbox in page.locator('input[name="target_ids[]"]').all():
                checkbox.check()
            assert page.locator('input[name="approved_cross_site_ids[]"]').count() == 0
            with page.expect_navigation():
                page.get_by_role("button", name="保存草稿").click()
            page.get_by_role("button", name="发布活动").click()
            expect(page.locator("[data-admin-action-dialog]")).to_be_visible()
            with page.expect_navigation():
                page.locator("[data-admin-action-confirm]").click()
            source = page.request.get(base_url + "/article/source")
            assert 'href="https://www.aichafen.com/"' in source.text()
            assert 'href="https://example.com/product?q=1&amp;lang=zh#spec"' in source.text()
            assert value(database, "select count(distinct target_id) from link_metrics_daily where target_site_key = 'external'") == 2
            external_nojs = browser.new_context(viewport={"width": 375, "height": 812}, java_script_enabled=False)
            external_nojs_page = external_nojs.new_page()
            external_nojs_page.goto(base_url + "/article/source")
            expect(external_nojs_page.locator('.link-pool__link[href="https://www.aichafen.com/"]')).to_have_count(1)
            expect(external_nojs_page.locator('.link-pool__link')).to_have_count(2)
            assert external_nojs_page.evaluate("document.documentElement.scrollWidth <= innerWidth")
            external_nojs_page.screenshot(path=str(output / "09-external-no-js-mobile.png"), full_page=True)
            evidence["screenshots"].append(str(output / "09-external-no-js-mobile.png"))
            external_nojs.close()
            check("Two external URLs publish without additional review, remain real anchors without JS, and retain separate metrics")
            goto("/article/source")
            page.locator('.link-pool__link[href="https://www.aichafen.com/"]').scroll_into_view_if_needed()
            page.evaluate("document.querySelector('.link-pool__link[href=\"https://www.aichafen.com/\"]').addEventListener('click', event => event.preventDefault())")
            page.locator('.link-pool__link[href="https://www.aichafen.com/"]').click()
            wait_for_value(database, "select coalesce(sum(human_clicks), 0) from link_metrics_daily where target_site_key = 'external'", 1)
            goto(f"/{ADMIN}/link-pool/dashboard?site=primary&target_site=external")
            expect(page.get_by_text("GEOFlow 无法采集外部网站的访问日志")).to_be_visible()
            check("An external-link browser click is recorded and the dashboard explains external access-log scope")
            with sqlite3.connect(database) as connection:
                cross_id = connection.execute("""INSERT INTO link_pool_targets
                    (target_site_key, article_id, input_url, input_title, display_title, status, created_at, updated_at)
                    VALUES (?, (SELECT MIN(id) FROM articles), ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)""",
                    (fixture['hosted_site_key'], 'https://adaptation.sites.test/article/source',
                     'Clearable cross-site target', 'Clearable cross-site target')).lastrowid
                external_id = connection.execute("SELECT MIN(id) FROM link_pool_targets WHERE target_type = 'external'").fetchone()[0]
            goto(f"/{ADMIN}/link-pool/campaigns/create?site=primary")
            page.locator('input[name="name"]').fill("Browser reason preservation")
            page.locator(f'input[name="target_ids[]"][value="{external_id}"]').check()
            reason = page.locator(f'input[name="review_reasons[{cross_id}]"]')
            reason.fill("Prior saved reason")
            with page.expect_navigation():
                page.get_by_role("button", name="保存草稿").click()
            expect(reason).to_have_value("Prior saved reason")
            reason.fill("")
            for query in ("NoMatchingTarget123", "Clearable cross-site target"):
                page.locator('input[name="target_q"]').fill(query)
                page.get_by_role("button", name="查找目标", exact=True).click()
                expect(page.locator('[data-link-pool-target-search-status]')).to_have_text("目标列表已更新，当前选择和活动设置已保留。")
                expect(reason).to_have_value("")
            expect(page.locator(f'input[name="target_ids[]"][value="{cross_id}"]')).not_to_be_checked()
            check("Clearing a saved reason for an unselected target survives searching away and back")
            goto(f"/{ADMIN}/link-pool/campaigns?site=primary")
            campaign_row = page.get_by_role("row").filter(has_text="Browser external recommendations")
            campaign_row.get_by_role("link", name="编辑", exact=True).click()
            page.locator('input[name="name"]').fill("External recommendations after pause")
            page.locator('input[name="limit"]').fill("2")
            with page.expect_navigation():
                page.get_by_role("button", name="保存草稿", exact=True).click()
            page.get_by_role("link", name="← 返回活动列表", exact=True).click()
            campaign_row.get_by_role("button", name="暂停", exact=True).click()
            with page.expect_navigation():
                page.get_by_role("button", name="确认暂停", exact=True).click()
            campaign_row.get_by_role("link", name="恢复投放", exact=True).click()
            expect(page.locator('input[name="name"]')).to_have_value("External recommendations after pause")
            expect(page.locator('input[name="limit"]')).to_have_value("2")
            page.get_by_role("button", name="发布活动", exact=True).click()
            with page.expect_navigation():
                page.locator("[data-admin-action-confirm]").click()
            expect(page.get_by_text("活动已发布，文章请求将刷新推荐批次。")).to_be_visible()
            assert value(database, "select status from link_campaigns where name = 'External recommendations after pause'") == "active"
            screenshot("15-campaign-resumed.png")
            check("Pausing an active campaign with a pending draft preserves its edits and resumes without a revision conflict")
            page.get_by_role("link", name="预览文章", exact=True).click()
            page.locator('select[name="version"]').select_option("draft")
            with page.expect_navigation():
                page.get_by_role("button", name="查看预览", exact=True).click()
            expect(page.get_by_text("此活动没有待发布草稿，请返回活动编辑，或切换到当前公开配置。")).to_be_visible()
            expect(page.locator("[data-link-pool]")).to_have_count(0)
            check("A published campaign's missing draft is explained and cannot be mistaken for live recommendations")
            goto(f"/{ADMIN}/link-pool/import?site=primary")
            ambiguous_links = "https://example.com/path,a\t表格标题"
            page.locator('textarea[name="links"]').fill(ambiguous_links)
            with page.expect_navigation():
                page.get_by_role("button", name="逐行预检", exact=True).click()
            expect(page.locator('textarea[name="links"]')).to_have_value(ambiguous_links)
            with page.expect_navigation():
                page.get_by_role("button", name="按表格重新预检", exact=True).click()
            expect(page.get_by_text("可新增 1 · 可更新 0 · 重复 0 · 错误 0")).to_be_visible()
            screenshot("16-import-format-retry.png")
            with page.expect_navigation():
                page.get_by_role("button", name="确认导入有效行", exact=True).click()
            assert value(database, "select count(*) from link_pool_targets where input_url = 'https://example.com/path,a' and input_title = '表格标题'") == 1
            check("An ambiguous spreadsheet row retains its input, retries in one click and imports the exact URL without truncation")
            with sqlite3.connect(database) as connection:
                connection.execute("UPDATE articles SET status = 'private' WHERE id = ?", (fixture['article_ids']['source'],))
                connection.execute("UPDATE tasks SET publish_scope = 'distribution_only' WHERE id = (SELECT task_id FROM articles WHERE id = ?)", (fixture['article_ids']['source'],))
                connection.execute("""INSERT INTO hosted_site_article_assignments
                    (article_id, hosted_site_profile_id, status, content_fingerprint, capacity_date, assigned_at, published_at, created_at, updated_at)
                    VALUES (?, ?, 'published', ?, DATE('now'), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)""",
                    (fixture['article_ids']['source'], int(fixture['hosted_site_key'].split(':')[1]), 'b' * 64))
            goto(f"/{ADMIN}/link-pool/campaigns/create?site={fixture['hosted_site_key']}")
            page.locator('input[name="name"]').fill("Hosted external recommendations")
            with sqlite3.connect(database) as connection:
                hosted_targets = connection.execute("SELECT id FROM link_pool_targets WHERE target_type = 'external' AND input_url != 'https://example.com/path,a'").fetchall()
            for (target_id,) in hosted_targets:
                page.locator(f'input[name="target_ids[]"][value="{target_id}"]').check()
            with page.expect_navigation():
                page.get_by_role("button", name="保存草稿", exact=True).click()
            page.get_by_role("link", name="开启推荐，继续发布", exact=True).click()
            with page.expect_navigation():
                page.locator("[data-adaptation-start]").click()
            page.wait_for_load_state("networkidle")
            expect(page.locator("[data-adaptation-actions]")).to_be_visible(timeout=15000)
            page.get_by_role("link", name="返回活动，继续发布", exact=False).click()
            expect(page.locator('input[name="name"]')).to_have_value("Hosted external recommendations")
            page.get_by_role("button", name="发布活动", exact=True).click()
            with page.expect_navigation():
                page.locator("[data-admin-action-confirm]").click()
            response = page.request.get(base_url + "/article/source", headers={"Host": "adaptation.sites.test"})
            assert response.status == 200 and 'href="https://www.aichafen.com/"' in response.text(), (response.status, response.text()[:500])
            assert response.text().count('class="link-pool__link"') == 2
            assert page.request.get(base_url + "/article/source").status == 404
            page.get_by_role("link", name="预览文章", exact=True).click()
            expect(page.get_by_role("heading", name="Source browser article", exact=True)).to_be_visible()
            expect(page.get_by_role("link", name="爱查分", exact=True)).to_be_visible()
            screenshot("17-hosted-article-preview.png")
            check("Enabling a hosted site from a draft returns to that same campaign and publishes two real HTTP anchors for an assigned private article")
            with sqlite3.connect(database) as connection:
                connection.execute("UPDATE hosted_site_article_assignments SET status = 'withdrawn', withdrawn_at = CURRENT_TIMESTAMP WHERE article_id = ?", (fixture['article_ids']['source'],))
            assert page.request.get(base_url + "/article/source", headers={"Host": "adaptation.sites.test"}).status == 404
            check("Withdrawing a hosted article assignment stops public article access and recommendation output")
            assert not evidence["page_errors"], evidence["page_errors"]
        except Exception:
            try:
                screenshot("failure.png")
                (output / "failure.html").write_text(page.content(), encoding="utf-8")
            except Exception:
                pass
            raise
        finally:
            (output / "browser-evidence.json").write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
            context.close()
            browser.close()
    return evidence


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=Path(tempfile.gettempdir()) / "geoflow-link-pool-proof")
    parser.add_argument("--ui-v3", action="store_true", help="render the admin UI V3 shell")
    parser.add_argument("--navigation-only", action="store_true", help="verify distribution entry, return links and responsive layouts")
    args = parser.parse_args()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    assert (ROOT / "public/build/manifest.json").is_file(), "Build assets before running this test."
    with tempfile.TemporaryDirectory(prefix="geoflow-link-pool-browser-") as directory:
        temporary = Path(directory).resolve()
        (temporary / ".browser-test-only").touch()
        database = temporary / "test.sqlite"
        database.touch()
        for child in ["framework/sessions", "framework/views", "framework/cache/data", "logs", "app/private"]:
            (temporary / "storage" / child).mkdir(parents=True, exist_ok=True)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        base_url = f"http://127.0.0.1:{port}"
        environment = dict(os.environ, APP_ENV="testing", APP_DEBUG="false", APP_KEY="base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
            APP_URL=base_url, ASSET_URL=base_url, SITE_URL="https://127.0.0.1", APP_LOCALE="zh_CN",
            DB_CONNECTION="sqlite", DB_DATABASE=str(database), DB_URL="", GEOFLOW_BROWSER_TEST_ROOT=str(temporary),
            ADMIN_BASE_PATH=ADMIN, CACHE_STORE="array", SESSION_DRIVER="file", SESSION_COOKIE="geoflow_browser_link_pool",
            SESSION_SECURE_COOKIE="false", SESSION_DOMAIN="", QUEUE_CONNECTION="sync", BROADCAST_CONNECTION="null",
            MAIL_MAILER="array", LOG_CHANNEL="single", BCRYPT_ROUNDS="4",
            GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED="true", GEOFLOW_ADMIN_UI_V3_ENABLED="true" if args.ui_v3 else "false",
            GEOFLOW_UPDATE_CHECK_ENABLED="false", GEOFLOW_ADMIN_EMAIL="fixture@example.test",
            GEOFLOW_LINK_POOL_THEME_SHARED_STORAGE="true", GEOFLOW_LINK_POOL_THEME_READBACK_BASE_URL=base_url,
            GEOFLOW_OUTBOUND_PRIVATE_TARGETS=f"127.0.0.1:{port}", PHP_CLI_SERVER_WORKERS="2",
            GEOFLOW_HOSTED_SITES_ENABLED="true", GEOFLOW_PRIMARY_HOSTS="127.0.0.1", GEOFLOW_HOSTED_SITE_ROOT_DOMAINS="sites.test",
            GEOFLOW_ADMIN_PASSWORD="test-only-seed", APP_CONFIG_CACHE=str(temporary / "config.php"),
            APP_ROUTES_CACHE=str(temporary / "routes.php"), APP_EVENTS_CACHE=str(temporary / "events.php"),
            APP_SERVICES_CACHE=str(temporary / "services.php"), APP_PACKAGES_CACHE=str(temporary / "packages.php"),
            PULSE_ENABLED="false", TELESCOPE_ENABLED="false", NIGHTWATCH_ENABLED="false")
        fixture_process = subprocess.run(["php", "tests/Browser/link_pool_fixture.php"], cwd=ROOT, env=environment,
                                         text=True, capture_output=True, timeout=120)
        (output / "fixture.log").write_text(fixture_process.stdout + fixture_process.stderr, encoding="utf-8")
        assert fixture_process.returncode == 0, fixture_process.stdout + fixture_process.stderr
        fixture = json.loads(fixture_process.stdout.strip().splitlines()[-1])
        with (output / "server.log").open("w") as server_log:
            server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public", "tests/Browser/link_pool_router.php"],
                                      cwd=ROOT, env=environment, stdout=server_log, stderr=subprocess.STDOUT, start_new_session=True)
            try:
                deadline = time.monotonic() + 30
                while True:
                    if server.poll() is not None:
                        raise RuntimeError("Local PHP server stopped; inspect server.log")
                    try:
                        with urlopen(base_url + "/up", timeout=1) as response:
                            if response.status == 200:
                                break
                    except OSError:
                        if time.monotonic() > deadline:
                            raise RuntimeError("Local PHP server was not ready; inspect server.log")
                        time.sleep(0.1)
                evidence = run_browser(base_url, database, fixture, output, navigation_only=args.navigation_only)
            finally:
                os.killpg(server.pid, signal.SIGTERM)
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(server.pid, signal.SIGKILL)
                    server.wait(timeout=5)
                application_log = temporary / "storage/logs/laravel.log"
                if application_log.exists():
                    (output / "application.log").write_bytes(application_log.read_bytes())
    evidence["cleanup"] = {"server_stopped": server.poll() is not None, "temporary_directory_removed": not temporary.exists()}
    assert all(evidence["cleanup"].values()), evidence["cleanup"]
    (output / "browser-evidence.json").write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"passed": len(evidence["checks"]), "evidence": str(output / "browser-evidence.json"),
                      "screenshots": evidence["screenshots"], "cleanup": evidence["cleanup"]}, ensure_ascii=False))


if __name__ == "__main__":
    main()
