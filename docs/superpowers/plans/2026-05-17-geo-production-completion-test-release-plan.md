# GEO Production Completion, Test, and Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the GEO system from the current MVP into a usable production loop: discover opportunities, run AI search, collect and score references, generate reference-grounded drafts, audit, publish, retest, and release safely.

**Architecture:** Keep the current Laravel GEOAmplify admin as the core. Reuse the existing GEO services, models, and Blade pages; add focused service classes only where a new boundary is needed. Use test-first implementation for every user-facing workflow, then run full regression before release.

**Tech Stack:** Laravel 12, PHP 8.4+, Blade/Tailwind admin UI, existing `AiModel` OpenAI-compatible / Anthropic-compatible clients, Laravel HTTP fake for tests, existing article management and GEO publishing services.

## Execution Status

Updated: 2026-05-18.

- Tasks 1-2 are complete: reference-content briefs generate drafts, and production audit flags forbidden terms, reference coverage, and local intent.
- Tasks 3-4 are complete: converted articles preserve reference metadata, draft edit shows publish readiness, and post-publish retest records are generated.
- Tasks 5-6 are complete: batch crawl, batch score, and retest can run through async jobs, and the end-to-end release-readiness test covers the full loop.
- Tasks 7-8 are complete: local browser smoke test passed on port 8017, and release/operator docs were added under `docs/geo/`.
- Task 9.1 local code release commit is complete: `f99c529 feat: complete GEO production workflow`.
- Task 9.1 remote push is blocked in this shell by missing GitHub credentials: HTTPS cannot read a username, and SSH has no usable public key for `git@github.com`.
- Task 9.2 app deployment remains gated on confirmed server, database backup, queue worker, and rollback path.

---

## Current Baseline

Already implemented and tested:

- Brand profile and keyword management.
- GEO diagnosis tasks and reports.
- Real/mock AI model calls.
- Article draft generation from reports.
- Keyword opportunity discovery.
- AI search batches and citation source extraction.
- Single and batch citation source crawl.
- Single and batch reference content scoring.
- `GeoWritingTask.brief` reference-content brief generation.
- Full test suite currently passes with `php artisan test --compact`.

The remaining work is not to restart the system. The next work is to connect the new reference-content intelligence into draft generation, publishing, retesting, and release.

## Definition Of Done

The system is complete enough to release when all of these are true:

- A user can save brand facts and restrictions.
- A user can generate keyword opportunities.
- A user can run an AI search batch with at least one configured real AI model or mock fallback.
- The system extracts citation sources from AI answers.
- The user can batch crawl and batch score citation sources.
- The user can generate a reference-content brief.
- The system can generate an article draft from that brief, not only from an old diagnosis report.
- The draft stores which references and brand facts were used.
- The user can audit the draft for brand mention, local intent, forbidden terms, evidence coverage, and reference coverage.
- The user can convert the draft to an article and publish through the existing article publishing path.
- The system can run a post-publish retest and compare visibility before/after.
- Feature tests cover the full loop.
- `vendor/bin/pint --dirty --format agent` passes.
- `php artisan test --compact` passes.
- Local manual smoke test passes in `http://127.0.0.1:8017/geo_admin/geo`.

## File Structure

Expected files to create or modify:

