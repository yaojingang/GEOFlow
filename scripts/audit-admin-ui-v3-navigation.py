#!/usr/bin/env python3
"""Exercise UI V3 sidebar persistence and full-page navigation journeys."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from playwright.sync_api import sync_playwright


STORAGE_KEY = "geoflow.admin.ui-v3.sidebar-collapsed"
OUTPUT_ROOT = Path("storage/app/review-artifacts/admin-ui-v3-flicker-review")

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


def frame_result(page, expected_state: str, expected_sidebar: float, expected_body_x: float) -> dict:
    page.wait_for_timeout(750)
    frames = [frame for frame in page.evaluate("window.__gfFrames") if frame.get("sidebar") and frame.get("shellBody")]
    first = frames[0]
    final = frames[-1]
    deltas = {
        "sidebar_width": abs(first["sidebar"]["width"] - final["sidebar"]["width"]),
        "body_x": abs(first["shellBody"]["x"] - final["shellBody"]["x"]),
    }
    passed = (
        first["state"] == expected_state
        and final["state"] == expected_state
        and abs(final["sidebar"]["width"] - expected_sidebar) <= 0.5
        and abs(final["shellBody"]["x"] - expected_body_x) <= 0.5
        and max(deltas.values()) <= 0.5
    )

    return {"status": "pass" if passed else "fail", "first": first, "final": final, "deltas": deltas}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://localhost:28080")
    args = parser.parse_args()
    base_url = args.base_url.rstrip('/')

    OUTPUT_ROOT.mkdir(parents=True, exist_ok=True)
    screenshots = OUTPUT_ROOT / "screenshots" / "after"
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
        page.goto(f"{base_url}/admin/dashboard", wait_until="domcontentloaded")
        checks.append({"name": "collapsed-new-tab", **frame_result(page, "collapsed", 72, 72)})
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
        page.wait_for_timeout(250)
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
            "status": "pass" if expanded == {"state": "expanded", "sidebarWidth": 256, "bodyX": 256, "stored": "0"} else "fail",
            "measurement": expanded,
        })
        page.screenshot(path=str(screenshots / "expanded-ai-workspace-1440.png"), full_page=False)
        context.close()

        mobile_context = browser.new_context(viewport={"width": 375, "height": 812}, storage_state=storage_state)
        mobile_context.add_init_script(FRAME_PROBE)
        mobile_page = mobile_context.new_page()
        mobile_page.goto(f"{base_url}/admin/dashboard", wait_until="domcontentloaded")
        checks.append({"name": "mobile-collapsed-preference", **frame_result(mobile_page, "collapsed", 256, 0)})
        mobile_page.screenshot(path=str(screenshots / "mobile-dashboard-375.png"), full_page=False)
        mobile_context.close()
        browser.close()

    payload = {
        "checks": len(checks),
        "passed": sum(check["status"] == "pass" for check in checks),
        "failed": sum(check["status"] != "pass" for check in checks),
        "results": checks,
    }
    (OUTPUT_ROOT / "navigation-audit.json").write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({key: payload[key] for key in ["checks", "passed", "failed"]}, ensure_ascii=False))

    return 1 if payload["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
