# API / CLI 第一阶段实施方案

> [English version](API_CLI_PHASE1_PLAN_en.md)

## 1. 文档目的

本文档用于确定 `GEOFlow` 第一阶段外部接口体系的正式实施方案。

目标不是重构现有系统，而是在 **不破坏现有后台、调度器、队列、worker、数据库语义** 的前提下，新增一套可供本地 CLI 和后续 skill 调用的正式 API。

本文档重点回答四个问题：

1. 当前系统真实的后台架构和业务链路是什么。
2. 现有页面和所谓“接口”里，哪些逻辑可以复用，哪些不能直接对外。
3. 第一阶段 API 应该做到什么程度，边界在哪里。
4. 第一阶段落地时，具体要新增哪些文件、轻改哪些文件、明确不改哪些部分。

---

## 2. 第一阶段的最终目标

第一阶段只要求打通两条主链路：

### 2.1 任务链路

让本地 CLI 能够：

- 获取模型、提示词、标题库、作者、分类等基础资源
- 创建任务
- 查看任务
- 更新任务
- 启动任务
- 停止任务
- 手动把任务加入队列
- 查询 job 执行状态

### 2.2 文章链路

让本地 CLI 能够：

- 查询文章
- 直接上传文章草稿
- 更新文章
- 审核文章
- 发布文章
- 软删除文章

这两条链路覆盖了后续 skill 最重要的两种使用方式：

1. 本地创建任务，远程触发系统自动生成文章
2. 本地先生成文章，再通过远程接口直接发布到系统

---

## 3. 第一阶段的原则

### 3.1 不动现有主架构

第一阶段不改现有主运行模型：

- 后台页面仍用于人工操作
- `cron` 仍负责调度
- `job_queue` 仍负责排队
- `worker` 仍负责执行 AI 任务
- PostgreSQL 仍是唯一事实来源

相关核心文件：

- `bin/cron.php`
- `bin/worker.php`
- `includes/job_queue_service.php`
- `includes/ai_engine.php`

### 3.2 不推翻现有数据库语义

第一阶段不重构已有核心表：

- `tasks`
- `articles`
- `article_reviews`
- `ai_models`
- `prompts`
- `title_libraries`
- `knowledge_bases`
- `job_queue`
- `task_runs`

允许新增的数据库对象只用于 API 基础设施，不改变业务真相源：

- `api_tokens`
- 可选：`api_idempotency_keys`

### 3.3 不直接复用后台 Ajax 端点

第一阶段不能让 CLI 直接调用这些现有页面配套接口：

- `admin/start_task_batch.php`
- `admin/get_task_status.php`
- `admin/title_generate_async.php`
- `admin/url-import-start.php`

原因：

- 这些端点依赖管理员 session
- 依赖 CSRF
- 依赖后台页面上下文
- 有的逻辑仍是请求内同步执行
- 不具备稳定的 scope / token / 幂等 / 版本化规范

因此，第一阶段应该复用的是 **现有业务逻辑**，不是现有后台接口文件本身。

### 3.4 先做 API 适配层，再做 CLI，再做 skill

顺序必须是：

1. 先整理清楚服务端 API 层
2. 再做本地 CLI
3. 最后让 skill 调 CLI

原因很简单：

- skill 不应该承载业务状态机
- CLI 不应该绕过服务端直接碰数据库
- API 才是后续跨端复用的稳定边界

---

## 4. 当前系统的真实后台架构

### 4.1 页面层

当前后台是典型的“页面即控制器”模式。

每个后台页面通常同时承担这些职责：

- 管理员鉴权
- CSRF 校验
- 处理 POST
- 直接查库
- 拼接页面输出

典型文件：

- `admin/task-create.php`
- `admin/tasks.php`
- `admin/articles.php`
- `admin/articles-review.php`
- `admin/article-create.php`
- `admin/article-edit.php`
- `admin/article-view.php`

这是现状，不是问题本身。第一阶段不要求把后台改成 MVC。

### 4.2 数据层

后台运行时数据库由 `database_admin.php` 负责建表和补 schema：

- `includes/database_admin.php`

核心业务表包括：

- `tasks`
- `articles`
- `article_reviews`
- `task_schedules`
- `job_queue`
- `task_runs`
- `worker_heartbeats`
- `ai_models`
- `prompts`
- `authors`
- `categories`

### 4.3 调度与执行层

系统已经不是“后台点击后直接同步生成文章”的模式，而是标准异步链路：