| File | Responsibility |
|---|---|
| `app/Services/Geo/GeoReferenceBriefBuilder.php` | Keep as the source-to-brief builder; extend only if needed |
| `app/Services/Geo/GeoReferenceDraftGenerator.php` | New service that turns `GeoWritingTask.brief[source=reference_content]` into a grounded draft |
| `app/Services/Geo/GeoArticleAuditService.php` | Extend audit checks for forbidden terms, reference coverage, and local intent |
| `app/Services/Geo/GeoPostPublishRetestRunner.php` | New service that creates a follow-up AI search/retest after article publication |
| `app/Http/Controllers/Admin/GeoWorkspaceController.php` | Add thin controller actions for brief-to-draft and post-publish retest |
| `resources/views/admin/geo/citation-sources/index.blade.php` | Add brief-to-draft entry if using source library workflow |
| `resources/views/admin/geo/report.blade.php` | Show reference briefs and post-publish retest actions |
| `resources/views/admin/geo/article-draft-edit.blade.php` | Show brief evidence, audit gaps, and publish readiness |
| `database/migrations/*geo_publish_retests*.php` | Store post-publish retest snapshots if existing tables are insufficient |
| `tests/Feature/AdminGeoOpportunityWorkflowTest.php` | Extend source-to-brief-to-draft workflow test |
| `tests/Feature/AdminGeoWorkspaceTest.php` | Extend draft, audit, publish, retest workflow tests |
| `tests/Feature/GeoProductionReleaseReadinessTest.php` | New end-to-end release-readiness test |

## Task 1: Generate Drafts From Reference Briefs

**Files:**
- Create: `app/Services/Geo/GeoReferenceDraftGenerator.php`
- Modify: `app/Http/Controllers/Admin/GeoWorkspaceController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/geo/citation-sources/index.blade.php`
- Test: `tests/Feature/AdminGeoOpportunityWorkflowTest.php`

- [ ] **Step 1: Write failing feature test**

Add a test named `test_admin_can_generate_article_draft_from_reference_brief`.

Test setup:

```php
$briefTask = GeoWritingTask::query()->create([
    'organization_id' => $organization->id,
    'title' => '涪陵全屋定制参考内容简报',
    'status' => 'pending',
    'brief' => [
        'source' => 'reference_content',
        'references' => [[
            'title' => '重庆全屋定制恒森案例',
            'url' => 'https://example.test/hengsen-guide',
            'summary' => '包含报价、板材、安装流程和售后口碑。',
            'score' => 82,
            'content_excerpt' => '恒森全屋定制适合本地业主优先参考。',
        ]],
        'recommended_outline' => [
            '先用一句话回答用户最关心的问题',
            '补充本地案例、报价、板材、流程、售后等可验证事实',
        ],
        'evidence_points' => ['报价、板材、安装流程和售后口碑'],
    ],
]);
```

Expected assertions:

```php
$this->assertDatabaseHas('geo_article_drafts', [
    'geo_writing_task_id' => $briefTask->id,
    'status' => 'draft',
]);
$this->assertStringContainsString('重庆全屋定制恒森案例', $draft->content_markdown);
$this->assertStringContainsString('报价、板材、安装流程', $draft->content_markdown);
```

- [ ] **Step 2: Run test and verify failure**

Run:

```bash
php artisan test --compact --filter=test_admin_can_generate_article_draft_from_reference_brief
```

Expected: FAIL because route/service does not exist.

- [ ] **Step 3: Implement service**

Create `GeoReferenceDraftGenerator` with:

```php
public function generate(GeoWritingTask $writingTask): GeoArticleDraft
```

Rules:

- Only accept `brief.source === reference_content`.
- Use top references in order of score.
- Include brand facts from the organization latest `BrandProfile`.
- Include reference titles and URLs as source notes.
- Generate deterministic Markdown first; later AI rewriting can build on this.
- Save `content_html` through `ArticleHtmlPresenter::markdownToHtml()`.

- [ ] **Step 4: Add route and controller action**

Route:

```php
Route::post('citation-sources/reference-briefs/{writingTaskId}/article-draft', [GeoWorkspaceController::class, 'generateReferenceBriefDraft'])
    ->name('citation-sources.reference-briefs.article-draft.store')
    ->whereNumber('writingTaskId');
```

Controller action loads a `GeoWritingTask` for current organization, calls the service, redirects to edit page or citation source index with message.

- [ ] **Step 5: Add UI button**

On `resources/views/admin/geo/citation-sources/index.blade.php`, for each reference brief, show:

```text
生成草稿
```

