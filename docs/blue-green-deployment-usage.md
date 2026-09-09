# GEOFlow 蓝绿部署与自动迁移使用教程

简体中文 | [English](blue-green-deployment-usage_en.md)

面向站点管理员和服务器管理员，涵盖首次安装、旧站接管、后台升级、自动迁移、备份和恢复。

本文依据 [GEOFlow PR #122](https://github.com/yaojingang/GEOFlow/pull/122) 与 [updater PR #16](https://github.com/yaojingang/geoflow-updater/pull/16) 合并后的实现编写。后续恢复修复及完整双架构结果见[主机验收记录](reports/2026-09-09-blue-green-host-acceptance.md)。

> **版本前提：** 本教程的新流程要求正式发布的 [GEOFlow v3.1.0](https://github.com/yaojingang/GEOFlow/releases/tag/v3.1.0)、[Updater v0.4.0](https://github.com/yaojingang/geoflow-updater/releases/tag/v0.4.0)、配套镜像和已签名升级计划。确认两个 Release 和签名更新源均已公开后执行；源码分支的版本号本身不代表发布完成。旧站首次升级路径见 [3.1 升级说明](deployment/GEOFLOW_V3_1_UPGRADE.md)。

## 1. 选择使用路径

[GEOFlow Updater](https://github.com/yaojingang/geoflow-updater) 是运行在宿主机上的独立更新工具，负责签名安装、升级、备份和恢复。

| 当前情况 | 操作路径 |
|---|---|
| 新服务器，尚未安装 GEOFlow | [安装 GEOFlow Updater](#3-安装或升级-updater) → [首次安装站点](#4-新服务器首次安装站点) → [配置授权](#6-配置后台操作授权) → [验收](#9-自动迁移与升级验收) |
| 已有标准 Docker 站点，尚未受管 | [安装 GEOFlow Updater](#3-安装或升级-updater) → [维护窗口接管](#5-已有站点接管并转换布局) → [配置授权](#6-配置后台操作授权) → [计划升级转换布局](#8-日常升级服务器命令) |
| 已有受管站点，日常升级 | [后台获取计划并升级](#7-日常升级后台操作)，或使用[服务器命令](#8-日常升级服务器命令) |
| 升级后出现问题 | [核对失败状态](#11-常见问题与中断处理)，选择[应用回切](#102-应用回切保留当前数据)或[完整数据恢复](#103-数据恢复回到完整恢复点) |

本文统一使用实例 `primary`、目录 `/opt/geoflow`、域名 `https://geo.example.com`。执行前替换实际目录和域名，实例名仍使用当前支持的 `primary`。

所有 `sudo geoflow-updater ...` 命令均在 **Linux 宿主机** 执行。后台操作需要超级管理员权限。

## 2. 哪些升级能不停机

blue、green 是两套应用运行位置。升级时，工具在另一套位置准备新版本，检查通过后切换稳定入口，再完成旧请求和后台任务的排空交接。

数据库、Redis 和业务文件由新旧应用共享。每次能否在线升级，由签名计划和实际预检共同决定：

| 预检结果 | 需要怎样操作 |
|---|---|
| `strategy: online` | 在线蓝绿升级，候选验证后切流；长连接可能重连 |
| `strategy: maintenance` | 安排维护窗口，暂停服务和写入后升级 |
| `layout_change: true` | 首次转换为蓝绿布局，必须安排维护窗口 |
| 预检失败 | 排查后重新预检，本次更新尚未开始 |

当前仓库默认计划为 `maintenance`。发布者逐项验证来源版本、数据库、队列、缓存、存储及迁移步骤的兼容性，签名发布在线计划后，对应路径才能在线升级。待执行迁移为 0，也可能因为业务回填、基础服务镜像变化或布局转换而需要维护。

为两套应用同时运行预留 CPU、内存和磁盘，并按实际负载确认容量。当前方案支持单机部署；服务器、数据库或 Redis 故障仍会影响整站。

## 3. 安装或升级 updater

### 3.1 准备服务器

支持 Linux、systemd、Docker Engine 和 Docker Compose v2，CPU 架构为 amd64 或 arm64。当前受管部署使用随站点部署的 PostgreSQL，外置数据库和多机部署不在本教程范围内。

```bash
uname -m
docker version
docker compose version
systemctl --version
```

`x86_64` 对应 amd64，`aarch64` 对应 arm64。updater 安装脚本会检查 Docker，但不会安装 Docker。

### 3.2 下载并校验

从 [updater Releases](https://github.com/yaojingang/geoflow-updater/releases) 选择明确包含本轮功能的正式版本，下载对应架构的压缩包与 `checksums.txt`，放入专用目录。

本轮配套版本为 `0.4.0`，命令需要已安装 GitHub CLI：

```bash
UPDATER_VERSION='0.4.0'
UPDATER_ARCH='amd64'
UPDATER_ARCHIVE="geoflow-updater_${UPDATER_VERSION}_linux_${UPDATER_ARCH}.tar.gz"

gh attestation verify "$UPDATER_ARCHIVE" --repo yaojingang/geoflow-updater
sha256sum --check checksums.txt --ignore-missing
```

确认所选压缩包的证明验证成功、校验结果为 `OK`，再解压和查看安装脚本。任何校验失败都应先查明原因。

```bash
mkdir geoflow-updater-package
tar -xzf "$UPDATER_ARCHIVE" -C geoflow-updater-package
cd geoflow-updater-package
less packaging/scripts/install.sh
sudo ./packaging/scripts/install.sh
sudo geoflow-updater version
sudo systemctl status geoflow-updater --no-pager
```

已有 updater 的主机也用这条流程升级工具本身。执行前确认没有正在运行的安装、更新、备份或恢复任务；安装脚本会替换二进制并重启服务。

## 4. 新服务器：首次安装站点

### 4.1 配置域名入口

把域名解析到服务器，通过外部 HTTPS 反向代理转发到宿主机入口，默认端口为 `18080`。代理与应用位于同一宿主机时，通常转发到 `http://127.0.0.1:18080`；代理在容器内时，使用它能够访问的宿主机地址。

代理需保留 Host、HTTPS 转发信息，支持 WebSocket 和长连接，避免缓冲流式响应。TLS 证书由外部代理管理；结合防火墙限制 18080 端口来源。

`--url` 使用最终公开地址，只包含协议、主机和可选端口，不带后台路径、查询参数或账号密码。

### 4.2 执行安装

前提：已完成 [updater 安装](#3-安装或升级-updater)，发布源已有配套签名计划，目标目录不存在或为空。已有业务的目录使用[第 5 节的接管流程](#5-已有站点接管并转换布局)。

```bash
sudo geoflow-updater install \
  --instance primary \
  --root /opt/geoflow \
  --url https://geo.example.com
```

工具自动生成密钥和随机凭据，准备数据库与存储，拉取签名镜像，执行初始化与升级步骤，启动服务并检查。

首次安装中断后，排除原因，用**相同实例、目录和 URL** 重复这条命令。工具按安装记录续接，保留已有密钥和数据；不要先删除目录重新初始化。

### 4.3 获取账号

```bash
sudo cat /opt/geoflow/install-credentials.txt
```

在自己的受控终端查看，按文件中的地址登录，修改初始密码并更新管理员邮箱。文件含明文初始凭据，应按密码材料保管。默认后台前缀为 `/geo_admin`；自定义过前缀的站点以实际配置为准。

继续完成[第 6 节授权](#6-配置后台操作授权)和[第 9 节验收](#9-自动迁移与升级验收)。系统部署完成后，AI 模型密钥、模型选择及业务参数仍需在后台配置。

## 5. 已有站点：接管并转换布局

已有受管实例直接进入[第 7 节后台操作](#7-日常升级后台操作)或[第 8 节服务器命令](#8-日常升级服务器命令)。本节 Compose 命令只适用于**尚未受管的标准单套 Docker 部署**。

### 5.1 接管前准备

站点目录应包含 `.env.prod`、`storage/` 和当前 `version.json`。接管要求当前版本信息与签名发布相匹配。若版本不匹配，先按 [3.1 升级说明](deployment/GEOFLOW_V3_1_UPGRADE.md)维护升级至签名源匹配版本，不要手改 `version.json` 通过检查。

接管保留配置中的 PostgreSQL、Redis 主版本。支持 PostgreSQL 16、18 和 Redis 7、8，需要确认镜像主版本与实际数据目录一致。数据库大版本迁移需单独安排。

预留维护窗口，停止新增任务，确认待处理、延迟和运行中的队列已排空，并保存部署配置与可恢复备份。旧版 Redis 若未持久化，停止旧容器可能丢失待处理任务。

### 5.2 登记并切换到受管服务

```bash
sudo geoflow-updater enroll \
  --instance-id primary \
  --instance-root /opt/geoflow
```

首次安装使用 `--instance / --root`，接管使用 `--instance-id / --instance-root`，参数名称不同。建议使用 `/opt` 下的专用站点目录，服务隔离的临时目录或用户主目录可能被拒绝。

在维护窗口按 `enroll` 输出的实际路径完成接管。标准示例如下，两份环境文件都要保留：

```bash
sudo docker compose \
  --env-file /opt/geoflow/.env.prod \
  --env-file /var/lib/geoflow-updater/instances/primary/release.env \
  -f /var/lib/geoflow-updater/instances/primary/docker-compose.managed.yml \
  down --remove-orphans

sudo docker compose \
  --env-file /opt/geoflow/.env.prod \
  --env-file /var/lib/geoflow-updater/instances/primary/release.env \
  -f /var/lib/geoflow-updater/instances/primary/docker-compose.managed.yml \
  up -d --remove-orphans
```

这一步会停止旧服务，再启动受管服务。完成[第 6 节授权](#6-配置后台操作授权)后，执行[第 9 节验收](#9-自动迁移与升级验收)。

**接管完成后，还需执行一次计划升级转换为蓝绿布局。** 按[第 7 节后台操作](#7-日常升级后台操作)或[第 8 节服务器命令](#8-日常升级服务器命令)获取计划；显示“将迁移到蓝绿部署”时，确认维护窗口再执行。接管命令本身不会完成布局转换。

## 6. 配置后台操作授权

宿主机管理员执行：

```bash
sudo geoflow-updater authorization-uri --instance primary
```

把输出的三个 URI 导入受信任管理员的验证器：

| 验证器条目 | 对应后台操作 |
|---|---|
| `update` | 检查并安全更新、应用回切 |
| `backup` | 创建完整备份 |
| `rollback` | 恢复数据与版本 |

URI 含授权秘密，不要放入工单、聊天记录或公开截图。每次使用与操作对应的新 6 位码，已接受的码不能重复使用。连续输错会触发锁定，先核对条目和验证器时间。

后台默认还要求当前管理员密码，是否显示以站点配置为准。获取计划和运行环境验收无需操作授权码；服务器 CLI 依靠主机管理员权限执行。

## 7. 日常升级：后台操作

已有受管 `3.0.0` 的首次升级应按 [3.1 升级说明](deployment/GEOFLOW_V3_1_UPGRADE.md)通过宿主机 CLI 确认维护计划；升级到 3.1 后再使用本节后台入口。

1. 用超级管理员打开“系统更新”，默认路径为 `/geo_admin/system-updates`。确认 updater 已连接、授权已配置，当前没有执行中或待恢复操作。
2. 点击“获取升级计划”。预检可能拉取镜像、启动临时检查容器，需要等待；它不会执行本次迁移或切流。
3. 核对目标版本、升级策略、布局变化和待执行迁移。
4. 显示“维护窗口升级”时，安排停机时间，勾选允许暂停服务的维护确认。
5. 点击“检查并安全更新”，按提示填写管理员密码和 `update` 条目的新授权码。
6. 查看阶段进度，等待最终状态为“已完成”。生成操作编号只表示任务开始。
7. 点击“运行环境验收”，并完成[第 9 节的业务检查](#9-自动迁移与升级验收)。

后台会把本次预检摘要带入更新请求。提示“升级计划已变化或尚未预检”时，重新获取计划、核对后再提交。

维护期间后台可能暂时无法访问，恢复后重新打开页面查看结果。后台任务由宿主机执行，关闭浏览器页面不会取消任务。

## 8. 日常升级：服务器命令

### 8.1 诊断与预检

```bash
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater update --instance primary --dry-run --json
```

宿主机预检最长可运行 25 分钟。重点阅读：

| 字段 | 含义 |
|---|---|
| `target_version` | 即将安装的版本 |
| `source_sequence` / `target_sequence` | 当前与目标发布序列 |
| `strategy` | 在线或维护升级 |
| `layout_change` | 是否转换部署布局 |
| `pending_migrations` | 尚未执行的数据库迁移 |
| `steps` | 发布计划中的回填、检查和缓存步骤 |
| `plan_sha256` | 确认执行时提交的预检摘要 |

执行命令使用 `plan_sha256`。`upgrade_plan_sha256` 是应用升级计划文件的摘要，用途不同。

### 8.2 按策略选择一条执行命令

把下面的占位符替换为本次返回的 64 位 `plan_sha256`：

```bash
GEOFLOW_PLAN_SHA256='替换为本次预检返回的 plan_sha256'
```

计划为 `online`：

```bash
sudo geoflow-updater update \
  --instance primary \
  --plan-sha256 "$GEOFLOW_PLAN_SHA256" \
  --json
```

计划为 `maintenance`，且已进入约定的维护窗口：

```bash
sudo geoflow-updater update \
  --instance primary \
  --plan-sha256 "$GEOFLOW_PLAN_SHA256" \
  --allow-maintenance \
  --json
```

两条命令按策略选一条。`--allow-maintenance` 表示允许维护停机，不能将维护计划改成在线计划。当前没有强制在线升级开关。

CLI 会等待操作结束。长时间操作建议在持久终端会话中运行，保持连接，避免因会话中断触发恢复流程。

### 8.3 源码仓库的统一脚本入口

`scripts/geoflow-deploy.sh` 调用已安装的 updater，共用同一套执行逻辑。日常受管操作不要求服务器保留源码副本。

在 GEOFlow 源码根目录中，维护升级的等价命令为：

```bash
sudo ./scripts/geoflow-deploy.sh status --instance primary --json
sudo ./scripts/geoflow-deploy.sh update --instance primary --dry-run --json
```

核对新预检结果，并更新 `GEOFLOW_PLAN_SHA256` 后执行：

```bash
sudo ./scripts/geoflow-deploy.sh update \
  --instance primary \
  --plan-sha256 "$GEOFLOW_PLAN_SHA256" \
  --allow-maintenance
```

在线计划去掉 `--allow-maintenance`。脚本的状态命令叫 `status`，updater 的对应命令叫 `doctor`。

## 9. 自动迁移与升级验收

updater 在候选应用中调用 `geoflow:upgrade`，按签名计划执行检查、应用和验证阶段。当前计划包括：

| 步骤 | 作用 |
|---|---|
| 数据库迁移 | 校验文件摘要，只执行尚未应用的迁移 |
| 托管图片处理 | 执行图片就绪处理并验证 |
| 系统知识同步 | 更新内置知识和媒体 |
| 检索数据回填 | 回填检索数据并验证 |
| 安全检查 | 检查升级后的安全基线 |
| 缓存预热 | 编译候选版本的配置、路由和视图缓存 |

系统保存阶段与步骤记录，重试结合记录和实际状态继续。日常升级直接使用 updater，无需另行执行 `git pull`、`composer install`、前端构建或手工 `migrate`。

候选检查通过后执行入口切换、后台任务交接和旧请求排空。默认健康观察 120 秒，整个升级还包括下载、迁移和排空时间；旧请求排空超时会保留旧槽位并报告待恢复状态。

完成后检查：

```bash
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater verify --instance primary --json
curl --fail --show-error https://geo.example.com/up
```

确认最终操作状态为 `succeeded`，实例版本符合目标，蓝绿实例的 `layout` 为 `blue-green`、`active_slot` 为 blue 或 green，健康检查无未处理的失败。

自动验收覆盖服务进程、入口版本、HTTP、数据库、Redis、存储和业务升级清单。继续通过真实域名检查：

- 首页、已有文章、图片和静态资源加载正常。
- 已登录会话可继续使用，新后台登录正常。
- 保存一条测试内容，确认可读写。
- 执行一项可控的小任务，确认结果完成且没有重复。
- 使用实时消息或流式输出页面，确认连接与重连正常。

## 10. 备份与恢复

### 10.1 创建完整备份

**完整备份会暂停服务和写入，需要维护窗口。** 后台选择“创建完整备份”，使用 `backup` 授权码；服务器命令如下：

```bash
sudo geoflow-updater backup --instance primary --json
sudo geoflow-updater recovery-points --instance primary
```

恢复点包含 PostgreSQL、完整业务存储、停写后的 Redis 数据、环境配置和受管部署文件。默认保留 5 个恢复点，其中会保护最近一次更新前检查点。

在线升级期间的数据库快照仅覆盖 PostgreSQL，无法替代包含业务文件和 Redis 的完整恢复点。另行安排完整备份时，也要计入维护时间。

### 10.2 应用回切：保留当前数据

适用于**已成功完成的兼容在线蓝绿升级**，且保留的上一应用版本仍与当前部署对应。维护升级、任意历史版本和待恢复状态不能直接套用此入口。

后台点击“应用回切”，使用 `update` 授权码；服务器执行：

```bash
sudo geoflow-updater switch-back --instance primary --json
```

源码脚本的等价命令：

```bash
sudo ./scripts/geoflow-deploy.sh rollback --application --instance primary
```

该操作切回保留应用，继续使用当前数据库和业务文件，升级后的新写入仍保留。

### 10.3 数据恢复：回到完整恢复点

适用于需要同时恢复数据、配置和应用版本的情况。**恢复点之后的新增或修改数据会被覆盖**，先确认恢复时间、业务影响和维护窗口。

```bash
sudo geoflow-updater recovery-points --instance primary
```

选择实际返回的恢复点 ID：

```bash
GEOFLOW_RECOVERY_POINT='替换为已核对的恢复点 ID'
sudo geoflow-updater rollback \
  --instance primary \
  --recovery-point "$GEOFLOW_RECOVERY_POINT" \
  --json
```

源码脚本的等价命令：

```bash
sudo ./scripts/geoflow-deploy.sh rollback --data \
  --instance primary \
  --recovery-point "$GEOFLOW_RECOVERY_POINT"
```

后台“恢复数据与版本”使用 `rollback` 授权码，只允许最近一次更新前检查点。主机 CLI 可选择其他经过校验的完整恢复点。恢复后重新完成[第 9 节检查](#9-自动迁移与升级验收)。

## 11. 常见问题与中断处理

| 现象 | 处理方式 |
|---|---|
| 不认识 `install` 或 `--dry-run` | 查看 updater 版本，确认正式包包含本轮能力 |
| `signed release has no upgrade plan` | 发布源仍是旧发布，需配套签名发布；不要篡改计划 |
| 接管版本不匹配 | 按受支持的旧版流程达到可接管版本后再登记 |
| 后台“未连接” | 检查服务、受管容器的 socket 与实例 token 挂载；不要挂载 Docker socket 给网站 |
| 后台缺少宿主机路径 | `GEOFLOW_UPDATER_HOST_ROOT` 用于生成接管命令，应填真实主机目录；填路径无法代替服务安装与连接 |
| 计划摘要变化 | 重新预检，核对后再提交 |
| 签名、有效期或镜像摘要失败 | 检查主机时间、网络及发布源，保留校验机制 |
| 已有操作运行 | 等待当前操作完成，避免重复提交 |
| 维护计划未获允许 | 安排维护窗口，再使用维护勾选项或参数 |
| `rolled_back` / “已自动回滚” | 本次升级失败，已执行恢复；确认旧版本健康，再排查原因 |
| `recovery_required` / “需要恢复” | 自动处理未完成，保留状态并检查失败阶段、日志与恢复点 |
| 应用回切被拒绝 | 核对[第 10.2 节条件](#102-应用回切保留当前数据)，必要时评估完整数据恢复 |

常用诊断命令：

```bash
sudo geoflow-updater doctor --instance primary --json
sudo systemctl status geoflow-updater --no-pager
sudo journalctl -u geoflow-updater -n 200 --no-pager
sudo geoflow-updater recovery-points --instance primary
```

updater 启动及后台检查会根据持久化记录处理被中断的操作。不要删除部署日志、锁文件、槽位或数据卷。服务已停止时，先排查原因，再启动服务观察恢复结果；正在工作的服务不要反复重启。

维护升级在重新开放流量前具备完整检查点恢复流程。在线失败优先恢复应用并保留新写入；流量开放后不会自动将整库恢复到旧时间点。需要恢复业务数据时，单独执行[数据恢复](#103-数据恢复回到完整恢复点)。

共享诊断信息前去掉密码、token、授权 URI 和业务隐私，保留操作编号、版本、失败阶段与脱敏错误。

## 12. 维护者参考

普通站点管理员按上述流程选择和确认计划。在线兼容性由发布者声明，并通过对应候选版本的验证。

**2026 年 9 月 9 日进展：** Updater `0.4.0-rc.4` 配套签名候选已完成原生 amd64、arm64 的安装、完整升级恢复、中断恢复和在线机制验收，全部通过。候选身份、逐项结果及修复 PR 见[验收记录](reports/2026-09-09-blue-green-host-acceptance.md)。技术验收与正式发布分别记录，安装时仍需满足[版本前提](#geoflow-蓝绿部署与自动迁移使用教程)。

- `deployment/upgrade-plan.json` 固定迁移文件摘要。新增迁移后更新并审阅清单，再运行 `python3 deployment/generate-upgrade-plan.py --check`。
- schema 3 发布清单将完整计划纳入 TUF 签名目标 `releases/<version>/upgrade-plan.json`。维护计划要求协议至少为 3，在线计划至少为 4；应用计划与预检摘要各自的 schema 版本需分别理解。
- [候选验收工作流](https://github.com/yaojingang/geoflow-updater/actions/workflows/planned-acceptance.yml) 在原生 amd64、arm64 主机运行应用与入口检查，并分别执行完整升级恢复、首次安装重试和在线切换演练。完整恢复会核对数据库、Redis、文件、配置及迁移记录；中断演练包含调度进程被冻结后恢复、恢复失败后的重试状态。
- 在线演练使用单独签名的同代码测试候选，检查登录会话、任务交接、实时消息跨槽传递、重连和保留数据的应用回切。正式旧版本与新版本之间的在线兼容性，仍需针对实际版本对验证。常规发布检查目前接受维护模式计划。
- 发布者需审阅同一个候选在两种架构上的完整结果，并完成发布、技术安全和产品审批。自动检查逐项验证必要用例；运行时修复后要重新构建候选并复测。历史 `phase-c-rehearsal.yml` 仅接收 schema 2 候选。具体操作见 [主机验收说明](https://github.com/yaojingang/geoflow-updater/blob/main/docs/planned-host-acceptance.md)。
- 应用和升级步骤使用 UID 33。运行进程关闭重复权限扫描和自动缓存优化，槽位视图缓存由切流前步骤预热。原 APP_KEY、业务存储和会话身份延续使用，受保护的 `.env.prod` 只读挂载。
- 部署状态位于 `/var/lib/geoflow-updater`，完整恢复点位于 `/var/backups/geoflow-updater`。这些目录由 updater 管理，站点 `.env.prod` 和 `storage/` 需一并纳入运维管理。

相关文档：[部署设计](superpowers/plans/2026-09-08-blue-green-deployment-design.md)、[实现验证记录](reports/2026-09-08-blue-green-implementation-validation.md)、[旧版 3.0 升级指南](deployment/GEOFLOW_V3_UPGRADE.md)、[updater 蓝绿部署说明](https://github.com/yaojingang/geoflow-updater/blob/main/docs/blue-green-deployment.md)、[updater 发布操作手册](https://github.com/yaojingang/geoflow-updater/blob/main/docs/release-runbook.md)。

### 12.1 站点管理员需要做什么

双架构候选验收由发布维护者完成。站点管理员按发布说明安装配套 Updater，首次接管和布局转换安排维护窗口，并检查自己站点的备份及恢复能力。

上线前，在隔离测试环境用本站数据副本试一次升级和完整恢复，核对登录、关键业务、队列任务、文件及实时消息。按数据规模记录备份、迁移和恢复耗时，用于安排维护窗口。后续每次升级先预检计划，再按 `online` 或 `maintenance` 选择执行方式。
