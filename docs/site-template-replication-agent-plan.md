# GEOFlow 网站模板复刻 Agent 实施方案

## 1. 背景与目标

当前后台的「网站设置 - 网站模板」已经支持从内置主题中选择前台模板，主题目录位于 `resources/views/theme/{主题目录名}`，并通过 `manifest.json`、Blade 页面、partials 和独立 CSS 组成一套可切换的前台展示层。

本方案要新增「复刻模板」能力：管理员输入 3 个对标页面 URL，分别对应首页、列表页、文章详情页，系统 Agent 自动分析页面视觉、布局和组件风格，生成一套新的 GEOFlow 前台主题，并支持预览、自然语言微调、确认启用。

目标不是复制对标站内容，而是将其页面结构、视觉节奏、排版习惯和组件风格转换成符合 GEOFlow 数据契约的主题包。

## 2. 核心原则

1. 只复刻展示层，不改变文章、分类、SEO、知识库、任务和分发等业务数据结构。
2. 生成主题必须沿用 GEOFlow 现有主题能力，包括 Blade、partials、独立 CSS、manifest、mapping、tokens。
3. Agent 只生成受控主题文件，不允许生成任意 PHP 逻辑、命令执行代码或外部请求代码。
4. 复刻过程先生成草稿，管理员确认后才可保存为正式主题或切换为当前生效模板。
5. 使用本机 GEOFlow 内容做预览，避免把对标站正文、图片、品牌素材复制到生产主题中。
6. 支持反复微调，但每次微调都应形成可回退的草稿版本。

## 3. 不做的范围

- 不复制对标网站的文章正文、品牌标识、图片素材、视频素材或版权内容。
- 不直接修改当前正在使用的主题。
- 不让大模型自由生成可执行 PHP 代码。
- 首版不做完整浏览器像素级还原，优先实现可控、可维护、可预览的主题生成。
- 首版不做跨站登录、绕过反爬、破解防护或批量抓取。
- 首版不把复刻能力开放给普通管理员，建议仅超级管理员可用。

## 4. 当前系统适配点

### 4.1 已有主题机制

现有主题主要由以下文件组成：

```text
resources/views/theme/{theme_id}/
  manifest.json
  tokens.json
  mapping.json
  layout.blade.php
  home.blade.php
  category.blade.php
  article.blade.php
  archive-index.blade.php
  archive-month.blade.php
  partials/
  assets/theme.css
```

当前 `SiteThemeCatalog` 会扫描 `resources/views/theme`，`SiteThemeViewResolver` 会优先读取当前主题的 `home`、`category`、`article` 等 Blade 文件，缺失时回退到默认前台页面。

### 4.2 推荐扩展方式

为了避免生产 Docker 环境中直接写入代码目录，建议新增「生成主题目录」：

```text
storage/app/generated-themes/{theme_id}/
  manifest.json
  tokens.json
  mapping.json
  layout.blade.php
  home.blade.php
  category.blade.php
  article.blade.php
  partials/
  assets/theme.css
```

实现时扩展主题目录扫描和视图解析：

- 内置主题继续来自 `resources/views/theme`。
- Agent 生成主题来自 `storage/app/generated-themes`。
- 预览和启用时都通过统一主题目录读取。
- 后续如果需要把主题纳入 Git 版本管理，可提供「导出主题包」功能。

## 5. 用户流程设计

### 5.1 入口

在 `http://localhost:18080/admin/site-settings` 的「网站模板」模块顶部增加按钮：

- 按钮名称：`复刻模板`
- 位置：当前生效模板说明区域右侧，和主题选择操作保持一致。
- 权限：仅超级管理员显示。

点击后进入独立页面，推荐路由：

```text
GET /admin/site-settings/theme-replication
```

### 5.2 第一步：输入对标 URL

页面包含以下字段：

- 模板名称：必填，例如「某某资讯风格」。
- 模板标识：可选，系统自动根据名称生成 slug。
- 首页 URL：必填。
- 列表页 URL：必填。
- 文章详情页 URL：必填。
- 风格说明：可选，例如「保留大留白，但文章页字体更紧凑」。