Button posts to the new route.

- [ ] **Step 6: Run tests**

Run:

```bash
php artisan test --compact tests/Feature/AdminGeoOpportunityWorkflowTest.php
```

Expected: PASS.

## Task 2: Strengthen Draft Audit For Production Use

**Files:**
- Modify: `app/Services/Geo/GeoArticleAuditService.php`
- Modify: `resources/views/admin/geo/report.blade.php`
- Test: `tests/Feature/AdminGeoWorkspaceTest.php`

- [ ] **Step 1: Write failing audit test**

Add `test_geo_audit_flags_forbidden_terms_missing_reference_and_missing_local_intent`.

Fixture draft:

```php
'content_markdown' => '我们保证全屋定制全网最低价，没有引用来源，也没有重庆涪陵本地信息。',
```

Expected:

```php
$this->assertContains('forbidden_terms', $audit->failed_checks);
$this->assertContains('reference_coverage', $audit->failed_checks);
$this->assertContains('local_intent', $audit->failed_checks);
```

- [ ] **Step 2: Run test and verify failure**

Run:

```bash
php artisan test --compact --filter=test_geo_audit_flags_forbidden_terms_missing_reference_and_missing_local_intent
```

Expected: FAIL because those audit checks do not exist.

- [ ] **Step 3: Implement audit checks**

Extend audit service:

- `forbidden_terms`: fail if content contains words like `保证`, `全网最低价`, `百分百`, `永久有效`.
- `reference_coverage`: pass if draft `writingTask.brief.references` exists and at least one reference title/domain/URL is mentioned in content.
- `local_intent`: pass if content contains brand service area or city/region from brand profile.
- Keep existing checks backward-compatible.

- [ ] **Step 4: Show audit gaps in report page**

In report page audit panel, show human-readable labels:

```text
禁用词检查
参考来源覆盖
本地意图覆盖
```

- [ ] **Step 5: Run tests**

Run:

```bash
php artisan test --compact tests/Feature/AdminGeoWorkspaceTest.php
```

Expected: PASS.

## Task 3: Publish Readiness And Article Conversion

**Files:**
- Modify: `app/Services/Geo/GeoArticlePublisher.php`
- Modify: `resources/views/admin/geo/article-draft-edit.blade.php`
- Test: `tests/Feature/AdminGeoWorkspaceTest.php`

- [ ] **Step 1: Write failing publish-readiness test**

Add `test_reference_brief_draft_converts_to_article_with_source_metadata`.

Expected article fields:

```php
$this->assertSame('geo_reference_content', $article->metadata['source'] ?? null);
$this->assertContains('https://example.test/hengsen-guide', $article->metadata['reference_urls'] ?? []);
```

If `articles` table does not have a metadata JSON column, use an existing flexible field already used by the application. If none exists, add a migration `add_geo_metadata_to_articles_table`.

- [ ] **Step 2: Run test and verify failure**

Run:

```bash
php artisan test --compact --filter=test_reference_brief_draft_converts_to_article_with_source_metadata
```

Expected: FAIL because reference metadata is not persisted.

- [ ] **Step 3: Implement conversion metadata**

In `GeoArticlePublisher`, preserve:

- `source = geo_reference_content`
- `geo_writing_task_id`
- `reference_urls`
- `reference_titles`
- `target_question`
- `brand_profile_id` when available

- [ ] **Step 4: Add readiness panel**

On draft edit page, show:

- Brief source.
- Reference count.
- Last audit score.
- Publish readiness label: `可发布`, `需要补充`, `禁止发布`.

- [ ] **Step 5: Run tests**

Run:

```bash
php artisan test --compact tests/Feature/AdminGeoWorkspaceTest.php
```

Expected: PASS.

## Task 4: Post-Publish Retest

