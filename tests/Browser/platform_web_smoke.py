"""Assisted publishing form and AI visibility UI checks in the private Chromium harness.

Reuses the article workflow harness for its temporary DB, local-only HTTP and cleanup.
Run: python3 tests/Browser/platform_web_smoke.py --output-dir /tmp/geoflow-platform-proof
"""
import json
import re
import sqlite3
from urllib.parse import urlparse

from playwright.sync_api import expect, sync_playwright
import article_workflow_smoke as harness


def run_browser(base_url, database, fixture, output):
    evidence = {"checks": [], "screenshots": [], "requests": []}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 1100}, locale="zh-CN", service_workers="block")
        context.route("**/*", lambda route: route.continue_() if urlparse(route.request.url).hostname == "127.0.0.1" else route.abort())
        page = context.new_page()
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))

        def goto(path):
            response = page.goto(base_url + path)
            assert response and response.status == 200, (path, response.status if response else None)
            page.wait_for_load_state("networkidle")

        def screenshot(name):
            path = output / name
            page.screenshot(path=str(path), full_page=True, animations="disabled")
            evidence["screenshots"].append(str(path))

        try:
            goto(f"/{harness.ADMIN}/login")
            page.locator('[name="username"]').fill("browser_workflow")
            page.locator('[name="password"]').fill("browser-workflow-test-only")
            with page.expect_navigation():
                page.locator('button[type="submit"]').click()
            goto(f"/{harness.ADMIN}/manual-publications/settings")
            csrf = page.locator('meta[name="csrf-token"]').get_attribute('content')
            headers = {"X-CSRF-TOKEN": csrf, "Referer": page.url}
            response = page.request.post(base_url + f"/{harness.ADMIN}/manual-publications/settings/personas", form={"name": "Browser platform persona", "is_active": "1"}, headers=headers)
            assert response.ok
            with sqlite3.connect(database) as db:
                persona_id = db.execute("SELECT id FROM manual_publication_personas WHERE name = ?", ("Browser platform persona",)).fetchone()[0]
            response = page.request.post(base_url + f"/{harness.ADMIN}/manual-publications/settings/accounts", form={"persona_id": str(persona_id), "platform": "toutiao", "account_name": "Browser platform account", "is_active": "1"}, headers=headers)
            assert response.ok
            with sqlite3.connect(database) as db:
                account_id = db.execute("SELECT id FROM manual_publication_accounts WHERE account_name = ?", ("Browser platform account",)).fetchone()[0]
            goto(f"/{harness.ADMIN}/distribution/create")
            page.locator('[name="channel_type"][value="platform_web"]').check()
            expect(page.locator('[data-platform-web-config]')).to_be_visible()
            page.locator('[name="name"]').fill("Browser assisted channel")
            page.locator('#manual_publication_account_id').select_option(str(account_id))
            page.locator('#editor_url').fill('https://evil.test/editor')
            with page.expect_navigation():
                page.locator('form').filter(has=page.locator('#name')).locator('button[type="submit"]').click()
            expect(page.locator('[name="channel_type"][value="platform_web"]')).to_be_checked()
            assert not page.locator('#domain').evaluate('(el) => el.required')
            assert not page.locator('#endpoint_url').evaluate('(el) => el.required')
            expect(page.get_by_text('编辑器地址必须为对应平台的 HTTPS 编辑页面。', exact=True)).to_be_visible()
            page.locator('#editor_url').fill('https://mp.toutiao.com/profile_v4/graph/articles/publish')
            assert page.locator('form').filter(has=page.locator('#name')).evaluate('(el) => el.checkValidity()')
            evidence['checks'].append('create validation round-trip keeps hidden endpoint fields optional and corrected form submits')
            with page.expect_navigation():
                page.locator('form').filter(has=page.locator('#name')).locator('button[type="submit"]').click()
            expect(page).to_have_url(re.compile(r"/distribution/\d+/edit$"))
            expect(page.locator('#editor_url')).to_have_value("https://mp.toutiao.com/profile_v4/graph/articles/publish")
            screenshot("01-platform-desktop.png")
            evidence["checks"].append("normal channel form creates a platform work channel with its default editor and shared active account")
            page.locator('#editor_url').fill('https://evil.test/editor')
            with page.expect_navigation():
                page.locator('form').filter(has=page.locator('#name')).locator('button[type="submit"]').click()
            expect(page.get_by_text('编辑器地址必须为对应平台的 HTTPS 编辑页面。', exact=True)).to_be_visible()
            evidence["checks"].append("server validation rejects a different editor origin in the rendered edit form")
            page.set_viewport_size({"width": 390, "height": 844})
            expect(page.locator('[data-platform-web-config]')).to_be_visible()
            screenshot("02-platform-mobile.png")
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
            goto(f"/{harness.ADMIN}/analytics/ai-visibility")
            page.locator('[data-ai-visibility-drawer-open]').click()
            expect(page.locator('[data-ai-visibility-search-workspace]')).to_have_class(re.compile(r"open"))
            expect(page.locator('#doubao-query')).to_be_visible()
            screenshot("03-visibility-mobile-workspace.png")
            page.keyboard.press('Escape')
            expect(page.locator('[data-ai-visibility-search-workspace]')).not_to_have_class(re.compile(r"open"))
            page.set_viewport_size({"width": 1440, "height": 1100})
            screenshot("04-visibility-desktop.png")
            expect(page.get_by_text('关键词批量采集', exact=True)).to_be_visible()
            assert not errors, errors
            evidence["checks"].append("search workspace opens and closes on mobile; desktop keeps keyword collection")
        except Exception:
            screenshot("failure.png")
            (output / 'failure.html').write_text(page.content(), encoding='utf-8')
            raise
        finally:
            evidence["page_errors"] = errors
            (output / 'browser-evidence.json').write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding='utf-8')
            context.close()
            browser.close()
    return evidence


if __name__ == '__main__':
    harness.run_browser = run_browser
    harness.main()
