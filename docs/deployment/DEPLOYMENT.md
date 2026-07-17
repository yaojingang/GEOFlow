# GEOFlow Laravel 生产 Docker 部署

本文对应仓库中的生产编排文件：

- `docker-compose.prod.yml`
- `docker/Dockerfile.prod`
- `docker/nginx/Dockerfile.prod`
- `docker/nginx/default.conf`
- `docker/entrypoint.prod.sh`
- `.env.prod.example`

## 1. 方案说明

生产环境推荐使用：

- `web`: `nginx`
- `app`: `php-fpm`
- `queue`: `php artisan queue:work`
- `scheduler`: `php artisan schedule:work`
- `reverb`: `php artisan reverb:start`
- `postgres`: PostgreSQL 16 + pgvector
- `redis`: Redis 7

这套方案与当前开发用 `docker-compose.yml` 分离：

- 开发：`docker-compose.yml`，继续使用 `php artisan serve`
- 生产：`docker-compose.prod.yml`，改为 `nginx + php-fpm`

## 1.1 一键部署脚本

一键部署脚本仅用于全新、空数据库安装。如果希望在常见云服务器、VPS 或面板服务器上先做环境自检，再自动完成首次生产 Docker 部署，可以使用仓库中的参考脚本：

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/GEOFlow/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

脚本会完成：

- 检查 CPU、内存、磁盘、Docker、Docker Compose 与端口占用
- 克隆或更新 GEOFlow 源码
- 生成 `.env.prod` 并写入生产默认配置
- 启动 PostgreSQL、Redis、Nginx、PHP-FPM、队列、调度和 Reverb
- 执行迁移、写入默认管理员、清理并重建 Laravel 缓存
- 调用 `deploy-scripts/geoflow-healthcheck.sh` 做部署后自检

如需部署成功后删除临时脚本，可使用：

```bash
GEOFLOW_SELF_DELETE=1 bash geoflow-docker-deploy.sh
```

完整变量说明见 `deploy-scripts/README.md`。

已有数据的实例禁止使用一键脚本升级，也禁止滚动升级。请完整执行 3.1 节的停机排空协议。

## 2. 准备环境文件

```bash
cp .env.prod.example .env.prod
vi .env.prod
```

至少确认这些值：

```env
APP_URL=https://your-domain.com
TRUSTED_PROXIES=*
APP_KEY=base64:replace-with-generated-key

DB_DATABASE=geo_flow
DB_USERNAME=geo_user
DB_PASSWORD=change-this-password

REDIS_PASSWORD=
WEB_PORT=18080
REVERB_EXPOSE_PORT=18081
```

说明：

- `APP_KEY` 可留空：应用容器启动时会 `key:generate` 写回 `.env.prod`（可写挂载）；也可在宿主机执行 `php artisan key:generate --show` 后粘贴。
- `TRUSTED_PROXIES` 用于反向代理、CDN、负载均衡或一级目录部署。若外层代理会传 `X-Forwarded-Proto` / `X-Forwarded-Host` / `X-Forwarded-Prefix`，生产环境通常可设为 `*` 或具体代理 IP。
- 如果部署在任意一级目录下，例如外部访问路径是 `/wiki`、`/docs`、`/site`，不要把目录写进 `ADMIN_BASE_PATH`；应由反向代理透传 `X-Forwarded-Prefix`，后台路径仍保持 `ADMIN_BASE_PATH=geo_admin`。
- `AUTO_MIGRATE=true` 由生产 `init` 服务执行迁移；常驻服务不接收 `.env.prod` 作为容器环境变量，重启时不会重复初始化。
- `AUTO_INSTALL_ONCE=true` 由生产 `init` 服务在迁移后运行 `php artisan geoflow:install`；该命令只在空库首次安装时执行安装填充，旧库只补初始化标记。
- 生产镜像不会在启动时执行 `composer install`
- **`postgres` / `redis` 凭据**：`docker-compose.prod.yml` 中 postgres 使用 `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` 映射为官方镜像的 `POSTGRES_*`；redis 使用 `REDIS_PASSWORD`；值均由 Compose 插值（推荐 `--env-file .env.prod`），与 Laravel 的 `DB_*` 同源、不重复定义。
- **建议仍使用 `--env-file .env.prod`**：便于插值 `WEB_PORT`、`POSTGRES_DATA_DIR` 等与根目录 `.env` 对齐；若曾用错误密码初始化过 Postgres，须删掉 `POSTGRES_DATA_DIR` 对应数据目录后再启动。

## 3. 启动步骤

下文统一使用前缀（请原样复制）：

```bash
export COMPOSE_PROD='docker compose --env-file .env.prod -f docker-compose.prod.yml'
```

首次部署建议按以下顺序：

```bash
$COMPOSE_PROD build
$COMPOSE_PROD up -d postgres redis
$COMPOSE_PROD up -d init
$COMPOSE_PROD up -d app web queue scheduler reverb
```

