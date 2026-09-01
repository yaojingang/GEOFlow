# GEOFlow AI 质检运行手册

## 适用范围

AI 质检覆盖知识一致性、数据与引文、广告合规风险和内容完整性。系统输出质量分、证据覆盖、置信度、门禁原因、问题和待人工确认项。最终法律判断与业务事实确认由授权人员完成。

在线快速路径的目标如下：

- 正文不超过 5000 字：成功质检 P50 不超过 25 秒，P95 不超过 55 秒。
- 全文阶段默认预算 180 秒。任务开启超时自动抽样后，系统额外保留 45 秒抽样预算和 10 秒终态持久化预算，总硬截止为 235 秒。
- 全文模型单次请求上限 160 秒，抽样模型单次请求上限 35 秒，Job 安全超时 245 秒，Worker 安全超时 250 秒。
- 单篇最多抽取 12 条物质性主张，执行 1 次文章级检索和最多 6 次补检。
- 最多注入 12 条、6000 字符证据，模型输出上限 2048 Token。
- 模型、队列和 Redis 健康时，前台队列等待 P95 目标不超过 5 秒。

这些指标需要在部署环境使用真实模型和近 30 天文章样本验证。代码内置预算、分阶段计时和离线评测能力，不把未经验证的目标写成生产实测结论。

## 发布门禁

默认阈值为自动通过 85 分、人工放行最低 70 分。

- `passed`：达到通过线，且没有 hard blocker、重要不确定项或无效证据引用。
- `needs_review`：分数未达到通过线，或证据覆盖不足、存在重要不确定项、模型输出被截断。
- `blocked`：存在已确认 hard blocker，或总分低于人工放行线。
- `error`：模型、证据检索、队列或业务截止时间导致本次检查失败。

证据缺失会形成 `unverified` 和人工确认项。只有受管证据与文章主张在主体、数值、时间或范围上明确冲突时，才形成已确认冲突。页面短编号 `K1`、`K2` 仅用于展示，评分使用 `knowledge_base_id + chunk_id + content_hash` 稳定来源键。

## 运行链路

```text
文章生成或人工重检
  -> 保存文章与质检策略快照
  -> ai-quality 前台队列
  -> 主张抽取与稳定证据检索
  -> 一次紧凑结构化判断，必要时在剩余预算内使用 JSON 回退或候选模型
  -> 性能类失败且策略快照已授权时，原子切换至确定性抽样质检
  -> 复用已完成全文分段，覆盖高风险主张并校验抽样安全条件
  -> 后端引用、位置和数值校验
  -> scoring v1 或 scoring v2
  -> 发布门禁、审计记录与进度终态
```

自动生成文章会保存实际使用的知识块快照。质检会复核知识库身份、切片身份、内容哈希、来源哈希和治理状态，再优先复用有效证据。有效快照覆盖主张时不再重复检索；缺少快照时使用本地关键词、章节和治理信息执行共享召回，并只补检尚未覆盖的主张。在线质检不会等待远程 Embedding 请求。

## 队列和进程

| 队列 | 默认并发 | 用途 | 等待告警 |
| --- | ---: | --- | ---: |
| `ai-quality` | 2 | 人工质检、发布门禁和在线请求 | 10 秒 |
| `ai-quality-backfill` | 1 | 历史补算、异常恢复和版本回填 | 45 秒 |
| `ai-content-optimization` | 1 | 单篇文章 AI 优化 | 15 秒 |
| `ai-content-optimization-bulk` | 1，共用优化 Worker | 任务自动优化、恢复和对账 | 60 秒 |

回填每批最多处理 25 篇。前台检查等待超过 10 秒、当前模型熔断或模型当日额度接近保留水位时，回填暂停并保存文章游标。调度器每 5 秒同步执行一次过期状态收敛，不经过回填队列；全文记录到达 `primary_deadline_at` 后按策略快照进入抽样或失败，到达最终 `deadline_at` 后统一进入失败终态。每分钟对账只处理历史补算、版本变化、缺失记录及已完成质检的发布流程补偿。页面通过 `retryable` 提示是否适合人工重检，避免供应商持续故障时形成无限请求循环。

