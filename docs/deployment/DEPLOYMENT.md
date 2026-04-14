# GEOFlow 服务器部署文档

> Languages: [简体中文](DEPLOYMENT.md) | [English](DEPLOYMENT_en.md) | [日本語](DEPLOYMENT_ja.md) | [Español](DEPLOYMENT_es.md) | [Русский](DEPLOYMENT_ru.md)

更新时间：2026-04-14

## 1. 推荐方式

当前公开版本推荐使用 Docker Compose 部署。

推荐理由：

- 部署路径最短
- 默认包含 `web + postgres + scheduler + worker`
- 与仓库当前运行结构一致
- 避免旧版 SQLite / Caddy / PHP-FPM 文档带来的误导

如果你只是想快速上线，请优先阅读：

- `docs/deployment/DOCKER_DEPLOYMENT.md`

## 2. 最低要求

- Docker 24+
- Docker Compose v2
- 至少 2C / 2G / 20G
- 一台可访问外网的 Linux 服务器

## 3. 基础部署流程

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

cp .env.example .env.prod
vi .env.prod

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

部署完成后：

- 前台：`https://your-domain.com/`
- 后台：`https://your-domain.com/geo_admin/`

## 4. 必填配置

在 `.env.prod` 中至少确认：

```env
SITE_URL=https://your-domain.com
APP_SECRET_KEY=replace-with-a-long-random-secret

DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_NAME=geo_system
DB_USER=geo_user
DB_PASSWORD=change-this-password

HOST_PORT=18080
CRON_INTERVAL=60
TZ=Asia/Shanghai
REQUIRE_STRONG_APP_SECRET=true
```

## 5. 运行结构

- `web`
  - 提供前台和后台页面
- `postgres`
  - 提供运行时数据库
- `scheduler`
  - 负责任务扫描、入队、自动发布
- `worker`
  - 负责实际调用模型生成内容

## 6. 安全建议

- 首次部署后立刻修改后台管理员密码
- 生产环境必须替换 `APP_SECRET_KEY`
- 不要把 `.env.prod` 提交到仓库
- 不要默认对外暴露 PostgreSQL 端口
- 反向代理层建议只公开 HTTP/HTTPS

## 7. 更新与回滚

更新：

```bash
git pull origin main
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

回滚：

- 回退到目标 commit/tag
- 重新执行相同的 `docker compose ... up -d --build`

## 8. 说明

本文件只保留当前公开仓库对应的部署方式说明。

历史的 SQLite、本地脚本式部署、旧版 Caddy/PHP-FPM 方案不再作为当前公开版的推荐路径。
