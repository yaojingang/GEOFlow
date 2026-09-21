# GEOFlow MySQL 向量兼容与 SAE 部署改造计划

状态：代码与部署资料已实施，待隔离 RDS/SAE 验收

## 目标

在不改变 GEOFlow 现有业务能力边界的前提下，完成以下目标：

1. 支持阿里云 RDS MySQL 8.0 的原生 `VECTOR` 与向量距离函数。
2. 保留 Redis 队列、缓存、锁和 Reverb 所需的运行时能力。
3. 支持 OSS 作为业务文件存储，并兼容 NAS 作为 SAE 多实例共享目录。
4. 将当前 Docker Compose 生产拓扑拆解为适合 SAE 的 Web、Worker、Scheduler 和 Reverb 部署单元。
5. 保留 PostgreSQL/pgvector 路径，避免把本地开发、既有实例或未来迁移路径锁死在 MySQL 上。
6. 为 MySQL fresh install、已有 MySQL 数据迁移、向量检索、队列和 SAE 部署建立可重复的测试与验收步骤。

## 已确认的外部条件

- 阿里云 RDS MySQL 8.0，当前内核版本为 `rds_20260228`。
- RDS 已开启向量存储；`VECTOR_DIM(VEC_FROMTEXT('[1,0]'))` 和 `VEC_DISTANCE_COSINE(...)` 测试成功。
- RDS 位于华南 1（深圳）专有网络，SAE 应与 RDS、Redis 处于可连通的 VPC/安全组/白名单范围。
- 已有 Redis、OSS、NAS 和 SAE。
- 当前项目原生路径是 PostgreSQL + pgvector；源码中存在 `CREATE EXTENSION vector`、`vector(3072)`、`<=> CAST(... AS vector)`、PostgreSQL 触发器/函数和驱动专用迁移。
- 当前工作区基线为 `main`，基线提交为 `f9cd887`；本任务使用独立 `codex/` 功能分支，不修改线上数据库。

## 目标架构

```text
                         ┌──────────────────────┐
                         │       Internet       │
                         └──────────┬───────────┘
                                    │ HTTPS
                         ┌──────────▼───────────┐
                         │ SAE Web / PHP-FPM    │
                         └─────┬────────┬───────┘
                               │        │
                  ┌────────────▼─┐  ┌──▼──────────────┐
                  │ RDS MySQL    │  │ Redis / Tair    │
                  │ + VECTOR     │  │ queue/cache/lock│
                  └──────────────┘  └─────────────────┘
                               │
                  ┌────────────▼────────────┐
                  │ SAE Worker / Scheduler  │
                  │ Knowledge / AI / Reverb │
                  └────────────┬────────────┘
                               │
                  ┌────────────▼────────────┐
                  │ OSS + optional NAS      │
                  │ objects + shared files  │
                  └─────────────────────────┘
```

### 数据库策略

- 应用业务表与 `knowledge_chunks` 使用当前 RDS MySQL。
- PostgreSQL 路径继续保留；新增 MySQL 路径只能通过明确的适配器/能力检测进入。
- 不在业务代码中继续散落 MySQL/PostgreSQL 原始判断；向量写入、查询、能力探测集中在一个深模块接口之后。
- PostgreSQL 专用迁移保持原有 PostgreSQL 语义；含共享 Schema Builder 的历史迁移则保留同一业务语义，并对 MySQL 的 64 字符标识符限制和 InnoDB 外键索引依赖做显式命名/顺序修复。之后新增驱动差异继续使用独立 migration；这类源码修复在本分支合并后应冻结，不能再做无关重写。

### 文件存储策略

- 首次 SAE 上线默认使用 NAS 保持现有 `Storage::path`、`rename`、压缩包和解析器的 POSIX 文件语义；不把 OSS 直接伪装成已经支持随机路径读写的本地盘。
- OSS 先作为显式的 S3 兼容磁盘，用于已经适配的对象、备份和归档；上传、图片、知识库源文件、主题包和 Markdown 导出等路径要逐项完成流式/临时文件改造后，才能把对应业务切换到 OSS。
- 不把 NAS 当数据库或 Redis 使用；Web、普通 Worker、知识库 Worker 使用一致的 NAS 挂载点和目录约定。
- SAE 容器本地磁盘只用于临时文件、缓存和日志，不能作为唯一业务文件存储。

