# GEOFlow 分发管理统一实施方案

> **给 Agent 执行者：** 必须使用 `superpowers:subagent-driven-development`（推荐）或 `superpowers:executing-plans` 按任务逐步执行。本文中的步骤使用复选框（`- [ ]`）语法，便于执行时跟踪进度。

**目标：** 将 GEOFlow 的内容分发能力建设为 Agent 优先、异步执行、带签名校验的内容同步层，并确保远端分发失败不会阻塞或回滚本地文章发布。

**架构：** GEOFlow 继续作为文章生成、审核和本地发布的事实源。当本地文章进入 `published` 状态后，GEOFlow 创建 `article_distributions` 记录，并把任务投递到专用的 `distribution` 队列；队列任务向目标站点 Agent 发送 HMAC 签名 HTTP 请求，并记录远端 ID、URL、状态和日志。WordPress、Ghost、微信公众号、Telegram 和社交平台等第三方 Provider 是建立在该基础能力之上的后续适配器，不作为第一版闭环的阻塞项。

**技术栈：** Laravel 12、PHP 8.2+、Blade 管理后台、Tailwind CSS、lucide 图标、Eloquent 模型、Laravel 队列、Laravel HTTP Client、PHPUnit、Laravel Pint。

---

## 1. 来源与统一口径

本文合并以下两份草案：

- `docs/distribution/multi-platform-distribution-plan.md`
- `docs/distribution/distribution-management-agent-plan.md`

统一结论：

- 第一版采用 Agent 优先路线，不直接把 WordPress、Ghost、微信公众号、社交平台等 Provider 做进首个闭环。
- `multi-platform-distribution-plan.md` 保留为长期 Provider 路线参考。
- `distribution-management-agent-plan.md` 的 Agent 架构成为当前实施基线，但本文补齐后台功能逻辑、UI 设计约束、代码调用链和数据库逻辑。

## 2. 产品边界与设计原则

### 2.1 第一版产品边界

第一版只解决“本地已发布文章如何可靠同步到目标站点 Agent”的问题。

纳入第一版：

- 分发渠道管理。
- 渠道 key 和 secret 管理。
- 渠道健康检查。
- 任务绑定分发渠道。
- 本地文章发布后自动入队。
- 队列任务发送签名请求。
- 分发状态、远端 URL、错误信息和日志记录。
- 管理后台查看渠道、队列和日志。

不纳入第一版：

- 微信公众号、WordPress、Ghost 等直接平台 Provider。
- OAuth 授权流程。
- 多平台内容格式自动改写。
- 远端物理删除。
- 本地文章状态与远端状态强一致。
- 大规模重试策略、死信队列和告警平台集成。

### 2.2 不阻塞本地发布

分发管理不能改变 GEOFlow 当前文章发布主流程。文章一旦被本地工作流判定为 `published`，本地文章状态就以本地发布为准。远端分发失败只影响 `article_distributions.status` 和分发日志，不回滚以下字段：

- `articles.status`
- `articles.review_status`
- `articles.published_at`

这样可以保证分发能力是附加能力，而不是发布链路里的硬依赖。

### 2.3 Agent 优先

目标站点只需要实现一组稳定 Agent 接口：

- `GET /geoflow-agent/v1/health`
- `POST /geoflow-agent/v1/articles`

GEOFlow 只关心 Agent 协议，不直接关心目标站点底层是 WordPress、静态站、Laravel、Next.js 还是其他系统。后续 Provider 也应该优先封装成目标站 Agent 或独立适配器，而不是把每个平台的复杂 API 直接塞进首版核心流程。

### 2.4 安全边界

分发渠道使用独立 key 和 secret。不得复用后台登录态、Admin API Token 或用户 Cookie 写入目标站点。

安全规则：

- secret 只在创建或重置后展示一次。
- secret 存库必须加密。
- 日志不得保存 secret 明文、Authorization 请求头、Cookie 或完整敏感响应。
- 每次请求必须带 timestamp、nonce、body hash、signature 和 idempotency key。
- Agent 必须拒绝过期 timestamp、重复 nonce、未知 key、禁用 key 和签名不匹配请求。

## 3. 用户角色与核心对象

### 3.1 用户角色

| 角色 | 主要动作 | 关注点 |
|---|---|---|
| 后台管理员 | 创建渠道、复制 secret、健康检查、查看队列和日志 | 渠道是否可用、分发是否成功、错误原因 |
| 内容运营人员 | 在任务中选择分发渠道、发布文章、查看分发状态 | 文章发布后是否同步到目标站点 |
| Worker 进程 | 生成草稿、发布到期草稿、触发分发入队 | 不阻塞生成和本地发布 |
| 目标站 Agent | 验签、接收文章、返回远端 ID 和 URL | 幂等、安全、可诊断 |

### 3.2 核心对象

| 对象 | 数据表 | 说明 |
|---|---|---|
| 分发渠道 | `distribution_channels` | 一个目标站点或 Agent 端点 |
| 渠道密钥 | `distribution_channel_secrets` | 渠道可用 key 与加密 secret |
| 任务渠道绑定 | `task_distribution_channels` | 任务生成的文章需要同步到哪些渠道 |
| 文章分发记录 | `article_distributions` | 一篇文章对一个渠道的一次分发动作 |
| 分发日志 | `distribution_logs` | 入队、发送、成功、失败和健康检查相关日志 |

## 4. 功能逻辑总览

### 4.1 完整业务闭环

1. 管理员进入“分发管理”。
2. 管理员创建目标站点渠道，填写名称、域名、Agent endpoint、状态和描述。
3. 系统生成 `key_id` 和 secret，secret 加密保存，明文只通过 session 在创建成功后展示一次。
4. 管理员把 `key_id` 和 secret 配置到目标站点 Agent。
5. 管理员在渠道详情页发起健康检查，确认 Agent 可达且验签正常。
6. 内容运营人员在任务创建或编辑页勾选活跃分发渠道。
7. 任务生成文章后，文章仍先进入 GEOFlow 本地审核和发布流程。
8. 当文章本地状态变为 `published` 后，系统为文章和渠道创建或更新 `article_distributions` 记录。
9. 系统向 `distribution` 队列投递 `ProcessArticleDistributionJob`。
10. 队列任务构造 payload，生成 HMAC 请求头，向目标站 Agent 发送文章。
11. 目标站 Agent 返回 `remote_id`、`remote_url` 和远端状态。
12. GEOFlow 将分发记录更新为 `synced`，保存远端信息，并写入日志。
13. 如果请求失败，分发记录更新为 `failed`，保存错误信息和日志，本地文章保持已发布。

### 4.2 渠道生命周期

渠道状态第一版只需要两个值：

- `active`：可被任务选择，可参与分发。
- `paused`：保留配置但不参与新任务选择和新分发入队。

渠道创建逻辑：

- `domain` 用于后台展示和识别，入库前应规范化为 host。
- `endpoint_url` 用于请求目标站 Agent，后台表单允许输入裸域名或完整 URL；未填写协议时默认补 `https://`，入库前去掉末尾 `/`。
- `channel_type` 第一版固定为 `geoflow_agent`。
- `template_key` 是可选字段，预留给目标站主题、栏目或模板映射。
- `site_settings` 保存当前渠道的目标站点设置，包括网站名称、副标题、描述、关键词、版权、Logo、Favicon、SEO 模板、列表数量等；它只影响目标渠道站点，不修改 GEOFlow 本站的网站设置。
- `created_by_admin_id` 记录创建人，便于后续审计。

密钥创建逻辑：

- `key_id` 格式为 `gfk_` 前缀加随机字符串。
- secret 格式为 `gfsec_` 前缀加随机字符串。
- secret 使用 `ApiKeyCrypto` 加密后写入 `secret_ciphertext`。
- 默认 scopes 为 `article.publish`、`article.delete`、`health.check`。
- 默认情况下详情页只展示 `key_id` 和 `last_used_at`，不直接展示 secret 明文。
- 如果超级管理员忘记密钥，可在渠道详情页输入当前管理员密码，系统从加密密文临时解密并通过 session 再展示一次；刷新后仍隐藏。
- 非超级管理员不能重新显示密钥，操作请求必须使用 POST，且不得把密码或 secret 明文写入日志。