1. 后台创建任务
2. 调度器扫描活跃任务
3. job 进入队列
4. worker 领取 job
5. AI 引擎生成文章
6. 文章进入草稿 / 审核 / 发布流程

关键文件：

- `bin/cron.php`
- `includes/job_queue_service.php`
- `bin/worker.php`
- `includes/ai_engine.php`

这条链路已经是第一阶段 API 的最大复用基础。

---

## 5. 当前和 API 最相关的业务现状

## 5.1 任务创建

当前任务创建页面：

- `admin/task-create.php`

现有行为：

- 校验 `task_name`
- 校验 `title_library_id`
- 校验 `prompt_id` 必须是 `content`
- 校验 `ai_model_id` 必须是启用的 chat 模型
- 允许配置：
  - 标题库
  - 图片库 / 配图数量
  - 内容提示词
  - AI 模型
  - 审核策略
  - 发布间隔
  - 作者
  - 关键词 / 描述自动生成
  - 草稿上限
  - 是否循环
  - 状态
  - 知识库
  - 分类策略
- 插入 `tasks`
- 调用 `JobQueueService::initializeTaskSchedule`
- 活跃任务会插入 `task_schedules`

这说明：

- API 创建任务时，应该直接复用这里的校验规则
- 不应该重新定义另一套“任务字段语义”

## 5.2 任务启停与手动入队

当前有两套相关逻辑：

1. 页面内切换状态：
   `admin/tasks.php`

2. JSON 端点手动启停 / 入队：
   `admin/start_task_batch.php`

现有行为：

- 启动时：
  - 任务状态改为 `active`
  - `schedule_enabled = 1`
  - `next_run_at = CURRENT_TIMESTAMP`
  - 调用 `enqueueTaskJob`
- 停止时：
  - 任务状态改为 `paused`
  - `schedule_enabled = 0`
  - `next_run_at = NULL`
  - 取消 `pending` 的 job
  - `running` 的 job 不强杀，等待自然退出

这意味着：

- 第一阶段 API 里的 `start`、`stop`、`enqueue` 都应复用这套规则
- 不要发明“停止任务就立即杀死 worker”这种新语义

## 5.3 任务执行状态

当前 `tasks.php` 页面已经展示出非常适合 API 暴露的数据：

- 任务基础信息
- `pending_jobs`
- `running_jobs`
- `batch_status`
- `last_run_at`
- `next_run_at`
- `task_runs` 成功 / 失败计数
- `worker_heartbeats`
- 最近 job

因此：

- 第一阶段 `GET /tasks/{id}` 和 `GET /tasks/{id}/jobs` 可以直接以这个页面的数据模型为基础
- 不需要重新定义新的“监控视图”

## 5.4 文章创建和编辑

当前文章手动创建页面：

- `admin/article-create.php`

当前文章编辑页面：

- `admin/article-edit.php`

现有行为：

- 标题、内容必填
- 自动生成唯一 slug
- 摘要可自动截取
- 可设置分类、作者、关键词、Meta 描述
- 可直接指定 `status`
- 可直接指定 `review_status`
- 但最终一定经过 `normalize_article_workflow_state`

这说明：

- API 创建 / 更新文章时，必须复用统一 slug 生成策略
- 必须复用统一工作流收敛逻辑
- 不能允许 CLI 绕过工作流规则直接写出冲突状态

## 5.5 文章审核与发布

当前相关文件：

- `admin/articles-review.php`
- `admin/article-view.php`
- `admin/articles.php`
- `includes/functions.php`

核心规则在：

- `normalize_article_workflow_state()`

现有工作流特征：

- `pending` / `rejected` 会收敛到 `draft`
- `published` 不能和 `pending` 共存
- `auto_approved` 会收敛为发布状态
- `published_at` 由工作流自动同步
- 审核记录会写入 `article_reviews`

额外规则：

- 如果来源任务 `need_review = 0`
- 审核通过后文章可直接进入 `published`

因此：

- 第一阶段 API 不应把“发布”和“审核”当成彼此独立、互不约束的字段更新
- 必须通过服务层统一处理

---

## 6. 当前系统里哪些“接口化能力”可以借鉴

当前系统虽然没有正式公共 API，但已经有一些可借鉴的接口化倾向：

### 6.1 统一 JSON 响应工具

- `includes/config.php`

现有 `json_response()` 可以继续复用，但需要在 API 层之上再封一层标准 envelope。