### SAE 进程策略

- `web`：合并 Nginx + PHP-FPM 的 HTTP 入口（不依赖 Compose 服务名）。
- `queue`：普通系统、分发、主题和默认队列。
- `ai-quality`：AI 质量前台队列。
- `ai-quality-backfill`：AI 质量回填队列。
- `ai-optimization`：AI 优化队列。
- `knowledge`：知识库切片、embedding 和同步队列。
- `scheduler`：`schedule:work`，只运行一个副本并依赖 Redis 分布式锁。
- `reverb`：实时通信；若首期不需要实时后台状态，可明确关闭，而不是隐式丢失广播。

## 分阶段实施

### Phase 0：基线与可回滚准备

- 建立 `codex/mysql-sae-support` 功能分支。
- 记录当前工作区状态、基线提交和测试环境。
- 只使用脱敏的 MySQL/Redis/OSS 配置；不把真实密码、AccessKey 或连接串写入仓库。
- 增加 MySQL/向量能力的配置契约和健康检查说明。
- 为本地/CI 准备 MySQL 8.0 向量测试依赖；若 CI 无法提供 RDS 特有向量能力，使用明确的 capability-gated 测试，不把 SQLite 测试伪装成 MySQL 覆盖。

验收：基线测试可运行；新分支可在没有线上凭据的情况下构建和执行静态检查。

### Phase 1：建立数据库能力与向量适配器

实际实现了一个小而稳定的数据库向量接口，集中暴露：

- `isAvailable()`：当前连接是否具备可用向量能力；
- `capabilities()`/`isAvailable()`/`dimensions()`：懒加载并缓存当前连接的向量能力；
- `encode(array $vector, ?int $dimensions)`：将 embedding 归一化为固定维度文本；
- `writeValue()`/`writeExpression()`：生成 PostgreSQL 绑定值或 MySQL `VEC_FROMTEXT` 表达式；
- `similarityExpression()`/`limitClause()`：生成驱动对应的余弦距离与安全的字面量 LIMIT。

适配器实现：

- PostgreSQL：保留 pgvector 的 `vector(n)`、`CAST(? AS vector)` 和 `<=>` 路径。
- MySQL：使用 `VECTOR(n)`、`VEC_FROMTEXT`/`TO_VECTOR`、`VEC_DISTANCE_COSINE` 及 MySQL 向量索引语法。
- 不支持的驱动：明确返回不可用，让已有 fallback 逻辑处理，而不是捕获所有异常后静默改变业务语义。

实现要求：

- 通过容器注入接口，不在多个服务中复制 `DB::getDriverName()` 判断。
- 所有表达式中的列名使用内部白名单，不把用户输入拼接进 SQL；向量值必须走绑定或安全转换。
- 统一处理维度、空向量、NaN/Infinity、截断/补零和模型指纹。
- 将能力探测缓存到合理的短 TTL，避免每次知识库查询都访问系统表。

验收：适配器单元测试覆盖 pgsql、mysql、不可用、错误维度、空向量和距离表达式；调用方不再直接依赖 MySQL/pgvector 方言。

### Phase 2：知识库写入与检索改造

改造范围：

- `KnowledgeChunkSyncService` 的向量写入、维度归一化和能力判断；
- `KnowledgeRetrievalService` 的候选召回、距离排序和 fallback；
- AI 质量检索依赖的知识库查询；
- 管理后台的数据库向量能力状态展示和提示文案；
- `KnowledgeChunk` 模型的 casts/字段读写。

行为要求：

- MySQL 向量能力可用时，优先使用数据库向量检索。
- 向量能力不可用时，保留已有关键词/fallback 路径，并在 retrieval metadata 中记录原因。
- 不把向量查询失败静默伪装成高质量召回；记录可诊断的、脱敏的 capability/error code。
- 大批量 embedding 同步使用现有队列与分批策略，不能在 Web 请求内一次性加载全部知识库。
- 保持模型维度、provider、fingerprint、profile version 等隔离条件，避免不同 embedding 配置相互召回。

