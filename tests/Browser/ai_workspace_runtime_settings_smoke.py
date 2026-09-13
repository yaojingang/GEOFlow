import os
from pathlib import Path

from playwright.sync_api import sync_playwright


base_url = os.environ.get("AIW_BROWSER_BASE_URL", "http://localhost:28081")
admin_path = os.environ.get("AIW_BROWSER_ADMIN_PATH", "admin").strip("/")
username = os.environ.get("AIW_BROWSER_USERNAME", "runtime_super_admin")
password = os.environ.get("AIW_BROWSER_PASSWORD", "RuntimePass123")
screenshot_dir = Path(os.environ.get("AIW_BROWSER_SCREENSHOT_DIR", "/tmp"))

screenshot_dir.mkdir(parents=True, exist_ok=True)


def assert_runtime_settings(page, viewport_name: str, exercise_save: bool = False) -> None:
    page.goto(f"{base_url}/{admin_path}/ai-workspace")
    page.wait_for_load_state("networkidle")

    workspace = page.locator("[data-ai-workspace]")
    workspace.wait_for(state="visible")
    assert workspace.get_attribute("data-runtime-enabled") == "false"

    settings_link = page.locator("[data-ai-runtime-settings]")
    settings_link.wait_for(state="visible")
    assert settings_link.get_attribute("href") == f"/{admin_path}/site-settings#site-settings-ai-workspace"
    settings_link.click()
    page.wait_for_load_state("networkidle")

    assert page.url.endswith(f"/{admin_path}/site-settings#site-settings-ai-workspace")
    section = page.locator("#site-settings-ai-workspace")
    section.wait_for(state="visible")
    assert section.get_attribute("open") is not None
    assert page.locator("#ai-workspace-runtime-help").count() == 1

    toggle = page.locator("[data-ai-workspace-runtime-toggle]")
    assert toggle.count() == 1
    assert toggle.is_enabled()
    assert toggle.get_attribute("aria-describedby") == "ai-workspace-runtime-help"
    assert toggle.evaluate("element => element.labels.length") == 1

    save_button = section.locator('button[type="submit"]')
    button_box = save_button.bounding_box()
    assert button_box is not None and button_box["height"] >= 44
    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1")

    page.screenshot(
        path=str(screenshot_dir / f"geoflow-ai-workspace-runtime-settings-{viewport_name}.png"),
        full_page=True,
    )

    if exercise_save:
        toggle.locator("xpath=ancestor::label[1]").click()
        assert toggle.is_checked()
        save_button.click()
        page.wait_for_load_state("networkidle")
        assert page.locator("[data-ai-workspace-runtime-toggle]").is_checked()

        page.goto(f"{base_url}/{admin_path}/ai-workspace")
        page.wait_for_load_state("networkidle")
        assert page.locator("[data-ai-runtime-settings]").count() == 0
        assert page.locator(f'a[href="/{admin_path}/ai-models"]').count() == 1

        page.goto(f"{base_url}/{admin_path}/site-settings#site-settings-ai-workspace")
        page.wait_for_load_state("networkidle")
        toggle = page.locator("[data-ai-workspace-runtime-toggle]")
        toggle.locator("xpath=ancestor::label[1]").click()
        assert not toggle.is_checked()
        page.locator("#site-settings-ai-workspace").locator('button[type="submit"]').click()
        page.wait_for_load_state("networkidle")
        assert not page.locator("[data-ai-workspace-runtime-toggle]").is_checked()

        page.goto(f"{base_url}/{admin_path}/ai-workspace")
        page.wait_for_load_state("networkidle")
        assert page.locator("[data-ai-workspace]").get_attribute("data-runtime-enabled") == "false"


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

    assert_runtime_settings(page, "desktop", exercise_save=True)
    page.set_viewport_size({"width": 375, "height": 812})
    assert_runtime_settings(page, "mobile")

    assert console_errors == [], f"Browser console errors: {console_errors}"
    browser.close()