后续可补充的渠道动作：

- 编辑渠道基本信息。
- 暂停或启用渠道。
- 重置 secret，旧 secret 置为 `revoked`。
- 查看 secret 使用记录和密钥重显审计记录。
- 删除渠道前检查是否存在历史分发记录。

### 4.3 任务绑定逻辑

任务是分发选择的入口。运营人员不在单篇文章里逐次选择渠道，而是在任务配置中声明“这个任务生成并发布的文章应该同步到哪些目标站点”。

绑定规则：

- 任务创建和编辑页只展示 `status = active` 的分发渠道。
- 没有活跃渠道时，页面展示空状态和“创建分发渠道”入口。
- 勾选渠道后，提交表单写入 `task_distribution_channels`。
- 编辑任务时，需要回填已选择渠道。
- 如果任务没有绑定任何渠道，文章发布后不创建分发记录。
- 如果渠道后来被暂停，已存在绑定可以保留，但新入队时只选择活跃渠道。

当前方法：

- `TaskController::loadTaskFormOptions()` 加载活跃渠道。
- `TaskController::selectedDistributionChannelIds()` 清洗提交 ID。
- `TaskController::taskDistributionChannelIds()` 回填编辑页。
- `DistributionOrchestrator::syncTaskChannels()` 写入 pivot，并设置默认触发策略。

### 4.4 发布触发逻辑

分发触发点必须落在“本地文章已发布”之后。

当前触发来源：

- `ArticleController::store()`：手动创建文章时，如果规范化后状态为 `published`，触发入队。
- `ArticleController::update()`：手动编辑文章时，如果规范化后状态为 `published`，触发入队。
- `ArticleController::handleBatchUpdateStatus()`：批量更新发布状态后，如果文章变为 `published`，触发入队。
- `ArticleController::handleBatchUpdateReview()`：批量审核后，如果文章根据审核规则变为 `published`，触发入队。
- `WorkerExecutionService::executeTask()`：Worker 发布到期草稿后，触发入队。

不触发的情况：

- 文章仍为 `draft`。
- 文章为 `private`。
- 文章没有 `task_id`。
- 任务没有绑定活跃分发渠道。
- 只生成草稿但未发布。

幂等规则：

- `article_distributions` 使用 `article_id + distribution_channel_id + action` 唯一约束。
- `DistributionOrchestrator::enqueueForArticle()` 使用 `updateOrCreate()`。
- 同一文章、同一渠道、同一动作重复触发时，更新现有记录并重新入队，而不是插入重复记录。
- `idempotency_key` 使用 `article-{articleId}-channel-{channelId}-{action}-v1`。

### 4.5 分发执行逻辑

分发记录状态机：

```text
queued -> sending -> synced
queued -> sending -> failed
failed -> queued -> sending -> synced
```

第一版执行规则：

- 入队时状态为 `queued`。
- 队列任务开始处理时改为 `sending`，`attempt_count + 1`，记录 `last_attempt_at`。
- HTTP 请求成功后改为 `synced`，写入 `remote_id` 和 `remote_url`。
- HTTP 请求失败或缺少渠道、文章、密钥时改为 `failed`，记录 `last_error_message`。
- 第一版 `ProcessArticleDistributionJob::$tries = 1`，避免 Laravel 自动重试造成不可控重复请求。

后续增强：

- 增加应用级 `DistributionRetryPolicy`，区分可重试和不可重试错误。
- 增加手动重试按钮。
- 增加 `next_retry_at` 延迟重试。
- 增加失败过滤、错误分类和批量重试。

### 4.6 可观测性逻辑

后台需要让管理员快速回答四个问题：

- 有多少渠道。
- 哪些渠道可用。
- 当前有多少分发在排队或发送中。
- 哪些分发失败，失败原因是什么。

当前可观测性能力：

- 分发首页统计总渠道数、活跃渠道数、待处理分发数、失败分发数。
- 渠道列表显示每个渠道的 pending 和 failed 数量。
- 渠道详情页显示该渠道最近 20 条分发记录和最近 20 条日志。
- 全局任务页显示所有分发记录，支持分页。
- 文章列表显示单篇文章分发摘要：已分发、分发中、分发失败。
- 任务列表显示任务相关文章的分发摘要，失败任务可从任务页被快速发现。
- 日志记录 `level`、`event`、`message`、`context` 和时间。

后续可补充：

- 分发任务筛选：渠道、状态、文章标题、时间范围。
- 任务列表显示绑定渠道数量和最近失败渠道明细。
- 失败记录支持手动重试。

## 5. 后台 UI 设计

### 5.1 UI 基本原则

分发管理必须沿用当前后台 UI 规范，不引入新的视觉体系。

必须沿用：

- 布局继承 `admin.layouts.app`。
- 页面容器使用 `px-4 sm:px-0`，复杂页面使用 `space-y-8`。
- 标题使用 `text-2xl font-bold text-gray-900`。
- 辅助说明使用 `text-sm text-gray-600`。
- 内容区使用 `rounded-lg bg-white shadow` 卡片。
- 卡片标题区使用 `border-b border-gray-200 px-6 py-4`。
- 表单使用 `label + input/select/textarea + help text` 结构。
- 表格使用 `min-w-full divide-y divide-gray-200`、`thead bg-gray-50`、`px-6 py-3` 表头。
- 主按钮使用 `bg-blue-600 hover:bg-blue-700 text-white`。
- 次按钮使用 `border border-gray-300 bg-white text-gray-700 hover:bg-gray-50`。
- 图标使用 lucide，即 `<i data-lucide="...">`。
- 所有后台文案走 `lang/zh_CN/admin.php` 和 `lang/en/admin.php`。
- 所有 URL 使用 `route()`，不得硬编码 `/geo_admin`，因为后台路径来自 `config('geoflow.admin_base_path')`。

避免：

- 不要引入新的前端框架或新的 JS 依赖。
- 不要在后台页面使用与当前系统不一致的大面积渐变、营销式 hero 或装饰性背景。
- 不要把页面区块做成卡片套卡片。
- 不要把 secret、签名、请求头等敏感信息直接展示在常规日志中。
- 不要在 Blade 中硬编码中文业务文案，已有临时中文日志除外，新增 UI 文案必须进入语言文件。

### 5.2 导航设计

分发管理作为一级导航，与“任务管理”“文章管理”同级。

当前导航入口：

- 菜单 key：`distribution`
- 路由：`admin.distribution.index`
- 文案：`admin.nav.distribution`
- 激活状态：`activeMenu = distribution`

子路由映射到同一个一级导航：

- `admin.distribution.index`
- `admin.distribution.create`
- `admin.distribution.store`
- `admin.distribution.show`
- `admin.distribution.jobs`
- `admin.distribution.health`
- 后续新增 `admin.distribution.retry`、`admin.distribution.update`、`admin.distribution.rotate-secret` 时，也应映射为 `distribution`。

### 5.3 页面结构总览

| 页面 | 路由 | 当前状态 | 核心能力 |
|---|---|---|---|
| 分发首页 | `admin.distribution.index` | 已实现 | 统计、渠道列表、创建入口、任务入口、最近日志、一次性 secret 提示 |
| 创建渠道 | `admin.distribution.create` / `store` | 已实现 | 填写基本信息，生成 key 和 secret |
| 渠道详情 | `admin.distribution.show` | 已实现 | 查看渠道信息、key ID、健康检查、最近分发、最近日志 |
| 分发任务 | `admin.distribution.jobs` | 已实现 | 查看所有分发记录，分页，支持状态和渠道筛选 |
| 任务创建/编辑分发区 | `admin.tasks.create` / `edit` | 已实现 | 选择活跃分发渠道 |
| 文章列表状态摘要 | `admin.articles.index` | 已实现 | 展示每篇文章的分发状态 |
| 任务列表状态摘要 | `admin.tasks.index` | 已实现 | 展示任务相关文章的分发状态 |
| 手动重试 | `admin.distribution.retry` | 已实现 | 将失败分发重新入队 |
| 渠道编辑与暂停 | `admin.distribution.update` | 已实现 | 修改基本信息，切换 active/paused |

