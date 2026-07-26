# Admin Analytics Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new admin “数据分析” section after “首页” that centralizes content operations analytics and prepares a structured log analytics pipeline for website, channel-site, and AI crawler traffic.

**Architecture:** Keep the existing dashboard as the operational entry page and move deeper analytical views into a new `Admin\AnalyticsController`. Reuse existing dashboard query logic through focused services, then add a separate analytics log ingestion layer with normalized events and daily aggregates. The first runnable slice should show database-backed content analytics immediately, while log parsing lands behind clear empty states and import jobs.

**Tech Stack:** Laravel 12, Blade, Tailwind utility classes already used by the admin, PostgreSQL, Redis queue/scheduler, PHPUnit feature/unit tests, Laravel migrations and Eloquent models.

---

## Scope

### Included

- Add top navigation item `数据分析` immediately after `首页`.
- Add `/admin/analytics` page with date, site/channel, content, traffic-type, and log-source filters.
- Reuse current dashboard metrics for content, task, material, AI, URL import, distribution, and popular article analytics.
- Add content operation charts: publication trend, task status trend, content funnel, AI/API usage summary, distribution status, top content.
- Add log analytics design and first implementation shell: log source registry, import records, crawler classifier, parsed event storage, aggregate tables, and empty states.
- Support future Nginx/Apache daily access log parsing and target channel-site log sync.

### Deferred Beyond First Runnable Slice

- Full remote channel log upload from generated target-site package can be implemented after the local log import path is stable.
- Geographic analysis should not be included until an IP geolocation database or provider is explicitly configured.
- Real-time streaming charts are not part of the first version; daily and hourly aggregation is enough.

---

## File Map

### Routes and Navigation

- Modify `routes/web.php`
  - Add `use App\Http\Controllers\Admin\AnalyticsController;`
  - Add `Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');`
- Modify `resources/views/admin/partials/header.blade.php`
  - Insert `analytics` menu after `dashboard`.
  - Add analytics route names to `$subMap`.
- Modify `lang/zh_CN/admin.php`
  - Add `admin.nav.analytics`.
  - Add `admin.analytics.*` copy.
- Modify `lang/en/admin.php`
  - Add English fallback copy for tests and language switching.

### Controllers and Services

- Create `app/Http/Controllers/Admin/AnalyticsController.php`
  - Owns request filters and page rendering.
  - Does not contain raw query logic.
- Create `app/Services/Admin/AdminDashboardMetricsService.php`
  - Extract reusable logic currently private in `DashboardController`.
  - Provides existing dashboard stats and chart series.
- Modify `app/Http/Controllers/Admin/DashboardController.php`
  - Delegate metrics to `AdminDashboardMetricsService`.
  - Keep dashboard behavior unchanged.
- Create `app/Services/Admin/Analytics/AnalyticsFilter.php`
  - Normalizes `date_from`, `date_to`, `channel_id`, `task_id`, `category_id`, `article_id`, `traffic_type`, and `log_source`.
- Create `app/Services/Admin/Analytics/AnalyticsOverviewService.php`
  - Builds content operation summaries and chart datasets.
- Create `app/Services/Admin/Analytics/AnalyticsLogQueryService.php`
  - Builds traffic summaries from parsed log aggregates.

### Log Analytics Data Layer

- Create migration `database/migrations/2026_05_21_000000_create_analytics_tables.php`
  - `analytics_log_sources`
  - `analytics_log_imports`
  - `analytics_log_events`
  - `analytics_daily_metrics`
  - `analytics_path_daily_metrics`
  - `analytics_article_daily_metrics`
  - `analytics_bot_daily_metrics`
- Create models:
  - `app/Models/AnalyticsLogSource.php`
  - `app/Models/AnalyticsLogImport.php`
  - `app/Models/AnalyticsLogEvent.php`
  - `app/Models/AnalyticsDailyMetric.php`
  - `app/Models/AnalyticsPathDailyMetric.php`
  - `app/Models/AnalyticsArticleDailyMetric.php`
  - `app/Models/AnalyticsBotDailyMetric.php`

### Log Parsing and Import

- Create `app/Services/Admin/Analytics/AccessLogParser.php`
  - Parses Nginx/Apache common and combined log lines.
