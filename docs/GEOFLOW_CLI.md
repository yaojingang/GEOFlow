# GEOFlow CLI 0.2.0

GEOFlow CLI 是仓库内置的 API v1 客户端，用于管理目录、任务、执行记录、素材和文章。它负责配置文件、登录、HTTPS 策略、密钥脱敏、JSON 校验、删除确认和 API 错误提示。

当前正式支持 macOS、Linux 和 WSL。原生 Windows 可以运行 PHP，但 CLI 无法验证 Windows ACL。需要在原生 Windows 保存 Token 时，请手动限制配置文件权限，或改用 WSL。

## 安装与前置条件

CLI 随 GEOFlow 源码提供，不需要单独下载。运行前需要：

- PHP 8.3 或更高版本。
- 已通过 Composer 安装项目依赖。
- 可以访问一个已启用 `/api/v1` 的 GEOFlow 实例。
- 登录账号，或一个具有所需 API scope 的 Token。

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
composer install --no-interaction --prefer-dist

bin/geoflow --version
bin/geoflow --help
```

如果文件的可执行位在复制过程中丢失，可以修复后重试：

```bash
chmod +x bin/geoflow
```

也可以直接交给 PHP 运行：

```bash
php bin/geoflow --version
```

`--version` 返回 JSON，当前版本为 `0.2.0`。`--help` 是命令名称和基本用法的最终依据。

## 配置文件与优先级

CLI 每次只选择一个配置文件，顺序如下：

1. 命令行指定的 `--config PATH`。
2. 当前工作目录中已存在的 `.geoflow.json`。
3. 用户目录下的 `~/.config/geoflow/config.json`。

选定配置文件后，各配置项按以下顺序覆盖：

1. CLI 选项。
2. 环境变量。
3. 配置文件。
4. 内置默认值。

支持的环境变量如下：

| 配置项 | 环境变量 |
|---|---|
| 系统地址 | `GEOFLOW_BASE_URL` |
| Token | `GEOFLOW_TOKEN`、`GEOFLOW_API_TOKEN` |
| 超时秒数 | `GEOFLOW_TIMEOUT` |
| 允许远程 HTTP | `GEOFLOW_ALLOW_INSECURE_HTTP` |

查看解析结果：

```bash
bin/geoflow --config /path/to/profile.json config show
```

输出只包含脱敏 Token，还会显示 endpoint 与 credential 的来源和绑定状态。

### endpoint 与 credential 绑定

CLI 会拒绝可能把 Token 发往错误主机的组合：

- 当前目录 `.geoflow.json` 中的 endpoint 只能使用同一文件里的 Token。
- 显式 `--base-url` 需要在同一次调用中提供凭据，推荐配合 `--token-stdin`。
- `GEOFLOW_BASE_URL` 不能继承配置文件中的 Token，需要配合 `GEOFLOW_TOKEN`、`GEOFLOW_API_TOKEN` 或 `--token-stdin`。
- 显式 `--config` 选中的 profile，以及用户目录中的默认 profile，属于可信 profile。
- profile 中保存的远程 HTTP 放行只适用于该 profile 自身的 endpoint。使用 `--base-url` 或 `GEOFLOW_BASE_URL` 覆盖 endpoint 时，需要在同次调用传入 `--allow-insecure-http` 或设置 `GEOFLOW_ALLOW_INSECURE_HTTP`。

仓库任意层级的 `.geoflow.json` 都已加入 `.gitignore`，请继续避免复制、上传或提交任何真实 Token。

## HTTPS 与 HTTP

没有协议的地址会自动补为 `https://`。以下回环地址允许使用 HTTP：

- `localhost` 和 `*.localhost`
- `127.0.0.0/8`
- `::1`

远程 HTTP 需要显式传入 `--allow-insecure-http`。这个例外只适合经过批准的测试环境。HTTPS 请求始终验证证书，CLI 也不会跟随 API 重定向。为了在传输阶段落实 5 MiB 响应上限，CLI 请求 `identity` 编码，并拒绝带压缩 `Content-Encoding` 的 API 响应。