本地 `composer dev`、源码构建 Compose 和预构建镜像 Compose 均包含在线与回填两个独立消费者。普通业务队列继续使用自己的长任务超时，AI 质检消费者使用 250 秒安全超时，Job 使用 245 秒安全超时，队列 `retry_after` 保持 960 秒。生产 Compose 默认启动两个在线质检副本；健康检查会核对 `AI_QUALITY_QUEUE_REPLICAS` 对应的运行副本数。生产环境使用 Horizon 时确认两个 Supervisor 均处于运行状态。

## 超时自动抽样

任务新建或编辑页可勾选“完整质检超时后，允许自动降级为抽样质检并按结果放行”。开关默认关闭，AI 质检关闭时服务端会同步关闭该开关。每次检查将开关、通过线、人工审核要求、模型候选和规则版本写入策略快照，任务执行中的配置修改不会改变当前检查。

以下性能类原因允许进入抽样：全文预算耗尽、模型请求超时、正文超过在线全文长度上限、输出截断或输出预算不足、剩余预算无法完成下一分段。配额、鉴权、限流、网关、供应商不可用、队列、Worker、证据检索、知识库和文章变更等故障直接进入明确失败。

抽样器最多选择 6000 字符和 12 个互不重叠的原文范围，固定覆盖标题、摘要、开头、结论、高风险词、数字、日期、引用、承诺和重大事实主张，并稳定覆盖正文前、中、后三个区域。范围保存 Unicode 原文偏移；相同文章、规则和算法版本会生成相同范围。抽样自动放行还要求确定性风险扫描无阻断项、重大主张完整覆盖、证据充分、后端结构与引用校验通过、没有截断、没有严重问题，并达到任务原有通过线。

抽样完成使用兼容终态 `status=completed`、`decision=passed`、`inspection_scope=fallback_sampled`。任务要求人工审核时继续进入人工审核。系统级紧急开关 `GEOFLOW_AI_QUALITY_SAMPLED_AUTO_RELEASE_ENABLED=false` 可立即关闭抽样自动放行。数据库运行开关可通过 `php artisan geoflow:ai-quality-rollout sample-off` 立即关闭，并通过 `incident --incident=INCIDENT_CODE` 同时冻结灰度。关闭任务开关会使该任务当前有效的抽样通过记录失效，已有全文结果继续有效。

## 环境参数

