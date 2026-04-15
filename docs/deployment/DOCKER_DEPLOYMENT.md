# Docker 部署文档

> [English version](DOCKER_DEPLOYMENT_en.md)

更新时间：2026-04-14

## 1. 适用范围

本文档适用于当前公开仓库的 Docker Compose 部署方式。

- `docker-compose.yml`
  - 开发环境
  - 挂载源码，便于本地调试
- `docker-compose.prod.yml`
  - 生产环境
  - 使用镜像内代码，不挂载源码
  - 默认包含 `web + postgres + scheduler + worker`

## 2. 服务结构

- `web`
  - 提供前台与后台 HTTP 访问
- `postgres`
  - PostgreSQL 运行时数据库
  - 生产编排默认不对宿主机暴露端口
- `scheduler`
  - 轮询执行 `bin/cron.php`
- `worker`
  - 常驻消费任务队列并调用 AI 生成内容

## 3. 环境变量

从模板复制一份生产配置：

```bash
cp .env.example .env.prod
```

至少修改以下变量：

```env
HOST_PORT=18080
SITE_URL=https://your-domain.com
APP_SECRET_KEY=replace-with-a-long-random-secret

DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_NAME=geo_system
DB_USER=geo_user
DB_PASSWORD=change-this-password

CRON_INTERVAL=60
TZ=Asia/Shanghai
REQUIRE_STRONG_APP_SECRET=true
```

说明：

- `APP_SECRET_KEY` 必须长期稳定保存
- `DB_HOST=postgres` 表示容器内连接 Compose 内的 PostgreSQL 服务
- 如需从宿主机连接数据库，请自行在 Compose 覆盖文件中显式暴露 PostgreSQL 端口，不建议默认对外开放

## 4. 启动生产环境

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

查看状态：

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod ps
```

查看日志：

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f web
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f scheduler
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f worker
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f postgres
```

## 5. 数据持久化

生产环境使用以下命名卷：

- `pgdata`
- `geo_data`
- `geo_uploads`
- `geo_logs`

对应关键数据：

- `pgdata`
  - PostgreSQL 数据文件
- `geo_uploads`
  - 图片库、知识库上传文件
- `geo_logs`
  - 运行日志
- `geo_data`
  - 运行时辅助数据

## 6. 更新部署

拉取新代码后：

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

仅重启服务：

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod restart
```

## 7. 停止与卸载

停止容器：

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod down
```

停止并删除卷：

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod down -v
```

`down -v` 会删除 PostgreSQL 数据、上传文件和日志卷。

## 8. 当前约束

- 当前公开版运行时数据库为 PostgreSQL
- 生产编排默认不暴露 PostgreSQL 到宿主机网络
- `scheduler` 与 `worker` 默认随生产环境一起启动
- AI 模型密钥加密依赖 `APP_SECRET_KEY`