交互约束：

- URL 必须是 `http` 或 `https`。
- 禁止 localhost、内网 IP、Docker 内部域名、file 协议。
- 三个 URL 可以来自同一站点，也可以来自不同页面，但建议同一站点。
- 提交后创建一个复刻任务，而不是同步等待完成。

### 5.3 第二步：Agent 分析进度

提交后进入任务详情页，展示动态进度：

1. 校验链接安全性。
2. 抓取页面 HTML 和关键静态资源。
3. 提取页面结构、颜色、字体、间距和组件层级。
4. 识别首页、列表页、文章页的数据映射关系。
5. 生成主题 tokens、partials、Blade 页面和 CSS。
6. 执行主题安全检查。
7. 渲染本机内容预览。

进度 UI 建议：

- 顶部状态条：等待中、分析中、生成中、预览中、已完成、失败。
- 中间步骤列表：每一步显示状态、耗时和错误原因。
- 右侧结果面板：模板名称、生成主题 ID、当前草稿版本。
- 失败时提供「重试本步骤」「返回修改 URL」。

### 5.4 第三步：三页面预览

生成成功后展示预览区域：

- 首页预览。
- 列表页预览。
- 文章详情页预览。
- 桌面视图和移动视图切换。
- 使用本机真实文章、分类、站点设置和 SEO 数据渲染。

预览建议使用 iframe，避免生成主题 CSS 污染后台页面。

推荐路由：

```text
GET /admin/site-settings/theme-replication/{job}/preview/home
GET /admin/site-settings/theme-replication/{job}/preview/category
GET /admin/site-settings/theme-replication/{job}/preview/article
```

### 5.5 第四步：自然语言微调

预览下方提供「微调说明」输入框：

示例提示：

- 首页首屏更紧凑一些。
- 文章页正文宽度缩小到 760px。
- 列表页减少卡片阴影，更像资讯流。
- 标题字号略小，行距更舒展。
- 移动端导航改为更简洁的顶部栏。

点击「应用调整」后：

1. 保存当前草稿版本。
2. Agent 基于当前主题和微调说明生成新版本。
3. 重新执行安全检查和预览渲染。
4. 管理员可在版本间切换对比。

### 5.6 第五步：确认保存或启用

最终操作：

- 保存为新模板：主题进入可选模板列表，但不立即启用。
- 保存并启用：更新 `active_theme`，前台立即使用新模板。
- 放弃草稿：保留任务日志，可删除草稿文件。

确认启用前需要提示：

- 只影响前台展示层，不影响文章、分类和 SEO 数据。
- 如前台显示异常，可切回原模板。
- 建议先检查首页、列表页、文章页和移动端。

## 6. 后台模块设计

### 6.1 路由建议

```php
Route::prefix('site-settings/theme-replication')->name('site-settings.theme-replication.')->group(function () {
    Route::get('/', [ThemeReplicationController::class, 'create'])->name('create');
    Route::post('/', [ThemeReplicationController::class, 'store'])->name('store');
    Route::get('/{job}', [ThemeReplicationController::class, 'show'])->name('show');
    Route::get('/{job}/status', [ThemeReplicationController::class, 'status'])->name('status');
    Route::post('/{job}/refine', [ThemeReplicationController::class, 'refine'])->name('refine');
    Route::post('/{job}/approve', [ThemeReplicationController::class, 'approve'])->name('approve');
    Route::get('/{job}/preview/{pageType}', [ThemeReplicationController::class, 'preview'])->name('preview');
});
```

### 6.2 Controller

新增：

```text
app/Http/Controllers/Admin/ThemeReplicationController.php
```

职责：

- 展示复刻入口页。
- 创建复刻任务。
- 查询任务状态。
- 渲染预览 iframe。
- 接收微调说明。
- 确认保存或启用主题。

### 6.3 Service

建议新增服务：