### 5.4 分发首页 UI

分发首页用于“总览 + 快速定位问题”。

页面结构：

- 顶部标题：`分发管理`。
- 顶部说明：说明这是外部站点 Agent 和文章分发队列管理入口。
- 右侧操作：
  - “分发任务”次按钮，图标 `list-checks`。
  - “新增渠道”主按钮，图标 `plus`。
- 一次性 secret 提示：
  - 仅在创建渠道后通过 session 展示。
  - 使用 amber 色提示框。
  - 分三列展示 `key_id`、`secret`、`endpoint_url`。
  - 文案必须明确“离开后不再展示 secret”。
- 统计卡片：
  - 总渠道数。
  - 活跃渠道数。
  - 待处理分发数。
  - 失败分发数。
- 渠道列表：
  - 渠道名称。
  - 域名。
  - 状态徽标。
  - 队列摘要。
  - 查看动作。
- 最近日志：
  - message。
  - 渠道名称。
  - level。
  - created_at。

空状态：

- 没有渠道时展示 `radio-tower` 图标。
- 提示用户先创建目标站点渠道。
- 保留页面顶部“新增渠道”主按钮，不需要在空状态里重复放强按钮。

### 5.5 创建渠道页 UI

创建渠道页用于完成第一步配置。

表单字段：

| 字段 | 类型 | 必填 | 校验 | 说明 |
|---|---|---|---|---|
| `name` | text | 是 | string, max:120 | 后台识别名称，如“官网主站” |
| `domain` | text | 是 | string, max:255 | 展示域名，保存前规范化为 host |
| `endpoint_url` | text | 是 | string, max:500，规范化后必须是 http/https URL | Agent 基础地址。可输入 `www.example.com` 或 `https://www.example.com`，未填写协议时默认补 `https://`，保存前去掉末尾 `/` |
| `template_key` | text | 否 | string, max:120 | 创建页可留空；编辑页通过模板单选卡片维护 |
| `status` | select | 是 | active/paused | 默认 active |
| `description` | textarea | 否 | string, max:1000 | 备注 |

按钮：

- 左侧或顶部使用返回箭头 `arrow-left`。
- 底部右侧：
  - 取消：次按钮，返回分发首页。
  - 保存并生成密钥：主按钮，图标 `key-round`。

交互约束：

- 表单提交失败时保留 `old()` 输入。
- 创建成功后跳回分发首页，使用 session 临时展示 secret。
- secret 提示文案必须说明：刷新后会隐藏，超级管理员可在渠道详情页输入当前密码再次临时显示。
- 不在创建页直接展示后续配置教程，避免挤压表单；配置说明放在渠道详情页的 Agent 接入引导和 Agent 示例文档中。

### 5.6 渠道详情页 UI

渠道详情页用于诊断单个渠道。

页面结构：

- 顶部返回箭头。
- 标题为渠道名称，副标题为域名。
- 右侧健康检查按钮，图标 `activity`。
- 基本信息卡片：
  - endpoint URL。
  - status。
  - template key。
  - health status。
- 密钥信息卡片：
  - active key ID。
  - last used at。
  - 默认不显示 secret 明文。
  - 超级管理员可输入当前管理员密码，验证通过后临时显示 active secret。
  - 非超级管理员显示权限提示，不展示密码输入框。
- 目标站点包卡片：
  - 展示该渠道可下载的 ZIP 站点包。
  - 站点包包含 `config.php`、根目录 `.htaccess`、`public/index.php`、`public/.htaccess`、`nginx.example.conf`、`storage/.htaccess` 和 `storage/articles`。
  - 功能标签展示：测试连接接口、文章接收接口、首页列表页、文章详情页。
  - 下载前必须由超级管理员输入当前管理员密码，因为包内会写入当前渠道的 `key_id` 和 secret。
  - 下载得到的目标站点包可以上传到目标服务器并解压。推荐 Web 根目录指向 `public`；如果只能把整个目录放到 Web 根目录下，根目录 `.htaccess` 必须阻止读取 `config.php` 和 `storage`，并把正常访问转发到 `public`。
  - 如果 Agent 基础地址带二级目录，例如 `https://example.com/geoflow-target-site`，站点包应在 `config.php` 中写入 `base_path`，目标站前端链接和签名校验都基于归一化后的路径处理。
- 目标站点设置区：
  - 复用网站设置中的站点信息字段：网站名称、副标题、描述、关键词、版权、Logo、Favicon、SEO 标题模板、SEO 描述模板、推荐数量和每页数量。
  - 复用网站设置中的前台模板发现能力，从 `resources/views/theme/*/manifest.json` 读取可选模板，在渠道编辑页以单选卡片展示。
  - 保存后写入当前渠道的 `site_settings` JSON 和 `template_key`，不会影响 GEOFlow 本站设置。
  - 渠道详情页提供“同步设置”操作，将这些设置通过签名接口发送到目标站 Agent。
- Agent 接入引导卡片：
  - 展示示例文件路径 `docs/distribution/agent-sample/php/geoflow-agent.php`。
  - 展示部署、配置密钥、测试连接、任务绑定四个步骤。
  - 明确目标站至少配置 `GEOFLOW_KEY_ID`、`GEOFLOW_SECRET` 和可写存储目录。
- 最近任务卡片：
  - 复用 `_jobs-table.blade.php`。
  - 默认展示该渠道最近 20 条。
- 最近日志卡片：
  - 展示该渠道最近 20 条日志。

健康检查交互：

- 成功：更新 `last_health_status = ok`、`last_health_checked_at`、清空 `last_error_message`，页面闪现成功消息。
- 失败：更新 `last_health_status = failed`、`last_health_checked_at`、写入 `last_error_message`，页面闪现错误。

### 5.7 分发任务页 UI

分发任务页用于排查所有分发记录。

当前表格列：

- 文章标题。
- 渠道名称。
- action。
- status。
- remote URL。
- attempt count。
- last error。

状态徽标建议：

- `queued`：蓝色，表示等待处理。
- `sending`：amber 色，表示发送中。
- `synced`：绿色，表示已同步。
- `failed`：红色，表示失败。

后续增强列：

- next retry at。
- last attempt at。
- 操作列，失败记录显示“重试”按钮。
- 失败类型或错误摘要。

分页约束：

- 全局任务页必须分页，当前为 `paginate(20)`。
- 渠道详情页可以只显示最近 20 条，避免详情页过重。

### 5.8 任务表单分发区 UI

任务表单中新增“内容分发”卡片，位置应在“发布设置”之后、“SEO 设置”之前。原因是分发属于发布后的动作，与 SEO、栏目等内容属性不同。

展示逻辑：

- 有活跃渠道时，以两列 checkbox 卡片展示。
- 每个选项展示渠道名称和域名。
- hover 使用 `hover:border-blue-300 hover:bg-blue-50`。
- 已选值来自 `old('distribution_channel_ids')` 或任务已保存绑定。
- 无渠道时展示灰色提示和“创建分发渠道”链接。

交互约束：

- 不要把渠道选择做成多选下拉。当前后台任务表单已经大量使用 section card，checkbox 卡片更容易扫描。
- 不要展示 paused 渠道，避免运营人员误选不可用目标。
- 不在任务表单里展示 secret 或健康检查按钮。渠道诊断留在分发管理模块。

### 5.9 文章列表分发状态 UI

文章列表已增加分发状态摘要，方便运营人员从文章视角判断远端同步情况。

推荐状态：

- 无分发记录：不展示徽标，避免干扰普通文章列表。
- 存在记录且全部 `synced`：显示绿色“已分发”。
- 存在记录且任一 `failed`：显示红色“分发失败”。
- 存在记录但未全部完成：显示蓝色“分发中”。

实现方式：

- 在 `ArticleController::queryArticles()` 中使用 `withCount()` 加载统计。
- 在 `resources/views/admin/articles/index.blade.php` 的文章行中增加轻量徽标。
- 徽标附带 `synced/total`、`failed/total` 或 `pending/total` 数字摘要。
- 不在列表中展开详细错误，详情应跳转到分发任务页或渠道详情页。

