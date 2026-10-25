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
            for order in fixture['retry_work_orders']:
                api = base_url + f"/api/v1/manual-publications/{order['id']}"
                browser_headers = {'Authorization': 'Bearer ' + fixture['browser_token'],
                    'X-GEOFlow-Browser-Protocol': '1', 'X-GEOFlow-Client-Version': '0.1.0'}
                def api_post(action, payload, key):
                    response = context.request.post(api + '/' + action, data=payload,
                        headers={**browser_headers, 'X-Idempotency-Key': key})
                    assert response.ok, response.text()
                    return response.json()['data']['publication']
                claimed = api_post('claim', {'revision': order['revision']}, f"retry-first-claim-{order['id']}")
                plain = claimed['publication_payload']['body_plain']
                assert 'Browser heading' in plain and 'Verified reference.' in plain and '**literal code**' in plain
                assert '## Browser' not in plain and '**Verified**' not in plain and '&#32;' not in plain
                assert 'https://example.test/reference' not in plain
                assert claimed['publication_payload']['body_markdown'] == plain
                finished = api_post('receipt', {'revision': claimed['revision'], 'outcome': order['outcome'],
                    'adapter_version': 'manual-test-only', 'target_origin': 'https://mp.toutiao.com',
                    'finished_at': '2026-10-25T12:00:00Z'}, f"retry-first-receipt-{order['id']}")
                goto(f"/{harness.ADMIN}/manual-publications/{order['id']}")
                form = page.locator('[data-confirm-unpublished-retry]')
                expect(form).to_be_visible()
                expect(form.locator('[name="publication_revision"]')).to_have_value(str(finished['revision']))
                expect(form.locator('[name="confirm_unpublished"]')).to_be_empty()
                screenshot(f"05-retry-{order['outcome']}-confirm.png")
                form.locator('[name="confirm_unpublished"]').fill('确认未发布')
                with page.expect_navigation():
                    form.get_by_role('button', name='确认未发布并重试', exact=True).click()
                expect(page.locator('[data-confirm-unpublished-retry]')).to_have_count(0)
                expect(page.get_by_text('已确认未发布，原工单已生成新修订，可重新领取。', exact=True)).to_be_visible()
                with sqlite3.connect(database) as db:
                    row = db.execute('SELECT revision, execution_receipt FROM manual_publications WHERE id = ?', (order['id'],)).fetchone()
                    revision, receipt = row[0], json.loads(row[1])
                assert revision == finished['revision'] + 1
                assert receipt['previous_attempts'][0]['receipt']['outcome'] == order['outcome']
                screenshot(f"05-retry-{order['outcome']}.png")
                next_claim = api_post('claim', {'revision': revision}, f"retry-new-claim-{order['id']}")
                assert next_claim['status'] == 'in_progress'
                evidence['checks'].append(f"real browser API claim and {order['outcome']} receipt, human form confirmation, preserved history and new-revision claim")
            assert not errors, errors
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
    harness.FIXTURE_SCRIPT = "tests/Browser/platform_web_fixture.php"
    harness.run_browser = run_browser
    harness.main()
