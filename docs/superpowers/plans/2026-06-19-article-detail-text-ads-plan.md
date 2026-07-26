# 文章详情页内容文本广告与渠道同步实施方案

## 背景

当前网站设置后台已有“文章详情页广告管理”，但现有能力主要是文章详情页底部跟随广告，数据存放在 `site_settings.article_detail_ads`，前台默认只展示第一条启用广告。新的需求是增加文章正文内容模块顶部和底部的文本广告，并允许多个 GEOFlow Agent 渠道站点按渠道选择、同步和渲染这些广告。

本方案不改动现有底部跟随广告的数据结构，避免破坏已存在的前台展示和测试。

## 目标

- 在后台“文章详情页广告管理”中新增正文顶部和正文底部文本广告配置。
- 支持文本广告新增、编辑、删除、启用、排序和预览。
- 支持广告文字、广告链接、来源追踪参数、新标签页打开、文本颜色等字段。
- 支持分发渠道编辑页按渠道选择是否同步指定文本广告。
- 支持 GEOFlow Agent 目标站点接收、保存和渲染文本广告。
- 文本广告样式要适配不同前台模板，不能破坏正文排版和 SEO 结构。

## 非目标

- 第一版不做曝光统计、点击统计、A/B 测试。
- 第一版不做按分类、关键词、文章、渠道模板的复杂定向。
- 第一版不接入第三方广告网络 JS。
- 第一版不要求 WordPress 渠道自动渲染这些站点文本广告；WordPress 仍按文章内容和 WordPress 主题自身逻辑展示。
- 第一版不做独立广告权限系统，沿用网站设置和分发渠道的现有权限边界。

## 当前代码基础

相关现有文件：

- `app/Http/Controllers/Admin/SiteSettingsController.php`
  - 已有 `updateArticleDetailAds()`、`parseArticleDetailAds()`、`loadSettings()`。
- `resources/views/admin/site-settings/index.blade.php`
  - 已有“文章详情页广告管理”表单与动态新增广告位脚本。
- `app/Support/Site/ArticleStickyAdPicker.php`
  - 负责从 `article_detail_ads` 中取第一条启用的底部跟随广告。
- `app/Http/Controllers/Site/ArticleController.php`
  - 向前台文章详情页传入 `$stickyAd`。
- `resources/views/site/article.blade.php`
  - 当前正文容器为 `.article-prose.article-rail`。
- `app/Models/DistributionChannel.php`
  - `site_settings` 与 `channel_config` 都是 JSON 字段。
  - `targetSiteSettingsPayload()` 已是目标站点设置同步入口。
- `app/Http/Controllers/Admin/DistributionController.php`
  - `normalizeChannelSiteSettings()` 保存渠道站点设置。
  - `syncSettings()` 同步目标站点设置并刷新渠道内容。
- `app/Services/GeoFlow/DistributionTargetSitePackageBuilder.php`
  - 目标站点包内置 `normalizeSiteSettings()`、`handleSiteSettingsUpdate()`、`renderArticlePage()` 和静态重建逻辑。

## 推荐数据设计

新增独立站点设置 key：

```text
article_detail_text_ads
```

不复用 `article_detail_ads`，因为现有字段是底部跟随广告结构，新增文本广告的展示位置和字段语义不同。

建议 JSON 结构：

```json
[
  {
    "id": "article_text_ad_...",
    "name": "内容顶部推荐",
    "placement": "content_top",
    "text": "了解 GEOFlow 多站点分发方案",
    "url": "/contact",
    "text_color": "#2563eb",
    "open_new_tab": true,
    "tracking_enabled": true,
    "tracking_param": "utm_source=geoflow&utm_medium=article_text_ad",
    "enabled": true,
    "sort_order": 10
  }
]
```

字段约束：

- `placement` 只允许 `content_top`、`content_bottom`。
- `text` 必填，建议限制 120 字以内。
- `url` 必填，允许站内相对路径、`http://`、`https://`。
- `text_color` 只允许合法 HEX 色值，空值时使用模板主色。
- `tracking_param` 只保存 query 参数，不保存完整 URL。
- `open_new_tab` 为 true 时默认输出 `target="_blank"` 和 `rel="noopener sponsored nofollow"`。

关于“Referer 信息”的处理：

- 浏览器真实 HTTP `Referer` 头不能由普通前端链接任意自定义。
- 第一版实现为“来源追踪参数”，即在广告链接后追加 `utm_*` 或 `ref=geoflow`。
- 是否使用 `noreferrer` 需要谨慎：如果用户希望目标站可看到浏览器 Referer，就不要加 `noreferrer`；如果优先隐私，可以加。第一版建议默认 `noopener sponsored nofollow`，不默认 `noreferrer`。

## 渠道配置设计

在 `DistributionChannel.channel_config` 中增加渠道级策略：

```json
{
  "article_text_ad_policy": {
    "content_top": {
      "mode": "inherit",
      "ad_ids": []
    },
    "content_bottom": {
      "mode": "selected",
      "ad_ids": ["article_text_ad_xxx"]
    }
  }
}
```

`mode` 可选：

