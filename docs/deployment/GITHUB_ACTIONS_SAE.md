# 使用 GitHub Actions 部署 GEOFlow 到 SAE

`.github/workflows/deploy-sae.yml` 参考 `/home/yuanjiawei/AIProject/fzzs/case_site` 的发布链路，先执行 PHP/JavaScript 测试与格式检查，再把 GEOFlow 的 PHP 应用镜像和 Nginx + PHP-FPM 合并 Web 镜像构建并推送到阿里云容器镜像服务（ACR），最后调用阿里云 CLI 的 `sae DeployApplication` 发布到 SAE。

默认 push 发布只负责镜像和常驻 SAE 应用发布，不负责数据库初始化、数据库迁移或 GEOFlow 首次安装。手动 `workflow_dispatch` 可以在配置了受保护 `production` Environment 和 `SAE_RELEASE_APP_ID` 后，显式运行一次性 release 应用；常驻 Web/Worker 仍不会自行迁移。

RDS 向量、迁移和 release 验收步骤见 [RDS MySQL 隔离验收手册](RDS_MYSQL_VALIDATION.md)。

## 触发方式

| 触发方式 | 默认行为 |
| --- | --- |
| 推送到 `main` | 构建一次 commit 镜像，并尝试部署 Web 应用 |
| `workflow_dispatch` | 构建一次 commit 镜像；Web 默认选中，其余角色由手动勾选决定 |

Worker、AI Quality 前台、AI Quality 回填、AI Optimization、Knowledge、Scheduler 和 Reverb 的手动开关默认都是关闭的。某个角色必须同时满足两个条件才会调用 SAE CLI：手动开关为 `true`，并且对应的应用 ID 已配置。开关未打开或应用 ID 缺失时，工作流会打印跳过原因，不会以空的 `--AppId` 调用 CLI。

应用镜像和 Web 镜像只推送不可变的 commit tag：

```text
<ACR_IMAGE_REGISTRY>/<ACR_NAMESPACE>/<ACR_REPOSITORY>:<GITHUB_SHA>
<ACR_IMAGE_REGISTRY>/<ACR_NAMESPACE>/<ACR_REPOSITORY>:<GITHUB_SHA>-web
```

Worker、AI Quality 前台/回填、AI Optimization、Knowledge、Scheduler 和 Reverb 使用 `${GITHUB_SHA}` 应用镜像；Web 使用 `${GITHUB_SHA}-web` 合并镜像，不使用可能被覆盖的 `latest` tag。

## GitHub Variables

在仓库或 GitHub Environment 的 **Settings → Secrets and variables → Actions → Variables** 中配置：

| Variable | 必填 | 示例 | 用途 |
| --- | --- | --- | --- |
| `ACR_LOGIN_REGISTRY` | 是 | `crpi-xxxx.cn-shenzhen.personal.cr.aliyuncs.com` | GitHub-hosted runner 登录 ACR 的地址 |
| `ACR_IMAGE_REGISTRY` | 否 | `crpi-xxxx-vpc.cn-shenzhen.personal.cr.aliyuncs.com` | 镜像 tag 和 SAE 拉取镜像使用的地址；不填时回退到 `ACR_LOGIN_REGISTRY` |
| `ACR_NAMESPACE` | 否 | `fzzs` | ACR 命名空间，默认 `fzzs` |
| `ACR_REPOSITORY` | 否 | `geoflow` | ACR 仓库名，默认 `geoflow` |
| `SAE_REGION_ID` | 否 | `cn-shenzhen` | SAE 区域，默认 `cn-shenzhen` |
| `COMPOSER_PACKAGIST_MIRROR` | 否 | `https://mirrors.aliyun.com/composer/` | Docker 构建时可选的 Composer 镜像源 |

如果 GitHub Runner 在公网，`ACR_LOGIN_REGISTRY` 应使用公网可访问的 ACR 地址；如果 SAE 与 ACR 在同一专有网络，`ACR_IMAGE_REGISTRY` 可以使用 ACR 的 VPC 地址。这个配置沿用了 `case_site` 的做法：Runner 登录/构建使用公开地址，SAE 发布参数使用 VPC 地址。两个地址必须指向同一个 ACR 实例、命名空间和仓库。

## GitHub Secrets

在同一位置配置以下 Secrets。推荐把它们放在受保护的 GitHub Environment 中，并限制可部署分支。

| Secret | 用途 |
| --- | --- |
| `ACR_USERNAME` | ACR 登录账号 |
| `ACR_PASSWORD` | ACR 登录密码或访问凭证 |
| `ALIYUN_SAE_AK_ID` | 调用 SAE API 的阿里云 AccessKey ID |
| `ALIYUN_SAE_AK_SECRET` | 调用 SAE API 的阿里云 AccessKey Secret |
| `SAE_WEB_APP_ID` | Web SAE 应用 ID |
| `SAE_WORKER_APP_ID` | 普通队列 Worker SAE 应用 ID |
| `SAE_AI_QUALITY_FRONT_APP_ID` | AI 质量前台队列 SAE 应用 ID |
| `SAE_AI_QUALITY_BACKFILL_APP_ID` | AI 质量回填 SAE 应用 ID |
| `SAE_AI_OPTIMIZATION_APP_ID` | AI 优化队列 SAE 应用 ID |
| `SAE_KNOWLEDGE_APP_ID` | Knowledge 队列 Worker SAE 应用 ID |
| `SAE_SCHEDULER_APP_ID` | Scheduler SAE 应用 ID |
| `SAE_REVERB_APP_ID` | Reverb SAE 应用 ID |
| `SAE_RELEASE_APP_ID` | 一次性 release/migration SAE 应用 ID；仅 `run_release=true` 时使用 |

