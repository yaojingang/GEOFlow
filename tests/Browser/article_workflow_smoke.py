"""Real Laravel HTTP smoke test with an ephemeral database and a private Chromium.

Run after composer install, npm ci, npm run build, and installing Playwright Chromium:
    python3 tests/Browser/article_workflow_smoke.py --output-dir /tmp/geoflow-workflow-proof
No project .env, user browser, or existing database is used.
"""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.parse import parse_qs, urlparse
from urllib.request import urlopen

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ADMIN = "geo_browser"


def database_row(database, table, row_id):
    assert table in {"articles", "tasks"}
    with sqlite3.connect(database) as connection:
        connection.row_factory = sqlite3.Row
        return dict(connection.execute(f"SELECT * FROM {table} WHERE id = ?", (row_id,)).fetchone())


def run_browser(base_url, database, fixture, output):
    evidence = {"checks": [], "requests": [], "screenshots": []}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 1100}, locale="zh-CN", service_workers="block")
        # Block browser-side external traffic, without substituting application responses.
        context.route("**/*", lambda route: route.continue_() if urlparse(route.request.url).hostname == "127.0.0.1" else route.abort())
        page = context.new_page()
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))

        def record(request):
            path = urlparse(request.url).path
            if request.method == "POST" and ("/articles/" in path or "/tasks/" in path):
                body = parse_qs(request.post_data or "", keep_blank_values=True)
                body.pop("_token", None)
                evidence["requests"].append({"path": path, "method": request.method, "body": body})

        context.on("request", record)

        def goto(path):
            response = page.goto(base_url + path)
            assert response and response.status == 200, (path, response.status if response else None)
            page.wait_for_load_state("networkidle")

        def screenshot(name):
            file = output / name
            page.screenshot(path=str(file), full_page=True, animations="disabled")
            evidence["screenshots"].append(str(file))

        def check(name):
            evidence["checks"].append(name)

        def open_batch(ids):
            goto(f"/{ADMIN}/articles")
            page.locator('button[onclick="toggleBatchActions()"]').first.click()
            for row_id in ids:
                page.locator(f'.article-checkbox[value="{row_id}"]').check()

        def submit_batch():
            with page.expect_navigation():
                page.locator('[data-batch-execute]').click()
            page.wait_for_load_state("networkidle")

        try:
            goto(f"/{ADMIN}/login")
            page.locator('input[name="username"]').fill("browser_workflow")
            page.locator('input[name="password"]').fill("browser-workflow-test-only")
            with page.expect_navigation():
                page.locator('button[type="submit"]').click()
            page.wait_for_load_state("networkidle")
            ids = fixture["article_ids"]

            open_batch([])
            expect(page.locator('[data-batch-execute]')).to_be_disabled()
            expect(page.locator('#select-all')).to_have_attribute("title", "选择当前页")
            screenshot("01-empty-selection.png")
            check("zero selection disables execution; select-all explicitly covers the current page")

            page.locator(f'.article-checkbox[value="{ids[0]}"]').check()
            page.locator('#batch-action').select_option('batch_update_review')
            page.locator('#review-select').select_option('approved')
            before = len(evidence["requests"])
            submit_batch()
            assert len(evidence["requests"]) == before + 1
            assert evidence["requests"][-1]["body"]["article_ids[]"] == [str(ids[0])]
            assert database_row(database, "articles", ids[0])["review_status"] == "approved"
            assert database_row(database, "articles", ids[0])["status"] == "draft"
            check("first review click makes exactly one real POST and records approval without publishing")

            open_batch([ids[1]])
            page.locator('#batch-action').select_option('delete_articles')
            before = len(evidence["requests"])
            page.locator('[data-batch-execute]').click()
            expect(page.locator('[data-admin-action-dialog]')).to_be_visible()
            page.locator('[data-admin-action-cancel]').click()
            expect(page.locator('[data-admin-action-dialog]')).not_to_be_visible()
            assert len(evidence["requests"]) == before
            assert page.locator('#batch-selected-ids input').count() == 0
            assert database_row(database, "articles", ids[1])["deleted_at"] is None
            check("cancelled delete sends no POST, clears hidden payload, and leaves the article intact")
            page.locator('button[onclick="toggleBatchActions()"]').last.click()
            expect(page.locator('.article-checkbox:checked')).to_have_count(0)

            open_batch([ids[1]])
            page.locator('#batch-action').select_option('batch_update_review')
            page.locator('#review-select').select_option('rejected')
            before = len(evidence["requests"])
            submit_batch()
            assert len(evidence["requests"]) == before + 1
            assert evidence["requests"][-1]["body"]["article_ids[]"] == [str(ids[1])]
            assert database_row(database, "articles", ids[0])["review_status"] == "approved"
            assert database_row(database, "articles", ids[1])["review_status"] == "rejected"
            check("a fresh selection submits only its ID; the previous article stays unchanged")

            open_batch([ids[0]])
            page.locator('#batch-action').select_option('batch_update_status')
            page.locator('#status-select').select_option('draft')
            before = len(evidence["requests"])
            with page.expect_navigation():
                page.locator('[data-batch-execute]').dblclick()
            page.wait_for_load_state("networkidle")
            assert len(evidence["requests"]) == before + 1
            assert database_row(database, "articles", ids[0])["publication_intent"] == "hold"
            check("a physical double click sends exactly one status POST")

            open_batch([ids[2], ids[3]])
            page.locator('#batch-action').select_option('batch_update_status')
            page.locator('#status-select').select_option('published')
            submit_batch()
            details = page.locator('details').filter(has=page.get_by_text('批量操作结果', exact=True))
            if details.get_attribute('open') is None:
                details.locator('summary').click()
            text = details.inner_text()
            assert str(ids[2]) in text and str(ids[3]) in text and "被阻止" in text and "成功" in text, text
            expect(details.locator('li')).to_have_count(2)
            expect(page.locator('[data-admin-notice-message]')).to_contain_text('本次 2 篇：成功 1，无需变更 0，被阻止 1，版本冲突 0，失败 0。')
            assert database_row(database, "articles", ids[2])["status"] == "published"
            assert database_row(database, "articles", ids[3])["status"] == "draft"
            assert database_row(database, "articles", ids[3])["review_status"] == "pending"
            screenshot("02-per-article-results.png")
            check("mixed publish shows real success and blocked outcomes and persists matching article states")

            task_id = fixture["task_id"]
            task_url = f"/{ADMIN}/tasks/{task_id}/edit"

            def save_task():
                with page.expect_navigation():
                    page.locator('[data-task-form-submit]').click()
                page.wait_for_load_state("networkidle")
                assert '/edit' not in urlparse(page.url).path, page.locator('body').inner_text()[:1500]

            goto(task_url)
            expect(page.locator('#publish_interval')).to_have_value('15')
            revision = page.locator('[name="task_revision"]').input_value()
            page.locator('#need_review').check()
            expect(page.locator('#publish_interval')).to_be_enabled()
            expect(page.locator('#publish_interval')).to_have_value('15')
            impact = page.locator('[data-workflow-impact]').inner_text()
            assert '1 篇' in impact and '停止发布' in impact, impact
            save_task()
            stored = database_row(database, "tasks", task_id)
            assert stored['publish_interval'] == 900 and stored['need_review'] == 1, stored
            check("enabling manual review preserves the 15-minute interval in the submitted form and database")

            goto(task_url)
            assert page.locator('[name="task_revision"]').input_value() != revision
            expect(page.locator('#need_review')).to_be_checked()
            expect(page.locator('#publish_interval')).to_have_value('15')
            page.locator('#task_name').fill('Browser workflow task saved twice')
            save_task()
            assert database_row(database, "tasks", task_id)['name'] == 'Browser workflow task saved twice'
            assert database_row(database, "tasks", task_id)['publish_interval'] == 900
            check("a new GET obtains a usable revision; the second save succeeds and keeps 15 minutes")

            goto(task_url)
            quality = page.locator('[data-ai-quality-toggle]')
            optimize = page.locator('[data-ai-quality-optimization-toggle]')
            sampling = page.locator('[data-ai-quality-timeout-sampling]')
            expect(quality).not_to_be_checked()
            expect(page.locator('[data-ai-quality-settings]')).not_to_be_visible()
            expect(optimize).to_be_disabled()
            expect(sampling).to_be_disabled()
            expect(page.locator('[data-ai-quality-workflow-inspect]').first).not_to_be_visible()
            quality.locator('..').click()
            expect(quality).to_be_checked()
            expect(page.locator('[data-ai-quality-settings]')).to_be_visible()
            expect(optimize).to_be_enabled()
            page.locator('[data-retrieval-mode-input][value="knowledge_broad"]').check()
            expect(sampling).to_be_disabled()  # Full-text inspection has no timeout sampling.
            page.locator('[data-retrieval-mode-input][value="chunk"]').check()
            expect(sampling).to_be_enabled()
            sampling.check()
            expect(sampling).to_be_checked()
            optimize.locator('..').click()
            expect(optimize).to_be_checked()
            expect(sampling).to_be_disabled()
            expect(sampling).not_to_be_checked()
            expect(page.locator('[data-ai-quality-optimization-level]:checked')).to_be_enabled()
            expect(page.locator('[data-ai-quality-workflow-optimization]').first).to_be_visible()
            screenshot("03-task-quality-on.png")
            save_task()
            stored = database_row(database, "tasks", task_id)
            assert stored['ai_quality_enabled'] == 1 and stored['ai_quality_auto_optimize_enabled'] == 1
            assert stored['ai_quality_timeout_sampling_enabled'] == 0
            check("AI-on enables optimization, preserves the mutually exclusive sampling rule, and saves the selected policy")

            goto(task_url)
            page.locator('[data-ai-quality-toggle]').locator('..').click()
            expect(page.locator('[data-ai-quality-optimization-toggle]')).to_be_disabled()
            expect(page.locator('[data-ai-quality-optimization-toggle]')).not_to_be_checked()
            expect(page.locator('[data-ai-quality-timeout-sampling]')).not_to_be_checked()
            expect(page.locator('[data-ai-quality-workflow-inspect]').first).not_to_be_visible()
            expect(page.locator('[data-workflow-impact]')).to_contain_text('关闭 AI 后仍需满足基础风险')
            screenshot("04-task-quality-off-impact.png")
            save_task()
            stored = database_row(database, "tasks", task_id)
            assert stored['ai_quality_enabled'] == 0 and stored['ai_quality_auto_optimize_enabled'] == 0
            assert stored['ai_quality_timeout_sampling_enabled'] == 0 and stored['publish_interval'] == 900
            assert database_row(database, "articles", ids[4])['publication_intent'] == 'hold'
            check("AI-off clears dependent actions, retains 15 minutes, shows impact, and preserves held articles")
            goto(f"/{ADMIN}/articles")
            badge = page.locator('[data-ai-quality-score-badge="90"]')
            expect(badge).to_contain_text('90')
            expect(badge).to_contain_text('通过')
            expect(badge.locator('..')).to_contain_text('85')
            screenshot("05-quality-score-decision.png")
            badge.click()
            page.wait_for_load_state("networkidle")
            expect(page.locator('#ai-quality-result')).to_contain_text('证据覆盖不足')
            expect(page.locator('#ai-quality-result')).to_contain_text('10 分')
            screenshot("06-quality-score-explanation.png")
            check("quality list shows score, decision and threshold; details explain evidence deductions")
            assert not errors, errors
        except Exception:
            screenshot("failure.png")
            (output / 'failure.html').write_text(page.content(), encoding='utf-8')
            raise
        finally:
            evidence["page_errors"] = errors
            (output / 'browser-evidence.json').write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding='utf-8')
            context.close()
            browser.close()
    return evidence


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', type=Path, default=Path(tempfile.gettempdir()) / 'geoflow-workflow-browser-proof')
    args = parser.parse_args()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    assert (ROOT / 'public/build/manifest.json').is_file(), 'Build assets before running this test.'
    # A dedicated lifecycle is needed to share the guarded ephemeral DB across fixture and HTTP processes.
    with tempfile.TemporaryDirectory(prefix='geoflow-workflow-browser-') as directory:
        temporary = Path(directory).resolve()
        (temporary / '.browser-test-only').touch()
        database = temporary / 'test.sqlite'
        database.touch()
        for child in ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/private']:
            (temporary / 'storage' / child).mkdir(parents=True, exist_ok=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base_url = f'http://127.0.0.1:{port}'
        environment = dict(os.environ, APP_ENV='testing', APP_DEBUG='false', APP_KEY='base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
            APP_URL=base_url, ASSET_URL=base_url, APP_LOCALE='zh_CN', DB_CONNECTION='sqlite', DB_DATABASE=str(database), DB_URL='',
            GEOFLOW_BROWSER_TEST_ROOT=str(temporary), ADMIN_BASE_PATH=ADMIN, CACHE_STORE='array', SESSION_DRIVER='file',
            SESSION_COOKIE='geoflow_browser_workflow', SESSION_SECURE_COOKIE='false', SESSION_DOMAIN='', QUEUE_CONNECTION='null',
            BROADCAST_CONNECTION='null', MAIL_MAILER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
            GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED='true', GEOFLOW_ADMIN_UI_V3_ENABLED='false',
            GEOFLOW_UPDATE_CHECK_ENABLED='false', GEOFLOW_ADMIN_EMAIL='fixture@example.test', GEOFLOW_ADMIN_PASSWORD='test-only-seed',
            GEOFLOW_AI_QUALITY_OPTIMIZATION_ENABLED='true', GEOFLOW_AI_QUALITY_OPTIMIZATION_AUTO_APPLY_ENABLED='true',
            GEOFLOW_AI_QUALITY_OPTIMIZATION_PERCENT='100', GEOFLOW_AI_QUALITY_OPTIMIZATION_AUTO_APPLY_PERCENT='100',
            APP_CONFIG_CACHE=str(temporary / 'config.php'), APP_ROUTES_CACHE=str(temporary / 'routes.php'),
            APP_EVENTS_CACHE=str(temporary / 'events.php'), APP_SERVICES_CACHE=str(temporary / 'services.php'),
            APP_PACKAGES_CACHE=str(temporary / 'packages.php'), PULSE_ENABLED='false', TELESCOPE_ENABLED='false', NIGHTWATCH_ENABLED='false')
        fixture_process = subprocess.run(['php', 'tests/Browser/article_workflow_fixture.php'], cwd=ROOT, env=environment, text=True, capture_output=True, timeout=120)
        (output / 'fixture.log').write_text(fixture_process.stdout + fixture_process.stderr, encoding='utf-8')
        assert fixture_process.returncode == 0, fixture_process.stdout + fixture_process.stderr
        fixture = json.loads(fixture_process.stdout.strip().splitlines()[-1])
        with (output / 'server.log').open('w') as server_log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'tests/Browser/article_workflow_router.php'], cwd=ROOT, env=environment, stdout=server_log, stderr=subprocess.STDOUT)
            try:
                deadline = time.monotonic() + 30
                while True:
                    if server.poll() is not None:
                        raise RuntimeError('Local PHP server stopped; inspect server.log')
                    try:
                        with urlopen(base_url + '/up', timeout=1) as response:
                            if response.status == 200:
                                break
                    except OSError:
                        if time.monotonic() > deadline:
                            raise RuntimeError('Local PHP server was not ready; inspect server.log')
                        time.sleep(0.1)
                evidence = run_browser(base_url, database, fixture, output)
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
                application_log = temporary / 'storage/logs/laravel.log'
                if application_log.exists():
                    (output / 'application.log').write_bytes(application_log.read_bytes())
        # TemporaryDirectory removes the DB, session files, app key and cache files on success or failure.
    evidence['cleanup'] = {'server_stopped': server.poll() is not None, 'temporary_directory_removed': not temporary.exists()}
    assert all(evidence['cleanup'].values()), evidence['cleanup']
    (output / 'browser-evidence.json').write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({'passed': len(evidence['checks']), 'evidence': str(output / 'browser-evidence.json'), 'screenshots': evidence['screenshots'], 'cleanup': evidence['cleanup']}, ensure_ascii=False))


if __name__ == '__main__':
    main()
