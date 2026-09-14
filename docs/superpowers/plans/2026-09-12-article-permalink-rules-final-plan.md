# GEOFlow 文章固定链接规则最终方案

日期：2026-09-12

状态：方案审查完成，待产品确认后实施

适用范围：GEOFlow Laravel 主站、第一方托管站、GEOFlow Agent 目标站包的文章详情页

## 1. 最终结论

建议增加站点级「文章固定链接规则」。每个站点在任一时刻只有一条生效的规范规则，可从预设规则中选择，也可填写受约束的自定义模板。文章详情页的生成、识别、规范链接、站点地图、主题链接、托管站远端地址和目标站静态输出全部使用同一个双向规则引擎。

首个版本保留 `/article/{slug}` 作为永久兼容入口。规则切换、文章 slug 变更、分类变更或日期字段不一致时，旧地址以 HTTP 301 一次跳转到当前规范地址。文章只在规范地址返回 200，页面的 canonical、内部链接和 sitemap 始终输出当前地址。

后台采用「检查并预览 → 明确确认 → 原子启用」流程。规则启用前展示示例 URL、影响文章数、路由冲突、历史规则和 URL 迁移清单。启用操作仅开放给超级管理员，并进入现有管理员操作日志。

推荐分三期交付：

1. 主站端到端能力，包含规则引擎、后台入口、旧链接跳转、稳定 slug、SEO 输出和内置主题改造。
2. 第一方托管站能力，包含逐站策略、托管域名路由和远端地址刷新。
3. GEOFlow Agent 目标站包能力，包含能力协商、设置同步、动态路由和静态构建切换。

默认规则保持 `/article/{slug}`。升级后未主动修改设置的站点不会改变现有 URL。

## 2. 本轮审查补齐的关键问题

| 问题 | 当前代码表现 | 最终方案中的处理 |
| --- | --- | --- |
| 托管站请求会在路由分发前受限 | `EnforceCurrentSiteSurface` 只允许固定的 `/article/{slug}` | 中间件复用规则编译器判断文章路径，并把初步匹配结果交给控制器，避免新规则提前收到 404 |
| 主题绕过统一 URL 生成器 | `resources/views` 中约有 330 处 `route('site.article', ...)`，分布在约 132 个 Blade 文件 | 内置主题统一使用 `SiteUrlGenerator`；保留旧命名路由兼容已安装主题，并增加主题兼容检查 |
| 标题编辑会生成新 slug | 后台和 API 在标题变化时可能重建 slug | 新文章生成一次 slug 后保持稳定；只有显式提交 slug 才允许变更，并记录旧 slug |
| 旧 slug 会失效或被其他文章复用 | 当前只检查 `articles.slug` | 新增 slug 历史注册表；当前 slug 和历史 slug 共同参与唯一性检查 |
| 分类和日期令牌会随元数据变化 | 分类 slug、发布日期均可能改变 | `{category}` 作为描述字段参与规范地址；旧分类路径仍可通过文章定位符解析后 301。日期令牌使用不可变的 `created_at` |
| 自定义根路径可能覆盖系统入口 | 后台入口可配置，公开路由也会继续增加 | 建立统一保留路径注册表；固定链接设置和后台入口设置双向校验 |
| 请求方法、尾斜杠和查询参数缺少规则 | 当前控制器只接收固定 slug 路由 | 仅处理 GET/HEAD；规范地址不带尾斜杠；301 保留查询参数；非法编码和模糊路径返回 404 |
| 跳转也可能增加阅读量 | 当前文章控制器在渲染前直接累计阅读量 | 仅规范地址的 GET 200 累计阅读量；HEAD、预览、301 和失败请求不累计 |
| 访问日志依赖固定路由名和 slug | `RecordSiteViewLog` 只识别 `site.article` | 解析器把文章 ID、解析来源和跳转原因写入请求属性，访问日志直接读取解析结果 |
| 主站设置保存缺少并发保护 | 多个 KV 逐项写入，规则变化可能被旧页面覆盖 | 固定链接使用独立控制器、单个版本化 JSON 设置、行锁和 revision 乐观并发校验 |
| 托管站远端 URL 在多处硬编码 | Publisher、Reconciler、失败恢复均拼接 `/article/` | 所有第一方站点地址改用同一生成器；规则切换后刷新当前分发记录的规范地址 |
| Agent 静态路径和伪静态配置固定 | 包内静态文件、动态匹配、宝塔规则均假设 `/article/` | 目标包加入同构规则引擎、路径到静态缓存映射和通用安全重写；切换通过暂存构建后原子生效 |
| 任意存储的内部链接可能继续使用旧格式 | 轮播、广告、文章正文允许保存相对 URL | 保留兼容 301；预览页报告结构化设置中的旧链接数量，不自动改写正文和外部输入内容 |
| 外部分发平台拥有自己的 URL 契约 | WordPress REST 返回平台自身 permalink | WordPress 和 Generic HTTP API 保持平台 URL，不套用 GEOFlow 固定链接规则 |

