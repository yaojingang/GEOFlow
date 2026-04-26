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
- `AUTO_MIGRATE` 在 `.env.prod` 中默认建议为 `false`
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

若希望单条命令拉起，也可以：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build
```

但第一次部署仍建议先观察 `init` 是否完成迁移。

## 4. 访问方式

- 前台与后台统一从 `web`（Nginx）进入
- 站点：`http://服务器IP:${WEB_PORT}` 或你的反向代理域名
- 后台：`/geo_admin/login`（或你的 `ADMIN_BASE_PATH`）
- Reverb：默认映射 `${REVERB_EXPOSE_PORT}:8080`

### 默认管理员（首次种子）

生产镜像入口 **`docker/entrypoint.prod.sh` 不会执行 `db:seed`**，只有迁移（`init` 在 compose 里打开 `AUTO_MIGRATE` 时）与 `optimize`。因此**首次部署在迁移成功后**，需在本机执行一次种子以写入默认后台账号：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm app php artisan db:seed --force
```

账号由 `Database\Seeders\AdminUserSeeder` 使用 `firstOrCreate` 写入：**重复执行不会覆盖**已存在的 `admin` 用户。

| 项目 | 值 |
|------|-----|
| 用户名 | `admin` |
| 密码 | `password` |

登录地址：站点根 URL + `/geo_admin/login`（默认；若改过 `ADMIN_BASE_PATH` 则把 `geo_admin` 换成你的前缀）。**上线后请立即修改密码。**

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

- 若需要手动跑迁移：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm \
  -e AUTO_MIGRATE=true \
  -e AUTO_OPTIMIZE=false \
  app php artisan about
```

或更直接：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm app php artisan migrate --force
```

## 7. 回滚与更新

更新：

```bash
git pull
docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
```

回滚：

- 切回目标 commit / tag
- 重新执行相同的 `build` 与 `up -d`

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
