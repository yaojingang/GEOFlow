# 项目结构说明

> [English version](STRUCTURE_en.md)

本文档用于固定当前项目的正式目录规范，便于后续继续整理时保持一致。

## 目录职责

### 根目录

根目录只保留站点入口和最常用的本地启动文件：

- `index.php`：前台首页
- `article.php`：文章详情页
- `category.php`：分类页
- `archive.php`：归档页
- `router.php`：本地开发环境路由器
- `start.sh`：本地启动入口

### `bin/`

只放 CLI 运行脚本：

- `bin/cron.php`：任务调度器
- `bin/worker.php`：队列消费进程（从 job_queue 拉取并执行任务）
- `bin/health_check_cron.php`：健康检查脚本

### `admin/`

后台页面、后台接口、任务启动入口、诊断页。

### `includes/`

配置、数据库封装、公共函数、AI 引擎、任务状态管理。

### `assets/`

静态资源，包括 CSS、JS、图片等。

### `data/`

SQLite 数据库、运行数据、备份文件。

### `logs/`

应用日志、任务日志、PID 文件、状态文件。

### `uploads/`

图片、知识库等上传内容。

### `docs/`

文档、归档、历史页面、维护脚本、分析记录。

## 约束规则

- 前台可访问页面不要放进 `docs/` 或 `bin/`
- CLI 脚本不要留在根目录，统一收口到 `bin/`
- 文档和历史兼容文件统一进入 `docs/`
- 后台业务文件集中在 `admin/`
- 新增脚本时先判断它属于运行脚本还是文档脚本，再决定放 `bin/` 还是 `docs/scripts/`

## 当前推荐心智模型

- `根目录 = 站点入口`
- `bin = 运行脚本`
- `admin = 后台`
- `includes = 核心逻辑`
- `docs = 文档与归档`
