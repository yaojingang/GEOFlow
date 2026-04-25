# GEOFlow

> Languages: [简体中文](README.md) | [English](docs/readme/README_en.md) | [日本語](docs/readme/README_ja.md) | [Español](docs/readme/README_es.md) | [Русский](docs/readme/README_ru.md)

> GEOFlow 是一套专门面向 GEO（生成式引擎优化）的开源智能内容工程系统，是全球最早围绕 GEO 场景系统化设计的数据、内容与分发基础设施之一。它把数据沉淀、知识库、素材管理、AI 生成、审核发布、前台展示与后续多端分发串联为一条可持续迭代的工作链路，目标是逐步演进为一套强大的“从数据到内容、从内容到多端发布”的 GEO 优化系统。

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

GEOFlow 以 [Apache License 2.0](LICENSE) 开源发布。你可以自由使用、复制、修改和分发本项目，包括商业使用；请保留版权声明和许可证文本，并遵守 Apache-2.0 的专利授权、商标与免责声明条款。

---

## ✨ 你可以用它做什么

| 特性 | 说明 |
|------|------|
| 🤖 多模型内容生成 | 兼容 OpenAI 风格接口，支持 chat / embedding 等模型类型、Provider URL 自动适配、智能模型切换与失败重试 |
| 📦 批量任务运行 | 任务创建、文章总数与发布节奏控制、队列执行、失败记录与任务文章筛选；可选 **Laravel Horizon** 监控 |
| 🗂 素材统一管理 | 标题库、关键词库、图片库、作者库、知识库、提示词集中管理 |
| 🧠 知识库 RAG | 知识库上传后自动切片；配置 embedding 模型后可写入向量并在生成时召回相关片段 |
| 📋 审核与发布工作流 | 草稿、审核、发布流程，可配置自动发布；文章管理支持状态、作者、任务等筛选 |
| 🔍 面向搜索展示优化 | 文章 SEO 元信息、Open Graph、结构化数据；前台 Markdown 支持标题、表格、列表、图片等渲染 |
| 🎨 前台与主题 | 默认主题、主题包、预览路径、后台主题切换；站点名称仅影响前台，后台品牌固定为 GEOFlow |
| 🌍 后台多语言 | 后台支持中文、英文、日语、西班牙语、俄语切换 |
| 🔔 版本提醒 | 后台可按 `version.json` 检查 GitHub 新版本，并在有新版本时提醒管理员 |
| 🐳 可直接部署 | **Docker Compose** 一键拉起 PostgreSQL（pgvector）、Redis、应用、队列、调度与 Reverb |
| 🗄 PostgreSQL 运行时 | 默认基于 PostgreSQL，适合稳定运行与并发写入 |

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

上述页面覆盖站点首页、任务调度、文章流程与模型配置等主链路；更多后台说明见 `docs/`（若目录中暂无截图资源，请本地补全或替换为你的截图路径）。

---

## 🆕 新版本重点

新版本重点变化包括：

- **后台体验**：固定后台品牌为 GEOFlow，支持多语言切换、管理员编辑/删除、首次欢迎页、版本更新提醒和仪表盘快速开始。
- **任务链路**：任务支持固定模型与智能模型切换；生成与发布分离，任务文章可从任务列表跳转到对应筛选结果。
- **素材体系**：素材库入口覆盖知识库、标题库、关键词库、图片库和作者库；知识库提供切片与向量化状态预览。
- **模型接入**：Provider URL 规则更清晰，兼容 OpenAI 风格接口以及智谱、火山方舟等非 `/v1` 路径；embedding 未配置时提供明确引导。
- **前台输出**：文章页 Markdown 采用 GFM 渲染，支持表格、标题、列表、图片；历史图片路径自动兼容 `/uploads` 与 `/storage/uploads`。
- **部署与安全**：支持自定义后台路径 `ADMIN_BASE_PATH`，生产建议使用 Nginx + PHP-FPM，默认管理员密码必须在上线前修改。

---

## 🏗 运行结构

