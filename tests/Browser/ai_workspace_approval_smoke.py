import os
from pathlib import Path

from playwright.sync_api import sync_playwright


base_url = os.environ.get("AIW_BROWSER_BASE_URL", "http://localhost:28080")
admin_path = os.environ.get("AIW_BROWSER_ADMIN_PATH", "admin").strip("/")
username = os.environ.get("AIW_BROWSER_USERNAME", "browser_admin")
password = os.environ.get("AIW_BROWSER_PASSWORD", "BrowserPass123")
screenshot_dir = Path(os.environ.get("AIW_BROWSER_SCREENSHOT_DIR", "/tmp"))
task_name = os.environ.get("AIW_BROWSER_TASK_NAME", "候选环境链路自查任务")

screenshot_dir.mkdir(parents=True, exist_ok=True)

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1000}, device_scale_factor=1)
    console_errors = []
    page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)

    for _ in range(60):
        page.goto(f"{base_url}/{admin_path}/login")
        if page.locator('input[name="username"]').count() == 1:
            break
        page.wait_for_timeout(500)
    else:
        raise AssertionError("AI workspace gateway did not become ready within 30 seconds")
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_url(f"**/{admin_path}/dashboard")
    page.goto(f"{base_url}/{admin_path}/ai-workspace")
    page.wait_for_load_state("networkidle")

    workspace = page.locator("[data-ai-workspace]")
    workspace.wait_for(state="visible")
    assert workspace.get_attribute("data-runtime-enabled") == "true"
    page.locator("[data-ai-new]").click()
    prompt = page.locator("[data-ai-input]")
    prompt.fill(f"请创建一个任务草稿，任务名称为“{task_name}”，文章数量 1，发布间隔 3600 秒。")
    page.locator("[data-ai-form] button[type=submit]").click()

    approval = page.locator("[data-approve-approval]")
    approval.wait_for(state="visible", timeout=120000)
    assert page.locator(".gf-ai-plan-step").count() == 1
    run_text = page.locator("[data-ai-runs]").inner_text()
    assert "任务草稿" in run_text
    assert '"article_limit": 1' in run_text
    assert '"publish_interval": 3600' in run_text
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-workspace-approval.png"), full_page=True)

    approval.click()
    page.locator(".gf-ai-run-card.is-success").wait_for(state="visible", timeout=90000)
    assert task_name in page.locator("[data-ai-runs]").inner_text()
    assert page.locator(".gf-ai-artifact").count() >= 1
    page.screenshot(path=str(screenshot_dir / "geoflow-ai-workspace-approved.png"), full_page=True)

    assert console_errors == [], f"Browser console errors: {console_errors}"
    browser.close()
