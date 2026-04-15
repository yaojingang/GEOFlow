# API v1 接口设计稿

> [English version](API_V1_REFERENCE_DRAFT_en.md)

## 1. 文档定位

本文档是 `GEOFlow` 第一阶段正式 API 的接口设计稿。

它建立在以下前提上：

- 现有后台架构不重构
- 现有数据库业务模型不推翻
- `cron + queue + worker + ai_engine` 主链路保持不变
- API 作为一层新的机器接口，对 CLI 和后续 skill 提供稳定入口

对应总方案见：

- [API_CLI_PHASE1_PLAN.md](./API_CLI_PHASE1_PLAN.md)

---

## 2. 版本与路径

第一阶段统一走：

- `/api/v1`

示例：

- `POST /api/v1/auth/login`
- `GET /api/v1/catalog`
- `POST /api/v1/tasks`
- `POST /api/v1/articles/101/publish`

说明：

- 第一阶段只做 `v1`
- 路径版本化，避免以后 API 迭代影响 CLI

---

## 3. 鉴权设计

## 3.1 鉴权方式

采用 Bearer Token：

```http
Authorization: Bearer gf_xxxxxxxxxxxxxxxxx
```

### 首次登录

CLI 首次推荐通过管理员用户名和密码换取 token：

- `POST /api/v1/auth/login`

登录成功后，CLI 自动保存 token 到本地配置。

### 不采用的方式

第一阶段不采用：

- 后台管理员 session
- CSRF
- Cookie 登录态
- OAuth

## 3.2 Token 存储

新增表：`api_tokens`

建议字段：