```bash
bin/geoflow --allow-insecure-http login \
  --base-url http://test-host.example \
  --username admin
```

生产环境请使用 HTTPS。

## 初始化配置与登录

### 使用已有 Token 初始化

交互终端可以使用隐藏提示，CLI 会把结果写入默认配置文件：

```bash
bin/geoflow config init --base-url https://geoflow.example.com
```

自动化环境可从受保护的 stdin 提供一行 Token：

```bash
bin/geoflow --token-stdin config init \
  --base-url https://geoflow.example.com \
  --file /path/to/profile.json
```

调用方需要把密钥管理器或隐藏输入连接到 stdin。不要把真实 Token 直接写进命令示例。已有配置文件需要 `--force` 才能覆盖。

在 macOS、Linux 和 WSL 上，包含 Token 的配置文件会被限制为 `0600`，默认配置目录会被限制为 `0700`。

### 使用管理员账号登录

交互登录会隐藏密码：

```bash
bin/geoflow login \
  --base-url https://geoflow.example.com \
  --username admin
```

将登录结果写到指定 profile：

```bash
bin/geoflow --config /path/to/profile.json login \
  --base-url https://geoflow.example.com \
  --username admin
```

刷新已有 profile 中的无效或过期 Token：

```bash
bin/geoflow --config /path/to/profile.json login \
  --base-url https://geoflow.example.com \
  --username admin \
  --force
```

非交互登录使用 `--password-stdin` 读取一行密码。`--token` 和 `--password` 仍可兼容旧脚本，但会输出弃用警告，并计划在下一个 CLI 主版本移除。新脚本请使用隐藏提示、环境变量或 stdin。

## 全局选项

| 选项 | 说明 |
|---|---|
| `--config PATH` | 选择可信 profile。 |
| `--base-url URL` | 临时指定 GEOFlow Web 根地址。 |
| `--token-stdin` | 从 stdin 读取一行 Token。 |
| `--timeout SECONDS` | 设置请求超时，必须为正整数。 |
| `--allow-insecure-http` | 允许远程 HTTP，仅用于明确批准的测试主机。 |
| `--no-interaction`、`-n` | 禁用交互提示。 |
| `--help`、`-h` | 显示帮助。 |
| `--version`、`-V` | 显示 JSON 版本信息。 |
| `--quiet`、`-q` | 抑制正常输出。 |
| `--verbose`、`-v` | 提高输出详细程度，可重复。 |

全局选项可以放在子命令前。以下示例统一把 `--config` 放在命令前，便于审计。

## 完整命令参考

### 配置、登录与目录

```text
geoflow config init --base-url URL [--token-stdin] [--file PATH] [--force]
geoflow config show [--config PATH]
geoflow login --base-url URL [--username USER] [--password-stdin] [--file PATH] [--force]
geoflow catalog
```

`catalog` 返回模型、提示词、关键词库、标题库、图片库、知识库、作者和分类。任何写操作开始前，先运行一次：

```bash
bin/geoflow --config /path/to/profile.json catalog
```

### 任务与执行记录

```text
geoflow task list [--page N] [--per-page N] [--status STATUS] [--search TEXT]
geoflow task create --json FILE [--idempotency-key KEY]
geoflow task get TASK_ID
geoflow task update TASK_ID --json FILE [--idempotency-key KEY]
geoflow task delete TASK_ID [--yes]
geoflow task start TASK_ID [--enqueue-now] [--idempotency-key KEY]
geoflow task stop TASK_ID [--idempotency-key KEY]
geoflow task enqueue TASK_ID [--job-type TYPE] [--payload-json FILE] [--idempotency-key KEY]
geoflow task jobs TASK_ID [--status STATUS] [--limit N]
geoflow job get JOB_ID
```

创建任务至少需要 `name`、`title_library_id`、`prompt_id` 和 `ai_model_id`：