- `disabled`：该位置不展示。
- `inherit`：继承全局已启用广告。
- `selected`：只同步当前渠道勾选的广告。

推荐默认值：

- 新渠道默认 `inherit`。
- 如果全局没有启用广告，目标站点不会渲染任何文本广告。

## 数据流

```text
网站设置：article_detail_text_ads
        |
        v
渠道编辑：article_text_ad_policy
        |
        v
DistributionChannel::targetSiteSettingsPayload()
        |
        v
GEOFlow Agent /geoflow-agent/v1/site-settings
        |
        v
目标站点 storage/site-settings.json
        |
        v
rebuildStaticSite()
        |
        v
远端文章详情页正文顶部 / 正文底部文本广告
```

## 后台 UI 方案

### 网站设置页

在“文章详情页广告管理”中分成两个子模块：

1. 底部跟随广告
   - 保持当前功能和字段。
   - 文案调整为“底部跟随广告”。

2. 正文文本广告
   - 顶部文本广告。
   - 底部文本广告。
   - 支持列表、添加、编辑、删除、启用、排序。
   - 每条广告展示一个轻量预览，模拟正文内的细线文本广告。

推荐交互：

- 每条广告用折叠卡片，减少页面长度。
- 新增按钮使用“新增文本广告”。
- 位置使用分段控件或 radio：正文顶部 / 正文底部。
- 颜色字段使用文本输入加原生 color input。
- 链接追踪参数使用开关，打开后显示参数输入框。

### 分发渠道编辑页

在远程站点设置区域增加“文章文本广告同步”模块：

- 正文顶部：
  - 不展示
  - 继承全局
  - 指定广告

- 正文底部：
  - 不展示
  - 继承全局
  - 指定广告

指定广告时展示可勾选广告列表。默认只显示启用广告，同时保留一个“显示全部广告”的切换，方便选择暂未启用但想单独给某渠道测试的广告。

### 渠道详情页

在渠道详情页“基本信息”或“同步设置”中补充当前文本广告策略：

- 顶部：继承全局 / 已选 N 条 / 不展示
- 底部：继承全局 / 已选 N 条 / 不展示

如果目标站点包版本不支持文本广告渲染，显示提示：

```text
当前目标站点包可能不支持正文文本广告，请重新下载并覆盖目标站点包后再同步设置。
```

## 前台本地渲染方案

新增支持类：

```text
app/Support/Site/ArticleTextAdPicker.php
```

职责：

- 读取 `site_settings.article_detail_text_ads`。
- 过滤启用广告。
- 按 `placement` 和 `sort_order` 返回广告。
- 生成最终 URL，包括可选追踪参数。
- 输出安全字段，避免 Blade 层重复处理脏数据。

新增 Blade partial：

```text
resources/views/site/partials/article-text-ads.blade.php
```

在 `resources/views/site/article.blade.php` 中：

- 正文容器前渲染 `content_top`。
- 正文容器后渲染 `content_bottom`。

推荐结构：

```html
<div class="article-text-ads article-text-ads--content-top">
  <a class="article-text-ad__link" href="..." style="--article-text-ad-color:#2563eb">
    广告文字
  </a>
</div>
```

只允许把用户选择的文本颜色写入 CSS 变量，不写内联布局样式。

## 目标站点包渲染方案

修改 `DistributionTargetSitePackageBuilder.php` 生成的目标站点包：

1. `config.php`
   - 增加默认 `article_text_ads => []`。

2. `normalizeSiteSettings()`
   - 接收并归一化 `article_text_ads`。

3. `handleSiteSettingsUpdate()`
   - 保存 `article_text_ads` 到 `storage/site-settings.json`。
   - 调用 `rebuildStaticSite()` 后，静态首页、列表页、详情页重新生成。

4. `renderArticlePage()`
   - 正文 HTML 前插入 `renderArticleTextAds($config, 'content_top')`。
   - 正文 HTML 后插入 `renderArticleTextAds($config, 'content_bottom')`。

5. `assets/css/site.css`
   - 增加通用文本广告样式。
   - 默认透明或纯白背景，避免压迫不同模板。
   - 使用 `currentColor`、CSS 变量和模板继承，减少模板割裂。

## 模板自适应原则

为了适配当前 20 套模板以及未来复刻模板，文本广告不能做成强视觉卡片。统一遵循：

- 广告容器不使用大面积底色。
- 背景默认透明或白色。
- 字体继承正文或模板字体。
- 间距控制在正文段落节奏内。
- 链接颜色支持后台配置，不配置则继承主题主色。
- 通过类名暴露样式钩子，允许模板覆盖：
  - `.article-text-ads`
  - `.article-text-ads--content-top`
  - `.article-text-ads--content-bottom`
  - `.article-text-ad__link`

## 兼容策略

### 本地前台

新配置为空时，不渲染任何内容，不影响文章详情页。

### GEOFlow Agent 渠道

新目标站点包支持文本广告。旧目标站点包不会识别 `article_text_ads`，因此需要重新下载并覆盖目标站点包。

### 静态模式与伪静态模式

