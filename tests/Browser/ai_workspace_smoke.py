import os
from pathlib import Path

from playwright.sync_api import sync_playwright


base_url = os.environ.get("AIW_BROWSER_BASE_URL", "http://localhost:28080")
admin_path = os.environ.get("AIW_BROWSER_ADMIN_PATH", "admin").strip("/")
username = os.environ.get("AIW_BROWSER_USERNAME", "browser_admin")
password = os.environ.get("AIW_BROWSER_PASSWORD", "BrowserPass123")
screenshot_dir = Path(os.environ.get("AIW_BROWSER_SCREENSHOT_DIR", "/tmp"))
runtime_enabled = os.environ.get("AIW_BROWSER_RUNTIME_ENABLED", "true").lower() == "true"

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1000}, device_scale_factor=1)
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
    prompt = page.locator("[data-ai-input]")
    assert prompt.is_enabled()
    composer_box = page.locator("[data-ai-form]").bounding_box()
    capability_box = page.locator(".gf-ai-capability").bounding_box()
    assert composer_box is not None and capability_box is not None
    assert 718 <= composer_box["width"] <= 722
    assert abs(composer_box["width"] - capability_box["width"]) <= 1
    prompt.fill("帮我新建任务")
    page.locator("[data-ai-form] button[type=submit]").click()

    if runtime_enabled:
        page.locator("[data-ai-runs] .gf-ai-run-card").wait_for(state="visible", timeout=90000)
        page.wait_for_function(
            "document.querySelector('[data-ai-runs]')?.textContent.includes('请补充')",
            timeout=90000,
        )
        assert "请补充" in page.locator("[data-ai-runs]").inner_text()
        page.locator("[data-ai-history-list]").get_by_text("帮我新建任务", exact=True).first.wait_for()
        assert page.locator("[data-ai-history-list] .gf-ai-history__item").count() >= 1
        assert "帮我新建任务" in page.locator("[data-ai-history-list]").inner_text()
        assert page.locator("[data-ai-thread]").is_visible()
        assert page.locator("[data-ai-form]").bounding_box()["width"] <= 842
    else:
        dialog = page.locator("[data-ai-error-dialog] .gf-ai-error-dialog")
        dialog.wait_for(state="visible")
        page.wait_for_timeout(200)
        dialog_box = dialog.bounding_box()
        viewport = page.viewport_size
        assert dialog_box is not None and viewport is not None
        assert abs((dialog_box["x"] + dialog_box["width"] / 2) - viewport["width"] / 2) <= 2, (dialog_box, viewport)
        assert abs((dialog_box["y"] + dialog_box["height"] / 2) - viewport["height"] / 2) <= 2, (dialog_box, viewport)
        assert page.locator("[data-ai-error-configurator]").is_visible()
        assert prompt.input_value() == "帮我新建任务"
        page.screenshot(path=str(screenshot_dir / "geoflow-ai-workspace-runtime-error.png"), full_page=True)
        page.locator("[data-ai-error-secondary]").click()
        dialog.wait_for(state="hidden")

    page.screenshot(path=str(screenshot_dir / "geoflow-ai-workspace-desktop.png"), full_page=True)

    for width, height in [(1280, 900), (1024, 900), (768, 900), (390, 844), (320, 700)]:
        page.set_viewport_size({"width": width, "height": height})
        page.wait_for_timeout(150)
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), width
        responsive_composer = page.locator("[data-ai-form]").bounding_box()
        assert responsive_composer is not None
        assert responsive_composer["width"] <= min(width, 842 if runtime_enabled else 722)

        if width == 390:
            page.locator("[data-sidebar-open]").click()
            assert "gf-sidebar-open" in (page.locator("body").get_attribute("class") or "")
            if runtime_enabled:
                page.locator("[data-ai-history-list]").get_by_text("帮我新建任务", exact=True).first.wait_for()
            page.locator("[data-sidebar-close]").first.click()
            assert "gf-sidebar-open" not in (page.locator("body").get_attribute("class") or "")
            page.wait_for_timeout(300)
            page.screenshot(path=str(screenshot_dir / "geoflow-ai-workspace-mobile.png"), full_page=True)

    assert console_errors == [], f"Browser console errors: {console_errors}"
    browser.close()
