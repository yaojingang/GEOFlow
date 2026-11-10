# GEOFlow 3.2.1 升级说明

简体中文 | [English](GEOFLOW_V3_2_1_UPGRADE_en.md)

本次配套版本为 Core `3.2.1`、Updater `0.5.0`，签名更新序列为 `4`。从 3.1.0 升级包含 28 项新增迁移，使用维护窗口。安装前确认 [Core 发布包](https://github.com/yaojingang/GEOFlow/releases/tag/v3.2.1) 和 [Updater 发布包](https://github.com/yaojingang/geoflow-updater/releases/tag/v0.5.0) 均可用。

## 选择路径

| 当前部署 | 操作 |
|---|---|
| 首次安装 | 校验源码 ZIP，按[生产部署](DEPLOYMENT.md)初始化空库；受管安装使用 0.5.0 配套包 |
| 已受管的 3.1.0 | 先安装兼容的 Updater 0.5.0，再预检并执行签名维护计划 |
| 标准 Docker 3.1.0 | 用本站备份演练下文的人工维护升级 |
| 更早版本或自定义部署 | 先在独立环境验证迁移和恢复路径，保留实际数据库及存储配置 |

## 备份与排空

1. 固定现有源码、镜像摘要、Compose 项目名及覆盖文件。记录 PostgreSQL 和 Redis 主版本、挂载路径、站点地址及后台入口。
2. 开启外层维护限制，暂停新增任务，等待在途请求及队列结束，停止所有写入进程，包括队列、AI 质检与优化进程、知识队列和调度器。
3. 保存 PostgreSQL 完整备份、Redis 完整 `/data`、整个 `storage/`、自定义模板、原镜像和配置。保留 `.env.prod` 中的 `APP_KEY`，以便解密历史数据。受管站点同时保留实例配置、版本、恢复点和操作收据。
4. 先在独立数据库和存储恢复一份备份，核对管理员登录、文章、上传、模板及加密数据，再进入升级窗口。

3.1.0 的旧 Redis 可能还未绑定宿主数据目录。首次启用持久化前，按[生产部署的 Redis 迁移步骤](DEPLOYMENT.md#32-首次启用-redis-持久化目录)复制旧容器完整 `/data`，包括多段 AOF 和 manifest。先校验复制和备份，再重建 Redis，确认队列、键数和持久化状态。

## 已受管实例

校验并安装 0.5.0，保留原实例身份和数据路径。首次维护升级从宿主机执行：

```bash
sudo geoflow-updater version
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater update --instance primary --dry-run --json
```

核对实际实例名、目标 `3.2.1`、序列 `4`、维护策略与计划摘要。排空后使用该次预检给出的 `plan_sha256`：

```bash
sudo geoflow-updater update --instance primary \
  --plan-sha256 PLAN_SHA256 --allow-maintenance
sudo geoflow-updater verify --instance primary
```

完整恢复后检查 `http_ready` 与后台 `held`。重新登录可读取恢复后的数据，核对恢复代次与原操作收据，并逐业务对账。本版本没有受支持的解除操作，业务写入和后台处理继续暂停。请保留恢复状态并联系维护者，避免手动修改状态或启动 Worker。

## 标准 Docker 人工维护升级

1. 从 Release 下载并校验 `GEOFlow-v3.2.1.zip` 与 `.sha256`。在独立目录构建新应用和 Web 镜像，保留旧镜像供恢复。生产 Dockerfile 使用 npm 官方源安装锁定依赖。
2. 将原配置迁入新部署目录，显式核对旧 Compose 项目名、覆盖文件、数据卷的绝对路径和端口。继续使用原 PostgreSQL、Redis 主版本与实际业务存储。新目录的相对默认路径可能指向空数据库。
3. 在新部署目录定义 `dc`，将项目名替换为实际旧实例名；将原有 Compose 覆盖文件按顺序加入命令。新镜像构建完成后，保持旧应用与所有写入进程停止。

```bash
GEOFLOW_COMPOSE_PROJECT=existing-instance-project
dc() { docker compose --env-file .env.prod -f docker-compose.prod.yml -p "$GEOFLOW_COMPOSE_PROJECT" "$@"; }
dc config --quiet
dc build app web
```

使用一次性容器预检。官方 3.1.0 来源序列为 `3`；核对预检报告中的 28 项迁移与 `plan_sha256`。自定义部署先确认来源序列。

```bash
dc run --rm --no-deps \
  -e AUTO_MIGRATE=false -e AUTO_INSTALL_ONCE=false -e AUTO_OPTIMIZE=false \
  app php artisan geoflow:upgrade --phase=inspect --source-sequence=3 --json
```

确已排空并完成备份后，设置本次唯一操作编号和预检返回的计划摘要，执行维护计划：

```bash
GEOFLOW_OPERATION=upgrade-321-maintenance-001
GEOFLOW_PLAN_SHA256=PLAN_SHA256_FROM_INSPECT
dc run --rm --no-deps \
  -e AUTO_MIGRATE=false -e AUTO_INSTALL_ONCE=false -e AUTO_OPTIMIZE=false \
  -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=false \
  -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true \
  app php artisan geoflow:upgrade --phase=apply --strategy=maintenance \
  --source-sequence=3 --operation="$GEOFLOW_OPERATION" \
  --plan-sha256="$GEOFLOW_PLAN_SHA256" --json
dc run --rm --no-deps \
  -e AUTO_MIGRATE=false -e AUTO_INSTALL_ONCE=false -e AUTO_OPTIMIZE=false \
  app php artisan geoflow:upgrade --phase=verify --strategy=maintenance \
  --source-sequence=3 --operation="$GEOFLOW_OPERATION" \
  --plan-sha256="$GEOFLOW_PLAN_SHA256" --json
```

## 验收与恢复

- 保持外层维护限制，按原部署依赖启动应用、Web 和 Reverb。后台进程继续停止；如先前执行了 `artisan down`，在内部验收前通过 `dc exec -T app php artisan up` 解除 Laravel 维护标记。
- 核对 `/up`、首页、管理员登录、文章、站点配置、模板、上传、知识库、Redis 和实时消息。旧质检报告需重检，历史暂缓文章维持原审核状态。核对后恢复 Worker、调度器和外部入口。
- 新环境示例指向 GitHub 正式 Release。已有部署如曾使用 `main/version.json`，将 `GEOFLOW_UPDATE_METADATA_URL` 改为 `https://github.com/yaojingang/GEOFlow/releases/latest/download/version.json`，并按部署流程刷新配置缓存。
- 遇到失败时保留维护状态及日志，用旧代码、旧镜像、原 APP_KEY 和同一时间点的数据库、Redis、存储与配置恢复到独立目标，验收后切回。新版本已接收写入时，先备份新增数据，再制定对账与恢复方案。