```
后台管理页面
    ↓
任务调度器 / 队列（Horizon 可选）
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
| Web / Admin | **Laravel** 路由与控制器；前台文章站点与 **Blade** 后台；内容浏览、素材、任务与配置入口 |
| API | `routes/api.php` 等提供机器可调用的 HTTP 接口（鉴权以项目配置为准） |
| Scheduler / Queue / Reverb | **Laravel Scheduler** 扫描与入队；**`queue:work` / Horizon** 消费任务；**Reverb** 提供 WebSocket（按需启用） |
| Domain & Jobs | `app/Services`、`app/Jobs`、`app/Http/Controllers` 等承载业务规则与 GEO 任务流水线 |
| Persistence | **PostgreSQL**（推荐 **pgvector** 镜像与线上实例一致）+ **Redis**（队列/缓存等） |

核心链路：

1. 在后台配置模型、提示词与素材库
2. 准备知识库、标题库、关键词库、图片库和作者库
3. 创建任务并进入调度与队列
4. Worker（队列进程）调用模型生成正文与元数据
5. 文章进入草稿、审核、发布链路
6. 前台输出文章与 SEO 页面

---

## ⚡ 后台三步上手

登录后台后，建议按仪表盘里的「快速开始」完成第一轮验证：

1. **配置 API**：至少添加一个可用 chat 模型；如果需要知识库 RAG 召回，再添加一个 embedding 模型。
2. **配置素材库**：准备知识库、标题库、关键词库、图片库和作者。知识库建议先用真实、可验证的业务资料。
3. **新建任务**：选择标题库、素材、模型、生成数量和发布频率，先让文章进入草稿或审核流程，再逐步开启自动发布。

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
  把前台弱化，重点使用后台的模型配置、素材库、任务调度、审核发布与 API 能力。适合内容团队、增长团队、品牌团队做内部生产系统。
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

### 方式一：Docker（开发 / 演示）

```bash
# 1. 克隆仓库
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# 2. 复制环境变量
cp .env.example .env

# 3. 按需编辑 .env（数据库、Redis、APP_URL、ADMIN_BASE_PATH、REVERB_* 等）
vi .env

# 4. 构建并启动（含 postgres、redis、init、app、queue、scheduler、reverb）
docker compose build
docker compose up -d
```

- 前台默认访问：`http://localhost:18080`（端口由环境变量 **`APP_PORT`** 控制，默认 `18080`）
- 后台登录：`http://localhost:18080/geo_admin/login`（前缀由 **`ADMIN_BASE_PATH`** 控制，默认 `geo_admin`）

首次启动会运行 **`init`** 容器：在数据库就绪后执行首次迁移与种子（默认管理员见下文「默认管理员」）。

### 方式一补充：Docker（生产）

生产环境建议使用 **`docker-compose.prod.yml`**，改为 **`Nginx + php-fpm`**，而不是 `php artisan serve`。

```bash
cp .env.prod.example .env.prod
vi .env.prod

docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres redis
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d init
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d app web queue scheduler reverb
```

- 前台 / 后台统一经 `web`（Nginx）访问
- PHP 由 `app`（php-fpm）解析
- **默认管理员**：生产不会自动 `db:seed`，迁移成功后需手动执行一次（命令与账号见 `docs/deployment/DEPLOYMENT.md`「默认管理员（首次种子）」）
- 详细说明见 `docs/deployment/DEPLOYMENT.md`

### 方式二：本地 PHP 服务器

**前置要求：** PHP **8.2+**，启用 `pdo_pgsql`、`redis` 等 Laravel 常用扩展；本机已安装 **PostgreSQL** 与 **Redis**；已安装 **Composer 2.x**。

```bash
# 1. 克隆仓库
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# 2. 环境与依赖
cp .env.example .env
# 编辑 .env：将 DB_HOST/DB_* 指向本机 Postgres，REDIS_* 指向本机 Redis，QUEUE_CONNECTION=redis 等

composer install --no-interaction --prefer-dist
php artisan key:generate

# 3. 数据库与存储
php artisan migrate --force
php artisan db:seed --force    # 可选：写入默认管理员等
php artisan storage:link

# 4. 开发用 HTTP（仅本地调试；生产请用 Nginx + PHP-FPM，站点根目录 public/）
php artisan serve --host=127.0.0.1 --port=8080
```

另开终端启动常驻进程（与 Docker 中 `queue` / `scheduler` / `reverb` 对应）：

