# GEOFlow → Laravel 12 迁移进度

对照 Cursor 计划：`geoflow_全量_laravel_迁移_15fe9db3`（本地 `.cursor/plans/`）。

**原则**：分批验收；本文件与仓库同步，完成一项勾一项。

**当前策略**：优先后端（API、服务、契约测试、运维相关）；**前台 Blade + Web 路由（批次 3）暂缓**。

## 批次总览

| 批次 | 内容 | 状态 |
|------|------|------|
| 1 | 地基：配置、`.env`、目录占位、migrations + 核心模型 | 基本完成（Composer PSR-4 扩展见下） |
| 2 | API v1 + 契约测试 | 已完成（见批次 2 明细） |
| 3 | 前台 Blade + 路由 | 未开始（**后排期**） |
| 4 | Filament 5.x 后台 | 未开始 |
| 5 | Redis + Horizon + Job + 调度 | 未开始 |
| 6 | 加固（日志、安全、SEO、测试、部署） | 未开始 |

## 批次 1 明细

- [x] `config/geoflow.php` 与 `.env.example` 中的 GEOFlow 变量
- [x] `app/Services/GeoFlow/` 服务类（Catalog / TaskLifecycle / Article / JobQueue 等）
- [x] 首批数据库：`admins`、`site_settings`
- [x] 业务全量表：`2026_04_18_120000_geoflow_legacy_schema`（PostgreSQL；含 `job_queue`）
- [x] `knowledge_chunks.embedding_vector vector(3072)`（ANN 索引限制见迁移注释）
- [x] Eloquent 模型：`app/Models` 下业务表模型与主要关联
- [x] PHPUnit 用 SQLite：`2026_04_18_120002_sqlite_geoflow_minimal_for_testing`（仅 `APP_ENV=testing` + sqlite，供 API 契约测）
- [ ] Composer / PSR-4 按需扩展（迁入 `includes` 时）

## 批次 2 明细（API v1，对齐 [`bak/api/v1/index.php`](bak/api/v1/index.php)）

### 2.1 基础设施

- [x] 在 Laravel 注册 **`/api/v1`**（`routes/api.php` + 框架 `api` 前缀）。
- [x] **统一响应信封**：`success` / `data` / `error` / `meta.request_id` / `meta.timestamp`（[`ApiResponse`](app/Support/ApiResponse.php)）。
- [x] **Request ID**：中间件 `api.request_id`，透传或生成 `X-Request-Id`。
- [x] **异常映射**：`ApiException` → 约定 HTTP + `error.code`；**其它未捕获异常**在 `api/*` 下 → 500 + `internal_error` + 日志（不落栈给客户端），见 `bootstrap/app.php`。

### 2.2 认证与权限

- [x] **Bearer Token**：`api.auth` + [`ApiTokenService`](app/Services/Api/ApiTokenService.php)。
- [x] **Scope 中间件**：`api.scope:*`（`catalog:read`、`tasks:*`、`jobs:read`、`articles:*` 等）。
- [x] **公开路由**：`POST /auth/login`，[`ApiAdminAuthService::login`](app/Services/Api/ApiAdminAuthService.php)。

### 2.3 幂等（写操作）

- [x] **`X-Idempotency-Key`** + `route_key` + body hash，冲突 409（[`IdempotencyService`](app/Services/Api/IdempotencyService.php)）。
- [x] 命中 `api_idempotency_keys` 则直接返回缓存 JSON + 状态码。
- [x] 写路由幂等与遗留一致（任务/文章相关 POST、PATCH）。

### 2.4 路由与 Scope 一览（均带前缀 `api/v1`）

| 方法 | 路径 | Scope | 幂等 | 说明 |
|------|------|--------|------|------|
| POST | `/auth/login` | — | 否 | 管理员登录，发 token |
| GET | `/catalog` | `catalog:read` | 否 | 目录/元数据 |
| GET | `/tasks` | `tasks:read` | 否 | `page`、`per_page`、`status`、`search` |
| POST | `/tasks` | `tasks:write` | 是 | 创建 **201** |
| GET | `/tasks/{id}` | `tasks:read` | 否 | |
| PATCH | `/tasks/{id}` | `tasks:write` | 是 | |
| POST | `/tasks/{id}/start` | `tasks:write` | 是 | `enqueue_now` |
| POST | `/tasks/{id}/stop` | `tasks:write` | 是 | |
| POST | `/tasks/{id}/enqueue` | `tasks:write` | 是 | **201** |
| GET | `/tasks/{id}/jobs` | `tasks:read` | 否 | `status`、`limit` |
| GET | `/jobs/{id}` | `jobs:read` | 否 | |
| GET | `/articles` | `articles:read` | 否 | 多条件筛选 |
| POST | `/articles` | `articles:write` | 是 | **201** |
| GET | `/articles/{id}` | `articles:read` | 否 | |
| PATCH | `/articles/{id}` | `articles:write` | 是 | |
| POST | `/articles/{id}/review` | `articles:publish` | 是 | |
| POST | `/articles/{id}/publish` | `articles:publish` | 是 | |
| POST | `/articles/{id}/trash` | `articles:write` | 是 | |

### 2.5 业务实现方式

- [x] `CatalogGeoFlowService` / `TaskLifecycleService` / `ArticleGeoFlowService` / `JobQueueService`（Eloquent + 少量 SQL 片段）。
- [x] 入参、返回值与遗留对齐（便于换基址）。

### 2.6 测试（契约）

- [x] Feature：无 Token → 401；错误 scope → 403。
- [x] Feature：登录校验 422；错误密码 401；成功返回 `token` + `admin` 结构。
- [x] Feature：`GET /catalog` 带 `catalog:read` 时 `success` + `data` 结构。
- [ ] （可选）幂等：同 key + 同 body 第二次响应一致（可后续补）。

## 已定决策摘要

- 后台：**Filament 5.x**（插件待定）
- 队列：**Redis + Horizon**（不迁移 `job_queue` 历史数据）
- 前台：**Blade**（**批次 3 往后排，先做后端能力**）

## 素材管理 5 模块深入迁移（2026-04-20）

- [x] Stage 1：补齐 5 模块 detail 路由、控制器骨架、index 入口与访问测试
- [x] Stage 2：关键词库/标题库 detail 闭环（搜索、分页、新增、批量删除、导入）+ 标题 AI 生成页与提交流程
- [x] Stage 3：图片库/知识库 detail 深入功能（上传、批量删除、知识文档上传、chunk 向量状态预览、任务引用阻断）
- [x] Stage 4：作者列表分页与详情页补齐；`materials` 页面 `view_all` 可点击对齐
- [x] Stage 5：`pint` + 相关 Feature 回归 + 计划状态收口
