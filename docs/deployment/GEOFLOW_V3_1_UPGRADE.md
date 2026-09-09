# GEOFlow 3.1 升级说明

简体中文 | [English](GEOFLOW_V3_1_UPGRADE_en.md)

目标版本为 GEOFlow `v3.1.0` 与 GEOFlow Updater `v0.4.0`。执行前确认两个正式 Release、配套镜像和签名更新源均已公开；源码分支中的版本号本身不代表发布完成。

## 选择升级路径

| 当前状态 | 操作路径 |
|---|---|
| 新服务器 | [安装 Updater 0.4.0](../blue-green-deployment-usage.md#3-安装或升级-updater)，再按教程首次安装站点 |
| 已受管的 3.0.0 站点 | 先升级宿主机 Updater，再按下节通过 CLI 确认维护升级 |
| 未受管的标准 Docker 3.0.0 站点 | 先按本文维护升级 Core 到 3.1.0，再接管并转换蓝绿布局 |
| 自定义部署或更早版本 | 先核对配置、数据库和历史升级要求，在隔离环境验证适配方案 |

本次签名计划采用 `maintenance`。首次布局转换及本次 3.0.0 到 3.1.0 升级需要维护窗口。在线演练验证了切换机制；实际旧新版本的兼容性由对应签名计划另行确认。

## 已受管 3.0.0：首次升级使用宿主机 CLI

先暂停新增业务任务，用本站数据副本验证备份及完整恢复，记录预计耗时。按[安装教程](../blue-green-deployment-usage.md#3-安装或升级-updater)校验并安装 Updater `0.4.0`，确认没有其他安装、更新、备份或恢复操作正在执行。

旧版后台发送的更新请求缺少新计划的维护确认，首次升级在 Linux 宿主机执行：

```bash
sudo geoflow-updater version
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater update --instance primary --dry-run --json
```

确认预检目标为 `3.1.0`、策略为 `maintenance`，保存返回的 `plan_sha256`。把下列占位符替换为该摘要后执行；若更新源已变为其他版本，先阅读对应发布说明。

```bash
sudo geoflow-updater update --instance primary \
  --plan-sha256 PLAN_SHA256 --allow-maintenance
sudo geoflow-updater verify --instance primary
sudo geoflow-updater doctor --instance primary --json
```

完成后按[升级验收](../blue-green-deployment-usage.md#9-自动迁移与升级验收)核对业务。后续升级可以使用 3.1 后台的计划预检和确认入口。

## 未受管的标准 Docker 3.0.0：先升级 Core

`enroll` 要求本地版本与当前签名发布一致。签名源已经指向 3.1.0 时，未受管的 3.0.0 站点需要先完成维护升级。下列步骤仅适用于官方、无源码修改的 Git 检出和标准 `docker-compose.prod.yml`，保留原项目名、数据目录及 PostgreSQL、Redis 主版本。

1. 按[旧版教程路径 B 的第 1 至 3 步](GEOFLOW_V3_UPGRADE.md#路径-b普通生产-compose-从-2x-升级)确认现场、停止新增任务、排空全部请求和任务，并创建数据库、配置、存储和旧镜像备份。按实际挂载停止原 Redis 容器并冷备其数据目录，同时保存外部存储副本；冷备完成后仅启动原 Redis。实际恢复验证通过后继续，旧应用全部停止，原数据库和 Redis 保持运行。
2. 在原站点目录的干净 Git 工作区固定新标签，核对提交与正式 Release 一致，保留 `.env.prod`、`APP_KEY`、业务存储和数据库挂载。

```bash
git fetch origin tag v3.1.0
git rev-parse 'v3.1.0^{commit}'
git switch --detach v3.1.0
dc() { docker compose --env-file .env.prod -f docker-compose.prod.yml "$@"; }
dc config --quiet
dc build app web
```

3. 用新镜像执行一次性迁移与回填。每个命令成功后再继续；临时排空标志只在确已停止旧应用时传入，保持外层维护限制。

```bash
dc run --rm --no-deps \
  -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=false \
  -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true \
  -e AUTO_MIGRATE=false -e AUTO_INSTALL_ONCE=false -e AUTO_OPTIMIZE=false \
  init php artisan migrate --force
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:sync-system-knowledge --media
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:backfill-ai-quality-retrieval
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:backfill-ai-quality-retrieval --verify --json
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:managed-images:readiness --json
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:security-audit --json
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:upgrade --phase=verify --json
```

4. 保持维护限制，按[启动与业务验收](GEOFLOW_V3_UPGRADE.md#启动新服务与业务验收)启动新版本，旧教程中的预期版本按 `3.1.0` 核对，并检查缓存、登录、关键业务、队列、文件与实时消息，再按[接管教程](../blue-green-deployment-usage.md#5-已有站点接管并转换布局)交给 Updater。接管前再次确认所有队列为空及旧服务排空。
5. 接管后按计划预检和维护确认执行蓝绿布局转换。同一个完整签名发布仅在旧单套布局下允许此转换；已转换的实例不会重复执行同序列升级。

从 2.x 或未完成 3.0 数据治理的部署开始时，先完成[管理员模型与历史身份回填](GEOFLOW_V3_UPGRADE.md#历史-ai-模型与执行身份)。源码 ZIP 是源码包，依赖与前端构建由上述 Docker 构建完成。

## 验收、主题和恢复

- 检查普通管理员与超级管理员登录、文章生成、任务草稿、队列、检索、文件和实时消息，并在隔离环境试一次完整恢复。
- 已安装的主题包保留；导出包默认绑定导出时的 Core 版本。导入标记仅兼容 3.0.0 的包时，应由主题作者验证 3.1.0 并更新兼容声明后重新打包。
- 受管升级通过完整恢复点恢复数据；在线应用回切保留实时数据，适用范围见[恢复教程](../blue-green-deployment-usage.md#10-备份与恢复)。
- 手动维护升级期间发生失败时，保留维护状态，使用升级前记录的源码、镜像、配置及完整数据库和存储备份恢复，再验收业务。