```dotenv
GEOFLOW_AI_QUALITY_REQUEST_TIMEOUT_SECONDS=160
GEOFLOW_AI_QUALITY_DEADLINE_SECONDS=180
GEOFLOW_AI_QUALITY_SAMPLED_FALLBACK_SECONDS=45
GEOFLOW_AI_QUALITY_SAMPLED_REQUEST_TIMEOUT_SECONDS=35
GEOFLOW_AI_QUALITY_SAMPLED_MAX_CHARACTERS=6000
GEOFLOW_AI_QUALITY_SAMPLED_MAX_RANGES=12
GEOFLOW_AI_QUALITY_FULL_ONLINE_MAX_CHARACTERS=60000
GEOFLOW_AI_QUALITY_SAMPLED_AUTO_RELEASE_ENABLED=true
GEOFLOW_AI_QUALITY_PERSISTENCE_RESERVE_SECONDS=10
GEOFLOW_AI_QUALITY_MAX_OUTPUT_TOKENS=2048
GEOFLOW_AI_QUALITY_MAX_MODEL_CANDIDATES=2
GEOFLOW_AI_QUALITY_MAX_EVIDENCE=12
GEOFLOW_AI_QUALITY_MAX_EVIDENCE_CHARACTERS=6000
GEOFLOW_AI_QUALITY_MAX_FACT_RETRIEVALS=6

GEOFLOW_AI_QUALITY_QUEUE=ai-quality
GEOFLOW_AI_QUALITY_BACKFILL_QUEUE=ai-quality-backfill
AI_QUALITY_QUEUE_REPLICAS=2
GEOFLOW_AI_QUALITY_BACKFILL_WORKERS=1
GEOFLOW_AI_QUALITY_WORKER_HEARTBEAT_SECONDS=10
GEOFLOW_AI_QUALITY_WORKER_STALE_SECONDS=300
GEOFLOW_AI_QUALITY_JOB_TIMEOUT_SECONDS=245
GEOFLOW_AI_QUALITY_WORKER_TIMEOUT_SECONDS=250
GEOFLOW_AI_QUALITY_STOP_GRACE_PERIOD=260s
GEOFLOW_AI_QUALITY_FRONT_QUEUE_WAIT_SECONDS=10
GEOFLOW_AI_QUALITY_BACKFILL_QUOTA_RESERVE=2
GEOFLOW_AI_QUALITY_RECOVERY_STALE_SECONDS=60
GEOFLOW_AI_QUALITY_STRUCTURED_REPROBE_SECONDS=86400

GEOFLOW_AI_QUALITY_OPTIMIZATION_ENABLED=false
GEOFLOW_AI_QUALITY_OPTIMIZATION_AUTO_APPLY_ENABLED=false
GEOFLOW_AI_QUALITY_OPTIMIZATION_PERCENT=0
GEOFLOW_AI_QUALITY_OPTIMIZATION_AUTO_APPLY_PERCENT=0
GEOFLOW_AI_QUALITY_OPTIMIZATION_QUEUE=ai-content-optimization
GEOFLOW_AI_QUALITY_OPTIMIZATION_BULK_QUEUE=ai-content-optimization-bulk
GEOFLOW_AI_QUALITY_OPTIMIZATION_BULK_QUOTA_RESERVE=2
GEOFLOW_AI_QUALITY_OPTIMIZATION_MAX_MODEL_ATTEMPTS=2
GEOFLOW_AI_QUALITY_OPTIMIZATION_MAX_ROUNDS=3
GEOFLOW_AI_QUALITY_OPTIMIZATION_RECOVERY_STALE_SECONDS=300
GEOFLOW_AI_QUALITY_OPTIMIZATION_JOB_TIMEOUT_SECONDS=850
GEOFLOW_AI_QUALITY_OPTIMIZATION_WORKER_TIMEOUT_SECONDS=900

GEOFLOW_AI_QUALITY_EVIDENCE_CACHE_ENABLED=true
GEOFLOW_AI_QUALITY_EVIDENCE_CACHE_TTL_SECONDS=86400

GEOFLOW_AI_QUALITY_CIRCUIT_CONSECUTIVE_FAILURES=5
GEOFLOW_AI_QUALITY_CIRCUIT_SAMPLE_SIZE=10
GEOFLOW_AI_QUALITY_CIRCUIT_FAILURE_PERCENT=50
GEOFLOW_AI_QUALITY_CIRCUIT_OPEN_SECONDS=60

GEOFLOW_AI_QUALITY_PRINCIPLE_V2_PERCENT=0
GEOFLOW_AI_QUALITY_EXECUTION_VERSION=legacy
GEOFLOW_AI_QUALITY_FAST_V2_PERCENT=0
GEOFLOW_AI_QUALITY_SCORING_V2_PERCENT=0
GEOFLOW_AI_QUALITY_SHADOW_V2_PERCENT=0
```

调整完整质检预算时，需要同步调整 Job、Worker 和容器停止宽限时间，并保持 `完整预算 + 抽样预算 + 持久化预留 < Job timeout < Worker timeout < retry_after`。健康检查会把不满足该关系的配置标记为异常。

这些灰度百分比用于迁移前和数据库不可用时的安全回退。迁移完成后，运行时灰度状态保存在 `article_ai_quality_rollouts`，只接受 0%、10%、25%、50% 和 100% 五个阶段。

建议先保持 v1 门禁，生成覆盖全文与抽样路径的在线评测报告，然后按顺序推进原则、执行、影子评分和正式评分：

```bash
php artisan geoflow:ai-quality-rollout status
php artisan geoflow:ai-quality-rollout promote --track=principles --to=10 --report=storage/app/ai-quality-evaluations/live/report.json
php artisan geoflow:ai-quality-rollout promote --track=execution --to=10 --report=storage/app/ai-quality-evaluations/live/report.json
php artisan geoflow:ai-quality-rollout promote --track=shadow --to=10 --report=storage/app/ai-quality-evaluations/live/report.json
```