### 6.2 队列与任务执行状态已经结构化

- `job_queue`
- `task_runs`
- `worker_heartbeats`

这些表已经非常适合给机器调用。

### 6.3 后台页面已经形成了真实的字段与状态含义

这意味着 API 不需要再猜测业务规则，而是可以把页面里已经稳定的行为提取成服务层。

---

## 7. 当前系统里哪些部分不适合作为正式 API 复用

## 7.1 session 型“异步接口”

- `admin/title_generate_async.php`

问题：

- 状态保存在 `$_SESSION`
- 依赖浏览器登录态
- 不适合 CLI
- 不适合 worker / 多实例

第一阶段明确不纳入正式 API。

## 7.2 请求内同步执行的采集接口

- `admin/url-import-start.php`

问题：

- 形式上返回 JSON
- 实际上请求内直接调用 `run_url_import_pipeline()`
- 不是标准异步任务模式

第一阶段明确不纳入。

## 7.3 依赖后台 session / CSRF 的 Ajax 端点

例如：

- `start_task_batch.php`
- `get_task_status.php`

这些接口虽然能返回 JSON，但它们的身份模型是后台页面配套，不适合给 CLI 直接对接。

---

## 8. 第一阶段建议新增的 API 体系

第一阶段建议单独新增：

- `/api/v1`

采用版本化、机器鉴权、统一返回结构。

## 8.1 资源边界

第一阶段只开放 4 类资源：

- `catalog`
- `tasks`
- `jobs`
- `articles`

这已经足够支持 CLI 和后续 skill 的第一轮闭环。

## 8.2 推荐端点

### Catalog

- `GET /api/v1/catalog`

### Tasks

- `GET /api/v1/tasks`
- `POST /api/v1/tasks`
- `GET /api/v1/tasks/{id}`
- `PATCH /api/v1/tasks/{id}`
- `POST /api/v1/tasks/{id}/start`
- `POST /api/v1/tasks/{id}/stop`
- `POST /api/v1/tasks/{id}/enqueue`
- `GET /api/v1/tasks/{id}/jobs`

### Jobs

- `GET /api/v1/jobs/{id}`

### Articles

- `GET /api/v1/articles`
- `POST /api/v1/articles`
- `GET /api/v1/articles/{id}`
- `PATCH /api/v1/articles/{id}`
- `POST /api/v1/articles/{id}/review`
- `POST /api/v1/articles/{id}/publish`
- `POST /api/v1/articles/{id}/trash`

---

## 9. 鉴权方案

第一阶段不能复用后台管理员 session。

应新增 Bearer Token 机制。

### 9.1 新增表：`api_tokens`

建议字段：

- `id`
- `name`
- `token_hash`
- `scopes`
- `status`
- `created_by_admin_id`
- `last_used_at`
- `expires_at`
- `created_at`
- `updated_at`

设计原则：

- 只存 hash，不存明文 token
- token 只展示一次
- scope 必须细分
- 允许撤销

### 9.2 第一阶段 scope

- `catalog:read`
- `tasks:read`
- `tasks:write`
- `jobs:read`
- `articles:read`
- `articles:write`
- `articles:publish`

### 9.3 不做的事

第一阶段不做：

- OAuth
- session 复用
- 多租户
- 用户级细粒度数据隔离

---

## 10. 请求与响应协议

### 10.1 请求头

```http
Authorization: Bearer gf_xxxxxxxxx
Content-Type: application/json
Accept: application/json
X-Request-Id: optional
X-Idempotency-Key: optional
```

### 10.2 统一成功响应

