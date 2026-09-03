# 独立更新工具 Phase C 发布门禁

Phase C 将 GEOFlow Updater 设为网站更新、完整备份和回滚的唯一执行边界。Laravel 保留版本元数据、安装包准备、本地代理桥接，以及旧运行和备份的只读历史。

升级期仍可读取旧的 `GEOFLOW_UPDATE_ARCHIVE_MAX_BYTES` 作为统一出站响应上限。请迁移到 `GEOFLOW_OUTBOUND_RESPONSE_MAX_BYTES`，后续版本会移除旧键回退。

## 发布前排空

在升级到 Phase C 代码前完成以下检查并保存证据：

- 停止新建内容、分发、知识库和更新任务。
- 确认 `system_update_runs` 中 `apply`、`rollback`、`rollback_file` 没有 `queued` 或 `running` 记录。
- 确认 Redis 或数据库队列中 `system-updates` 的待处理、延迟和保留任务均为 0。
- 保存旧 `storage/app/geoflow-updates`，升级过程不删除这些物理备份。
- 完成标准生产项目到受管项目的维护窗口切换。

Phase C 保留一版同名 tombstone Job。共享队列 worker 会临时监听 `system-updates`，遗留序列化任务被消费时只会把关联运行记录标记为 `failed`，错误码为 `legacy_executor_retired`。该兼容类和共享队列监听项可在下一次稳定发布、且所有环境均确认旧队列为空后删除。

受管 Phase B 环境仍存在 `geoflow-system-update-queue-prod` 时，更新中心会显示 updater 降级，并仅开放签名更新交接。更新预检只会在其余诊断全部通过时接受这一个具名过渡失败。静默阶段会停止遗留 worker，受管 Compose 会清理孤立容器，最终验收要求全部诊断通过。验收完成前，备份和网站回滚保持锁定。

## 授权配置

宿主机管理员执行：

```bash
sudo geoflow-updater authorization-uri --instance primary
```

把输出的更新、备份和回滚三个 URI 分别添加到受信任管理员的验证器。`mutation.secret` 仅保存在 updater 的 root-only 状态目录，不挂载到应用容器。每项操作使用名称匹配的 6 位授权码，已接受的计数器只能消费一次。连续五次无效尝试会触发持久化的 15 分钟锁定，后续失败会逐级延长。环境验收属于只读操作，无需授权码。

## 历史保留

`system_update_runs` 与 `system_update_backups` 不做破坏性迁移。更新中心默认展示最近 90 天记录，并通过归档视图查询更早数据。所有旧详情页只读，旧备份目录继续由运维按显式、路径受限的维护流程管理。

## 真实宿主演练

先由 GEOFlow Updater 的 `release-candidate.yml` 生成独立签名候选包，再使用同一候选包完成 linux/amd64 与 linux/arm64 演练。发布工作流只接受受保护环境中的完整证据 JSON，并复用候选包的二进制、TUF 目标和镜像摘要。任何待填、失败、身份不匹配或缺少审批的项目都会阻止发布。操作清单见 updater 仓库 `docs/phase-c-staging-rehearsal.md`。

GEOFlow 3.0 的 Core 标签、Release 资产、稳定元数据和 Updater 发布顺序见 [`GEOFLOW_V3_RELEASE.md`](GEOFLOW_V3_RELEASE.md)。
