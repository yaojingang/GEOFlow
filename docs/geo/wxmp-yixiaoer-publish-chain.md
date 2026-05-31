# GEO 引用来源到微信公众号草稿链路

本文档记录 GEOAmplify 当前从引用来源采集到微信公众号草稿提交的完整链路。该链路面向“文章”，默认只提交到微信公众号草稿箱，不群发，不提交小红书、抖音、视频号、B 站等平台。

## 1. 链路范围

```text
真实 AI 搜索/网页工作台
-> 引用来源抽取
-> 高分来源采集到本地
-> 文章结构分析
-> 结构仿写草稿
-> 可发布正文
-> 正文排版
-> 配图方案
-> 生成/植入本地配图
-> 正文预览
-> 导出发布包
-> 蚁小二上传图片
-> 微信公众号草稿提交
-> 查询蚁小二任务详情并回写状态
```

## 2. 页面入口

- GEO 工作台：`/geo_admin/geo`
- 引用来源列表：`/geo_admin/geo/citation-sources`
- 引用来源详情：`/geo_admin/geo/citation-sources/{sourceId}`
- 独立草稿编辑页：`/geo_admin/geo/article-drafts/{draftId}/edit`

当前草稿示例：

- 草稿 ID：`5`
- 编辑页：`http://127.0.0.1:8017/geo_admin/geo/article-drafts/5/edit`
- 发布包目录：`storage/app/private/geo_publish_packages/draft-5`
- 公众号任务集：`fa904eef-502c-4f10-addb-607544335a45`
- 当前阻塞：微信公众号后台开启了“群发消息保护”，蚁小二返回失败。

## 3. 发布包结构

导出发布包后，本地文件位于 Laravel `local` disk：

```text
storage/app/private/geo_publish_packages/draft-{draftId}/
  article.md
  manifest.json
  images/
    01-cover-image.png
    02-process-diagram.png
    03-checklist-infographic.png
```

`article.md` 固定包含：

```text
标题：
摘要：
封面建议：
---
正文 Markdown
```

`manifest.json` 保存图片映射、缺失图片、目标渠道和生成时间。蚁小二提交时以 `manifest.json` 中的图片为准，逐张上传到 `cloud-publish`，再把返回的 key 写入公众号文章 HTML 和封面字段。

## 4. 后端实现

关键文件：

- `app/Services/Geo/GeoReferenceContentAnalyzer.php`：高分来源本地分析。
- `app/Services/Geo/GeoReferenceImitationDraftGenerator.php`：结构仿写草稿。
- `app/Services/Geo/GeoPublishableDraftPolisher.php`：可发布正文。
- `app/Services/Geo/GeoPublishableArticleLayoutSkill.php`：正文排版。
- `app/Services/Geo/GeoArticleVisualPublishPackBuilder.php`：配图方案。
- `app/Services/Geo/GeoArticleVisualImageInserter.php`：植入正文图片。
- `app/Services/Geo/GeoArticlePublishPackageExporter.php`：导出发布包。
- `app/Services/Geo/GeoYixiaoerDistributionService.php`：微信公众号草稿提交。
- `resources/views/admin/geo/article-draft-edit.blade.php`：草稿页按钮、预览和状态展示。
- `app/Http/Controllers/Admin/GeoArticleDraftDistributionController.php`：独立草稿提交到蚁小二的入口。
- `routes/web.php`：独立草稿编辑、发布包、公众号草稿提交路由。

核心路由：

```text
POST admin.geo.article-drafts.visual-pack
POST admin.geo.article-drafts.visual-pack.insert-images
POST admin.geo.article-drafts.publish-package
POST admin.geo.article-drafts.yixiaoer-distribute
```

## 5. 公众号提交规则

当前 `GeoYixiaoerDistributionService` 强制：