- Create `app/Services/Admin/Analytics/CrawlerClassifier.php`
  - Classifies user agents into `human`, `search_bot`, `ai_bot`, `other_bot`, and `unknown`.
- Create `app/Services/Admin/Analytics/AnalyticsLogImporter.php`
  - Imports lines, stores normalized events, and updates aggregate tables.
- Create `app/Console/Commands/GeoFlowImportAnalyticsLogsCommand.php`
  - Command: `php artisan geoflow:analytics-import-logs --source=local --date=2026-05-21`
- Modify `routes/console.php` or scheduler registration if the project uses scheduled commands there.

### Views

- Create `resources/views/admin/analytics/index.blade.php`
  - Uses existing admin page shell and card styles.
  - Includes filter form, KPI cards, content analytics section, log analytics section, and empty states.
- Create partials:
  - `resources/views/admin/analytics/_filters.blade.php`
  - `resources/views/admin/analytics/_kpis.blade.php`
  - `resources/views/admin/analytics/_content-section.blade.php`
  - `resources/views/admin/analytics/_log-section.blade.php`
  - `resources/views/admin/analytics/_line-chart.blade.php`
  - `resources/views/admin/analytics/_bar-chart.blade.php`
  - `resources/views/admin/analytics/_funnel.blade.php`

### Tests

- Create `tests/Feature/AdminAnalyticsPageTest.php`
- Create `tests/Unit/AnalyticsFilterTest.php`
- Create `tests/Unit/AccessLogParserTest.php`
- Create `tests/Unit/CrawlerClassifierTest.php`
- Create `tests/Unit/AnalyticsLogImporterTest.php`
- Update `tests/Feature/AdminDashboardQuickStartTest.php` if dashboard metrics extraction changes rendered strings.

---

## UI Design

### Page Layout

1. Header row
   - Title: `数据分析`
   - Subtitle: `按日期、站点、内容和日志来源查看内容生产与访问趋势`
   - Right side: last updated time and refresh button

2. Filter band
   - Date preset buttons: 今天、昨天、近 7 天、近 30 天、近 90 天
   - Custom date range inputs
   - Channel selector
   - Task/category/article selectors
   - Traffic type selector
   - Log source selector

3. KPI grid
   - 8 cards, responsive 4 columns desktop, 2 columns tablet, 1 column mobile
   - Short labels never wrap; descriptions can wrap

4. Content analytics section
   - Two-column chart grid on desktop
   - Tables below charts for top content and distribution status

5. Log analytics section
   - Empty state before logs are imported
   - After import: traffic trend, AI crawler breakdown, top pages, status code distribution, top referrers

### Visual Constraints

- Follow current admin UI: white cards, light gray background, blue primary actions, restrained borders.
- Use Lucide icons already loaded by the admin.
- Do not introduce a new JS chart dependency in the first slice. Use Blade-generated SVG charts and tables to match the current dashboard.
- Keep operational data dense. Avoid marketing-style hero panels.
- Long titles and URLs may wrap; short numeric/status fields should use `whitespace-nowrap`.

---

## Data Model

### `analytics_log_sources`

Purpose: defines where logs come from.

Columns:

- `id`
- `name`
- `source_type`: `local_file`, `uploaded_file`, `channel_agent`
- `channel_id`: nullable FK to `distribution_channels`
- `path_pattern`: nullable string, e.g. `/www/wwwlogs/example.com.log`
- `status`: `active`, `paused`
- `last_imported_at`
- `created_at`, `updated_at`

### `analytics_log_imports`

Purpose: records each import run.

Columns:

- `id`
- `source_id`
- `log_date`
- `file_path`
- `file_hash`
- `line_count`
- `parsed_count`
- `skipped_count`
- `status`: `pending`, `running`, `completed`, `failed`
- `error_message`
- `started_at`, `finished_at`
- `created_at`, `updated_at`

### `analytics_log_events`

Purpose: stores normalized parsed requests for drill-down and re-aggregation.

Columns:

- `id`
- `source_id`
- `import_id`
- `channel_id`
- `article_id`
- `occurred_at`
- `host`
- `method`
- `path`
- `query_hash`
- `status_code`
- `bytes_sent`
- `request_time_ms`
- `ip_hash`
- `user_agent`
- `crawler_family`
- `traffic_type`
- `referer_host`
- `created_at`

