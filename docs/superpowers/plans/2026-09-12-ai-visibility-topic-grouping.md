# AI 可见性监测主题分组 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 在"自由问法采集"与"统计分析"之间引入监测主题（Topic）分组层，保证用户提问自由性的同时，信源分布与竞品证据只在同一主题内聚合，不再跨问题混合。

**Architecture:** 保留 `ai_visibility_runs` 单一事实表并追加可空列（`ai_visibility_topic_id`、`keyword_hash`）；新增 `ai_visibility_topics` 与 `ai_visibility_topic_keywords`（问法变体指纹绑定）两张表。采集时选择主题或按问法指纹自动归类，未匹配的进入"未归类"并可一键归类（同时登记问法变体）。分析服务新增主题筛选维度，竞品/信源面板携带主题列。

**Tech Stack:** Laravel, PHP 8.x, PostgreSQL, Eloquent, Blade, PHPUnit.

**不做的事（YAGNI）：** 不按问题分表；不做语义/embedding 自动归类（二期）；不改 KPI/趋势的按问法平均算法（数学上已是按问法粒度，无混合问题）。

---

### Task 1: 问法规范化与指纹

**Files:**
- Create: `app/Services/GeoFlow/AiVisibility/AiVisibilityKeywordNormalizer.php`
- Test: `tests/Unit/AiVisibilityKeywordNormalizerTest.php`

- [ ] 写失败测试：`test_it_normalizes_case_whitespace_and_punctuation`（断言 `新东方 怎么样？` 与 `xdf怎么样` 分别归一为 `新东方怎么样`、`xdf怎么样`）与 `test_it_hashes_stably`（同一问法不同写法 hash 相同）。
- [ ] 运行确认失败，然后实现：

```php
final class AiVisibilityKeywordNormalizer
{
    public static function normalize(string $keyword): string
    {
        $normalized = mb_strtolower(trim($keyword), 'UTF-8');

        return (string) preg_replace('/[\s\p{P}]+/u', '', $normalized);
    }

    public static function hash(string $keyword): string
    {
        return hash('sha256', self::normalize($keyword));
    }
}
```

- [ ] 测试通过后提交：`feat(ai-visibility): add keyword normalizer for topic grouping`

### Task 2: 主题表、问法绑定表与 runs 扩展列

**Files:**
- Create: `database/migrations/2026_09_12_000001_create_ai_visibility_topics_table.php`
- Modify: `app/Models/AiVisibilityRun.php`（fillable/casts 加 `ai_visibility_topic_id`、`keyword_hash`，新增 `topic()` 关联）
- Create: `app/Models/AiVisibilityTopic.php`（`keywords()` hasMany、`runs()` hasMany）
- Create: `app/Models/AiVisibilityTopicKeyword.php`
- Test: `tests/Feature/AiVisibilityTopicModelTest.php`

- [ ] 写失败测试：创建 Topic 与 TopicKeyword，断言唯一约束（同 topic 下同 keyword_hash 唯一）与 runs 关联；SQLite 内存库跑（现有 `phpunit.xml` 约定）。
- [ ] 迁移内容（沿用 `ai_visibility_runs` 迁移的防御式写法）：

```php
Schema::create('ai_visibility_topics', function (Blueprint $table): void {
    $table->id();
    $table->string('name', 120)->unique();
    $table->text('description')->nullable();
    $table->json('brand_aliases')->nullable(); // 本主题下我的品牌与竞品别名，供分析提示词使用
    $table->timestamps();
});
Schema::create('ai_visibility_topic_keywords', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('ai_visibility_topic_id')->constrained('ai_visibility_topics')->cascadeOnDelete();
    $table->string('keyword', 255);          // 登记时的示例原文
    $table->char('keyword_hash', 64)->unique();
    $table->timestamps();
});
Schema::table('ai_visibility_runs', function (Blueprint $table): void {
    $table->foreignId('ai_visibility_topic_id')->nullable()->after('keyword')->constrained('ai_visibility_topics')->nullOnDelete();
    $table->char('keyword_hash', 64)->nullable()->after('ai_visibility_topic_id');
    $table->index('keyword_hash', 'ai_visibility_runs_keyword_hash_idx');
});
```

- [ ] down() 按反序 drop 新表、dropColumn（包在 `Schema::hasColumn` 判断里）。
- [ ] 测试通过后提交：`feat(ai-visibility): add topic tables and run grouping columns`