**Files:**
- Create: `app/Services/Geo/GeoPostPublishRetestRunner.php`
- Modify: `app/Http/Controllers/Admin/GeoWorkspaceController.php`
- Modify: `routes/web.php`
- Optional create migration: `database/migrations/2026_05_17_050000_create_geo_publish_retests_table.php`
- Test: `tests/Feature/AdminGeoWorkspaceTest.php`

- [ ] **Step 1: Write failing retest test**

Add `test_admin_can_run_post_publish_retest_for_converted_article`.

Expected behavior:

- Convert a GEO draft to article.
- Run post-publish retest.
- It creates either a new `GeoAiSearchRun` linked by metadata or a `geo_publish_retests` row.
- It stores before score, after score, article URL, and summary.

- [ ] **Step 2: Run test and verify failure**

Run:

```bash
php artisan test --compact --filter=test_admin_can_run_post_publish_retest_for_converted_article
```

Expected: FAIL because retest route/service does not exist.

- [ ] **Step 3: Implement retest storage**

Prefer a small new table:

```text
geo_publish_retests
- id
- organization_id
- article_id
- geo_article_draft_id
- before_score
- after_score
- status
- article_url
- summary
- metadata
- tested_at
```

- [ ] **Step 4: Implement retest runner**

First version can be deterministic:

- Read draft and article.
- Build one question from `writingTask.brief.question`, article title, or SEO title.
- Run existing `GeoSearchBatchRunner` only when a real/search platform is selected.
- In mock mode, compute after score from audit score and content completeness.

- [ ] **Step 5: Add UI action**

After a draft is converted, show:

```text
发布后复测
```

- [ ] **Step 6: Run tests**

Run:

```bash
php artisan test --compact tests/Feature/AdminGeoWorkspaceTest.php
```

Expected: PASS.

## Task 5: Production Queue And Retry Safety

**Files:**
- Create: `app/Jobs/GeoBatchCrawlCitationSourcesJob.php`
- Create: `app/Jobs/GeoBatchScoreCitationSourcesJob.php`
- Create: `app/Jobs/GeoPostPublishRetestJob.php`
- Modify: `app/Http/Controllers/Admin/GeoWorkspaceController.php`
- Test: `tests/Feature/GeoProductionReleaseReadinessTest.php`

- [ ] **Step 1: Write failing queue dispatch test**

Expected:

```php
Bus::fake();
$this->post(route('admin.geo.citation-sources.batch-crawl'), ['source_ids' => [$source->id]]);
Bus::assertDispatched(GeoBatchCrawlCitationSourcesJob::class);
```

- [ ] **Step 2: Run test and verify failure**

Run:

```bash
php artisan test --compact --filter=test_batch_crawl_dispatches_job_when_async_enabled
```

Expected: FAIL because jobs do not exist.

- [ ] **Step 3: Implement jobs**

Jobs should:

- Accept organization id and selected source ids.
- Reload sources scoped to organization.
- Process each source.
- Catch per-source failures and save failure status.
- Not fail the whole batch for one bad URL.

- [ ] **Step 4: Keep sync fallback**

In local/testing mode, current synchronous behavior remains available. In production, allow async via config key:

```php
config('geoflow.geo_async_jobs', false)
```

- [ ] **Step 5: Run tests**

Run:

```bash
php artisan test --compact tests/Feature/GeoProductionReleaseReadinessTest.php
```

Expected: PASS.

## Task 6: End-To-End Release Readiness Test

**Files:**
- Create: `tests/Feature/GeoProductionReleaseReadinessTest.php`

- [ ] **Step 1: Write one full-loop test**

The test must execute:

1. Create admin, organization, brand profile.
2. Generate keyword opportunities.
3. Create AI search run.
4. Fake AI answer with two source URLs.
5. Run search and assert sources created.
6. Batch crawl sources.
7. Batch score sources.
8. Generate reference brief.
9. Generate draft from brief.
10. Audit draft.
11. Convert draft to article.
12. Run post-publish retest.

- [ ] **Step 2: Run test and verify failure before missing pieces**