非 Web 应用 ID 可以不配置；只有在手动勾选对应角色且 ID 存在时才会发布该角色。Web 应用 ID 也必须配置，否则推送到 `main` 时会安全跳过 Web 发布并在日志中说明原因。

## SAE 应用准备

每个 SAE 应用需要在控制台中预先创建，并分别设置适合该角色的启动命令、端口、环境变量、VPC/安全组和 NAS 挂载。工作流只更新应用镜像，不会替代这些 SAE 应用配置。

建议的角色划分如下：

| 角色 | 典型启动命令 | 说明 |
| --- | --- | --- |
| Web | 由 `docker/Dockerfile.sae-web` 构建的合并镜像提供 HTTP 服务 | 必须能监听 SAE 配置的端口并通过 `/up` 健康检查 |
| Worker | `php artisan queue:work redis ...` | 绑定普通业务队列 |
| AI Quality 前台 | `php artisan geoflow:work-ai-quality front` | 对应 Compose 的 `ai-quality-queue` |
| AI Quality 回填 | `php artisan geoflow:work-ai-quality backfill` | 对应 Compose 的 `ai-quality-backfill-queue` |
| AI Optimization | `php artisan geoflow:work-ai-optimization` | 对应 Compose 的 `ai-optimization-queue` |
| Knowledge | `php artisan queue:work redis --queue=knowledge ...` | 绑定知识库队列 |
| Scheduler | `php artisan schedule:work` | 保持单副本，避免定时任务重复执行 |
| Reverb | `php artisan reverb:start --host=0.0.0.0` | 按 SAE 的 WebSocket/端口配置接入 |
| Release | `/usr/local/bin/geoflow-entrypoint-sae-release` | 仅手动受保护发布；应用配置需启用 `SAE_RELEASE_CONFIRM=true` 和明确的 release action |

`docker/Dockerfile.prod` 构建应用/Worker 镜像；工作流随后用该镜像作为基础构建 `docker/Dockerfile.sae-web`，在同一容器中运行 Nginx 和 PHP-FPM。Web 入口会把 PHP-FPM 默认上游渲染为 `127.0.0.1:9000`，Reverb 上游由 `GEOFLOW_REVERB_UPSTREAM` 注入，因此 SAE Web 不依赖 Compose 的 `app`/`reverb` 服务名。

## 数据库安全边界

默认 push 工作流明确不执行以下操作：

- `php artisan migrate`；
- `php artisan geoflow:install`；
- 任何自动建库、清库或生产数据初始化。

这样可以避免 Web、Worker、Scheduler、Reverb 扩缩容时争抢数据库迁移锁，也避免把现有 RDS 数据库误当成 GEOFlow 空库。MySQL/VECTOR 迁移和首次安装应在独立 release 流程中针对明确的 GEOFlow 数据库执行，并先完成隔离库验证、备份和回滚预案。

需要执行 release 时，在仓库 Settings → Environments 中为 `production` 配置 Required reviewers，然后手动运行该 workflow 并打开 `run_release`。release 应用必须预先配置 `/usr/local/bin/geoflow-entrypoint-sae-release`、`SAE_RELEASE_CONFIRM=true`、`AUTO_MIGRATE=true`（首次空库才允许 `AUTO_INSTALL_ONCE=true`）和与 Web/Worker 相同的 RDS/Redis/NAS Secret。工作流会调用 `DeployApplication`，再轮询 `DescribeApplicationStatus`，只有最近一次变更为 `SUCCESS` 才继续常驻角色部署。

## 验证与回滚

1. 先在 GitHub Actions 中手动只勾选 `deploy_web`，确认镜像构建成功。
2. 在 ACR 中确认 `${GITHUB_SHA}` tag 存在，并确认 SAE 能从 `ACR_IMAGE_REGISTRY` 拉取该 tag。
3. 数据库变更时先完成 RDS 隔离验收，再在受保护 Environment 中单独运行 `run_release`，确认 release 日志和 SAE change order 成功。
4. 在 SAE 中确认 Web 版本发布、容器日志、`/up` 健康检查、RDS/Redis/NAS 连接。
5. 再分别手动勾选 Worker、AI Quality 前台/回填、AI Optimization、Knowledge、Scheduler、Reverb，逐个观察队列和实时通信。
6. 发布异常时在 SAE 控制台回滚到上一条 commit tag；不要通过覆盖 `latest` 来回滚。

## 风险清单

- **Web 镜像运行时风险**：仓库已提供 `Dockerfile.sae-web`，把 Nginx 与 PHP-FPM 合并到同一个 SAE Web 容器；仍需在实际 SAE 环境验证监听端口、`/up`、可信代理和 Reverb 内网地址。
- **ACR 网络风险**：GitHub Runner 不能访问只在 VPC 内可达的登录地址；登录地址和 SAE 拉取地址必须按网络边界分别配置。
- **凭证风险**：当前沿用参考项目的 AccessKey/Secret 方式。应使用最小权限 RAM 用户、GitHub Environment 保护和定期轮换；后续可迁移到 GitHub OIDC/RAM Role，避免长期密钥。
- **应用配置风险**：SAE 应用 ID、启动命令、监听端口、环境变量、Redis、RDS、NAS 和 Reverb 配置不由此 workflow 创建或校验，必须在 SAE 控制台单独验收。
- **数据库版本风险**：镜像发布成功不等于 MySQL 方言和向量迁移已经兼容；数据库迁移必须等 MySQL 改造和隔离库测试完成后单独执行。
- **release 安全门**：`run_release` 仅是显式人工输入，仍必须在 GitHub `production` Environment 配置 Required reviewers；不要给 push 自动触发 release。
- **多副本调度风险**：Scheduler 默认不部署；启用后应保持单副本，并确认调度锁与 Redis 配置，避免任务重复执行。