## 6. 后台路由与控制器设计

后台分发路由位于可配置后台前缀下：

```php
$adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
```

因此所有链接必须使用 route helper。

当前路由：

| HTTP | 路由 | 名称 | 控制器方法 | 说明 |
|---|---|---|---|---|
| GET | `distribution/` | `admin.distribution.index` | `DistributionController::index` | 分发首页 |
| GET | `distribution/create` | `admin.distribution.create` | `DistributionController::create` | 创建渠道页 |
| POST | `distribution/create` | `admin.distribution.store` | `DistributionController::store` | 保存渠道并生成密钥 |
| GET | `distribution/jobs` | `admin.distribution.jobs` | `DistributionController::jobs` | 全局分发任务页 |
| POST | `distribution/jobs/{distributionId}/retry` | `admin.distribution.retry` | `DistributionController::retry` | 手动重试失败分发 |
| GET | `distribution/{channelId}` | `admin.distribution.show` | `DistributionController::show` | 渠道详情页 |
| GET | `distribution/{channelId}/edit` | `admin.distribution.edit` | `DistributionController::edit` | 编辑渠道页 |
| PUT | `distribution/{channelId}` | `admin.distribution.update` | `DistributionController::update` | 更新渠道信息 |
| POST | `distribution/{channelId}/pause` | `admin.distribution.pause` | `DistributionController::pause` | 暂停渠道 |
| POST | `distribution/{channelId}/activate` | `admin.distribution.activate` | `DistributionController::activate` | 启用渠道 |
| POST | `distribution/{channelId}/rotate-secret` | `admin.distribution.rotate-secret` | `DistributionController::rotateSecret` | 重置 secret |
| POST | `distribution/{channelId}/reveal-secret` | `admin.distribution.reveal-secret` | `DistributionController::revealSecret` | 超级管理员验证密码后临时显示 active secret |
| POST | `distribution/{channelId}/download-package` | `admin.distribution.download-package` | `DistributionController::downloadPackage` | 超级管理员验证密码后下载目标站点包 |
| POST | `distribution/{channelId}/sync-settings` | `admin.distribution.sync-settings` | `DistributionController::syncSettings` | 通过目标站 Agent 同步渠道站点设置 |
| POST | `distribution/{channelId}/health` | `admin.distribution.health` | `DistributionController::health` | 健康检查 |

后续建议路由：

| HTTP | 路由 | 名称 | 控制器方法 | 说明 |
|---|---|---|---|---|
| POST | `distribution/jobs/bulk-retry` | `admin.distribution.bulk-retry` | `DistributionController::bulkRetry` | 批量重试失败分发 |
| DELETE | `distribution/{channelId}` | `admin.distribution.destroy` | `DistributionController::destroy` | 删除未被使用的渠道 |

权限：

- 当前分发管理位于 `admin.auth` 和 `admin.activity` 中间件下。
- 第一版不强制 `admin.super`，普通后台管理员可以管理渠道。
- 后续如果要限制 secret 重置和渠道删除，可单独把这些高风险动作放入 `admin.super`。

## 7. Agent 协议设计

### 7.1 健康检查

```http
GET /geoflow-agent/v1/health
```

预期响应：

```json
{
  "ok": true,
  "agent_version": "1.0.0",
  "site_name": "Example Site",
  "server_time": "2026-05-18T10:00:00+08:00",
  "capabilities": ["article.publish"]
}
```

GEOFlow 处理规则：

- HTTP 2xx 且可解析 JSON 即认为 Agent 可响应。
- 成功时写入 `last_health_status = ok`。
- 失败时写入 `last_health_status = failed` 和错误信息。
- 如果渠道存在 active secret，健康检查也应签名。

### 7.2 发布文章

```http
POST /geoflow-agent/v1/articles
```

第一版请求体：

```json
{
  "version": "1.0",
  "source": "geoflow",
  "event": "article.publish",
  "article": {
    "id": 123,
    "title": "Article title",
    "slug": "article-title",
    "excerpt": "Short summary",
    "content": "Markdown body",
    "keywords": "GEO,AI",
    "meta_description": "SEO description",
    "status": "published",
    "published_at": "2026-05-18T10:00:00.000000Z",
    "updated_at": "2026-05-18T10:00:00.000000Z",
    "category": {"id": 1, "name": "Category", "slug": "category"},
    "author": {"id": 1, "name": "GEOFlow"},
    "task": {"id": 1, "name": "Task name"}
  }
}
```

预期响应：

```json
{
  "ok": true,
  "remote_id": "987",
  "remote_url": "https://example.com/article/article-title",
  "status": "published"
}
```

响应处理规则：

- `remote_id` 为标量时保存为字符串。
- `remote_url` 为标量时保存为字符串。
- 响应中缺少 `remote_id` 或 `remote_url` 不应导致本次请求失败，但会降低可观测性；后续可在 Agent 示例中要求必须返回。

### 7.3 HMAC 请求头

每个签名请求都包含：

```text
Content-Type: application/json
Accept: application/json
X-GEOFlow-Key-Id: gfk_xxx
X-GEOFlow-Timestamp: 2026-05-18T10:00:00+08:00
X-GEOFlow-Nonce: uuid-or-random
X-GEOFlow-Idempotency-Key: article-123-channel-5-publish-v1
X-GEOFlow-Body-SHA256: hex-sha256-body
X-GEOFlow-Signature: hex-hmac-sha256
X-GEOFlow-Event: article.publish
```

签名字符串：

```text
METHOD + "\n" +
PATH + "\n" +
TIMESTAMP + "\n" +
NONCE + "\n" +
SHA256(BODY)
```

Agent 校验规则：

- 拒绝超出允许时间窗口的 timestamp。
- 拒绝重复 nonce。
- 拒绝 body hash 不匹配。
- 拒绝未知或已禁用 key ID。
- 拒绝缺少所需 scope 的请求。
- 对同一个 idempotency key 返回同一结果。

## 8. 代码结构与调用链

### 8.1 文件地图

当前第一版文件：

- `routes/web.php`：管理后台分发路由。
- `app/Http/Controllers/Admin/DistributionController.php`：渠道 UI、创建、详情、任务列表和健康检查。
- `app/Http/Controllers/Admin/TaskController.php`：任务表单渠道选项、任务渠道同步。
- `app/Http/Controllers/Admin/ArticleController.php`：管理后台文章发布钩子。
- `app/Services/GeoFlow/WorkerExecutionService.php`：Worker 发布钩子。
- `app/Services/GeoFlow/DistributionOrchestrator.php`：任务渠道同步、入队、处理和日志。
- `app/Services/GeoFlow/DistributionPayloadBuilder.php`：文章 payload 构造。
- `app/Services/GeoFlow/DistributionHttpClient.php`：签名 HTTP 发送和健康检查。
- `app/Services/GeoFlow/DistributionSigningService.php`：HMAC 请求头构造。
- `app/Jobs/ProcessArticleDistributionJob.php`：分发队列任务。
- `app/Models/DistributionChannel.php`：渠道模型。
- `app/Models/DistributionChannelSecret.php`：渠道 secret 模型。
- `app/Models/ArticleDistribution.php`：文章分发记录模型。
- `app/Models/DistributionLog.php`：分发日志模型。
- `database/migrations/2026_05_17_000000_create_distribution_management_tables.php`：分发表迁移。
- `database/migrations/2026_05_18_180000_align_distribution_management_tables.php`：兼容旧分发表结构，补齐 `channel_type`、`idempotency_key`、任务绑定策略列和日志事件列。
- `resources/views/admin/distribution/*.blade.php`：分发管理后台页面。
- `resources/views/admin/tasks/create.blade.php`：渠道复选框 UI。
- `resources/views/admin/partials/header.blade.php`：顶部菜单。
- `lang/zh_CN/admin.php`、`lang/en/admin.php`：管理后台文案。
- `tests/Feature/AdminDistributionPageTest.php`：第一版覆盖测试。

### 8.2 渠道创建调用链

调用链：

