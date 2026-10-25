# GEOFlow v3.1.0

GEOFlow 3.1 配套 Updater 0.4.0，增加蓝绿部署、自动迁移与完整恢复，并扩展 AI 任务创建、站点主题包和 AI 可见性分析。

## 新增

- **部署与恢复**：支持签名升级计划预检、迁移和回填、蓝绿布局转换、完整备份与恢复，后台分别提供应用回切和数据还原入口。
- **对话创建任务**：AI 工作台可通过对话补齐任务参数、生成可检查的草稿，并改善连接状态和失败提示。
- **主题包**：站点主题支持导入、隔离预览、安装和导出，已安装主题随应用升级保留。
- **AI 可见性**：支持关键词库批量采集、竞品识别、竞品提及统计和来源分析。
- **繁体中文**：补齐后台和编辑器文案。

## 修复

- 改善 GLM、MiniMax 的 AI 质检结果解析，避免 MiniMax 思考内容混入文章正文，并修复豆包搜索空域名过滤导致的请求失败。
- 修复知识库全屏编辑时的大纲显示，以及 PostgreSQL 任务恢复时的 UUID 查询错误。
- 配套 Updater 修复升级排空、启动健康等待、布局转换中断恢复和网络重建问题，支持新接管实例对同一签名发布执行一次维护布局转换。

## 升级提示

- 配套版本为 GEOFlow Core `3.1.0`、Updater `0.4.0`，内置 CLI `0.2.0`、Chrome 运营助手 `0.1.0`。
- 已受管 `3.0.0` 先安装 Updater `0.4.0`，首次升级通过服务器 CLI 预检并确认维护计划；未受管旧站先维护升级 Core 到签名源匹配版本，再接管。
- 本次发布使用维护模式计划，3.0.0 到 3.1.0 升级及首次蓝绿布局转换需要维护窗口；正式在线升级须有对应旧新版本的兼容计划。
- 升级前用本站数据副本验证备份和完整恢复；旧主题包若仅声明兼容 3.0.0，应由作者验证并更新兼容范围后重新导入。

操作步骤见 [3.1 升级说明](https://github.com/yaojingang/GEOFlow/blob/v3.1.0/docs/deployment/GEOFLOW_V3_1_UPGRADE.md)和[蓝绿部署教程](https://github.com/yaojingang/GEOFlow/wiki/蓝绿部署与自动迁移教程)。Core ZIP 包含源码，Docker 构建负责安装依赖和生成前端资源。

## English

GEOFlow 3.1 pairs with Updater 0.4.0 to add blue/green deployment, automatic migrations and full recovery, along with conversational task creation, site theme packages and AI visibility analysis.

### New features

- **Deployment and recovery**: Preview signed upgrade plans, run migrations and backfills, convert deployment layouts, and manage full backups, with separate admin actions for application switch-back and data restoration.
- **Conversational task creation**: Collect task parameters through AI Workspace, produce reviewable drafts, and see clearer connection and failure states.
- **Theme packages**: Import, preview in isolation, install and export site themes, while retaining installed themes across application upgrades.
- **AI visibility**: Collect keyword libraries in bulk, detect competitors, and analyze competitor mentions and sources.
- **Traditional Chinese**: Add admin and editor text.

### Fixes

- Improve GLM and MiniMax quality-result parsing, keep MiniMax reasoning out of article text, and fix Doubao search requests with empty domain filters.
- Restore the knowledge outline in fullscreen editing and fix UUID query errors during PostgreSQL task recovery.
- The companion updater fixes draining, startup health, interrupted layout recovery and network recreation, and allows newly enrolled instances to convert the same signed release once during maintenance.

### Upgrade notes

- Component versions are GEOFlow Core `3.1.0`, Updater `0.4.0`, bundled CLI `0.2.0`, and Chrome operations assistant `0.1.0`.
- Enrolled `3.0.0` sites install Updater `0.4.0` first and preview and confirm the first maintenance upgrade through the host CLI; unenrolled older sites first upgrade Core to the matching signed version, then enroll.
- This release uses a maintenance plan; upgrading from 3.0.0 to 3.1.0 and converting the initial blue/green layout require a maintenance window, while production online upgrades require a compatible plan for the actual version pair.
- Rehearse backup and full restoration with a copy of site data; theme packages restricted to 3.0.0 need author validation and an updated compatibility range before import.

See the [3.1 upgrade instructions](https://github.com/yaojingang/GEOFlow/blob/v3.1.0/docs/deployment/GEOFLOW_V3_1_UPGRADE_en.md) and [deployment tutorial](https://github.com/yaojingang/GEOFlow/wiki/Blue-Green-Deployment-and-Automatic-Migrations). The Core ZIP contains source; Docker builds install dependencies and generate frontend assets.
