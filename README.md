# GEOFlow

> 一个面向 GEO / SEO 内容运营场景的开源内容生产系统。它把模型配置、素材管理、任务调度、草稿审核和前台发布串成一条完整链路，适合搭建自动化内容站点或内部内容运营后台。

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)

---

## ✨ 你可以用它做什么

| 特性 | 说明 |
|------|------|
| 🤖 多模型内容生成 | 兼容 OpenAI 风格接口，可接入不同 AI 服务商 |
| 📦 批量任务运行 | 任务创建、定时调度、队列执行、失败重试 |
| 🗂 素材统一管理 | 标题库、关键词库、图片库、知识库、提示词集中管理 |
| 📋 审核与发布工作流 | 草稿、审核、发布三段式流程，可切换自动发布 |
| 🔍 面向搜索展示优化 | 文章 SEO 元信息、Open Graph、结构化数据 |
| 🐳 可直接部署 | 支持 Docker Compose，本地和服务器都能跑 |
| 🗄 PostgreSQL 运行时 | 默认基于 PostgreSQL，适合稳定运行和并发写入 |

---

## 🏗 运行结构

```
后台管理页面
    ↓
任务调度器 / 队列
    ↓
Worker 执行 AI 生成
    ↓
草稿 / 审核 / 发布
    ↓
前台文章与 SEO 页面输出
```

---

## 🚀 快速开始

### 方式一：Docker（推荐）

```bash
# 1. 克隆仓库
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# 2. 复制环境变量文件
cp .env.example .env

# 3. 编辑 .env，设置必要参数（见下方配置说明）
vi .env

# 4. 启动 Web、PostgreSQL、调度器与 Worker
docker compose --profile scheduler up -d --build

# 访问前台
open http://localhost:18080

# 访问后台
open http://localhost:18080/geo_admin/
```

### 方式二：本地 PHP 服务器

**前置要求:** PHP 7.4+，开启 `pdo_pgsql`、`curl` 扩展，并准备本地 PostgreSQL

```bash
# 1. 克隆仓库
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# 2. 配置数据库环境变量
export DB_DRIVER=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_NAME=geo_system
export DB_USER=geo_user
export DB_PASSWORD=geo_password

# 3. 启动开发服务器
php -S localhost:8080 router.php

# 访问后台
open http://localhost:8080/geo_admin/
```

## 🤝 配套 Skill

这个项目配套提供了一个公开 skill，用于通过本地 `geoflow` CLI 操作 GEOFlow 系统：

- Skill 仓库：[yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill 路径：`skills/geoflow-cli-ops`

适用场景：

- 通过本地 CLI 创建和管理任务
- 上传文章草稿
- 审核和发布文章
- 检查任务与 job 状态

---

## ⚙️ 环境变量配置

复制 `.env.example` 为 `.env` 并按需修改：

```dotenv
# Web 服务对外暴露端口（默认 18080）
HOST_PORT=18080

# 站点访问地址（需与 HOST_PORT 对应）
SITE_URL=http://localhost:18080

# 应用安全密钥（建议使用 32 位以上随机字符串）
APP_SECRET_KEY=replace-with-a-long-random-secret

# Cron 调度间隔（秒，默认 60）
CRON_INTERVAL=60

# 时区
TZ=Asia/Shanghai
```

---

## 📖 上手流程

1. 登录后台  
访问 `/geo_admin/`，使用管理员账号进入后台。默认管理员用户名和密码：`admin / admin888`，登录后可自行修改。

2. 配置 AI 模型  
在“AI 配置中心 → AI 模型管理”里添加模型，填写 API 地址、模型 ID 和密钥。

3. 准备素材  
创建标题库、图片库、知识库和提示词模板。

4. 创建任务  
在“任务管理”里选择标题库、模型、提示词、图片库和发布规则。

5. 启动生成  
任务进入调度与 worker 执行链路，文章会按配置生成到草稿或直接发布。

> 首次部署后，建议立刻修改管理员密码和 `APP_SECRET_KEY`。

---

## 🔄 内容生成流程

```
配置模型 / 素材 / 提示词
        ↓
创建任务
        ↓
调度器入队
        ↓
Worker 调用 AI 生成正文
        ↓
可选插图 / SEO 元信息
        ↓
草稿 / 审核 / 发布
        ↓
前台展示
```

---

## 🐳 Docker 组件

| 服务 | 说明 | 默认启动 |
|------|------|----------|
| `web` | 提供前后台 HTTP 访问 | ✅ |
| `postgres` | PostgreSQL 数据库 | ✅ |
| `scheduler` | 任务调度器 | `--profile scheduler` |
| `worker` | 常驻生成进程 | `--profile scheduler` |

```bash
# 仅启动 Web（不含调度）
docker compose up -d

# 启动完整服务（含调度器和 Worker）
docker compose --profile scheduler up -d

# 查看完整服务日志
docker compose logs -f
```

---

## 🛡 安全说明

- 所有数据库操作使用 **PDO 预处理语句**，防止 SQL 注入
- 表单提交均验证 **CSRF Token**
- 输出内容经过 **HTMLSpecialChars** 转义，防止 XSS
- 管理员密码使用 **bcrypt** 加密存储
- 支持配置安全响应头（X-Frame-Options、X-Content-Type-Options 等）

> ⚠️ 生产部署前请务必修改 `.env` 中的 `APP_SECRET_KEY`，并更新默认管理员密码。

---

## 📚 文档与扩展

详细文档见 [`docs/`](docs/) 目录：

- [系统说明文档](docs/系统说明文档.md) - 完整功能说明
- [AI 开发指南](docs/AI_PROJECT_GUIDE.md) - 核心类与架构说明
- [本地环境配置](docs/本地环境配置指南.md) - 开发环境搭建
- [部署文档](docs/deployment/DEPLOYMENT.md) - 服务器部署步骤
- [配套 Skill 仓库](https://github.com/yaojingang/yao-geo-skills) - `geoflow-cli-ops`

---

## 📌 当前开源仓库定位

- 提供可运行的公开源码版本
- 不附带生产数据库、上传文件和真实 API 密钥
- 适合作为二次开发基础，或用于自建 GEO 内容站点