```bash
php artisan queue:work redis --queue=geoflow,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

- 后台：`http://127.0.0.1:8080/geo_admin/login`（若修改了 `ADMIN_BASE_PATH` 请替换路径）
- 生产可用 `php artisan horizon` 替代 `queue:work`（需按项目配置托管进程）

---

## 环境要求（部署检查清单）

| 组件 | 说明 |
|------|------|
| PHP | **8.2+**（Docker 镜像可为 8.4） |
| 扩展 | Laravel 常规扩展；PostgreSQL 需 `pdo_pgsql`；Redis 队列需 `redis` |
| Composer | 2.x |
| 数据库 | **PostgreSQL**（推荐 **pgvector**，与 `docker-compose.yml` 中镜像一致） |
| Redis | 队列、缓存等（本地极简调试可将 `QUEUE_CONNECTION` 改为 `sync`，生产不推荐） |

---

## 源码部署补充说明

**目录权限（Linux / macOS 常见）：**

```bash
chmod -R ug+rwx storage bootstrap/cache
```

**默认管理员（执行 `php artisan db:seed` 后，以 `Database\\Seeders\\AdminUserSeeder` 为准）：**

| 字段 | 值 |
|------|-----|
| 用户名 | `admin` |
| 密码 | `password`（**生产环境请立即修改**） |

### 管理员登录失败锁定与手动解锁

- 后台账号连续登录失败 **5 次** 会自动锁定（`status=locked`）。
- 被锁定账号无法继续登录，需管理员手动解锁。
- 解锁命令：

```bash
php artisan geoflow:admin-unlock <username>
```

例如：

```bash
php artisan geoflow:admin-unlock admin
```

**生产环境 Web：** 使用 Nginx / Apache + **PHP-FPM**，网站根目录指向项目 **`public/`**，勿将仓库根目录直接暴露为文档根。

---

## Docker 部署补充说明

### 开发 Compose 服务一览

| 服务 | 作用 |
|------|------|
| `postgres` | PostgreSQL 16 + pgvector |
| `redis` | Redis 7 |
| `init` | 一次性初始化（`restart: "no"`） |
| `app` | `php artisan serve`，映射 **`${APP_PORT:-18080}:8080`** |
| `queue` | `queue:work redis` |
| `scheduler` | `schedule:work` |
| `reverb` | WebSocket，映射 **`${REVERB_EXPOSE_PORT:-18081}:8080`** |

宿主机仅绑定 **127.0.0.1** 暴露数据库 / Redis 端口时，见 `docker-compose.yml` 中的 `DB_EXPOSE_PORT`、`REDIS_EXPOSE_PORT`。

### 入口脚本（`docker/entrypoint.sh`）常用变量

| 变量 | 默认 | 含义 |
|------|------|------|
| `COMPOSER_ON_START` | `true` | 容器启动时执行 `composer install` |
| `AUTO_MIGRATE` | `true` | 每次启动执行 `php artisan migrate --force` |
| `AUTO_INIT_ONCE` | 仅 `init` 为 `true` | 新库时执行一次 `migrate` + `db:seed` |
| `AUTO_GENERATE_APP_KEY` | `init` 内为 `true` | 无有效 `APP_KEY` 时自动生成 |
| `AUTO_SEED` | `false` | 为 `true` 时**每次**启动都 `db:seed`（慎用） |

Compose 将 **`./storage`** 与 **`./.env`** 挂载进容器；应用代码在镜像内。若要用于正式生产，请改用仓库新增的 **`docker-compose.prod.yml`**（`Nginx + php-fpm`），并参见 `docs/deployment/DEPLOYMENT.md`。

**升级建议：** `git pull` → `docker compose build` → `docker compose up -d`。

---

## 开发与测试

```bash
composer test
./vendor/bin/pint
```

---

## 🌍 多语言文档

- [English README](docs/readme/README_en.md)
- [日本語 README](docs/readme/README_ja.md)
- [Español README](docs/readme/README_es.md)
- [Русский README](docs/readme/README_ru.md)

---

## 📄 开源协议

本项目采用 [Apache License 2.0](LICENSE)。该协议允许个人和企业在遵守许可证声明、版权保留、修改说明、专利授权和免责声明等条款的前提下使用、修改、分发和商用 GEOFlow。

---

## ⭐ Star 趋势

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)