验收：同一批 fixture 在 PostgreSQL 和 MySQL 上均能写入、检索、排序；无向量能力时现有 fallback 测试继续通过；错误 provider/维度不会污染已发布切片。

### Phase 3：迁移与 Schema 兼容

先按迁移审计结果实施，优先解决会阻断 MySQL fresh install 的部分：

1. 为 `geoflow_legacy_schema` 提供 MySQL Schema Builder/driver-specific 实现，确保基础表完整创建。
2. 将 pgvector extension migration 改为能力检测；MySQL 走向量列创建，不执行 `CREATE EXTENSION`。
3. 将 `knowledge_chunks.embedding_vector` 的驱动定义分支化，3072 维保持与现有 embedding pipeline 一致。
4. 对 PostgreSQL 专用触发器/函数逐项处理；URL revision 的 statement-level transition table 在 MySQL 上暂不伪造 row-level trigger，迁移只初始化 `primary` 状态并依赖已存在的应用层 revision 路径，后续再补充经过验证的 MySQL 机制。
5. 对 JSON、upsert/conflict、索引、外键、删除约束和 `ALTER TABLE` 做逐个迁移适配。
6. 对已经在生产执行过的迁移不做无关重写；但本次 fresh-install 验证证明，若保留 MySQL 不可执行的历史 Schema Builder 定义，任何新增 migration 都无法在其之前介入，因此对尚未用于 MySQL 部署的共享迁移补上显式短约束名、短索引名和必要的索引创建顺序。PostgreSQL 专用文件仍不改写，驱动能力新增部分使用独立 migration。
7. 大表 backfill 使用可恢复的 Artisan command/job，分批、可观察、可重试，不塞进 schema migration。

迁移策略：

- Fresh install：在 MySQL 上从零跑完整迁移，验证没有“pgsql 跳过导致缺表”。
- Existing MySQL：仅适用于之后创建的 MySQL 安装；如果 RDS 里已有其他业务数据，必须使用独立 GEOFlow database/schema 和备份。
- Existing PostgreSQL GEOFlow：提供导出/转换工具或明确的停机迁移 runbook，不能把 PostgreSQL 数据库直接指向 MySQL。
- Production：迁移前备份、dry-run、表数量/列数量/关键计数核对，再执行正式 migration。

验收：标准 MySQL 8.0 fresh install 已完成全部迁移且二次执行无 pending migration；关键表、外键、索引、默认值和 JSON 列存在。标准 MySQL 会安全跳过原生 VECTOR 列，阿里云 RDS 的 `VECTOR(3072)` 列、写入和检索仍需隔离 RDS 验收；回滚说明诚实且不承诺会恢复已转换的数据。

### Phase 4：MySQL 查询与事务兼容

对静态审计发现的 raw SQL 逐类改造：

- PostgreSQL JSONB cast/operator；
- `ON CONFLICT`、`RETURNING`、`ILIKE`、`IS DISTINCT FROM`；
- 字符串连接和日期表达式；
- `DROP INDEX CONCURRENTLY`；
- `lockForUpdate`、隔离级别和批量 upsert；
- 触发器/transition table 行为；
- `information_schema` 与驱动能力检查。

要求：

- 普通 CRUD 尽量使用 Schema Builder/Eloquent/Query Builder；
- 必须 raw SQL 时按驱动适配器封装并使用参数绑定；
- 对 MySQL 向量索引查询验证 RDS 要求的 RC 隔离级别，不能默认沿用 PostgreSQL 事务假设；
- 重要查询用代表性数据执行 `EXPLAIN`/查询计划检查。

验收：MySQL Feature 测试覆盖创建、更新、删除、并发、分页、JSON、任务调度和 URL revision；慢查询和重复索引不在上线后才发现。

### Phase 5：OSS/NAS/Redis 配置与安全

- 完善 `config/filesystems.php` 的 OSS endpoint、region、bucket、path-style 与可见性配置。
- 保留 `FILESYSTEM_DISK=local` 作为本地开发和 SAE 首期 NAS 默认；已经完成对象流式/临时文件适配的业务，再按目录切换到 OSS。
- 明确哪些目录必须使用 NAS；避免让 cache/session/log 写入需要高延迟共享存储的路径。
- Redis 统一配置 queue/cache/locks/Reverb，确认所有 SAE Worker 使用同一个 Redis endpoint/DB/prefix。
- 生产密钥使用 SAE 环境变量/Secret，不把 `.env.prod` 和 AccessKey 提交仓库。
- RDS、Redis、OSS 使用最小权限、VPC 白名单、TLS/内网 endpoint（如果实例配置支持）。

