import base64
import os
from urllib.parse import urljoin

from playwright.sync_api import sync_playwright


BASE_URL = os.environ.get("GEOFLOW_BROWSER_BASE_URL", "http://127.0.0.1:18113")
ADMIN_PREFIX = os.environ.get("GEOFLOW_BROWSER_ADMIN_PREFIX", "/geo_admin")
USERNAME = os.environ.get("GEOFLOW_BROWSER_USERNAME", "ui_v3_reviewer")
PASSWORD = os.environ.get("GEOFLOW_BROWSER_PASSWORD", "ui-v3-review-only")


def absolute_url(path: str) -> str:
    return urljoin(f"{BASE_URL}/", path.lstrip("/"))


def login(page) -> None:
    page.goto(absolute_url(f"{ADMIN_PREFIX}/login"), wait_until="networkidle")
    page.locator('input[name="username"]').fill(USERNAME)
    page.locator('input[name="password"]').fill(PASSWORD)
    page.locator('button[type="submit"]').click()
    page.wait_for_url(f"**{ADMIN_PREFIX}/dashboard")
    page.wait_for_load_state("networkidle")


def page_metrics(page) -> dict:
    return page.evaluate(
        """
        () => {
            const root = document.documentElement;
            const body = document.body.getBoundingClientRect();
            return {
                innerWidth: window.innerWidth,
                innerHeight: window.innerHeight,
                scrollWidth: root.scrollWidth,
                bodyRight: Math.round(body.right),
                bodyBottom: Math.round(body.bottom),
            };
        }
        """
    )


def check_surface(page, label: str, url: str, width: int) -> None:
    response = page.goto(url, wait_until="networkidle")
    assert response is not None and response.status < 400, f"{label}: HTTP {response.status if response else 'none'}"
    assert page.locator("main#main-content h1").count() == 1, f"{label}: missing unique h1"
    metrics = page_metrics(page)
    assert metrics["scrollWidth"] <= metrics["innerWidth"], f"{label}: horizontal overflow {metrics}"
    assert metrics["bodyRight"] >= width, f"{label}: body does not reach viewport right edge {metrics}"
    assert metrics["bodyBottom"] >= metrics["innerHeight"], f"{label}: body does not reach viewport bottom {metrics}"
    print(f"viewport={width} page={label} metrics={metrics}")


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    console_errors = []
    image_detail_url = None

    for viewport_width in (1280, 375, 320):
        context = browser.new_context(viewport={"width": viewport_width, "height": 900})
        page = context.new_page()
        page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)
        page.on("pageerror", lambda error: console_errors.append(str(error)))
        login(page)

        author_index = absolute_url(f"{ADMIN_PREFIX}/authors")
        check_surface(page, "authors.index", author_index, viewport_width)
        author_edit = page.locator('[data-author-index] a[href$="/edit"]').first.get_attribute("href")
        assert author_edit
        check_surface(page, "authors.create", absolute_url(f"{ADMIN_PREFIX}/authors/create"), viewport_width)
        check_surface(page, "authors.edit", author_edit, viewport_width)

        image_index = absolute_url(f"{ADMIN_PREFIX}/image-libraries")
        check_surface(page, "image-libraries.index", image_index, viewport_width)
        image_edit = page.locator('[data-image-library-index] a[href$="/edit"]').first.get_attribute("href")
        image_upload = page.locator('[data-image-library-index] a[href$="/images/upload"]').first.get_attribute("href")
        assert image_edit and image_upload
        check_surface(page, "image-libraries.create", absolute_url(f"{ADMIN_PREFIX}/image-libraries/create"), viewport_width)
        check_surface(page, "image-libraries.edit", image_edit, viewport_width)
        check_surface(page, "image-libraries.images.create", image_upload, viewport_width)

        image_input = page.locator('input[type="file"][name="images[]"]')
        upload_dropzone = page.locator('[data-image-upload-dropzone]')
        image_input.set_input_files(
            {
                "name": "invalid.php",
                "mimeType": "text/php",
                "buffer": b"not-an-image",
            }
        )
        assert upload_dropzone.evaluate("element => element.classList.contains('border-red-300')")
        assert upload_dropzone.evaluate("element => element.classList.contains('bg-red-50')")

        image_input.set_input_files(
            {
                "name": "responsive-check.png",
                "mimeType": "image/png",
                "buffer": base64.b64decode(
                    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n0sAAAAASUVORK5CYII="
                ),
            }
        )
        assert not upload_dropzone.evaluate("element => element.classList.contains('border-red-300')")
        assert not upload_dropzone.evaluate("element => element.classList.contains('bg-red-50')")
        assert upload_dropzone.evaluate("element => element.classList.contains('border-gray-300')")
        assert upload_dropzone.evaluate("element => element.classList.contains('bg-gray-50')")
        page.locator('[data-image-upload-status]').wait_for(state="visible")
        assert page.locator('[data-image-upload-file-list] li').count() == 1
        assert not page.locator('[data-image-upload-submit]').is_disabled()
        assert page.locator('[data-image-upload-status]').get_attribute("aria-live") == "polite"

        if viewport_width == 1280:
            page.locator('[data-image-upload-submit]').click()
            page.wait_for_url(f"**{ADMIN_PREFIX}/image-libraries/*/detail")
            page.wait_for_load_state("networkidle")
            image_detail_url = page.url

        assert image_detail_url
        check_surface(page, "image-libraries.detail", image_detail_url, viewport_width)
        detail_edit = page.locator('[data-image-library-detail] a[href*="/edit?context=detail"]')
        assert detail_edit.count() == 1

        preview_trigger = page.locator('[data-image-preview-trigger]').first
        assert preview_trigger.count() == 1
        if viewport_width == 1280:
            preview_trigger.click()
        else:
            preview_trigger.focus()
            page.keyboard.press("Enter")

        preview_backdrop = page.locator('[data-gf-modal="image-preview"]')
        preview_backdrop.wait_for(state="visible")
        preview_close = preview_backdrop.locator('[data-dialog-close]')
        assert preview_close.evaluate("element => element === document.activeElement")
        page.keyboard.press("Shift+Tab")
        assert page.locator('[data-image-preview-url]').evaluate("element => element === document.activeElement")
        page.keyboard.press("Tab")
        assert preview_close.evaluate("element => element === document.activeElement")
        page.keyboard.press("Escape")
        preview_backdrop.wait_for(state="hidden")
        assert preview_trigger.evaluate("element => element === document.activeElement")

        with context.expect_page() as popup_info:
            page.locator('[data-image-card-url]').first.click()
        image_popup = popup_info.value
        image_popup.wait_for_load_state("domcontentloaded")
        assert "/storage/uploads/images/" in image_popup.url
        image_popup.close()

        if viewport_width == 320:
            page.locator('button[onclick="toggleBatchActions()"]').first.click()
            page.locator('.image-checkbox').first.check()
            page.once("dialog", lambda dialog: dialog.accept())
            with page.expect_navigation():
                page.locator('[data-image-delete-submit]').click()

        context.close()

    browser.close()

assert console_errors == [], f"browser console errors: {console_errors}"