```text
app/Services/SiteTheme/ThemeReplicationService.php
app/Services/SiteTheme/ThemeReferenceCrawler.php
app/Services/SiteTheme/ThemeReferenceAnalyzer.php
app/Services/SiteTheme/ThemeDraftGenerator.php
app/Services/SiteTheme/ThemeRefinementService.php
app/Services/SiteTheme/ThemeSafetyValidator.php
app/Support/Site/GeneratedSiteThemeCatalog.php
```

职责拆分：

- `ThemeReferenceCrawler`：安全抓取 URL、限制内容长度、处理跳转、提取 HTML 和 CSS 线索。
- `ThemeReferenceAnalyzer`：把页面转成结构摘要、组件摘要、颜色字体间距摘要。
- `ThemeDraftGenerator`：基于 GEOFlow 主题骨架生成草稿文件。
- `ThemeRefinementService`：根据自然语言修改 tokens、CSS 和局部组件。
- `ThemeSafetyValidator`：拦截危险 Blade、PHP、JS 和非法路径。
- `GeneratedSiteThemeCatalog`：让生成主题进入后台主题列表和前台解析链路。

### 6.4 Job

首版建议用一个主 Job，内部记录步骤：

```text
app/Jobs/GenerateSiteThemeFromReferencesJob.php
```

后续可拆分：

- `FetchThemeReferencePagesJob`
- `AnalyzeThemeReferencePagesJob`
- `GenerateThemeDraftJob`
- `RenderThemePreviewJob`

## 7. 数据库设计

### 7.1 theme_replication_jobs

```text
id
admin_id
theme_name
theme_slug
home_url
list_url
article_url
style_prompt
status
progress
current_step
error_message
analysis_payload json
generated_theme_id
draft_path
active_revision_id
approved_at
activated_at
created_at
updated_at
```

状态建议：

```text
pending
validating
fetching
analyzing
generating
validating_theme
previewing
ready
failed
approved
activated
cancelled
```

### 7.2 theme_replication_revisions

```text
id
job_id
revision_no
prompt
theme_id
theme_path
status
validation_errors json
preview_payload json
created_at
updated_at
```

用途：

- 每次生成和微调都形成一个 revision。
- 可回看、对比、回滚。
- 最终启用时使用指定 revision。

### 7.3 theme_replication_events

```text
id
job_id
revision_id nullable
level
step
message
context json
created_at
```

用途：

- 进度日志。
- 失败原因。
- 后续排查 Agent 生成质量。

## 8. Agent 生成逻辑

### 8.1 输入材料

Agent 不直接读取完整网页原文，而是读取经过清洗的结构化输入：

```text
{
  "home": {
    "url": "...",
    "dom_outline": "...",
    "layout_summary": "...",
    "style_tokens": "...",
    "component_patterns": "..."
  },
  "list": {},
  "article": {},
  "geoflow_theme_contract": {},
  "sample_data_contract": {}
}
```

### 8.2 输出格式

Agent 输出必须是结构化 JSON，不能直接输出任意文件：

```json
{
  "theme": {
    "name": "示例主题",
    "description": "由 3 个参考页面生成的 GEOFlow 前台主题"
  },
  "tokens": {
    "colors": {},
    "typography": {},
    "spacing": {},
    "radius": {}
  },
  "layout": {
    "home": {},
    "category": {},
    "article": {}
  },
  "components": {
    "header": {},
    "article_card": {},
    "article_body": {},
    "footer": {}
  }
}
```

再由 `ThemeDraftGenerator` 把 JSON 转换为受控 Blade 和 CSS。

### 8.3 生成方式

推荐采用「固定骨架 + AI 样式映射」：

- 固定骨架来自现有成熟主题，例如 `default`、`netease-news-20260507`。
- AI 负责生成布局参数、设计 tokens、组件变体和 CSS 规则。
- Blade 文件由服务端模板组装，避免自由生成危险逻辑。

这样比「让 AI 直接写整套 Blade」更稳定，也更容易测试和回滚。

## 9. 主题安全规则

### 9.1 URL 抓取安全

必须限制：