每次 `promote` 只允许进入下一个阶段，并要求 30 天内生成且通过门禁的在线端到端报告；报告必须同时覆盖全文和抽样路径、延迟闸门与同输入五次稳定性。影子记录带有 `gate_applied=false` 和基线记录 ID，不会成为发布结果。正式评分完成盲测评审后，再对 `scoring` 轨道执行同样的逐级推进。

发现重大风险漏检时执行：

```bash
php artisan geoflow:ai-quality-rollout incident --incident=MAJOR_RISK_YYYYMMDD
```

该操作冻结所有灰度推进并关闭抽样自动放行。修复完成后，使用新的合格在线报告解除冻结：

```bash
php artisan geoflow:ai-quality-rollout unfreeze --report=storage/app/ai-quality-evaluations/live/recovery-report.json
php artisan geoflow:ai-quality-rollout sample-on
```

## 上线步骤

1. 备份数据库，拉取代码并安装依赖。
2. 执行迁移，新增生成证据快照、评分版本、置信度和影子评测字段。
3. 刷新配置并重启队列进程。
4. 启动应用、前台质检、回填、主队列和调度器等长期进程。
5. 同步收敛过期任务，再执行 Worker 心跳、超时链和前台探针健康检查。
6. 全部闸门通过后恢复流量并执行 `/up` HTTP 冒烟检查。
7. 执行离线评测，保存 JSON 与 Markdown 报告。
8. 先开启快速链路和 v2 影子样本，观察一个发布周期。

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
php artisan geoflow:work-ai-quality front --validate
php artisan geoflow:work-ai-quality backfill --validate
php artisan geoflow:work-ai-optimization --validate
php artisan geoflow:converge-ai-quality --json
php artisan geoflow:ai-quality-health --probe --wait=10 --json
php artisan geoflow:evaluate-ai-quality
php artisan geoflow:reconcile-ai-quality --limit=25
php artisan geoflow:reconcile-ai-optimization --limit=25
```

## AI 质检自动优化

自动优化使用“质检问题、局部补丁、后端校验、完整复检”的有限循环。单篇入口默认只生成影子候选，任务自动应用还受独立开关和稳定灰度百分比控制。候选复检固定禁用抽样，来源或文章指纹变化会让运行进入 `stale`，正式文章只在候选达到目标且事务校验通过后更新。

上线时依次开启单篇预览、任务影子运行和任务自动应用。自动应用需要先完成 240 条基础质检集、120 条优化基准和 30 条对抗样本，并满足安全指标。紧急停用顺序如下：

1. 将 `GEOFLOW_AI_QUALITY_OPTIMIZATION_AUTO_APPLY_PERCENT` 调为 `0`。
2. 需要停止新运行时，将 `GEOFLOW_AI_QUALITY_OPTIMIZATION_PERCENT` 调为 `0` 并关闭 `GEOFLOW_AI_QUALITY_OPTIMIZATION_ENABLED`。
3. 刷新配置，重启 Worker，执行优化对账命令。
4. 检查 `applying`、过期租约和 `waiting_optimization` 数量已经收敛。

健康快照的 `optimization_metrics` 会显示活跃运行、过期截止时间、过期租约、24 小时转人工和失败数量。恢复任务每分钟清理过期租约、继续已完成候选、补偿最终工作流，并处理缺少活跃运行的 `waiting_optimization` 记录。

## 状态查询

管理后台会展示阶段、已用时间、按策略计算的最长时间、分段进度和安全错误。抽样阶段会显示独立状态。失败记录独立展示失败阶段、原因、模型尝试耗时、安全错误码、下一步建议和重试按钮；技术失败的总分与四维分统一显示“未评分”，页面不会渲染成功结论。

只读 API：

```http
GET /api/v1/articles/{article}/ai-quality/status
Authorization: Bearer <articles:read token>
```

CLI：

```bash
geoflow article ai-quality-status ARTICLE_ID
```

状态载荷包含 `effective_status`、`elapsed_ms`、`primary_deadline_at`、`deadline_at`、`inspection_scope`、`degraded`、`result_label`、`score_label`、`coverage`、`fallback`、`queue_wait_ms`、`service_status`、`timings`、`safe_error_code`、`retryable`、`next_action` 和 `failure`。`coverage` 返回字符数、范围数、重大主张覆盖和不含正文的原文偏移。`failure` 提供可展示的 `title`、`reason`、`next_step`、`model_attempt_seconds`、HTTP 状态与脱敏供应商代码，不会返回文章正文、证据正文、API Key 或供应商内部异常。页面到达 `deadline_at + 5 秒` 后会停止等待，并展示安全错误信息。

## 模型能力和熔断

质检会在模型 readiness profile 的 `article_quality_structured_output` 中记录：

- 配置指纹和最近成功时间。
- 最近 20 次 Schema 通过率与错误率。
- 响应延迟 P50、P95。
- 结构化模式、JSON 回退模式和能力状态。

首次质检承担受单次请求预算约束的惰性能力探测。结构化模式已被验证为降级时，后续请求会直接使用严格 JSON，减少重复失败。默认 24 小时后或配置指纹变化后重新探测结构化能力。固定单模型最多使用 160 秒；存在多个同端点候选时，首个候选最多使用约 65% 的可用请求预算，为一次候选切换保留约 60 秒。

连续 5 次可重试供应商错误，或最近 10 次调用错误率达到 50% 时，熔断器打开 60 秒。熔断结束后只放行一个半开探测请求。智能切换只选择与主模型使用相同协议、主机和端口的候选模型；需要跨供应商切换时，应由管理员显式调整质检模型和数据授权范围。

常见安全错误码：

| 错误码 | 含义 | 处理建议 |
| --- | --- | --- |
| `provider_timeout` | 供应商请求超时 | 检查模型延迟和候选模型 |
| `provider_rate_limited` | 供应商限流 | 降低并发，检查账号配额 |
| `provider_gateway_error` | 网关或上游临时故障 | 检查网络与供应商状态 |
| `provider_quota_exhausted` | 当日或账号额度耗尽 | 补充额度或切换模型 |
| `provider_authentication_failed` | 鉴权失败 | 更新 API Key 与 Base URL |
| `provider_circuit_open` | 供应商熔断中 | 等待半开探测或使用候选模型 |
| `structured_output_unsupported` | 结构化输出能力不可用 | readiness 会切换严格 JSON |
| `invalid_model_output` | JSON 或 Schema 无效 | 检查模型兼容性与输出上限 |
| `evidence_retrieval_failed` | 证据检索失败 | 检查数据库、切片和向量服务 |
| `queue_dispatch_failed` | 队列投递失败 | 检查 Redis 与队列连接配置 |
| `queue_worker_unavailable` | 前台消费者失联 | 恢复 `ai-quality` Worker 后人工重检 |
| `queue_wait_timeout` | 消费者存活但排队超时 | 检查并发与队列积压 |
| `worker_interrupted` | 执行进程中断 | 检查 Worker 重启或资源限制 |
| `inspection_deadline_exceeded` | 达到业务截止时间 | 检查队列等待和模型延迟 |
| `inspection_primary_deadline_exceeded` | 全文阶段达到预算 | 已授权任务进入抽样；其他任务可人工重检 |
| `input_too_large` | 正文超过在线全文长度上限 | 开启抽样或拆分文章 |
| `model_output_truncated` | 模型输出被截断 | 已授权任务进入抽样；检查输出预算 |
| `remaining_budget_insufficient` | 全文剩余时间不足以继续分段 | 已授权任务进入抽样 |

## 黄金集评测

默认命令读取合成与脱敏 starter 数据，不调用模型：

```bash
php artisan geoflow:evaluate-ai-quality \
  --dataset=tests/Fixtures/ai-quality/golden-v1.json \
  --output=storage/app/ai-quality-evaluations/golden-v1
