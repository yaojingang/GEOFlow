#!/usr/bin/env python3
"""Exercise UI V3 sidebar persistence and full-page navigation journeys."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright


STORAGE_KEY = "geoflow.admin.ui-v3.sidebar-collapsed"
OUTPUT_ROOT = Path("storage/app/review-artifacts/admin-ui-v3-flicker-review")
REVIEWED_RESPONSE_HEADERS = (
    "cache-control",
    "x-content-type-options",
    "x-frame-options",
    "referrer-policy",
)

FRAME_PROBE = r"""
(() => {
    window.__gfFrames = [];
    let count = 0;
    const rect = (selector) => {
        const element = document.querySelector(selector);
        if (!element) return null;
        const bounds = element.getBoundingClientRect();
        return { x: bounds.x, width: bounds.width };
    };
    const sample = () => {
        window.__gfFrames.push({
            state: document.documentElement.getAttribute('data-gf-sidebar-state'),
            sidebar: rect('.gf-sidebar'),
            shellBody: rect('.gf-shell__body'),
        });
        count += 1;
        if (count < 40) requestAnimationFrame(sample);
    };
    requestAnimationFrame(sample);
})();
"""


def login(context, base_url: str):
    page = context.new_page()
    page.goto(f"{base_url}/admin/login", wait_until="domcontentloaded")
    page.locator("#username").fill("ui_v3_reviewer")
    page.locator("#password").fill("ui-v3-review-only")
    page.locator("form").evaluate("form => form.requestSubmit()")
    page.wait_for_url("**/admin/dashboard")
    return page


def security_headers_pass(headers: dict[str, str]) -> bool:
    return (
        headers.get("x-content-type-options", "").lower() == "nosniff"
        and headers.get("x-frame-options", "").lower() == "sameorigin"
        and headers.get("referrer-policy", "").lower() == "strict-origin-when-cross-origin"
    )


def reviewed_headers(headers: dict[str, str]) -> dict[str, str]:
    return {name: headers[name] for name in REVIEWED_RESPONSE_HEADERS if name in headers}


def frame_result(page, expected_state: str, expected_sidebar: float, expected_body_x: float) -> dict:
    try:
        page.wait_for_function("() => window.__gfFrames?.length >= 40", timeout=5000)
    except PlaywrightTimeoutError:
        pass

    frames = [frame for frame in page.evaluate("window.__gfFrames") if frame.get("sidebar") and frame.get("shellBody")]
    if not frames:
        return {
            "status": "fail",
            "error": "No measurable shell frame was captured.",
            "first": None,
            "final": None,
            "deltas": None,
        }

    first = frames[0]
    final = frames[-1]
    deltas = {
        "sidebar_width": abs(first["sidebar"]["width"] - final["sidebar"]["width"]),
        "body_x": abs(first["shellBody"]["x"] - final["shellBody"]["x"]),
    }
    passed = (
        len(frames) >= 40
        and first["state"] == expected_state
        and final["state"] == expected_state
        and abs(final["sidebar"]["width"] - expected_sidebar) <= 0.5
        and abs(final["shellBody"]["x"] - expected_body_x) <= 0.5
        and max(deltas.values()) <= 0.5
    )

    return {
        "status": "pass" if passed else "fail",
        "frame_count": len(frames),
        "first": first,
        "final": final,
        "deltas": deltas,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://localhost:28080")
    parser.add_argument("--output", default=str(OUTPUT_ROOT / "navigation-audit.json"))
    parser.add_argument("--screenshots", default=str(OUTPUT_ROOT / "screenshots" / "after"))
    parser.add_argument("--skip-screenshots", action="store_true")
    args = parser.parse_args()
    base_url = args.base_url.rstrip('/')

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    screenshots = Path(args.screenshots)
    if not args.skip_screenshots:
        screenshots.mkdir(parents=True, exist_ok=True)
    checks = []

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        login_context = browser.new_context(viewport={"width": 1440, "height": 1000})
        login_page = login(login_context, base_url)
        login_page.evaluate("key => localStorage.setItem(key, '1')", STORAGE_KEY)
        storage_state = login_context.storage_state()
        login_context.close()

        context = browser.new_context(viewport={"width": 1440, "height": 1000}, storage_state=storage_state)
        context.add_init_script(FRAME_PROBE)
        page = context.new_page()

        for request_path, leaked_marker in (("/index.php", "<?php"), ("/.htaccess", "RewriteEngine")):
            response = context.request.get(f"{base_url}{request_path}", fail_on_status_code=False, max_redirects=0)
            response_text = response.text()
            checks.append({
                "name": f"blocked-source-{request_path.rsplit('/', 1)[-1]}",
                "status": "pass" if response.status in {403, 404} and leaked_marker not in response_text else "fail",
                "request_path": request_path,
                "http_status": response.status,
                "source_marker_exposed": leaked_marker in response_text,
            })

        public_home_response = context.request.get(f"{base_url}/", fail_on_status_code=False, max_redirects=0)
        public_home_text = public_home_response.text()
        checks.append({
            "name": "public-home-through-laravel",
            "status": "pass" if public_home_response.status == 200 and "<?php" not in public_home_text else "fail",
            "request_path": "/",
            "http_status": public_home_response.status,
            "source_marker_exposed": "<?php" in public_home_text,
        })

        dashboard_response = page.goto(f"{base_url}/admin/dashboard", wait_until="domcontentloaded")
        checks.append({"name": "collapsed-new-tab", **frame_result(page, "collapsed", 72, 72)})
        dashboard_headers = dashboard_response.all_headers() if dashboard_response else {}
        checks.append({
            "name": "dynamic-response-security-headers",
            "status": "pass" if security_headers_pass(dashboard_headers) else "fail",
            "headers": reviewed_headers(dashboard_headers),
        })
        if not args.skip_screenshots:
            page.screenshot(path=str(screenshots / "collapsed-dashboard-1440.png"), full_page=False)

        page.reload(wait_until="domcontentloaded")
        checks.append({"name": "collapsed-refresh", **frame_result(page, "collapsed", 72, 72)})
        cached_assets = page.evaluate(
            """() => performance.getEntriesByType('resource')
                .filter(entry => entry.name.includes('/build/assets/'))
                .map(entry => ({ name: entry.name, transferSize: entry.transferSize }))"""
        )
        checks.append({
            "name": "immutable-asset-cache",
            "status": "pass" if cached_assets and all(asset["transferSize"] == 0 for asset in cached_assets) else "fail",
            "assets": cached_assets,
        })
        asset_response = context.request.get(cached_assets[0]["name"], fail_on_status_code=False) if cached_assets else None
        asset_headers = asset_response.headers if asset_response else {}
        asset_cache_control = asset_headers.get("cache-control", "")
        checks.append({
            "name": "immutable-asset-security-headers",
            "status": "pass" if (
                asset_response is not None
                and asset_response.status == 200
                and security_headers_pass(asset_headers)
                and "immutable" in asset_cache_control
                and "max-age=31536000" in asset_cache_control
            ) else "fail",
            "http_status": asset_response.status if asset_response else None,
            "headers": reviewed_headers(asset_headers),
        })

        page.locator('.gf-sidebar__link[href$="/admin/ai-workspace"]').click()
        page.wait_for_url("**/admin/ai-workspace")
        checks.append({"name": "collapsed-cross-page", **frame_result(page, "collapsed", 72, 72)})

        second_page = context.new_page()
        second_page.goto(f"{base_url}/admin/articles", wait_until="domcontentloaded")
        checks.append({"name": "collapsed-second-tab", **frame_result(second_page, "collapsed", 72, 72)})
        second_page.close()

        page.go_back(wait_until="domcontentloaded")
        checks.append({"name": "collapsed-back", **frame_result(page, "collapsed", 72, 72)})
        page.go_forward(wait_until="domcontentloaded")
        checks.append({"name": "collapsed-forward", **frame_result(page, "collapsed", 72, 72)})

        page.locator("[data-sidebar-collapse]").click()
        page.wait_for_function(
            """() => {
                const sidebar = document.querySelector('.gf-sidebar');
                const shellBody = document.querySelector('.gf-shell__body');
                const transitionsFinished = [...(sidebar?.getAnimations() ?? []), ...(shellBody?.getAnimations() ?? [])]
                    .every(animation => animation.playState === 'finished');
                return document.documentElement.getAttribute('data-gf-sidebar-state') === 'expanded'
                    && transitionsFinished
                    && Math.abs(sidebar?.getBoundingClientRect().width - 256) <= 0.5
                    && Math.abs(shellBody?.getBoundingClientRect().x - 256) <= 0.5;
            }""",
            timeout=5000,
        )
        expanded = page.evaluate(
            """() => ({
                state: document.documentElement.getAttribute('data-gf-sidebar-state'),
                sidebarWidth: document.querySelector('.gf-sidebar').getBoundingClientRect().width,
                bodyX: document.querySelector('.gf-shell__body').getBoundingClientRect().x,
                stored: localStorage.getItem('geoflow.admin.ui-v3.sidebar-collapsed'),
            })"""
        )
        checks.append({
            "name": "user-expand",
            "status": "pass" if (
                expanded["state"] == "expanded"
                and abs(expanded["sidebarWidth"] - 256) <= 0.5
                and abs(expanded["bodyX"] - 256) <= 0.5
                and expanded["stored"] == "0"
            ) else "fail",
            "measurement": expanded,
        })
        if not args.skip_screenshots:
            page.screenshot(path=str(screenshots / "expanded-ai-workspace-1440.png"), full_page=False)
        context.close()

        mobile_context = browser.new_context(viewport={"width": 375, "height": 812}, storage_state=storage_state)
        mobile_context.add_init_script(FRAME_PROBE)
        mobile_page = mobile_context.new_page()
        mobile_page.goto(f"{base_url}/admin/dashboard", wait_until="domcontentloaded")
        checks.append({"name": "mobile-collapsed-preference", **frame_result(mobile_page, "collapsed", 256, 0)})
        if not args.skip_screenshots:
            mobile_page.screenshot(path=str(screenshots / "mobile-dashboard-375.png"), full_page=False)
        mobile_context.close()
        browser.close()

    payload = {
        "checks": len(checks),
        "passed": sum(check["status"] == "pass" for check in checks),
        "failed": sum(check["status"] != "pass" for check in checks),
        "results": checks,
    }
    output_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({key: payload[key] for key in ["checks", "passed", "failed"]}, ensure_ascii=False))

    return 1 if payload["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