验收：上传、下载、导出、队列、缓存锁、Reverb 连接和多实例文件访问均有测试或部署检查项。

### Phase 6：容器与 SAE 部署适配

- 生产 PHP 镜像增加 `pdo_mysql`，保留 Redis、curl、pcntl、zip 等现有运行时能力。
- 应用镜像通过 ACR/等价镜像仓库发布；镜像 tag 固定到版本或 commit，不使用不可追踪的 `latest`。
- SAE 发布构建两个不可变镜像：`Dockerfile.prod` 的应用/Worker 镜像，以及以它为基础、由 `Dockerfile.sae-web` 生成的 Nginx + PHP-FPM Web 镜像；GitHub Runner 用公网 ACR 地址构建，SAE 发布参数可使用同一仓库的 VPC 地址。
- `docker-compose.prod.yml` 继续作为本地/自托管参考；新增 SAE 部署说明和每个进程的启动命令，不把 Compose 中的 PostgreSQL/Redis 容器搬进 SAE 生产。
- 提供独立的 `.env.sae.example`，不把 Compose 的自动迁移/首次安装开关直接复制到 SAE 常驻进程。
- Web 入口保证 `/up` 健康检查、HTTPS 反代、可信代理和 WebSocket/Reverb 路由正确。
- SAE Web 镜像不得依赖 `app:9000` 或 `reverb:18080` 这类 Compose 网络别名；PHP-FPM 使用同容器 socket/localhost，上游 Reverb 使用可配置内网地址。
- Worker 设置与现有任务 timeout/retry_after 对齐，避免重复执行长任务；每个队列按资源和吞吐独立伸缩。
- Scheduler 只保留一个活跃副本，依赖 Redis lock 和 `onOneServer`。
- Reverb 单独部署；不需要实时功能时通过显式配置关闭并验证前端不会假定连接存在。
- NAS 挂载点和 OSS 访问策略在 Web/Worker 之间一致；常驻进程不执行数据库迁移或首次安装。
- 发布流程单独运行一次 release/migration job：迁移、空库首次安装和配置缓存完成后才放开常驻副本。
- 内置 Updater/Unix socket 不直接照搬到 SAE；生产升级优先采用镜像发布与 SAE 版本切换，Updater 能力需单独验证。

#### GitHub Actions 自动发布

参考 `/home/yuanjiawei/AIProject/fzzs/case_site/.github/workflows/deploy-sae.yml` 的已验证结构，但不直接复制其单容器 Next.js 假设：

- checkout → 依赖/测试 → Buildx → ACR 登录 → 固定 commit tag 构建推送 → `aliyun sae DeployApplication`；
- ACR Personal 使用 `provenance: false` 和 `sbom: false`，避免不兼容 OCI attestation manifest；
- ACR、SAE region、SAE app id、镜像仓库和部署环境全部从 GitHub Secrets/Variables 注入；
- workflow 先部署 Web 应用，再按显式开关更新 Worker、AI Quality 前台/回填、AI Optimization、Scheduler、Knowledge 和 Reverb 应用，避免一个发布动作意外重启所有角色；
- 生产部署使用 commit tag，不把 `latest` 作为唯一可回滚标识；
- 部署步骤输出镜像 tag 和 SAE 应用目标；发布后的健康检查由 SAE 探针/`sae-healthcheck.sh` 完成；
- GitHub Actions 只负责构建和触发部署，不把 RDS migration、Redis flush、OSS 删除或线上数据迁移放进默认 push workflow；数据库迁移使用独立、受保护的 workflow 或人工批准的 release job；
- 为 workflow 增加 YAML/文本契约测试，检查 action major versions、必需 secrets、ACR tags、attestation 关闭和部署参数，沿用 casesite 的测试思路。

建议的变量/密钥最小集合：

