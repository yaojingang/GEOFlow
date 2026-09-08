# 蓝绿部署与自动迁移

先通过 geoflow-updater 已签名安装包中的 `packaging/scripts/install.sh` 完成主机安装和信任引导，再使用仓库的薄入口 `scripts/geoflow-deploy.sh`。入口只转交参数给已安装的 updater，主机权限、Docker、签名验证、部署状态和恢复逻辑由 updater 统一处理。

首次安装：

```sh
sudo scripts/geoflow-deploy.sh install --instance primary --root /opt/geoflow --url https://geo.example
```

已有实例接管：

```sh
sudo scripts/geoflow-deploy.sh enroll --instance-id primary --instance-root /opt/geoflow
sudo scripts/geoflow-deploy.sh status --instance primary --json
```

先预检，再提交预检结果中的 `plan_sha256`：

```sh
sudo scripts/geoflow-deploy.sh update --instance primary --dry-run --json
sudo scripts/geoflow-deploy.sh update --instance primary --plan-sha256 <plan_sha256>
```

计划为 `maintenance` 时，在确认维护窗口后加上 `--allow-maintenance`。在线升级要求签名计划明确列出兼容的来源序列，并确认数据库、队列、缓存、存储以及每个迁移步骤可同时运行。默认计划保持维护模式。现有单套部署首次转换为蓝绿布局需要维护窗口。预检失败或计划摘要改变时，重新检查并预检；系统不会自动跳过确认。

后台更新中心提供“获取升级计划”，显示目标版本、布局变化和待执行迁移。更新提交会绑定该次预检的摘要，并要求管理员密码（按站点设置）和更新专用授权码。维护模式还要求主动勾选维护窗口。

恢复入口区分应用与数据：

```sh
sudo scripts/geoflow-deploy.sh rollback --application --instance primary
sudo scripts/geoflow-deploy.sh rollback --data --instance primary --recovery-point <point_id>
```

应用回切保留当前数据，仅在保留版本与当前数据兼容时切换；后台使用更新授权码。数据恢复会恢复指定恢复点中的数据，后台使用恢复专用授权码，并保持最新更新检查点限制。主机 CLI 通过主机权限授权。不要将两种操作当作可互换的恢复方式。

发布候选版本时，CI 先以 `deployment/generate-upgrade-plan.py --check` 校验已审阅迁移清单，再把完整计划写入 `releases/<version>/upgrade-plan.json` 的 TUF 签名目标。Schema 3 的维护计划要求 updater 协议至少为 3，在线计划至少为 4。历史 schema 1/2 保留读取兼容；新发布必须包含签名升级计划。

新候选版本运行 `planned-acceptance.yml`：原生 amd64、arm64 分别验证镜像与计划一致性、空数据库迁移、首次初始化、固定回填步骤、缓存编译、应用就绪状态和 Nginx 入口切换。审批材料明确标注 `planned-container-contract-and-ingress` 范围；完整已安装主机的升级、恢复点还原及崩溃恢复仍需单独演练。历史 `phase-c-rehearsal.yml` 只接收 schema 2 候选，不能充当 schema 3 的验收证据。

## 运行与恢复边界

应用升级、首次初始化、队列及调度进程以 UID 33 读写共享存储。部署器为新目录设置明确权限，运行进程关闭重复权限扫描和自动缓存优化；每个槽位的视图缓存由切流前的升级步骤预热。生产入口优先使用已注入的 APP_KEY，容器继续只读挂载受保护的 `.env.prod`。

稳定入口、PostgreSQL 和 Redis 共享一套；blue、green 各自保存应用配置和编译视图。原站点的 APP_KEY、会话和业务文件延续使用。外部 HTTPS 由现有反向代理提供，入口默认监听主机 18080 端口。首次安装生成的管理员凭据保存在站点目录下 `install-credentials.txt`，仅主机管理员可读。

在线切换先验收候选，再平滑切流。旧队列与调度器完成在途任务后交接，旧请求超时未结束时保留旧槽位并报告需要恢复处理。默认观察 120 秒。当前自动探测覆盖服务进程、入口版本、HTTP、数据库、Redis、存储及业务升级清单；登录延续、真实业务读写、任务副作用、Reverb 跨实例消息和重连需要在对应候选的演练中验证。

上线前为新旧应用并存预留 CPU、内存和磁盘，并根据实际负载压测确认容量。当前预检验证运行健康与发布兼容性，容量峰值仍需运维确认。在线数据库快照仅覆盖 PostgreSQL 的一致性；完整数据库、Redis、业务文件恢复点在停写维护阶段创建。流量开放后，更新失败不会自动还原整库。

`deployment/upgrade-plan.json` 默认使用维护策略，152 个历史迁移文件以 SHA-256 固定。新增迁移后先生成清单并审阅；声明在线版本时需要逐项确认允许来源与数据兼容性，补上对应候选的并发与恢复演练。初次安装中断后，重复相同安装命令会延续原密钥，补齐系统知识与媒体；开放流量后重试仅完成服务激活与检查。