### Task 3: 采集归类（选主题 + 指纹自动匹配 + 落 hash）

**Files:**
- Modify: `app/Services/GeoFlow/AiVisibility/AiVisibilityService.php`（`createRun()` 统一计算 `keyword_hash`；`runDoubaoSearchCustom()`、`runDeepSeekAnalysis()`、`runDoubaoSearchThenDeepSeekAnalysis()` 增加末位可选参 `?AiVisibilityTopic $topic = null`；无主题时按 hash 反查 `ai_visibility_topic_keywords` 自动归类）
- Modify: `app/Http/Controllers/Admin/AiVisibilityAnalyticsController.php`（`search()` 校验 `topic_id` 并传入）
- Test: `tests/Feature/AiVisibilityServiceTest.php`、`tests/Feature/AdminAiVisibilityAnalyticsTest.php`

- [ ] 失败测试：① 带 `topic_id` 搜索 → 新 run 的 `topic_id` 与 `keyword_hash` 正确；② 不带主题但问法已登记变体 → 自动归类到该主题；③ 全新问法不选主题 → `topic_id` 为 null 但 hash 有值。
- [ ] `createRun()` 核心改动（其余方法只加透传参数）：

```php
private function createRun(array $attributes, ?AiVisibilityTopic $topic = null): AiVisibilityRun
{
    $keyword = (string) $attributes['keyword'];
    $attributes['keyword_hash'] = AiVisibilityKeywordNormalizer::hash($keyword);
    $attributes['ai_visibility_topic_id'] = $topic?->id
        ?? AiVisibilityTopicKeyword::query()->where('keyword_hash', $attributes['keyword_hash'])->value('ai_visibility_topic_id');

    return AiVisibilityRun::query()->create(array_replace([/* 现有默认值不变 */], $attributes));
}
```

- [ ] 测试通过后提交：`feat(ai-visibility): classify runs into topics on collection`

### Task 4: 未归类一键归类（登记变体）

**Files:**
- Modify: `app/Http/Controllers/Admin/AiVisibilityAnalyticsController.php`（新增 `assignTopic()`）
- Modify: `routes/web.php:167` 旁新增 `Route::post('ai-visibility/assign-topic', [AiVisibilityAnalyticsController::class, 'assignTopic'])->middleware('throttle:admin-sensitive')->name('ai-visibility.assign-topic');`
- Test: `tests/Feature/AdminAiVisibilityAnalyticsTest.php`

- [ ] 失败测试：POST `run_id + topic_id` → run 归入主题；该问法 hash 写入 `ai_visibility_topic_keywords`（幂等，重复 POST 不报错）；再次以相同问法免主题搜索时自动归类（衔接 Task 3 测试）。
- [ ] 实现要点：

```php
public function assignTopic(Request $request): RedirectResponse
{
    $payload = $request->validate([
        'run_id' => ['required', 'integer', 'exists:ai_visibility_runs,id'],
        'topic_id' => ['required', 'integer', 'exists:ai_visibility_topics,id'],
    ]);
    $run = AiVisibilityRun::query()->findOrFail((int) $payload['run_id']);
    $run->update(['ai_visibility_topic_id' => (int) $payload['topic_id']]);
    AiVisibilityTopicKeyword::query()->firstOrCreate(
        ['keyword_hash' => $run->keyword_hash],
        ['ai_visibility_topic_id' => (int) $payload['topic_id'], 'keyword' => $run->keyword],
    );

    return redirect()->route('admin.analytics.ai-visibility', ['ai_run' => $run->id])->with('message', '已归类');
}
```

- [ ] 测试通过后提交：`feat(ai-visibility): assign runs to topics and remember question variants`

### Task 5: 分析服务主题维度

**Files:**
- Modify: `app/Services/Admin/Analytics/AiVisibilityAnalyticsFilter.php`（新增 `topicId` 字段，读取 `ai_topic`，`'all'` 或合法 id；`fromRequest()`/`forDays()` 同步）
- Modify: `app/Services/Admin/Analytics/AiVisibilityAnalyticsService.php`（`sampledRunIds()` 增加主题过滤；`sourceDistribution()`、`competitorEvidence()` 每行携带 `topic` 名称）
- Modify: `app/Http/Controllers/Admin/AiVisibilityAnalyticsController.php`（`__invoke` 传 `visibilityTopics` 列表给视图）
- Test: `tests/Feature/AdminAiVisibilityAnalyticsTest.php`