```json
{
  "success": true,
  "data": {},
  "error": null,
  "meta": {
    "request_id": "req_xxx",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

### 10.3 统一失败响应

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_failed",
    "message": "参数校验失败"
  },
  "meta": {
    "request_id": "req_xxx",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

### 10.4 HTTP 状态码约定

- `200` 查询成功
- `201` 创建成功
- `202` 已接受
- `400` 参数错误
- `401` 未认证
- `403` 权限不足
- `404` 资源不存在
- `409` 状态冲突或幂等冲突
- `422` 业务校验失败
- `500` 服务器错误

---

## 11. 第一阶段服务层设计

第一阶段不要直接在 API 入口里堆 SQL。

应该补三层最小服务。

## 11.1 `CatalogService`

职责：

- 读取模型
- 读取提示词
- 读取标题库
- 读取知识库
- 读取作者
- 读取分类

数据来源：

- `ai_models`
- `prompts`
- `title_libraries`
- `knowledge_bases`
- `authors`
- `categories`

说明：

- 这是最简单的一层
- 同时也是 CLI 初始化最先要用的一层

## 11.2 `TaskLifecycleService`

职责：

- 创建任务
- 更新任务
- 查看任务列表
- 查看任务详情
- 启动任务
- 停止任务
- 手动入队
- 查询任务关联 jobs

应复用：

- `admin/task-create.php`
- `admin/tasks.php`
- `admin/start_task_batch.php`
- `includes/task_service.php`
- `includes/job_queue_service.php`

说明：

- `TaskService` 可以作为底层基础
- 但当前它还不够完整，不足以直接成为 API 服务层
- 第一阶段更稳的做法是新增 `TaskLifecycleService` 作为更贴近业务行为的 facade

## 11.3 `ArticleService`

职责：

- 查询文章列表
- 查询文章详情
- 创建文章
- 更新文章
- 审核文章
- 发布文章
- 删除文章

应复用：

- `admin/article-create.php`
- `admin/article-edit.php`
- `admin/article-view.php`
- `admin/articles.php`
- `admin/articles-review.php`
- `includes/functions.php`

特别要求：

- 必须复用 `normalize_article_workflow_state()`
- 必须复用现有 slug 生成与唯一性策略
- 审核变更要继续写入 `article_reviews`

---

## 12. API 入口层设计

第一阶段不建议做一堆分散的 `api/*.php` 文件。

建议先做单入口：

- `api/v1/index.php`

配合基础组件：

- `includes/api_auth.php`
- `includes/api_request.php`
- `includes/api_response.php`
- `includes/api_token_service.php`

### 12.1 单入口的好处

- 路由规则统一
- 鉴权统一
- 错误输出统一
- 更适合以后写 OpenAPI 文档
- 更适合 CLI/skill 的长期维护

### 12.2 Router 的最小改动

只需要在：

- `router.php`

增加一条 `/api/v1/*` 路由映射即可。

这属于低侵入接入。

---

## 13. 第一阶段数据库变更建议

## 13.1 必增：`api_tokens`

用途：

- 让 CLI 和后续 skill 有正式机器凭证

这是第一阶段唯一真正有必要新增的业务外围表。

## 13.2 可选：`api_idempotency_keys`

用途：

- 防止 CLI 重试时重复创建任务或文章

建议：

- 如果第一阶段要追求更稳，可以加
- 如果想先快落地，可以先不做

## 13.3 明确不修改的表

第一阶段不重构这些表结构：

- `tasks`
- `articles`
- `article_reviews`
- `job_queue`
- `task_runs`
- `worker_heartbeats`

允许增加索引，但不改变现有字段业务含义。

---

## 14. 第一阶段建议新增的文件

### API 入口与基础设施

- `api/v1/index.php`
- `includes/api_auth.php`
- `includes/api_request.php`
- `includes/api_response.php`
- `includes/api_token_service.php`

### 业务服务层

- `includes/catalog_service.php`
- `includes/task_lifecycle_service.php`
- `includes/article_service.php`

### 文档

- `API_CLI_PHASE1_PLAN.md`
- 后续可补：`API_V1_REFERENCE.md`

---

## 15. 第一阶段需要轻改的现有文件

## 15.1 Router

- `router.php`

改动：

- 增加 `/api/v1/*` 路由映射

## 15.2 数据库初始化

- `includes/database_admin.php`

改动：

- 新增 `api_tokens`
- 可选新增 `api_idempotency_keys`

## 15.3 TaskService

- `includes/task_service.php`

改动：

- 只做必要增强
- 不要求重构成完美服务层

建议增强：

- 把任务创建 / 更新的可允许字段补齐到和后台页面一致
- 对 `knowledge_base_id`、`category_mode`、`fixed_category_id` 的支持补全

---

## 16. 第一阶段明确不要动的地方

以下内容第一阶段明确不纳入：

### 16.1 不动 worker 主循环

- `bin/worker.php`

### 16.2 不动 cron 调度策略

- `bin/cron.php`

### 16.3 不动 AI 引擎主生成逻辑

- `includes/ai_engine.php`

### 16.4 不动 URL 导入体系

- `admin/url-import-start.php`
- `admin/url-import-status.php`
- `admin/url-import-commit.php`

### 16.5 不动标题异步生成体系

- `admin/title_generate_async.php`

### 16.6 不重构后台页面

第一阶段后台页面继续照常工作。

API 是外挂层，不是后台替代层。

---

## 17. 第一阶段分步实施建议

## 17.1 步骤一：API 基础设施

交付内容：

- Bearer Token 校验
- 统一请求解析
- 统一 JSON 输出
- request id

验收标准：

- 不带 token 访问 `/api/v1/catalog` 返回 `401`
- 带 token 可正常返回

## 17.2 步骤二：Catalog API

交付内容：

- `GET /api/v1/catalog`

验收标准：

- CLI 可以拿到模型、提示词、标题库、知识库、作者、分类 ID

## 17.3 步骤三：任务 API

交付内容：

- 任务列表
- 任务创建
- 任务详情
- 任务更新
- 启动 / 停止 / 入队
- 任务 jobs 查询
- 单 job 查询

验收标准：

- 本地 CLI 可以完整跑通：
  - 创建任务
  - 启动任务
  - 手动入队
  - 查询 job
  - 等待 worker 完成

## 17.4 步骤四：文章 API

交付内容：

- 查询文章
- 创建文章
- 更新文章
- 审核文章
- 发布文章
- 删除文章

验收标准：

- 本地一篇 Markdown 内容可通过 API 进入系统
- 能被审核 / 发布
- 前台可访问

## 17.5 步骤五：对接 CLI

第一阶段 API 完成后，再开始 CLI。

建议命令结构：

- `geoflow catalog`
- `geoflow task create`
- `geoflow task start`
- `geoflow task enqueue`
- `geoflow job get`
- `geoflow article create`
- `geoflow article publish`

---

## 18. 第一阶段主要风险

## 18.1 风险一：把 API 写成“复制版后台页面”

这是最容易踩的坑。

错误做法：

- 直接复制 `admin/*.php` 里的 SQL 和表单逻辑

问题：

- 后台改一处，API 忘一处
- 长期漂移
- 业务规则双份维护

应对：

- 先补服务层
- API 只调服务层

## 18.2 风险二：状态机被绕过

最危险的是文章工作流。

如果 API 允许随便写：

- `status = published`
- `review_status = pending`

就会直接破坏现有系统状态语义。

应对：

- 文章所有状态更新必须统一收敛到 `normalize_article_workflow_state()`

## 18.3 风险三：CLI 直接依赖后台接口

短期看起来快，长期会非常脆。

原因：

- session
- CSRF
- 登录态过期
- 页面上下文耦合

应对：

- 第一阶段就建立正式 `/api/v1`

## 18.4 风险四：范围失控

最容易把第一阶段越做越大：

- URL 导入
- 标题生成
- 图片上传
- 知识库上传

这些都不应进入第一阶段。

应对：

- 第一阶段只做任务和文章两条主链

---

## 19. 第一阶段验收口径

第一阶段完成时，至少要满足：

1. 系统新增一套正式 `/api/v1` 入口
2. 使用 Bearer Token，不依赖后台 session
3. 能稳定查询 catalog
4. 能通过 API 创建、启动、停止、入队任务
5. 能通过 API 查询 job 状态
6. 能通过 API 创建、更新、审核、发布文章
7. 文章工作流仍与后台页面保持一致
8. 现有后台页面功能不受影响
9. `cron + queue + worker` 主链路不受影响

---

## 20. 对后续 CLI / skill 的意义

第一阶段 API 做完后，CLI 和 skill 会变得简单很多。

### CLI 负责

- 命令组织
- 参数解析
- 本地文件读取
- 人类操作体验

### API 负责

- 鉴权
- 业务校验
- 状态机
- 数据持久化

### skill 负责

- 调用 CLI
- 组合一条操作链
- 把复杂流程包装成能力

这样后续的 skill 不需要理解：

- 数据库结构
- session
- 后台页面逻辑
- 文章工作流细节

---

## 21. 结论

第一阶段最合理的路线不是“重构整个系统”，也不是“直接调用现有后台 JSON 文件”。

正确路线是：

1. 保持现有后台、队列、worker、数据库主模型不变
2. 在外围新增一层正式 API 适配层
3. API 复用现有任务和文章业务规则
4. 先打通任务链路和文章链路
5. API 稳定后再实现 CLI
6. 最后基于 CLI 封装 skill

这条路线改动最小、风险最低，也最符合当前项目已经形成的架构现实。