## 3. 已核实的系统基础

当前主站文章路由位于 `routes/web.php`，路径为 `/article/{slug}`，路由名为 `site.article`。`SiteUrlGenerator::article()`、`Site\ArticleController`、托管站 Publisher、目标站包前端控制器和多处主题模板也直接依赖这一结构。

站点设置使用 `site_settings` 键值表。第一方托管站通过分发渠道的 `site_settings` JSON 保存独立设置，并由 `SiteSettingsBag` 根据当前域名解析。目标站 Agent 通过签名的 `site.settings.update` 事件接收设置。

Laravel 路由缓存要求路由定义在应用启动时保持静态。固定链接规则属于运行时站点设置，因此实现不能按数据库值动态注册 Laravel 路由。最终设计使用固定的末尾捕获路由和运行时匹配器，`route:cache` 继续可用。

当前目标站包静态模式直接写入 `article/{slug}/index.html`。宝塔伪静态文件只转发 `article/.*`，自定义根级 `.html` 或日期路径会绕过前端控制器。目标站阶段需要同时升级动态匹配、静态缓存寻址和三类重写配置。

当前文章 slug 在数据库中唯一，并包含软删除记录的检查。新历史表需要继续为软删除文章保留旧 slug，只有强制删除文章时随外键清理。

## 4. 外部项目设计参考

