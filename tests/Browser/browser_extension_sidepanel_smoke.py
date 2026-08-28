import os
import json
from pathlib import Path

from playwright.sync_api import sync_playwright


CHROME_MOCK = """
window.chrome = {
  runtime: { getManifest: () => ({ version: '0.1.0' }) },
  i18n: { getMessage: () => '', getUILanguage: () => 'zh-CN' },
  storage: {
    local: {
      get: async () => ({}), set: async () => {}, remove: async () => {},
      setAccessLevel: async () => {}
    },
    session: {
      get: async () => ({}), set: async () => {}, remove: async () => {},
      setAccessLevel: async () => {}
    }
  },
  permissions: { contains: async () => false, request: async () => false },
  tabs: { create: async () => ({ id: 1 }), get: async () => ({ status: 'complete' }) },
  scripting: { executeScript: async () => [{ result: null }] }
};
"""
BASE_URL = os.environ.get("BROWSER_EXTENSION_SMOKE_URL", "http://127.0.0.1:18765")
CONNECTED_MOCK = CHROME_MOCK.replace(
    "get: async () => ({}), set: async () => {}, remove: async () => {},",
    "get: async (key) => key === 'geoflow_browser_connection' ? "
    + json.dumps({"geoflow_browser_connection": {"baseUrl": BASE_URL, "token": "test-token"}})
    + " : {}, set: async () => {}, remove: async () => {},",
    1,
)


def envelope(data):
    return {"success": True, "data": data, "error": None, "meta": {"request_id": "smoke", "timestamp": "2026-08-24T00:00:00Z"}}


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    for width in (375, 480):
        page = browser.new_page(viewport={"width": width, "height": 820})
        page.add_init_script(CHROME_MOCK)
        page.goto(f"{BASE_URL}/src/sidepanel/sidepanel.html")
        page.wait_for_load_state("networkidle")
        page.locator("#connect-form").wait_for(state="visible")
        assert page.locator("#workspace-view").is_hidden()
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
        assert page.locator("#connect-form button").evaluate("el => el.getBoundingClientRect().height >= 40")
        page.screenshot(path=str(Path("/tmp") / f"geoflow-browser-extension-{width}.png"), full_page=True)
        page.close()

    page = browser.new_page(viewport={"width": 375, "height": 900})
    page.add_init_script(CONNECTED_MOCK)

    def respond(route):
        if route.request.url.endswith("/browser-operations/session"):
            payload = envelope({"protocol_version": 1, "admin": {"id": 1, "display_name": "运营同事", "role": "admin"}, "scopes": []})
        else:
            payload = envelope({
                "items": [{
                    "id": 42, "type": "post", "platform": "zhihu", "status": "ready", "revision": 1,
                    "target_url": "https://www.zhihu.com/question/123456", "scheduled_at": None,
                    "publication_payload": {"target_action": "zhihu_answer", "title": "怎样建立可信的 GEO 内容流程？", "body_plain": "这是需要由运营人员检查并发布的知乎回答正文。"},
                    "completion_url": None, "claim": {"claimed_at": None, "last_seen_at": None, "stale": False},
                    "account": {"id": 1, "name": "GEOFlow 知乎账号", "profile_url": "https://www.zhihu.com/people/geoflow"},
                    "persona": {"id": 1, "name": "GEOFlow 专家"},
                }],
                "pagination": {"page": 1, "per_page": 20, "total": 1, "last_page": 1},
                "protocol_version": 1,
            })
        route.fulfill(status=200, content_type="application/json", body=json.dumps(payload, ensure_ascii=False))

    page.route("**/api/v1/**", respond)
    page.goto(f"{BASE_URL}/src/sidepanel/sidepanel.html")
    page.wait_for_load_state("networkidle")
    page.locator(".task-item").wait_for(state="visible")
    page.locator(".task-item").click()
    page.locator("#task-view").wait_for(state="visible")
    assert page.locator("#claim-task").is_visible()
    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
    page.screenshot(path="/tmp/geoflow-browser-extension-task-375.png", full_page=True)
    page.close()
    browser.close()
