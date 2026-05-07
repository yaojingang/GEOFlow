# GEOFlow 部署脚本 / Deployment Scripts

这个目录用于存放 GEOFlow 的参考部署脚本，方便技术人员在常见云服务器、VPS、Docker 主机或面板服务器上快速完成环境自检和生产部署。

脚本默认走仓库现有的 `docker-compose.prod.yml` 生产链路，不绕开项目标准部署方式。

## 脚本清单

| 脚本 | 用途 |
| --- | --- |
| `geoflow-docker-deploy.sh` | 生产 Docker 一键部署脚本。会自检服务器、准备 `.env.prod`、部署 PostgreSQL、Redis、Web、App、队列、调度和 Reverb，并在最后执行健康检查。 |
| `geoflow-healthcheck.sh` | 部署后健康检查脚本。可单独检查容器状态、Laravel 健康端点和数据库连接。 |

## 推荐服务器配置

测试最低配置：

- 2 核 CPU
- 2 GB 内存，建议额外配置 2 GB swap
- 20 GB 可用磁盘
- Ubuntu 22.04+ / Debian 12+ / Rocky Linux 9+ / Alibaba Cloud Linux 3+
- 可以稳定访问 Docker 镜像源、GitHub 和你配置的 AI API 服务商

正式生产建议：

- 2-4 核 CPU
- 4-8 GB 内存
- 40-80 GB SSD
- 使用 Nginx、Caddy、宝塔、1Panel、SLB 或 CDN 做 HTTPS 反向代理
- PostgreSQL 和 Redis 不直接暴露到公网

## 一键部署

在新服务器执行：

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/GEOFlow/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

脚本会要求确认：

- 对外访问的 `APP_URL`
- Web 端口，默认 `18080`
- Reverb 端口，默认 `18081`
- 后台入口路径，默认 `geo_admin`

部署完成后：

- 前台：`APP_URL`
- 后台：`APP_URL/geo_admin/login`
- 默认管理员：`admin`
- 默认密码：`password`

首次登录后请立即修改默认密码。

## 非交互部署

适合云服务器初始化脚本、镜像模板或 CI：

```bash
GEOFLOW_NONINTERACTIVE=1 \
GEOFLOW_APP_URL=https://example.com \
GEOFLOW_APP_DIR=/opt/geoflow \
GEOFLOW_WEB_PORT=18080 \
GEOFLOW_REVERB_PORT=18081 \
GEOFLOW_ADMIN_BASE_PATH=geo_admin \
bash geoflow-docker-deploy.sh
```

常用变量：

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `GEOFLOW_REPO_URL` | `https://github.com/yaojingang/GEOFlow.git` | 源码仓库地址 |
| `GEOFLOW_BRANCH` | `main` | 部署分支 |
| `GEOFLOW_APP_DIR` | `/opt/geoflow` | 服务器部署目录 |
| `GEOFLOW_INSTALL_DOCKER` | `auto` | `1` 自动安装 Docker；`0` 缺少 Docker 时直接失败 |
| `GEOFLOW_DB_PASSWORD` | 随机生成 | PostgreSQL 密码 |
| `GEOFLOW_REDIS_PASSWORD` | 随机生成 | Redis 密码 |
| `GEOFLOW_TRUSTED_PROXIES` | `*` | 反向代理、CDN、二级目录部署时的可信代理设置 |
| `GEOFLOW_SELF_DELETE` | `0` | 设置为 `1` 时，部署成功后删除当前执行的部署脚本 |

## 执行后自删除

如果你把部署脚本下载到临时目录，部署成功后希望自动删除它：

```bash
GEOFLOW_SELF_DELETE=1 bash geoflow-docker-deploy.sh
```

这个动作只会删除当前执行的脚本文件，不会删除已部署的 GEOFlow 源码目录。

## 手动健康检查

部署后、改域名、改 HTTPS 或改反向代理后，可以执行：

```bash
cd /opt/geoflow
bash deploy-scripts/geoflow-healthcheck.sh
```

## 一级目录部署

如果网站部署在一级目录下，例如：

```text
https://example.com/wiki
```

建议配置：

```env
APP_URL=https://example.com/wiki
TRUSTED_PROXIES=*
ADMIN_BASE_PATH=geo_admin
```

不要把 `ADMIN_BASE_PATH` 写成 `wiki/geo_admin`。一级目录应由反向代理处理，并透传：

```nginx
proxy_set_header X-Forwarded-Prefix /wiki;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
```

## 注意事项

