#!/usr/bin/env python3
"""Capture the official, sanitized AI Workspace knowledge screenshots."""

from __future__ import annotations

import argparse
from pathlib import Path

from playwright.sync_api import Page, TimeoutError as PlaywrightTimeoutError, sync_playwright


CAPTURES = [
    ("01-ai-workspace-start", "/ai-workspace", None),
    ("03-analytics-overview", "/analytics", None),
    ("04-ai-visibility", "/analytics/ai-visibility", None),
    ("05-task-list", "/tasks", None),
    ("06-task-create-basics", "/tasks/create", None),
    ("07-task-assets-distribution", "/tasks/create", "#prompt_id"),
    ("08-article-list", "/articles", None),
    ("09-article-editor", "/articles/create", None),
    ("10-article-quality", "/articles", 520),
    ("11-materials-overview", "/materials", None),
    ("12-title-ai-generation", "/title-libraries", None),
    ("13-knowledge-base-list", "/knowledge-bases", None),
    ("15-enterprise-knowledge", "/enterprise-knowledge", None),
    ("16-url-import", "/url-import", None),
    ("17-distribution-list", "/distribution", None),
    ("18-distribution-health", "/distribution", 560),
    ("19-hosted-sites", "/distribution/hosted-sites", None),
    ("20-manual-publication", "/manual-publications", None),
    ("21-browser-pairing", "/account/browser-clients", None),
    ("22-ai-models", "/ai-models", None),
    ("23-homepage-theme", "/site-settings/homepage-modules", None),
    ("24-system-update", "/system-updates", None),
]


def admin_url(base_url: str, admin_path: str, path: str) -> str:
    return f"{base_url}/{admin_path.strip('/')}/{path.lstrip('/')}"


def wait_for_page(page: Page) -> None:
    page.wait_for_load_state("domcontentloaded")
    try:
        page.wait_for_load_state("networkidle", timeout=8_000)
    except PlaywrightTimeoutError:
        pass
    page.wait_for_timeout(350)


def reveal_position(page: Page, position: str | int | None) -> None:
    if position is None:
        return

    if isinstance(position, int):
        main = page.locator(".gf-main")
        if main.count() > 0:
            main.evaluate("(element, value) => { element.scrollTop = value; }", position)
        page.evaluate("value => window.scrollTo(0, value)", position)
        page.wait_for_timeout(250)

        return

    target = page.locator(position).first
    if target.count() == 0:
        raise RuntimeError(f"Screenshot anchor was not found: {position}")
    target.scroll_into_view_if_needed()
    target.evaluate("element => element.scrollIntoView({ block: 'start', inline: 'nearest' })")
    page.wait_for_timeout(250)


def capture(
    page: Page,
    base_url: str,
    admin_path: str,
    output: Path,
    name: str,
    path: str,
    position: str | int | None = None,
) -> None:
    response = page.goto(admin_url(base_url, admin_path, path), wait_until="domcontentloaded")
    if response is None or response.status >= 400:
        raise RuntimeError(f"Unable to capture {path}: HTTP {response.status if response else 'none'}")
    wait_for_page(page)
    reveal_position(page, position)
    page.screenshot(path=str(output / f"{name}.png"), full_page=False, animations="disabled")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:18081")
    parser.add_argument("--admin-path", default="geo_admin")
    parser.add_argument("--username", default="admin")
    parser.add_argument("--password", required=True)
    parser.add_argument(
        "--output",
        default="resources/knowledge/ai-workspace/media",
    )
    args = parser.parse_args()
    output = Path(args.output).resolve()
    output.mkdir(parents=True, exist_ok=True)
    base_url = args.base_url.rstrip("/")

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(
            viewport={"width": 1440, "height": 900},
            locale="zh-CN",
            color_scheme="light",
            reduced_motion="reduce",
        )
        page = context.new_page()
        page.goto(admin_url(base_url, args.admin_path, "/login"), wait_until="domcontentloaded")
        wait_for_page(page)
        page.locator('input[name="username"]').fill(args.username)
        page.locator('input[name="password"]').fill(args.password)
        page.locator('button[type="submit"]').click()
        page.wait_for_url(lambda url: f"/{args.admin_path.strip('/')}/login" not in url, timeout=10_000)
        wait_for_page(page)

        capture(page, base_url, args.admin_path, output, *CAPTURES[0])
        page.locator("[data-ai-input]").fill("请详细说明如何创建一个内容任务？")
        page.locator("[data-ai-form]").press("Control+Enter")
        try:
            assistant = page.locator(".gf-ai-help__message.is-assistant").last
            assistant.wait_for(state="visible", timeout=10_000)
            page.wait_for_function(
                "!document.querySelector('.gf-ai-help__message.is-assistant:last-of-type')?.classList.contains('is-pending')",
                timeout=90_000,
            )
            media = assistant.locator(".gf-ai-help__media").first
            if media.count() > 0:
                media.scroll_into_view_if_needed()
                media_image = media.locator("img").first
                media_image.wait_for(state="visible")
                page.wait_for_function(
                    "element => element.complete && element.naturalWidth > 0",
                    arg=media_image.element_handle(),
                )
        except PlaywrightTimeoutError:
            page.locator(".gf-ai-help__error").last.wait_for(timeout=10_000)
        page.screenshot(path=str(output / "02-ai-workspace-conversation.png"), full_page=False, animations="disabled")

        for item in CAPTURES[1:]:
            capture(page, base_url, args.admin_path, output, *item)

        page.goto(admin_url(base_url, args.admin_path, "/knowledge-bases"), wait_until="domcontentloaded")
        wait_for_page(page)
        detail_link = page.locator(
            '[data-system-knowledge="true"] a[href*="/knowledge-bases/"][href$="/detail"]'
        ).first
        if detail_link.count() == 0:
            raise RuntimeError("The system knowledge detail link was not found.")
        detail_link.click()
        wait_for_page(page)
        page.screenshot(path=str(output / "14-knowledge-base-detail.png"), full_page=False, animations="disabled")

        context.close()
        browser.close()


if __name__ == "__main__":
    main()
