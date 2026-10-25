# GEOFlow 文档中心

源码版本见 [`version.json`](../version.json)，当前正式版及下载入口见 [GitHub Latest Release](https://github.com/yaojingang/GEOFlow/releases/latest)。

## 安装与升级

- [3.1 升级说明](deployment/GEOFLOW_V3_1_UPGRADE.md) · [English instructions](deployment/GEOFLOW_V3_1_UPGRADE_en.md)：配套 Updater 0.4.0、旧版后台的首次升级、未受管站点桥接及维护窗口。
- [3.0 升级教程](deployment/GEOFLOW_V3_UPGRADE.md)：版本选择、备份、普通 Compose 升级、Updater 接管、数据回填、验收和故障恢复。
- [蓝绿部署与自动迁移教程](blue-green-deployment-usage.md) · [English tutorial](blue-green-deployment-usage_en.md)：签名安装、旧站接管、后台与 CLI 升级、备份和恢复；适用发布版本见教程开头。
- [生产 Docker 部署](deployment/DEPLOYMENT.md)：首次安装、环境配置、反向代理和运行进程。
- [初始化问题排查](deployment/docker-prod-init-troubleshooting.md)。
- [独立更新工具交接说明](deployment/SYSTEM_UPDATER_PHASE_C.md)。
- [中文更新日志](CHANGELOG.md) · [English changelog](CHANGELOG_en.md)。

## 管理与运营

- [知识文档导入](knowledge-import.md)：支持格式、OCR 要求及上传和解析限制。
- [AI 质检运行手册](ai-quality-inspection-runbook.md)。
- [管理员 AI 模型隔离与共享](admin-ai-config-sharing-runbook.md)。
- [AI 工作台与系统帮助](ai-workspace-runbook.md)。
- [平台网页人工辅助发布](distribution/platform-web-assisted-publishing.md)。
- [AI 可见性专题与词云](ai-visibility-operations.md)。
- [图片备注与智能配图](image-library-remarks.md)。
- [Chrome 运营助手](browser-operations-runbook.md)。
- [GEOFlow CLI](GEOFLOW_CLI.md) · [CLI English guide](GEOFLOW_CLI_en.md)。

## 开发与集成方案

- [GEOFlow 原生 MCP 与 ChatGPT 接入方案](plans/chatgpt-mcp-management-rfc.md)：维护者已批准文档方案并进入实施准备；单仓库、每实例授权、共享管理服务，包含 D01+D02 最小读取交接。仅交付文档，运行接入仍待实现。
- [原生 MCP 方案复核报告](reviews/chatgpt-mcp-management-review.md)：2026-10-25 按固定源码基线复查 20 项风险与缺口，含现有工作流版本/收据复用、OAuth 身份及恢复边界。
- [原生 MCP 实施与验收清单](plans/chatgpt-mcp-management-acceptance.md)：80 项运行验收全部 NOT_RUN；文档一致性、仓库 CI 及未来 MCP 运行验收分别报告。

## Wiki 与维护者资料

- [Wiki 首页](https://github.com/yaojingang/GEOFlow/wiki)：使用场景、知识库、分发、主题和常见问题。
- [Wiki 3.0 升级教程](https://github.com/yaojingang/GEOFlow/wiki/v3.0.0-升级教程)。
- [Wiki 蓝绿部署教程](https://github.com/yaojingang/GEOFlow/wiki/蓝绿部署与自动迁移教程) · [Wiki English tutorial](https://github.com/yaojingang/GEOFlow/wiki/Blue-Green-Deployment-and-Automatic-Migrations)。
- [3.1 配套发布流程](deployment/GEOFLOW_V3_1_RELEASE.md)：正式候选、双架构验收、签名资产和配套 Latest 顺序。
- [3.0 历史发布手册](deployment/GEOFLOW_V3_RELEASE.md)：标签、Release 资产和签名发布流程。

`plans/`、`reports/`、`reviews/` 中保留设计与评审记录。部署操作以对应正式版、升级教程及运行手册为准。
