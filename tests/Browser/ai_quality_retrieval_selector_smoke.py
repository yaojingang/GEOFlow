import os
from pathlib import Path

from playwright.sync_api import sync_playwright


BASE_URL = os.environ.get('AI_QUALITY_BROWSER_BASE_URL', 'http://127.0.0.1:18081').rstrip('/')
ADMIN_PATH = os.environ.get('AI_QUALITY_BROWSER_ADMIN_PATH', 'admin').strip('/')
USERNAME = os.environ.get('AI_QUALITY_BROWSER_USERNAME', 'ui_v3_reviewer')
PASSWORD = os.environ.get('AI_QUALITY_BROWSER_PASSWORD', 'ui-v3-review-only')
OUTPUT_DIR = Path(os.environ.get('AI_QUALITY_BROWSER_OUTPUT_DIR', '/tmp/geoflow-ai-quality-ui'))


def login(page) -> None:
    page.goto(f'{BASE_URL}/{ADMIN_PATH}/login')
    page.wait_for_load_state('networkidle')
    page.locator('input[name="username"]').fill(USERNAME)
    page.locator('input[name="password"]').fill(PASSWORD)
    page.get_by_role('button', name='登录').click()
    page.wait_for_load_state('networkidle')


def selector_layout(page, selector: str) -> dict:
    return page.locator(selector).evaluate(
        """root => {
            const rootRect = root.getBoundingClientRect();
            const cards = [...root.querySelectorAll('[data-retrieval-mode-card]')];
            return {
                root: {
                    left: rootRect.left,
                    right: rootRect.right,
                    width: rootRect.width,
                    clientWidth: root.clientWidth,
                    scrollWidth: root.scrollWidth,
                },
                cards: cards.map(card => {
                    const rect = card.getBoundingClientRect();
                    const input = card.querySelector('[data-retrieval-mode-input]');
                    return {
                        mode: card.dataset.mode,
                        left: rect.left,
                        right: rect.right,
                        top: Math.round(rect.top),
                        width: rect.width,
                        height: rect.height,
                        checked: Boolean(input?.checked),
                        disabled: Boolean(input?.disabled),
                        status: card.querySelector('[data-retrieval-mode-status]')?.textContent.trim() || '',
                        helpExpanded: card.querySelector('[data-retrieval-mode-help-trigger]')?.getAttribute('aria-expanded') || '',
                        helpHidden: Boolean(card.querySelector('[data-retrieval-mode-help-panel]')?.hidden),
                    };
                }),
            };
        }"""
    )


def assert_selector(layout: dict, expected_cards: int, desktop: bool) -> None:
    assert len(layout['cards']) == expected_cards, layout
    assert layout['root']['scrollWidth'] <= layout['root']['clientWidth'] + 1, layout
    for card in layout['cards']:
        assert card['height'] >= 40, card
        assert card['height'] <= 96, card
        assert card['left'] >= layout['root']['left'] - 1, layout
        assert card['right'] <= layout['root']['right'] + 1, layout
        assert card['helpExpanded'] == 'false', card
        assert card['helpHidden'], card
        if card['mode']:
            assert card['status'] in {'可用', '暂不可用'}, card

    rows = {card['top'] for card in layout['cards']}
    if desktop:
        assert len(rows) == 1, layout
    else:
        assert len(rows) == expected_cards, layout


