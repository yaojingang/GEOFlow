# 豆包搜索工作区 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 在现有 AI 可见性页落地豆包 Global/Custom 搜索配置、结构化结果、信源统计和 DeepSeek 竞品证据展示。

**Architecture:** 保留现有 Laravel 服务和表结构，通过 metadata/analysis_json 扩展结果契约；新增纯函数解析与聚合方法，Blade 仅负责渲染和轻量 JSON 操作。

**Tech Stack:** Laravel, PHP 8.x, Eloquent, Blade, Tailwind CSS, PHPUnit.

---

### Task 1: 搜索选项与结果契约

**Files:**
- Modify: `app/Services/GeoFlow/AiVisibility/DoubaoSearchCustomClient.php`
- Modify: `app/Services/GeoFlow/AiVisibility/AiVisibilityResultNormalizer.php`
- Modify: `app/Services/GeoFlow/AiVisibility/AiVisibilitySourceData.php`
- Test: `tests/Unit/AiVisibilityResultNormalizerTest.php`
- Test: `tests/Feature/AiVisibilityServiceTest.php`

- [ ] Add failing tests for Global/Custom count limits, Filter auth/time/sites/block hosts, QueryRewrite/Industry and authority/image metadata.
- [ ] Run focused tests and confirm failure.
- [ ] Implement normalized payload and metadata-only result extensions.
- [ ] Run focused tests and confirm pass.

### Task 2: DeepSeek competitor parser

**Files:**
- Create: `app/Services/GeoFlow/AiVisibility/DeepSeekCompetitorParser.php`
- Modify: `app/Services/GeoFlow/AiVisibility/DeepSeekAnalysisClient.php`
- Modify: `app/Services/GeoFlow/AiVisibility/AiVisibilityResultNormalizer.php`
- Test: `tests/Unit/DeepSeekCompetitorParserTest.php`

- [ ] Add failing tests for JSON/code-fence parsing, URL whitelist, authority labels and invalid fallback.
- [ ] Run tests and confirm failure.
- [ ] Implement parser and include `competitors` plus `analysis_status` in analysis metadata.
- [ ] Run tests and confirm pass.

### Task 3: Analytics aggregates

**Files:**
- Modify: `app/Services/Admin/Analytics/AiVisibilityAnalyticsService.php`
- Modify: `app/Http/Controllers/Admin/AiVisibilityAnalyticsController.php`
- Test: `tests/Feature/AdminAiVisibilityAnalyticsTest.php`

- [ ] Add failing feature assertions for source distribution, authority filters and competitor evidence fields.
- [ ] Implement source and competitor aggregation from existing runs/sources.
- [ ] Run feature test and confirm pass.

### Task 4: V5 Blade workspace

**Files:**
- Modify: `resources/views/admin/analytics/ai-visibility.blade.php`
- Modify: `lang/zh_CN/admin.php`
- Modify: `lang/en/admin.php`

- [ ] Add failing rendered-page assertions for mode tabs, JSON controls, distribution table, competitor table and scroll container.
- [ ] Implement responsive two-column workspace and evidence cards with fixed-height overflow.
- [ ] Run feature tests and `npm run build`.

### Task 5: Verification

- [ ] Run focused PHPUnit, full relevant PHPUnit suite, JS tests, build, PHP syntax, Pint and `git diff --check`.
- [ ] Confirm `git status` contains no secrets and no GitHub push was performed.
