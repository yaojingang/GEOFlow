# GEOFlow 3.0 升级教程

适用版本：GEOFlow `v3.0.0`，发布于 2026-09-05。本文面向已有数据的站点管理员，覆盖升级准备、两种升级路径、数据回填、验收和故障恢复。首次安装请看 [生产部署文档](DEPLOYMENT.md)。

## 版本与下载入口

| 组件 | 本次版本 | 官方入口 |
| --- | --- | --- |
| GEOFlow Core | `v3.0.0` | [正式 Release](https://github.com/yaojingang/GEOFlow/releases/tag/v3.0.0) |
| 独立更新工具 | `v0.3.0`，Linux amd64 / arm64 | [Updater Release](https://github.com/yaojingang/geoflow-updater/releases/tag/v0.3.0) |
| 内置 CLI | `0.2.0` | [CLI 使用说明](../GEOFLOW_CLI.md) |
| Chrome 运营助手 | `0.1.0` | [浏览器运营手册](../browser-operations-runbook.md) |

Core 正式标签对应提交 `f5301eb14a91f8e5994b479c545dbe2773d601d8`。Release 提供 `GEOFlow-v3.0.0.zip`、`GEOFlow-v3.0.0.zip.sha256` 和 `version.json`。压缩包包含源码，生产依赖与前端资源需要通过 Docker 构建生成。

稳定版提示读取 GitHub Latest Release 的 `version.json`。`main` 会继续变化；准备升级到本教程版本时，固定使用 `v3.0.0`。如果已经安装了更晚的开发提交，先评估源码与数据库差异，避免把切换正式标签当作无风险降级。

## 3.0 升级后有哪些变化

- 后台统一为 Admin UI V3，增加图文帮助、PWA 和本地静态资源。
- AI 质检加入三层知识召回、原子事实和自动优化；历史数据需要回填，质检异常会明确显示失败状态。
- AI 模型按管理员区分所有者和共享范围；历史模型、任务身份需要检查，普通管理员使用个人模型或明确共享的模型。
- 增加托管渠道站点、Chrome 运营助手和人工发布流程。托管站点默认关闭，启用前需要完成域名、证书与代理配置。
- 网站更新、完整备份和恢复点回滚由独立 Updater 执行。旧更新记录保留为只读历史。
- 当前版本使用 AGPL-3.0-only，免费使用场景和相关义务见 [仓库许可说明](../../README.md#许可证)。

完整功能变化见 [3.0 更新日志](../CHANGELOG.md)。升级应用、开启托管站点和调整 AI 自动放行策略可以分别安排，先验证现有业务。

## 选择适合当前实例的路径

| 当前部署 | 升级路径 |
| --- | --- |
| 已由 Updater 管理，`primary` 实例可诊断 | 路径 A：安装兼容工具，执行签名更新，再完成业务数据验收 |
| 官方源码、`docker-compose.prod.yml`、本机 PostgreSQL / Redis，尚未接管 | 路径 B：停机升级 Core，完成回填，再接入 Updater |
| ZIP 安装、预构建编排、修改过源码、外部数据库、面板 PHP-FPM | 使用相同备份与停机边界，由运维适配代码切换和进程命令；不要直接套用路径 B 的 Git 命令 |
| Mac / Windows Docker Desktop | 可以做隔离的应用测试；Updater v0.3.0 的宿主要求是 Linux + systemd，需另用 Linux 虚拟机或测试服务器验证接管与恢复 |

首次 `enroll` 要求本地 `version.json` 与当时签名稳定目标的版本匹配。首次接管普通 2.x 实例时，先完成 Core 升级。已有受管实例保留原有状态，不重复 `enroll`。

## 升级前准备

1. 在独立测试环境恢复一份备份，检查登录、文章、图片和知识库。测试环境使用独立数据库、Redis、存储目录、Compose 项目名、网络与端口，关闭定时发布和外部分发，避免测试任务发送到真实渠道。
2. 记录运行版本、提交、容器镜像、Compose 项目名、数据库主版本、数据目录、Web 端口和后台前缀。保留旧镜像或导出镜像，回滚期间不要清理 Docker 数据。
3. 备份 PostgreSQL、`.env.prod`、可能使用的 `.env`、完整 `storage/`、独立上传目录、自定义主题和代理配置。保留原 `APP_KEY`，已有实例不要重新生成密钥或用示例环境文件覆盖配置。
4. 确认备份磁盘能容纳数据库、文件与镜像，备份目录位于站点目录之外且仅授权人员可读；至少保留一份异机副本。数据库目录的在线文件复制不能替代一致的数据库备份。
5. 约定维护窗口，暂停新增任务、定时发布、外部 API 写入和其他自动化。旧队列的待处理、延迟、保留及执行中任务都需要清点，旧 `system-updates` 和知识切片任务尤其需要排空。
6. 保留服务器 SSH / 控制台访问。准备超级管理员账号及 Updater 操作授权；口令、授权 URI、API Key 和备份文件均不要发到公开 Issue。

### 保留数据库主版本与原有挂载

v3.0.0 的普通生产 Compose 默认使用 PostgreSQL 18 和 Redis 8。旧实例可能运行 PostgreSQL 16 / Redis 7，需要在现有 `.env.prod` 中明确保留原值。以下仅适用于实际使用 PostgreSQL 16、对应挂载及 Redis 7 的实例：

```dotenv
PGVECTOR_IMAGE=pgvector/pgvector:pg16
POSTGRES_DATA_DIR=./docker-data/prod/postgres
POSTGRES_CONTAINER_DATA_DIR=/var/lib/postgresql/data
REDIS_IMAGE=redis:7-alpine
```

实际路径、镜像和挂载以旧容器检查结果为准。PostgreSQL 18 通常挂载到 `/var/lib/postgresql`；不要凭版本号推测宿主目录。GEOFlow 应用升级保留数据库主版本，跨主版本数据库升级需要单独的迁移计划。[PostgreSQL 官方升级说明](https://www.postgresql.org/docs/current/upgrading.html)

## 路径 A：已有 Updater 管理的实例

1. 按下文「安装 Updater v0.3.0」验证并安装兼容工具。已有实例跳过首次接管步骤。
2. 在服务器检查工具和实例：

```bash
sudo geoflow-updater version
sudo geoflow-updater doctor --instance primary --json
```

3. 处理诊断失败后，在外层反向代理保留维护限制、暂停新任务，并确认旧队列已排空。在后台更新中心确认目标是 `3.0.0`，输入管理员密码和验证器中「更新」项的 6 位授权码后提交；也可由宿主机管理员执行：

```bash
sudo geoflow-updater update --instance primary
```

宿主 CLI 使用 root 权限。网站的更新、备份、回滚分别使用对应授权码，不要混用。Phase B 过渡实例只允许旧更新 Worker 的具名诊断失败进入签名更新；其他失败都需要先修复，详见 [Phase C 交接说明](SYSTEM_UPDATER_PHASE_C.md)。

CLI 的 `update` 会解析当时的签名稳定目标，并要求更新序列递增；它没有固定 `3.0.0` 的版本参数。目标若已变为后续版本，先阅读该版本说明；当前已经是 `3.0.0` 时直接验收，无需重复更新。

Updater 会验证签名目标与镜像摘要，依次执行预检、拉取、静默、备份、迁移、激活和验证。迁移或激活等受保护阶段失败时会尝试恢复。命令成功后仍需完成下文的数据回填和业务验收；自动恢复应用进程不等于业务已验收，外层维护限制保留到验收结束。

4. 使用 `enroll` 时记录的真实实例根目录定义受管 Compose 函数。下面以 `/opt/geoflow` 为例：

```bash
export GEOFLOW_INSTANCE_ROOT=/opt/geoflow
gfm() {
  sudo docker compose \
    --env-file "$GEOFLOW_INSTANCE_ROOT/.env.prod" \
    --env-file /var/lib/geoflow-updater/instances/primary/release.env \
    -f /var/lib/geoflow-updater/instances/primary/docker-compose.managed.yml "$@"
}
```

运行 `gfm exec -T app php artisan down`，停止业务 Worker、scheduler、Reverb 与入口，确认零在途后再停止 app。按「数据回填」执行一次性命令时，用 `gfm` 替换其中的 `dc`；完成后由同一受管编排恢复服务。不要用旧 `docker-compose.prod.yml` 启动另一套容器。

## 路径 B：普通生产 Compose 从 2.x 升级

以下命令在运行 GEOFlow 的 Linux 服务器上执行，使用专用 Bash 会话。仅适用于未修改源码的官方 Git 检出、标准生产编排和原地保留数据目录的实例。每一步成功后再继续；出现失败时保留维护状态，查看「故障处理与回退」。`set -euo pipefail` 让命令失败或变量缺失时停止会话，重新连接后先核对现场状态再继续。

### 1. 确认目录和当前状态

切换到真实的 GEOFlow 根目录，里面应有 `.env.prod`、`storage/` 和当前编排。不要在新下载的空目录执行旧站点升级。

```bash
set -euo pipefail
test -f .env.prod && test -d storage && test -f docker-compose.prod.yml
git remote get-url origin
git status --short --branch -uall
git rev-parse HEAD
dc() { docker compose --env-file .env.prod -f docker-compose.prod.yml "$@"; }
dc config --quiet
dc ps
dc config --services
docker inspect "$(dc ps -q postgres)" --format '{{.Config.Image}} {{json .Mounts}}'
docker inspect "$(dc ps -q redis)" --format '{{.Config.Image}} {{json .Mounts}}'
```

确认远端属于官方仓库，工作区没有待保留的源码改动或未跟踪业务文件。自定义 Compose 项目名、覆盖文件、数据库目录或外部卷需要一并记录和适配；保持项目、网络与数据路径不变。完整 `docker compose config` 可能包含密码，仅保存到私有备份目录。

### 2. 停止新工作并排空旧进程

先暂停后台任务和外部写入，阻断普通访问，停止调度器，让仍在执行的任务结束：

```bash
dc stop -t 1200 scheduler
```

通过后台任务记录、队列监控与进程状态确认待处理、延迟、保留、执行中任务为零，再继续。维护模式不会自动清空队列。旧知识索引任务缺少新版本的模型快照时，应在升级后重新发起切片，避免直接重放旧载荷。

```bash
dc exec -T app php artisan down
dc stop -t 1200 web
# 确认反向代理连接和 PHP 请求已经结束，再停止当前编排中的其他旧服务。
for service in $(dc config --services); do
  case "$service" in postgres|redis|app|web) continue ;; esac
  dc stop -t 1200 "$service" || exit 1
done
dc stop -t 1200 app
dc ps --all
```

核对每次停止结果。被强制终止、仍有在途请求、编排外还有旧 Worker 时暂停升级，由运维处理后重新确认。此时保留 PostgreSQL 和 Redis 运行，供一致性备份与迁移使用。

### 3. 创建并检查升级前备份

先准备一个位于站点外、当前管理员可写的备份父目录，将其绝对路径填入 `GEOFLOW_BACKUP_BASE`。在同一个 Bash 会话中执行：

```bash
umask 077
: "${GEOFLOW_BACKUP_BASE:?请设置站点外的备份父目录绝对路径}"
test -d "$GEOFLOW_BACKUP_BASE" && test -w "$GEOFLOW_BACKUP_BASE"
GEOFLOW_BACKUP_DIR=$(mktemp -d "$GEOFLOW_BACKUP_BASE/geoflow-pre-v3.XXXXXX")
git rev-parse HEAD > "$GEOFLOW_BACKUP_DIR/core-sha.txt"
dc config > "$GEOFLOW_BACKUP_DIR/compose-resolved.yml"
dc images > "$GEOFLOW_BACKUP_DIR/images.txt"
old_image_ids=($(dc images -q | sort -u))
test "${#old_image_ids[@]}" -gt 0
docker image save -o "$GEOFLOW_BACKUP_DIR/images.tar" "${old_image_ids[@]}"
cp -p .env.prod "$GEOFLOW_BACKUP_DIR/env.prod"
dc exec -T postgres sh -ec 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' \
  > "$GEOFLOW_BACKUP_DIR/database.dump"
test -s "$GEOFLOW_BACKUP_DIR/database.dump"
dc exec -T postgres pg_restore --list < "$GEOFLOW_BACKUP_DIR/database.dump" \
  > "$GEOFLOW_BACKUP_DIR/database-contents.txt"
sudo tar --acls --xattrs -czpf "$GEOFLOW_BACKUP_DIR/storage.tar.gz" storage
sudo tar -tzf "$GEOFLOW_BACKUP_DIR/storage.tar.gz" > /dev/null
printf '备份目录：%s\n' "$GEOFLOW_BACKUP_DIR"
```

`-Fc` 生成供 `pg_restore` 使用的自定义格式备份。[PostgreSQL pg_dump 说明](https://www.postgresql.org/docs/current/app-pgdump.html) 目录检查只能确认备份可解析，正式升级前还需在隔离数据库中实际恢复并抽查文章数量和图片。单独使用的 `.env`、外部上传目录、对象存储、自定义主题、数据库角色与代理配置也需按实际部署备份；上面的命令只覆盖列出的文件与单个数据库。

### 4. 固定正式版并检查配置

仅在工作区干净、备份可恢复、全部旧应用进程停止后执行：

```bash
git fetch origin tag v3.0.0
git rev-parse 'v3.0.0^{commit}'
# 上一行必须是 f5301eb14a91f8e5994b479c545dbe2773d601d8。
git switch --detach v3.0.0
git rev-parse HEAD
```

保留 `.env.prod` 和 `APP_KEY`，对照新 `.env.prod.example` 逐项补齐配置。显式固定原数据库和 Redis 主版本、原数据挂载、原 Web 端口与网络；`APP_DEBUG=false`，HTTPS 站点配置安全 Cookie，可信代理填写实际 IP/CIDR。既有数据不要设置 `GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true`。

设置 `GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=false`。管理员模型迁移的首轮开关与参数按 [管理员 AI 模型隔离手册](../admin-ai-config-sharing-runbook.md) 准备，记录旧管理员、模型、任务和运行记录的最大 ID，以及带时区的截止时间。不要猜测模型所有者。

```bash
dc config --quiet
dc build app web
```

构建会安装锁定的生产依赖并运行前端构建。不要在已有数据实例上执行一键首次安装脚本、`migrate:fresh`、`db:seed`、`down -v` 或数据目录清理。此阶段保留原 PostgreSQL / Redis 容器，不借应用升级替换数据库主版本。

### 5. 一次性迁移

确认零在途后，仅给这一个迁移容器传入排空确认。`--no-deps` 防止一次性命令提前启动其他服务；`--rm` 只移除结束后的临时容器。[Docker Compose run 说明](https://docs.docker.com/reference/cli/docker/compose/run/)

```bash
dc run --rm --no-deps \
  -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=false \
  -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true \
  -e AUTO_MIGRATE=false -e AUTO_INSTALL_ONCE=false -e AUTO_OPTIMIZE=false \
  init php artisan migrate --force
```

命令必须退出成功。安全门禁拒绝时重新核查旧进程和备份，禁止用 fresh-install 标志绕过。一次性环境变量会随容器结束，不需要把排空确认长期留在 `.env.prod`。

## 数据回填：恢复流量前完成

此节要求数据库可用、旧应用停止、维护状态仍保留。普通生产编排使用 `dc`，受管编排使用路径 A 的 `gfm` 替换命令前缀。

### 历史 AI 模型与执行身份

运行 `geoflow:backfill-admin-ai-access --help` 查看参数，按 [模型隔离手册](../admin-ai-config-sharing-runbook.md) 使用已记录的所有者、截止时间与四项 ID 上限先做 `--dry-run`。处理所有系统模型冲突、无效绑定和阻塞身份后，以同一组参数执行 `--apply --maintenance-confirmed`，然后再次预检。

调用形式为 `dc run --rm --no-deps app php artisan geoflow:backfill-admin-ai-access ...`。省略号代表手册要求的真实参数，不能直接复制执行。回填会影响模型归属和共享，多个超级管理员时需明确负责人；无法恢复身份的历史活动记录会冻结，需人工确认后重新创建。恢复服务后继续检查 Shadow 报告和管理员模型可见范围。

### 三层召回与图片身份

```bash
dc run --rm --no-deps app php artisan geoflow:backfill-ai-quality-retrieval --dry-run
dc run --rm --no-deps app php artisan geoflow:backfill-ai-quality-retrieval
dc run --rm --no-deps app php artisan geoflow:backfill-ai-quality-retrieval --dry-run
dc run --rm --no-deps app php artisan geoflow:managed-images:readiness
dc run --rm --no-deps app php artisan geoflow:security-audit --json
```

最终召回预检的 `tasks`、`tasks_deferred`、`checks`、`checks_staled`、`sources`、`knowledge_bases`、`readiness_projections`、`atomic_fact_counts` 都应为 `0`。`tasks_deferred` 表示历史任务缺少可用切片，需要在受控维护环境修复切片后重跑；回填标为 `stale` 的历史质检需要重新质检。

图片 readiness 会修改数据，`remaining`、`terminal`、`registry_failed` 应为 `0`。安全审计为只读；退出码 `1` 表示存在问题、待复核例外或未完成审计，需处理后复查，不能直接忽略。

### 同步系统图文帮助

```bash
dc run --rm --no-deps app php artisan geoflow:sync-system-knowledge --key=ai_workspace_manual --media
```

此命令同步官方帮助与随包截图，保留管理员已修改的正文，也可能排入知识索引任务。若随后准备接管 Updater，应让新版本知识 Worker 完成这些任务，确认队列为空后再交接。

## 启动新服务与业务验收

普通生产 Compose 在完成迁移和回填后执行以下命令。已受管实例用 `gfm` 替换 `dc`，保持原受管配置。`--no-deps` 避免重跑初始化服务或重建数据库服务；此时 PostgreSQL 与 Redis 应已运行。

```bash
dc up -d --no-deps app queue ai-quality-queue ai-quality-backfill-queue \
  ai-optimization-queue knowledge-queue scheduler reverb web
dc exec -T app php artisan migrate:status
dc exec -T app php artisan geoflow:work-ai-quality front --validate
dc exec -T app php artisan geoflow:work-ai-quality backfill --validate
dc exec -T app php artisan geoflow:work-ai-optimization --validate
dc exec -T app php artisan geoflow:admin-ai-shadow-report --hours=24
dc ps --all
```

保持外层维护限制，执行 `dc exec -T app php artisan up` 后只允许测试人员访问，检查队列探针：

```bash
dc exec -T app php artisan geoflow:ai-quality-health --probe --wait=10 --json
```

该探针会投递测试任务，不能按纯只读检查理解。若刚启动时 Worker 心跳尚未建立，核查日志与消费者状态后重试，持续失败时保持维护窗口。业务验收至少包含：

| 检查 | 通过标准 |
| --- | --- |
| 健康与版本 | `/up` 正常；运行中版本为 `3.0.0`；无待执行迁移 |
| 账号与权限 | 原账号能登录；普通管理员无法看到他人的私有模型；共享模型范围正确 |
| 历史数据 | 抽查文章数量、正文、图片、分类、主题和知识库；原 `APP_KEY` 保持不变 |
| AI 内容流程 | 使用已授权模型生成一篇测试草稿，执行质检，结果有分数和证据，失败不会当作通过 |
| 异步任务 | 普通队列、两类质检队列、优化队列、知识队列和 scheduler 正常，无持续积压 |
| 前台与帮助 | 首页、文章、静态资源和图文帮助正常，后台前缀沿用原配置 |
| 分发与扩展 | 使用者同步 Chrome 扩展；先在测试渠道确认草稿与人工确认流程，避免误发生产内容 |

全部通过后再恢复普通流量和定时任务。图片物理删除开关需在 readiness 与安全审计通过后按 [部署门禁](DEPLOYMENT.md) 单独启用。托管站点、AI 自动优化和自动放行策略继续按各自手册逐步启用。

## 安装 Updater v0.3.0

在 Linux amd64 / arm64 宿主机执行，需要 systemd、Docker Compose v2、`gh`、`curl`、`tar` 和 `sha256sum`。先下载到临时目录，完成验证后再以管理员权限安装：

```bash
set -euo pipefail
case "$(uname -m)" in
  x86_64) updater_arch=amd64 ;;
  aarch64|arm64) updater_arch=arm64 ;;
  *) printf '不支持的架构\n' >&2; exit 1 ;;
esac
updater_dir=$(mktemp -d /tmp/geoflow-updater-0.3.0.XXXXXX)
updater_archive="geoflow-updater_0.3.0_linux_${updater_arch}.tar.gz"
curl -fL "https://github.com/yaojingang/geoflow-updater/releases/download/v0.3.0/$updater_archive" -o "$updater_dir/$updater_archive"
curl -fL https://github.com/yaojingang/geoflow-updater/releases/download/v0.3.0/checksums.txt -o "$updater_dir/checksums.txt"
gh attestation verify "$updater_dir/$updater_archive" --repo yaojingang/geoflow-updater
(cd "$updater_dir" && sha256sum --check checksums.txt --ignore-missing)
tar -tzf "$updater_dir/$updater_archive"
tar -xzf "$updater_dir/$updater_archive" -C "$updater_dir"
```

签名证明、校验和或下载有任何失败都停止。阅读解压后的 `packaging/scripts/install.sh`，确认是官方包再执行：

```bash
sudo "$updater_dir/packaging/scripts/install.sh"
sudo geoflow-updater version
```

不要把安装包来源改成未验证镜像，也不要将远端脚本直接通过管道交给 root。已有 Updater 的服务器先确认无运行中的更新、备份或恢复操作再安装；此步骤会重启 Updater 服务。

### 首次接管已经升级好的 Core

仅支持单宿主、实例名 `primary`、本机 PostgreSQL 和 Redis。项目根目录建议位于 `/opt/geoflow`；`/home`、`/root`、`/tmp` 等路径受服务沙箱限制，迁移目录需另行规划。`.env.prod` 中的数据库主版本、挂载和有效 `APP_KEY` 必须与现有实例一致。

在已完成备份的维护窗口，暂停新任务，排空所有队列，确认实际 Compose 项目是标准 `geoflow-laravel-prod`。自定义项目名需要先制定接管方案，避免新旧容器同时连接同一数据库目录。填入真实根目录：

```bash
sudo geoflow-updater enroll --instance-id primary --instance-root /opt/geoflow
sudo geoflow-updater authorization-uri --instance primary
```

将输出的更新、备份和回滚三个 URI 分别添加到可信验证器。URI 含授权秘密，只在本地保管。`enroll` 生成受管配置，不会自动替换现有部署；按它打印的、包含两个 `--env-file` 的命令完成 `down --remove-orphans` 与 `up -d --remove-orphans` 交接。执行前核对项目、数据目录和容器清单；交接会停止并移除该项目的容器与网络，禁止追加 `-v`。

标准旧 Compose 未持久化 Redis 数据，交接前队列必须为空。保留完整 `storage/app/geoflow-updates` 旧备份；已有受管状态、令牌和恢复点不要删除或手工重建。签名目标若已高于 `3.0.0`，停止接管，改用对应版本教程，勿修改 `version.json` 伪装版本。

```bash
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater verify --instance primary
sudo geoflow-updater backup --instance primary
sudo geoflow-updater recovery-points --instance primary
```

诊断与环境验收成功，且完整备份产生可用恢复点后，再确认网站更新中心能够读取状态。备份属于会短暂停止服务的操作，安排在维护窗口。后续维护统一使用受管编排和 Updater。[Updater v0.3.0 完整说明](https://github.com/yaojingang/geoflow-updater/blob/v0.3.0/README.md)

## 故障处理与回退

| 现象 | 处理方法 |
| --- | --- |
| 升级后出现空站、缺文章 | 立即停止写入，核对 Compose 项目名、数据库名和原数据挂载；不要初始化或清理旧数据目录 |
| 安全迁移门禁拒绝 | 确认所有旧进程和请求排空，只给一次性迁移传入 drain 确认；保留失败日志 |
| 页面 500、样式或图片缺失 | 检查构建结果、原环境配置、Web 根目录、存储权限与挂载；禁止用 `chmod 777` 或更换 `APP_KEY` 试错 |
| 模型不可见、任务身份冻结 | 核对历史模型所有者、共享授权和身份回填报告，由管理员明确归属后处理 |
| `inspection_failed` 或 AI 质检未完成 | 先查 AI 质检健康检查、专用队列、模型权限和脱敏日志；这个通用错误码本身无法证明升级可修复 |
| Updater 签名、摘要、过期或环境诊断失败 | 停止操作，检查系统时间、网络和官方发布状态；保留验证流程，不绕过校验 |

仍在维护窗口内、尚未恢复业务写入的失败升级，可由运维使用升级前的完整备份恢复代码、数据库、文件和配置。数据库已经迁移时，仅切回 Git 标签可能不兼容；不要把 `migrate:rollback` 当作通用恢复步骤。

受管实例可以在更新中心选择最近的更新前恢复点，或由宿主管理员先列出恢复点，再用真实 ID 执行：

```bash
sudo geoflow-updater recovery-points --instance primary
sudo geoflow-updater rollback --instance primary --recovery-point RECOVERY_POINT_ID
sudo geoflow-updater doctor --instance primary --json
```

回滚会覆盖当前数据库、文件与配置，恢复点之后的写入可能丢失。执行前保留故障现场并明确恢复范围。普通管理员开始使用 3.0 个人模型后，保留模型隔离与身份字段，优先采用兼容修复；禁止恢复全局模型池或重放缺身份的旧载荷，见 [模型隔离回滚约束](../admin-ai-config-sharing-runbook.md#回滚)。

报障请提供当前版本、部署方式、系统架构、操作阶段、退出码和脱敏错误。不要上传 `.env`、数据库、授权 URI、Token、API Key 或完整私有内容。