```sql
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by_admin_id BIGINT DEFAULT NULL,
    last_used_at TIMESTAMP DEFAULT NULL,
    expires_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 约束

- 数据库存 hash，不存明文 token
- token 只在创建时展示一次
- `status` 只支持：
  - `active`
  - `revoked`

## 3.3 Scope 设计

第一阶段 scope：

- `catalog:read`
- `tasks:read`
- `tasks:write`
- `jobs:read`
- `articles:read`
- `articles:write`
- `articles:publish`

### Scope 到端点映射

- `GET /catalog` -> `catalog:read`
- `GET /tasks*` -> `tasks:read`
- `POST /tasks*` / `PATCH /tasks*` -> `tasks:write`
- `GET /jobs*` -> `jobs:read`
- `GET /articles*` -> `articles:read`
- `POST /articles` / `PATCH /articles*` -> `articles:write`
- `POST /articles/{id}/review` -> `articles:publish`
- `POST /articles/{id}/publish` -> `articles:publish`
- `POST /articles/{id}/trash` -> `articles:write`

---

## 4. 公共协议

## 4.1 请求头

```http
Authorization: Bearer gf_xxx
Content-Type: application/json
Accept: application/json
X-Request-Id: cli-optional-id
X-Idempotency-Key: optional-uuid
```

## 4.2 响应 envelope

成功：

```json
{
  "success": true,
  "data": {},
  "error": null,
  "meta": {
    "request_id": "req_123",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

失败：

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_failed",
    "message": "参数校验失败",
    "details": {
      "field_errors": {
        "prompt_id": "内容提示词不存在"
      }
    }
  },
  "meta": {
    "request_id": "req_123",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

## 4.3 HTTP 状态码

- `200` 查询成功
- `201` 创建成功
- `202` 已接受
- `400` 请求格式错误
- `401` 未认证
- `403` scope 不足
- `404` 资源不存在
- `409` 状态冲突
- `422` 业务校验失败
- `500` 服务异常

## 4.4 分页格式

```json
{
  "items": [],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 120,
    "total_pages": 6
  }
}
```

---

## 5. 数据来源与复用映射

## 5.1 任务相关

真实逻辑来源：

- [task-create.php](../../admin/task-create.php)
- [tasks.php](../../admin/tasks.php)
- [start_task_batch.php](../../admin/start_task_batch.php)
- [task_service.php](../../includes/task_service.php)
- [job_queue_service.php](../../includes/job_queue_service.php)

## 5.2 文章相关

真实逻辑来源：

- [article-create.php](../../admin/article-create.php)
- [article-edit.php](../../admin/article-edit.php)
- [article-view.php](../../admin/article-view.php)
- [articles.php](../../admin/articles.php)
- [articles-review.php](../../admin/articles-review.php)
- [functions.php](../../includes/functions.php#L380)

## 5.3 不纳入第一阶段的现有接口

- [title_generate_async.php](../../admin/title_generate_async.php)
- [url-import-start.php](../../admin/url-import-start.php)

---

## 6. 认证接口

## `POST /api/v1/auth/login`

### 用途

给 CLI 首次登录使用。

用户输入：

- 系统地址
- 管理员用户名
- 管理员密码

服务端验证成功后签发 API token，CLI 保存本地配置，后续业务请求继续走 Bearer Token。

### Scope

无，需要匿名访问。

### 请求体

```json
{
  "username": "admin",
  "password": "your-password"
}
```

### 成功响应

```json
{
  "token": "gf_xxx",
  "expires_at": null,
  "admin": {
    "id": 1,
    "username": "admin",
    "display_name": "系统超级管理员",
    "role": "super_admin",
    "status": "active"
  }
}
```

## 6.1 `GET /api/v1/catalog`

### 用途

给 CLI 获取所有常用资源的 ID。

避免：

- 在 CLI 里硬编码模型 ID
- 硬编码提示词 ID
- 硬编码分类和作者 ID

### Scope

- `catalog:read`

### 查询参数

无

### 返回结构

```json
{
  "models": [
    {
      "id": 1,
      "name": "OpenAI GPT-4.1",
      "model_id": "gpt-4.1",
      "model_type": "chat",
      "status": "active"
    }
  ],
  "prompts": [
    {
      "id": 2,
      "name": "默认内容提示词",
      "type": "content"
    }
  ],
  "title_libraries": [
    {
      "id": 3,
      "name": "AI 行业标题库",
      "title_count": 240
    }
  ],
  "knowledge_bases": [
    {
      "id": 4,
      "name": "品牌知识库"
    }
  ],
  "authors": [
    {
      "id": 5,
      "name": "系统作者"
    }
  ],
  "categories": [
    {
      "id": 6,
      "name": "人工智能",
      "slug": "ai"
    }
  ]
}
```

### 数据规则

- `models` 只返回 `status = active` 且 `model_type = chat` 的模型
- `prompts` 第一阶段优先返回 `type = content`
- `title_libraries` 返回标题计数
- `categories` 返回 `id + name + slug`

---

## 7. Tasks API

## 7.1 `GET /api/v1/tasks`

### Scope

- `tasks:read`

### 查询参数

- `page`
- `per_page`
- `status`
- `search`

### 返回字段

- `id`
- `name`
- `status`
- `schedule_enabled`
- `title_library_id`
- `prompt_id`
- `ai_model_id`
- `knowledge_base_id`
- `draft_limit`
- `publish_interval`
- `created_count`
- `published_count`
- `last_run_at`
- `next_run_at`
- `pending_jobs`
- `running_jobs`
- `updated_at`

### 数据来源

以 [tasks.php](../../admin/tasks.php) 的列表查询逻辑为主。

### 排序

- `created_at DESC`

---

## 7.2 `POST /api/v1/tasks`

### Scope

- `tasks:write`

### 用途

创建任务。

### 请求体

```json
{
  "name": "AI 周报任务",
  "title_library_id": 3,
  "prompt_id": 2,
  "ai_model_id": 1,
  "knowledge_base_id": 4,
  "author_id": 5,
  "image_library_id": null,
  "image_count": 0,
  "need_review": true,
  "publish_interval": 3600,
  "auto_keywords": true,
  "auto_description": true,
  "draft_limit": 10,
  "is_loop": false,
  "status": "paused",
  "category_mode": "smart",
  "fixed_category_id": null
}
```

### 字段规则

必填：

- `name`
- `title_library_id`
- `prompt_id`
- `ai_model_id`

可选：

- `knowledge_base_id`
- `author_id`
- `image_library_id`
- `image_count`
- `need_review`
- `publish_interval`
- `auto_keywords`
- `auto_description`
- `draft_limit`
- `is_loop`
- `status`
- `category_mode`
- `fixed_category_id`

### 校验规则

复用 [task-create.php](../../admin/task-create.php) 现有逻辑：

- 名称不能为空
- 标题库必须存在
- 提示词必须存在且 `type = content`
- AI 模型必须存在、已启用、且是 `chat`
- `category_mode = fixed` 时必须提供有效 `fixed_category_id`
- `publish_interval` 最小值建议仍按秒传输，服务端强制 `>= 60`

### 行为

- 插入 `tasks`
- 调用 `JobQueueService::initializeTaskSchedule()`
- 如果 `status = active`，初始化 `task_schedules`

### 响应

返回：

- `id`
- `name`
- `status`
- `schedule_enabled`
- `next_run_at`

### 幂等建议

若客户端带 `X-Idempotency-Key`，应按路由和请求体 hash 复用同一响应。

---

## 7.3 `GET /api/v1/tasks/{id}`

### Scope

- `tasks:read`

### 返回字段

- 任务基础字段
- `queue_summary`
- `article_summary`
- `last_run_at`
- `next_run_at`

### 推荐返回结构

```json
{
  "id": 12,
  "name": "AI 周报任务",
  "status": "active",
  "schedule_enabled": 1,
  "title_library_id": 3,
  "prompt_id": 2,
  "ai_model_id": 1,
  "knowledge_base_id": 4,
  "draft_limit": 10,
  "publish_interval": 3600,
  "queue_summary": {
    "pending_jobs": 1,
    "running_jobs": 0,
    "last_job_id": 88,
    "last_job_status": "pending"
  },
  "article_summary": {
    "draft_count": 3,
    "published_count": 9,
    "total_count": 18
  },
  "last_run_at": "2026-04-13 18:00:00",
  "next_run_at": "2026-04-13 19:00:00"
}
```

### 数据来源

主要参考 [tasks.php](../../admin/tasks.php) 的任务列表聚合查询。

---

## 7.4 `PATCH /api/v1/tasks/{id}`

### Scope

- `tasks:write`

### 可更新字段

- `name`
- `prompt_id`
- `ai_model_id`
- `knowledge_base_id`
- `author_id`
- `need_review`
- `publish_interval`
- `draft_limit`
- `status`
- `category_mode`
- `fixed_category_id`
- `image_library_id`
- `image_count`
- `auto_keywords`
- `auto_description`
- `is_loop`

### 规则

- 只允许局部更新
- 更新字段必须重新校验外键有效性
- 若更新 `status`，应走和后台一致的启停逻辑

### 设计建议

第一阶段里，`PATCH /tasks/{id}` 只负责字段更新。

显式动作仍走：

- `/start`
- `/stop`
- `/enqueue`

这样语义更稳定。

---

## 7.5 `POST /api/v1/tasks/{id}/start`

### Scope

- `tasks:write`

### 用途

激活任务，允许调度。

### 规则

复用 [start_task_batch.php](../../admin/start_task_batch.php) 和 [tasks.php](../../admin/tasks.php) 的现有语义：

- `status = active`
- `schedule_enabled = 1`
- `next_run_at = CURRENT_TIMESTAMP` 或最小合理时间

### 是否自动入队

第一阶段建议：

- `start` 只负责启动，不自动入队
- 手动触发执行单独走 `/enqueue`

原因：

- 对 CLI 来说，动作更清晰
- 避免“一次 start 既改状态又直接执行”的副作用过重

如果要兼容后台行为，也可以在请求体加：

```json
{
  "enqueue_now": false
}
```

默认 `false`。

---

## 7.6 `POST /api/v1/tasks/{id}/stop`

### Scope

- `tasks:write`

### 用途

暂停任务，取消 pending jobs。

### 规则

复用 [start_task_batch.php](../../admin/start_task_batch.php)：

- `status = paused`
- `schedule_enabled = 0`
- `next_run_at = NULL`
- 取消当前任务下 `pending` 的 job
- 不强杀 `running` 的 job

### 响应字段

- `id`
- `status`
- `schedule_enabled`
- `cancelled_jobs`
- `running_jobs`

---

## 7.7 `POST /api/v1/tasks/{id}/enqueue`

### Scope

- `tasks:write`

### 用途

立即把任务加入队列。

### 请求体

```json
{
  "job_type": "generate_article",
  "source": "cli_manual"
}
```

### 规则

底层复用：

- `JobQueueService::enqueueTaskJob()`

语义：

- 如果当前任务已有 `pending` 或 `running` job，返回 `409`
- 不允许重复入队

### 响应

- `task_id`
- `job_id`
- `status = pending`

---

## 7.8 `GET /api/v1/tasks/{id}/jobs`

### Scope

- `tasks:read`

### 查询参数

- `status`
- `limit`

### 返回字段

- `id`
- `task_id`
- `job_type`
- `status`
- `attempt_count`
- `max_attempts`
- `worker_id`
- `claimed_at`
- `finished_at`
- `error_message`
- `created_at`

### 用途

给 CLI 看某个任务最近的执行情况。

---

## 8. Jobs API

## 8.1 `GET /api/v1/jobs/{id}`

### Scope

- `jobs:read`

### 用途

查看单个 job 执行情况，供 CLI 轮询。

### 返回字段

- `id`
- `task_id`
- `job_type`
- `status`
- `attempt_count`
- `max_attempts`
- `worker_id`
- `claimed_at`
- `finished_at`
- `error_message`
- `payload`
- `task_run_summary`

### `task_run_summary`

建议包含：

- `article_id`
- `duration_ms`
- `meta`

数据来源：

- `job_queue`
- `task_runs`

### 响应示例

```json
{
  "id": 88,
  "task_id": 12,
  "job_type": "generate_article",
  "status": "completed",
  "attempt_count": 1,
  "max_attempts": 3,
  "worker_id": "host:12345",
  "claimed_at": "2026-04-13 20:01:00",
  "finished_at": "2026-04-13 20:01:12",
  "error_message": "",
  "payload": {
    "source": "cli_manual"
  },
  "task_run_summary": {
    "article_id": 101,
    "duration_ms": 12105,
    "meta": {
      "title": "AI 周报",
      "message": "ok"
    }
  }
}
```

---

## 9. Articles API

## 9.1 `GET /api/v1/articles`

### Scope

- `articles:read`

### 查询参数

- `page`
- `per_page`
- `task_id`
- `status`
- `review_status`
- `author_id`
- `search`

### 返回字段

- `id`
- `title`
- `slug`
- `status`
- `review_status`
- `task_id`
- `author_id`
- `category_id`
- `published_at`
- `created_at`
- `updated_at`

### 数据来源

参考 [articles.php](../../admin/articles.php) 的筛选逻辑。

### 默认条件

- `deleted_at IS NULL`

---

## 9.2 `POST /api/v1/articles`

### Scope

- `articles:write`

### 用途

直接通过 API 创建文章，适合：

- 本地 AI 已经生成好内容
- 本地 Markdown 已经准备好
- 只需要远程入库和发布

### 请求体

```json
{
  "title": "一篇新文章",
  "content": "# 一篇新文章\n\n这里是正文。",
  "excerpt": "这里是摘要。",
  "slug": null,
  "task_id": 12,
  "author_id": 5,
  "category_id": 6,
  "keywords": "AI,工作流",
  "meta_description": "文章摘要",
  "status": "draft",
  "review_status": "pending",
  "auto_generate_slug": true
}
```

### 校验规则

复用 [article-create.php](../../admin/article-create.php)：

- `title` 必填
- `content` 必填
- 若未传 `excerpt`，自动截取
- 若 `auto_generate_slug = true` 或 `slug` 为空，服务端生成唯一 slug
- 若传 `slug`，也要确保唯一

### 工作流规则

必须经过：

- `normalize_article_workflow_state()`

因此：

- 不允许写出非法组合
- 如果请求 `status = published` 且 `review_status = pending`
- 服务端必须收敛为合法状态

### 默认值

- `status` 默认 `draft`
- `review_status` 默认 `pending`
- `is_ai_generated` 第一阶段建议允许显式传入，否则默认 `0`

### 响应

返回：

- `id`
- `title`
- `slug`
- `status`
- `review_status`
- `published_at`

---

## 9.3 `GET /api/v1/articles/{id}`

### Scope

- `articles:read`

### 返回字段

- 文章基础字段
- `task_name`
- `author_name`
- `category_name`
- `images`

### 数据来源

参考：

- [article-view.php](../../admin/article-view.php)

`images` 来自：

- `article_images`
- `images`

---

## 9.4 `PATCH /api/v1/articles/{id}`

### Scope

- `articles:write`

### 可更新字段

- `title`
- `content`
- `excerpt`
- `keywords`
- `meta_description`
- `category_id`
- `author_id`
- `slug`
- `status`
- `review_status`

### 规则

- 若标题改变且未显式传 `slug`，可自动重新生成唯一 slug
- 若传 `status` / `review_status`，必须重新走 `normalize_article_workflow_state()`
- 若只更新文案字段，不应无故改动发布状态

### 数据来源

参考：

- [article-edit.php](../../admin/article-edit.php)

---

## 9.5 `POST /api/v1/articles/{id}/review`

### Scope

- `articles:publish`

### 用途

更新审核结果。

### 请求体

```json
{
  "review_status": "approved",
  "review_note": "内容通过，可发布"
}
```

### 允许值

- `pending`
- `approved`
- `rejected`
- `auto_approved`

### 行为

复用：

- [articles-review.php](../../admin/articles-review.php)
- [functions.php](../../includes/functions.php#L380)

具体规则：

- 取当前文章状态
- 若 `review_status` 为 `approved` 或 `auto_approved`
- 且所属任务 `need_review = 0` 或审核状态为 `auto_approved`
- 则 `desiredStatus = published`
- 最后统一走 `normalize_article_workflow_state()`
- 写回 `articles`
- 插入 `article_reviews`

### 响应

返回：

- `id`
- `status`
- `review_status`
- `published_at`

---

## 9.6 `POST /api/v1/articles/{id}/publish`

### Scope

- `articles:publish`

### 用途

显式发布文章。

### 请求体

可为空：

```json
{}
```

### 核心规则

不能绕过工作流。

服务端应：

1. 读取当前文章
2. 取当前 `review_status`
3. 以 `desiredStatus = published`
4. 调 `normalize_article_workflow_state()`

### 注意点

如果当前审核状态是：

- `pending`
- `rejected`

那么是否允许发布有两个设计选择：

#### 方案 A：严格模式

- 返回 `409`
- 要求先走 `/review`

#### 方案 B：自动收敛模式

- 自动把 `review_status` 收敛为 `approved`

第一阶段建议采用 **严格模式**。

原因：

- 和后台审核逻辑更一致
- 避免 CLI 在不知情时跳过审核流程

### 推荐错误码

- `article_not_publishable`

---

## 9.7 `POST /api/v1/articles/{id}/trash`

### Scope

- `articles:write`

### 用途

软删除文章。

### 行为

- 设置 `deleted_at = CURRENT_TIMESTAMP`

### 数据来源

参考：

- [articles.php](../../admin/articles.php)

### 响应

```json
{
  "id": 101,
  "trashed": true
}
```

---

## 10. 幂等设计

## 10.1 第一阶段推荐支持的写接口

建议对以下接口支持 `X-Idempotency-Key`：

- `POST /tasks`
- `POST /tasks/{id}/enqueue`
- `POST /articles`
- `POST /articles/{id}/publish`

## 10.2 最小实现方式

新增表：

```sql
CREATE TABLE IF NOT EXISTS api_idempotency_keys (
    id BIGSERIAL PRIMARY KEY,
    idempotency_key VARCHAR(120) NOT NULL,
    route_key VARCHAR(120) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    response_body TEXT NOT NULL,
    response_status INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (idempotency_key, route_key)
);
```

### 行为

- 相同 `idempotency_key + route_key + request_hash` 直接复用已有响应
- 相同 `idempotency_key + route_key` 但 `request_hash` 不同，返回 `409`

---

## 11. 路由与代码结构建议

## 11.1 新增文件

- `api/v1/index.php`
- `includes/api_auth.php`
- `includes/api_request.php`
- `includes/api_response.php`
- `includes/api_token_service.php`
- `includes/catalog_service.php`
- `includes/task_lifecycle_service.php`
- `includes/article_service.php`

## 11.2 Dispatcher 建议

`index.php` 内部按：

- HTTP 方法
- 路径段
- scope

分发到对应 handler。

### 推荐结构

```php
GET    /api/v1/catalog

GET    /api/v1/tasks
POST   /api/v1/tasks
GET    /api/v1/tasks/{id}
PATCH  /api/v1/tasks/{id}
POST   /api/v1/tasks/{id}/start
POST   /api/v1/tasks/{id}/stop
POST   /api/v1/tasks/{id}/enqueue
GET    /api/v1/tasks/{id}/jobs

GET    /api/v1/jobs/{id}

GET    /api/v1/articles
POST   /api/v1/articles
GET    /api/v1/articles/{id}
PATCH  /api/v1/articles/{id}
POST   /api/v1/articles/{id}/review
POST   /api/v1/articles/{id}/publish
POST   /api/v1/articles/{id}/trash
```

---

## 12. 和 CLI 的对应关系

第一阶段 CLI 建议只封装核心操作。

### 任务相关

```bash
geoflow catalog
geoflow task list --status active
geoflow task create --json task.json
geoflow task get 12
geoflow task update 12 --json patch.json
geoflow task start 12
geoflow task stop 12
geoflow task enqueue 12
geoflow task jobs 12
geoflow job get 88
```

### 文章相关

```bash
geoflow article list --task-id 12 --status draft
geoflow article create --title "标题" --content-file ./article.md --task-id 12 --category-id 6
geoflow article get 101
geoflow article update 101 --content-file ./article.md
geoflow article review 101 --status approved --note "通过"
geoflow article publish 101
geoflow article trash 101
```

### 关键说明

CLI 不应该碰：

- 数据库连接
- 后台 session
- 后台表单页面

CLI 只调 `/api/v1`。

---

## 13. 第一轮开发顺序

## 13.1 第一步

先做 API 基础设施：

- token 鉴权
- request 解析
- response 输出
- router 接入

## 13.2 第二步

做 `catalog`

原因：

- 简单
- 可快速验证 token、路由、JSON 协议全链路

## 13.3 第三步

做 `tasks` 和 `jobs`

原因：

- 这是 CLI 自动创建任务和批量生成的基础

## 13.4 第四步

做 `articles`

原因：

- 需要更谨慎处理工作流与 slug 逻辑

---

## 14. 第一轮测试建议

## 14.1 接口级测试

至少要覆盖：

- 未认证返回 `401`
- 错误 scope 返回 `403`
- 参数错误返回 `422`
- 资源不存在返回 `404`
- 重复入队返回 `409`

## 14.2 业务级测试

### 任务

- 创建暂停任务
- 启动任务
- 手动入队
- worker 正常消费
- 查询 job 状态

### 文章

- 创建草稿
- 更新标题和内容
- 自动生成或更新 slug
- 审核通过
- 发布
- 软删除

### 工作流一致性

重点检查：

- `pending/rejected` 不会和 `published` 共存
- `published_at` 自动收敛
- `article_reviews` 正确写入

---

## 15. 第一阶段明确不做

以下能力不进入本轮开发：

- URL 导入 API
- 标题异步生成 API
- 图片上传 API
- 知识库上传 API
- 批量文件导入 API
- Webhook
- OpenAPI 页面
- SDK

原因：

- 这些能力当前不是统一服务层模型
- 容易拖慢第一轮落地

---

## 16. 结论

这份接口设计稿的核心不是“重新定义系统”，而是把现有后台里已经成立的业务逻辑正规化、接口化。

第一版开发时应坚持两个底线：

1. 新 API 必须复用现有业务规则，尤其是任务启停逻辑和文章工作流逻辑。
2. 新 API 必须与后台页面解耦，不能让 CLI 去依赖 session 型后台接口。

只要这两个底线守住，第一轮开发就能在低风险前提下，把 CLI 和后续 skill 的基础稳定打出来。