```

显式 `--live` 才会调用真实模型并消耗额度：

```bash
php artisan geoflow:evaluate-ai-quality --live --model=MODEL_ID
```

报告包含 decision 混淆矩阵、安全样本误拦截率、重大风险召回率、问题级 Macro F1、Cohen Kappa、模型延迟、输入 Token 和输出 Token 分位数。starter 只有 6 个框架样本，命令会保持 `production_gate_ready=false`。生产切换需要补齐 120 篇校准、60 篇固定回归、60 篇盲测，两人独立标注并由第三人裁决分歧，还要补充端到端延迟和同输入 5 次重复稳定性数据。

## 数据和存储

- 人工“重新 AI 质检”始终创建新的模型审计记录，可命中相同指纹的证据缓存。
- 原始模型输出和单段原始结果最多保存 64 KiB。超限后保存长度、哈希和 Base64 预览，后端已校验的问题与门禁理由完整保留。
- 证据缓存键包含文章、知识版本、主张哈希、生成证据、检索版本和预算，默认有效 24 小时。
- `execution_meta.timings_ms` 记录排队、抽取、检索、提示渲染、模型、校验、评分、持久化和总耗时。
- `usage_meta.total_tokens` 在供应商缺失总量时由输入与输出 Token 相加。
- 质检完成后的发布状态与分发入队记录在 `execution_meta.workflow_apply`。临时失败由每分钟对账补偿，最多执行 3 次；达到上限后状态为 `exhausted`，文章继续保持人工可处理状态。

## 故障排查

### 页面持续显示“质检中”

1. 使用状态 API 或 CLI 查看 `elapsed_ms`、`current_phase` 和安全错误码。
2. 检查 `ai-quality` Worker、Horizon 进程和 Redis。
3. 检查 `jobs` 与 `failed_jobs` 中的 `ProcessArticleAiQualityJob`。
4. 执行 `php artisan geoflow:ai-quality-health --probe --wait=10 --json`，检查消费者心跳、超时链、积压和前台探针。
5. 确认 Laravel 调度器每 5 秒执行 `geoflow:converge-ai-quality`，每分钟执行健康检查和 `geoflow:reconcile-ai-quality`。
6. 手动执行 `php artisan geoflow:converge-ai-quality --json` 收敛过期状态；历史补算或版本重算再执行 `php artisan geoflow:reconcile-ai-quality --limit=25`。
7. 排队或运行记录到达固定截止时间后会进入失败终态；页面兜底会在截止时间后 5 秒内退出等待。

### 分数较高仍需人工复核

查看 `gate_reasons`，常见原因包括：

- `evidence_coverage_partial` 或 `evidence_coverage_insufficient`。
- `high_materiality_uncertainty`。
- `model_output_truncated`。
- `unresolved_reference`。

v2 将未证实主张作为人工确认项，质量分可保持较高。门禁结论与质量分表达不同风险信号。

### 缓存或生成证据疑似陈旧

更新知识库内容、切片来源哈希或治理状态后，精确缓存键会变化。生成证据只有在内容与来源哈希均匹配时才会复用。可临时设置 `GEOFLOW_AI_QUALITY_EVIDENCE_CACHE_ENABLED=false` 验证实时检索链路。

## 回滚

优先使用持久化灰度回滚，保留审计数据。每条轨道只能回退到低于当前阶段的有效阶段：

```bash
php artisan geoflow:ai-quality-rollout rollback --track=scoring --to=0
php artisan geoflow:ai-quality-rollout rollback --track=shadow --to=0
php artisan geoflow:ai-quality-rollout rollback --track=execution --to=0
php artisan geoflow:ai-quality-rollout rollback --track=principles --to=0
php artisan geoflow:ai-quality-rollout sample-off
```

环境配置继续作为数据库迁移前的安全回退和全局硬停开关：

```dotenv
GEOFLOW_AI_QUALITY_PRINCIPLE_V2_PERCENT=0
GEOFLOW_AI_QUALITY_EXECUTION_VERSION=legacy
GEOFLOW_AI_QUALITY_FAST_V2_PERCENT=0
GEOFLOW_AI_QUALITY_SCORING_V2_PERCENT=0
GEOFLOW_AI_QUALITY_SHADOW_V2_PERCENT=0
GEOFLOW_AI_QUALITY_EVIDENCE_CACHE_ENABLED=false
GEOFLOW_AI_QUALITY_SAMPLED_AUTO_RELEASE_ENABLED=false
```

刷新配置并重启 Worker。新旧队列在回滚窗口继续监听，等待现有 Job 排空。数据库迁移回滚会删除新增评分字段和生成证据快照，执行前必须完成数据库备份。历史主质检记录继续保留，影子记录不会参与发布门禁。
