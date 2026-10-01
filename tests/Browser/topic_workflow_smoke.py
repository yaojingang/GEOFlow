"""Verify topic workflows over real Laravel HTTP in disposable databases/private Chromium.

Run with existing build assets and Playwright Chromium:
    python3 tests/Browser/topic_workflow_smoke.py --output-dir /tmp/geoflow-topic-browser-proof
Both admin V3 modes run by default. No project .env, user browser, shared viewport,
production database, or external network is used.
"""
import argparse
import hashlib
import base64
import json
import os
import re
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.parse import urlparse, parse_qs
from urllib.request import urlopen

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ADMIN = "geo_browser"


def database_rows(database, table, where="1 = 1", parameters=()):
    assert table in {"articles", "tasks", "topics", "topic_build_runs", "topic_import_batches", "task_runs", "titles"}
    with sqlite3.connect(database) as connection:
        connection.row_factory = sqlite3.Row
        return [dict(row) for row in connection.execute(f"SELECT * FROM {table} WHERE {where}", parameters).fetchall()]


def database_row(database, table, row_id):
    rows = database_rows(database, table, "id = ?", (row_id,))
    assert len(rows) == 1, (table, row_id, rows)
    return rows[0]


def draft(database, topic_id):
    return json.loads(database_row(database, "topics", topic_id)["draft_payload"])


