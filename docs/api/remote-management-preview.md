# GEOFlow 远程管理预览版覆盖说明

版本：CLI `0.3.0-preview.1`；管理协议 `1.0`。本页描述当前实现，完整目标见已确认升级方案 v1.1。

## 已实现的入口

- 无 Core 依赖的独立 PHAR 构建、本地签名包校验安装、升级中断恢复；官方分发与正式密钥尚未发布。
- 显式 scope 登录、多 profile、身份/能力查询、当前令牌撤销。
- 旧文章、任务、素材、目录与作业命令继续保留。
- 任务入队的实例/账号/客户端请求 ID 收据、响应丢失后查询及重新登录续接。
- 主站只读配置；内置及安装主题发现、草稿、不可变文件版本、分块读取、原子文件修改、代码授权、真实内容预览、丢弃与受保护清理。
- 后台外观写入共用配置锁与快照摘要，冲突返回 409。

`api tasks.enqueue` 使用 `--client-request-id` 续接；旧 `task enqueue` 继续使用 `--idempotency-key`。收据操作拒绝后者，服务端也拒绝同时提供两种请求头，避免去重保护被静默忽略。

草稿创建和修改当前没有新管理收据，不能自动重发。`idempotent` 声明只用于已实现相应保护的操作。文件上限 5 MiB、单次文本读取 256 KiB、JSON 修改批次 1 MiB；大文件传输仍待实现。原生 Blade 必须由获得显式代码权限和短期草稿授权的可信超级管理员执行。

## 明确未开放

`capabilities.theme_publication.available` 为 `false`。发布计划、统一 apply、原子绑定与收据、字段级回滚、发布回读、升级联锁、一致备份与恢复验收尚未闭合。当前没有主题发布 API。部分模型与表属于后续实现所需的数据基础；它们不代表发布能力已经可用。

A 的剩余工作包括统一新旧操作契约、完整业务 schema、官方签名分发、CLI 内 skill 安装入口。B 还包括已发布版本来源、配置 patch、搜索/大文件暂存、检查计划、定时清理及完整发布回滚验收。C/D/E 的完整后台、托管站/Agent、多副本与跨平台稳定版验收仍为后续批次。

## 覆盖台账与契约

- [management-coverage.json](management-coverage.json)：完整后台路由清单、旧 CLI 操作、新管理操作及分批未完成项。当前为 355 条后台路由、37 个旧 CLI 操作、18 个管理操作。
- [management-openapi.json](management-openapi.json)：从同一管理注册表导出的 OpenAPI 3.1 预览契约；嵌套业务 schema 尚不完整，以 `x-schema-completeness` 明示。
- 后台路由的 `pending_domain_mapping` 表示领域操作映射尚待逐项审核，不能按路由数量声称管理覆盖率。后台页面路由和领域操作并非一一对应。
- 使用 `php scripts/export-management-coverage.php` 更新；CI 运行 `--check` 阻止清单漂移。此脚本只导出静态路由元数据，不读取用户凭据或业务记录。仓库台账使用默认后台前缀 `geo_admin`；本机 `.env` 自定义前缀时，用 `ADMIN_BASE_PATH=geo_admin php scripts/export-management-coverage.php --check` 复核公开台账。

## 数据升级

新增 5 个表已纳入 `deployment/upgrade-plan.json` 的 163 项迁移清单，沿用维护模式，新增迁移未标记为可在线执行。此更新修复升级清单遗漏；受管主题与升级、备份系统的完整联锁仍待实现。不要据此启用尚未开放的发布能力。

## 使用与验收

运行实例操作优先使用 [skill 的远程 CLI 流程](../../.agents/skills/geoflow/references/remote-cli-workflow.md)。本地测试、模拟服务、实际安装测试与真实线上验收分开记录。完整 A+B 验收要求两类主题各两轮修改、预览、发布、回滚；当前仅草稿/预览层可测试，不满足该完整验收。