```json
{
  "name": "CLI task",
  "title_library_id": 1,
  "prompt_id": 2,
  "ai_model_id": 3,
  "status": "paused",
  "publish_scope": "local_only",
  "knowledge_base_ids": [4, 5],
  "need_review": 1
}
```

```bash
bin/geoflow --config /path/to/profile.json task create \
  --json ./task.json \
  --idempotency-key task-create-001

bin/geoflow --config /path/to/profile.json task start 12 \
  --enqueue-now \
  --idempotency-key task-start-12

bin/geoflow --config /path/to/profile.json task jobs 12 --limit 20
bin/geoflow --config /path/to/profile.json job get 88
```

`knowledge_base_ids` 最多包含五个 ID，并优先于旧字段 `knowledge_base_id`。API v1 和 CLI 不绑定具体的 `distribution_channel_ids`，需要在后台任务表单中选择渠道。

### 素材

支持的类型：

- `categories`
- `authors`
- `keyword-libraries`，别名 `keywords`
- `title-libraries`，别名 `titles`
- `image-libraries`，别名 `images`
- `knowledge-bases`，别名 `knowledge`

```text
geoflow material summary
geoflow material list TYPE [--page N] [--per-page N] [--search TEXT]
geoflow material create TYPE --json FILE [--idempotency-key KEY]
geoflow material get TYPE ID
geoflow material update TYPE ID --json FILE [--idempotency-key KEY]
geoflow material delete TYPE ID [--yes]
geoflow material item-list TYPE ID [--page N] [--per-page N]
geoflow material item-create TYPE ID --json FILE [--idempotency-key KEY]
geoflow material item-upload TYPE ID --image FILE [--idempotency-key KEY]
geoflow material item-delete TYPE ID (--ids 1,2 | --json FILE) [--yes]
```

`item-upload` 只支持 `image-libraries` 或 `images`，上传字段使用 `--image`：

```bash
bin/geoflow --config /path/to/profile.json material item-upload images 9 \
  --image ./cover.png \
  --idempotency-key image-upload-001
```

删除多个条目时，`--ids` 与 `--json` 只能选择一个：

```bash
bin/geoflow --config /path/to/profile.json material item-delete titles 34 --ids 101,102
bin/geoflow --config /path/to/profile.json material item-delete titles 34 --json ./delete-items.json
```

知识库条目是自动生成的切块，只能读取。修改知识库正文后，系统会重建切块。

### 文章

```text
geoflow article list [--page N] [--per-page N] [--task-id ID] [--status STATUS]
  [--review-status STATUS] [--author-id ID] [--search TEXT]
geoflow article create (--json FILE | direct fields) [--idempotency-key KEY]
geoflow article get ARTICLE_ID
geoflow article update ARTICLE_ID (--json FILE | direct fields) [--idempotency-key KEY]
geoflow article review ARTICLE_ID --status STATUS [--note TEXT]
  [--risk-override-reason TEXT] [--idempotency-key KEY]
geoflow article publish ARTICLE_ID [--idempotency-key KEY]
geoflow article trash ARTICLE_ID [--idempotency-key KEY]
```

文章创建和更新都支持以下直接字段：

- `--title`
- `--excerpt`
- `--slug`
- `--keywords`
- `--meta-description`
- `--task-id`
- `--author-id`
- `--category-id`
- `--content`
- `--content-file`

以下工作流字段仅用于创建文章：

- `--status`
- `--review-status`
- `--ai-generated`

更新文章状态时，请使用 `article review`、`article publish` 或 `article trash`。更新命令会在发起请求前拒绝上述三个创建专用字段。

创建文章时，标题和正文不能为空。`--content` 与 `--content-file` 不能同时使用。传入 `--json` 后，请让 JSON 对象提供完整请求体。

```bash
bin/geoflow --config /path/to/profile.json article create \
  --title "CLI article" \
  --content-file ./article.md \
  --author-id 5 \
  --category-id 2 \
  --idempotency-key article-create-001

bin/geoflow --config /path/to/profile.json article review 101 \
  --status approved \
  --note "Reviewed" \
  --idempotency-key article-review-101

bin/geoflow --config /path/to/profile.json article publish 101 \
  --idempotency-key article-publish-101

bin/geoflow --config /path/to/profile.json article get 101
```