def run_browser(base_url, database, fixture, output, v3, execute_task):
    evidence = {"admin_v3": v3, "checks": [], "screenshots": [], "blocked_external_requests": []}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 1100}, locale="zh-CN", service_workers="block")
        errors = []

        def guard_network(route):
            if urlparse(route.request.url).netloc == urlparse(base_url).netloc:
                route.continue_()
            else:
                evidence["blocked_external_requests"].append(urlparse(route.request.url).hostname)
                route.abort()

        def capture_errors(page):
            page.on("pageerror", lambda error: errors.append({"url": page.url, "error": str(error)}))

        context.route("**/*", guard_network)
        context.on("page", capture_errors)
        page = context.new_page()
        mobile = None

        def goto(path, status=200):
            response = page.goto(base_url + path)
            assert response and response.status == status, (path, response.status if response else None)
            page.wait_for_load_state("networkidle")

        def navigate_click(locator):
            with page.expect_navigation(wait_until="networkidle"):
                locator.click()
            page.wait_for_load_state("networkidle")

        def check(name, details=None):
            evidence["checks"].append({"name": name, "details": details})
            if len(evidence["checks"]) % 10 == 0:
                print(json.dumps({"admin_v3": v3, "checks_so_far": len(evidence["checks"]), "latest": name}, ensure_ascii=False), flush=True)

        def screenshot(name, target=None):
            file = output / name
            (target or page).screenshot(path=str(file), full_page=True, animations="disabled")
            evidence["screenshots"].append(str(file))

        def save_with_shortcut(shortcut, intro):
            page.locator("#intro").fill(intro)
            with page.expect_navigation(wait_until="networkidle"):
                page.keyboard.press(shortcut)
            expect(page.locator("#intro")).to_have_value(intro)

        try:
            goto(f"/{ADMIN}/login")
            page.locator('[name="username"]').fill("topic_browser")
            page.locator('[name="password"]').fill("topic-browser-test-only")
            navigate_click(page.locator('button[type="submit"]'))
            goto(f"/{ADMIN}/topics")
            assert page.locator("body").evaluate("(body) => body.classList.contains('gf-admin-v3')") == v3
            expect(page.get_by_role("link", name="专题列表", exact=True).first).to_be_visible()
            screenshot("01-topic-list-desktop.png")
            check("topic tab and core content entry render in selected admin mode")

            goto(f"/{ADMIN}/topics/create")
            page.locator("#topic-title").fill("GEO 浏览器实际创建")
            page.locator("#intro").fill("用户手工编辑的导读，返回选文后应继续保留。")
            page.locator('[name="tags_text"]').fill("人工标签")
            page.locator('[name="summary[one_sentence]"]').fill("人工核心摘要")
            page.get_by_text("搜索展示与地址，高级设置", exact=True).click()
            page.locator('[name="seo[title]"]').fill("浏览器搜索展示标题")
            page.locator('[name="seo[description]"]').fill("浏览器填写并保存的搜索描述，与正文标题保持独立。")
            navigate_click(page.locator("[data-topic-picker]"))
            page.locator("[data-topic-choice]").nth(0).check()
            page.locator("[data-topic-choice]").nth(1).check()
            expect(page.locator("[data-selected-count]")).to_have_text("2")
            navigate_click(page.locator("[data-topic-done]").first)
            expect(page.locator("#topic-title")).to_have_value("GEO 浏览器实际创建")
            expect(page.locator("#intro")).to_have_value("用户手工编辑的导读，返回选文后应继续保留。")
            expect(page.locator('[name="tags_text"]')).to_have_value("人工标签")
            expect(page.locator('[name="summary[one_sentence]"]')).to_have_value("人工核心摘要")
            expect(page.locator('[name="seo[title]"]')).to_have_value("浏览器搜索展示标题")
            expect(page.locator('[name="seo[description]"]')).to_have_value("浏览器填写并保存的搜索描述，与正文标题保持独立。")
            cards = page.locator('[data-topic-array="articles"] > div')
            expect(cards).to_have_count(2)
            for card in cards.all():
                expect(card.get_by_role("link", name="查看原文")).to_be_visible()
                assert "GEO 内容研究" in card.inner_text()
                assert re.search(r"\d{4}-\d{2}-\d{2}", card.inner_text())
                assert "本地验收材料" in card.inner_text()
            with context.expect_page() as opened:
                cards.nth(0).get_by_role("link", name="查看原文").click()
            original = opened.value
            try:
                original.wait_for_load_state("networkidle")
                source_id = int(cards.nth(0).locator('[data-column="article_id"]').input_value())
                expect(original.locator("h1").first).to_have_text(database_row(database, "articles", source_id)["title"])
            finally:
                original.close()
            check("source original link opens the actual eligible public article")
            expect(cards.nth(0).get_by_role("button", name="上移", exact=True)).to_be_disabled()
            expect(cards.nth(1).get_by_role("button", name="下移", exact=True)).to_be_disabled()
            check("picker preserves all scalar inputs and source cards show date/category/excerpt/original link")
            move_id = cards.nth(1).locator('[data-column="article_id"]').input_value()
            cards.nth(1).get_by_role("button", name="上移", exact=True).click()
            assert cards.nth(0).locator('[data-column="article_id"]').input_value() == move_id
            assert page.evaluate("document.activeElement.dataset.topicMove") == "down"
            check("source sorting disables boundaries and restores focus on the moved source")
            navigate_click(page.get_by_role("button", name="保存并预览", exact=True))
            assert "/preview" in page.url, page.url
            expect(page.get_by_text("用户手工编辑的导读，返回选文后应继续保留。")).to_be_visible()
            topic_id = int(urlparse(page.url).path.split("/")[-2])
            topic = database_row(database, "topics", topic_id)
            assert topic["public_revision_id"] is None
            check("save and preview stores a private draft with no public pointer")
            screenshot("02-topic-preview.png")
            goto("/topics/" + topic["slug"], 404)
            check("unauthenticated public draft URL returns 404")
            goto(f"/{ADMIN}/topics/{topic_id}/edit")
            save_with_shortcut("Control+s", "Ctrl 保存的导读")
            assert draft(database, topic_id)["intro"] == "Ctrl 保存的导读"
            check("Ctrl+S submits the ordinary draft save action")
            save_with_shortcut("Meta+s", "用户手工编辑的导读，返回选文后应继续保留。")
            assert draft(database, topic_id)["intro"] == "用户手工编辑的导读，返回选文后应继续保留。"
            check("Cmd+S submits the ordinary draft save action")
            before = database_row(database, "topics", topic_id)["draft_version"]
            page.locator("#intro").focus()
            prevented = page.locator("#intro").evaluate("""node => {
                node.dispatchEvent(new CompositionEvent('compositionstart', {bubbles:true, data:'中文'}));
                const event = new KeyboardEvent('keydown', {bubbles:true,cancelable:true,key:'Enter',isComposing:true,keyCode:229});
                node.dispatchEvent(event); return event.defaultPrevented;
            }""")
            page.keyboard.press("Control+s")
            page.wait_for_timeout(150)
            assert prevented and database_row(database, "topics", topic_id)["draft_version"] == before
            page.locator("#intro").evaluate("node=>node.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true,data:'中文'}))")
            check("Chinese IME Enter and save shortcut cannot accidentally submit during composition")
            page.locator("#intro").fill("本轮直接发布当前编辑内容，完整保留用户导读。")
            navigate_click(page.get_by_role("button", name="保存并发布", exact=True))
            assert draft(database, topic_id)["intro"] == "本轮直接发布当前编辑内容，完整保留用户导读。"
            topic = database_row(database, "topics", topic_id)
            assert topic["public_revision_id"] is not None
            goto("/topics/" + topic["slug"])
            expect(page.locator("h1").first).to_have_text("GEO 浏览器实际创建")
            assert page.locator('link[rel="canonical"]').count() == 1
            assert page.title() == "浏览器搜索展示标题"
            expect(page.locator('meta[name="description"]')).to_have_attribute("content", "浏览器填写并保存的搜索描述，与正文标题保持独立。")
            check("editor SEO inputs survive source navigation and render independent head title/description")
            check("publish changes the public pointer and exposes one canonical URL")
            screenshot("03-topic-public-desktop.png")
            goto(f"/{ADMIN}/topics/{topic_id}/edit")
            page.locator("#intro").fill("第二版工作稿，公开页面仍显示上一版导读。")
            navigate_click(page.get_by_role("button", name="保存工作稿", exact=True))
            goto("/topics/" + topic["slug"])
            expect(page.locator(".topic-intro")).to_contain_text("本轮直接发布当前编辑内容")
            check("saving a later work draft leaves the published revision unchanged")
            goto(f"/{ADMIN}/topics/{topic_id}/edit")
            navigate_click(page.get_by_role("button", name="撤回公开版", exact=True))
            assert database_row(database, "topics", topic_id)["public_revision_id"] is None
            goto("/topics/" + topic["slug"], 404)
            check("withdraw removes public access immediately")
            goto(f"/{ADMIN}/topics/{topic_id}/edit")
            navigate_click(page.get_by_role("button", name="移入回收站", exact=True))
            assert database_row(database, "topics", topic_id)["deleted_at"] is not None
            goto(f"/{ADMIN}/topics?status=trash")
            navigate_click(page.get_by_role("button", name="恢复到草稿", exact=True).first)
            restored = database_row(database, "topics", topic_id)
            assert restored["deleted_at"] is None and restored["public_revision_id"] is None
            check("trash restore returns to a private draft")
            goto(f"/{ADMIN}/topics/{topic_id}/history")
            navigate_click(page.get_by_role("button", name="恢复到工作稿").first)
            assert database_row(database, "topics", topic_id)["public_revision_id"] is None
            check("history restoration keeps the result private")

            filtered_path = f"/{ADMIN}/topics?site=primary&search=GEO&status=draft"
            goto(filtered_path)
            list_url = page.url
            navigate_click(page.get_by_role("link", name="GEO 浏览器实际创建", exact=True))
            expect(page.locator('[data-topic-list-back]')).to_have_attribute("href", list_url)
            page.locator("#intro").fill("筛选列表进入后的临时输入，返回后仍保留。")
            navigate_click(page.locator("[data-topic-picker]"))
            navigate_click(page.locator("[data-topic-done]").first)
            expect(page.locator('[data-topic-list-back]')).to_have_attribute("href", list_url)
            expect(page.locator("#intro")).to_have_value("筛选列表进入后的临时输入，返回后仍保留。")
            navigate_click(page.get_by_role("link", name="查看本站专题设置", exact=True))
            navigate_click(page.get_by_role("link", name="返回原页面", exact=True))
            expect(page.locator('[data-topic-list-back]')).to_have_attribute("href", list_url)
            expect(page.locator("#intro")).to_have_value("筛选列表进入后的临时输入，返回后仍保留。")
            navigate_click(page.get_by_role("button", name="保存工作稿", exact=True))
            navigate_click(page.locator('[data-topic-list-back]'))
            assert parse_qs(urlparse(page.url).query) == parse_qs(urlparse(list_url).query), (page.url, list_url)
            check("filtered list context and temporary inputs survive picker/settings/save/return")

            goto(f"/{ADMIN}/topics/{topic_id}/edit")
            page.locator("#intro").fill("生成请求前尚未保存的人工导读")
            page.locator('[name="summary[one_sentence]"]').fill("生成请求前尚未保存的人工摘要")
            page.locator('[name="model_id"]').select_option(str(fixture["model_id"]))
            navigate_click(page.get_by_role("button", name="保存并获取 AI 建议", exact=True))
            run_id = int(urlparse(page.url).path.split("/")[-1])
            run = database_row(database, "topic_build_runs", run_id)
            assert run["status"] == "needs_adoption", run
            assert draft(database, topic_id)["intro"] == "生成请求前尚未保存的人工导读"
            assert draft(database, topic_id)["summary"]["one_sentence"] == "生成请求前尚未保存的人工摘要"
            expect(page.get_by_text("对照 AI 建议", exact=True)).to_be_visible()
            check("generation action saves unsaved manual inputs and preserves them until explicit adoption")
            screenshot("04-topic-ai-suggestion.png")
            page.locator('[name="fields[]"][value="intro"]').check()
            for field in ["summary", "tags", "articles", "seo", "faq", "basic_info"]:
                option = page.locator(f'[name="fields[]"][value="{field}"]')
                if option.count():
                    option.uncheck()
            before_draft = draft(database, topic_id)
            navigate_click(page.get_by_role("button", name="采用到工作稿（所选内容）", exact=True))
            adopted = draft(database, topic_id)
            assert adopted["intro"].startswith("本地测试生成结果")
            for field in ["summary", "tags", "articles", "seo", "faq", "basic_info"]:
                assert adopted[field] == before_draft[field], (field, adopted[field], before_draft[field])
            assert database_row(database, "topics", topic_id)["public_revision_id"] is None
            expect(page.locator('[name="tags_text"]')).to_have_value("人工标签")
            check("adopting only intro preserves manual summary/tags/articles and remains private")

            cards = page.locator('[data-topic-array="articles"] > div')
            excluded_id = int(cards.nth(0).locator('[data-column="article_id"]').input_value())
            cards.nth(0).get_by_role("button", name="永久排除", exact=True).click()
            expect(page.locator("[data-exclusion-count]")).to_have_text("1")
            navigate_click(page.get_by_role("button", name="保存工作稿", exact=True))
            page.reload(wait_until="networkidle")
            assert draft(database, topic_id)["source_overrides"]["excluded_article_ids"] == [excluded_id]
            check("permanent source exclusion persists across save and reload")
            page.locator('[name="model_id"]').select_option(str(fixture["model_id"]))
            navigate_click(page.get_by_role("button", name="保存并获取 AI 建议", exact=True))
            excluded_run = database_row(database, "topic_build_runs", int(urlparse(page.url).path.split("/")[-1]))
            assert excluded_id not in [row["article_id"] for row in json.loads(excluded_run["result"])["articles"]]
            report = next(event["report"] for event in json.loads(excluded_run["telemetry"]) if event.get("kind") == "matching")
            assert excluded_id not in [row["article_id"] for row in report["matches"]]
            check("saved exclusion filters matching candidates and actual AI output")
            goto(f"/{ADMIN}/topics/{topic_id}/edit")
            navigate_click(page.locator("[data-topic-picker]"))
            choice = page.locator(f'[data-topic-choice][value="{excluded_id}"]')
            if choice.count() == 0:
                choice = page.locator(f'[data-topic-choice][data-article-id="{excluded_id}"]')
            choice.check()
            navigate_click(page.locator("[data-topic-done]").first)
            expect(page.locator("[data-exclusion-count]")).to_have_text("0")
            navigate_click(page.get_by_role("button", name="保存工作稿", exact=True))
            assert draft(database, topic_id)["source_overrides"]["excluded_article_ids"] == []
            assert excluded_id in [row["article_id"] for row in draft(database, topic_id)["articles"]]
            check("explicit picker reselection clears exclusion and keeps the source selected")
            page.locator('[name="model_id"]').select_option(str(fixture["model_id"]))
            navigate_click(page.get_by_role("button", name="保存并获取 AI 建议", exact=True))
            restored_run = database_row(database, "topic_build_runs", int(urlparse(page.url).path.split("/")[-1]))
            restored_report = next(event["report"] for event in json.loads(restored_run["telemetry"]) if event.get("kind") == "matching")
            assert excluded_id in [row["article_id"] for row in restored_report["matches"]]
            check("reallowed source returns to actual generation matching")

            goto(f"/{ADMIN}/topics/{topic_id}/edit")
            page.get_by_text("时间范围与基本信息", exact=True).click()
            for label, value in [("材料类型", "公开来源"), ("维护责任", "编辑台")]:
                page.get_by_role("button", name="添加基本信息", exact=True).click()
                row = page.locator('[data-topic-array="basic_info"] > div').last
                row.locator('[data-column="label"]').fill(label)
                row.locator('[data-column="value"]').fill(value)
            page.get_by_role("button", name="添加事实", exact=True).click()
            fact = page.locator('[data-topic-array="facts"] > div').last
            fact.locator('[data-column="text"]').fill("仅用于未公开工作稿的事实编辑示例。")
            current_id = draft(database, topic_id)["articles"][0]["article_id"]
            fact.locator('select').select_option(str(current_id))
            source = database_row(database, "articles", current_id)
            manual_quote = source["title"].removeprefix("GEO ")
            fact.locator('[data-column="evidence_quotes_text"]').fill(f"{current_id}：{manual_quote}")
            page.get_by_text("常见问题，可选", exact=True).click()
            page.get_by_role("button", name="添加问题", exact=True).click()
            faq = page.locator('[data-topic-array="faq"] > div').last
            faq.locator('[data-column="question"]').fill("如何核对这份材料？")
            faq.locator('[data-column="answer"]').fill("通过已选来源中的原文查看全文。")
            faq.locator('select').select_option(str(current_id))
            navigate_click(page.get_by_role("button", name="保存工作稿", exact=True))
            assert len(draft(database, topic_id)["basic_info"]) == 2
            saved_fact = draft(database, topic_id)["summary"]["facts"][0]
            locator_evidence = saved_fact["evidence"][0]
            actual = source[locator_evidence["field"]][locator_evidence["start"]:locator_evidence["end"]]
            assert actual == locator_evidence["text"] == manual_quote
            assert locator_evidence["sha256"] == hashlib.sha256(actual.encode("utf-8")).hexdigest()
            assert len(draft(database, topic_id)["faq"]) == 1
            check("manual fact quote input resolves an actual UTF-8 source locator and matching SHA-256")
            page.get_by_text("时间范围与基本信息", exact=True).click()
            page.locator('[data-topic-array="articles"] > div').first.get_by_role("button", name="移除", exact=True).click()
            while page.locator('[data-topic-array="basic_info"] > div').count():
                page.locator('[data-topic-array="basic_info"] > div').first.get_by_role("button", name="移除", exact=True).click()
            page.locator('[data-topic-array="facts"] > div').first.get_by_role("button", name="移除", exact=True).click()
            page.get_by_text("常见问题，可选", exact=True).click()
            page.locator('[data-topic-array="faq"] > div').first.get_by_role("button", name="移除", exact=True).click()
            page.locator('[name="tags_text"]').fill("")
            page.locator('[name="summary[scope]"]').fill("x" * 2001)
            before_error = database_row(database, "topics", topic_id)["draft_version"]
            navigate_click(page.get_by_role("button", name="保存工作稿", exact=True))
            expect(page.locator('[data-topic-editor]')).to_have_attribute("data-has-errors", "true")
            expect(page.locator('[data-topic-array="articles"] > div')).to_have_count(1)
            expect(page.locator('[data-topic-array="basic_info"] > div')).to_have_count(0)
            expect(page.locator('[data-topic-array="facts"] > div')).to_have_count(0)
            expect(page.locator('[data-topic-array="faq"] > div')).to_have_count(0)
            expect(page.locator('[name="tags_text"]')).to_have_value("")
            assert database_row(database, "topics", topic_id)["draft_version"] == before_error
            screenshot("05-validation-retained-deletions.png")
            check("validation return retains deleted rows and empty lists instead of reviving stored arrays")
            page.locator('[name="summary[scope]"]').fill("")
            navigate_click(page.get_by_role("button", name="保存工作稿", exact=True))
            cleared = draft(database, topic_id)
            assert len(cleared["articles"]) == 1 and cleared["basic_info"] == [] and cleared["summary"]["facts"] == [] and cleared["tags"] == [] and cleared["faq"] == [], {"articles": cleared["articles"], "basic_info": cleared["basic_info"], "facts": cleared["summary"]["facts"], "tags": cleared["tags"], "faq": cleared["faq"]}
            check("corrected save persists explicit list deletions and cleared tags")

            goto(f"/{ADMIN}/topics/batches/create")
            page.locator('[name="model_id"]').select_option(str(fixture["model_id"]))
            page.locator('[name="after"]').select_option("draft_only")
            csv = '\ufefftags,template,标题,audience,category,freshness,sourcecoverage\n"基础,实操",guide,"GEO CSV,\nGuide",新手,GEO 内容研究,none,本站内容研究\n"基础,实操",guide,"GEO CSV, Guide",新手,GEO 内容研究,none,本站内容研究\n汇总,roundup,GEO CSV Roundup,编辑,GEO 内容研究,none,本站公开文章\n'
            page.locator('[name="csv"]').set_input_files({"name": "topics.csv", "mimeType": "text/csv", "buffer": csv.encode("utf-8")})
            navigate_click(page.get_by_role("button", name="检查标题与配置", exact=True))
            expect(page.locator("#preview-row-1")).to_contain_text("GEO CSV, Guide")
            expect(page.locator("#preview-row-1")).to_contain_text("目标读者：新手")
            expect(page.locator("#preview-row-1")).to_contain_text("来源范围：本站内容研究")
            expect(page.locator('#preview-row-2 a[href="#preview-row-1"]')).to_be_visible()
            screenshot("05-csv-preview.png")
            check("CSV BOM/header mapping uses third-column title, metadata and quoted multiline values")
            check("CSV duplicate preview links to the first matching row")
            navigate_click(page.get_by_role("button", name="确认配置并开始生成", exact=True))
            batch_id = int(urlparse(page.url).path.split("/")[-1])
            batch = database_row(database, "topic_import_batches", batch_id)
            rows = json.loads(batch["rows"])
            assert [row["status"] for row in rows] == ["completed", "duplicate", "completed"], rows
            assert rows[0]["topic_id"] == rows[1]["topic_id"]
            assert rows[0]["run_id"] is not None and rows[2]["run_id"] is not None
            first = draft(database, rows[0]["topic_id"])
            third = draft(database, rows[2]["topic_id"])
            assert first["template_key"] == "guide" and third["template_key"] == "roundup"
            assert first["tags"] == ["基础", "实操"]
            assert first["basic_info"][0]["value"] == "新手"
            assert first["basic_info"][2]["value"] == "本站内容研究"
            assert database_row(database, "topics", rows[0]["topic_id"])["public_revision_id"] is None
            check("confirmed CSV batch saves row metadata/template/tags and preserves duplicate first IDs")
            screenshot("06-csv-batch-result.png")

            goto(f"/{ADMIN}/topics/batches/create")
            page.locator('[name="model_id"]').select_option(str(fixture['model_id']))
            page.locator('[name="after"]').select_option('draft_only')
            scoped_csv = '标题,适合人群,文章分类,适用范围与限制,模板\nGEO 同名范围浏览器,新手,GEO 内容研究,入门阅读,guide\nGEO 同名范围浏览器,管理员,GEO 内容研究,管理实践,roundup\nGEO 错误配置浏览器,新手,GEO 内容研究,错误行,missing-layout\n'
            page.locator('[name="csv"]').set_input_files({'name':'scoped-topics.csv','mimeType':'text/csv','buffer':scoped_csv.encode('utf-8')})
            navigate_click(page.get_by_role('button', name='检查标题与配置', exact=True))
            expect(page.locator('#preview-row-1')).to_contain_text('入门阅读')
            expect(page.locator('#preview-row-2')).to_contain_text('管理实践')
            expect(page.get_by_role('button', name='确认配置并开始生成', exact=True)).to_be_enabled()
            expect(page.get_by_role('button', name='转为专题任务', exact=True)).to_be_enabled()
            check('Chinese CSV scope aliases preserve two valid ranges and bad rows allow valid work')
            screenshot('19-scoped-batch-preview.png')
            navigate_click(page.get_by_role('button', name='转为专题任务', exact=True))
            expect(page.get_by_role('heading', name='已带入 2 个有效标题', exact=True)).to_be_visible()
            page.locator('[name="name"]').fill('浏览器同名范围转入任务')
            navigate_click(page.get_by_role('button', name='仅保存暂停', exact=True))
            transferred = database_rows(database, 'tasks', 'name = ?', ('浏览器同名范围转入任务',))[0]
            transferred_settings = json.loads(transferred['topic_settings'])
            assert len(transferred_settings['title_ids']) == 2
            assert transferred['status'] == 'paused' and transferred['title_library_id'] is not None
            assert len(database_rows(database, 'titles', 'library_id = ?', (transferred['title_library_id'],))) == 2
            check('batch transfer saves two real scoped title assets and one paused task without losing metadata')
            goto(f"/{ADMIN}/tasks/{transferred['id']}/edit")
            page.locator('[name="ai_model_id"]').select_option(str(fixture['model_id']))
            navigate_click(page.get_by_role('button', name='保存并启动', exact=True))
            execute_task(transferred['id'])
            execute_task(transferred['id'], advance_interval=True)
            generated_scopes = database_rows(database, 'topics', 'task_id = ?', (transferred['id'],))
            assert len(generated_scopes) == 2
            assert {item['title'] for item in generated_scopes} == {'GEO 同名范围浏览器'}
            assert len({json.loads(item['draft_payload'])['summary']['scope'] for item in generated_scopes}) == 2
            assert {json.loads(item['draft_payload'])['template_key'] for item in generated_scopes} == {'guide','roundup'}
            check('real worker executes both same-title ranges and preserves original topic titles/templates')
            screenshot('20-scoped-task-results.png')

            goto(f"/{ADMIN}/tasks/create?content_type=topic")
            page.locator('[name="name"]').fill("浏览器专题任务")
            navigate_click(page.get_by_role("button", name="仅保存暂停", exact=True))
            task = database_rows(database, "tasks", "name = ?", ("浏览器专题任务",))[0]
            assert (task["content_type"], task["status"]) == ("topic", "paused")
            check("real topic task form saves paused without model/library")
            goto(f"/{ADMIN}/tasks/{task['id']}/edit")
            page.locator('[name="title_library_id"]').select_option(str(fixture["library_id"]))
            page.locator('[name="ai_model_id"]').select_option(str(fixture["model_id"]))
            page.locator('[name="topic_limit"]').fill("2")
            page.locator('[name="interval_value"]').fill("1")
            page.locator('[name="interval_unit"]').select_option("minute")
            page.get_by_text("维护已有专题与高级设置", exact=True).click()
            page.locator('[name="topic_settings[matching_rules][related_terms_text]"]').fill("GEO,内容")
            navigate_click(page.get_by_role("button", name="保存并启动", exact=True))
            active = database_row(database, "tasks", task["id"])
            assert active["status"] == "active" and active["publish_interval"] == 60
            assert json.loads(active["topic_settings"])["matching_rules"]["related_terms"] == ["geo", "内容"]
            check("real task edit starts configured topic task and stores friendly interval/matching rules")
            result = execute_task(task["id"])
            assert result["topic_id"] is not None, result
            created = database_row(database, "topics", result["topic_id"])
            assert created["task_id"] == task["id"]
            task_run = database_row(database, "task_runs", result["task_run_id"])
            assert task_run["status"] == "completed" and task_run["content_type"] == "topic"
            assert json.loads(task_run["meta"])["action"] == "publish_topic", task_run
            assert created["public_revision_id"] is not None
            goto("/topics/" + created["slug"])
            expect(page.locator(".topic-intro")).to_contain_text("本地测试生成结果")
            assert all(row["used_count"] == 0 for row in database_rows(database, "titles", "library_id = ?", (fixture["library_id"],)))
            check("activated task runs the real worker, produces linked topic and preserves article title usage")
            goto(f"/{ADMIN}/tasks/{task['id']}/edit")
            expect(page.get_by_text("当前结果", exact=True)).to_be_visible()
            expect(page.get_by_role("link", name="查看本任务专题", exact=True)).to_be_visible()
            assert "生成 1 个专题" in page.locator(".topic-shell").inner_text()
            navigate_click(page.get_by_role("link", name="查看本任务专题", exact=True))
            expect(page.get_by_role("link", name=created["title"], exact=True)).to_be_visible()
            check("task result UI counts topics and links to the actual generated topic")
            screenshot("07-topic-task-result.png")

            goto(f"/{ADMIN}/topics/settings")
            screenshot("08-topic-settings.png")
            for template, item in fixture["published"].items():
                goto("/topics/" + item["slug"])
                expect(page.locator(".topic-layout-" + template)).to_be_visible()
                expect(page.locator(f'[data-topic-template="{template}"]')).to_be_visible()
                expect(page.locator(".topic-group-title")).to_have_count(3)
                expect(page.locator('[aria-label="专题分组目录"] a')).to_have_count(3)
                for anchor in page.locator('[aria-label="专题分组目录"] a').all():
                    assert page.locator(anchor.get_attribute("href")).count() == 1
                if template == "default":
                    expect(page.locator(".topic-source-cards")).to_be_visible()
                    first_card = page.locator(".topic-source-cards .topic-source").nth(0).bounding_box()
                    second_card = page.locator(".topic-source-cards .topic-source").nth(1).bounding_box()
                    assert second_card["x"] > first_card["x"] and abs(second_card["y"] - first_card["y"]) < 2
                    assert page.locator(".topic-source-number").evaluate_all("""nodes => nodes.every(node=>{const range=document.createRange();range.selectNodeContents(node);return range.getClientRects().length===1;})""")
                    expect(page.locator(".topic-reading-index")).to_have_count(0)
                    expect(page.locator(".topic-roundup-list")).to_have_count(0)
                    expect(page.locator(".topic-score-total strong")).to_have_text("8.5")
                    expect(page.locator('.topic-stars[role="img"]')).to_have_attribute("aria-label", "4.25 / 5 星")
                    assert page.locator(".topic-stars span").last.evaluate("node=>node.style.width") == "85%"
                    expect(page.locator(".topic-score")).to_contain_text("本地编辑评分示例")
                    page.locator(".topic-score-method summary").click()
                    expect(page.locator(".topic-score-method a")).to_have_count(2)
                    expect(page.locator(".topic-score-method")).to_contain_text("权重 50.0%")
                    score = draft(database, item["id"])["score"]
                    assert sum(row["score"] * weight for row, weight in zip(score["dimensions"], score["weights"])) / sum(score["weights"]) == score["total"]
                    check("optional editorial score is a real weighted 8.5 example with 4.25 stars and source evidence")
                elif template == "guide":
                    expect(page.locator(".topic-reading-index")).to_be_visible()
                    expect(page.locator('.topic-reading-index a[href^="#source-"]')).to_have_count(6)
                    expect(page.locator(".topic-reading-steps")).to_be_visible()
                    first_step = page.locator(".topic-reading-steps .topic-source").nth(0).bounding_box()
                    second_step = page.locator(".topic-reading-steps .topic-source").nth(1).bounding_box()
                    assert abs(first_step["x"] - second_step["x"]) < 2 and second_step["y"] > first_step["y"]
                    expect(page.locator(".topic-score")).to_have_count(0)
                    assert page.title() == "GEO 搜索阅读指南 · 本地示例"
                    expect(page.locator('meta[name="description"]')).to_have_attribute("content", "专题搜索展示描述示例：依据本站公开来源提供分组阅读指南。")
                    expect(page.locator("h1").first).to_have_text(item["title"])
                    check("SEO head override remains independent from the visible H1")
                else:
                    expect(page.locator(".topic-roundup-list")).to_be_visible()
                    date_box = page.locator(".topic-roundup-date").first.bounding_box()
                    title_box = page.locator(".topic-roundup-item h3").first.bounding_box()
                    assert date_box["x"] < title_box["x"]
                    expect(page.locator(".topic-roundup-date time")).to_have_count(6)
                    expect(page.locator(".topic-reading-index")).to_have_count(0)
                    expect(page.locator(".topic-score")).to_have_count(0)
                page.locator(".topic-fact-evidence summary").click()
                expect(page.locator(".topic-fact-evidence blockquote")).to_have_text("明确文章的来源")
                screenshot("09-layout-" + template + ".png")
                check(template + " uses its actual internal card/reading/timeline layout with three group anchors")
            guide = fixture["published"]["guide"]
            goto(f"/{ADMIN}/topics/{guide['id']}/edit")
            page.get_by_text("搜索展示与地址，高级设置", exact=True).click()
            navigate_click(page.get_by_role("link", name="管理专题地址", exact=True))
            page.locator('[name="path"]').fill("geo-browser-renamed-guide")
            navigate_click(page.get_by_role("button", name="预检地址", exact=True))
            assert database_row(database, "topics", guide["id"])["slug"] == guide["slug"]
            expect(page.get_by_text("确认地址变更", exact=True)).to_be_visible()
            expect(page.locator(".topic-shell")).to_contain_text("/topics/geo-browser-renamed-guide")
            screenshot("10-path-preview.png")
            check("address preflight displays old/new URLs while keeping the stored slug unchanged")
            navigate_click(page.get_by_role("button", name="确认更新地址", exact=True))
            assert database_row(database, "topics", guide["id"])["slug"] == "geo-browser-renamed-guide"
            old = context.request.get(base_url + "/topics/" + guide["slug"], max_redirects=0)
            assert old.status == 301 and old.headers["location"] == base_url + "/topics/geo-browser-renamed-guide"
            goto("/topics/geo-browser-renamed-guide")
            expect(page.locator("h1").first).to_have_text(guide["title"])
            expect(page.locator('link[rel="canonical"]')).to_have_attribute("href", base_url + "/topics/geo-browser-renamed-guide")
            check("confirmed address change returns a direct old-URL 301 and a new canonical URL")

            mobile = browser.new_context(viewport={"width": 375, "height": 812}, locale="zh-CN", service_workers="block")
            mobile.route("**/*", guard_network)
            mobile.on("page", capture_errors)
            mobile.add_cookies(context.cookies())
            mpage = mobile.new_page()
            paths = ["/topics", "/topics/" + fixture["published"]["default"]["slug"], "/topics/geo-browser-renamed-guide", "/topics/" + fixture["published"]["roundup"]["slug"], f"/{ADMIN}/topics", f"/{ADMIN}/topics/create", f"/{ADMIN}/topics/{topic_id}/edit", f"/{ADMIN}/topics/articles", f"/{ADMIN}/topics/batches/{batch_id}", f"/{ADMIN}/topics/settings", f"/{ADMIN}/topics/{guide['id']}/path", f"/{ADMIN}/tasks/create?content_type=topic", f"/{ADMIN}/tasks/{task['id']}/edit"]
            for path in paths:
                response = mpage.goto(base_url + path)
                assert response and response.status == 200, (path, response.status if response else None)
                mpage.wait_for_load_state("networkidle")
                sizes = mpage.evaluate("({width:innerWidth,scroll:document.documentElement.scrollWidth})")
                if sizes["scroll"] > sizes["width"] + 1:
                    overflow = mpage.evaluate("""() => Array.from(document.querySelectorAll('body *')).map(node => {
                        const rect=node.getBoundingClientRect(), css=getComputedStyle(node);
                        return {tag:node.tagName,classes:node.className, text:(node.textContent||'').trim().slice(0,70),left:rect.left,right:rect.right,width:rect.width,display:css.display,overflowX:css.overflowX,minWidth:css.minWidth};
                    }).filter(row=>row.width>0&&row.right>innerWidth+1).slice(0,30)""")
                    (output / "mobile-overflow.json").write_text(json.dumps({"path":path,"sizes":sizes,"elements":overflow}, ensure_ascii=False, indent=2), encoding="utf-8")
                    screenshot("mobile-failure.png", mpage)
                assert sizes["scroll"] <= sizes["width"] + 1, (path, sizes)
                check("375px mobile has no root horizontal overflow: " + path)
            mpage.goto(base_url + "/topics/" + fixture["published"]["default"]["slug"])
            mpage.wait_for_load_state("networkidle")
            screenshot("11-topic-public-mobile.png", mpage)
            auxiliary = mpage.locator('[data-topic-auxiliary]')
            assert auxiliary.get_attribute('open') is None
            expect(mpage.locator('.topic-score')).to_have_count(1)
            expect(mpage.locator('.topic-score')).not_to_be_visible()
            auxiliary.locator('summary').first.click()
            expect(mpage.locator('.topic-score')).to_be_visible()
            expect(auxiliary).to_contain_text('来源说明')
            screenshot("16-mobile-auxiliary-expanded.png", mpage)
            check("mobile has one closed auxiliary group, all score/time/source information expands together")
            responsive_paths = ["/topics", "/topics/" + fixture['published']['default']['slug'], "/topics/geo-browser-renamed-guide", "/topics/" + fixture['published']['roundup']['slug'], f"/{ADMIN}/topics/{topic_id}/edit", f"/{ADMIN}/topics/batches/{batch_id}", f"/{ADMIN}/tasks/create?content_type=topic", f"/{ADMIN}/topics/settings"]
            for width, scale in [(320, 1), (390, 1), (1280, 1), (1440, 1), (720, 2)]:
                probe = browser.new_context(viewport={"width": width, "height": 900}, device_scale_factor=scale, locale="zh-CN", service_workers="block")
                try:
                    probe.route("**/*", guard_network)
                    probe.on("page", capture_errors)
                    probe.add_cookies(context.cookies())
                    probe_page = probe.new_page()
                    for path in responsive_paths:
                        response = probe_page.goto(base_url + path)
                        assert response and response.status == 200, (width, path)
                        probe_page.wait_for_load_state("networkidle")
                        sizes = probe_page.evaluate("({width:innerWidth,scroll:document.documentElement.scrollWidth})")
                        assert sizes['scroll'] <= sizes['width'] + 1, (width, scale, path, sizes)
                        if path.endswith('/edit'):
                            for label in ['保存工作稿', '保存并发布', '保存并预览']:
                                box = probe_page.get_by_role('button', name=label, exact=True).bounding_box()
                                assert box and box['height'] >= 44 and box['x'] >= 0 and box['x'] + box['width'] <= width + 1, (width, label, box)
                    check("responsive reflow and usable controls", {"width": width, "scale": scale, "pages": len(responsive_paths), "zoom_equivalent": "200% reflow at 720 CSS pixels / 2x device scale" if scale == 2 else None})
                    if width == 320:
                        probe_page.goto(base_url + '/topics/geo-browser-renamed-guide')
                        screenshot('17-public-320px.png', probe_page)
                    if scale == 2:
                        probe_page.goto(base_url + f"/{ADMIN}/topics/{topic_id}/edit")
                        screenshot('18-editor-200-percent-reflow.png', probe_page)
                finally:
                    probe.close()
            mpage.goto(base_url + f"/{ADMIN}/topics/{topic_id}/edit")
            mpage.wait_for_load_state("networkidle")
            screenshot("12-topic-editor-mobile.png", mpage)
            assert not errors, errors
            check("desktop and mobile journeys finish with no browser script errors")
        except Exception:
            screenshot("failure.png")
            (output / "failure-page.txt").write_text(page.locator("body").inner_text(), encoding="utf-8")
            (output / "partial-evidence.json").write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
            raise
        finally:
            try:
                if mobile:
                    mobile.close()
            finally:
                try:
                    context.close()
                finally:
                    browser.close()
    return evidence


