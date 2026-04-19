# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> 一个面向 GEO / SEO 内容运营场景的开源内容生产系统。它把模型配置、素材管理、任务调度、草稿审核和前台发布串成一条完整链路，适合搭建自动化内容站点或内部内容运营后台。

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

Released under the Apache License 2.0.

---

## ✨ 你可以用它做什么

| 特性 | 说明 |
|------|------|
| 🤖 多模型内容生成 | 兼容 OpenAI 风格接口，可接入不同 AI 服务商 |
| 📦 批量任务运行 | 任务创建、定时调度、队列执行、失败重试 |
| 🗂 素材统一管理 | 标题库、关键词库、图片库、知识库、提示词集中管理 |
| 📋 审核与发布工作流 | 草稿、审核、发布三段式流程，可切换自动发布 |
| 🔍 面向搜索展示优化 | 文章 SEO 元信息、Open Graph、结构化数据 |
| 🎨 前台模板预览 | 支持 preview-first 主题包、模板预览与后台启用 |
| 🐳 可直接部署 | 支持 Docker Compose，本地和服务器都能跑 |
| 🗄 PostgreSQL 运行时 | 默认基于 PostgreSQL，适合稳定运行和并发写入 |

---

## 🖼 界面预览

<p>
  <img src="docs/images/screenshots/home.png" alt="GEOFlow 首页预览" width="48%" />
  <img src="docs/images/screenshots/task-management.png" alt="GEOFlow 任务管理预览" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/article-management.png" alt="GEOFlow 文章管理预览" width="48%" />
  <img src="docs/images/screenshots/ai-config.png" alt="GEOFlow AI 配置器预览" width="48%" />
</p>

这四个页面基本覆盖了站点首页、任务调度、文章流程和模型配置这几条主链路。其余后台页面说明保留在 `docs/`。

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

## 🧱 系统架构

| 层级 | 说明 |
|------|------|
| Web / Admin | 前台文章站点与后台管理页面，负责内容浏览、素材管理、任务管理和配置入口 |
| API / CLI | `/api/v1` 提供机器接口，`bin/geoflow` 提供本地 CLI 能力，适合批量任务和自动化接入 |
| Scheduler / Worker | 调度器负责扫描任务和入队，Worker 负责实际调用模型生成内容 |
| Domain Services | `includes/` 中的任务、文章、队列、AI、检索等服务承载核心业务规则 |
| Persistence | PostgreSQL 作为运行时数据库，保存任务、文章、素材、审核状态和系统配置 |

核心链路：

1. 后台配置模型、提示词和素材库
2. 创建任务并进入调度
3. 调度器写入 job queue
4. Worker 调用 AI 生成正文
5. 文章进入草稿、审核、发布链路
6. 前台输出文章与 SEO 页面

---

## 🎯 适用场景与目标收益

GEOFlow 适合这些真实且可落地的场景：

- **独立 GEO 官网**  
  把官网内容、产品资料、FAQ、案例和品牌知识组织成一个可持续更新的内容系统。目标是提升 AI 搜索可见度、品牌信源覆盖和内容运营效率，而不是堆砌低质量页面。
- **官网中的 GEO 子频道**  
  在现有官网下搭建一个独立的资讯、知识或解决方案频道。目标是让品牌内容更结构化、更适合搜索引用，也方便不同团队协同更新。
- **独立 GEO 信源站点**  
  面向某个行业、主题或问题域，持续沉淀高质量文章、榜单、解读、指南和资料。目标是构建稳定可信的外部内容资产，而不是做信息污染。
- **GEO 内容管理系统**  
  作为内部内容生产后台，统一管理模型、素材、标题、图片、知识库、审核和发布。目标是提升团队提效、降低重复劳动、减少分散工具切换。
- **GEO 多站点 / 多栏目部署**  
  用同一套系统管理多个站点、多个栏目或多个主题模板。目标是让内容生产、模板切换、分发和维护更标准化。
- **自动化信源管理与内容分发**  
  对知识库、专题内容、资讯更新和内容分发流程进行工程化管理。目标是让真正有价值的信息更稳定地被用户和 AI 理解、引用和检索。

