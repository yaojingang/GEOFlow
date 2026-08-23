# GEOFlow AI 工作台运行手册

## 目标与边界

AI 工作台把自然语言请求编译为可审计的 GEOFlow 工作流。职责边界如下：

- `IntentResolverAgent` 识别意图、候选能力、已知参数、缺失参数和置信信号。
- `GeoHubPlanDrafterAgent` 生成多步计划草案，`tools()` 始终为空。
- `AiPlanCompiler` 校验能力、管理员权限、参数 Schema、风险、对象快照、依赖和能力版本。
- `AiWorkflowEngine` 管理审批、步骤租约、目标复核、幂等、执行、取消和终态。
- `AiCapabilityExecutor` 调度已登记的查询处理器；任务、文章和知识草稿由独立 capability handler 调用共享领域服务。
- 普通问答由 `GeoHubAgent` 完成，并明确展示“本次未执行系统操作”。

模型没有领域工具调用权限。系统读写只能经过编译后的 `AiWorkflowPlan`。

## 运行条件

生产环境需要 PostgreSQL、Redis、队列 Worker 和至少一个通过结构化输出检测的启用模型。Reverb 可选；前端在连接不可用、事件丢失或序号不连续时读取 Run 权威快照。

关键环境变量：

```dotenv
GEOFLOW_AI_WORKSPACE_RUNTIME_ENABLED=false
GEOFLOW_AI_WORKSPACE_RETENTION_DAYS=90
GEOFLOW_AI_WORKSPACE_GLOBAL_CONCURRENCY=10
GEOFLOW_AI_WORKSPACE_CONCURRENCY_CACHE_STORE=redis
GEOFLOW_AI_WORKSPACE_ADMIN_DAILY_MODEL_CALLS=200
GEOFLOW_AI_WORKSPACE_HISTORY_CHAR_BUDGET=24000
GEOFLOW_AI_WORKSPACE_MAX_ACTIVE_RUNS_PER_ADMIN=3
GEOFLOW_AI_WORKSPACE_MAX_PLAN_STEPS=100
GEOFLOW_AI_WORKSPACE_STEP_LEASE_MINUTES=20
GEOFLOW_AI_WORKSPACE_RESOLUTION_LEASE_MINUTES=3
GEOFLOW_AI_WORKSPACE_APPROVAL_TTL_MINUTES=30
GEOFLOW_AI_WORKSPACE_QUEUE=ai-workspace
GEOFLOW_AI_WORKSPACE_INTERACTIVE_QUEUE=ai-workspace-interactive
```

运行时开关默认关闭。关闭时历史会话、Run 快照和取消接口继续可用；新会话、消息、审批、计划修改和重试返回 503。

## 状态与恢复

运行状态主链：

```text
received
  -> clarifying / answering / planning
  -> validating_plan
  -> awaiting_approval / queued
  -> running / awaiting_step_approval
  -> completed / partially_completed / failed
  -> cancelled / outcome_unknown / rejected
```

两条独立队列承担不同负载：

- `ai-workspace-interactive`：意图解析、追问、普通回答和计划草案。
- `ai-workspace`：已批准的领域工作流。

`geoflow:recover-ai-workspace` 每五分钟恢复以下中断：

- 超时的 `received`、`planning`、`answering` 解析租约；
- 两分钟未消费的 `queued` Run；
- 没有活动步骤的陈旧 `running` Run；
- 租约过期的内部步骤；
- 租约过期的外部写步骤进入 `outcome_unknown`，等待人工对账。

解析租约和步骤租约均使用唯一 fencing token。状态迁移、异常落库、Artifact 写入和 Job 失败回调都会复核 token，陈旧 Worker 无法覆盖恢复后的运行。恢复命令在运行时关闭期间继续执行：未发出的步骤安全终止，已确认外部结果继续记账，已发出且无法确认的结果进入 `outcome_unknown`。任意仍处于 `running` 的步骤会保留租约，Run 进入 `cancel_requested` 等待 Worker 收口或租约过期；这条规则同时覆盖 `external_read` 和内部步骤。

计划步骤以 `depends_on` 持久化为 DAG。`input_bindings` 可以把前序 Artifact 的已声明 `payload_schema` 字段绑定到后序参数；绑定完成后重新计算参数、目标和计划摘要，需要审批的步骤会在当前参数确定后暂停确认。某个分支失败时，其依赖分支标记为 `skipped`，其余独立分支继续执行，最终聚合为部分完成。

## 审批与目标完整性

审批有效期默认 30 分钟，并绑定以下摘要：

- 计划版本和计划摘要；
- 全部能力版本；
- 规范化参数摘要；
- 目标对象及其修订快照摘要。