- 禁止 `localhost`、`127.0.0.1`、`0.0.0.0`、`::1`。
- 禁止私有网段和 Docker 内部域名。
- 禁止 `file://`、`ftp://`、`gopher://` 等协议。
- 限制最大跳转次数。
- 限制响应大小。
- 限制超时时间。
- DNS 解析后再次校验 IP。

### 9.2 生成文件安全

禁止：

- `<?php`
- `@php`
- `shell_exec`
- `exec`
- `system`
- `passthru`
- `proc_open`
- `file_get_contents`
- `curl_*`
- 任意外部 JS 注入
- 写入主题目录以外路径

允许：

- `@extends`
- `@section`
- `@include`
- `@foreach`
- `@if`
- `{{ }}`
- 受控的 `{!! $safeHtml !!}` 场景，必须由系统预先净化。

### 9.3 版权和合规

提示文案需要明确：

- 复刻功能用于学习布局和视觉风格，不应复制第三方品牌资产。
- 生成主题默认使用 GEOFlow 本地内容和本地资源。
- 如果引用外部字体或资源，需要管理员自行确认授权。

## 10. UI 设计建议

### 10.1 网站模板模块

在现有模块中增加一个紧凑操作区：

```text
当前生效模板
TDWH NetEase English
模板切换不会影响文章、分类、SEO 与内容数据结构，只替换前台展示层。

[复刻模板] [导出当前主题] [查看主题目录说明]
```

### 10.2 复刻页面布局

页面建议分为左右两栏：

- 左侧：URL 输入、风格说明、生成按钮。
- 右侧：流程说明、风险提示、最近复刻任务。

提交后切换为任务视图：

- 顶部：任务标题、状态、进度。
- 中部：步骤时间线。
- 底部：日志和错误提示。

### 10.3 预览页面布局

预览页建议：

- 顶部操作栏：返回、当前 revision、保存为模板、保存并启用。
- 中部 iframe：按首页、列表页、文章页切换。
- 右侧或底部：微调输入框和调整历史。
- 移动端预览用固定宽度容器，避免后台页面横向溢出。

### 10.4 错误提示

常见错误需要直接可理解：

- URL 无法访问：请确认页面可公网访问，且不是内网地址。
- 页面内容过大：请更换更典型的页面，或稍后支持截图分析模式。
- 生成主题未通过安全检查：系统已拦截不安全代码，请重试或调整描述。
- 预览失败：主题已生成但渲染异常，可查看错误日志或回退上一版本。

## 11. 开发阶段

### Phase 1：入口、任务和进度闭环

交付内容：

- 网站模板模块新增「复刻模板」入口。
- 新增复刻任务页面。
- 新增任务表和事件表。
- 完成 URL 校验、权限校验、任务创建、状态轮询。
- 先不接入真实 Agent 生成，使用模拟步骤跑通完整进度 UI。

可独立上线价值：

- 后台交互和数据闭环先稳定。
- 后续接入 Agent 不需要重做 UI 和任务模型。

### Phase 2：页面抓取与结构分析

交付内容：

- 安全抓取 3 个 URL。
- 清洗 HTML 和 CSS 线索。
- 提取颜色、字体、布局、组件摘要。
- 记录分析结果到 `analysis_payload`。
- 失败时提供清晰错误和重试。

可独立上线价值：

- 管理员可以看到系统如何理解对标页面。
- 后续生成质量可追踪。

### Phase 3：生成草稿主题和预览

交付内容：

- 生成 `storage/app/generated-themes/{theme_id}` 草稿主题。
- 扩展主题目录扫描和视图解析。
- 渲染首页、列表页、文章页预览。
- 完成主题安全检查。
- 支持保存为模板但不启用。

可独立上线价值：

- 已经可以产出可预览主题。
- 不影响当前正式前台。

### Phase 4：自然语言微调和版本管理

交付内容：

- 增加微调输入框。
- 每次微调生成一个 revision。
- 支持版本切换、对比和回退。
- 支持保存并启用。

可独立上线价值：

