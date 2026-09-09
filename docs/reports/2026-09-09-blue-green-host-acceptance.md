# 蓝绿部署与自动迁移主机验收记录

简体中文 | [English](2026-09-09-blue-green-host-acceptance_en.md)

核对日期：2026 年 9 月 9 日。

> **技术验收通过：** 同一签名候选在原生 amd64、arm64 上的 2 组容器检查、6 组完整主机演练全部成功，证据汇总校验通过。

## 候选与范围

- 配套 Updater 候选：`0.4.0-rc.4`，源码 `917b0acecd85d833ba41a6c04ddf279980c50697`。
- GEOFlow 应用：源码版本 `3.0.0`，提交 `02babc6f514ffbd0116dfeaa7e262cb655695d5b`，签名发布序列 `3`。
- 应用镜像由上述 main 提交构建。公开 v3.0.0 发布物仍为先前的稳定版本。
- [候选构建记录](https://github.com/yaojingang/geoflow-updater/actions/runs/34310152452)。候选镜像和签名仓库独立生成，稳定版发布标签及生产更新源保持原状。
- 在 GitHub 托管的临时 Linux 主机上分别原生运行 amd64、arm64。每种架构包含容器契约检查、完整升级恢复、首次安装重试和在线切换演练。

## 演练中发现并修复的问题

| 问题 | 修复及验证 |
|---|---|
| 首次安装缺少入口网络，应用槽无法启动 | 创建并校验本实例拥有的入口网络；拒绝接管同名的其他网络 |
| 启动恢复记录缺少恢复点或目标版本 | 恢复操作身份持久化，覆盖恢复失败、重试退避和显式恢复 |
| 旧版调度进程忽略退出信号，升级排空超时 | 冻结调度父进程，等待活动子任务完成；记录冻结意图，中断后恢复同一进程 |
| 应用已响应 HTTP，Docker 健康检查仍在启动期，安装提前失败 | 共享启动流程等待健康检查完成；真实容器测试验证延迟成功与持续不健康失败 |
| 布局转换在切流阶段中断，完整恢复遗漏候选数据库 | 授权恢复同时排空当前及事务记录中的部署；恢复数据前关闭不同于目标的基础设施，防止两套数据库共用目录 |
| 恢复后再次升级，停止的应用容器仍引用被删除的旧网络 | 删除已排空的两槽容器后再删除基础设施网络；完整恢复、切流前恢复和更换基础服务镜像的维护升级共用该顺序 |
| 新站实时消息配置不完整 | 补齐内部广播地址、端口、协议和服务路径 |

代码及验证见 [Updater PR #17](https://github.com/yaojingang/geoflow-updater/pull/17)、[PR #18](https://github.com/yaojingang/geoflow-updater/pull/18) 和 [PR #20](https://github.com/yaojingang/geoflow-updater/pull/20)。失败定位记录见[首次验收](https://github.com/yaojingang/geoflow-updater/actions/runs/34298401281)、[第二次验收](https://github.com/yaojingang/geoflow-updater/actions/runs/34303691010)和[第三次验收](https://github.com/yaojingang/geoflow-updater/actions/runs/34308204922)。

恢复回归同时覆盖更换恢复点、恢复中断后重启、反向布局恢复、保留目标基础设施，以及在任何 Docker 操作前拒绝不匹配的实例根目录、基础设施路径和父目录符号链接。Go 全量竞态测试、静态检查和独立复核通过。

第三轮中，两种架构的容器检查和首次安装重试通过。在线演练切换测试更新源后，脚本早于重启后的控制接口就绪而访问升级计划。[Updater PR #19](https://github.com/yaojingang/geoflow-updater/pull/19) 增加真实接口就绪等待、候选版本核对和脱敏失败堆栈；延迟接口、超时及错误版本回归通过。该修复只涉及测试流程，复测继续使用同一份签名候选。

第三份候选的[在线复测](https://github.com/yaojingang/geoflow-updater/actions/runs/34309123244)在两种架构通过。随后将网络清理修复纳入第四份候选，重新执行完整验收。网络回归先在真实 Docker 复现相同错误，再验证两次连续恢复及双槽重建；三个调用路径和任一槽清理失败的九组用例均通过。

## 最终验收结果

[最终工作流](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078)的全部 9 个任务成功，含最后的证据汇总。

| 验证范围 | 原生 amd64 | 原生 arm64 |
|---|---|---|
| 应用、迁移、入口及真实容器回归 | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102341511277) | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102341511143) |
| 首次安装与中断重试 | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198405) | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198399) |
| 完整升级、备份还原与中断恢复 | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198415) | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198363) |
| 在线切换与保留数据的应用回切 | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198546) | [通过](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198476) |

完整恢复核对 PostgreSQL、Redis、存储文件、环境配置、版本、实例及部署文件和迁移记录。10 个升级中断点、恢复失败后的重试退避与授权恢复、恢复后的再次升级、手动备份及完整还原均通过，真实管理员会话在升级后保持可用。

在线演练中，amd64 完成 499 轮、arm64 完成 506 轮公共健康与已登录后台页面探测，错误均为 0。每种架构的升级与回切各交接 20 个待执行任务，均在目标槽执行一次；旧实时连接收到新槽消息，重连成功，数据库、Redis 与文件的新写入在应用回切后保留。

完整候选摘要及逐项结果原样归档于[技术证据 JSON](2026-09-09-blue-green-host-acceptance-evidence.json)，已通过候选身份和必要用例校验。发布操作员、安全审阅者及产品负责人的发布审批字段保留为 `pending`，由相应审批人另行填写。

## 使用边界

在线演练使用与主候选相同的应用代码，仅替换单独签名的在线升级计划，用于验证切流、登录会话、待执行任务交接、实时消息跨槽传递、重连及保留数据的应用回切。正式旧版本与新版本之间的数据库、任务载荷、缓存和存储兼容性，需要按实际版本对验证。

本轮主候选使用维护模式计划。技术验收、发布审批和稳定版发布分别记录；本文不代表已发布新的稳定版本。站点管理员按[使用教程](../blue-green-deployment-usage.md)安装配套版本，并用本站数据副本确认业务结果和备份、迁移、恢复耗时。