`once` 和 `target_matrix` 按能力合并审批；`per_step` 在每个状态变更步骤前单独审批。任一策略在长任务中到期后都会暂停并生成续批项，已经完成的步骤保持不变。参数、对象内容、渠道配置、任务配置或能力版本发生变化后，执行前复核会拒绝旧计划。

计划修改会保留旧审批摘要，并写入 `plan_revision` Artifact。历史审批进入 `expired`，用于审计和防重放。

## 能力治理

能力目录位于 `config/ai-workspace.php`。每项能力必须声明：

- 输入 Schema、结果契约和 handler；
- 成熟度、风险、执行范围和数据分类；
- 权限、成本、审批策略和版本；
- 对应后台命名路由。

新增或调整能力契约时必须提升能力版本。新增后台命名路由需要登记到具体能力，或加入明确的基础设施排除清单。架构测试冻结路由名、HTTP 方法和最终能力归属的精确摘要，通配能力只承担运行时兜底。`managed.operations` 和 `admin.governance` 将尚未开放的写操作归为受限能力。

首批工作流覆盖运营日报、运营周报、AI 可见性诊断、内容机会分析、任务草稿、文章草稿、知识草稿、URL 导入预览与提交、分发预览与入队、任务启停、站点设置同步和托管站点预检。

多站分发的工作台步骤完成含义为“分发记录已进入现有分发中心”。远程发送、重试和最终状态继续由分发中心记录与对账。

每条 AI 分发记录持久化审批时的渠道修订摘要和不可变文章载荷。渠道修订摘要覆盖域名、端点、类型、配置、站点设置和当前凭据版本。分发 Worker 在目标操作租约内、调用 Publisher 前复核运行时、管理员授权、能力版本、审批期限和全部摘要。管理员在活动渠道租约期间修改设置、状态或凭据时，页面会要求等待当前操作完成。

AI 发起的 WordPress 新建发布会附带稳定幂等键。连接中断或超时后，分发中心先通过已审批的文章 slug 查询远程记录；对账成功时记录 `synced`，无法确认时记录 `outcome_unknown` 并停止自动重试。分发中心会显示“需要人工对账”，当前状态不允许直接重新入队。

站点设置同步在远程请求前写入 `ai_workspace_external_operations` 账本，并把稳定 execution key 传给目标站幂等头。账本记录请求摘要、目标摘要、发出时间和确认结果。超时恢复优先读取已确认账本；仅有已发出记录时进入 `outcome_unknown`，等待人工对账。

## 模型开放流程

1. 配置一个启用的聊天模型及 API Key。
2. 由超级管理员执行模型绑定检测。检测使用真实意图解析 Schema。
3. 确认模型的 `ai_workspace_structured_output_status` 为 `ready`。
4. 运行意图评测和聚焦回归测试。
5. 先对超级管理员开启运行时，再逐步开放普通管理员和能力族。

普通管理员的模型连通性检测不会修改全局就绪状态。模型 ID、类型、地址、密钥或启用状态变化会自动清除就绪状态，并要求重新检测。

## 日常运维

```bash
php artisan route:list --path=admin/ai-workspace -v
php artisan geoflow:recover-ai-workspace
php artisan geoflow:prune-ai-workspace --days=90
php artisan horizon:status
```

关注指标包括请求量、完成率、部分完成率、失败分类、`outcome_unknown` 数量、平均首次状态时间、平均执行时间、模型额度和两个队列的等待时间。

完整载荷保留 90 天。清理任务只处理曾创建 AI Workspace Run 的会话；同表中的其他 Laravel AI 会话保持原样。Run 的计划摘要、能力版本、审批摘要和管理员快照继续保留。

## 发布与回滚

发布顺序：

1. 发布数据库、后端、队列和前端代码，保持运行时关闭。
2. 启动两个 Horizon supervisor，并确认恢复与清理计划任务正常。
3. 完成模型检测、意图基线和自动化回归。
4. 开启运行时并按管理员与能力族灰度。

Laravel AI 会话表名读取 `ai.conversations.tables` 配置。AI 会话和工作台业务表需要使用同一数据库连接；就绪检测会拦截跨连接配置。

回滚时将 `GEOFLOW_AI_WORKSPACE_RUNTIME_ENABLED` 设为 `false` 并刷新配置缓存。历史记录与取消入口继续可用，Worker 会协作式停止后续步骤。已经创建的草稿、已经进入分发中心的记录和已经发生的远程结果全部保留。

## 验证命令

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/AiWorkspace
php artisan test --compact tests/Feature/AdminAiWorkspaceTest.php
php artisan test --compact tests/Feature/AiWorkspaceWorkflowTest.php
node --test tests/JavaScript/ai-workspace.test.js
npm run build
```

涉及阶段五能力或发布前，运行完整 PHP、JavaScript 和浏览器回归。
