#!/usr/bin/env python3
"""Measure first-paint stability across every registered UI V3 shell page."""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path
from urllib.parse import urlparse, urlunparse

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright


VIEWPORTS = {
    "1440": {"width": 1440, "height": 1000},
    "1280": {"width": 1280, "height": 900},
    "1024": {"width": 1024, "height": 820},
    "768": {"width": 768, "height": 900},
    "375": {"width": 375, "height": 812},
    "320": {"width": 320, "height": 700},
}

EXPECTED_BROWSER_DIAGNOSTICS = (
    "because the document's frame is sandboxed and the 'allow-scripts' permission is not set",
)

INIT_SCRIPT = r"""
(() => {
    window.__gfFlickerAudit = { shifts: [], frames: [] };
    try {
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (!entry.hadRecentInput) {
                    window.__gfFlickerAudit.shifts.push({
                        value: entry.value,
                        sources: entry.sources.map((source) => source.node?.outerHTML?.slice(0, 240) ?? ''),
                    });
                }
            }
        }).observe({ type: 'layout-shift', buffered: true });
    } catch {}

    const rect = (selector) => {
        const element = document.querySelector(selector);
        if (!element) return null;
        const bounds = element.getBoundingClientRect();
        return { x: bounds.x, y: bounds.y, width: bounds.width, height: bounds.height };
    };
    let frame = 0;
    const sample = () => {
        window.__gfFlickerAudit.frames.push({
            time: performance.now(),
            ready: document.readyState,
            state: document.documentElement.getAttribute('data-gf-sidebar-state'),
            booting: document.documentElement.hasAttribute('data-gf-ui-booting'),
            sidebar: rect('.gf-sidebar'),
            shellBody: rect('.gf-shell__body'),
            heading: rect('.gf-content h1'),
            aiStart: rect('.gf-ai-start'),
            aiHeading: rect('.gf-ai-heading'),
            aiSuggestions: rect('.gf-ai-suggestions'),
            aiComposer: rect('.gf-ai-composer'),
            aiRuntimeNotice: rect('.gf-ai-runtime-notice'),
            pendingIcons: document.querySelectorAll('i[data-lucide]').length,
        });
        frame += 1;
        if (frame < 50) requestAnimationFrame(sample);
    };
    requestAnimationFrame(sample);
})();
"""


def shell_rows(matrix_path: Path) -> list[dict[str, str]]:
    with matrix_path.open(encoding="utf-8-sig", newline="") as handle:
        return [row for row in csv.DictReader(handle) if row["page_type"] == "V3 公共壳层"]


def delta(first: dict | None, final: dict | None, key: str) -> float | None:
    if not first or not final:
        return None
    return round(abs(float(first[key]) - float(final[key])), 3)


def is_unexpected_console_error(message: str) -> bool:
    return not any(diagnostic in message for diagnostic in EXPECTED_BROWSER_DIAGNOSTICS)


def audit_url(base_url: str, recorded_url: str) -> str:
    base = urlparse(base_url)
    recorded = urlparse(recorded_url)

    return urlunparse((base.scheme, base.netloc, recorded.path, '', recorded.query, ''))