```text
DistributionController::create()
DistributionController::store()
  -> validate()
  -> normalizeDomain()
  -> DistributionChannel::create()
  -> DistributionChannelSecret::create()
  -> ApiKeyCrypto::encrypt()
  -> redirect(admin.distribution.index)
  -> session(distribution_secret)
```

关键约束：

- `endpoint_url` 先补全协议，再校验 http/https 和 host，最后使用 `rtrim($url, '/')` 保存。
- `domain` 保存时尽量解析 host。
- secret 明文只进入 redirect session，不写日志。
- `created_by_admin_id` 使用 `auth('admin')->id()`。

### 8.3 任务绑定调用链

调用链：

```text
TaskController::create()
  -> loadTaskFormOptions()
  -> view(admin.tasks.form)

TaskController::store()
  -> validateTaskForm()
  -> buildTaskPayload()
  -> TaskLifecycleService::createTask()
  -> selectedDistributionChannelIds()
  -> DistributionOrchestrator::syncTaskChannels()

TaskController::edit()
  -> taskDistributionChannelIds()
  -> view(admin.tasks.form)

TaskController::update()
  -> validateTaskForm()
  -> TaskLifecycleService::updateTask()
  -> selectedDistributionChannelIds()
  -> DistributionOrchestrator::syncTaskChannels()
```

`syncTaskChannels()` 逻辑：

- 只保留提交 ID 中仍为 `active` 的渠道。
- 使用 `syncWithPivotValues()` 写入 pivot。
- 默认 pivot 字段：
  - `trigger = after_local_publish`
  - `remote_status = follow_local`
  - `failure_policy = ignore_distribution_failure`
  - `max_attempts = 3`

### 8.4 文章发布入队调用链

管理后台调用链：

```text
ArticleController::store/update/batch...
  -> ArticleWorkflow::normalizeState()
  -> Article::create/update()
  -> if status === published
  -> DistributionOrchestrator::enqueueForArticle()
```

Worker 调用链：

```text
WorkerExecutionService::executeTask()
  -> publishDueDraftArticle()
  -> ArticleWorkflow::normalizeState('published', review_status)
  -> Article::update(status, review_status, published_at)
  -> DistributionOrchestrator::enqueueForArticle(article_id)
```

`enqueueForArticle()` 逻辑：

- 接收 `Article` 模型或 article ID。
- 文章不存在时直接返回。
- 文章不是 `published` 时直接返回。
- 文章没有 `task_id` 时直接返回。
- 加载 `task.distributionChannels`。
- 只取 `status = active` 的渠道。
- 构造 payload 并计算 `payload_hash`。
- 对每个渠道 `updateOrCreate()` 分发记录。
- 写入 `distribution.queued` 日志。
- dispatch `ProcessArticleDistributionJob` 到 `distribution` 队列，并 `afterCommit()`。

### 8.5 队列处理调用链

调用链：

```text
ProcessArticleDistributionJob::handle()
  -> ArticleDistribution::find()
  -> DistributionOrchestrator::process()
    -> loadMissing(article, channel)
    -> status = sending
    -> attempt_count + 1
    -> DistributionPayloadBuilder::build()
    -> DistributionHttpClient::send()
    -> status = synced
    -> save remote_id / remote_url
    -> log success
  -> catch Throwable
    -> status = failed
    -> save last_error_message
    -> log failure
```

异常处理原则：

- 队列任务内部捕获异常并写入失败状态。
- 不向外抛出导致 Laravel 自动重试。
- 第一版通过后台可观测性暴露失败，后续再接入应用级重试。

### 8.6 签名和 HTTP 调用链

发送文章：

```text
DistributionHttpClient::send()
  -> load channel.activeSecret
  -> json_encode(payload)
  -> endpoint(channel, '/geoflow-agent/v1/articles')
  -> DistributionSigningService::headers()
  -> Http::timeout(30)->withHeaders()->withBody()->post()
  -> activeSecret.last_used_at = now()
  -> response failed: throw RuntimeException
  -> response ok: return json array
```

健康检查：

```text
DistributionHttpClient::health()
  -> endpoint(channel, '/geoflow-agent/v1/health')
  -> if activeSecret exists: signed GET
  -> Http::timeout(10)->acceptJson()->get()
  -> response failed: throw RuntimeException
  -> response ok: return json array
```

签名：

```text
DistributionSigningService::headers()
  -> ApiKeyCrypto::decrypt(secret_ciphertext)
  -> bodyHash = sha256(body)
  -> timestamp = now()->toIso8601String()
  -> nonce = Str::uuid()
  -> signature = hash_hmac('sha256', signingString, plainSecret)
  -> return headers
```

## 9. 数据库逻辑

### 9.1 `distribution_channels`

用途：保存目标站点 Agent 渠道。

字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint | 主键 |
| `name` | string(120) | 渠道名称 |
| `domain` | string(255) | 展示域名，规范化 host |
| `endpoint_url` | string(500) | Agent 根地址 |
| `channel_type` | string(60) | 第一版固定 `geoflow_agent` |
| `template_key` | string(120), nullable | 目标站模板或栏目映射 |
| `site_settings` | json, nullable | 目标站点设置：站点名、描述、SEO、版权、Logo、Favicon、列表数量等 |
| `status` | string(30), index | `active`、`paused` |
| `description` | text, nullable | 备注 |
| `last_health_status` | string(30), nullable | 最近健康状态 |
| `last_health_checked_at` | timestamp, nullable | 最近健康检查时间 |
| `last_error_message` | text, nullable | 最近渠道级错误 |
| `created_by_admin_id` | unsignedBigInteger, nullable, index | 创建管理员 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

关系：

- hasMany `DistributionChannelSecret`
- hasOne `activeSecret`
- belongsToMany `Task` through `task_distribution_channels`
- hasMany `ArticleDistribution`
- hasMany `DistributionLog`

### 9.2 `distribution_channel_secrets`

用途：保存渠道密钥。

字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint | 主键 |
| `distribution_channel_id` | foreignId | 关联渠道，级联删除 |
| `key_id` | string(80), unique | 对外 key ID |
| `secret_ciphertext` | text | 加密 secret |
| `status` | string(30), index | `active`、后续可加 `revoked` |
| `scopes` | json, nullable | 权限范围 |
| `last_used_at` | timestamp, nullable | 最近使用时间 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

约束：

- 同一时间建议只有一个 active secret。
- `key_id` 全局唯一。
- secret 明文不得持久化。

### 9.3 `task_distribution_channels`

用途：保存任务与分发渠道的绑定关系。

字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint | 主键 |
| `task_id` | foreignId | 关联任务，级联删除 |
| `distribution_channel_id` | foreignId | 关联渠道，级联删除 |
| `trigger` | string(60) | 默认 `after_local_publish` |
| `remote_status` | string(40) | 默认 `follow_local` |
| `failure_policy` | string(60) | 默认 `ignore_distribution_failure` |
| `max_attempts` | unsignedSmallInteger | 默认 3 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

唯一约束：

```text
task_id + distribution_channel_id
```

设计说明：

- 当前只暴露渠道选择，不暴露 trigger、remote status、failure policy。
- 这些字段先作为后续扩展点存在，默认值由 `syncTaskChannels()` 写入。

### 9.4 `article_distributions`

用途：保存文章分发执行状态。

字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint | 主键 |
| `article_id` | foreignId | 关联文章，级联删除 |
| `distribution_channel_id` | foreignId | 关联渠道，级联删除 |
| `action` | string(30) | 当前为 `publish` |
| `status` | string(30), index | `queued`、`sending`、`synced`、`failed` |
| `remote_id` | string(120), nullable | 目标站返回 ID |
| `remote_url` | string(500), nullable | 目标站文章 URL |
| `idempotency_key` | string(120), unique | 幂等 key |
| `attempt_count` | unsignedInteger | 尝试次数 |
| `next_retry_at` | timestamp, nullable, index | 下次重试时间 |
| `last_attempt_at` | timestamp, nullable | 最近尝试时间 |
| `last_error_message` | text, nullable | 最近错误 |
| `payload_hash` | string(64), nullable | payload SHA-256 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

唯一约束：

```text
article_id + distribution_channel_id + action
```

状态说明：

