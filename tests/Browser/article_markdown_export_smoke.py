import os
import zipfile
from pathlib import Path

from playwright.sync_api import sync_playwright


def environment_value(name: str, default: str) -> str:
    configured = os.environ.get(name)
    if configured:
        return configured

    environment = Path('.env')
    if environment.is_file():
        for line in environment.read_text(encoding='utf-8').splitlines():
            if line.startswith(f'{name}='):
                return line.split('=', 1)[1].strip().strip('"\'') or default

    return default


base_url = os.environ.get('ARTICLE_EXPORT_BROWSER_BASE_URL', 'http://localhost:18080').rstrip('/')
admin_path = environment_value('ADMIN_BASE_PATH', 'admin').strip('/')
username = environment_value('GEOFLOW_ADMIN_USERNAME', 'admin')
password = environment_value('GEOFLOW_ADMIN_PASSWORD', 'password')

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    page = context.new_page()
    console_errors = []
    page.on('console', lambda message: console_errors.append(message.text) if message.type == 'error' else None)

    response = page.goto(f'{base_url}/{admin_path}/articles')
    assert response is not None
    page.wait_for_load_state('networkidle')
    if page.locator('input[name="username"]').count() == 1:
        page.locator('input[name="username"]').fill(username)
        page.locator('input[name="password"]').fill(password)
        with page.expect_navigation():
            page.locator('button[type="submit"]').click()

    response = page.goto(f'{base_url}/{admin_path}/articles')
    assert response is not None and response.status == 200
    page.wait_for_load_state('networkidle')

    export_option = page.locator('[data-article-batch-export-option]')
    assert export_option.count() == 1 and export_option.is_enabled(), {
        'count': export_option.count(),
        'disabled': export_option.is_disabled() if export_option.count() == 1 else None,
        'console_errors': console_errors,
        'scripts': page.locator('script[src]').evaluate_all('nodes => nodes.map(node => node.src)'),
    }
    page.evaluate('toggleBatchActions()')
    checkboxes = page.locator('.article-checkbox')
    assert checkboxes.count() >= 2
    checkboxes.nth(0).check()
    checkboxes.nth(1).check()
    page.locator('#batch-action').select_option('export_markdown')

    execute = page.locator('[data-batch-execute]')
    with page.expect_download(timeout=300_000) as first_download:
        execute.click()
        page.locator('[data-article-batch-export]').wait_for(state='visible')
    download = first_download.value
    page.locator('[data-export-state="success"]:not([hidden])').wait_for(timeout=300_000)
    archive_path = download.path()
    assert archive_path is not None
    with zipfile.ZipFile(archive_path) as archive:
        entries = archive.namelist()
        assert len(entries) == 2
        assert all(entry.endswith('.md') for entry in entries)

    with page.expect_download(timeout=30_000) as retry_download:
        page.locator('[data-export-retry]').click()
    assert retry_download.value.suggested_filename.endswith('.zip')
    page.locator('[data-export-close]').first.click()
    assert execute.evaluate('element => document.activeElement === element')
    page.screenshot(path='/tmp/geoflow-article-export-review.png', full_page=True)
    assert console_errors == []

    no_script_page = context.new_page()
    no_script_page.route('**/*.js*', lambda route: route.abort())
    response = no_script_page.goto(f'{base_url}/{admin_path}/articles')
    assert response is not None and response.status == 200
    no_script_page.wait_for_load_state('networkidle')
    disabled_option = no_script_page.locator('[data-article-batch-export-option]')
    assert disabled_option.count() == 1 and disabled_option.is_disabled()

    browser.close()