`init` 服务会把 `GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true` 仅注入该一次性容器。迁移只在单一 fresh migration batch 且业务表为空时接受此标志；已有部署仍需下一节的 drain confirmation。

### 3.1 受管图片删除升级门禁

升级到包含 `images.managed_path_hash` 的版本时，先保持 `GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=false`。已有数据或既有迁移历史的数据库必须使用 down → stop/drain → one-time confirmation → migrate → start-new → readiness → up → enable 的顺序。迁移会在任何 schema 变更前检查 `GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true`；未确认时会安全终止。全新空库使用 init 服务限定作用域的 `GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true`。

滚动升级、migration-first、一键升级均无法覆盖已经通过旧版空 replay 检查的在途请求，因此明确禁止用于已有数据的实例。一次性确认仅表示运维人员已经完成排空，不会自动停止进程。

```bash
# 1. 先进入维护模式，再停止入口和所有旧版常驻进程。
$COMPOSE_PROD exec app php artisan down
$COMPOSE_PROD stop web queue scheduler reverb

# 2. 等待负载均衡连接、PHP 请求、队列任务和调度任务全部结束；确认零在途后停止 app。
# 请使用平台连接数、进程列表和队列监控完成确认。
$COMPOSE_PROD stop app

# 3. 仅在确认全部旧进程和在途请求已排空后，临时修改 .env.prod：
# GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true
# GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=false

# 4. 构建新镜像，并由一次性 init 服务执行新版本迁移。
$COMPOSE_PROD build
$COMPOSE_PROD up -d postgres redis
$COMPOSE_PROD up init

# 5. 迁移成功后立即将一次性确认恢复为 false，再启动全部新版本进程：
# GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=false
$COMPOSE_PROD up -d app web queue scheduler reverb

# 6. 回填并检查受管图片身份；remaining、terminal、registry_failed 必须都为 0。
$COMPOSE_PROD run --rm app php artisan geoflow:managed-images:readiness

# 7. 运行只读安全审计，并逐项处理或确认 finding。
$COMPOSE_PROD run --rm app php artisan geoflow:security-audit

# 8. 退出维护模式并恢复流量。
$COMPOSE_PROD exec app php artisan up
```

readiness 命令会回填已有图片路径哈希，并在路径锁内对账注册表、文件状态和内容哈希。永久无效的历史路径会保留稳定终态哈希，并计入 `terminal`；文件缺失、身份不一致或无法安全读取会计入 `registry_failed`。确认输出表格的 `remaining`、`terminal`、`registry_failed` 都为 `0`，再运行 `geoflow:security-audit`。该审计命令严格只读，不回填哈希、不修改数据库、不访问 HTTP/DNS，也不启动外部进程。人工可读模式和 JSON 模式使用相同 finding 集合：

```bash
# 人工检查
$COMPOSE_PROD run --rm app php artisan geoflow:security-audit

# 自动化检查；JSON schema_version 固定为 1
$COMPOSE_PROD run --rm app php artisan geoflow:security-audit --json
```

退出码 `0` 表示没有 finding；退出码 `1` 表示发现问题、需要复核的私网出站例外，或审计无法安全完成。JSON 包含 `schema_version`、`status`、按 severity 汇总的 `summary` 和按稳定 code 排序的 `findings`，不会输出路径、URL、token、owner 或哈希原文。该命令用于 GEOFlow 运行数据与安全配置检查，依赖漏洞检查仍需单独执行 `composer audit`。

完成审计处理，再次确认运行中的容器全部来自新镜像，然后将 `GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=true` 写入生产环境配置，并重新创建会执行图片清理的新版本进程：

```bash
$COMPOSE_PROD up -d --force-recreate app queue scheduler
```

门禁关闭或回填未完成时，数据库记录仍可删除，物理图片文件会安全保留并记录清理失败日志。

以下单条命令仅适用于全新空库安装。已有数据的升级执行它会触发安全迁移门禁；不要通过预设一次性确认绕过停机排空流程：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build
```

但第一次部署仍建议先观察 `init` 是否完成迁移。

## 4. 访问方式

- 前台与后台统一从 `web`（Nginx）进入
- 站点：`http://服务器IP:${WEB_PORT}` 或你的反向代理域名
- 后台：`/geo_admin/login`（或你的 `ADMIN_BASE_PATH`）
- Reverb：默认映射 `${REVERB_EXPOSE_PORT}:8080`

### 默认管理员（首次安装）

生产 `docker-compose.prod.yml` 的 **`init`** 服务会在迁移完成后执行 `php artisan geoflow:install`。该命令只在空库首次安装时写入默认管理员；如果检测到已有业务数据但没有安装标记，只会补写标记并跳过填充，避免重启、重构或拉取新代码后污染线上网站设置、广告、提示词、分类和文章。常驻的 `app`、`queue`、`scheduler`、`reverb` 服务不会自动 seed。