- `queued`：已入队，等待处理。
- `sending`：队列任务处理中。
- `synced`：目标站已成功接收。
- `failed`：发送失败或处理异常。

### 9.5 `distribution_logs`

用途：记录分发过程日志。

字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint | 主键 |
| `distribution_channel_id` | unsignedBigInteger, nullable, index | 关联渠道 |
| `article_distribution_id` | unsignedBigInteger, nullable, index | 关联分发记录 |
| `article_id` | unsignedBigInteger, nullable, index | 关联文章 |
| `level` | string(20) | `info`、`warning`、`error` |
| `event` | string(120), nullable | 事件名 |
| `message` | text | 可读日志 |
| `context` | json, nullable | 附加上下文 |
| `created_at` | timestamp, nullable | 创建时间 |

日志事件建议：

- `distribution.queued`
- `distribution.sending`
- `distribution.synced`
- `distribution.failed`
- `distribution.retry_scheduled`
- `distribution.health_ok`
- `distribution.health_failed`

当前模型设置：

- `DistributionLog::$timestamps = false`。
- 写入时由 `DistributionOrchestrator::log()` 显式设置 `created_at`。

## 10. 错误处理与一致性策略

### 10.1 错误分类

| 错误类型 | 示例 | 第一版处理 | 后续处理 |
|---|---|---|---|
| 配置错误 | endpoint URL 错误、缺少 active secret | 标记 failed，记录错误 | 后台详情页提示配置问题 |
| 鉴权错误 | 401、403、签名不匹配 | 标记 failed | 不自动重试，提示重置 secret 或检查 Agent |
| 网络错误 | timeout、connection refused | 标记 failed | 可自动延迟重试 |
| 远端限流 | 429 | 标记 failed | 可自动延迟重试 |
| 远端服务错误 | 500、502、503、504 | 标记 failed | 可自动延迟重试 |
| 载荷错误 | 422、字段缺失 | 标记 failed | 不自动重试，提示修正 Agent 或 payload |

### 10.2 一致性策略

本地与远端采用最终一致。

本地强一致范围：

- 文章发布状态。
- 任务渠道绑定。
- 分发记录入库。

远端最终一致范围：

- 目标站文章创建或更新。
- 目标站 URL 回写。
- 失败后的重试或人工处理。

原则：

- 本地事务提交后再 dispatch 分发任务。
- 分发任务通过 `afterCommit()` 避免读取未提交数据。
- 远端失败不会回滚本地事务。
- 重复入队依赖唯一约束和 idempotency key 防止重复创建远端文章。

## 11. 开发计划

### 11.1 开发节奏

分发管理应按“小闭环、可回滚、可验证”的方式推进。每个开发包都要独立可测试，避免一次性把 Provider、重试、UI 状态、密钥轮换全部混在同一个提交里。

建议开发顺序：

1. **DP-0：整理当前 Phase 1/2 基线**。锁定已经跑通的 Agent-first 闭环，筛掉不应提交的 longtask 输出。
2. **DP-1：渠道生命周期完善**。补渠道编辑、暂停、启用、secret 重置。
3. **DP-2：分发任务运营能力**。补失败筛选、手动重试、重试策略。
4. **DP-3：文章和任务侧状态可见性**。让运营人员从文章列表和任务列表看到分发结果。
5. **DP-4：Agent 示例、目标站点包与接入文档**。提供可本地运行的 PHP Agent 示例、可下载目标站点 ZIP 包和协议说明。
6. **DP-5：发布硬化与首版提交**。补齐测试、文案、路由检查、提交范围。
7. **DP-6：后续 Provider 基础**。在 Agent-first 稳定后再抽象通用 Webhook 或特定平台适配器。

