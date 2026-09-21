# 阿里云 SAE 部署运行时说明

本文说明 GEOFlow 在 SAE 上的容器运行时和发布边界。仓库已经提供 RDS MySQL 方言、原生向量字段/检索和迁移分支；真正执行 release 前，仍必须在隔离 RDS MySQL 数据库完成迁移与向量写入验收，本文件不会绕过数据库兼容性检查。具体检查步骤见 [RDS MySQL 隔离验收手册](RDS_MYSQL_VALIDATION.md)。

## 1. 运行时拓扑

建议把 SAE 应用拆成以下角色：

| SAE 角色 | 镜像 | 作用 | 是否常驻 | 端口 |
| --- | --- | --- | --- | --- |
| `web` | `Dockerfile.sae-web` 生成的 Web 镜像 | Nginx + PHP-FPM | 是 | 80 |
| `worker` | `Dockerfile.prod` 生成的应用镜像 | 普通 Redis 队列 | 是 | 无 |
| `ai-quality-front` | 应用镜像 | AI 质量前台队列 | 是 | 无 |
| `ai-quality-backfill` | 应用镜像 | AI 质量回填队列 | 是 | 无 |
| `ai-optimization` | 应用镜像 | AI 内容优化队列 | 是 | 无 |
| `knowledge` | 应用镜像 | 知识库队列 | 是 | 无 |
| `scheduler` | 应用镜像 | Laravel 调度器，保持单副本 | 是 | 无 |
| `reverb` | 应用镜像 | WebSocket 服务 | 是 | 18080 |
| `release` | 应用镜像 + release 入口 | 迁移、首次安装和配置缓存 | 否，一次性 | 无 |

`web` 镜像内的 Nginx 通过 `127.0.0.1:9000` 连接同一容器内的 PHP-FPM。Reverb 可以单独部署为 SAE 应用；此时把 `GEOFLOW_REVERB_UPSTREAM` 配成 Reverb 应用的可达内网地址，例如 `reverb.internal.example:18080`，不要使用 Docker Compose 的 `reverb` 服务名。

## 2. 构建镜像

SAE 通常运行 `linux/amd64` 镜像。先构建应用镜像，再构建合并 Web 镜像；第二步通过 `GEOFLOW_APP_IMAGE` 复用第一步的 Composer、Node 和 PHP 产物。

```bash
docker buildx build \
  --platform linux/amd64 \
  -f docker/Dockerfile.prod \
  -t <acr-registry>/<namespace>/geoflow:<git-sha> \
  --push .

docker buildx build \
  --platform linux/amd64 \
  -f docker/Dockerfile.sae-web \
  --build-arg GEOFLOW_APP_IMAGE=<acr-registry>/<namespace>/geoflow:<git-sha> \
  -t <acr-registry>/<namespace>/geoflow:<git-sha>-web \
  --push .
```

不要把密码、AccessKey、`APP_KEY` 或完整 `.env` 文件复制进镜像。ACR 地址、仓库命名空间和应用 ID 应通过 CI/CD 变量或 SAE 配置维护。

## 3. SAE 环境变量与 Secret

SAE 不需要挂载 `.env` 文件。为所有应用注入非敏感配置，为对应应用注入 Secret：

仓库提供了可复制到 SAE 控制台的 [`.env.sae.example`](../../.env.sae.example)。它与
`.env.prod.example` 分开，避免把本地 Compose 的 `AUTO_MIGRATE=true`、
`AUTO_INSTALL_ONCE=true` 带到 SAE 常驻副本。

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<固定且随机的Laravel密钥>
APP_URL=https://<正式域名>
GEOFLOW_SAE_RUNTIME=true
GEOFLOW_ALLOW_MISSING_ENV_FILE=true
AUTO_WAIT_FOR_DB=true
AUTO_MIGRATE=false
AUTO_INSTALL_ONCE=false
AUTO_OPTIMIZE=false

DB_CONNECTION=mysql
DB_HOST=<RDS MySQL 私网地址>
DB_PORT=3306
DB_DATABASE=<GEOFlow 专用数据库>
DB_USERNAME=<RDS 账号>
DB_PASSWORD=<放入 SAE Secret>