Indexes:

- `(occurred_at)`
- `(channel_id, occurred_at)`
- `(article_id, occurred_at)`
- `(traffic_type, occurred_at)`
- `(crawler_family, occurred_at)`
- `(path, occurred_at)`

### Aggregate Tables

- `analytics_daily_metrics`: one row per date/channel/source.
- `analytics_path_daily_metrics`: one row per date/path/channel/source.
- `analytics_article_daily_metrics`: one row per date/article/channel/source.
- `analytics_bot_daily_metrics`: one row per date/crawler_family/channel/source.

Aggregates should store:

- `pv`
- `unique_ip_count`
- `human_pv`
- `search_bot_pv`
- `ai_bot_pv`
- `other_bot_pv`
- `error_4xx`
- `error_5xx`

---

## Task Plan

### Task 1: Add Analytics Navigation and Empty Page

**Files:**

- Create: `app/Http/Controllers/Admin/AnalyticsController.php`
- Create: `resources/views/admin/analytics/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/partials/header.blade.php`
- Modify: `lang/zh_CN/admin.php`
- Modify: `lang/en/admin.php`
- Test: `tests/Feature/AdminAnalyticsPageTest.php`

- [ ] Step 1: Write feature test for route and nav.

Test expectations:

- `route('admin.analytics')` returns 200 for authenticated admin.
- Page contains `数据分析`.
- Header menu contains 首页 before 数据分析.
- Data analysis menu is active on `/admin/analytics`.

Run:

```bash
docker exec geoflow-app php artisan test tests/Feature/AdminAnalyticsPageTest.php --filter=analytics_page
```

Expected before implementation: route not defined or page not found.

- [ ] Step 2: Add route and controller shell.

Controller returns:

- `pageTitle`: `__('admin.analytics.page_title')`
- `activeMenu`: `analytics`
- `filters`: default normalized values
- empty arrays for `kpis`, `content`, and `logs`

- [ ] Step 3: Add Blade page shell.

Use existing admin layout:

- `@extends('admin.layouts.app')`
- header row
- filter section with default date and source controls
- KPI empty cards
- content analytics empty state
- log analytics empty state

- [ ] Step 4: Add menu item.

In `$menu`, insert:

```php
'analytics' => ['route' => 'admin.analytics', 'name' => __('admin.nav.analytics')],
```

immediately after `dashboard`.

- [ ] Step 5: Run test and targeted header tests.

```bash
docker exec geoflow-app php artisan test tests/Feature/AdminAnalyticsPageTest.php tests/Feature/AdminHeaderNotificationTest.php --compact
```

Expected: pass.

---

### Task 2: Extract Reusable Dashboard Metrics

**Files:**

- Create: `app/Services/Admin/AdminDashboardMetricsService.php`
- Modify: `app/Http/Controllers/Admin/DashboardController.php`
- Test: `tests/Feature/AdminDashboardQuickStartTest.php`

- [ ] Step 1: Write regression test for dashboard rendering.

Use existing `AdminDashboardQuickStartTest` and add assertions for:

- total articles card
- active tasks card
- popular articles section
- content funnel section

- [ ] Step 2: Move private query methods from `DashboardController` into `AdminDashboardMetricsService`.

Service public methods:

- `stats(): array`
- `todayStats(): array`
- `weekStats(): array`
- `categoryDistribution(): array`
- `latestArticles(): array`
- `articleTrendSeries(int $days = 7): array`
- `articleTrendChartPaths(array $articleTrend): array`
- `performanceStats(int $completedTasks, int $failedJobs): array`
- `contentFunnel(array $stats): array`
- `taskHealth(): array`
- `materialHealth(): array`
- `aiHealth(): array`
- `urlImportHealth(): array`
- `popularArticles(int $limit = 5): array`
- `todoItems(array $stats, array $materialHealth, array $aiHealth, array $urlImportHealth): array`

- [ ] Step 3: Update `DashboardController` to call the service.

The view payload must stay the same so the dashboard UI does not change.

- [ ] Step 4: Run dashboard tests.

```bash
docker exec geoflow-app php artisan test tests/Feature/AdminDashboardQuickStartTest.php --compact
```

Expected: pass.

---

### Task 3: Add Analytics Filters

**Files:**