每个开发包完成后都至少运行：

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/AdminDistributionPageTest.php
git diff --check
```

如果改动影响文章、任务或 Worker 发布链路，还要运行：

```bash
php artisan test --compact tests/Feature/AdminDistributionPageTest.php tests/Feature/AdminTasksPageTest.php tests/Feature/AdminArticlesPageTest.php
php artisan test --compact
```

### 11.2 DP-0：整理当前 Phase 1/2 基线

目标：把当前未提交的分发管理半成品整理成一个可运行、可提交的最小闭环。

范围：

- 保留 Phase 1/2 代码文件。
- 保留 `docs/distribution/unified-distribution-implementation-plan.md`。
- 可选保留 `docs/distribution/unified-distribution-implementation-plan.docx`。
- 不纳入 `.longtask/`。
- 不纳入 longtask 生成的骨架文档。
- 不纳入单独草案 `docs/distribution/distribution-management-agent-plan.md`，除非明确需要保存历史草案。

开发动作：

- 确认 `DistributionController`、模型、服务、Job、迁移、视图和测试都在主仓库。
- 确认任务表单可以选择渠道。
- 确认文章发布和 Worker 发布都会触发入队。
- 确认签名请求头包含 body hash 和 idempotency key。
- 确认分发失败不会影响本地文章状态。

验收标准：

- 分发管理菜单可访问。
- 可以创建渠道并看到一次性 secret。
- 任务可以绑定渠道。
- 发布文章后生成分发记录。
- 分发 Job 可以发送签名 payload 并保存远端结果。
- 全量测试通过。

### 11.3 DP-1：渠道生命周期完善

目标：让管理员可以在后台维护渠道，而不是只能创建和查看。

涉及文件：

- `routes/web.php`
- `app/Http/Controllers/Admin/DistributionController.php`
- `resources/views/admin/distribution/index.blade.php`
- `resources/views/admin/distribution/show.blade.php`
- `resources/views/admin/distribution/edit.blade.php`
- `lang/zh_CN/admin.php`
- `lang/en/admin.php`
- `tests/Feature/AdminDistributionPageTest.php`

功能清单：

- 渠道编辑：修改 `name`、`domain`、`endpoint_url`、`template_key`、`site_settings`、`status`、`description`。
- 暂停渠道：将 `status` 改为 `paused`。
- 启用渠道：将 `status` 改为 `active`。
- 重置 secret：旧 active secret 改为 `revoked`，生成新的 `key_id` 和 secret。
- 详情页展示最近错误和健康检查时间。

UI 设计：

- 渠道列表操作列增加“查看”“编辑”。
- 详情页右侧增加“编辑”“暂停/启用”“重置密钥”。
- 重置密钥必须使用 POST 表单，并在按钮附近提示“旧密钥将失效，新密钥会临时展示”。
- 重置成功后复用首页的 secret 提示样式，或者在详情页顶部展示同样的 amber 提示框。
- 密钥重显放在密钥信息卡片中：超级管理员输入当前管理员密码后，系统临时展示 active secret。

方法设计：

- `DistributionController::edit(int $channelId)`
- `DistributionController::update(Request $request, int $channelId)`
- `DistributionController::pause(int $channelId)`
- `DistributionController::activate(int $channelId)`
- `DistributionController::rotateSecret(int $channelId)`
- `DistributionController::revealSecret(Request $request, int $channelId)`

验收标准：

- 暂停渠道后，任务表单不再展示该渠道。
- 已暂停渠道不会参与新文章入队。
- 重置 secret 后旧 key 不再是 active。
- 新 secret 明文临时展示，刷新后隐藏。
- 超级管理员输入当前管理员密码后可再次临时显示 active secret。
- 密钥重显失败场景有明确错误提示：非超级管理员、密码错误、active secret 不存在、密钥解密失败。
- 相关操作都有测试覆盖。

### 11.4 DP-2：分发任务运营能力

目标：让管理员可以在后台定位失败并处理失败。

涉及文件：

- `routes/web.php`
- `app/Http/Controllers/Admin/DistributionController.php`
- `app/Jobs/ProcessArticleDistributionJob.php`
- `app/Services/GeoFlow/DistributionRetryPolicy.php`
- `app/Services/GeoFlow/DistributionOrchestrator.php`
- `resources/views/admin/distribution/jobs.blade.php`
- `resources/views/admin/distribution/_jobs-table.blade.php`
- `lang/zh_CN/admin.php`
- `lang/en/admin.php`
- `tests/Unit/DistributionRetryPolicyTest.php`
- `tests/Feature/AdminDistributionPageTest.php`

功能清单：

- 分发任务按状态筛选：全部、queued、sending、synced、failed。
- 分发任务按渠道筛选。
- 失败记录展示“重试”按钮。
- 手动重试将 `failed` 改回 `queued`，清空 `last_error_message`，重新 dispatch Job。
- 增加 `DistributionRetryPolicy`，区分可重试和不可重试错误。
- 可重试错误写入 `next_retry_at`，不可重试错误保持 `failed`。

重试策略：

- 不自动重试：401、403、signature invalid、payload validation error。
- 可重试：timeout、connection、429、500、502、503、504。
- 重试间隔：按 attempt 指数退避，最大不超过 1 小时。
- 最大次数：读取 `task_distribution_channels.max_attempts`，默认 3。

UI 设计：

- 分发任务页顶部增加筛选表单，沿用当前后台筛选区样式。
- 状态使用彩色徽标：queued 蓝色、sending amber、synced 绿色、failed 红色。
- 失败记录操作列显示“重试”。
- 非失败记录操作列显示灰色空状态。

验收标准：

- 管理员可以筛选失败任务。
- 管理员可以手动重试失败任务。
- 鉴权错误不会被自动重试。
- 网络错误和 5xx 错误可按策略延迟重试。
- 重试不改变本地文章状态。

### 11.5 DP-3：文章和任务侧状态可见性

目标：让内容运营人员不进入分发管理页，也能看到文章和任务的分发结果。

涉及文件：

- `app/Http/Controllers/Admin/ArticleController.php`
- `resources/views/admin/articles/index.blade.php`
- `app/Services/GeoFlow/TaskMonitoringQueryService.php`
- `resources/views/admin/tasks/index.blade.php`
- `lang/zh_CN/admin.php`
- `lang/en/admin.php`
- `tests/Feature/AdminDistributionPageTest.php`
- `tests/Feature/AdminArticlesPageTest.php`
- `tests/Feature/AdminTasksPageTest.php`

文章列表设计：

- 无分发记录：不展示徽标。
- 全部成功：绿色“已分发”。
- 存在失败：红色“分发失败”。
- 存在 queued 或 sending：蓝色“分发中”。
- 鼠标或链接后续可跳转到分发任务筛选页。

任务列表设计：

- 展示该任务相关文章的失败分发数。
- 全部成功时显示绿色“已分发 :count”。
- 存在失败时显示红色“分发失败 :count”。
- 存在 queued 或 sending 时显示蓝色“分发中 :count”。
- 有失败时用红色轻量提示，不改变任务运行状态。
- 绑定渠道数可以作为后续增强补充，不阻塞 DP-3。

查询设计：

- 文章列表使用 `withCount()` 加载 `distribution_total_count`、`distribution_synced_count`、`distribution_failed_count`。
- 任务列表避免 N+1 查询，应在 `TaskMonitoringQueryService` 聚合统计。
- 不在 Blade 里逐行查询数据库。

验收标准：

- 文章列表能看出单篇文章分发状态。
- 任务列表能看出哪些任务产生了分发失败。
- 列表查询没有明显 N+1。
- 现有文章和任务页面测试通过。

### 11.6 DP-4：Agent 示例、目标站点包与接入文档

目标：让目标站点开发者可以按示例快速接入 GEOFlow 分发协议，同时让普通新站点可以直接下载 ZIP 站点包、上传解压后进入测试。

涉及文件：

- `app/Services/GeoFlow/DistributionTargetSitePackageBuilder.php`
- `app/Http/Controllers/Admin/DistributionController.php`
- `resources/views/admin/distribution/show.blade.php`
- `docs/distribution/agent-sample/php/geoflow-agent.php`
- `docs/distribution/agent-sample/README.md`
- `docs/distribution/unified-distribution-implementation-plan.md`

示例能力：

- 支持 `GET /geoflow-agent/v1/health`。
- 支持 `POST /geoflow-agent/v1/articles`。
- 支持 `POST /geoflow-agent/v1/site-settings`。
- 校验 key ID。
- 校验 timestamp 时间窗口。
- 校验 body SHA-256。
- 校验 HMAC signature。
- 使用 idempotency key 返回相同结果。
- 将接收到的文章写为本地 JSON 文件，便于开发验证和后续替换成 CMS 写入逻辑。

目标站点包能力：

- 按渠道动态生成 ZIP 包，文件名形如 `geoflow-target-site-{domain}.zip`。
- 包内 `config.php` 写入当前渠道站点名、站点描述、关键词、版权、SEO 模板、目标模板标识、域名、Agent 基础地址、`base_path`、`key_id` 和 secret。
- 包内 `public/index.php` 同时提供：
  - `GET /geoflow-agent/v1/health`
  - `POST /geoflow-agent/v1/articles`
  - `POST /geoflow-agent/v1/site-settings`
  - `GET /`
  - `GET /article/{slug}`
- `public/index.php` 接收请求时先按 `base_path` 归一化路径，再进行路由匹配和 HMAC 签名校验，避免二级目录部署时签名路径不一致。
- 目标站前台链接通过统一的路径 helper 生成，二级目录部署时首页和详情页链接应自动带上 `base_path`。
- 包内 `storage/articles` 保存接收到的文章 JSON。
- 包内 `storage/site-settings.json` 保存远程同步后的站点设置；首页、详情页和页脚渲染会优先读取该文件，未同步时回退 `config.php` 内置设置。
- 包内根目录 `.htaccess`、`storage/.htaccess` 和 `nginx.example.conf` 提供基础路由与敏感文件保护，必须禁止直接读取 `config.php`、`storage`、`README.md` 和 Nginx 示例配置。
- 站点包下载必须验证超级管理员当前密码，且 `package_password` 不得写入管理员操作日志。

文档内容：

- 如何启动本地 Agent 示例。
- 如何在 GEOFlow 创建渠道。
- 如何复制 `key_id` 和 secret；忘记 secret 时，超级管理员如何通过当前密码临时重新显示。
- 如何从渠道详情页下载目标站点包。
- 如何在渠道编辑页维护目标站点设置并选择目标模板。
- 如何从渠道详情页点击“同步设置”，把站点标题、版权、SEO 和模板标识写入目标站。
- 如何上传解压、将 Web 根目录指向 `public`，并确认 `storage/articles` 可写。
- 如果无法设置 Web 根目录，如何依赖根目录 `.htaccess` 转发访问并保护敏感文件。
- 如果 Agent 基础地址带二级目录，如何确认 `base_path`、首页链接、详情页链接和签名校验一致。
- 如何配置 endpoint。
- 如何触发一篇测试文章分发。
- 常见错误说明：401、403、422、500、timeout。

验收标准：

- `php -l docs/distribution/agent-sample/php/geoflow-agent.php` 通过。
- 临时生成的站点包中 `config.php` 和 `public/index.php` 语法检查通过。
- 站点包 ZIP 中包含当前渠道的 `key_id`、secret、首页列表页和文章详情页代码。
- 站点包 ZIP 中包含根目录 `.htaccess`、`storage/.htaccess` 和 Nginx 敏感路径拒绝配置。
- 带二级目录的 Agent 地址可以通过生成包的健康检查、文章发布和前台链接烟测。
- 目标站点设置可以通过签名接口同步到生成包，并影响首页标题、SEO 和页脚版权。
- 密码错误不能下载站点包。
- 本地启动示例后，GEOFlow 健康检查可成功。
- 发布测试文章后，示例 Agent 能收到并保存文章。

### 11.7 DP-5：发布硬化与首版提交

目标：把分发管理整理成可合并、可推送的首版提交。

提交前检查：

- 只选择性暂存相关代码和统一方案文档。
- 不暂存 `.env`、storage、日志、缓存、上传目录。
- 不暂存 `.longtask/`。
- 不暂存 longtask 骨架文档。
- 不从旁路仓库 `GEOFlow-laravel12` 推送。

建议提交拆分：

1. `feat: add distribution management baseline`
   - 分发表、模型、服务、Job、控制器、后台页面、任务绑定、发布钩子、测试。
2. `docs: add distribution implementation plan`
   - 统一实施方案 Markdown。
   - 如果决定保存 Word，再纳入 DOCX。

验证命令：

```bash
git fetch origin
git status --short --branch
vendor/bin/pint --dirty --format agent
php artisan test --compact
git diff --check
```

验收标准：

- `main` 与 `origin/main` 无冲突。
- 全量测试通过。
- 提交范围不包含敏感信息和无关生成物。
- 推送目标只使用主仓库 `origin/main`。

### 11.8 DP-6：后续 Provider 基础

目标：在 Agent-first 稳定后，开始抽象 Provider，但不破坏第一版协议。

建议顺序：

1. 通用 Webhook Provider，只转发签名 payload。
2. WordPress Agent 插件或 WordPress Provider。
3. Ghost Provider。
4. 微信公众号草稿和发布能力。
5. 通知型适配器，例如 Telegram、Discord。

Provider 开发约束：

- Provider 不应绕过 `article_distributions` 状态机。
- Provider 不应直接改本地文章状态。
- Provider 返回结果必须统一映射到 `remote_id`、`remote_url` 和 `status`。
- Provider 特有配置应进入后续 `distribution_channels.config` 或独立配置表，不要塞进现有通用字段。

## 12. 实施阶段

### 12.1 阶段 1：跑通分发管理基础闭环

当前已完成：

- [x] 后台分发管理菜单与路由。
- [x] 渠道创建与一次性 secret 展示。
- [x] 渠道详情与健康检查。
- [x] 分发任务列表。
- [x] 任务创建和编辑页绑定渠道。
- [x] 本地文章发布触发分发入队。
- [x] Worker 发布到期草稿后触发分发入队。
- [x] 签名 HTTP 发送。
- [x] 分发记录和日志写入。
- [x] 功能测试覆盖第一版闭环。

验证命令：

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/AdminDistributionPageTest.php tests/Feature/AdminTasksPageTest.php tests/Feature/AdminArticlesPageTest.php
php artisan route:list --except-vendor --path=distribution
php artisan test --compact
```