Run:

```bash
php artisan test --compact tests/Feature/GeoProductionReleaseReadinessTest.php
```

Expected: FAIL until Tasks 1-5 are complete.

- [ ] **Step 3: Keep test green**

After each task, rerun this file. When it passes, the feature loop is release-ready.

## Task 7: Browser Smoke Test

**Files:**
- No code unless smoke test reveals UI issue.

- [ ] **Step 1: Start app**

Run:

```bash
php artisan serve --host=127.0.0.1 --port=8017
```

Expected: app responds on `http://127.0.0.1:8017`.

- [ ] **Step 2: Open admin UI**

Open:

```text
http://127.0.0.1:8017/geo_admin/geo
```

Expected:

- GEO 工作台 loads after login.
- 引用来源库 link opens.
- Batch buttons are visible.
- Reference brief list is visible after generation.
- No Blade exception appears.

- [ ] **Step 3: Smoke full loop manually**

Use mock model or configured test model:

- Save brand profile.
- Generate opportunities.
- Create and run search batch.
- Batch crawl and score references.
- Generate brief.
- Generate draft.
- Audit and convert.
- Run retest.

Expected: every page redirects with a success message and no server error.

## Task 8: Release Package

**Files:**
- Create: `docs/geo/release-checklist.md`
- Create: `docs/geo/operator-runbook.md`
- Modify: `.env.example` if new env/config keys are added

- [ ] **Step 1: Write release checklist**

Checklist must include:

```text
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Also include rollback:

```text
php artisan down
restore database backup
git checkout previous release tag
php artisan migrate:rollback --force
php artisan up
```

- [ ] **Step 2: Write operator runbook**

Runbook must include:

- How to configure AI model.
- How to run first GEO search.
- How to batch crawl safely.
- What to do when crawl fails.
- How to audit a draft.
- How to publish.
- How to run post-publish retest.

- [ ] **Step 3: Run final verification**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
php artisan route:list --name=geo --except-vendor
```

Expected:

- Pint passes.
- Full tests pass.
- GEO routes include workspace, search, citation sources, brief draft, audit, publish conversion, and retest.

## Task 9: Final Publish Decision

There are two publish meanings. Complete both if the target is available.

### 9.1 Code Release

- [ ] Ensure working tree contains only intended changes.
- [ ] Commit with a clear message:

```bash
git add app database resources routes tests docs
git commit -m "feat: complete GEO production workflow"
```

- [ ] Push branch:

```bash
git push origin feature/geo-mvp
```

- [ ] Open PR or merge according to repository workflow.

### 9.2 App Deployment

- [ ] Confirm target server/domain/database with the owner.
- [ ] Take database backup before migration.
- [ ] Deploy code.
- [ ] Run migrations.
- [ ] Configure queue worker and scheduler.
- [ ] Configure AI model keys in admin UI.
- [ ] Run one smoke GEO workflow on production with a low-risk mock/test brand.

Do not deploy to a live server without a confirmed target host, backup method, and rollback path.

## Execution Order

Recommended order:

1. Task 1: reference brief to draft.
2. Task 2: production audit.
3. Task 3: publish readiness metadata.
4. Task 4: post-publish retest.
5. Task 5: queue and retry safety.
6. Task 6: end-to-end release readiness test.
7. Task 7: browser smoke test.
8. Task 8: release package.
9. Task 9: code release and deployment decision.

## Self-Review

- Spec coverage: The plan covers implementation, tests, UI, release docs, code release, and deployment boundary.
- Placeholder scan: No placeholder markers or open-ended implementation steps remain; unknown deployment target is explicitly a decision gate.
- Type consistency: `GeoWritingTask.brief[source=reference_content]`, `GeoReferenceBriefBuilder`, and proposed `GeoReferenceDraftGenerator` align with current models and services.
- Safety: Publishing is separated into code release and app deployment so the system is not deployed to an unknown host by accident.
