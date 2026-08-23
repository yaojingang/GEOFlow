import os
from pathlib import Path

from playwright.sync_api import sync_playwright


base_url = os.environ.get("AIW_BROWSER_BASE_URL", "http://localhost:28080")
admin_path = os.environ.get("AIW_BROWSER_ADMIN_PATH", "admin").strip("/")
username = os.environ.get("AIW_BROWSER_USERNAME", "browser_admin")
password = os.environ.get("AIW_BROWSER_PASSWORD", "BrowserPass123")
screenshot_dir = Path(os.environ.get("AIW_BROWSER_SCREENSHOT_DIR", "/tmp"))

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1000}, device_scale_factor=1)
    console_errors = []
    page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)

    page.goto(f"{base_url}/{admin_path}/ai-workspace")
    page.wait_for_load_state("networkidle")
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    page.goto(f"{base_url}/{admin_path}/ai-workspace")
    page.wait_for_load_state("networkidle")

    workspace = page.locator("[data-ai-workspace]")
    workspace.wait_for(state="visible")
    assert workspace.get_attribute("data-runtime-enabled") == "true"
    prompt = page.locator("[data-ai-input]")
    assert prompt.is_enabled()
    prompt.fill("帮我新建任务")
    page.locator("[data-ai-form] button[type=submit]").click()
    page.locator("[data-ai-runs] .gf-ai-run-card").wait_for(state="visible")
    assert "请补充" in page.locator("[data-ai-runs]").inner_text()
    page.locator("[data-ai-history-list]").get_by_text("帮我新建任务", exact=True).first.wait_for()
    assert page.locator("[data-ai-history-list] .gf-ai-history__item").count() >= 1
    assert "帮我新建任务" in page.locator("[data-ai-history-list]").inner_text()
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-workspace-desktop.png"), full_page=True)

    page.set_viewport_size({"width": 390, "height": 844})
    page.locator("[data-sidebar-open]").click()
    assert "gf-sidebar-open" in (page.locator("body").get_attribute("class") or "")
    page.locator("[data-ai-history-list]").get_by_text("帮我新建任务", exact=True).first.wait_for()
    page.locator("[data-sidebar-close]").first.click()
    assert "gf-sidebar-open" not in (page.locator("body").get_attribute("class") or "")
    page.wait_for_timeout(300)
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-workspace-mobile.png"), full_page=True)

    assert console_errors == [], f"Browser console errors: {console_errors}"
    browser.close()