- 脚本是部署辅助工具，不替代服务器安全加固。
- 不要把 PostgreSQL 和 Redis 暴露到公网。
- 更新前请备份 `.env.prod`、`storage/` 和 PostgreSQL 数据。
- 如果大陆服务器拉取 Docker 镜像较慢，建议先在 Docker daemon 层配置稳定镜像源，再执行脚本。

---

This folder contains reference scripts for technical operators who want a faster, repeatable GEOFlow deployment path.

## Scripts

| Script | Purpose |
| --- | --- |
| `geoflow-docker-deploy.sh` | Production Docker one-click deployment. It checks the server, prepares `.env.prod`, deploys PostgreSQL, Redis, web, app, queue, scheduler and Reverb, then runs a healthcheck. |
| `geoflow-healthcheck.sh` | Post-deployment healthcheck. It validates Docker Compose services, the Laravel health endpoint and database connectivity. |

## Recommended Server Profile

Minimum for testing:

- 2 vCPU
- 2 GB RAM plus 2 GB swap
- 20 GB free disk
- Ubuntu 22.04+/Debian 12+/Rocky Linux 9+/Alibaba Cloud Linux 3+
- Stable outbound network access to Docker registry, GitHub and your AI provider APIs

Recommended for production:

- 2-4 vCPU
- 4-8 GB RAM
- 40-80 GB SSD
- Reverse proxy or cloud load balancer for HTTPS
- PostgreSQL and Redis ports not exposed to the public Internet

## One-Command Deployment

On a fresh server, run:

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/GEOFlow/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

The script will ask for:

- Public `APP_URL`
- Web port, default `18080`
- Reverb port, default `18081`
- Admin base path, default `geo_admin`

After deployment:

- Site: `APP_URL`
- Admin: `APP_URL/geo_admin/login`
- Default username: `admin`
- Default password: `password`

Change the default admin password immediately after first login.

## Non-Interactive Deployment

For CI, image templates or scripted server initialization:

```bash
GEOFLOW_NONINTERACTIVE=1 \
GEOFLOW_APP_URL=https://example.com \
GEOFLOW_APP_DIR=/opt/geoflow \
GEOFLOW_WEB_PORT=18080 \
GEOFLOW_REVERB_PORT=18081 \
GEOFLOW_ADMIN_BASE_PATH=geo_admin \
bash geoflow-docker-deploy.sh
```

Optional variables:

| Variable | Default | Description |
| --- | --- | --- |
| `GEOFLOW_REPO_URL` | `https://github.com/yaojingang/GEOFlow.git` | Source repository URL |
| `GEOFLOW_BRANCH` | `main` | Branch to deploy |
| `GEOFLOW_APP_DIR` | `/opt/geoflow` | Server installation directory |
| `GEOFLOW_INSTALL_DOCKER` | `auto` | `1` to install Docker automatically, `0` to fail if Docker is missing |
| `GEOFLOW_DB_PASSWORD` | random | PostgreSQL password |
| `GEOFLOW_REDIS_PASSWORD` | random | Redis password |
| `GEOFLOW_TRUSTED_PROXIES` | `*` | Trusted proxy setting for reverse proxy/CDN/subdirectory deployments |
| `GEOFLOW_SELF_DELETE` | `0` | Set to `1` to remove the deployment script after a successful deployment |

## Self-Delete Mode

If you download the script to a temporary location and want it removed after deployment:

```bash
GEOFLOW_SELF_DELETE=1 bash geoflow-docker-deploy.sh
```

This only removes the executed script file. It does not remove the deployed GEOFlow source code.

## Manual Healthcheck

Run after DNS, HTTPS or reverse proxy changes:

```bash
cd /opt/geoflow
bash deploy-scripts/geoflow-healthcheck.sh
```

## Subdirectory Deployment

If the site is deployed under a first-level path, for example:

```text
https://example.com/wiki
```

Use:

```env
APP_URL=https://example.com/wiki
TRUSTED_PROXIES=*
ADMIN_BASE_PATH=geo_admin
```

Do not set `ADMIN_BASE_PATH=wiki/geo_admin`. The reverse proxy should strip or forward the prefix correctly and pass:

```nginx
proxy_set_header X-Forwarded-Prefix /wiki;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
```

## Notes

- The scripts are references, not a replacement for server security hardening.
- Do not expose PostgreSQL or Redis to the public Internet.
- Keep `.env.prod`, `storage/`, and PostgreSQL data backed up before upgrades.
- If Docker image pulling is slow or unstable in mainland China, configure a stable registry mirror at the server level before running the script.