- 只接受 `weixingongzhonghao`。
- `publishType=article`。
- `platforms=["微信公众号"]`。
- `publishArgs.platformForms["微信公众号"].pubType=0`，只进平台草稿。
- `notifySubscribers=0`，不群发。
- 提交前必须实时查询蚁小二账号。
- 账号 `status !== 1` 时立即阻断，并提示用户先登录/恢复授权。

不要把文章提交到：

- 小红书
- 抖音
- 视频号
- B 站
- 其它视频平台

## 6. 蚁小二前置检查

查公众号账号：

```bash
node /Users/renjianghua/.codex/skills/yixiaoer/scripts/api.ts \
  --payload='{"action":"accounts","platform":"微信公众号","page":1,"size":20}'
```

判断：

- `status=1`：可继续。
- `status=2/3/4`：停止，让用户先在蚁小二登录或恢复授权。
- `proxyInfo=null` 且云发布返回“未设置代理”：先设置默认代理。

当前已验证的默认代理：

```bash
node /Users/renjianghua/.codex/skills/yixiaoer/scripts/api.ts \
  --payload='{"action":"update-account","account_id":"6a0528042907e572d7a6240a","kuaidailiArea":"510100"}'
```

## 7. 提交与查询

通过页面点击“提交公众号草稿”，或在本地执行：

```bash
php artisan tinker --execute='$draft = App\Models\GeoArticleDraft::findOrFail(5); $record = app(App\Services\Geo\GeoYixiaoerDistributionService::class)->submitOfficialAccountArticleDraft($draft, ["weixingongzhonghao"]); dump($record->only(["status","target_url","platform_codes","error_message"]));'
```

查蚁小二任务详情：

```bash
node /Users/renjianghua/.codex/skills/yixiaoer/scripts/api.ts \
  --payload='{"action":"details","task_set_id":"fa904eef-502c-4f10-addb-607544335a45"}'
```

状态会回写到 `geo_publish_records`：

- `submitted`：蚁小二已接收并创建公众号草稿任务；本链路 `pubType=0`、`notifySubscribers=0`，不代表已群发发布。
- `published`：仅当后续扩展为真实发布并且任务详情明确返回 published 时使用。
- `partial_success`：部分平台成功；当前公众号专用链路一般不会出现。
- `failed`：平台失败，错误写入 `error_message`。

时间字段约定：

- `submitted_at`：蚁小二已接收草稿任务的时间。
- `published_at`：真实发布成功时间；公众号草稿提交成功时保持为空。

## 8. 常见阻塞

### 账号未登录

表现：账号 `status` 不是 `1`。

处理：停止提交，提示用户先在蚁小二重新登录公众号账号。

### 未设置代理

表现：蚁小二返回“以下账号未设置代理”。

处理：查询 `proxy-areas` 后设置 `kuaidailiArea`。当前可用默认值是四川成都 `510100`。

### 微信群发保护

表现：

```text
发布失败，账号开启了群发保护；需前往微信公众平台-设置与开发-安全中心-风险操作保护-关闭<群发消息>保护后再试。
```

处理：让用户进入微信公众平台关闭该保护后，再重新提交。即使本链路只建草稿，蚁小二仍会被该保护拦截。

## 9. 验证命令

```bash
vendor/bin/pint app/Services/Geo/GeoYixiaoerDistributionService.php app/Http/Controllers/Admin/GeoArticleDraftDistributionController.php resources/views/admin/geo/article-draft-edit.blade.php tests/Feature/AdminGeoOpportunityWorkflowTest.php config/services.php
```

```bash
php artisan test --filter=AdminGeoOpportunityWorkflowTest
```

```bash
php artisan test --filter=AdminGeoOpportunityWorkflowTest::test_yixiaoer_official_account_distribution_requires_logged_in_account
```

页面冒烟检查：

- 草稿页显示“微信公众号草稿”。
- 不显示“小红书/抖音/视频号/B站”作为本文章发布目标。
- 错误原因能显示在“异常”区域。
- 任务集 ID 能显示在“任务集”区域。