def page_result(page, row: dict[str, str], viewport_name: str) -> dict:
    audit = page.evaluate("window.__gfFlickerAudit") or {"shifts": [], "frames": []}
    frames = [frame for frame in audit.get("frames", []) if frame.get("sidebar") and frame.get("shellBody")]
    first = frames[0] if frames else {}
    final = frames[-1] if frames else {}
    cls = round(sum(float(entry.get("value", 0)) for entry in audit.get("shifts", [])), 6)
    sidebar_width_delta = delta(first.get("sidebar"), final.get("sidebar"), "width")
    content_x_delta = delta(first.get("shellBody"), final.get("shellBody"), "x")
    heading_x_delta = delta(first.get("heading"), final.get("heading"), "x")
    measurements = page.evaluate(
        """() => ({
            state: document.documentElement.getAttribute('data-gf-sidebar-state'),
            booting: document.documentElement.hasAttribute('data-gf-ui-booting'),
            pendingIcons: document.querySelectorAll('i[data-lucide]').length,
            pendingIconMarkup: Array.from(document.querySelectorAll('i[data-lucide]')).slice(0, 5).map(icon => icon.outerHTML),
            shell: Boolean(document.querySelector('[data-gf-shell]')),
            sidebarPosition: document.querySelector('.gf-sidebar')
                ? getComputedStyle(document.querySelector('.gf-sidebar')).position
                : null,
            documentOverflow: Math.max(0, document.documentElement.scrollWidth - window.innerWidth),
        })"""
    )
    stable = (
        measurements["shell"]
        and measurements["sidebarPosition"] == "fixed"
        and not measurements["booting"]
        and measurements["pendingIcons"] == 0
        and cls <= 0.01
        and (sidebar_width_delta is None or sidebar_width_delta <= 0.5)
        and (content_x_delta is None or content_x_delta <= 0.5)
        and (heading_x_delta is None or heading_x_delta <= 0.5)
        and measurements["documentOverflow"] <= 1
    )

    return {
        "route_name": row["route_name"],
        "url": page.url,
        "viewport": viewport_name,
        "status": "pass" if stable else "fail",
        "layout_status": "pass" if stable else "fail",
        "cls": cls,
        "sidebar_width_delta": sidebar_width_delta,
        "content_x_delta": content_x_delta,
        "heading_x_delta": heading_x_delta,
        "layout_shifts": audit.get("shifts", []),
        "first_frame": first,
        "final_frame": final,
        **measurements,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://localhost:28080")
    parser.add_argument("--matrix", default="storage/app/review-artifacts/admin-ui-v3-full-review/page-audit-matrix.csv")
    parser.add_argument("--output", default="storage/app/review-artifacts/admin-ui-v3-flicker-review/browser-audit.json")
    parser.add_argument("--username", default="ui_v3_reviewer")
    parser.add_argument("--password", default="ui-v3-review-only")
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--viewports", default=','.join(VIEWPORTS))
    parser.add_argument("--sidebar-state", choices=["expanded", "collapsed"], default="expanded")
    parser.add_argument("--routes", default="")
    args = parser.parse_args()

    matrix_path = Path(args.matrix)
    rows = shell_rows(matrix_path)
    selected_routes = {route for route in args.routes.split(',') if route}
    if selected_routes:
        rows = [row for row in rows if row["route_name"] in selected_routes]
    if args.limit > 0:
        rows = rows[: args.limit]
    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    base_host = urlparse(args.base_url).netloc
    selected_viewports = {name: VIEWPORTS[name] for name in args.viewports.split(',') if name in VIEWPORTS}
    if not selected_viewports:
        raise SystemExit('No valid viewports selected.')
    results: list[dict] = []
    failures: list[dict] = []

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        login_context = browser.new_context(viewport=VIEWPORTS["1440"])
        login_page = login_context.new_page()
        login_page.goto(f"{args.base_url}/admin/login", wait_until="domcontentloaded")
        login_page.locator("#username").fill(args.username)
        login_page.locator("#password").fill(args.password)
        login_page.locator("form").evaluate("form => form.requestSubmit()")
        login_page.wait_for_url(lambda url: urlparse(str(url)).netloc == base_host and "/admin/login" not in str(url), timeout=15000)
        collapsed_value = '1' if args.sidebar_state == 'collapsed' else '0'
        login_page.evaluate(
            "value => localStorage.setItem('geoflow.admin.ui-v3.sidebar-collapsed', value)",
            collapsed_value,
        )
        storage_state = login_context.storage_state()
        login_context.close()

        for viewport_name, viewport in selected_viewports.items():
            context = browser.new_context(viewport=viewport, storage_state=storage_state)
            context.add_init_script(INIT_SCRIPT)
            for index, row in enumerate(rows, start=1):
                page = context.new_page()
                console_errors: list[str] = []
                request_failures: list[str] = []
                http_errors: list[str] = []
                page.on(
                    "console",
                    lambda message: console_errors.append(message.text)
                    if message.type == "error" and is_unexpected_console_error(message.text)
                    else None,
                )
                page.on("pageerror", lambda error: console_errors.append(str(error)))
                page.on("requestfailed", lambda request: request_failures.append(f"{request.method} {request.url}"))
                page.on(
                    "response",
                    lambda response: http_errors.append(f"{response.status} {response.request.method} {response.url}")
                    if response.status >= 400
                    else None,
                )
                session = context.new_cdp_session(page)
                session.send("Network.enable")
                session.send("Network.setCacheDisabled", {"cacheDisabled": True})
                try:
                    response = page.goto(audit_url(args.base_url, row["url"]), wait_until="domcontentloaded", timeout=20000)
                    page.wait_for_function(
                        """() => (
                            window.__gfFlickerAudit?.frames?.length >= 50
                            && !document.documentElement.hasAttribute('data-gf-ui-booting')
                            && document.querySelectorAll('i[data-lucide]').length === 0
                        )""",
                        timeout=5000,
                    )
                    result = page_result(page, row, viewport_name)
                    result["http_status"] = response.status if response else None
                    result["console_errors"] = console_errors
                    result["request_failures"] = request_failures
                    result["http_errors"] = http_errors
                    if result["http_status"] != 200 or console_errors or request_failures or http_errors:
                        result["status"] = "fail"
                except PlaywrightTimeoutError as error:
                    result = {
                        "route_name": row["route_name"],
                        "url": row["url"],
                        "viewport": viewport_name,
                        "status": "fail",
                        "error": str(error),
                        "console_errors": console_errors,
                        "request_failures": request_failures,
                        "http_errors": http_errors,
                    }
                results.append(result)
                if result["status"] != "pass":
                    failures.append(result)
                page.close()
                print(f"[{viewport_name}] {index:02d}/{len(rows)} {row['route_name']}: {result['status']}", flush=True)
            context.close()
        browser.close()

    payload = {
        "base_url": args.base_url,
        "sidebar_state": args.sidebar_state,
        "shell_pages": len(rows),
        "viewports": list(selected_viewports),
        "checks": len(results),
        "passed": len(results) - len(failures),
        "failed": len(failures),
        "layout_passed": sum(result.get("layout_status") == "pass" for result in results),
        "layout_failed": sum(result.get("layout_status") != "pass" for result in results),
        "maximum_cls": max((result.get("cls", 0) for result in results), default=0),
        "results": results,
    }
    output_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({key: payload[key] for key in ["shell_pages", "checks", "passed", "failed", "layout_passed", "layout_failed", "maximum_cls"]}, ensure_ascii=False))

    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