REDIS_CLIENT=phpredis
REDIS_HOST=<Redis 私网地址>
REDIS_PORT=6379
REDIS_PASSWORD=<放入 SAE Secret；无密码时留空>
QUEUE_CONNECTION=redis
CACHE_STORE=redis

FILESYSTEM_DISK=local
GEOFLOW_NGINX_PRIMARY_HOST=<正式域名>
GEOFLOW_NGINX_PRIMARY_ALIASES=<可选的别名域名>
GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN=<托管站点根域名；不用时填 invalid>
GEOFLOW_NGINX_PUBLIC_SCHEME=https
GEOFLOW_NGINX_PUBLIC_PORT=443
GEOFLOW_PHP_FPM_UPSTREAM=127.0.0.1:9000
GEOFLOW_NGINX_RESOLVER=<SAE/VPC DNS resolver；Reverb 用 IP 时可使用默认值>
GEOFLOW_REVERB_UPSTREAM=<Reverb 内网地址>:18080
```

`APP_KEY` 必须固定，不能让每个 SAE 副本启动时重新生成。`DB_PASSWORD`、`REDIS_PASSWORD`、邮件凭据、OSS `AWS_SECRET_ACCESS_KEY` 和各 AI Provider 的密钥都放在 SAE Secret；不要写进仓库或 Dockerfile。生产镜像通过 `league/flysystem-aws-s3-v3` 提供 OSS 的 S3-compatible driver。

第一阶段如果 NAS 已挂载，建议把持久化挂载点用于 `/var/www/html/storage`，以保留当前代码对 POSIX 文件路径、压缩包和临时文件的语义。配置中同时提供 `s3` 和显式 `oss` disk；OSS 作为主文件盘需要额外适配业务代码后再把对应业务的 `FILESYSTEM_DISK` 切换为 `oss`，不能仅修改一个环境变量就假设所有本地路径操作都兼容 OSS。

## 4. 角色启动命令

应用镜像仍保留原来的生产入口；在 SAE 中把启动命令或容器入口配置为 `/usr/local/bin/geoflow-entrypoint-sae`，并设置对应的 `SAE_ROLE`。`web` 镜像已经把该入口设为默认 `ENTRYPOINT`。

### Web

```env
SAE_ROLE=web
```

启动参数使用默认的 `--role=web`。容器内会启动 PHP-FPM 和 Nginx，SAE HTTP 端口配置为 `80`，健康检查 URL 为 `/up`。

### 普通 Worker

```env
SAE_ROLE=worker
```

```bash
php artisan queue:work redis \
  --queue=system-updates,geoflow,distribution,theme-replication,default \
  --sleep=1 --tries=1 --timeout=930 --memory=128 \
  --max-jobs=100 --max-time=3600
```

### AI 与知识库 Worker

```bash
# SAE_ROLE=ai-quality-front
php artisan geoflow:work-ai-quality front

# SAE_ROLE=ai-quality-backfill
php artisan geoflow:work-ai-quality backfill

# SAE_ROLE=ai-optimization
php artisan geoflow:work-ai-optimization

# SAE_ROLE=knowledge
php artisan queue:work redis --queue=knowledge \
  --sleep=1 --tries=1 --timeout=210 --memory=128 \
  --max-jobs=20 --max-time=1800