- Create: `app/Services/Admin/Analytics/AnalyticsFilter.php`
- Modify: `app/Http/Controllers/Admin/AnalyticsController.php`
- Modify: `resources/views/admin/analytics/_filters.blade.php`
- Modify: `resources/views/admin/analytics/index.blade.php`
- Test: `tests/Unit/AnalyticsFilterTest.php`

- [ ] Step 1: Write filter normalization unit tests.

Cases:

- no input defaults to last 7 days
- `preset=today` sets both dates to current date
- `preset=30d` sets `date_from` to today minus 29 days and `date_to` to today
- invalid date falls back to default range
- `date_from` later than `date_to` swaps or clamps to a valid range
- channel/task/category/article IDs are integers or null

- [ ] Step 2: Implement immutable filter value object.

Properties:

- `dateFrom`
- `dateTo`
- `channelId`
- `taskId`
- `categoryId`
- `articleId`
- `trafficType`
- `logSource`
- `preset`

- [ ] Step 3: Render filter form.

Use `GET` form so filtered URLs are shareable.

- [ ] Step 4: Run filter tests.

```bash
docker exec geoflow-app php artisan test tests/Unit/AnalyticsFilterTest.php tests/Feature/AdminAnalyticsPageTest.php --compact
```

Expected: pass.

---

### Task 4: Content Analytics Query Service

**Files:**

- Create: `app/Services/Admin/Analytics/AnalyticsOverviewService.php`
- Modify: `app/Http/Controllers/Admin/AnalyticsController.php`
- Modify: `resources/views/admin/analytics/_kpis.blade.php`
- Modify: `resources/views/admin/analytics/_content-section.blade.php`
- Test: `tests/Feature/AdminAnalyticsPageTest.php`

- [ ] Step 1: Write feature test with seeded articles, tasks, task runs, AI models, and distributions.

Assertions:

- KPI cards show total articles, published articles, running tasks, failed jobs, AI calls, distribution failures.
- Publication trend includes seeded dates.
- Top content table includes seeded article title.
- Distribution status includes synced and failed counts.

- [ ] Step 2: Implement `AnalyticsOverviewService`.

Methods:

- `kpis(AnalyticsFilter $filter): array`
- `publicationTrend(AnalyticsFilter $filter): array`
- `taskTrend(AnalyticsFilter $filter): array`
- `contentFunnel(AnalyticsFilter $filter): array`
- `distributionSummary(AnalyticsFilter $filter): array`
- `topContent(AnalyticsFilter $filter, int $limit = 10): array`
- `aiUsageSummary(AnalyticsFilter $filter): array`

- [ ] Step 3: Wire service into controller.

Controller passes:

- `kpis`
- `publicationTrend`
- `taskTrend`
- `contentFunnel`
- `distributionSummary`
- `topContent`
- `aiUsageSummary`

- [ ] Step 4: Render content analytics section.

Charts:

- publication trend line chart
- task status stacked bars
- content funnel horizontal bars
- distribution status cards
- top content table

- [ ] Step 5: Run feature tests.

```bash
docker exec geoflow-app php artisan test tests/Feature/AdminAnalyticsPageTest.php --compact
```

Expected: pass.

---

### Task 5: Analytics Log Schema and Models

**Files:**

- Create: `database/migrations/2026_05_21_000000_create_analytics_tables.php`
- Create: `app/Models/AnalyticsLogSource.php`
- Create: `app/Models/AnalyticsLogImport.php`
- Create: `app/Models/AnalyticsLogEvent.php`
- Create: `app/Models/AnalyticsDailyMetric.php`
- Create: `app/Models/AnalyticsPathDailyMetric.php`
- Create: `app/Models/AnalyticsArticleDailyMetric.php`
- Create: `app/Models/AnalyticsBotDailyMetric.php`
- Test: `tests/Unit/AnalyticsSchemaMigrationTest.php`

- [ ] Step 1: Write schema migration test.

Assertions:

- all analytics tables exist
- required columns exist
- indexes exist for date, channel, article, traffic type, crawler family

- [ ] Step 2: Create migration.

Use guarded `Schema::hasTable` checks to keep migrations safe across local iterations.

- [ ] Step 3: Create Eloquent models.

Each model should define:

- `$fillable`
- `$casts`
- relationships where applicable