发布后需要重新读取文章，并使用持久化 slug 对应的 `/article/{slug}`。请勿返回旧版 `article.php?id=...` 兼容地址。

## JSON 文件与 stdin

`--json FILE` 和 `--payload-json FILE` 要求顶层为 JSON 对象。使用 `-` 可以从 stdin 读取：

```bash
bin/geoflow --config /path/to/profile.json task create \
  --json - \
  --idempotency-key task-create-stdin
```

`--content-file -` 可以从 stdin 读取文章正文。JSON 和文本输入上限为 5 MiB。`--token-stdin` 与 `--password-stdin` 读取一行，安全上限为 64 KiB。

一个进程只有一个 stdin 流。请勿在同一调用中组合两个需要读取 stdin 的选项，比如 `--token-stdin` 与 `--json -`。

## 删除确认与 `--yes`

以下命令会请求交互确认：

- `task delete`
- `material delete`
- `material item-delete`

非交互环境必须传入 `--yes`。使用前先读取精确 ID 和当前状态：

```bash
bin/geoflow --config /path/to/profile.json task get 12
bin/geoflow --config /path/to/profile.json --no-interaction task delete 12 --yes
```

`--yes` 只确认当前 CLI 命令中的精确删除目标。Token scope、服务端权限、资源锁、业务校验和后台高风险确认仍然有效。

当前 DELETE 操作不使用幂等键。任务删除、素材库删除和素材条目删除都不要添加 `X-Idempotency-Key`。创建、更新、任务动作、文章审核/发布/回收和图片上传等受支持的 POST/PATCH 操作可以使用 `--idempotency-key`。

## 输出、错误与退出码

成功的 API 命令把服务端 JSON 对象写到 stdout。`config show` 和 `--version` 也输出 JSON。警告和错误写到 stderr，敏感字段会脱敏。

CLI 当前退出码：

| 退出码 | 含义 |
|---|---|
| `0` | 命令成功。 |
| `1` | 参数、配置、传输、API 或其他运行错误。 |

HTTP 状态需要结合 JSON `error.code` 判断：

| HTTP | 处理建议 |
|---|---|
| `401` | Token 无效或过期，重新登录或更新 Token。 |
| `403` | Token 缺少所需 scope，重新签发最小权限 Token。 |
| `409` | 幂等冲突、处理中或结果不确定，先读取资源状态。 |
| `422` | 请求字段校验失败，按 `field_errors` 修正。 |
| `423` | 目标资源已锁定，检查工作流状态后重试。 |
| `429` | 请求过于频繁，按 `retry_after` 等待。 |
| `500` | 服务端错误，保留请求 ID，检查应用和队列日志。 |

CLI 拒绝空响应、非 JSON 响应、非对象 JSON、缺少 `success=true` 的 2xx 响应，以及超过 5 MiB 的响应。

## 典型工作流

### 只读核对

```bash
bin/geoflow --config /path/to/profile.json catalog
bin/geoflow --config /path/to/profile.json material summary
bin/geoflow --config /path/to/profile.json task list --per-page 20
bin/geoflow --config /path/to/profile.json article list --per-page 20
```

### 创建任务并查看执行

```bash
bin/geoflow --config /path/to/profile.json task create \
  --json ./task.json \
  --idempotency-key task-create-001

bin/geoflow --config /path/to/profile.json task start 12 \
  --enqueue-now \
  --idempotency-key task-start-12

bin/geoflow --config /path/to/profile.json task jobs 12 --limit 20
```

每次写操作完成后，使用 `task get`、`job get` 或对应列表重新读取持久化状态。

### 创建、审核并发布文章

```bash
bin/geoflow --config /path/to/profile.json article create \
  --json ./article.json \
  --idempotency-key article-create-001

bin/geoflow --config /path/to/profile.json article review 101 \
  --status approved \
  --idempotency-key article-review-101

bin/geoflow --config /path/to/profile.json article publish 101 \
  --idempotency-key article-publish-101

bin/geoflow --config /path/to/profile.json article get 101
```