```

### Scheduler

```env
SAE_ROLE=scheduler
```

```bash
php artisan schedule:work
```

Scheduler 只部署一个副本，避免调度任务重复触发。项目中的 `onOneServer()` 仍需要共享 Redis 锁才能生效。

### Reverb

```env
SAE_ROLE=reverb
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=18080
```

```bash
php artisan reverb:start --host=0.0.0.0
```

如果 Web 和 Reverb 是两个 SAE 应用，Web 的 `GEOFLOW_REVERB_UPSTREAM` 必须指向 Reverb 的内网服务发现地址。若该地址是域名，同时配置 SAE/VPC 可用的 `GEOFLOW_NGINX_RESOLVER`；也可以直接使用可达内网 IP。外部域名仍通过 `/reverb` 反向代理到该地址。Compose 默认仍使用 Docker DNS `127.0.0.11`。

## 5. Release / Migration 一次性任务

迁移、首次安装和 `optimize` 不得放进 Web、Worker、Scheduler 或 Reverb 的常驻副本。发布顺序如下：

1. 推送应用镜像和 Web 镜像。
2. 确认 RDS、Redis、NAS/OSS 网络和安全组可达。
3. 运行一个 `release` 一次性任务并等待成功。
4. 只有空库首次安装时，才额外打开 `AUTO_INSTALL_ONCE=true` 和项目要求的 fresh-install 确认变量。
5. Release 成功后再启动或滚动更新 Web、Worker、Scheduler、Reverb。

Release 任务使用应用镜像，启动入口为：

```text
/usr/local/bin/geoflow-entrypoint-sae-release
```

对应环境变量：

```env
SAE_ROLE=release
SAE_RELEASE_CONFIRM=true
GEOFLOW_SAE_RUNTIME=true
GEOFLOW_ALLOW_MISSING_ENV_FILE=true
AUTO_MIGRATE=true
AUTO_INSTALL_ONCE=false
AUTO_OPTIMIZE=true
```

`AUTO_INSTALL_ONCE=true` 仅允许在确认目标数据库是 GEOFlow 专用空库时使用。现有业务库不能直接执行首次安装；RDS MySQL 的 migration/vector 适配和数据库备份策略必须先验收。

如果使用 `.github/workflows/deploy-sae.yml`，在受保护的 `production` Environment 中手动打开 `run_release` 即可触发该一次性应用。工作流会等待 SAE `DescribeApplicationStatus` 报告最近一次变更成功后，才继续部署常驻角色；未打开 `run_release` 的 push 不会运行迁移。

## 6. 健康检查

容器内健康检查脚本是 `deploy-scripts/sae-healthcheck.sh`。Web 默认检查：

- `/up` HTTP 响应；
- PHP-FPM 和 Nginx 进程；
- Redis PING；
- Laravel migration 状态，仍有 pending migration 时失败。

示例：

```bash
SAE_ROLE=web /usr/local/bin/sae-healthcheck.sh
```

脚本在镜像中的安装路径由部署配置映射到 `/usr/local/bin/sae-healthcheck.sh`；如果 SAE 使用独立的探针命令，直接执行仓库脚本即可。Worker、Scheduler 和 Reverb 角色会检查相应进程，同时检查数据库与 Redis。Release 任务不应配置为常驻健康检查。

如数据库迁移尚未完成，健康检查应保持失败，不要通过 `SAE_HEALTHCHECK_ALLOW_PENDING_MIGRATIONS=true` 掩盖发布顺序问题。该变量只适合明确的迁移窗口诊断。

## 7. 本地 Compose 兼容边界

本次运行时改造不改变 `docker-compose.prod.yml` 的服务名和挂载方式：

- 本地 Compose 仍可把 `.env.prod` 挂载为 `/var/www/html/.env`；
- 没有 `GEOFLOW_SAE_RUNTIME=true` 时，生产入口仍按原规则要求 `.env`；
- Nginx 模板保留 `app:9000` / `reverb:18080` 作为 Compose 兼容默认值；
- SAE 入口会在启动时把它们渲染为 `GEOFLOW_PHP_FPM_UPSTREAM` / `GEOFLOW_REVERB_UPSTREAM`，所以 SAE 运行时不依赖 Compose 服务名；
- 只有显式的 release 入口允许执行迁移、首次安装和配置缓存。

## 8. 当前不能在本地完成的验证

本地静态检查可以验证 shell 语法、Dockerfile 结构和 Nginx 模板，但以下事项必须在你的阿里云环境验收：

- SAE 到 RDS MySQL、Redis、NAS、OSS 的 VPC/安全组/挂载权限；
- RDS MySQL 向量能力对应的内核版本、维度和实际索引查询；
- ACR 私有镜像拉取权限与 `linux/amd64` 镜像启动；
- SAE 的启动命令、健康检查和滚动发布行为；
- Reverb 内网服务发现、WebSocket 握手和域名证书；
- 真实数据库迁移、现有数据升级以及首次安装门禁。