- [ ] Step 4: Run schema tests.

```bash
docker exec geoflow-app php artisan test tests/Unit/AnalyticsSchemaMigrationTest.php --compact
```

Expected: pass.

---

### Task 6: Access Log Parser and Crawler Classifier

**Files:**

- Create: `app/Services/Admin/Analytics/AccessLogParser.php`
- Create: `app/Services/Admin/Analytics/CrawlerClassifier.php`
- Test: `tests/Unit/AccessLogParserTest.php`
- Test: `tests/Unit/CrawlerClassifierTest.php`

- [ ] Step 1: Write parser tests.

Supported examples:

```text
127.0.0.1 - - [21/May/2026:10:15:30 +0800] "GET /article/demo HTTP/1.1" 200 1234 "-" "Mozilla/5.0"
127.0.0.1 - - [21/May/2026:10:15:31 +0800] "GET /geoflow/article/demo HTTP/1.1" 404 512 "https://example.com" "Googlebot/2.1"
```

Expected parsed fields:

- IP
- occurred_at
- method
- path
- protocol
- status_code
- bytes_sent
- referer
- user_agent

- [ ] Step 2: Write classifier tests.

Required classifications:

- `Googlebot` => `search_bot`
- `Baiduspider` => `search_bot`
- `bingbot` => `search_bot`
- `GPTBot` => `ai_bot`
- `ChatGPT-User` => `ai_bot`
- `ClaudeBot` => `ai_bot`
- `PerplexityBot` => `ai_bot`
- `Bytespider` => `ai_bot`
- normal browser UA => `human`
- empty UA => `unknown`

- [ ] Step 3: Implement parser and classifier.

Classifier should return:

- `traffic_type`
- `crawler_family`
- `is_bot`

- [ ] Step 4: Run unit tests.

```bash
docker exec geoflow-app php artisan test tests/Unit/AccessLogParserTest.php tests/Unit/CrawlerClassifierTest.php --compact
```

Expected: pass.

---

### Task 7: Log Importer and Aggregation

**Files:**

- Create: `app/Services/Admin/Analytics/AnalyticsLogImporter.php`
- Create: `app/Console/Commands/GeoFlowImportAnalyticsLogsCommand.php`
- Modify: `bootstrap/app.php` or command registration path used by this Laravel 12 app
- Test: `tests/Unit/AnalyticsLogImporterTest.php`
- Test: `tests/Feature/AnalyticsImportCommandTest.php`

- [ ] Step 1: Write importer test.

Seed:

- one log source
- one article with slug `demo-article`
- log lines for `/article/demo-article`, `/missing`, and `/`

Assertions:

- import row is completed
- events are created
- article_id is mapped for `/article/demo-article`
- daily aggregate increments PV
- path aggregate records `/missing` 404
- bot aggregate records AI crawler counts

- [ ] Step 2: Implement importer.

Importer responsibilities:

- read file line by line
- parse each line
- classify user agent
- hash IP with HMAC using app key
- map path to article when possible
- create events in chunks
- update aggregate tables
- record skipped lines

- [ ] Step 3: Implement artisan command.

Command:

```bash
php artisan geoflow:analytics-import-logs --source=local --date=2026-05-21
```

Behavior:

- finds active source by name or ID
- builds file path from source pattern and date
- imports once per hash unless `--force` is passed
- prints parsed, skipped, and status summary

- [ ] Step 4: Run importer tests.

```bash
docker exec geoflow-app php artisan test tests/Unit/AnalyticsLogImporterTest.php tests/Feature/AnalyticsImportCommandTest.php --compact
```

Expected: pass.

---

### Task 8: Log Analytics Query and UI

**Files:**

- Modify: `app/Services/Admin/Analytics/AnalyticsLogQueryService.php`
- Modify: `app/Http/Controllers/Admin/AnalyticsController.php`
- Modify: `resources/views/admin/analytics/_log-section.blade.php`
- Test: `tests/Feature/AdminAnalyticsPageTest.php`

- [ ] Step 1: Write feature test with aggregate rows.

Assertions:

- traffic trend shows PV and AI crawler count
- bot breakdown shows GPTBot or ChatGPT family
- top pages table shows URL and status code counts
- empty state is hidden when log data exists

- [ ] Step 2: Implement query service.

Methods:

- `trafficKpis(AnalyticsFilter $filter): array`
- `trafficTrend(AnalyticsFilter $filter): array`
- `botBreakdown(AnalyticsFilter $filter): array`
- `topPaths(AnalyticsFilter $filter, int $limit = 10): array`
- `topArticles(AnalyticsFilter $filter, int $limit = 10): array`
- `statusCodeSummary(AnalyticsFilter $filter): array`
- `topReferrers(AnalyticsFilter $filter, int $limit = 10): array`

- [ ] Step 3: Render log analytics section.

Cards:

- PV
- unique IP
- AI crawler PV
- 4xx/5xx error count

Charts/tables:

- traffic trend line chart
- crawler breakdown bar chart
- top pages table
- top articles table
- status code distribution

- [ ] Step 4: Run analytics page tests.

```bash
docker exec geoflow-app php artisan test tests/Feature/AdminAnalyticsPageTest.php --compact
```

Expected: pass.

---

### Task 9: Polish, Cache, and Full Verification

**Files:**

- Modify only files touched by earlier tasks.

- [ ] Step 1: Run formatter.

```bash
docker exec geoflow-app ./vendor/bin/pint app/Http/Controllers/Admin/AnalyticsController.php app/Services/Admin/AdminDashboardMetricsService.php app/Services/Admin/Analytics tests/Feature/AdminAnalyticsPageTest.php tests/Unit/AnalyticsFilterTest.php tests/Unit/AccessLogParserTest.php tests/Unit/CrawlerClassifierTest.php tests/Unit/AnalyticsLogImporterTest.php
```

Expected: PASS.

- [ ] Step 2: Run targeted analytics tests.

```bash
docker exec geoflow-app php artisan test tests/Feature/AdminAnalyticsPageTest.php tests/Unit/AnalyticsFilterTest.php tests/Unit/AccessLogParserTest.php tests/Unit/CrawlerClassifierTest.php tests/Unit/AnalyticsLogImporterTest.php --compact
```

Expected: all pass.

- [ ] Step 3: Run full suite.

```bash
docker exec geoflow-app php artisan test --compact
```

Expected: all pass.

- [ ] Step 4: Clear cache.

```bash
docker exec geoflow-app php artisan optimize:clear
```

Expected: config, cache, compiled, events, routes, and views cleared.

- [ ] Step 5: Browser smoke test.

Open:

- `http://localhost:18080/admin/analytics`
- `http://localhost:18080/admin/dashboard`

Verify:

- menu order is correct
- analytics menu active state works
- date filters submit without layout breakage
- content cards render
- log section shows empty state when no imports exist
- dashboard still renders existing quick-start and health modules

---

## Recommended Implementation Split

### First Development Batch

Implement Tasks 1 through 4.

Outcome:

- New “数据分析” menu and page exists.
- Current dashboard data is available in analytics form.
- Content operation analytics is usable without waiting for log parsing.

### Second Development Batch

Implement Tasks 5 through 8.

Outcome:

- Log tables, parser, crawler classifier, importer command, and log analytics UI exist.
- Local Nginx/Apache logs can be imported manually and analyzed.

### Third Development Batch

Add target channel-site log sync.

Outcome:

- Target site package can expose or push daily access logs.
- Main admin can analyze group-site/channel traffic and AI crawler visits by channel.

---

## Acceptance Criteria

- Admin menu shows `首页` followed by `数据分析`.
- `/admin/analytics` is protected by admin auth and follows the existing backend UI style.
- Date filters affect content operation metrics.
- Content analytics shows publication trend, task trend, funnel, AI/API summary, distribution status, and top content.
- Log analytics shows a useful empty state before import.
- After importing a supported access log, analytics show PV, unique IP count, AI crawler PV, top paths, top articles, status codes, and crawler breakdown.
- Tests cover route access, filter normalization, dashboard regression, parser behavior, crawler classification, import aggregation, and analytics rendering.
- Full test suite passes before implementation is considered complete.

---

## Open Decision Before Development

Recommended first build scope: **Tasks 1 through 4 only**, then review the page in the browser. This creates a useful analytics page quickly and reduces risk before adding log ingestion tables and parser behavior.

If the first development batch is approved, implementation should start with Task 1 and stop after Task 4 for a visual and functional review.