如果风险扫描要求覆盖理由，在审核命令中加入 `--risk-override-reason`，并记录真实、可审计的原因。

## 在 Docker 中运行

开发 Compose 的应用服务名为 `app`：

```bash
docker compose exec app php bin/geoflow --version
docker compose exec app php bin/geoflow --help
docker compose exec app php bin/geoflow --config /path/in/container/profile.json catalog
```

容器内的配置路径必须真实存在。可以通过只读 secret mount 或受保护的数据卷提供 profile。避免把 Token 写进镜像层、Compose 文件或可公开的环境文件。

生产 Compose 使用相同方式，并显式指定 Compose 文件：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml \
  exec app php bin/geoflow --version
```

如果调用方位于宿主机，通常可以直接运行宿主机的 `bin/geoflow`，并把 `base_url` 指向 Nginx 暴露的 HTTPS 地址。

## API 与后台边界

CLI 0.2.0 覆盖 API v1 中的目录、任务、执行记录、素材和文章操作。CLI 缺失时，可以安全回退到对应 `/api/v1` 路由。API Token 需要与操作匹配的 scope。

以下能力目前只在登录后的后台提供：

- Analytics 总览、内容、流量、AI 可见度、线索和分发页面。
- 人工发布工作台。
- 分发渠道、目标站点包、密钥、远端同步和渠道安全删除。
- AI source provider 与模型绑定。
- 文章 AI assistant、风险复扫、编辑器图片和批量操作。
- 企业知识、线索管理、URL Import 和 System Updates。
- 首页模块 GET 编辑器、网站设置和 Theme Replication。
- API Token、管理员、密码和安全设置。

后台前缀由 `ADMIN_BASE_PATH` 控制，仓库默认值为 `geo_admin`。先运行 `php artisan route:list --except-vendor` 获取目标实例的真实路由。当前仓库没有 live Theme Editor 路由。

本地维护命令也没有 CLI/API 等价项：

```bash
php artisan geoflow:recover-knowledge-syncs --stale=600 --limit=50
php artisan geoflow:prune-expired-cache --limit=5000
```

## 排障

### 找不到 Composer autoload

错误通常是 `Composer autoload not found`。在当前代码版本中运行：

```bash
composer install --no-interaction --prefer-dist
```

### 缺少 `base_url` 或 Token

先查看来源：

```bash
bin/geoflow --config /path/to/profile.json config show
```

随后使用 `login` 或 `config init` 补全。若 endpoint 与 credential 绑定无效，请选择一个显式 profile，或在同一次调用中组合 `--base-url` 与 `--token-stdin`。

### 远程 HTTP 被拒绝

生产地址改用 HTTPS。经过批准的测试地址可以在调用或 profile 中启用 `allow_insecure_http`。该选项不会关闭 HTTPS 证书验证。

### 返回 HTML

CLI 需要 JSON API。如果错误显示服务端返回非 JSON，请检查：

- `base_url` 是否指向 GEOFlow Web 根地址。
- 地址中是否错误包含 `/api/v1` 或后台路径。
- 反向代理是否把 API 请求转到登录页或错误页。
- Docker Web 端口是否映射到 Laravel/Nginx 入口。

### `401`、`403`、`423` 或 `429`

`401` 时刷新 Token。`403` 时核对 scope。`423` 时读取资源工作流状态。`429` 时读取 `retry_after` 并等待。请勿通过重复登录处理 scope、锁定或限流问题。

### 删除在非交互环境失败

先读取目标并确认 ID，再为精确删除命令添加 `--yes`。`--no-interaction` 本身不会确认删除。

### 原生 Windows ACL 警告

CLI 会明确提示无法验证 ACL。推荐在 WSL 中运行。继续使用原生 Windows 时，请通过系统权限工具限制 profile，只允许当前用户读取。