def run_mode(output, v3):
    server = None
    temporary = None
    try:
        # Fixture, worker and HTTP processes share only this guarded ephemeral application state.
        with tempfile.TemporaryDirectory(prefix="geoflow-workflow-browser-") as directory:
            temporary = Path(directory).resolve()
            (temporary / ".browser-test-only").touch()
            database = temporary / "test.sqlite"
            database.touch()
            for child in ["framework/sessions", "framework/views", "framework/cache/data", "logs", "app/private"]:
                (temporary / "storage" / child).mkdir(parents=True, exist_ok=True)
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            base_url = f"http://127.0.0.1:{port}"
            environment = dict(os.environ, APP_ENV="testing", APP_DEBUG="false", APP_KEY="base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
                APP_URL=base_url, SITE_URL=base_url, ASSET_URL=base_url, APP_LOCALE="zh_CN", DB_CONNECTION="sqlite", DB_DATABASE=str(database), DB_URL="",
                GEOFLOW_BROWSER_TEST_ROOT=str(temporary), ADMIN_BASE_PATH=ADMIN, CACHE_STORE="array", SESSION_DRIVER="file",
                SESSION_COOKIE="geoflow_browser_workflow", SESSION_SECURE_COOKIE="false", SESSION_DOMAIN="", QUEUE_CONNECTION="null",
                BROADCAST_CONNECTION="null", MAIL_MAILER="array", LOG_CHANNEL="single", BCRYPT_ROUNDS="4",
                GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED="true", GEOFLOW_ADMIN_UI_V3_ENABLED="true" if v3 else "false",
                GEOFLOW_UPDATE_CHECK_ENABLED="false", GEOFLOW_ADMIN_EMAIL="fixture@example.test", GEOFLOW_ADMIN_PASSWORD="test-only-seed",
                APP_CONFIG_CACHE=str(temporary / "config.php"), APP_ROUTES_CACHE=str(temporary / "routes.php"), APP_EVENTS_CACHE=str(temporary / "events.php"),
                APP_SERVICES_CACHE=str(temporary / "services.php"), APP_PACKAGES_CACHE=str(temporary / "packages.php"), PULSE_ENABLED="false", TELESCOPE_ENABLED="false", NIGHTWATCH_ENABLED="false")
            fixture_process = subprocess.run(["php", "tests/Browser/topic_workflow_fixture.php"], cwd=ROOT, env=environment, text=True, capture_output=True, timeout=120)
            (output / "fixture.log").write_text(fixture_process.stdout + fixture_process.stderr, encoding="utf-8")
            assert fixture_process.returncode == 0, fixture_process.stdout + fixture_process.stderr
            fixture = json.loads(fixture_process.stdout.strip().splitlines()[-1])

            def execute_task(task_id, advance_interval=False):
                command = ["php", "tests/Browser/topic_workflow_fixture.php", "execute-task", str(task_id)]
                if advance_interval:
                    command.append("advance-next-interval")
                result = subprocess.run(command, cwd=ROOT, env=environment, text=True, capture_output=True, timeout=120)
                with (output / "worker.log").open("a", encoding="utf-8") as worker_log:
                    worker_log.write(result.stdout + result.stderr)
                assert result.returncode == 0, result.stdout + result.stderr
                return json.loads(result.stdout.strip().splitlines()[-1])

            with (output / "server.log").open("w") as server_log:
                server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public", "tests/Browser/topic_workflow_router.php"], cwd=ROOT, env=environment, stdout=server_log, stderr=subprocess.STDOUT)
                try:
                    deadline = time.monotonic() + 30
                    while True:
                        if server.poll() is not None:
                            raise RuntimeError("Local PHP server stopped; inspect server.log")
                        try:
                            with urlopen(base_url + "/up", timeout=1) as response:
                                if response.status == 200:
                                    break
                        except OSError:
                            if time.monotonic() > deadline:
                                raise RuntimeError("Local PHP server was not ready; inspect server.log")
                            time.sleep(0.1)
                    evidence = run_browser(base_url, database, fixture, output, v3, execute_task)
                finally:
                    server.terminate()
                    try:
                        server.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        server.kill()
                        server.wait(timeout=5)
                    application_log = temporary / "storage/logs/laravel.log"
                    if application_log.exists():
                        (output / "application.log").write_bytes(application_log.read_bytes())
    finally:
        cleanup = {"server_stopped": server is None or server.poll() is not None, "temporary_directory_removed": temporary is None or not temporary.exists()}
        (output / "cleanup.json").write_text(json.dumps(cleanup, indent=2), encoding="utf-8")
    evidence["cleanup"] = cleanup
    assert all(evidence["cleanup"].values()), evidence["cleanup"]
    (output / "browser-evidence.json").write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
    return evidence


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=Path(tempfile.gettempdir()) / "geoflow-topic-browser-proof")
    parser.add_argument("--admin-ui", choices=["both", "off", "on"], default="both")
    args = parser.parse_args()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    assert (ROOT / "public/build/manifest.json").is_file(), "Build assets before running this test."
    results = []
    for v3 in [False, True] if args.admin_ui == "both" else [args.admin_ui == "on"]:
        mode_output = output / ("admin-v3-on" if v3 else "admin-v3-off")
        mode_output.mkdir(parents=True, exist_ok=True)
        results.append(run_mode(mode_output, v3))
    evidence = {"passed": sum(len(result["checks"]) for result in results), "modes": results, "cleanup": all(all(result["cleanup"].values()) for result in results)}
    (output / "browser-evidence.json").write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"passed": evidence["passed"], "evidence": str(output / "browser-evidence.json"), "screenshots": sum((result["screenshots"] for result in results), []), "cleanup": evidence["cleanup"]}, ensure_ascii=False))


if __name__ == "__main__":
    main()