| 项目 | 可复用思路 | GEOFlow 采用方式 |
| --- | --- | --- |
| [WordPress Permalinks](https://wordpress.org/documentation/article/customize-permalinks/) | 预设结构、自定义标签、统一 permalink 生成、旧 slug 跳转 | 提供预设与受约束模板；保留旧规则和旧 slug 的 301 |
| [WordPress `get_permalink`](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/link-template.php) | URL 生成集中在单一入口 | `SiteUrlGenerator` 只委托固定链接服务，不再拼接路径 |
| [WordPress old slug redirect](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/query.php) | 旧 slug 定位文章后跳转到当前 URL | 建立 slug 历史表并保留永久兼容入口 |
| [Ghost content collections](https://ghost.org/tutorials/content-collections/) | collection 级 permalink、受控令牌、路由和 URL 同源 | 一个模板同时负责生成与匹配，避免两套规则漂移 |
| [Ghost permalink matcher](https://github.com/TryGhost/Ghost/blob/main/ghost/core/core/server/services/url/permalink-matcher.ts) | 令牌白名单、必须存在唯一定位符、编译匹配器 | `{slug}` 或 `{id}` 必填，正则全部由编译器生成 |
| [Drupal Pathauto](https://www.drupal.org/project/pathauto) | 令牌替换、路径清洗、别名与系统路由冲突处理 | 保存前统一规范化并执行保留路径、历史规则和实际文章冲突检查 |
| [Google URL 迁移指南](https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes) | 旧新 URL 映射、永久重定向、内部链接和 sitemap 同步 | 提供迁移清单；301 直达当前地址；canonical、内链和 sitemap 同步切换 |

这些项目共同强调一条架构原则：URL 模板需要同时驱动输出和输入解析，重定向历史需要与当前规范地址统一计算。

## 5. 产品定义

### 5.1 设置入口

主站入口放在「网站设置 → SEO 设置 → 文章固定链接」。托管站入口放在「分发管理 → 托管站 → 编辑 → 文章固定链接」。Agent 目标站在第三期沿用渠道编辑与「前台体验同步预览」。

界面展示：

- 当前规则、启用时间和 revision。
- 六个预设单选项和一个自定义模板输入框。
- 可用令牌及语义说明。
- 三篇真实文章的当前 URL 与预览 URL。
- 受影响的可访问文章数量。
- 当前保留的历史规则。
- 结构化站点设置中仍指向旧规则的链接数量。
- 「下载 URL 迁移清单」和「确认启用」操作。

用户先点击「检查并预览」。服务端返回规范化模板、冲突结果、示例和 15 分钟有效的签名预览凭据。只有检查通过的凭据可以提交启用。提交时重新核对 revision、当前规则、管理员身份和站点范围，避免两个后台页面相互覆盖。

### 5.2 预设规则

| 名称 | 模板 | 说明 |
| --- | --- | --- |
| 默认短链 | `/article/{slug}` | 默认值，升级零变化，推荐通用场景 |
| HTML 短链 | `/{slug}.html` | 接近传统内容站格式 |
| ID + slug | `/article/{id}-{slug}.html` | ID 提供稳定定位，slug 提供可读性 |
| 分类层级 | `/article/{category}/{slug}.html` | 展示内容分类；分类变更会触发旧地址 301 |
| 创建日期 | `/article/{year}/{month}/{slug}.html` | 日期来自文章 `created_at`，保持稳定 |
| 纯 ID | `/article/{id}.html` | 地址最稳定，迁移和对账简单 |

自定义模板支持同一组令牌。首版不开放任意正则、域名、协议、查询参数和锚点。

### 5.3 令牌语义

| 令牌 | 来源 | 格式与行为 |
| --- | --- | --- |
| `{slug}` | `articles.slug` | 单个 URL segment，输出时逐段编码；新文章生成后保持稳定 |
| `{id}` | `articles.id` | 正整数，唯一且不可变 |
| `{category}` | 当前分类 slug | 单个 URL segment；变更后旧路径根据文章定位符跳到新路径 |
| `{year}` | `created_at` | 应用时区下四位年份 |
| `{month}` | `created_at` | 两位月份 `01`–`12` |
| `{day}` | `created_at` | 两位日期 `01`–`31` |

模板必须包含 `{slug}` 或 `{id}`。描述令牌不能单独承担文章定位。`{title}` 不进入首版，避免标题编辑导致 URL 频繁变化和多语言清洗差异。

### 5.4 站点策略边界

每个可独立访问的站点拥有一份明确策略：

- 主站读取 `site_settings.article_permalink_policy`。
- 第一方托管站读取对应渠道 `site_settings.article_permalink_policy`。
- 新建托管站时快照主站当前策略。
- 已存在且没有该设置的托管站使用 `/article/{slug}`，不会跟随主站设置自动变化。
- `frontend_experience_mode` 继续管理首页与主题体验，固定链接策略单独保存，避免主站一次设置改动批量迁移多个站点 URL。
- WordPress REST 和 Generic HTTP API 渠道继续使用远端返回的 URL。

## 6. 模板语法与安全校验

保存前执行以下全部规则：

1. 模板以 `/` 开头，长度不超过 160 个 UTF-8 字节，最多 8 个路径段。
2. 模板规范化后不含尾斜杠；根路径 `/` 不可作为文章模板。
3. 固定文字首版限定为 ASCII 小写字母、数字、`-`、`_` 和 `.`；令牌值可包含合法 UTF-8，并按 segment 编码。
4. 只允许 `{slug}`、`{id}`、`{category}`、`{year}`、`{month}`、`{day}`，每个令牌最多出现一次。
5. 必须包含 `{slug}` 或 `{id}`；相邻令牌之间必须有固定分隔符。
6. 禁止协议、主机、`?`、`#`、反斜杠、空段、`.`、`..`、控制字符、NUL 和编码后的 `/` 或 `\`。
7. 根级动态 slug 必须带固定后缀，例如 `/{slug}.html`；`/{slug}` 不通过检查。
8. 编译后的路径不能与首页、关于页、归档、分类、表单、站点地图、robots、静态资源、API、健康检查、PWA 入口、当前后台前缀和框架保留入口相交。
9. 当前模板、全部历史模板和永久兼容模板共同执行文章路径冲突预检。若同一路径可解析到不同文章，启用失败并展示最多 50 条冲突样例。
10. 当前后台前缀发生变化时重新校验固定链接策略；固定链接策略发生变化时也校验当前后台前缀。

编译器只生成带起止锚点的固定结构正则，固定文字全部经过转义，令牌使用预定义片段。用户输入不会作为正则片段执行，可消除 ReDoS 和路由注入入口。

文章 slug 的新写入规则为：长度 1–255 个字符，合法 UTF-8，单一 segment，禁止 `/`、`\`、`?`、`#`、控制字符和 NUL。已有不满足规则的 slug 在预览报告中列为阻断项；系统不静默修改已有文章地址。

## 7. 规则数据结构

主站使用一个 KV 值保存完整策略，保证一次写入：

```json
{
  "schema_version": 1,
  "revision": 3,
  "current_pattern": "/article/{slug}",
  "activated_at": "2026-09-12T10:00:00+08:00",
  "history": [
    {
      "pattern": "/{slug}.html",
      "retired_at": "2026-09-12T10:00:00+08:00"
    }
  ]
}
```

规则切换时：

- 当前模板加入历史，并记录退役时间。
- 新模板从历史中移除后成为当前模板，支持安全回切。
- 相同规范化模板只保留一条历史记录。
- 历史规则不按时间自动删除，保持长期 301 能力。
- `revision` 在事务内加一。
- 缺失、空值或无法解析的设置回退到 revision 0 的 `/article/{slug}`，同时写结构化告警；前台继续服务。

第一方托管站在渠道 `site_settings` 中保存同一 JSON 对象。每次成功更新同时增加 `HostedSiteProfile.settings_version`，事务提交后失效对应域名解析缓存和站点设置缓存。

## 8. 文章 slug 历史

新增 `article_slug_histories`：

| 字段 | 约束 |
| --- | --- |
| `id` | bigint 主键 |
| `article_id` | 外键指向 `articles.id`，强制删除时级联 |
| `slug` | varchar(255)，全局唯一 |
| `created_at` | 首次退役时间 |
| `last_used_at` | 最近一次退役时间，可空 |

新增 `ArticleSlugRegistry` 作为所有生产写路径的唯一 slug 入口，负责：

- 生成随机唯一 slug 时同时检查当前文章和历史表。
- 显式变更前锁定文章、当前 slug 和目标 slug 的命名空间。PostgreSQL 按排序后的 slug 哈希获取事务级 advisory lock；SQLite 使用立即写事务串行化该段写入。
- 将旧 slug 写入历史，再保存新 slug；整个过程处于同一数据库事务。
- 文章恢复使用自己的历史 slug 时删除该条历史占用、写入被替换值，再把历史 slug 设为当前值。
- 软删除继续占用当前和历史 slug；强制删除按外键清理历史。
- 并发冲突返回可理解的 422，不依赖随机重试掩盖问题。

实施时覆盖后台文章编辑、API/CLI 文章创建与更新、Worker 自动建文和其他生产 Article 写入路径。应用层禁止生产代码绕过 Registry 直接修改 `articles.slug`，静态扫描和契约测试负责守住这一边界。数据库迁移会校验所有现有当前 slug；历史数据无法从现有库推导，因此不伪造迁移前的旧 slug。

标题变化不再自动重建 slug。API 显式传入 `slug` 时继续允许变更，并触发历史记录。

## 9. 双向固定链接引擎

新增 `ArticlePermalinkService`，承担以下职责：

- 解析主站或当前托管站策略。
- 规范化和校验模板。
- 把模板编译成缓存后的安全匹配器。
- 根据完整 Article 模型生成相对路径和绝对 URL。
- 根据请求路径提取定位符并按当前站点文章范围查找文章。
- 生成规范地址、301 目标和解析原因。
- 执行规则历史与文章路径冲突预检。

数据流如下：

```text
后台规则输入
    │
    ▼
模板规范化与保留路径检查 ──失败──> 返回具体冲突，不保存
    │
    ▼
文章路径冲突预检
    │
    ▼
签名预览凭据 ──确认──> 原子保存 policy + revision + 审计日志
                               │
               ┌───────────────┴────────────────┐
               ▼                                ▼
       URL 生成：Article → path          URL 识别：path → Article
               │                                │
               ├→ 主题/SEO/sitemap              ├→ 当前精确路径：200
               ├→ 托管站 remote_url             ├→ 历史/旧 slug：301
               └→ Agent 静态缓存                └→ 冲突/非法/无权限：404
```

解析顺序固定为：

1. Laravel 显式系统路由。
2. 当前固定链接模板。
3. 历史模板，按最近退役时间排序。
4. 永久兼容模板 `/article/{slug}`。

每个匹配结果先用 `{id}` 定位；没有 `{id}` 时使用当前或历史 `{slug}`。`{category}` 和日期令牌用于核对规范路径。一个请求若经不同模板解析到多个文章 ID，系统返回 404、记录 `ambiguous_permalink`，不选择其中任意一篇。

生成器以完整 Article 为主要输入。包含 `{category}` 时需要分类 slug，包含日期时需要 `created_at`。前台列表查询统一补齐链接所需字段并按需 eager load 分类，避免主题渲染阶段产生 N+1 查询。字符串 slug 兼容调用只能生成 slug 型规则；缺少其他元数据时返回永久兼容地址并记录开发告警。

## 10. HTTP 路由与响应规则

保留现有 `/article/{slug}` 和 `site.article` 路由名，供书签、旧主题和第三方模板继续使用。该路由改为进入固定链接解析流程，在默认规则下返回 200，在其他规则下返回 301。

在 `routes/web.php` 的所有显式公开路由、后台路由和其他系统路由之后增加一个静态 catch-all GET 路由。它只负责把剩余路径交给固定链接解析器，路由定义不读取数据库设置，因此兼容 `route:cache`。

响应约定：

| 请求 | 响应 |
| --- | --- |
| 当前规范路径 GET | 200，渲染文章并累计一次阅读量 |
| 当前规范路径 HEAD | 200，不累计阅读量 |
| 历史模板、旧 slug、旧分类、错误日期、尾斜杠或永久兼容地址 | 301，直接指向当前规范 URL |
| 已撤回、草稿、软删除或不属于当前托管站的文章 | 404 |
| 已归档托管站 | 410，沿用现有站点状态规则 |
| 非 GET/HEAD 的自定义文章路径 | 不进入 catch-all，返回现有 404/405 |
| 非法编码、超过 2048 字节、编码分隔符、模糊多文章匹配 | 404，并记录原因 |

301 保留原查询参数，丢弃服务器无法接收的 fragment，目标始终由当前规则重新计算，避免服务端重定向链。响应使用 `Cache-Control: public, max-age=3600, s-maxage=300`，使规则再次切换后的旧浏览器缓存能在可控时间内更新。

`EnforceCurrentSiteSurface` 只用已编译规则判断路径形状，不重复查文章。控制器完成文章解析后把 `resolved_article_id`、`permalink_match_source` 和 `permalink_redirect_reason` 放入请求属性，供访问日志和诊断使用。

## 11. SEO、内链与分析一致性

启用规则时同步切换以下输出：

- 文章 `<link rel="canonical">`。
- Open Graph URL、JSON-LD Article URL 和面包屑条目。
- 首页、分类、归档、相关文章、导航模块和搜索结果中的文章链接。
- XML sitemap、托管站 sitemap shard、Agent `sitemap.txt` 和 `llms.txt`。
- 后台文章列表的本地预览链接。
- 第一方托管站 Publisher、Reconciler、失败恢复和分发详情中的当前规范 URL。
- `AnalyticsLogQueryService` 的文章路径回退。

`RecordSiteViewLog` 记录实际请求路径，并用解析后的文章 ID 聚合。301 可以记录文章 ID 和跳转原因，阅读量只在规范 GET 200 增加。这样可以观察旧规则使用量，同时避免同一访问重复计数。

设置启用页提供流式 CSV 迁移清单，字段固定为：`article_id`、`title`、`old_url`、`new_url`、`change_reason`。清单按当前站点可访问文章范围生成，不包含草稿或其他托管站文章。

启用后的运营动作包括重新提交 sitemap、检查 CDN/反向代理缓存和观察 404/301 趋势。系统内缓存自动失效；外部 CDN 清理由部署方执行，首版不新增供应商集成。

## 12. 主题与预览契约

所有内置主题把文章链接改为 `SiteUrlGenerator::article($article)` 或由布局统一注入的等价 helper。实施后自动扫描 `resources/views`，内置主题不得继续出现以下模式：

- `route('site.article', ...)`
- 字面量 `/article/`
- 通过字符串拼接生成文章 URL

预计会机械修改约 132 个 Blade 文件、约 330 处调用。此范围较大，但判断逻辑集中在一个服务；主题文件只做等价替换。测试需覆盖全部内置主题的首页、分类和文章页链接。

已安装的旧主题继续通过 `site.article` 命名路由生成兼容地址，再由 301 到规范地址。主题目录和导入检查增加兼容警告，提示开发者改用统一 helper。新生成的主题脚手架直接输出统一 helper。

`SiteThemePreviewContext` 不再维护固定的 `article/` 路径白名单。预览 URL 通过固定链接服务生成后，由预览上下文添加 frame base；预览内部链接继续停留在预览环境，普通请求的规则缓存不受影响。

## 13. 后台保存、权限与审计

主站新增管理接口：

| 方法与相对路径 | 路由名 | 用途 |
| --- | --- | --- |
| `POST site-settings/article-permalink/preview` | `admin.site-settings.article-permalink.preview` | 校验、冲突预检、示例和签名凭据 |
| `POST site-settings/article-permalink` | `admin.site-settings.article-permalink.update` | 校验凭据并原子启用 |
| `GET site-settings/article-permalink/migration-map` | `admin.site-settings.article-permalink.migration-map` | 依据有效预览凭据导出 CSV |

三个接口均使用 `admin.auth`、`admin.super`、CSRF、现有敏感操作限流和 `admin.activity`。签名凭据绑定管理员 ID、站点 ID、当前 revision、旧模板、新模板、生成时间和摘要哈希，15 分钟后失效。

事务内锁定 `article_permalink_policy` 设置行；旧 revision 不一致时返回「规则已被其他管理员更新，请重新预览」。提交成功后再清理站点设置缓存。审计详情只记录站点、旧模板、新模板、revision、影响文章数和成功状态，不记录文章标题清单或完整设置正文。

主站基础设置继续走现有保存接口。后台入口路径保存前调用保留路径注册表验证当前固定链接规则，避免后续把文章路径变成后台入口。

所有新增公开路由都必须同步登记到保留路径注册表。CI 读取 Laravel route collection，对比保留路径注册表并阻止遗漏；系统升级健康检查在发现已启用的自定义规则与新增系统路由相交时给出阻断错误和回切建议。

## 14. 第一方托管站

第一方托管站复用同一规则引擎和同一文章范围查询。新增托管站级预览、启用和迁移清单接口，挂在现有 `admin.distribution.hosted-sites.*` 超级管理员权限组中。

托管域名请求在全局中间件阶段按本站策略做形状放行，文章解析仍受以下条件限制：

- 文章已分配到当前 `HostedSiteProfile`。
- assignment 状态为 published。
- 文章状态和审核状态符合现有托管站公开规则。
- 归档、维护、激活探针和 noindex 行为保持现有定义。

规则启用事务同时更新渠道设置和 `settings_version`。提交后：

1. 失效当前域名的 CurrentSite 与 SiteSettings 缓存。
2. 以队列任务按 ID 分块刷新该站点现有 `article_distributions.remote_url`。
3. Publisher、Reconciler 和失败恢复立即改用生成器，新写入不会继续产生旧地址。
4. 刷新完成前，旧 `remote_url` 仍通过 301 可访问。

队列任务按 `profile_id + policy_revision` 幂等。若任务执行时 revision 已变化，旧任务停止，最新 revision 的任务继续。

## 15. GEOFlow Agent 目标站包

目标站包把前端能力版本从 `1.2` 升至 `1.3`，增加：

```json
{
  "supports_article_permalink_policy": true,
  "article_permalink_schema_versions": [1],
  "current_article_permalink_revision": 3,
  "current_article_permalink_pattern": "/article/{slug}"
}
```

渠道同步预览只有在远端能力明确支持 schema 1 时才允许发送自定义规则。旧包继续使用默认地址，后台提示重新下载目标站包，不向旧端点发送它无法理解的字段。

目标包内实现与 Laravel 同语义的轻量规则函数：模板规范化、令牌渲染、路径匹配、规范跳转和路径冲突检查共用一组测试向量。PHP 目标包不执行用户正则。

设置同步采用以下顺序：

1. GEOFlow 读取实时 frontend capabilities，核对 schema 和远端 revision。
2. 远端接收带 `expected_revision` 的设置，先校验并在独占 staging 目录完成静态缓存构建。
3. 构建成功后原子切换设置文件和当前静态 manifest，再返回新 revision。
4. GEOFlow 保存渠道设置和远端 revision；若本地保存失败，下一次同步通过远端 capabilities 对账并幂等补写。
5. 任一步失败时，远端继续使用旧设置和旧静态 manifest。

静态模式不再把公开 URL 直接等同于 `article/{slug}/index.html`。构建器按规范路径生成私有静态缓存，并写入 `request_path → cache_file` manifest。前端控制器先用固定链接规则解析请求，再从当前 manifest 输出预渲染内容。这样可以一致支持 `/{slug}.html`、日期路径、ID 路径和无扩展路径。

Apache、Nginx 和宝塔规则增加安全的应用路径回退：

- 已知公共资源文件继续直接读取。
- Agent API、文章当前规则、历史规则和永久兼容规则进入 `index.php`。
- `config.php`、storage、构建 manifest 和私有静态缓存继续拒绝公网访问。
- 老版本生成的公开 `article/{slug}` 静态目录只在新 manifest 生效后按包拥有清单移入可恢复暂存区，避免旧文件绕过 301。

文章发布、更新、删除、首页模块、canonical、JSON-LD、`sitemap.txt`、`llms.txt` 和返回给 GEOFlow 的 `remote_url` 全部调用目标包固定链接函数。静态构建失败不会覆盖已生效页面。

## 16. 缓存、性能与并发

规则编译缓存键包含站点类型、站点 ID、policy revision 和 schema version。主站使用设置 revision；托管站使用 `settings_version + policy revision`。缓存值只保存规范化结构和编译产物，不缓存 Article 模型。

每个请求最多读取一次策略。`EnforceCurrentSiteSurface` 复用编译结果，文章控制器只执行一次文章解析查询。当前 slug、历史 slug 和 ID 都有索引。

启用预检通过 `chunkById` 遍历当前站点可访问文章，并计算当前、候选、历史和兼容路径。路径哈希表用于发现跨模板冲突，遇到 50 条冲突后停止收集详情并返回总计下限。该操作只由超级管理员触发，不进入普通前台请求。

大列表和 sitemap 查询补齐 `id`、`slug`、`created_at`、`category_id`，仅在模板包含 `{category}` 时 eager load `category:id,slug`。加入查询数量测试，确保 100 篇列表不会按文章数量增加 SQL 查询。

规则写入使用行锁和 revision。Slug 变更使用事务、当前/历史唯一索引和有界重试，覆盖 SQLite 测试环境与 PostgreSQL 并发契约。

## 17. 错误处理与可观测性

统一原因码：

| 原因码 | 含义 |
| --- | --- |
| `current_permalink` | 当前规范地址 200 |
| `legacy_pattern` | `/article/{slug}` 兼容入口 |
| `retired_pattern` | 命中历史规则 |
| `stale_slug` | 命中旧 slug |
| `stale_category` | 分类描述与当前值不一致 |
| `stale_date` | 日期描述与当前值不一致 |
| `trailing_slash` | 可识别路径带尾斜杠 |
| `ambiguous_permalink` | 多个模板解析出不同文章 |
| `invalid_permalink_path` | 编码、长度或结构非法 |
| `article_not_visible` | 文章不属于当前站点公开范围 |

301 数量和原因写入现有访问日志可用字段或结构化应用日志，避免新增高基数指标依赖。后台诊断页展示最近 7 天旧规则访问量、404 数量和模糊匹配告警。日志写入失败继续保持不影响前台响应。

设置解析失败时回退默认规则并记录站点 ID、schema version 和异常类型，不记录原始恶意字符串。Agent 设置同步失败进入现有分发日志和渠道错误状态。

## 18. 数据库与迁移策略

数据库变化只有一张新表 `article_slug_histories`，同时为历史 slug 建立唯一索引、为 `article_id` 建立普通索引。策略继续使用现有设置存储，不新增站点策略表。

迁移步骤：

1. 创建历史表和索引。
2. 校验现有 `articles.slug` 的唯一性与路由安全性，异常只写迁移后健康报告，不修改数据。
3. 不写入 `article_permalink_policy` 默认行；读取缺失值时自然使用 `/article/{slug}`。
4. 部署应用代码后，默认路由、主题链接和 sitemap 输出保持原值。
5. 只有超级管理员完成预览并确认后，才产生首条策略设置和 URL 迁移。

回滚应用版本时，默认站点仍可通过 `/article/{slug}` 访问。若已启用自定义规则，应先在后台回切默认规则并确认 301 生效，再回滚代码。历史表和策略设置可以保留；旧代码会忽略它们。数据库迁移不在紧急应用回滚时向下删除，以免丢失 slug 历史。

## 19. 测试与验收标准

### 19.1 单元测试

- 六个预设和自定义模板的规范化、渲染和反向匹配。
- 令牌缺失、未知令牌、重复令牌、相邻令牌、路径穿越、控制字符、编码分隔符和超长输入。
- 系统路由、动态后台前缀、静态资源路径和历史模板冲突。
- 当前规则、历史规则和兼容规则解析到同一文章或多个文章的结果。
- slug 历史写入、复用、软删除占用、强制删除和并发冲突。
- Laravel 与 Agent 共享测试向量产生完全相同的规范路径。

### 19.2 主站 Feature 测试

- 无设置时 `/article/{slug}` 继续 200，页面 URL 与升级前一致。
- 每个预设的当前 URL 200，旧地址一次 301，目标无二次跳转。
- 301 保留查询参数；HEAD 不累计阅读量；草稿和软删除返回 404。
- 标题编辑保持 slug；显式 slug 变更后旧 slug 301。
- 分类变更后旧分类路径 301；日期使用 `created_at`。
- canonical、Open Graph、JSON-LD、首页、分类、归档、相关文章和 sitemap 全部输出候选规则。
- 管理员预览不写设置；过期凭据、非超级管理员、错误 revision 和冲突规则无法启用。
- 后台入口路径与固定链接规则互相阻止冲突。
- route cache 构建后自定义规则仍可访问。
- 100 篇列表和 sitemap 的查询数保持有界。

### 19.3 主题测试

- 自动扫描所有内置 Blade 和主题脚手架，不允许旧 route helper 与字面量文章路径。
- 每个内置主题至少验证首页文章链接和详情页 canonical。
- 旧安装主题使用兼容路由仍可打开，并显示兼容警告。
- 主题预览中的自定义文章链接留在预览 frame。

### 19.4 托管站测试

- 两个托管域名使用不同规则，互不串用设置和文章。
- 中间件允许本站自定义文章路径，拒绝后台、API 和另一站文章。
- 规则更新递增 settings version、清缓存并刷新 remote URL。
- 归档、维护、noindex、激活探针和 sitemap shard 行为保持一致。
- PostgreSQL 并发更新只允许一个 revision 成功。

### 19.5 Agent 包测试

- capability 1.3 返回策略支持信息；旧 capability 阻止自定义规则同步。
- 动态和静态模式覆盖六个预设、子目录安装和 `index.php` fallback。
- Apache、Nginx、宝塔规则可到达根级 `.html`、日期路径和永久兼容地址。
- 设置同步在构建失败、磁盘不可写、revision 冲突时保留旧策略和旧页面。
- 规则切换后旧公开静态文件不会绕过 301。
- publish、update、delete 返回当前 `remote_url`，sitemap 与 llms 使用同一地址。

### 19.6 完整质量门

实施完成后执行：

```bash
vendor/bin/pint --test
php artisan route:cache
php artisan route:list
composer test
npm run build
npm run test:analytics
composer validate --strict
```

PostgreSQL 环境执行 `vendor/bin/phpunit -c phpunit.postgresql.xml`。同时运行 `git diff --check`、固定链接字面量扫描和目标包测试向量校验。

验收完成的定义：

1. 默认升级零 URL 变化。
2. 任一允许规则在主站和对应站点生成、识别一致。
3. 所有历史入口一次 301 到当前规范 URL。
4. 页面、主题、SEO、sitemap、分析和分发地址不存在第二套拼接逻辑。
5. 路由缓存、托管域名隔离和 PostgreSQL 并发测试通过。
6. Agent 设置或静态构建失败时，线上旧页面继续可用。

## 20. 实施分期与文件范围

### 第一期：主站端到端

交付可独立上线的主站功能：

- 新增 policy/resolution 数据对象、`ArticlePermalinkService`、`ArticleSlugRegistry`、历史模型与迁移。
- 新增后台预览、启用和迁移 CSV 控制器与视图。
- 调整 `routes/web.php`、`ArticleController`、`SiteUrlGenerator`、`SiteSettingsBag`、`RecordSiteViewLog`、`SiteThemePreviewContext`、`AdminBasePathManager` 和站点发现输出。
- 调整文章后台/API/Worker slug 写路径。
- 修改约 132 个内置主题 Blade 文件和主题脚手架。
- 增加中英文后台文案、CLI 文档和测试。

预计涉及 150 个左右文件，其中大部分为主题链接的机械替换；新增 2 个业务服务、2 个数据对象、1 个模型、1 个控制器组和 1 张表。评估工期为 6–9 个工程日，包含全主题回归。

### 第二期：第一方托管站

交付逐站规则、托管域名放行、缓存隔离、remote URL 刷新和托管站管理入口。主要涉及 `DistributionChannel`、`HostedSitePublisher`、`HostedSiteReconciler`、`HostedSitePublishFailureService`、`EnforceCurrentSiteSurface`、`HostedSiteController`、托管站视图和测试。

评估工期为 3–4 个工程日。

### 第三期：Agent 目标站包

交付 capability 1.3、同构规则函数、远端 revision、设置预构建、私有静态 manifest、安全重写、旧静态文件迁移和分发同步检查。主要涉及 `DistributionTargetSitePackageBuilder`、`DistributionRewriteRuleGenerator`、`FrontendExperienceInspector`、`DistributionHttpClient`、渠道设置模型/视图、样例 Agent、文档和目标包测试。

评估工期为 4–6 个工程日。

三期合计评估为 13–19 个工程日。时间主要消耗在主题契约收口、旧链接兼容、托管站隔离和目标包静态模式，规则输入框本身只占较小部分。

## 21. 最小可用替代方案

若需要压缩首期，可只提供四个预设：`/article/{slug}`、`/{slug}.html`、`/article/{id}.html`、`/article/{id}-{slug}.html`，暂缓 `{category}`、日期令牌和自定义输入。核心双向引擎、301、slug 稳定、主题收口和 SEO 同步仍需保留。

该范围预计 4–6 个工程日，只覆盖主站。它能降低模板校验和元数据变更测试成本，但无法满足完整的「多种自定义规则」目标，因此最终推荐仍为三期完整方案。

## 22. 风险与回退

| 风险 | 控制措施 | 回退方式 |
| --- | --- | --- |
| 自定义规则覆盖系统路由 | 统一保留路径注册、双向设置校验、启用前冲突预检 | 规则不落库；站点继续旧规则 |
| 多个历史模板解析到不同文章 | 运行时多候选检测、预检阻断、结构化告警 | 返回 404，管理员回切或调整模板 |
| 内置或安装主题仍输出旧地址 | 全仓扫描、主题契约检查、兼容命名路由 | 旧地址 301，页面保持可访问 |
| slug 变更导致外链失效 | 标题编辑冻结 slug、历史表、显式变更事务 | 旧 slug 301；可恢复该历史 slug |
| 规则切换引起搜索波动 | 直接 301、自引用 canonical、迁移 CSV、sitemap 同步 | 后台回切上一历史规则，旧新映射继续保留 |
| 设置并发覆盖 | revision、签名预览凭据、行锁 | 冲突提交失败并要求重新预览 |
| 大站预检耗时 | 后台专用 chunk 扫描、索引、冲突详情上限 | 超时不保存，可在提高管理请求时限后重试 |
| Agent 静态构建中断 | staging 构建、原子 manifest、旧文件拥有清单 | 保持旧 revision 与旧 manifest |
| 外部 CDN 缓存旧 301 | 受控 301 cache-control、部署检查 | 清理 CDN；一小时内浏览器缓存自然更新 |

## 23. 明确不纳入本次范围

- 单篇文章手工填写完整 URL。
- 同一站点按分类同时启用多套 canonical 规则。
- 自定义域名、协议、查询参数或任意正则。
- 自动改写文章正文、富文本和第三方主题中的历史链接。
- WordPress permalink 管理、Generic API 远端路由管理。
- Google Search Console、Cloudflare 或其他 CDN 的自动提交与缓存清理。
- 分类页、标签页、作者页和静态页面的自定义固定链接。
- 多语言 locale 前缀；未来可作为模板编译器的独立扩展。

## 24. 最脆弱的前提

最脆弱的前提是现有文章规模允许超级管理员在规则启用前完成一次分块路径冲突预检。当前系统的 sitemap、后台批量操作和目标站构建均面向中等规模内容库，这一假设与现有架构一致。实施前会用生产级数据量做基准：10 万篇文章、当前规则加 10 条历史规则时，预检目标为 30 秒内完成且峰值内存不超过 256 MiB。若基准未达标，第一期会把路径哈希暂存改为数据库临时表，产品流程和外部契约保持不变。

## 25. 确认后的实施顺序

收到确认后先建立第一期功能分支，按测试先行完成规则语法、路由冲突和 301 契约，再接入后台、slug 历史、SEO 和主题。第一期通过完整 CI 后进入第二期托管站，随后进入第三期 Agent 包。每期都保持默认规则可用，并提交独立可审查的变更。