```text
Variables: ACR_LOGIN_REGISTRY, ACR_IMAGE_REGISTRY(optional),
           ACR_NAMESPACE, ACR_REPOSITORY, SAE_REGION_ID,
           COMPOSER_PACKAGIST_MIRROR(optional)
Secrets:   ACR_USERNAME, ACR_PASSWORD,
           ALIYUN_SAE_AK_ID, ALIYUN_SAE_AK_SECRET,
           SAE_WEB_APP_ID, SAE_WORKER_APP_ID, SAE_KNOWLEDGE_APP_ID,
           SAE_SCHEDULER_APP_ID, SAE_REVERB_APP_ID
```

真实应用环境变量（DB、Redis、OSS、APP_KEY、AI keys）不进入 GitHub workflow 日志，使用 SAE 应用配置/Secret 注入。

验收：每个 SAE 进程能独立启动；Web、队列、调度、实时通信、健康检查和日志均可回读；重启/扩容后业务文件仍可访问。

### Phase 7：测试、验收与上线 Runbook

测试层级：

- 静态检查：Pint、PHP syntax、配置缓存、Compose/YAML 校验；
- 单元测试：数据库能力/向量 adapter、维度/距离/fallback；
- Feature 测试：MySQL migrations、知识库同步/检索、AI 质量、任务、URL revision、OSS 文件；
- 集成测试：真实 RDS MySQL 测试库 + Redis；不对生产库写入；
- 容器测试：镜像启动、`php artisan about`、`migrate --force`、`/up`；
- SAE 验收：最小发布、日志、队列、定时任务、WebSocket、OSS/NAS、扩缩容和重启；
- 性能：1 核/2 GB 测试实例基准，向量召回延迟、队列积压、连接数、内存和慢查询。

上线顺序：

1. 备份 RDS、Redis 关键配置、OSS/NAS 关键文件。
2. 部署新镜像到独立 SAE 测试应用。
3. 指向测试库和测试 Bucket，执行迁移与数据核对。
4. 验收登录、文章、任务、AI、知识库、文件、队列、调度、Reverb。
5. 低流量灰度正式 SAE 应用。
6. 观察错误率、队列等待、数据库连接、慢查询、内存和存储。
7. 通过 SAE 版本回滚恢复应用；数据库变更只使用已验证的 forward-fix/恢复方案。

## 子代理分工

- `mysql-audit`：PostgreSQL/pgvector/raw SQL/驱动分支审计。
- `migration-strategy`：迁移阻断点、fresh-install 与升级路径。
- `sae-deployment`：Docker、入口脚本、Nginx、Worker/Scheduler/Reverb/OSS/NAS 部署资料。
- `mysql-adapter`：数据库能力/向量适配器和单元测试。
- `mysql-migrations`：不与 adapter 重叠的 Schema/迁移补丁。
- `sae-tests`：部署配置、容器启动与验收测试；只读或使用隔离测试环境。

所有代码子代理必须使用不相交的写集；不改线上数据库、不写入真实密钥、不删除现有测试。主代理负责接口决策、合并、冲突处理、全局测试和最终提交。

## 完成定义

- MySQL fresh install 路径、PostgreSQL 路径和迁移分支已实现并完成静态契约检查；真实 RDS fresh install 仍待隔离库验收。
- RDS MySQL 向量函数探测路径已接入；知识库写入和检索仍需在真实 RDS 向量列上验收。
- Redis 队列/缓存/锁、NAS 存储和 SAE 网络连通性仍需在隔离 SAE 应用验收；OSS 仍按目录逐项适配。
- SAE 所需镜像、进程命令、环境变量、网络和健康检查文档完整。
- 至少一轮隔离环境端到端验收通过。
- 所有 PHP 改动已格式化，聚焦测试和 CI 相关检查通过。
- 工作区仅包含本任务改动；如后续要求提交，再按仓库规则完成 feature branch、PR、检查和合并。

## 当前不执行的操作

- 不连接或修改用户的生产 RDS、Redis、OSS、NAS。
- 不替用户执行 RDS 内核升级、向量开关以外的数据库变更、数据迁移或删除。
- 不把真实凭据写入仓库或测试日志。
- 不在没有 MySQL 集成环境的情况下宣称完整兼容；无法验证的部分标记为待验收。
