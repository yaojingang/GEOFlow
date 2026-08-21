# GEOFlow 托管渠道站点分发能力升级方案

> 文档状态：待确认
> 版本：1.0
> 日期：2026-08-21
> 适用范围：GEOFlow Laravel 主应用、公开站点、分发管理、任务调度、队列与部署层
> 实施约束：本方案经确认后再进入开发，本文件本身不包含功能代码变更

## 1. 最终结论

GEOFlow 可以在现有分发体系上增加“托管渠道站点”类型，通过泛解析把大量二级域名指向同一套 Laravel 应用，再由请求域名解析出站点上下文，完成站点级主题、设置、内容、SEO、表单和统计隔离。

推荐采用以下产品与技术形态：

1. 继续使用 `distribution_channels` 表达渠道、任务绑定和分发状态，新增 `hosted_site` 渠道类型。
2. 新增 `hosted_site_profiles` 保存托管站点的可查询配置、域名、容量、上线和索引状态。
3. 新增 `hosted_site_article_assignments` 保存文章与托管站点之间的一对一归属关系。
4. 使用请求级 `CurrentSite` 上下文统一驱动公开页面，后台、API、Horizon 和 Reverb 仅允许主站域名访问。
5. 泛解析只承担流量接入，站点只能通过后台单个创建或批量审核提交生成。未知域名不会自动创建站点。
6. 第一阶段交付单站点完整闭环，第二阶段增加批量生成和容量均衡，第三阶段补充规模化监控与运营辅助。

这条路线能最大化复用现有文章生产、任务、分发队列、主题和后台管理能力，同时把域名隔离、文章归属、并发分配、SEO 质量和运维回滚纳入第一版基础设计。

## 2. 方案 Review 结论

上一版方向成立，核心模型与 GEOFlow 当前代码结构能够衔接。本轮 Review 发现并补齐了以下关键缺口。

| 缺口 | 风险 | 本版处理 |
| --- | --- | --- |
| 泛解析请求缺少可信 Host 边界 | 未知域名可能进入主站，后台和实时服务可能暴露 | 增加入口层域名分流、Laravel Host 白名单、精确站点解析和未知域名 404 |
| 仅依赖 `article_distributions` 表达站点文章 | 无法数据库级保证一篇文章只进入一个托管站点 | 新增唯一文章归属表，并保留分发表负责队列、重试和审计 |
| 无目标站点时只有日志记录 | 待分配文章缺少可重试、可查询的持久状态 | 新增分配请求表，保存下一次尝试时间、次数、结果和错误码 |
| 自动均衡的选站与占位分离 | 并发任务可能突破日限额或重复分配 | 在同一数据库事务内锁定候选站点、复核容量、创建归属与分发记录 |
| 站点配置放在 JSON 中 | 域名唯一性、状态筛选和容量查询难以保证 | 新增一对一 profile 表，保留 `site_settings` 承载展示型配置 |
| 页面级内容隔离范围不完整 | 导航、相关推荐、首页模块和 Sitemap 可能串站 | 引入统一站点文章查询服务，覆盖所有公开查询入口 |
| Nginx 当前使用兜底域名 | 泛域名可能访问 Reverb、静态入口和框架特殊路径 | 拆分默认拒绝、主站、托管泛域名三类入口 |
| 新站点上线即被索引 | 低内容量和模板化页面会增加搜索风险 | 分离服务状态、分发状态、索引状态和质量状态，使用质量门禁控制索引 |
| 批量导入缺少预览和幂等 | 大批量误创建、重复提交和错误恢复成本高 | 增加批次、明细、文件哈希、预检、幂等提交和逐行报告 |
| 日志和表单缺少站点归属 | 无法按渠道站点评估流量和线索 | 给浏览日志和线索提交增加可空站点外键 |
| 生命周期与回滚语义不完整 | 暂停、维护、归档和删除容易混淆 | 定义独立状态机、软性下线流程和数据保留式回滚 |
| 主题兼容范围不明确 | 部分主题可能引用全局设置或生成错误链接 | 增加托管兼容声明、自动化兼容测试和安全回退主题 |
| 域名、TLS、合规责任未落地 | 大规模上线可能遇到证书、备案、隐私和内容风险 | 增加部署前置条件、运营确认清单和分级放量门槛 |

## 3. 当前系统基线

### 3.1 已有能力

当前仓库已经具备本功能可复用的主要基础：

- Laravel 12 主应用和公开站点路由。
- 文章、分类、任务、自动写作、定时发布和风险审核链路。
- `distribution_channels`、`task_distribution_channels`、`article_distributions` 和分发日志。
- `geoflow_agent`、`wordpress_rest`、`generic_http_api` 三类发布器。
- `broadcast`、`round_robin`、`random_balanced` 三种外部分发策略。
- `local_and_distribution`、`distribution_only`、`local_only` 三种任务发布范围。
- Redis、Horizon 和 `distribution` 队列。
- 站点设置、首页搭建、主题目录和公开页面日志。

### 3.2 当前约束

源码核对显示以下位置仍然采用全局站点假设：

- `routes/web.php` 的公开、后台和分发路由没有域名约束。
- `bootstrap/app.php` 没有站点解析和 Host 防护中间件。
- `config/geoflow.php` 使用全局站点 URL、语言和默认主题。
- `SiteSettingsBag` 使用单一静态缓存键。
- `SiteThemeViewResolver` 只解析一套全局主题。
- 首页、文章、分类、搜索、推荐和导航查询直接使用全局 `Article::published()`。
- `view_logs` 与 `lead_submissions` 没有托管站点标识。
- `docker/nginx/default.conf` 使用 `server_name _`，`robots.txt` 静态返回，Reverb 也位于同一兜底服务中。
- 现有发布选择器要求文章关联任务，且容量计算与最终占位之间缺少事务锁。

因此，域名解析只是入口能力。完整上线还需要请求隔离、数据归属、页面查询、SEO、运维和安全共同升级。

## 4. 目标与成功标准

### 4.1 产品目标

1. 管理员可以创建一个托管渠道站点并绑定二级域名、主题、任务和站点资料。
2. 管理员可以通过审核式批量流程生成大量站点。
3. 任务生成的文章可以按站点容量自动分配，并在对应域名公开访问。
4. 每篇文章在托管站点池中只有一个归属站点。
5. 每个站点拥有独立的标题、描述、主题、首页、关于页、SEO、表单和统计上下文。
6. 站点可以独立暂停分发、进入维护、恢复服务、开放索引和归档。
7. 主站后台、API、队列面板和实时连接不会通过托管站点域名暴露。

### 4.2 技术成功指标

| 指标 | 验收目标 |
| --- | --- |
| 未知泛域名 | 100% 返回 404，且不会显示主站内容 |
| 跨站内容泄漏 | 自动化测试与灰度期观测均为 0 |
| 一文多站重复归属 | 数据库约束下为 0 |
| 站点日限额突破 | 并发压力测试下为 0 |
| 自动分配等待 | 正常队列负载下 95% 在 5 分钟内完成 |
| 托管站点错误率 | 灰度期 5xx 低于 0.5% |
| 页面性能 | 托管站点 P95 不高于主站同类页面 20% |
| 索引门禁绕过 | 为 0 |
| 批量提交幂等 | 同一批次重复提交不产生重复站点 |
| 主站回归 | `composer test`、代码格式和前端构建全部通过 |

## 5. 范围

### 5.1 本期范围