- [ ] 失败测试：两个主题各 2 条 run → ① 选主题 A：信源分布与竞品只含 A 的数据；② 不选主题：面板行携带 `topic` 字段且不跨主题合并计数（同站点/同竞品在两个主题下是两行）。
- [ ] 过滤实现（`sampledRunIds()` 现有 `->when($filter->keyword ...)` 旁）：

```php
->when($filter !== null && $filter->topicId > 0, fn (Builder $query) => $query->where('ai_visibility_topic_id', $filter->topicId))
```

- [ ] 聚合实现：两个面板方法内按 `$run['topic'] ?? '未归类'` 参与分组键（竞品：`主题|竞品名`；信源：`主题|站点`），行内输出 `topic` 字段；主题名通过一次性查询 `ai_visibility_topics` 建映射。
- [ ] 测试通过后提交：`feat(analytics): scope ai visibility aggregates by topic`

### Task 6: 工作台 UI 与语言包

**Files:**
- Modify: `resources/views/admin/analytics/ai-visibility.blade.php`（搜索表单加主题下拉 `topic_id`；选中 run 详情区为未归类 run 显示"归入主题"下拉 + 按钮；信源分布与竞品表格增加"主题"列）
- Modify: `lang/zh_CN/admin.php`、`lang/en/admin.php`（`ai_visibility.topic`、`topic_all`、`assign_topic`、`uncategorized` 等键，中英同步）
- Test: `tests/Feature/AdminAiVisibilityAnalyticsTest.php`（渲染断言：页面含 `name="topic_id"` 下拉与主题列头）

- [ ] 失败渲染断言 → 实现 Blade 改动（沿用页面现有 Tailwind 约定；下拉选项 `__('admin.analytics.pages.ai_visibility.topic_all')` + 各主题）。
- [ ] **注意 Blade 相邻指令坑：`@endforeach` 与 `@if(` 之间必须留空白，否则两个指令都不编译（见 3246d01 提交教训）。**
- [ ] 测试通过后提交：`feat(analytics): add topic selector and assignment UI to ai visibility workspace`

### Task 7: 历史 run 的 hash 回填命令

**Files:**
- Create: `app/Console/Commands/GeoFlowBackfillAiVisibilityKeywordHashes.php`（命令签名 `geoflow:ai-visibility:backfill-keyword-hashes`，分块 500，`chunkById` 更新 `keyword_hash` 为 null 的行；命中已登记问法绑定的 run 同时自动归类，其余主题不回填，留给人工归类）
- Test: `tests/Feature/AiVisibilityBackfillHashesTest.php`

- [ ] 失败测试：造 3 条 `keyword_hash` 为 null 的 run → 跑命令 → 全部回填且重复问法 hash 一致；再次跑命令幂等（更新 0 行）。
- [ ] 测试通过后提交：`feat(ai-visibility): backfill keyword hashes for existing runs`

### Task 8: 验证

- [ ] 聚焦测试：`vendor/bin/phpunit tests/Unit/AiVisibilityKeywordNormalizerTest.php tests/Feature/AiVisibilityTopicModelTest.php tests/Feature/AiVisibilityServiceTest.php tests/Feature/AdminAiVisibilityAnalyticsTest.php tests/Feature/AiVisibilityBackfillHashesTest.php`
- [ ] 全量相关套件 + `php -l` 改动文件 + `vendor/bin/pint --test`（Windows 下用 `C:\Users\Wind\.local\bin\php.cmd`）
- [ ] `git status` 确认无密钥、无截图等杂物；确认未推送前不执行 `git push`（推送与上线由用户决定）

---

## 自查记录

- 覆盖面：归类（选主题/自动匹配/一键归类+记忆变体）→ Task 3/4；聚合不跨主题 → Task 5；UI 与自由问法不受限 → Task 6；历史数据 → Task 7；未匹配不阻塞采集 → Task 3（topic_id 可空）。
- 类型一致性：hash 统一 `char(64)` sha256，列名统一 `keyword_hash`/`ai_visibility_topic_id`；`AiVisibilityKeywordNormalizer::hash()` 为 Task 3/4/7 共用。
- 已知取舍：`keyword_hash` 在绑定表上全局唯一（一个问法最多归属一个主题，重新归类即改绑）；主题过滤基于 run 上的 `ai_visibility_topic_id` 冗余列，归类后旧 run 不自动跟随新变体（保持历史事实），由分析端按列过滤。