这套系统的收益，应该建立在**真实、优质、持续维护的知识库**之上。  
我们不鼓励利用系统制造信息噪音、批量污染互联网或堆积虚假内容。GEOFlow 的本质是帮助团队更高效地管理、生产和分发可信内容，提升 AI 营销效率和 GEO 运营效率，而不是替代事实、替代判断或替代内容质量本身。

---

## 🧭 场景对应的部署与使用方式

不同场景下，建议这样使用 GEOFlow：

- **作为独立 GEO 官网运行**  
  直接部署完整前台与后台，围绕官网栏目、产品页延展内容、FAQ、案例和专题进行运营。适合希望把官网做成 AI 搜索友好型内容资产的团队。
- **作为官网中的 GEO 子频道运行**  
  将 GEOFlow 作为一个相对独立的内容频道部署，再通过导航、子域名或目录与主站打通。适合不想重构主站、但需要快速上线内容频道的团队。
- **作为 GEO 信源站运行**  
  单独维护一个面向特定主题的内容站点，把知识库和资料建设放在首位，再通过任务系统做稳定更新。适合想做行业型、专题型或问题导向型内容资产的团队。
- **作为内部 GEO 内容管理后台运行**  
  把前台弱化，重点使用后台的模型配置、素材库、任务调度、审核发布和 API / CLI / Skill 协同能力。适合内容团队、增长团队、品牌团队做内部生产系统。
- **作为多站点 / 多频道系统运行**  
  使用不同模板、栏目、域名或部署实例，管理多个内容出口。适合需要同时维护多个品牌频道、多个主题站或多个实验站点的团队。
- **作为自动化信源管理系统运行**  
  重点建设知识库、标题库、图片库和提示词体系，把系统当作一个内容工程与分发操作台。适合希望长期沉淀可信知识资产、再逐步扩展自动化能力的团队。

建议的使用顺序是：

1. 先确定真实的业务目标和目标读者  
2. 先建设知识库，再建设自动化流程  
3. 先确保内容真实、可核验、可维护  
4. 再用模型、任务和模板能力去提效  

如果知识库本身不真实、不完整、不稳定，再强的自动化也只会放大噪音。  
所以在 GEOFlow 里，**知识库建设应该始终排在最前面**。

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

这个项目配套提供了两个公开 skill：