- 一个或多个受控根域名下的单层二级域名。
- 单套 GEOFlow 部署、数据库、媒体存储、后台和队列。
- 托管站点单个创建、编辑、预检、上线、暂停、维护、索引和归档。
- 托管站点任务绑定、文章一对一归属和自动容量均衡。
- CSV 批量预览、提交、进度、失败明细和结果导出。
- 公开页面全链路站点隔离。
- 站点级主题、首页设置、关于页、SEO 和表单白名单。
- 站点级访问日志、线索归属和基础运营指标。
- 域名、证书、代理、缓存和回滚部署说明。

### 5.2 暂不纳入

- 自定义顶级域名、域名别名、深层子域名和国际化域名。
- 每站独立数据库、文件系统、容器、后台账号体系或发布版本。
- 站点租户自助注册、计费和套餐。
- 应用内自动修改 DNS 或持有 DNS 服务商 API 密钥。
- Search Console 自动接入和搜索引擎索引承诺。
- 全站静态化生成。
- 以改写同一内容为目标的大规模近似页面生成。
- 自动完成法律、备案、隐私和内容版权判断。
- 第一版对所有站点执行高频 AI 可见性采样。

## 6. 参考项目与可复用思路

| 参考 | 借鉴点 | GEOFlow 落地方式 |
| --- | --- | --- |
| [WordPress Multisite](https://developer.wordpress.org/advanced-administration/multisite/create-network/) | 虚拟站点共享一套程序，子域模式依赖泛解析 | 共享 Laravel 应用和数据底座，站点通过 Host 与数据库配置识别 |
| [WordPress 网络准备说明](https://developer.wordpress.org/advanced-administration/multisite/prepare-network/) | 上线前先完成域名、服务器和网络形态选择 | 把 DNS、TLS、代理和主域隔离列入启用前检查 |
| [Vercel Platforms Starter Kit](https://github.com/vercel/platforms) | 根域与租户域分流，租户域限制管理入口，再把请求交给对应站点 | 入口层划分主站和托管域名，应用层绑定 `CurrentSite` |
| [stancl Tenancy 域名解析](https://tenancyforlaravel.com/docs/v3/domains/) | 精确域名匹配、解析器缓存、找不到租户时终止请求 | 自研轻量解析器，使用精确 hostname 唯一索引和短期负缓存 |
| [Laravel 子域路由](https://laravel.com/docs/12.x/routing#subdomain-routing) | 框架支持域名参数路由，域名路由应先于根域路由注册 | 公开站点采用 Host 中间件与路由白名单，中央路由单独保护 |
| [Cloudflare 泛 DNS 说明](https://developers.cloudflare.com/dns/manage-dns-records/reference/wildcard-dns-records/) | 泛记录可把大量名称指向同一资源，精确记录优先 | DNS 负责接入，数据库记录决定站点是否存在和可访问 |
| [Let's Encrypt 验证方式](https://letsencrypt.org/docs/challenge-types/) | DNS-01 支持通配符证书，验证凭据应缩小权限范围 | 推荐 CDN 托管证书或独立 DNS-01 自动化，GEOFlow 不保存 DNS 主账号凭据 |
| [Google 搜索垃圾政策](https://developers.google.com/search/docs/essentials/spam-policies) | 大量低价值页面和门页会带来搜索风险 | 新站默认 noindex，质量门禁通过后逐站开放索引 |
| [Google Sitemap 指南](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap) | 单文件上限 50,000 个 URL 或 50MB，URL 应使用绝对地址 | 按站生成 Sitemap 索引与分片，每片不超过 50,000 条 |

本方案吸收这些项目的架构原则，不引入完整多租户依赖。当前共享内容与共享后台的边界下，轻量站点上下文更适合 GEOFlow。

## 7. 总体架构

```text
                    +--------------------------+
                    | Wildcard DNS and TLS     |
                    | *.sites.example.com      |
                    +-------------+------------+
                                  |
                    +-------------v------------+
                    | Reverse proxy or CDN     |
                    | Host-aware routing       |
                    +------+------+------------+
                           |      |
              central host|      |hosted wildcard
                           |      |
             +-------------v+    +v----------------+
             | Admin/API/   |    | Public site     |
             | Horizon/WS   |    | route allowlist |
             +-------------++    +--------+---------+
                           |              |
                           |     +--------v---------+
                           |     | HostedSiteResolver|
                           |     | CurrentSite       |
                           |     +--------+----------+
                           |              |
                    +------v--------------v----------+
                    | Laravel application            |
                    | settings, themes, content, SEO |
                    +------+------------------+-------+
                           |                  |
                 +---------v------+   +-------v--------+
                 | PostgreSQL     |   | Redis/Horizon  |
                 | profiles,      |   | distribution   |
                 | assignments    |   | reconciliation |
                 +----------------+   +----------------+
```

### 7.1 核心请求流程

1. DNS 和证书层接收目标根域下的二级域名。
2. 反向代理按 Host 把主站请求与托管站点公开请求送入不同入口。
3. Laravel 对 Host 做标准化和可信校验。
4. `HostedSiteResolver` 按完整 hostname 精确查找 profile。
5. 查找成功后绑定请求级 `CurrentSite`，查找失败返回 404。
6. 页面控制器通过站点查询服务获取文章、分类、设置、主题和表单。
7. URL、canonical、JSON-LD、Sitemap 和日志都读取当前站点上下文。

## 8. 关键设计决策

### 8.1 托管站点复用分发渠道

新增 `channel_type = hosted_site`。它继续参与以下既有流程：

- 任务与渠道绑定。
- 渠道启用、暂停和审计。
- 文章分发记录、重试和日志。
- 任务监控和文章分发状态展示。

托管站点发布器在本地完成归属确认和缓存失效，无需发出 HTTP 请求。`endpoint_url` 由 hostname 自动生成 `https://{hostname}`，后台以只读方式展示，以兼容现有非空约束与详情页。

托管站点不展示外部渠道专属操作，包括密钥轮换、密钥查看、Agent 安装包、远端能力刷新和远端健康检查。

创建托管站点时不生成 `distribution_channel_secrets`。既有 `last_health_status` 可记录域名、TLS、页面与 SEO 综合预检结果。

### 8.2 使用独立 profile 保存可查询状态

域名、容量、冷却时间和上线状态需要唯一约束、索引和事务锁。展示型设置继续存放在渠道 `site_settings`，结构化运营数据进入 `hosted_site_profiles`。

`distribution_channels.domain` 暂时镜像 profile 的 hostname，便于既有列表、筛选和兼容代码使用。`hosted_site_profiles.hostname` 是域名真值来源，所有修改通过统一服务同步镜像字段。

### 8.3 一篇文章只归属一个托管站点

`hosted_site_article_assignments.article_id` 建立唯一索引。这个约束覆盖手动指派、任务自动分配、重试和并发场景。

`article_distributions` 继续负责发布动作、幂等、队列重试和审计。归属表负责站点内容可见性。两个对象通过可空唯一外键关联，该外键只关联 `action = publish` 的首次发布记录；更新与删除继续使用同一文章和渠道下的独立动作记录。

### 8.4 不使用全局 Eloquent Scope

后台、Worker、统计和分配器需要访问全量文章。公开页面通过 `SiteScopedArticleQuery` 主动施加当前站点范围，降低后台查询被意外过滤的风险。

### 8.5 泛解析不会触发自动建站

访问未知 hostname 时只执行短期负缓存和 404 响应。站点创建必须来自已认证后台的单个创建流程或已审核批次提交，避免域名扫描制造数据、缓存和证书压力。

## 9. 数据模型

### 9.1 `distribution_channels` 调整

新增合法类型 `hosted_site`，复用现有字段和关联。需要同步更新模型、请求校验、创建和编辑页、类型翻译、发布器管理器、删除影响分析与测试。

托管站点渠道约定：

| 字段 | 规则 |
| --- | --- |
| `channel_type` | 固定为 `hosted_site` |
| `name` | 运营可读的站点名称 |
| `domain` | 镜像 profile hostname |
| `endpoint_url` | 自动生成的 HTTPS 地址，只读 |
| `status` | 控制是否接收新文章，可用既有 active 和 paused 语义 |
| `template_key` | 站点主题标识 |
| `site_settings` | 展示设置、首页配置、SEO 和表单白名单 |
| `channel_config` | 仅保存不涉及检索和锁定的兼容配置 |

### 9.2 新增 `hosted_site_profiles`

| 字段 | 类型与约束 | 说明 |
| --- | --- | --- |
| `id` | 主键 | Profile 标识 |
| `distribution_channel_id` | 唯一外键 | 与托管渠道一对一，删除策略由归档服务控制 |
| `hostname` | 唯一字符串 | 标准化后的完整小写域名 |
| `root_domain` | 索引字符串 | 受控根域名 |
| `topic` | 字符串 | 站点主题定位 |
| `locale` | 字符串 | 默认语言，例如 `zh_CN` |
| `timezone` | 字符串 | 运营和日限额计算时区 |
| `daily_publish_limit` | 正整数，默认 3 | 单个自然日最大发布数 |
| `publish_weight` | 正整数，默认 100 | 容量相同时的选择权重 |
| `min_publish_interval_minutes` | 正整数，默认 360 | 两次发布最小间隔 |
| `min_articles_before_index` | 正整数，默认 10 | 开放索引最低文章量 |
| `serving_status` | 枚举 | `maintenance`、`online`、`archived` |
| `indexing_status` | 枚举 | `noindex`、`index` |
| `quality_status` | 枚举 | `pending`、`passed`、`blocked` |
| `consecutive_publish_failures` | 非负整数 | 连续发布失败计数 |
| `cooldown_until` | 可空时间 | 自动分配冷却截止时间 |
| `last_published_at` | 可空时间 | 容量排序和间隔校验 |
| `activated_at` | 可空时间 | 首次在线时间 |
| `indexed_at` | 可空时间 | 首次开放索引时间 |
| `archived_at` | 可空时间 | 归档时间 |
| `created_at`、`updated_at` | 时间 | 审计时间 |

推荐索引：

- `unique(hostname)`
- `unique(distribution_channel_id)`
- `index(root_domain, serving_status, indexing_status)`
- `index(cooldown_until, last_published_at)`

profile 外键对渠道使用 `cascadeOnDelete`，并要求所有生产删除先经过生命周期服务。常规停用只修改为 `archived`，不会触发级联删除。

### 9.3 新增 `hosted_site_article_assignments`

| 字段 | 类型与约束 | 说明 |
| --- | --- | --- |
| `id` | 主键 | 归属记录标识 |
| `article_id` | 唯一外键 | 数据库级保证每篇文章只有一个托管站点 |
| `hosted_site_profile_id` | 外键与索引 | 目标站点 |
| `article_distribution_id` | 可空唯一外键 | 对应首次 publish 分发执行记录 |
| `status` | 枚举 | `reserved`、`published`、`failed`、`withdrawn` |
| `content_fingerprint` | 唯一 64 字符串 | 标准化标题与正文的 SHA-256 指纹 |
| `capacity_date` | 站点本地日期与索引 | 该次发布占用的日容量日期 |
| `reservation_expires_at` | 可空时间与索引 | 预留槽位超时点 |
| `assigned_at` | 时间 | 成功占位时间 |
| `published_at` | 可空时间 | 对外可见时间 |
| `withdrawn_at` | 可空时间 | 下架时间 |
| `last_error_message` | 可空文本 | 最近失败摘要，禁止保存密钥和完整响应 |
| `created_at`、`updated_at` | 时间 | 审计时间 |

可见文章必须同时满足：

1. 归属记录指向当前站点。
2. 归属状态为 `published`。
3. 文章通过既有审核要求。
4. 文章状态为 `published`，或任务发布范围为 `distribution_only` 且文章状态为 `private`。
5. 站点未归档。

指纹只处理完全重复内容。近似内容质量由运营规则和后续质量能力控制，第一版不引入高成本语义去重服务。

推荐增加 `index(hosted_site_profile_id, capacity_date, status)`，用于事务内复核站点当日已发布与有效预留数量。发布任务跨越站点本地日期或预留超时后，需要在 profile 行锁内重新获取当日槽位，再进入 `published`。

第一版把已公开文章的归属视为稳定 SEO 关系。文章首次公开前，超时或失败的预留可以在事务和审计保护下改派到另一个合格站点。文章公开后只能在原站点显式重新发布，不支持直接迁移到另一个托管站点。未来如增加公开文章跨站迁移，需要设计旧 URL 301、canonical、Sitemap 清理和审计流程。

归属表对文章和 profile 使用 `cascadeOnDelete`，对 `article_distribution_id` 使用 `nullOnDelete`。硬删除站点会清理归属和分发执行记录，文章主体继续保留；删除文章会同步清理其归属。该动作只能在影响预览、备份和双重确认后执行。

### 9.4 新增 `hosted_site_allocation_requests`

分配请求表为自动选站提供持久待办队列。文章进入托管分发条件时先创建或复用唯一请求，再由分配器尝试选择站点。

| 字段 | 类型与约束 | 说明 |
| --- | --- | --- |
| `id` | 主键 | 请求标识 |
| `article_id` | 唯一外键 | 每篇文章最多一个分配请求 |
| `task_id` | 可空外键与索引 | 发起分配时的任务 |
| `hosted_site_article_assignment_id` | 可空唯一外键 | 分配成功后的归属记录 |
| `status` | 枚举与索引 | `pending`、`allocating`、`assigned`、`failed`、`cancelled` |
| `attempt_count` | 非负整数 | 分配尝试次数 |
| `next_attempt_at` | 可空时间与索引 | 下一次补偿时间 |
| `last_attempt_at` | 可空时间 | 最近尝试时间 |
| `last_error_code` | 可空字符串 | 稳定错误码，例如 `no_capacity` |
| `last_error_message` | 可空文本 | 脱敏错误摘要 |
| `created_at`、`updated_at` | 时间 | 审计时间 |

对文章使用 `cascadeOnDelete`，对任务和归属记录使用 `nullOnDelete`。分配器锁定请求行后再锁定候选 profile，处理成功后把状态改为 `assigned`。文章失去发布资格、被删除或任务切换到 `local_only` 时，请求进入 `cancelled`。

### 9.5 访问与线索归属

为以下表增加可空 `hosted_site_profile_id`，使用索引和 `nullOnDelete`：

- `view_logs`
- `lead_submissions`

主站记录保持 `null`。托管站点访问日志使用 `source = hosted_site`。线索表单限流键使用站点 ID 与客户端 IP 组合，便于隔离不同站点流量。

### 9.6 第二阶段批次表

新增 `hosted_site_batches`：

- 原文件名、文件哈希、总行数、有效行数、失败行数。
- `previewed`、`committing`、`completed`、`failed` 状态。
- 创建人、提交人、预览时间、提交时间和完成时间。
- 规范化参数快照和错误摘要。

新增 `hosted_site_batch_items`：

- 批次外键、原始行号、规范化 hostname 和站点名称。
- 原始数据 JSON、规范化数据 JSON、校验错误 JSON。
- `pending`、`created`、`failed`、`skipped` 状态。
- 已创建的渠道和 profile 外键。
- 行级幂等键和执行时间。

### 9.7 第三阶段指标表

当原始日志量开始影响后台查询时，增加 `hosted_site_daily_metrics`，按站点、日期聚合访问量、文章量、线索量、发布成功率和错误数。聚合任务每小时运行，原始日志保留周期由部署配置管理。

## 10. Host 路由与安全边界

### 10.1 域名规则

- 第一版只允许单个 ASCII 标签加已配置根域名，例如 `finance.sites.example.com`。
- 子域标签统一转小写，去除尾部点，拒绝端口、空标签、连续点和超长名称。
- 标签使用 DNS 安全格式，长度 1 至 63，完整 hostname 不超过 253 字符。
- 固定保留词至少包含 `www`、`admin`、`api`、`horizon`、`reverb`、`mail`、`smtp`、`ftp`、`cdn`、`static`、`assets`、`status`、`up`、`localhost`。
- 创建时校验数据库唯一性、主站冲突、保留词、根域白名单、DNS 可达性和 TLS 可用性。
- `X-Forwarded-Host` 只在请求来自明确信任的代理 IP 或 CIDR 时生效。

### 10.2 三类入口

Nginx 或上游代理配置拆分为：

1. 默认入口：拒绝未匹配 Host，可直接返回 444 或 404。
2. 主站入口：允许公开主站、后台、API、Horizon、Reverb、Sanctum 和必要的框架路径。
3. 托管泛域入口：只允许公开站点 PHP 路由和只读静态资源。

当前 `/robots.txt` 的 Nginx 静态处理需要移除或仅保留在主站入口，托管站点的 robots 必须由 Laravel 根据站点索引状态动态生成。

Reverb 的 WebSocket 代理只能出现在主站入口，避免它绕过 Laravel 中间件暴露到所有二级域名。

### 10.3 Laravel 中间件顺序

推荐顺序如下：

1. `TrustProxies`
2. `TrustHosts`
3. `NormalizeRequestHost`
4. `ResolveCurrentSite`
5. 路由组自身的会话、CSRF、语言和访问日志中间件

`ResolveCurrentSite` 返回两类上下文：

- `primary`：精确匹配主站 Host。
- `hosted`：精确匹配已存在的 `hosted_site_profiles.hostname`。

未知 Host 立即返回 404。解析失败不会回落到主站。

### 10.4 托管域名路由白名单

托管站点只开放以下能力：

```text
GET|HEAD /
GET|HEAD /about
GET|HEAD /category/*
GET|HEAD /article/*
GET|HEAD /forms/*
POST     /forms/*/submissions
GET|HEAD /robots.txt
GET|HEAD /sitemap.xml
GET|HEAD /sitemaps/*
```

后台、API、Horizon、Reverb、Sanctum、Boost、调试入口、存储变更入口和其他未列出路径全部拒绝。

### 10.5 Cookie、CSRF 与缓存

- 保持 `SESSION_DOMAIN=null`，使用 Host-only Cookie。
- 托管站点表单继续启用 CSRF，并确保令牌无法跨站复用。
- CDN 与反向代理缓存键必须包含 Host、路径、查询参数和内容协商维度。
- 应用缓存键包含站点 ID 和配置版本。
- 解析器正缓存建议 300 秒，负缓存建议 30 秒。
- profile 更新、归档、主题切换和文章发布后主动失效相应缓存。

## 11. 请求级站点上下文

### 11.1 `CurrentSite`

新增请求级对象，至少提供：

- 站点类型。
- profile 和渠道 ID。
- hostname 与基础 URL。
- locale 与 timezone。
- 主题标识。
- 站点设置版本。
- 服务、索引和质量状态。

队列和命令没有浏览器 Host，需要显式传入站点 ID，再由 `SiteUrlGenerator` 使用 profile hostname 生成绝对 URL。禁止在长生命周期进程中修改全局 `config('app.url')`。

### 11.2 `HostedSiteResolver`

职责：

- 标准化 Host。
- 判断主站、托管根域和非法域名。
- 按完整 hostname 精确查询 profile。
- 维护带版本的正缓存和短期负缓存。
- 输出稳定的“找到、未知、非法、功能关闭”结果。

全局开关关闭时，主站继续正常服务，所有托管域名返回 404。

### 11.3 `SiteSettingsResolver`

替换公开页面对全局 `SiteSettingsBag` 的直接依赖：

- 主站读取现有全局设置。
- 托管站点以默认站点设置为基础，再叠加渠道 `site_settings`。
- 缓存键包含站点 ID，以及 profile 与渠道 `updated_at` 共同生成的设置版本。
- 后台预览可以显式指定站点，不依赖当前请求 Host。

## 12. 公开页面数据隔离

### 12.1 统一文章查询服务

新增 `SiteScopedArticleQuery`，所有公开文章入口必须经由它完成查询：

- 首页最新文章和搜索。
- 分类页与分类导航。
- 文章详情页。
- 相关文章。
- 推荐、热门和置顶内容。
- 首页搭建器中的文章模块。
- Sitemap 与 Feed，如后续开放。

主站继续使用现有发布状态规则。托管站点查询额外连接 `hosted_site_article_assignments`，并限制 profile、归属状态和文章审核状态。

分类可以继续共享现有分类词表，分类导航和分类列表只展示当前站点存在可见文章的分类。广告位继续复用站点设置结构，并由 `SiteSettingsResolver` 解析当前站点策略，避免托管站点读取主站专属广告配置。

### 12.2 设置与页面内容

托管站点 `site_settings` 增加或规范以下字段：

- `site_name`
- `site_description`
- `site_keywords`
- `logo_url`
- `favicon_url`
- `theme_id`
- `homepage_settings`
- `about_title`
- `about_content`
- `repository_url`，可选
- `lead_form_slugs`
- `seo_title_template`
- `seo_description_template`

当前关于页中的 GEOFlow 固定文案需要改为站点设置驱动，并为主站保留现有默认内容。

### 12.3 表单隔离

- 托管站点只加载 `lead_form_slugs` 明确允许且处于启用状态的表单。
- 提交记录写入 profile ID。
- 限流、审计和导出支持按站点过滤。
- 批量文件不得设置自定义 HTML、JavaScript、统计脚本和其他可执行内容。
- 可执行或高风险展示配置只能由超级管理员在单站编辑页操作，并经过现有安全过滤。

### 12.4 URL 与结构化数据

所有公开 URL 使用 `SiteUrlGenerator`：

- 导航、分页、分类、文章和表单地址。
- canonical。
- Open Graph URL。
- JSON-LD 中的站点和文章地址。
- Sitemap 绝对 URL。
- 分发结果中的远端 URL。

这样可以避免 Worker、命令和后台预览使用主站 URL 生成托管站点链接。

## 13. 主题兼容策略

### 13.1 默认模式

托管站点默认使用 `snapshot_default` 前端体验模式，在创建时记录当前默认主题与展示设置快照。后续主站主题变化不会批量改变所有托管站点。

### 13.2 兼容声明

主题清单增加 `hosted_site_compatible` 能力声明。进入可选列表前需要通过：

- 公开路由完整性测试。
- 全部链接 Host 正确性测试。
- 文章、分类和推荐内容隔离测试。
- canonical、robots、Sitemap 和 JSON-LD 测试。
- 关于页、表单和空状态测试。
- 移动端和桌面端基础渲染检查。

任何主题解析失败都回退到 `site.*` 安全视图，并记录告警。首批只认证少量稳定主题，再逐步扩大目录。

## 14. 内容分配与自动运营

### 14.1 任务规则

- 绑定托管站点的任务在第一版默认使用 `publish_scope = distribution_only`。
- 当任务同时绑定外部渠道与托管站点时，外部渠道继续使用现有分发策略。
- 托管站点集合单独进入容量分配器，每篇文章只选择其中一个站点。
- 第一阶段每个任务只允许绑定一个托管站点，先完成端到端闭环。
- 第二阶段开放一个任务绑定托管站点池，并启用自动容量均衡。
- 未关联任务的文章可以由管理员手动指派，前提是文章已通过审核且符合托管发布状态要求。

手动指派也创建状态为 `assigned` 的分配请求，`task_id` 可以为空，便于后台使用同一状态模型追踪全部托管文章。

`DistributionOrchestrator` 在入队前把绑定渠道分成外部渠道集合与托管站点集合。外部集合交给现有 `TaskDistributionChannelSelector`，托管集合交给 `HostedSiteAllocator`。这样可以保留外部分发行为，并保证托管集合始终只产生一个目标站点。

### 14.2 容量均衡算法

候选站点依次经过：

1. 渠道状态允许接收新文章。
2. profile 服务状态允许发布。
3. 站点质量状态未被阻断。
4. 不在冷却期。
5. 当日发布量低于 `daily_publish_limit`。
6. 距上次发布超过 `min_publish_interval_minutes`。
7. 站点主题与文章任务或关键词匹配。

排序建议：

1. 当日用量与日限额的比值升序。
2. `last_published_at` 升序，空值优先。
3. `publish_weight` 降序。
4. profile ID 升序，保证结果稳定。

### 14.3 并发与占位

选站和占位在同一个数据库事务内执行：

1. 锁定当前文章的分配请求行。
2. 按稳定顺序锁定少量候选 profile 行。
3. 重新计算当前时区窗口内的已发布与已预留数量。
4. 复核冷却、间隔和状态。
5. 创建唯一文章归属记录，状态为 `reserved`。
6. 创建或关联 `article_distributions`，并更新分配请求。
7. 提交事务并派发队列任务。

唯一约束负责拦截同一文章的重复归属，行锁负责控制站点容量。队列任务继续使用既有幂等规则。

### 14.4 本地发布器

新增 `HostedSitePublisher` 并接入 `DistributionPublisherManager`：

- `create`：确认归属，写入内容指纹，更新为 `published`，生成托管 URL，失效页面与 Sitemap 缓存。
- `update`：复用原归属站点，刷新指纹和缓存，不重新分配。
- `delete`：把归属更新为 `withdrawn`，页面返回 404 或 410，具体由保留策略决定。
- `restore`：只恢复文章本身，不静默重新发布；管理员或任务需要显式触发重新分发。

发布失败时记录安全摘要，递增连续失败数。达到阈值后设置 `cooldown_until` 并告警，避免故障站点持续消耗队列。

### 14.5 容量不足与补偿

没有可用站点时，分配请求保持 `pending`，写入结构化错误码与下一次尝试时间。调度器每 5 分钟运行 reconciliation：

- 补建缺失的分发任务。
- 重试尚未占位的合格文章。
- 修复 `reserved` 超时记录。
- 对比归属表与分发表状态。
- 释放已归档站点的待发布占位。

补偿操作必须幂等，并设置单次扫描上限，避免大范围锁表。

没有候选站点时不创建空渠道的 `article_distributions` 或归属记录。系统保留 `hosted_site_allocation_requests` 并写入带文章 ID、任务 ID、原因和下次检查时间的 `distribution_logs` 事件。后台直接查询分配请求展示待分配队列，reconciliation 按 `status + next_attempt_at` 索引恢复处理。

## 15. 站点生命周期

### 15.1 状态维度

站点采用四个相互独立的状态维度：

| 维度 | 用途 |
| --- | --- |
| 渠道状态 | 是否接收新文章 |
| `serving_status` | 域名当前如何响应请求 |
| `indexing_status` | 是否允许搜索引擎索引 |
| `quality_status` | 是否通过运营与技术质量检查 |

### 15.2 标准流程

```text
创建
  -> 渠道 paused
  -> serving maintenance
  -> indexing noindex
  -> quality pending

预检通过
  -> serving online
  -> 渠道 active
  -> indexing 仍为 noindex

质量门禁通过并人工确认
  -> quality passed
  -> indexing index

停止接收新内容
  -> 渠道 paused
  -> 已发布页面继续在线

维护
  -> serving maintenance
  -> 返回 503 和 Retry-After
  -> 强制 noindex

归档
  -> serving archived
  -> 返回 410
  -> 强制 noindex
  -> 从任务站点池移除
```

硬删除只允许在归档后执行。后台先展示文章、任务、分发记录、日志和线索影响范围，再要求输入完整 hostname 和管理员密码确认。生产环境执行前必须完成数据库备份。

## 16. SEO 与质量门禁

### 16.1 默认策略

- 新站点默认 `noindex`。
- `noindex` 同时通过响应头和页面 meta 输出。
- `noindex` 站点的 Sitemap 返回空索引或仅返回站点说明，不提交文章 URL。
- `robots.txt` 根据当前站点动态生成，不能作为唯一索引控制手段。
- 每篇托管文章使用当前站点 URL 作为 self-canonical。
- `distribution_only` 任务避免同一正文同时出现在主站与托管站点。

### 16.2 开放索引条件

默认质量门禁全部通过后，管理员才能把 `indexing_status` 改为 `index`：

1. DNS 和 HTTPS 正常。
2. 站点名称、描述、主题、关于页和必要联系信息完整。
3. 至少有 `min_articles_before_index` 篇已发布且互不完全重复的文章，默认 10 篇。
4. 文章均通过既有风险与审核流程。
5. 内容指纹没有重复冲突。
6. canonical、robots、Sitemap 和结构化数据检查通过。
7. 最近观测窗口没有持续 5xx。
8. 运营人员完成内容价值、来源、版权与合规确认。

系统可以自动判断技术条件，内容价值和合规条件由有权限的运营人员确认并留下审计记录。

### 16.3 Sitemap

- 每个站点生成独立 Sitemap。
- 使用 Sitemap 索引管理分片。
- 单个分片最多 50,000 个 URL，并控制未压缩文件不超过 50MB。
- 所有 URL 为当前站点的绝对 HTTPS 地址。
- 发布、更新、下架、索引状态变化后失效缓存。

## 17. 批量建站

### 17.1 输入协议

CSV 限制：

- 单文件最大 5MB。
- 最多 10,000 行。
- 使用流式解析。
- 后台任务每批处理 100 行。
- 文件编码统一为 UTF-8，可接受带 BOM 输入并在解析时规范化。

必填列：

```text
subdomain,site_name,topic,task_ids
```

可选列与默认值：

| 列 | 默认值 |
| --- | --- |
| `description` | 空，预检会提示资料不完整 |
| `keywords` | 空 |
| `locale` | 主站公开语言 |
| `timezone` | 应用默认时区 |
| `theme_id` | 当前认证的默认托管主题 |
| `daily_publish_limit` | 3 |
| `min_publish_interval_minutes` | 360 |
| `min_articles_before_index` | 10 |
| `lead_form_slugs` | 空 |

### 17.2 两步提交

预览阶段只做读取和验证：

- 字段规范化。
- 子域格式、保留词和完整 hostname 生成。
- 文件内重复与数据库重复检查。
- 根域、主题、任务、表单和权限检查。
- DNS 与 TLS 可用性预检。
- 显示将创建、跳过和失败的每一行。

提交阶段要求：

- 超级管理员权限。
- 预览批次仍有效。
- 上传内容哈希与预览哈希完全一致。
- 同一批次只允许一次有效提交。
- 单行失败不会回滚其他已成功行。
- 所有新站以 `paused`、`maintenance`、`noindex`、`pending` 创建。

结果报告对 CSV 公式前缀进行转义，避免下载后在电子表格中触发公式注入。

### 17.3 批量生成边界

技术团队可以使用范围生成器创建内部测试站点。正式业务站点采用逐行站点规划表，确保每个站点具备明确主题、任务来源和运营责任人。

后续可以增加站点规划助手，帮助生成候选名称、主题和描述。生成结果仍然进入预览，不会直接创建或开放索引。

## 18. 后台产品设计

### 18.1 渠道列表

现有分发渠道列表增加：

- 托管站点类型筛选。
- Host、服务状态、索引状态、质量状态。
- 今日发布量与日限额。
- 最近发布时间和连续失败数。
- 快捷操作：预检、上线、暂停分发、维护、开放索引、归档。

### 18.2 创建与编辑

选择“托管渠道站点”后显示：

- 根域和子域输入，实时预览完整 URL。
- 站点名称、主题定位、描述、关键词、语言和时区。
- 主题与首页设置。
- 关于页与表单白名单。
- 日限额、最小发布间隔、权重和最低索引文章数。
- 任务绑定。
- 域名、证书和主题兼容预检结果。

外部渠道密钥、请求头、远端 API 和 Agent 安装包区域不显示。

### 18.3 详情页

详情页分为：

- 概览：状态、URL、今日容量、错误和最近发布。
- 内容：当前站点文章、待发布、失败和已下架。
- 任务：绑定任务与站点池分配情况。
- 外观：主题、首页和站点资料。
- SEO：质量门禁、canonical、robots 和 Sitemap 检查。
- 线索与访问：站点级趋势和导出。
- 操作记录：创建、配置、上线、索引、暂停和归档审计。

### 18.4 批量管理

第二阶段增加批量中心：

- 下载 CSV 模板。
- 上传并预览。
- 按错误类型筛选行。
- 提交有效行。
- 查看实时进度。
- 下载结果与失败明细。
- 对已创建站点执行批量预检和分阶段上线。

### 18.5 权限与审计

- 列表、详情和基础内容运营沿用现有后台登录保护。
- 批量提交、开放索引、归档和硬删除使用现有 `EnsureSuperAdmin` 中间件。
- 激活、维护、索引、归档、批量提交和删除均写入现有管理员活动日志。
- 审计内容包含管理员、站点、动作、变更前后状态、批次或文章标识、时间和请求来源。
- 批量导入的原始字段采用白名单保存，未知列不进入可执行配置。

## 19. 接口、路由、命令与配置契约

### 19.1 后台路由建议

沿用现有后台前缀，新增：

```text
GET    /admin/distribution/hosted-sites
GET    /admin/distribution/hosted-sites/create
POST   /admin/distribution/hosted-sites
GET    /admin/distribution/hosted-sites/{channel}
GET    /admin/distribution/hosted-sites/{channel}/edit
PUT    /admin/distribution/hosted-sites/{channel}
POST   /admin/distribution/hosted-sites/{channel}/preflight
POST   /admin/distribution/hosted-sites/{channel}/activate
POST   /admin/distribution/hosted-sites/{channel}/pause
POST   /admin/distribution/hosted-sites/{channel}/maintenance
POST   /admin/distribution/hosted-sites/{channel}/indexing
POST   /admin/distribution/hosted-sites/{channel}/archive
POST   /admin/distribution/hosted-sites/{channel}/assign-article

GET    /admin/distribution/hosted-site-batches
GET    /admin/distribution/hosted-site-batches/create
POST   /admin/distribution/hosted-site-batches/preview
POST   /admin/distribution/hosted-site-batches/{batch}/commit
GET    /admin/distribution/hosted-site-batches/{batch}
GET    /admin/distribution/hosted-site-batches/{batch}/report
```

状态变更通过专用动作路由执行，避免通用编辑接口绕过预检和审计。

### 19.2 命令建议

```text
php artisan hosted-sites:preflight {hostname?} --all
php artisan hosted-sites:reconcile --limit=500
php artisan hosted-sites:rollup-metrics --date=
php artisan hosted-sites:invalidate-cache {hostname?} --all
```

命令支持只读预览选项。调度器建议每 5 分钟执行 reconcile，每小时执行指标聚合，预检健康扫描可按 15 分钟或 1 小时运行。

### 19.3 配置建议

```env
GEOFLOW_HOSTED_SITES_ENABLED=false
GEOFLOW_HOSTED_SITE_ROOT_DOMAINS=sites.example.com
GEOFLOW_PRIMARY_HOSTS=example.com,www.example.com
```

在 `config/geoflow.php` 增加结构化配置：

- `hosted_sites.enabled`
- `hosted_sites.root_domains`
- `hosted_sites.primary_hosts`
- `hosted_sites.reserved_labels`
- `hosted_sites.resolver_positive_ttl`
- `hosted_sites.resolver_negative_ttl`
- `hosted_sites.default_daily_publish_limit`
- `hosted_sites.default_min_publish_interval_minutes`
- `hosted_sites.default_min_articles_before_index`
- `hosted_sites.failure_cooldown_threshold`
- `hosted_sites.failure_cooldown_minutes`

主站 Host 从 `APP_URL`、`SITE_URL` 和 `GEOFLOW_PRIMARY_HOSTS` 构建精确白名单。生产环境发现空值、通配主站 Host 或根域冲突时，启动检查应失败并给出清晰错误。

## 20. 服务与代码落点

建议新增或调整的主要职责如下：

| 组件 | 职责 |
| --- | --- |
| `CurrentSite` | 保存请求级主站或托管站点上下文 |
| `HostedSiteResolver` | Host 规范化、白名单判断、精确解析与缓存 |
| `SiteSettingsResolver` | 合并主站默认设置与站点覆盖设置 |
| `SiteScopedArticleQuery` | 为全部公开文章查询施加站点范围 |
| `SiteUrlGenerator` | 在请求、队列和命令中生成站点绝对 URL |
| `HostedSiteAllocationRequestService` | 建立、取消和恢复持久分配请求 |
| `HostedSiteAssignmentService` | 手动指派、唯一归属、更新和下架 |
| `HostedSiteAllocator` | 容量筛选、事务选站与占位 |
| `HostedSitePublisher` | 本地发布、结果 URL 和缓存失效 |
| `HostedSiteQualityService` | 技术预检、质量门禁和索引资格 |
| `HostedSiteLifecycleService` | 上线、暂停、维护、索引、归档和删除影响分析 |
| `HostedSiteBatchService` | 批量解析、预览、幂等提交和结果报告 |
| `HostedSiteMetricsService` | 日指标聚合和运营查询 |

预计会涉及 40 至 60 个文件，覆盖迁移、模型、服务、中间件、控制器、请求校验、Blade 页面、语言包、测试、Nginx 示例和部署文档。开发过程无需增加 Composer 或 npm 依赖，也无需引入新的外部运行时服务。

### 20.1 核心文件地图

| 区域 | 计划文件或目录 |
| --- | --- |
| 迁移 | `database/migrations/*_create_hosted_site_profiles_table.php`、`*_create_hosted_site_article_assignments_table.php`、`*_create_hosted_site_allocation_requests_table.php`、`*_add_hosted_site_profile_id_to_view_logs_and_lead_submissions.php` |
| 第二阶段迁移 | `database/migrations/*_create_hosted_site_batches_table.php`、`*_create_hosted_site_batch_items_table.php` |
| 模型 | `app/Models/HostedSiteProfile.php`、`HostedSiteArticleAssignment.php`、`HostedSiteAllocationRequest.php`、`HostedSiteBatch.php`、`HostedSiteBatchItem.php` |
| 站点上下文 | `app/Support/Site/CurrentSite.php`、`HostedSiteResolver.php`、`SiteSettingsResolver.php`、`SiteScopedArticleQuery.php`、`SiteUrlGenerator.php` |
| 中间件 | `app/Http/Middleware/NormalizeRequestHost.php`、`ResolveCurrentSite.php`、`RequirePrimaryHost.php`、`RequireHostedPublicRoute.php` |
| 分发服务 | `app/Services/GeoFlow/HostedSiteAllocationRequestService.php`、`HostedSiteAllocator.php`、`HostedSiteAssignmentService.php`、`HostedSitePublisher.php`、`HostedSiteLifecycleService.php`、`HostedSiteQualityService.php` |
| 批量与指标 | `app/Services/GeoFlow/HostedSiteBatchService.php`、`HostedSiteMetricsService.php`、对应队列 Job 与 Console Command |
| 现有分发入口 | `app/Services/GeoFlow/DistributionOrchestrator.php`、`TaskDistributionChannelSelector.php`、`DistributionPublisherManager.php`、`app/Models/DistributionChannel.php` |
| 公开页面 | `app/Http/Controllers/Site/*`、`app/View/Composers/SiteLayoutComposer.php`、`app/Support/Site/SiteThemeViewResolver.php`、`resources/views/site/*` |
| 后台 | `app/Http/Controllers/Admin/DistributionController.php`、新增托管站点与批次控制器、`resources/views/admin/distribution/*`、`lang/*/admin.php` |
| 入口与配置 | `routes/web.php`、`routes/console.php`、`bootstrap/app.php`、`config/geoflow.php`、`config/session.php`、`docker/nginx/default.conf`、`.env.prod.example` |
| 测试 | `tests/Unit/HostedSite*Test.php`、`tests/Feature/HostedSite*Test.php`、现有分发与公开站点回归测试 |
| 文档 | `docs/distribution/` 与部署说明 |

迁移文件的时间戳在实施时根据仓库最新迁移顺序生成。第一版只提供后台 HTML 管理流程，不新增公开建站 API。

## 21. 分阶段实施计划

### 阶段一：单站点完整闭环

目标：一个托管站点可以安全创建、绑定任务、发布内容、独立访问和回滚。

交付项：

- profile 与文章归属数据表。
- 持久分配请求与补偿入口。
- `hosted_site` 渠道类型和本地发布器。
- Host 安全边界、请求级站点上下文和未知域名 404。
- 主站与托管站点的 Nginx 示例配置。
- 公开页面、主题、URL、SEO、表单和日志隔离。
- 单站点后台创建、编辑、预检、上线、暂停、维护、索引和归档。
- 每个任务最多绑定一个托管站点。
- 手动文章指派。
- 单站点质量门禁、robots 和 Sitemap。
- 单元、功能、安全与回归测试。

预计工作量：10 至 14 个工程日。

独立验收结果：即使后续阶段延期，系统仍可稳定运营少量人工配置的托管站点。

### 阶段二：批量生成与自动站点池

目标：经过审核的站点规划可以批量创建，任务文章可以在多个站点间自动分配。

交付项：

- 批次与批次明细表。
- CSV 模板、预览、哈希校验、提交、进度和报告。
- 容量均衡、事务占位、冷却和失败隔离。
- 多托管站点任务池。
- reconciliation 调度。
- 并发、幂等、容量和批量恢复测试。

预计工作量：8 至 12 个工程日。

独立验收结果：运营团队可以创建和维护百级站点，自动分配遵守唯一归属与容量规则。

### 阶段三：规模化运营

目标：提升千级站点的可观测性、故障发现和批量治理效率。

交付项：

- 站点日指标聚合。
- DNS、TLS、5xx、canonical、robots 和 Sitemap 定时健康扫描。
- 批量状态与容量仪表盘。
- 10、100、1000 站点分级放量工具和检查清单。
- 批量归档、缓存治理和日志保留策略。
- 可选站点规划助手与抽样式 AI 可见性分析。

预计工作量：6 至 9 个工程日。

独立验收结果：系统具备千级站点的日常巡检、容量管理和故障处置能力。

## 22. 测试与验收

### 22.1 单元测试

- Host 标准化、根域判断和保留词。
- profile 状态转换。
- 设置合并与缓存键。
- 容量计算、时区日窗口和间隔判断。
- 内容指纹规范化。
- URL 生成。
- 质量门禁结果。
- CSV 字段规范化与公式转义。

### 22.2 功能测试

- 主站公开页保持现有行为。
- 已存在托管 Host 返回对应站点。
- 未知、非法、大小写变体、尾部点和带端口 Host 按规则处理。
- 托管域名无法访问后台、API、Horizon 和 Reverb。
- 两个站点的首页、分类、文章、推荐、关于页、表单和 Sitemap 互不串数据。
- `distribution_only` 文章只在归属托管站点出现。
- 更新沿用原归属，下架后页面不可见，恢复不会静默发布。
- noindex 与 index 状态输出正确。
- 线索和浏览日志写入正确 profile。
- 全局功能关闭后主站正常，托管域名返回 404。

### 22.3 并发与幂等测试

- 同一文章并发分配只产生一条归属。
- 相同文章重复触发只产生一个有效分配请求。
- 多文章并发选择不会突破站点日限额。
- 队列重试不会增加重复发布记录。
- 相同批次重复提交不会重复创建站点。
- reconciliation 重复运行结果稳定。

### 22.4 安全测试

- Host Header 注入。
- 伪造 `X-Forwarded-Host`。
- 跨 Host Cookie 与 CSRF。
- 缓存键缺少 Host 的污染场景。
- 托管域名访问受限路由。
- CSV 公式注入和超大文件。
- 批量字段尝试注入 HTML、脚本和统计代码。
- 错误日志与导出文件中的敏感信息检查。

### 22.5 主题与 SEO 测试

- 每个认证主题覆盖首页、分类、文章、关于页、表单、404 和 503。
- canonical、Open Graph、JSON-LD 和 Sitemap 使用当前 Host。
- noindex 站点不会输出文章 Sitemap。
- Sitemap 分片数量和绝对 URL 正确。
- 主题失败回退到安全视图。

### 22.6 仓库验证命令

```bash
composer test
./vendor/bin/pint
npm run build
php artisan route:list --json
```

部署环境还需执行 Host、TLS、代理、Cookie、WebSocket 和缓存的端到端验证。

## 23. 发布与迁移

### 23.1 发布前准备

1. 确认可控根域名和主站 Host 清单。
2. 配置泛 DNS 记录。
3. 配置通配符 TLS，推荐 CDN 托管或权限受限的 DNS-01 自动化。
4. 部署三类入口配置并验证默认 Host 拒绝。
5. 确认 CDN 缓存键包含 Host。
6. 确认 `SESSION_DOMAIN=null`。
7. 备份数据库。

### 23.2 数据库迁移

- 迁移采用新增表、新增可空外键和新增索引的方式。
- 初次部署不回填历史文章归属。
- 历史 `view_logs` 和 `lead_submissions` 保持站点外键为空。
- `hosted_site` 类型在功能开关关闭时不会被公开解析。
- 生产数据产生后保留新增表，回滚应用时避免执行破坏性 down migration。

### 23.3 灰度顺序

1. 以 `GEOFLOW_HOSTED_SITES_ENABLED=false` 部署代码与迁移。
2. 完成 DNS、TLS、代理和缓存预检。
3. 开启功能开关，创建 1 个 `maintenance + noindex` 站点。
4. 发布少量经过审核的文章。
5. 切换为 `online + noindex`，观察错误率、查询隔离和页面性能。
6. 质量门禁通过后单独确认是否开放索引。
7. 按 10、100、1000 站点逐级放量，每一级至少观察一个完整发布周期。

每一级满足成功指标后进入下一级。出现跨站泄漏、容量突破、未知 Host 回落或主站回归时立即停止扩容。

## 24. 回滚与故障处置

### 24.1 应用级回滚

1. 关闭 `GEOFLOW_HOSTED_SITES_ENABLED`。
2. 托管域名统一返回 404，主站保持服务。
3. 暂停托管站点分配和 reconciliation 调度。
4. 保留 profile、归属、分发、日志和线索数据。
5. 修复后从灰度站点重新开启。

### 24.2 入口级回滚

- 移除或暂停托管泛域名入口。
- 保留主站精确域名入口和 Reverb 配置。
- DNS 需要回退时先降低 TTL，并记录缓存传播窗口。

### 24.3 单站故障

- 发布故障：暂停渠道，已发布页面可继续在线。
- 页面故障：切换 maintenance，返回 503 与 `Retry-After`。
- 内容风险：强制 noindex 并下架相关归属。
- 长期停用：归档并返回 410。
- 数据清理：完成备份、影响预览和双重确认后执行硬删除。

## 25. 外部依赖与运营前置条件

### 25.1 技术依赖

- 现有 PostgreSQL。
- 现有 Redis 与 Horizon。
- Laravel 应用和队列 Worker。
- 支持 Host 分流的 Nginx、Ingress 或 CDN。
- 泛 DNS 控制权。
- 通配符 TLS 或等效证书托管能力。

应用无需持有 DNS 主账号密钥，也无需新增独立多租户服务。

### 25.2 运营确认清单

每个根域名和站点组上线前，由运营负责人确认：

- 域名、备案和当地网络服务要求。
- 隐私政策、Cookie、线索收集告知与用户同意。
- 内容来源、版权、引用和删除机制。
- 联系方式、投诉和退出入口。
- 站点主题与内容之间具备持续关联。
- 批量内容具备独立价值，且不会形成门页或低价值规模化页面。

系统保存确认人、确认时间和适用站点范围，便于后续审计。

## 26. 风险与应对

| 风险 | 发生方式 | 应对 |
| --- | --- | --- |
| Host 路由泄漏 | 未知域名进入主站或管理入口 | 默认入口拒绝、精确解析、路由白名单、端到端测试 |
| 跨站内容泄漏 | 页面某个查询遗漏站点范围 | 统一查询服务、双站差异测试、代码审查清单 |
| 并发超限 | 多个 Worker 同时选中同一站点 | 事务锁、容量复核、唯一索引和压力测试 |
| 搜索质量下降 | 大量空站、重复或低价值页面开放索引 | 默认 noindex、最低内容量、质量门禁、逐站人工确认 |
| 缓存串站 | CDN 或应用缓存未包含 Host | 缓存键规范、自动化回归和失效服务 |
| 证书或 DNS 故障 | 泛域配置、代理或验证异常 | 创建预检、定时健康扫描、maintenance 状态 |
| 主题不兼容 | 主题读取全局设置或生成主站链接 | 兼容声明、认证测试、安全回退主题 |
| 批量误操作 | 错误文件一次创建大量站点 | 只读预览、文件哈希、权限、状态安全默认值和报告 |
| 日志增长 | 站点量和访问量带来查询压力 | 分区或保留策略、日指标聚合、按站点索引 |
| 运维复杂度增长 | 大量站点产生状态和故障组合 | 独立状态维度、批量中心、健康扫描和分级放量 |

## 27. 可选方案与取舍

### 27.1 推荐方案：共享应用内的轻量站点上下文

适用于共享部署、数据库、内容源、媒体和中央后台。它对现有 GEOFlow 分发体系改动集中，单站闭环可以较快上线，后续也能扩展到批量和容量调度。

### 27.2 完整 Laravel 多租户框架

可采用 stancl Tenancy 等方案，获得数据库、缓存、文件系统和队列的租户隔离。当前需求未要求独立数据域与独立后台，引入后会增加模型迁移、上下文切换、测试矩阵和运维成本。当项目出现独立客户租户需求时，应重新评估这条路线。

### 27.3 每个站点部署一个 GEOFlow Agent

适用于法律边界、数据驻留、独立发布、独立扩缩容和故障域要求较高的站点。基础设施与升级成本随站点数量增长，更适合少量高价值独立站点。

### 27.4 最小可行版本

可以只实现 Host 解析、站点设置和手动文章归属，预计 5 至 7 个工程日。该版本适合验证二级域名渲染，缺少批量创建、自动容量分配、完整质量门禁和规模化运营能力。推荐方案保留阶段一的完整安全闭环，降低上线后返工。

## 28. 前提失效条件

本方案依赖以下核心前提：所有托管站点共享同一部署、数据库、内容源、媒体资源和中央管理后台。

出现任一条件时，应暂停沿用本架构并重新选择完整多租户或独立 Agent 方案：

- 客户或法规要求数据库级隔离。
- 不同站点需要独立数据驻留区域。
- 站点需要独立发布版本或独立维护窗口。
- 单个站点故障必须与其他站点形成基础设施级隔离。
- 站点运营方需要独立管理员、权限边界和计费体系。
- 自定义顶级域名成为主要接入方式，并需要复杂的域名所有权验证。

## 29. 开发确认边界

用户确认本方案后，开发按以下顺序开始：

1. 把阶段一拆成可审查的迁移、Host 边界、站点上下文、页面隔离、发布链路、后台和部署小任务。
2. 先编写关键隔离、唯一归属和并发容量测试，再实现对应代码。
3. 每个小任务完成后执行相关测试，阶段完成后执行仓库全量验证。
4. 阶段一通过验收后，再开始阶段二。

确认前不修改功能代码、数据库迁移、部署配置或运行环境。

## 30. 最终建议

建议批准本方案，并以阶段一作为首个开发里程碑。阶段一已经包含安全上线所需的完整纵向闭环，能够用一个真实二级站点验证域名、内容、主题、SEO、分发、表单、统计和回滚。验证稳定后再进入批量生成和自动站点池，可以把规模化风险控制在可观测范围内。