```bash
# 如果你没有使用 compose 的 init 服务，可在迁移成功后执行首次安装命令：
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm app php artisan geoflow:install
```

账号由 `Database\Seeders\AdminUserSeeder` 在首次空库安装时写入：只在目标用户名不存在时创建，**重复执行不会覆盖**已存在账号的用户名、邮箱或密码。前台演示分类和文章默认不会写入；只有显式设置 `GEOFLOW_SEED_FRONTEND_DEMO=true` 且首次空库安装时才会导入演示数据。

| 项目 | 值 |
|------|-----|
| 用户名 | `GEOFLOW_ADMIN_USERNAME`，默认 `admin` |
| 密码 | 生产环境请设置 `GEOFLOW_ADMIN_PASSWORD`；若留空且账号尚不存在，首次安装会生成一次性随机密码并输出到初始化日志 |

登录地址：站点根 URL + `/geo_admin/login`（默认；若改过 `ADMIN_BASE_PATH` 则把 `geo_admin` 换成你的前缀）。账号已存在时，重复执行安装命令不会重新生成或打印密码。**上线后请立即修改默认或初始化生成的密码。**

### 初始化数据维护规则

后续新增默认站点配置、默认提示词、默认渠道、默认模板、演示分类或演示文章时，必须接入 `php artisan geoflow:install` 的首次空库安装路径，或通过明确的手动修复命令执行。不要把用户可修改的默认数据放到常规容器启动、迁移或每次升级都会自动执行的 seed 流程里，避免覆盖线上用户配置。

## 5. 关键差异

### 当前开发 Docker

- `php:8.4-cli-bookworm`
- `php artisan serve`
- 允许运行时 `composer install`
- 默认 `AUTO_MIGRATE=true`

### 当前生产 Docker

- `php:8.4-fpm-bookworm`
- `nginx` 直接服务静态文件，PHP 交给 `php-fpm`
- 依赖在构建期安装完成
- 通过 `docker/entrypoint.prod.sh` 执行可选的等待数据库、迁移、`php artisan optimize`

## 6. 运维建议

- 不要对外暴露 `postgres` / `redis`
- 建议在反向代理层只公开 `80/443`
- 若更新了 PHP 代码，因 OPcache `validate_timestamps=0`，请重新构建镜像
- 修改 `.env.prod` 后，执行：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
```

该命令仅用于没有代码、镜像或数据库迁移变化的配置重建。已有部署的代码更新统一执行 3.1 节的停机排空协议。

- 全新空库需要手动跑迁移时，可把 fresh-install intent 限定在该一次性容器：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm \
  -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true \
  -e AUTO_MIGRATE=true \
  -e AUTO_OPTIMIZE=false \
  app php artisan about
```

或直接执行：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm \
  -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true \
  app php artisan migrate --force
```

已有部署不得使用上述 fresh-install 命令；手动迁移也必须先完成 3.1 节的 down、停止排空和 drain confirmation。

## 7. 回滚与更新

已有部署的更新统一执行 3.1 节。`git pull` 与镜像构建应放在停机排空流程内，禁止用 `git pull` → `build` → `up -d` 直接替代该流程。

回滚：

- 切回目标 commit / tag
- 先确认目标版本与当前数据库 schema 兼容
- 使用与 3.1 节相同的停机排空边界重建并启动目标版本

## 8. 原理说明

- 静态文件：由 `web` 容器中的 Nginx 直接返回
- PHP 请求：Nginx 通过 FastCGI 转发给 `app:9000`
- Laravel 代码执行：由 `php-fpm` 进程解析并运行 `public/index.php`

## 9. 构建时拉取基础镜像失败（`not found` / `could not fetch content descriptor`）

若日志类似 `FROM php:8.4-fpm-bookworm` 或某层 `application/vnd.oci.image.layer...` **`from remote: not found`**，多为**仓库侧或镜像加速与 manifest 不一致**，而非项目 Dockerfile 写错。

建议按顺序尝试：

1. **直接重试** `docker compose --env-file .env.prod -f docker-compose.prod.yml build`（偶发 Hub 或链路问题）。
2. **单独拉基础镜像**，确认是拉取问题还是仅 BuildKit 缓存问题：  
   `docker pull php:8.4-fpm-bookworm`  
   若此处同样 `not found`，说明当前访问的 registry/加速源缺层，需换源或直连。
3. **检查本机 `/etc/docker/daemon.json` 的 `registry-mirrors`**：部分公共加速源对 `docker.io` 层同步不完整，可**暂时注释镜像加速**后重启 Docker，再 `docker pull` / `build`；或换成你环境稳定可用的镜像源策略。
4. **清理构建缓存后再构建**：  
   `docker builder prune -f`  
   必要时再 `docker system prune`（注意会删掉未使用镜像，执行前自行确认）。

仍失败时，把 **`docker pull php:8.4-fpm-bookworm` 的完整输出**与 **`daemon.json` 中与 registry 相关的配置**（可打码）一并排查网络与镜像源。
