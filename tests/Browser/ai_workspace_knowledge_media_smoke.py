#!/usr/bin/env python3
"""Responsive smoke test for AI Workspace system knowledge and its private gallery."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from playwright.sync_api import Browser, BrowserContext, Page, sync_playwright


def admin_url(base_url: str, admin_path: str, path: str) -> str:
    return f"{base_url}/{admin_path.strip('/')}/{path.lstrip('/')}"


def login(page: Page, base_url: str, admin_path: str, username: str, password: str) -> None:
    page.goto(admin_url(base_url, admin_path, "/login"), wait_until="domcontentloaded")
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.locator('button[type="submit"]').click()
    page.wait_for_url(lambda url: f"/{admin_path.strip('/')}/login" not in url, timeout=10_000)


def assert_no_horizontal_overflow(page: Page) -> None:
    dimensions = page.evaluate(
        "() => ({ width: window.innerWidth, scrollWidth: document.documentElement.scrollWidth })"
    )
    if dimensions["scrollWidth"] > dimensions["width"] + 1:
        raise AssertionError(f"Horizontal overflow detected: {dimensions}")


def check_workspace(page: Page, base_url: str, admin_path: str) -> None:
    page.goto(admin_url(base_url, admin_path, "/ai-workspace"), wait_until="networkidle")
    page.locator("[data-ai-workspace]").wait_for()
    page.locator("[data-ai-form]").wait_for()
    page.locator("[data-ai-input]").wait_for()
    assert_no_horizontal_overflow(page)


def check_gallery(page: Page, base_url: str, admin_path: str) -> None:
    page.goto(admin_url(base_url, admin_path, "/knowledge-bases"), wait_until="networkidle")
    detail = page.locator(
        '[data-system-knowledge="true"] a[href*="/knowledge-bases/"][href$="/detail"]'
    ).first
    detail.click()
    page.wait_for_load_state("networkidle")
    page.locator("#knowledge-media").wait_for()
    images = page.locator("#knowledge-media article img")
    manifest = json.loads(
        Path("resources/knowledge/ai-workspace/media/manifest.json").read_text(encoding="utf-8")
    )
    required_keys = {asset["asset_key"] for asset in manifest["assets"]}
    active_keys = set(
        page.locator('#knowledge-media article[data-media-active="true"]').evaluate_all(
            "elements => elements.map(element => element.dataset.mediaAssetKey)"
        )
    )
    missing_keys = required_keys - active_keys
    if missing_keys:
        raise AssertionError(f"Bundled knowledge images are missing or inactive: {sorted(missing_keys)}")
    if images.count() < len(required_keys):
        raise AssertionError(f"Expected at least {len(required_keys)} knowledge images, found {images.count()}")
    image_urls = images.evaluate_all("elements => elements.map(element => element.src)")
    image_statuses = page.evaluate(
        "async urls => Promise.all(urls.map(async url => (await fetch(url, { credentials: 'same-origin' })).status))",
        image_urls,
    )
    if image_statuses != [200] * len(image_urls):
        raise AssertionError(f"Private knowledge thumbnails returned unexpected statuses: {image_statuses}")
    images.first.scroll_into_view_if_needed()
    images.first.wait_for(state="visible")
    page.wait_for_function(
        "element => element.complete && element.naturalWidth > 0",
        arg=images.first.element_handle(),
    )
    assert_no_horizontal_overflow(page)


def authenticated_page(
    browser: Browser,
    base_url: str,
    username: str,
    password: str,
    admin_path: str,
    width: int,
    height: int,
) -> tuple[Page, BrowserContext]:
    context = browser.new_context(
        viewport={"width": width, "height": height},
        locale="zh-CN",
        color_scheme="light",
        reduced_motion="reduce",
    )
    page = context.new_page()
    login(page, base_url, admin_path, username, password)

    return page, context


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:18081")
    parser.add_argument("--admin-path", default="geo_admin")
    parser.add_argument("--username", default="admin")
    parser.add_argument("--password", required=True)
    args = parser.parse_args()
    base_url = args.base_url.rstrip("/")

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        for width, height in [(1440, 900), (390, 844)]:
            page, context = authenticated_page(
                browser,
                base_url,
                args.username,
                args.password,
                args.admin_path,
                width,
                height,
            )
            check_workspace(page, base_url, args.admin_path)
            check_gallery(page, base_url, args.admin_path)
            context.close()
        browser.close()


if __name__ == "__main__":
    main()