def assert_help_popover(page, selector: str) -> None:
    root = page.locator(selector)
    cards = root.locator('[data-retrieval-mode-card]')
    target = cards.first
    for index in range(cards.count()):
        candidate = cards.nth(index)
        if candidate.locator('[data-retrieval-mode-input]').is_disabled():
            target = candidate
            break

    trigger = target.locator('[data-retrieval-mode-help-trigger]')
    panel_id = trigger.get_attribute('aria-controls')
    panel = page.locator(f'#{panel_id}')
    checked_before = root.locator('[data-retrieval-mode-input]:checked').count()
    touched = root.locator('[data-retrieval-mode-touched]')
    touched_before = touched.input_value()
    height_before = target.evaluate('card => card.getBoundingClientRect().height')

    assert trigger.is_enabled()
    assert panel.is_hidden()
    trigger.click()
    assert trigger.get_attribute('aria-expanded') == 'true'
    assert panel.is_visible()
    assert panel.inner_text().strip()
    assert root.locator('[data-retrieval-mode-input]:checked').count() == checked_before
    assert touched.input_value() == touched_before
    assert target.evaluate('card => card.getBoundingClientRect().height') == height_before

    if target.locator('[data-retrieval-mode-input]').is_disabled() and target.get_attribute('data-mode'):
        assert target.locator('[data-retrieval-mode-blockers-wrapper]').is_visible()
        assert target.locator('[data-retrieval-mode-blockers]').inner_text().strip()

    trigger.press('Escape')
    assert trigger.get_attribute('aria-expanded') == 'false'
    assert panel.is_hidden()
    assert trigger.evaluate('element => document.activeElement === element')

    triggers = root.locator('[data-retrieval-mode-help-trigger]')
    first = triggers.first
    second = triggers.nth(1)
    first_panel = page.locator(f'#{first.get_attribute("aria-controls")}')
    second_panel = page.locator(f'#{second.get_attribute("aria-controls")}')
    first.click()
    second.click()
    assert first_panel.is_hidden()
    assert first.get_attribute('aria-expanded') == 'false'
    assert second_panel.is_visible()
    root.locator('legend').click()
    assert second_panel.is_hidden()
    assert second.get_attribute('aria-expanded') == 'false'


def inspect_view(browser, viewport: dict, suffix: str, desktop: bool) -> None:
    context = browser.new_context(viewport=viewport, locale='zh-CN')
    page = context.new_page()
    errors = []
    page.on('console', lambda message: errors.append(f'console:{message.type}:{message.text}') if message.type == 'error' else None)
    page.on('pageerror', lambda error: errors.append(f'page:{error}'))

    login(page)

    page.goto(f'{BASE_URL}/{ADMIN_PATH}/tasks/1/edit')
    page.wait_for_load_state('networkidle')
    quality_toggle = page.locator('[data-ai-quality-toggle]')
    if quality_toggle.count() == 1 and not quality_toggle.is_checked():
        quality_toggle.evaluate(
            """input => {
                input.checked = true;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }"""
        )
        page.locator('[data-ai-quality-settings]').wait_for(state='visible')
    page.locator('#task-ai-quality-retrieval-mode').scroll_into_view_if_needed()
    task_layout = selector_layout(page, '#task-ai-quality-retrieval-mode')
    assert_selector(task_layout, 3, desktop)
    assert page.locator('#task-ai-quality-retrieval-mode legend').inner_text().strip() == '质检方式'
    checked_count = page.locator('#task-ai-quality-retrieval-mode [data-retrieval-mode-input]:checked').count()
    if any(not card['disabled'] for card in task_layout['cards']):
        assert checked_count == 1, task_layout
    else:
        assert checked_count == 0, task_layout
        assert all(card['status'] for card in task_layout['cards']), task_layout
    assert_help_popover(page, '#task-ai-quality-retrieval-mode')
    page.screenshot(path=OUTPUT_DIR / f'task-{suffix}.png', full_page=True)

    page.goto(f'{BASE_URL}/{ADMIN_PATH}/articles/1/edit')
    page.wait_for_load_state('networkidle')
    page.locator('#article-ai-quality-retrieval-mode').scroll_into_view_if_needed()
    article_layout = selector_layout(page, '#article-ai-quality-retrieval-mode')
    assert_selector(article_layout, 4, desktop)
    assert page.locator('#article-ai-quality-retrieval-mode [data-mode=""] input').is_checked()
    assert '知识库由任务配置提供' in page.locator('#article-ai-quality-retrieval-mode').inner_text()
    assert_help_popover(page, '#article-ai-quality-retrieval-mode')
    page.screenshot(path=OUTPUT_DIR / f'article-{suffix}.png', full_page=True)

    assert errors == [], errors
    context.close()


OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
with sync_playwright() as playwright:
    chromium = playwright.chromium.launch(headless=True)
    inspect_view(chromium, {'width': 1440, 'height': 1000}, 'desktop', True)
    inspect_view(chromium, {'width': 390, 'height': 844}, 'mobile', False)
    chromium.close()
