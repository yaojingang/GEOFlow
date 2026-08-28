import os
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


def measure_layout(page) -> dict:
    return page.locator('[data-article-list-table]').evaluate(
        """table => {
            const wrapper = table.parentElement;
            const headers = [...table.querySelectorAll('thead th')].filter(
                cell => getComputedStyle(cell).display !== 'none'
            );
            const cells = [...table.querySelectorAll('tbody tr:first-child td')].filter(
                cell => getComputedStyle(cell).display !== 'none'
            );
            const box = element => {
                const rect = element.getBoundingClientRect();
                const style = getComputedStyle(element);
                return {
                    left: Math.round(rect.left),
                    right: Math.round(rect.right),
                    width: Math.round(rect.width),
                    client_width: element.clientWidth,
                    scroll_width: element.scrollWidth,
                    content_left: Math.round(rect.left + parseFloat(style.paddingLeft || 0)),
                    content_right: Math.round(rect.right - parseFloat(style.paddingRight || 0)),
                };
            };
            const columnOrder = headers.length === 8
                ? ['select', 'id', 'info', 'task', 'workflow', 'ai_quality', 'created_at', 'actions']
                : ['id', 'info', 'task', 'workflow', 'ai_quality', 'created_at', 'actions'];
            const actionCell = cells.at(-1);
            const actionGroup = actionCell?.firstElementChild;
            const workflowCell = cells.at(-4);
            const timeCell = cells.at(-2);

            return {
                viewport_width: window.innerWidth,
                root_width: document.documentElement.scrollWidth,
                wrapper: box(wrapper),
                table: box(table),
                row_count: table.querySelectorAll('tbody tr').length,
                headers: headers.map((header, index) => ({
                    column: columnOrder[index],
                    label: header.textContent.trim(),
                    ...box(header),
                })),
                cells: cells.map((cell, index) => ({
                    column: columnOrder[index],
                    ...box(cell),
                })),
                workflow_badges: workflowCell
                    ? [...workflowCell.firstElementChild.children].map(box)
                    : [],
                time_lines: timeCell ? [...timeCell.children].map(box) : [],
                action_cell: actionCell ? {
                    ...box(actionCell),
                    background: getComputedStyle(actionCell).backgroundColor,
                } : null,
                action_group: actionGroup ? box(actionGroup) : null,
            };
        }"""
    )


def assert_desktop_layout(layout: dict) -> None:
    headers = {header['column']: header for header in layout['headers']}
    cells = {cell['column']: cell for cell in layout['cells']}

    assert layout['table']['scroll_width'] <= layout['wrapper']['client_width'] + 1, layout
    assert headers['created_at']['right'] <= headers['actions']['left'] + 1, headers
    assert headers['id']['width'] <= 72, headers['id']
    assert headers['task']['width'] <= 160, headers['task']
    assert headers['workflow']['width'] <= 144, headers['workflow']
    assert headers['ai_quality']['width'] <= 144, headers['ai_quality']
    assert headers['created_at']['width'] <= 160, headers['created_at']
    assert headers['actions']['width'] <= 144, headers['actions']
    assert layout['row_count'] > 0, 'article list layout smoke requires at least one seeded article'
    assert layout['action_group']['scroll_width'] <= layout['action_group']['client_width'], layout

    for badge in layout['workflow_badges']:
        assert badge['left'] >= cells['workflow']['content_left'] - 1, layout
        assert badge['right'] <= cells['workflow']['content_right'] + 1, layout
        assert badge['scroll_width'] <= badge['client_width'], layout

    for line in layout['time_lines']:
        assert line['left'] >= cells['created_at']['content_left'] - 1, layout
        assert line['right'] <= cells['created_at']['content_right'] + 1, layout
        assert line['scroll_width'] <= line['client_width'], layout


base_url = os.environ.get('ARTICLE_LIST_BROWSER_BASE_URL', 'http://localhost:18080').rstrip('/')
admin_path = environment_value('ADMIN_BASE_PATH', 'admin').strip('/')
username = os.environ.get('ARTICLE_LIST_BROWSER_USERNAME') or environment_value('GEOFLOW_ADMIN_USERNAME', 'admin')
password = os.environ.get('ARTICLE_LIST_BROWSER_PASSWORD') or environment_value('GEOFLOW_ADMIN_PASSWORD', 'password')

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1512, 'height': 900})
    page = context.new_page()
    console_errors = []
    page.on('console', lambda message: console_errors.append(message.text) if message.type == 'error' else None)
    page.on('pageerror', lambda error: console_errors.append(str(error)))

    response = page.goto(f'{base_url}/{admin_path}/articles', wait_until='networkidle')
    assert response is not None
    if page.locator('input[name="username"]').count() == 1:
        page.locator('input[name="username"]').fill(username)
        page.locator('input[name="password"]').fill(password)
        with page.expect_navigation():
            page.locator('button[type="submit"]').click()

    response = page.goto(f'{base_url}/{admin_path}/articles', wait_until='networkidle')
    assert response is not None and response.status == 200
    table = page.locator('[data-article-list-table]')
    assert table.count() == 1, {
        'url': page.url,
        'title': page.title(),
        'heading': page.locator('h1').first.inner_text() if page.locator('h1').count() else '',
    }

    desktop = measure_layout(page)
    assert_desktop_layout(desktop)

    page.locator('button[onclick="toggleBatchActions()"]').first.click()
    page.wait_for_timeout(100)
    assert_desktop_layout(measure_layout(page))
    page.locator('button[onclick="toggleBatchActions()"]').first.click()

    table.scroll_into_view_if_needed()
    table.locator('xpath=..').screenshot(path='/tmp/geoflow-article-list-layout-1512.png')

    for locale in ('en', 'pt_BR'):
        response = page.goto(f'{base_url}/{admin_path}/locale/{locale}', wait_until='networkidle')
        assert response is not None and response.status == 200
        response = page.goto(f'{base_url}/{admin_path}/articles', wait_until='networkidle')
        assert response is not None and response.status == 200
        assert_desktop_layout(measure_layout(page))

    page.set_viewport_size({'width': 1440, 'height': 900})
    page.wait_for_timeout(100)
    compact_desktop = measure_layout(page)
    assert_desktop_layout(compact_desktop)

    page.set_viewport_size({'width': 1280, 'height': 900})
    page.wait_for_timeout(100)
    narrow = measure_layout(page)
    assert narrow['table']['scroll_width'] > narrow['wrapper']['client_width'], narrow
    assert narrow['action_cell']['background'] not in {'rgba(0, 0, 0, 0)', 'transparent'}, narrow
    assert narrow['action_group']['scroll_width'] <= narrow['action_group']['client_width'], narrow
    assert narrow['root_width'] <= narrow['viewport_width'], narrow
    table.scroll_into_view_if_needed()
    table.locator('xpath=..').screenshot(path='/tmp/geoflow-article-list-layout-1280.png')

    page.set_viewport_size({'width': 375, 'height': 812})
    page.wait_for_timeout(100)
    mobile = measure_layout(page)
    assert mobile['table']['scroll_width'] > mobile['wrapper']['client_width'], mobile
    assert mobile['action_cell']['background'] not in {'rgba(0, 0, 0, 0)', 'transparent'}, mobile
    assert mobile['root_width'] <= mobile['viewport_width'], mobile

    assert console_errors == []
    browser.close()