- Skill 仓库：[yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- CLI 运维：`skills/geoflow-cli-ops`
- 前台模板复刻：`skills/geoflow-template`

适用场景：

- 通过本地 CLI 创建和管理任务
- 上传文章草稿
- 审核和发布文章
- 检查任务与 job 状态
- 基于参考网址生成 GEOFlow 兼容的前台主题包
- 输出 `tokens.json / mapping.json` 并走预览优先的模板迭代

相关文档：

- [前台模板预览与启用](docs/project/THEME_PREVIEW.md)

---

## 🌍 多语言文档

- [English README](README_en.md)
- [日本語 README](README_ja.md)
- [Español README](README_es.md)
- [Русский README](README_ru.md)
- [文档中心](docs/README.md)
- [Wiki 源稿（中英双语）](docs/wiki/README.md)

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
在“AI 配置中心 → AI 模型管理”里添加模型，填写 API 地址、模型 ID 和密钥。可使用**服务商快速填充**按钮一键预填常用服务商配置：

   | 服务商 | API 地址 | 模型 ID |
   |--------|---------|--------|
   | **MiniMax** | `https://api.minimax.io` | `MiniMax-M2.7` / `MiniMax-M2.7-highspeed` |
   | OpenAI | `https://api.openai.com` | `gpt-4o` |
   | DeepSeek | `https://api.deepseek.com` | `deepseek-chat` |
   | 智谱 GLM | `https://open.bigmodel.cn/api/paas/v4` | `glm-4.6` / `glm-5` |
   | 火山方舟 | `https://ark.cn-beijing.volces.com/api/v3` | 推理接入点 ID（如 `ep-xxxx`） |

   支持填写服务商基础地址或完整接口 URL。聊天模型默认补全 `/v1/chat/completions`，embedding 模型默认补全 `/v1/embeddings`；智谱 `/api/paas/v4` 与火山方舟 `/api/v3` 这类版本化基础地址会自动补全各自对应的 capability 路径。Rerank / 重排序接口当前尚未接入独立模型配置。

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

## 📁 目录结构

```text
GEOFlow/
├── index.php                     前台首页入口，负责文章列表与站点聚合展示
├── article.php                   文章详情页入口，负责正文、SEO 和相关文章渲染
├── category.php                  分类页入口，按分类聚合文章
├── archive.php                   归档页入口，用于按时间浏览内容
├── router.php                    本地开发路由入口，供 `php -S` 使用
├── docker-compose.yml            开发环境编排，启动 web / postgres / scheduler / worker
├── docker-compose.prod.yml       生产环境编排模板
├── start.sh                      本地快速启动脚本
├── .env.example                  环境变量模板
│
├── admin/                        后台管理系统
│   ├── dashboard.php             后台仪表盘与统计总览
│   ├── tasks.php                 任务管理页，查看任务状态、重试、执行情况
│   ├── task-create.php           新建任务页，配置标题库、模型、提示词和发布规则
│   ├── articles.php              文章列表页，查看草稿、已发布文章与流程状态
│   ├── articles-review.php       审核中心，处理待审核文章
│   ├── materials.php             素材管理入口，统一进入标题库、图片库、知识库等
│   ├── ai-models.php             AI 模型配置页，填写模型地址、ID 和密钥
│   ├── ai-prompts.php            提示词模板管理页
│   ├── site-settings.php         站点设置页，管理站点名称、SEO、前台配置
│   └── includes/                 后台公共模板、导航和页面骨架
│
├── api/v1/                       对机器开放的 API 层
│   └── index.php                 API 单入口，负责路由分发、鉴权和响应输出
│
├── assets/                       前端静态资源
│   ├── css/                      前后台样式文件
│   ├── js/                       前后台交互脚本
│   └── images/                   默认图片与静态图标资源
│
├── bin/                          CLI 与后台运行脚本
│   ├── geoflow                   本地 CLI，供 skill 和自动化脚本调用
│   ├── cron.php                  调度器，负责扫描任务并写入队列
│   ├── worker.php                常驻 Worker，负责实际调用 AI 生成内容
│   ├── db_maintenance.php        数据库维护工具
│   ├── migrate_sqlite_to_pg.php  历史迁移脚本
│   ├── api/                      API 辅助脚本，例如 token 创建
│   └── git/                      发布同步与开源检查脚本
│
├── docker/                       容器镜像与启动辅助脚本
│   ├── Dockerfile                Web / Scheduler / Worker 多阶段镜像定义
│   ├── entrypoint.sh             Web 容器启动入口
│   ├── scheduler.sh              调度容器启动入口
│   └── php.ini                   容器内 PHP 配置
│
├── docs/                         对外文档中心
│   ├── deployment/               安装与部署文档
│   ├── project/                  API、CLI、结构说明等研发文档
│   ├── 系统说明文档.md           系统整体功能说明
│   ├── AI_PROJECT_GUIDE.md       AI 相关核心模块说明
│   └── FAQ.md                    常见问题
│
├── includes/                     核心业务逻辑与服务层
│   ├── config.php                全局配置、常量和基础运行参数
│   ├── db_support.php            数据库驱动和连接辅助函数
│   ├── database.php              前台与基础数据访问封装
│   ├── database_admin.php        后台 schema 初始化和默认数据引导
│   ├── functions.php             公共函数、Markdown 渲染、后台登录辅助
│   ├── ai_engine.php             任务执行主引擎，串起标题、正文、插图和落库
│   ├── ai_service.php            通用 AI 请求封装
│   ├── job_queue_service.php     队列 claim / complete / fail / retry 逻辑
│   ├── task_service.php          任务基础服务
│   ├── task_lifecycle_service.php 任务启动、停止、入队等生命周期动作
│   ├── article_service.php       文章创建、更新、审核、发布服务
│   ├── api_auth.php              API Bearer 鉴权
│   ├── api_token_service.php     API token 生成与校验
│   └── catalog_service.php       CLI/API 用的基础资源字典输出
│
└── data/                         运行时数据目录占位；公开仓库不附带真实数据库和业务数据
```

目录约束：

- 前台入口文件放根目录，方便直接部署和路由映射
- `admin/` 放后台页面和后台动作入口
- `api/v1/` 放正式对外 API
- `bin/` 放 CLI、调度和维护脚本
- `includes/` 放核心业务逻辑和服务层
- `docs/` 只保留对外真正需要的文档

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
>
> 如需报告安全问题，请参见 [SECURITY.md](SECURITY.md)。

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
