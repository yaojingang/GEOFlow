import os

from playwright.sync_api import sync_playwright


port = os.environ.get("GEOFLOW_UI_PORT", "8019")
username = os.environ["GEOFLOW_UI_USERNAME"]
password = os.environ["GEOFLOW_UI_PASSWORD"]
primary = f"http://primary.test:{port}"
hosted = f"http://alpha.sites.test:{port}"
unknown = f"http://unknown.sites.test:{port}"

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        headless=True,
        args=[
            "--host-resolver-rules=MAP primary.test 127.0.0.1, MAP alpha.sites.test 127.0.0.1, MAP unknown.sites.test 127.0.0.1",
            "--proxy-server=direct://",
            "--proxy-bypass-list=*",
        ],
    )
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()
    console_errors = []
    page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)

    response = page.goto(primary + "/geo_admin/login")
    assert response is not None and response.status == 200
    page.wait_for_load_state("networkidle")
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    with page.expect_navigation():
        page.locator('button[type="submit"]').click()

    response = page.goto(primary + "/geo_admin/distribution/hosted-sites")
    assert response is not None and response.status == 200
    page.wait_for_load_state("networkidle")
    assert page.get_by_role("heading", name="托管渠道站点").is_visible()
    page.get_by_role("link", name="查看详情", exact=True).click()
    page.wait_for_load_state("networkidle")
    assert page.get_by_role("heading", name="Alpha 托管站").is_visible()
    assert page.locator("body").evaluate("node => node.scrollWidth <= node.clientWidth")
    page.screenshot(path="/tmp/geoflow-hosted-sites-desktop.png", full_page=True)

    page.set_viewport_size({"width": 375, "height": 812})
    page.reload()
    page.wait_for_load_state("networkidle")
    assert page.get_by_role("heading", name="Alpha 托管站").is_visible()
    assert page.locator("body").evaluate("node => node.scrollWidth <= node.clientWidth")
    page.screenshot(path="/tmp/geoflow-hosted-sites-mobile.png", full_page=True)
    assert console_errors == []

    response = page.goto(hosted + "/geo_admin/login")
    assert response is not None and response.status == 404
    response = page.goto(hosted + "/")
    assert response is not None and response.status == 503
    response = page.goto(unknown + "/")
    assert response is not None and response.status == 404

    browser.close()