两种模式都通过 `renderArticlePage()` 输出详情页，因此同一套渲染逻辑即可覆盖。

### Generic HTTP API

可以把 `article_text_ads` 透传到 `settings` payload 中，但是否展示由对方系统决定。后台文案需要说明“通用 API 只负责发送字段，不保证远端渲染”。

### WordPress REST

第一版不建议把这些广告强行写入 WordPress 文章正文，因为这会污染正文内容，并与 WordPress 主题/插件广告体系冲突。WordPress 渠道可暂不启用该配置，后续如果需要，可以单独设计 WordPress 短代码或区块注入方案。

## 安全与校验

- 广告文字必须 HTML escape。
- 广告 URL 只允许相对路径、`http://`、`https://`。
- 禁止 `javascript:`、`data:`、协议相对 URL。
- 颜色只允许 `#RGB` 或 `#RRGGBB`。
- 新标签页打开时使用 `rel="noopener sponsored nofollow"`。
- 追踪参数只作为 query 参数追加，不能拼接未经处理的完整 HTML。
- 每个位置第一版建议最多渲染 3 条启用广告，避免正文体验被广告占据。

## 实施阶段

### Phase 1：全局文本广告配置

- 在 `SiteSettingsController` 中新增 `article_detail_text_ads` 默认值、解析和保存逻辑。
- 在网站设置页“文章详情页广告管理”中新增正文文本广告 UI。
- 增加中英文文案。
- 保持原 `article_detail_ads` 不变。

可独立上线：配置保存后本地和渠道暂不展示，但后台数据已可管理。

### Phase 2：本地文章详情页渲染

- 新增 `ArticleTextAdPicker`。
- `ArticleController` 传入顶部/底部文本广告。
- `resources/views/site/article.blade.php` 引入 partial。
- 前台 CSS 增加通用样式。

可独立上线：本地前台文章详情页可展示文本广告。

### Phase 3：渠道选择与同步 payload

- 分发渠道编辑页增加文本广告同步策略。
- `DistributionController` 保存 `article_text_ad_policy` 到 `channel_config`。
- `DistributionChannel::targetSiteSettingsPayload()` 输出渠道生效后的 `article_text_ads`。
- 渠道详情页展示当前策略。

可独立上线：同步 payload 完整，但旧目标站点包不会渲染，需要后续 Phase 4。

### Phase 4：目标站点包支持

- 目标站点包 `config.php`、`normalizeSiteSettings()`、`siteSettings()` 支持 `article_text_ads`。
- `renderArticlePage()` 渲染正文顶部/底部广告。
- `targetSiteCss()` 增加样式。
- 更新目标站点包说明和渠道详情页提示。

可独立上线：重新下载覆盖目标站点包后，渠道同步设置即可生效。

### Phase 5：测试与回归

补充测试：

- 全局文本广告保存、编辑、删除。
- 非法 URL、非法颜色会被拦截。
- 本地文章详情页顶部/底部广告渲染正确。
- 广告文字被转义，URL 追踪参数追加正确。
- 新标签页属性正确。
- 渠道策略保存和回显正确。
- `targetSiteSettingsPayload()` 只输出渠道生效广告。
- 目标站点包生成的 `public/index.php` 包含文本广告归一化和渲染逻辑。
- 旧底部跟随广告功能不回归。

建议验证命令：

```bash
php artisan test tests/Feature/SiteArticleMarkdownRenderTest.php
php artisan test tests/Feature/AdminDistributionPageTest.php
php artisan test tests/Feature/AdminSiteSettingsPageTest.php
php artisan test
```

如果项目中没有 `AdminSiteSettingsPageTest.php`，则新增对应 feature test，或者把站点设置相关断言放入现有后台设置测试文件。

## 回滚方案

- 新增配置是独立 key：删除或置空 `site_settings.article_detail_text_ads` 即可停止展示。
- 渠道配置放在 `channel_config.article_text_ad_policy`，清空该字段即可停止同步。
- 目标站点包如果出现兼容问题，远端删除 `storage/site-settings.json` 中的 `article_text_ads` 或同步空数组即可恢复无广告状态。
- 现有 `article_detail_ads` 不变，因此底部跟随广告可以独立回滚。

## 主要风险与处理

1. 旧目标站点包无法展示新广告。
   - 处理：后台提示重新下载覆盖目标站点包；同步设置后触发内容刷新。

2. 不同模板样式冲突。
   - 处理：广告结构保持极简，样式使用透明背景、继承字体和 CSS 变量。

3. 用户误解“Referer 信息”。
   - 处理：后台文案改为“来源追踪参数”，说明不会伪造浏览器 Referer。

4. WordPress 渠道展示逻辑不同。
   - 处理：第一版不对 WordPress 自动注入广告，避免污染正文和破坏 WordPress 主题体系。

## 推荐结论

建议按“独立文本广告配置 + 渠道策略选择 + GEOFlow Agent 目标站点渲染”的方向实施。这个方案对现有底部跟随广告零侵入，对本地前台和目标站点都可逐步上线，并且后续可以自然扩展点击统计、定向展示和渠道级样式覆盖。
