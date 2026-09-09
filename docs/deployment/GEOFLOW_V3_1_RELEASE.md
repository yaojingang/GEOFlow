# GEOFlow v3.1.0 配套发布记录与流程

本轮版本：GEOFlow `3.1.0`、Updater `0.4.0`、签名更新序列 `3`。当前公开基线为 GEOFlow `3.0.0`、Updater `0.3.0`、序列 `2`；开始发布时重新核对远端状态，序列必须递增。

## 固定发布范围

- Core 以版本 PR 合并后的不可变提交为准，`version.json`、中英文更新日志、README 源码版本和发布说明保持一致。
- Updater 以配套修复 PR 合并后的 main 为准，二进制版本由候选工作流写入。发布重试保留原候选提交身份。
- Core 提供 `GEOFlow-v3.1.0.zip`、`GEOFlow-v3.1.0.zip.sha256` 和 `version.json`；ZIP 根目录为 `GEOFlow-3.1.0/`，内容来自该提交的 `git archive`。
- 内置 CLI 保持 `0.2.0`，Chrome 运营助手保持 `0.1.0`。

## 发布检查与顺序

1. 保留[前版发布手册](GEOFLOW_V3_RELEASE.md#零启用发布完整性保护)中的不可变 Release、受保护版本标签、具名发布身份和签名标签检查，使用本轮版本及实际证据。核对发布窗口内有写权限的身份、应用及自动化，记录权限清点结果。
2. 通过 PR 合并准备变更，确认 Core 的应用和 PostgreSQL CI、Updater CI、配套定向回归与独立复核通过。固定两个仓库的候选提交；运行时修复后重新构建候选。
3. 在发布窗口记录 `metadata-refresh.yml` 与 `targets-refresh.yml` 的原启用状态，暂停这两个会写入并部署元数据的工作流，避免候选来源漂移或中断发布的待公开目标提前进入 Pages。保留正常发布所需的候选、验收、发布及 Pages 工作流。
4. 从固定 Updater main 触发 `release-candidate.yml`，输入 `updater_version=0.4.0`、Core 最终 SHA、`geoflow_version=3.1.0` 和 `release_sequence=3`。记录候选 run ID，下载候选并核对版本、源码、档案、镜像、版本文档和升级计划摘要。
5. 运行 `planned-acceptance.yml`，两种原生架构分别完成容器检查、首次安装、旧版升级恢复、在线机制和同版本接管转换演练。审阅汇总证据后，由发布操作员、安全审阅者和产品负责人具名批准该候选；将完整 JSON 写入受保护环境并记录 SHA-256。以前的 RC 记录作为历史证据保留。
6. 从 Core 最终 SHA 生成并核对三个资产，创建签名标签 `v3.1.0` 和 Draft Release，重新下载比较字节与校验和。全部门槛通过后公开 Core，暂不提升 Latest，验证不可变 Release 及资产证明。
7. 按 [Updater 发布手册](https://github.com/yaojingang/geoflow-updater/blob/main/docs/release-runbook.md)触发 `release.yml`，使用同一候选和已批准证据，`superadmin_risk_waiver=false`。等待发布工作流和其触发的 Pages 工作流均成功，回读公共 TUF 签名链、Core `3.1.0`／序列 `3`／源码及镜像摘要，以及 Updater 两架构资产和发布授权。
8. 确认配套内容都可用后，将 Updater `v0.4.0` 和 Core `v3.1.0` 依次提升为 Latest；回读两处 Latest 下载入口、Core 版本文档和 Updater bootstrap。同步中英文教程与 Wiki 的最终结果。

完成后按原状态恢复两个元数据刷新工作流。若发布中止且签名目标尚未提交，核对旧稳定通道后恢复；若新 TUF 已提交，先用同一候选安全续跑并闭合资产与 Pages 状态，再恢复刷新。记录任何仍未完成的环节及停用状态。

## 用户升级入口

本次签名计划为维护模式。已受管 `3.0.0` 的首次升级需要宿主机 CLI 明确确认计划；未受管旧站先维护升级 Core 到匹配版本，再接管并转换布局。操作步骤见[中文说明](GEOFLOW_V3_1_UPGRADE.md)和 [English instructions](GEOFLOW_V3_1_UPGRADE_en.md)。

在线机制测试使用同应用代码的独立计划。正式旧新版本的数据库、队列、缓存和存储兼容性按实际版本对验证；该记录不会将本次维护发布标记为在线发布。