当前预期：

```text
Tests: 151 passed (705 assertions)
```

### 12.2 阶段 2：补齐运营可用性

当前已完成：

- [x] 渠道编辑、暂停和启用。
- [x] secret 重置和旧 key 失效。
- [x] 手动重试失败分发。
- [x] 分发任务筛选。
- [x] 错误分类和重试策略。
- [x] 文章列表显示分发状态摘要。
- [x] 任务列表显示分发状态摘要。

后续继续完成：

- [ ] Agent PHP 示例。

阶段 2 的验收标准：

- 管理员不需要进数据库即可处理常见失败。
- 文章列表能快速看到分发状态。
- 目标站开发者可以照 Agent 示例接入。
- 失败重试不会影响本地文章状态。

### 12.3 阶段 3：Provider 路线图

Agent 优先基础能力稳定后，再推进：

1. 通用签名 Webhook Provider：只转发 payload。
2. WordPress Provider 或 WordPress Agent 插件：文章、媒体 REST API、分类、标签、原文链接。
3. Ghost Provider：Admin API 文章、标签、canonical URL。
4. 微信公众号：只有权限检测明确后，再接入草稿和发布 API。
5. Telegram/Discord：作为通知适配器，不作为长篇 SEO 文章目标。
6. Dev.to、Mastodon、Bluesky：短内容或垂直社区适配器。

不要把微信、社交媒体或 OAuth 流程放进第一版 Agent 协议提交。

## 13. 测试策略

### 13.1 已有功能测试

`tests/Feature/AdminDistributionPageTest.php` 应覆盖：

- 管理员可以打开分发管理页。
- 管理员可以创建渠道并看到一次性 secret。
- 管理员可以编辑、暂停、启用渠道。
- 渠道编辑页展示目标站点设置和可选前台模板。
- 保存渠道时写入目标站点设置 JSON 和模板标识。
- 管理员可以重置 secret，旧 key 失效。
- 超级管理员可以输入当前密码临时重新显示 active secret。
- 密码错误或非超级管理员不能重新显示 active secret。
- 渠道详情页展示 Agent 接入引导和示例文件路径。
- 超级管理员可以输入当前密码下载目标站点包。
- 密码错误不能下载目标站点包。
- 目标站点包包含敏感文件访问保护配置。
- 目标站点包支持 Agent 基础地址部署在二级目录下。
- 目标站点设置可以通过签名请求同步到目标 Agent。
- 任务创建页展示活跃分发渠道。
- 创建任务时保存已选择渠道。
- 发布任务文章后创建 `article_distributions` 记录。
- 签名请求头包含 body hash 和 idempotency key。
- 处理分发记录时发送签名 payload，并记录远端结果。
- 分发任务页能按状态和渠道筛选。
- 失败记录可以手动重试并重新入队。
- 网络错误按策略延迟重试，鉴权错误不自动重试。
- 分发页面不暴露缺失翻译 key。
- 分发页面不直接展示 `active`、`publish`、`info` 等内部枚举值。

`tests/Feature/AdminArticlesPageTest.php` 应覆盖：

- 文章列表能显示单篇文章的分发状态摘要。

`tests/Feature/AdminTasksPageTest.php` 应覆盖：

- 任务列表能显示任务相关文章的分发失败摘要。

`tests/Unit/DistributionRetryPolicyTest.php` 应覆盖：

- 可重试和不可重试错误分类。
- 退避间隔计算。

`tests/Unit/DistributionSchemaMigrationTest.php` 应覆盖：

- 已存在旧分发表时，迁移能补齐当前代码需要的缺失列。

### 13.2 后续测试补充

渠道管理：

- 暂停渠道后，任务表单不再展示该渠道。
- 已暂停渠道不参与新文章入队。
- 重置 secret 后旧 key 不再是 active。

任务绑定：

- 编辑任务可以回填已绑定渠道。
- 提交非法渠道 ID 时不会写入 pivot。
- 取消全部渠道后，pivot 被清空。

分发任务：

- 批量重试失败记录。
- 失败类型摘要和错误分类展示。

UI：

- 任务列表后续增加绑定渠道数时补充对应断言。

## 14. 本次提交纳入决策

建议纳入下一次代码提交：

- 纳入 **文件地图** 中列出的 Phase 1/2 代码文件。
- 纳入本统一方案：`docs/distribution/unified-distribution-implementation-plan.md`。
- 可纳入 Word 输出：`docs/distribution/unified-distribution-implementation-plan.docx`，如果希望仓库直接保存可读交付件。
- 仅在未修改的情况下保留现有已跟踪文档 `docs/distribution/multi-platform-distribution-plan.md`；它可以继续作为长期研究稿存在。

建议不纳入下一次代码提交：

- `.longtask/`
- longtask 生成的骨架文档：`docs/AcceptanceCriteria.md`、`docs/Architecture.md`、`docs/Assumptions.md`、`docs/BenchmarkReport.md`、`docs/DecisionLog.md`、`docs/DefinitionOfDone.md`、`docs/DeliveryReport.md`、`docs/DeploymentReport.md`、`docs/Documentation.md`、`docs/EvalReport.md`、`docs/Implement.md`、`docs/LongTaskSummary.md`、`docs/OutOfScope.md`、`docs/Plan.md`、`docs/ProductBrief.md`、`docs/TechnologyDecision.md`、`docs/TestStrategy.md`、`docs/UIAudit.md`、`docs/UISpec.md`
- 单独的未跟踪草案 `docs/distribution/distribution-management-agent-plan.md`，除非项目明确希望保留历史草案。

## 15. 发布检查清单

- [ ] `vendor/bin/pint --dirty --format agent`
- [ ] `php artisan test --compact tests/Feature/AdminDistributionPageTest.php tests/Feature/AdminTasksPageTest.php tests/Feature/AdminArticlesPageTest.php`
- [ ] `php artisan route:list --except-vendor --path=distribution`
- [ ] `php artisan test --compact`
- [ ] `git diff --check`
- [ ] `git status --short --branch`
- [ ] 选择性暂存，避开 `.longtask/`、`.env`、storage/log/cache/upload 文件，以及 longtask 生成的骨架文档。