- 形成可用的 Agent 设计闭环。
- 管理员可以把模板调整到可发布状态。

### Phase 5：增强能力和文档

交付内容：

- 主题导出和导入。
- 主题复制。
- 生成质量评分。
- 官方使用教程和安全说明。
- 多语言文案。

可独立上线价值：

- 方便把生成主题迁移到其他 GEOFlow 实例。
- 降低使用门槛。

## 12. 测试计划

### 12.1 Feature 测试

- 超级管理员可以看到「复刻模板」按钮。
- 普通管理员看不到或无法访问复刻接口。
- 创建任务时必须填写 3 个合法 URL。
- localhost、内网 IP、非法协议会被拒绝。
- 任务状态接口返回进度、步骤和错误信息。
- 生成主题通过 `SiteThemeCatalog` 可见。
- 预览路由能用本机内容渲染首页、列表页和文章页。
- 确认启用后 `active_theme` 更新。
- 启用失败时原主题不变。

### 12.2 Unit 测试

- URL 安全校验。
- HTML 清洗和摘要提取。
- 主题 slug 生成和冲突处理。
- 主题安全校验拒绝危险代码。
- 生成主题目录不能越权写入。
- revision 回退逻辑。

### 12.3 手动验收

- 在网站设置页能进入复刻流程。
- 输入 3 个页面后能看到动态进度。
- 失败时有明确原因。
- 成功后能预览 3 个页面。
- 桌面和移动预览不溢出。
- 微调后预览发生可见变化。
- 保存为模板后出现在网站模板列表。
- 保存并启用后前台页面切换为新主题。
- 切回原主题无数据损失。

## 13. 依赖与配置

需要依赖：

- 已配置可用的聊天模型，用于结构分析和样式映射。
- Laravel 队列，用于异步执行复刻任务。
- 可写的 `storage/app/generated-themes` 目录。

可选依赖：

- 浏览器截图能力，用于后续像素级分析。
- 视觉模型，用于分析截图中的布局、间距和视觉层级。

首版建议不强依赖截图能力，先用 HTML、CSS、DOM 摘要和现有主题骨架完成可运行闭环。

## 14. 风险与处理

### 14.1 生成质量不稳定

处理方式：

- 固定 GEOFlow 主题骨架。
- Agent 只生成 tokens 和组件配置。
- 通过预览和微调修正。

### 14.2 生产环境不可写代码目录

处理方式：

- 生成主题保存到 `storage/app/generated-themes`。
- 主题扫描和视图解析同时支持 `resources/views/theme` 与 `storage/app/generated-themes`。

### 14.3 复制第三方站点风险

处理方式：

- 明确只参考布局和视觉风格。
- 不保存对标站正文和图片作为生产主题资产。
- 生成主题默认使用 GEOFlow 本地内容。

### 14.4 安全风险

处理方式：

- URL 抓取做 SSRF 防护。
- 生成文件做危险语法扫描。
- 草稿主题不自动启用。
- 仅超级管理员可操作。

## 15. 推荐结论

建议采用「任务化复刻流程 + 受控主题生成 + iframe 预览 + 自然语言微调」方案。

第一版不要直接做成完全自由的代码生成 Agent，而应基于现有主题系统做一个安全可控的主题工厂。这样可以快速获得可用能力，同时不破坏 GEOFlow 当前稳定的前台数据契约和后台设置逻辑。

推荐先做 Phase 1 到 Phase 3，形成从输入 URL 到生成可预览主题的闭环；再做 Phase 4 的自然语言微调和版本管理。

## 16. 确认后开发清单

如果确认本方案，建议按以下顺序开发：

1. 新增复刻入口和任务页面。
2. 新增任务、revision、event 数据表。
3. 新增 URL 安全校验和任务状态轮询。
4. 接入页面抓取和结构摘要。
5. 生成 storage 主题目录。
6. 扩展主题扫描和预览解析。
7. 增加主题安全检查。
8. 增加保存、启用、回退。
9. 增加自然语言微调。
10. 补齐测试和文档。
