# 管理员 AI API 配置归属与共享机制最终迭代设计方案

日期：2026-09-01

状态：待确认，尚未开始实施

适用范围：GEOFlow 后台管理员、AI 配置器、内容生成与优化、任务队列、AI Workspace、知识检索、系统采集配置、API Token、审计与用量归因

## 1. 最终结论

本轮建立管理员级 AI API 配置归属和运行时访问边界。每个 AI 模型配置拥有明确所有者，普通管理员可以管理自己的配置；超级管理员可以把自己的用户内容模型池共享给指定普通管理员。共享只授予运行时调用权，不授予密钥查看、复制、编辑、删除和连接测试权限。

本方案固定以下三项已确认产品规则：

1. 开启共享时采用“个人模型优先，共享模型兜底”。
2. 历史普通管理员升级后默认保持共享，新建普通管理员默认独立。
3. AI 搜索源等系统采集配置本轮收口为超级管理员专属。

关闭共享后，普通管理员的内容生成、标题生成、文章助手、AI 优化、AI 质检、知识事实生成、AI Workspace 等用户内容 AI 调用，只能使用本人有权调用的模型。系统找不到可用个人模型时返回明确错误，不会进入任意全局模型池，也不会使用其他普通管理员或超级管理员的凭据。

系统采集与用户内容调用采用独立边界：

- AI 搜索源、采集供应商、系统采集模型绑定、全局知识切片策略和索引构建配置由超级管理员管理。
- 普通管理员可以读取已经形成且原本有权查看的内容资产和检索结果。
- 普通管理员的交互请求不会直接测试、修改或调用系统采集供应商凭据。
- 用户内容检索需要实时向量查询时，优先使用该管理员可调用且与索引兼容的 Embedding 模型；没有兼容模型时降级为关键词检索，并显示能力降级原因。
- 系统定时采集、索引构建和批量同步使用显式 `system` 执行身份，独立记录审计和用量，不计入普通管理员的个人模型池。

预计实施规模为 12 至 16 人日，另建议安排 2 至 3 个自然日灰度观察。整体风险为中高，主要风险集中在历史数据归属、异步任务执行身份、共享撤销竞态和现有全局模型查询的完整替换。

## 2. Review 后补齐的关键设计

初版方向成立，本轮 Review 补齐了以下内容：

- 将模型所有权、运行时使用权、配置管理权、密钥可见性拆成四个独立概念。
- 用明确的共享提供方管理员 ID 表达“共享当前超级管理员配置”，避免多超级管理员场景随机选择第一条记录。
- 增加统一访问解析器和 Policy，覆盖 Web、API、CLI、队列、重试、恢复与定时任务。
- 明确显式选模、自动选模和智能故障切换的不同语义。
- 为任务和运行记录持久化模型访问身份，队列不依赖登录会话。
- 增加访问版本号和调用前复核，关闭共享或停用账号后可以阻止尚未发起的外部调用。
- 增加结果落库前复核，撤销期间已经返回的外部结果不会继续写入或触发后续任务。
- 增加模型调用用量账本，超级管理员可以识别共享模型被谁、在什么业务中使用。
- 明确普通管理员删除、停用、角色变更以及超级管理员失效时的依赖处理。
- 明确 AI 搜索源、系统采集与用户内容 AI 的边界。
- 增加历史回填预检、多超级管理员裁决、灰度开关和安全回滚条件。
- 将现有 35 个直接访问 `AiModel` 的应用文件纳入强制改造清单，防止只改配置页面后仍可从其他链路越权调用。

## 3. 当前架构基线与缺口

以下事实基于 2026-09-01 代码快照。行号用于实施定位，后续提交可能发生变化。

| 现状 | 代码位置 | 影响 |
| --- | --- | --- |
| `ai_models` 没有所有者字段 | `app/Models/AiModel.php` | 无法区分超级管理员、普通管理员和系统配置 |
| 管理员没有共享提供方与访问版本 | `app/Models/Admin.php` | 无法表达“共享哪位超级管理员”，也无法可靠撤销队列访问 |
| 模型控制器按全局 ID 读取、修改、测试和删除 | `app/Http/Controllers/Admin/AiModelController.php` | 隐藏界面选项后仍可能通过直接提交 ID 越权 |
| AI 模型目录返回全局活动模型 | `app/Services/GeoFlow/CatalogGeoFlowService.php` | API Token 可能看到和选择无权使用的模型 |
| `tasks` 和 `task_runs` 没有稳定的模型访问身份 | `app/Models/Task.php`、`app/Models/TaskRun.php` | 队列离开登录请求后无法确定个人与共享模型池 |
| Worker 智能切换从全局活动模型池选取 | `app/Services/GeoFlow/WorkerExecutionService.php` | 可能跨管理员消耗私有密钥与额度 |
| AI Workspace 的管理员 ID 当前主要用于限流和预算 | `app/Services/AiWorkspace/AiWorkspaceModelRuntime.php` | 就绪状态与真实模型访问权可能不一致 |
| 知识切片与 Embedding 使用全局默认和全局兜底 | `app/Services/GeoFlow/KnowledgeChunkSyncService.php` | 系统采集、用户查询和个人配置边界混合 |
| AI 搜索源路由只要求后台登录 | `routes/web.php` 的 `admin.ai-source-providers.*` | 普通管理员当前可以读取、修改、删除和测试全局供应商 |
| 全局设置保存在唯一键 `site_settings` | `SiteSetting` 与 `SiteSettingsBag` | 不适合存放个人默认模型或共享偏好 |
| 管理员删除采用硬删除且只处理少量关联 | `app/Http/Controllers/Admin/AdminUserController.php` | 新增模型所有权后可能出现悬空私钥、意外级联或删除失败 |
| 活动日志已有敏感字段递归脱敏 | `app/Support/AdminActivityLogger.php` | 可以继续复用，并补齐运行时访问审计 |
| 队列连接的 `after_commit` 当前为 `false` | `config/queue.php` | 共享变更后的对账任务必须显式 `afterCommit()` |

代码扫描发现 35 个应用文件包含直接 `AiModel` 查询，共 83 处调用。重点分布在：

- 后台控制器：模型配置、任务、文章、文章助手、AI 优化、知识库、知识事实、标题库、素材和仪表盘。
- API：文章接口与模型目录。
- GEOFlow 服务：任务执行、智能故障切换、AI 质检、AI 优化、标题生成、知识事实、企业知识、URL 导入和目录服务。
- AI Workspace：模型就绪检查和运行时选模。
- 系统能力：知识切片、Embedding、站点主题复制和命令行评测。

当前强制盘点清单：

```text
app/Console/Commands/EvaluateArticleAiQualityCommand.php
app/Http/Controllers/Admin/AiModelController.php
app/Http/Controllers/Admin/AiSourceProviderController.php
app/Http/Controllers/Admin/ArticleAiOptimizationController.php
app/Http/Controllers/Admin/ArticleController.php
app/Http/Controllers/Admin/ArticleEditorAssistantController.php
app/Http/Controllers/Admin/DashboardController.php
app/Http/Controllers/Admin/KnowledgeBaseController.php
app/Http/Controllers/Admin/KnowledgeFactController.php
app/Http/Controllers/Admin/KnowledgeFactGenerationController.php
app/Http/Controllers/Admin/LegacyController.php
app/Http/Controllers/Admin/MaterialsController.php
app/Http/Controllers/Admin/TaskController.php
app/Http/Controllers/Admin/TitleLibraryController.php
app/Http/Controllers/Api/V1/ArticleController.php
app/Services/Admin/Analytics/AnalyticsOverviewService.php
app/Services/Admin/SiteThemeReplicationService.php
app/Services/AiWorkspace/AiWorkspaceModelReadiness.php
app/Services/AiWorkspace/AiWorkspaceModelRuntime.php
app/Services/GeoFlow/AiUsageQuotaService.php
app/Services/GeoFlow/AiVisibility/AiVisibilityConfigurationResolver.php
app/Services/GeoFlow/ArticleAiOptimizationCoordinator.php
app/Services/GeoFlow/ArticleAiOptimizationReconciliationService.php
app/Services/GeoFlow/ArticleAiQualityInspectionService.php
app/Services/GeoFlow/ArticleAiQualityPolicyResolver.php
app/Services/GeoFlow/ArticleAiQualityReadinessRecorder.php
app/Services/GeoFlow/CatalogGeoFlowService.php
app/Services/GeoFlow/EnterpriseKnowledgeDraftService.php
app/Services/GeoFlow/KnowledgeChunkSyncService.php
app/Services/GeoFlow/KnowledgeFacts/KnowledgeFactAiGenerator.php
app/Services/GeoFlow/TaskLifecycleService.php
app/Services/GeoFlow/TitleAiGenerationService.php
app/Services/GeoFlow/TitleGenerationCoordinator.php
app/Services/GeoFlow/UrlImportProcessingService.php
app/Services/GeoFlow/WorkerExecutionService.php
```

实施验收要求所有业务选模入口接入统一解析器。保留的直接查询只能用于超级管理员治理清单、数据迁移、审计报表或显式系统任务，并在代码中标记用途。

## 4. 开源项目参考与采用原则

本轮参考以下项目的官方文档和官方仓库：

| 项目 | 可借鉴机制 | 本方案采用方式 |
| --- | --- | --- |
| n8n | 凭据所有者可以共享使用权，接收方可以运行工作流，但看不到凭据详情 | 共享模型只暴露脱敏元数据和调用能力，接收方不能编辑、删除、测试或查看密钥 |
| Langfuse | 每条记录携带项目上下文，服务端在所有入口校验项目权限，队列保留项目上下文 | 每次 AI 执行显式携带管理员访问身份、访问版本和策略版本 |
| LiteLLM | Virtual Key 绑定所有者、模型许可范围和用量统计 | 模型绑定所有者，调用事件记录配置所有者和实际执行管理员 |
| Open WebUI | 资源、主体和权限分离，并提供有效权限预览 | 界面展示“我的模型”“共享模型”和有效访问预览，管理权与使用权分别校验 |
| Dify | 模型供应商凭据按租户隔离，控制器从当前租户上下文解析配置 | GEOFlow 从当前管理员或持久化任务身份解析个人与共享候选池 |

官方参考：

- [n8n 凭据安全共享](https://docs.n8n.io/administer/manage-credentials/share-credentials-securely/)
- [n8n 工作流共享](https://docs.n8n.io/workflows/sharing/)
- [Langfuse 数据隔离](https://langfuse.com/security/data-isolation)
- [Langfuse RBAC](https://langfuse.com/docs/administration/rbac)
- [Langfuse 审计日志](https://langfuse.com/docs/administration/audit-logs)
- [LiteLLM Virtual Keys](https://docs.litellm.ai/docs/proxy/virtual_keys)
- [Open WebUI 群组与 RBAC](https://docs.openwebui.com/features/authentication-access/rbac/groups/)
- [Open WebUI 安全加固](https://docs.openwebui.com/getting-started/advanced-topics/hardening/)
- [Dify Provider 数据模型](https://github.com/langgenius/dify/blob/main/api/models/provider.py)
- [Dify 模型供应商控制器](https://github.com/langgenius/dify/blob/main/api/controllers/console/workspace/model_providers.py)

采用这些机制时保持 GEOFlow 当前单后台、两级管理员的产品复杂度。本轮使用“普通管理员绑定一位共享提供方”模型。按单模型授权、管理员组授权和跨组织授权可以在未来演进为标准授权表，本轮不提前引入。

## 5. 核心领域定义

### 5.1 四种权限

| 权限 | 含义 | 普通管理员自己的模型 | 共享的超级管理员模型 | 其他普通管理员模型 |
| --- | --- | --- | --- | --- |
| 查看元数据 | 查看名称、类型、状态、能力和脱敏配置状态 | 允许 | 允许，字段受限 | 禁止 |
| 运行时使用 | 发起模型调用 | 允许 | 仅开启共享时允许 | 禁止 |
| 配置管理 | 创建、编辑、停用、删除和设置优先级 | 允许 | 禁止 | 禁止 |
| 密钥可见 | 读取 API Key 明文 | 保存后任何角色均不可回显 | 禁止 | 禁止 |

超级管理员的治理权限单独定义：

- 可以查看普通管理员模型的非敏感清单、状态、依赖数量和用量汇总。
- 可以因安全、合规或账号处置停用、归档普通管理员模型。
- 不能在自己的内容生成中自动使用普通管理员模型。
- 不能读取普通管理员 API Key 明文，也不能以普通管理员身份执行连接测试。
- 所有权转移需要显式操作、事务处理和审计记录，禁止通过删除管理员产生隐式转移。

### 5.2 三类执行身份

| 执行身份 | 来源 | 可用模型范围 |
| --- | --- | --- |
| `interactive_admin` | 当前 Web 或 API Token 对应的活动管理员 | 本人模型，加上已授权共享模型 |
| `persisted_admin` | 任务、运行记录或业务流程保存的模型访问管理员 | 与该管理员当前有效权限一致 |
| `system` | 超级管理员专属的采集、索引和运维任务 | 显式绑定的系统模型与供应商 |

队列进程不得从 `auth()` 推断执行身份。任何缺少执行身份的 AI 任务都应拒绝运行并记录稳定错误码。

### 5.3 共享关系

共享关系保存为普通管理员到超级管理员的单向绑定：

```text
ordinary_admin.shared_ai_config_owner_id -> active_super_admin.id
```

- 值为空表示独立模式。
- 有值表示可以使用该超级管理员可共享的用户内容模型。
- 绑定失效、提供方停用或角色不再是超级管理员时，共享立即失效。
- 超级管理员自身不需要共享绑定，其用户内容模型池始终只包含本人模型。
- 禁止运行时使用 `Admin::whereRole(...)->first()` 推断“当前超级管理员”。

## 6. 模型解析与故障切换规则

### 6.1 统一解析器

新增统一服务，建议命名为 `AdminAiModelAccessResolver`，职责包括：

- `manageableBy(Admin $actor)`：返回可管理模型。
- `visibleTo(Admin $actor)`：返回可以展示的脱敏模型元数据。
- `assertUsable(Admin $actor, AiModel $model, AiCapability $capability)`：校验显式模型选择。
- `resolveCandidates(AiExecutionContext $context)`：返回有序候选池。
- `resolveCompatibleEmbeddingCandidates(...)`：返回与索引指纹兼容的 Embedding 候选池。
- `sanitizedFor(Admin $actor, AiModel $model)`：生成共享视图 DTO。

新增 `AiModelPolicy` 负责查看、修改、测试、停用、归档和删除授权。Form Request 的 `authorize()` 与模型 ID 校验必须调用同一授权规则，禁止继续使用全局 `exists:ai_models,id` 作为充分条件。

不使用依赖当前登录态的全局 Eloquent Scope。Web、API、CLI、队列和系统任务需要显式传入不同上下文，全局 Scope 容易产生遗漏或错误放行。

### 6.2 自动选模顺序

普通管理员开启共享后，自动候选池顺序固定为：

```text
个人可执行模型池
  -> 共享提供方可执行模型池
  -> 明确失败
```

每个池内按以下稳定顺序排列：

1. 业务显式优先级。
2. 当前健康状态。
3. 可用额度与并发状态。
4. 稳定 ID。

候选必须同时满足：

- 模型所有者有效。
- 模型处于启用状态。
- API Key 已配置且解密成功。
- 模型类型和请求能力匹配。
- 当前管理员拥有运行时使用权。
- 配额、限流和健康门禁允许执行。

共享关闭时不构建共享候选池。任何路径都禁止进入其他普通管理员模型池。

### 6.3 显式选模

- 用户显式选择模型时，该模型保持主模型语义。
- 提交时校验该模型属于本人，或属于当前绑定的共享提供方。
- 越权资源 ID 对普通管理员返回 404，降低资源枚举风险。
- 模型在调用前已经停用、撤销或不兼容时返回稳定错误，不做静默替换。
- 业务已明确开启“智能故障切换”时，可以在外部调用发生可切换故障后进入剩余候选池，顺序仍为个人池优先、共享池随后。

### 6.4 可切换故障

允许进入下一个候选的故障：

- 连接超时和临时网络错误。
- 供应商 429 限流。
- 可恢复的供应商 5xx 错误。
- 当前模型短时健康门禁打开。

直接结束且禁止故障切换的错误：

- 当前管理员、共享提供方或模型访问权失效。
- 401、403 等密钥或供应商权限配置错误。
- 请求参数、内容校验和模型能力不兼容。
- 密钥解密失败。
- 系统检测到跨管理员模型 ID。
- 业务幂等、预算或内容安全门禁拒绝。

这样可以避免用共享模型掩盖个人密钥配置问题，也能减少共享额度被无意消耗。

## 7. 数据模型设计

### 7.1 `admins`

新增字段：

| 字段 | 类型 | 规则 |
| --- | --- | --- |
| `shared_ai_config_owner_id` | nullable foreign key | 指向活动超级管理员；为空表示独立模式；删除受限制 |
| `ai_config_access_version` | unsigned bigint，默认 1 | 共享、角色、状态等访问边界变化时原子递增 |

数据库默认和模型 `$attributes` 保持一致。新建普通管理员明确写入 `shared_ai_config_owner_id = null`。

### 7.2 `ai_models`

新增字段：

| 字段 | 类型 | 规则 |
| --- | --- | --- |
| `owner_admin_id` | foreign key，收口后非空 | 明确配置所有者，使用限制删除 |
| `access_scope` | enum 或受约束字符串 | `user_content` 或 `system_only` |
| `failover_priority` | unsigned integer | 同一所有者、模型类型内稳定排序 |
| `archived_at` | nullable timestamp | 支持安全归档，减少直接硬删除 |

建议索引：

```text
(owner_admin_id, access_scope, status, model_type, failover_priority, id)
```

`created_by` 继续表达创建审计，不承担所有权。API Key 继续复用当前 `ApiKeyCrypto` 加密契约并保持 `$hidden`。实施时评估迁移为 Laravel encrypted cast 的兼容成本，不能在同一发布中无验证地混用两套密文格式。

### 7.3 `admin_ai_settings`

新增一对一设置表，避免把个人偏好写入全局 `site_settings`：

| 字段 | 用途 |
| --- | --- |
| `admin_id` | 唯一管理员 ID |
| `default_chat_model_id` | 个人默认聊天模型，可引用本人或当前共享模型 |
| `default_embedding_model_id` | 个人实时检索默认 Embedding 模型，可引用本人或当前共享模型 |
| `updated_by_admin_id` | 审计 |

共享关闭、提供方失效或模型停用时，引用共享模型的个人默认项需要清空并写入审计。系统采集默认模型、全局知识切片策略和供应商绑定继续保存在系统设置中，并限定超级管理员管理。

### 7.4 `tasks` 与 `task_runs`

任务仍然可以是现有全局业务对象，因此字段使用“模型访问身份”命名，避免与内容数据权限混淆。

`tasks` 新增：

| 字段 | 用途 |
| --- | --- |
| `model_access_admin_id` | 定时、重试和续跑使用的稳定管理员身份 |
| `model_access_policy_version` | 保存任务时的解析策略版本 |

`task_runs` 新增：

| 字段 | 用途 |
| --- | --- |
| `model_access_admin_id` | 排队时从任务复制的执行管理员 |
| `ai_config_access_version` | 排队时访问版本快照 |
| `requested_ai_model_id` | 用户或任务显式指定的模型，可空 |
| `resolved_ai_model_id` | 首次实际解析出的模型，可空 |
| `resolved_model_source` | `personal`、`shared` 或 `system` |
| `model_resolved_at` | 实际解析时间 |
| `resolver_policy_version` | 解析规则版本 |

一个运行可能发生多次 AI 调用和故障切换，实际每次调用由用量账本记录。`task_runs` 保存首个选模结果和总体身份，避免把多次调用压缩成一个不准确的最终模型字段。

### 7.5 其他异步运行

优先复用现有身份字段：

- `TitleGenerationRun.created_by_admin_id`
- `ArticleAiOptimizationRun.requested_by_admin_id`
- `KnowledgeFactGenerationRun.created_by_admin_id`
- `AiWorkspaceRun.admin_id`

缺少稳定 ID 的运行记录需要补充 `model_access_admin_id`。仅保存用户名的 URL 导入任务需要增加管理员外键。所有后续子任务继承父运行的执行身份和访问版本。

### 7.6 `ai_model_usage_events`

新增最小用量与审计账本：

| 字段 | 用途 |
| --- | --- |
| `ai_model_id` | 实际调用模型 |
| `config_owner_admin_id` | 配置所有者 |
| `execution_admin_id` | 业务执行管理员，可空，仅 system 任务为空 |
| `execution_scope` | `interactive_admin`、`persisted_admin` 或 `system` |
| `model_source` | `personal`、`shared` 或 `system` |
| `source_type`、`source_id` | 任务、文章、运行或其他业务来源 |
| `operation` | chat、embedding、quality、optimization 等 |
| `request_id` | 幂等与链路追踪 |
| `status`、`error_code` | 成功或稳定错误 |
| `input_tokens`、`output_tokens`、`estimated_cost` | 可空用量字段 |
| `created_at` | 调用时间 |

账本不得保存 API Key、API Base URL、完整 Prompt、原始正文和供应商响应正文。现有 `used_today`、`total_used` 和 `daily_limit` 继续作为模型所有者聚合额度，本轮不引入普通管理员独立计费上限。

## 8. 系统采集与用户内容边界

### 8.1 超级管理员专属配置

以下能力全部增加 `admin.super` 路由门禁、Controller 或 Service 二次授权、导航隐藏和直接 URL 测试：

- AI 搜索源列表、创建、编辑、删除。
- 搜索源 API Key、Endpoint 和启停状态。
- 搜索源连接测试。
- 搜索源与模型绑定。
- 系统采集模型绑定。
- 全局默认 Embedding 模型。
- 全局知识切片策略与语义切片模型。
- 系统索引构建和批量采集配置。

普通管理员仍可管理自己的 `user_content` 模型，包括个人 Chat 和 Embedding 配置。`system_only` 模型不进入普通管理员的个人或共享候选池。

### 8.2 普通管理员可使用的已有资产

- 对已有内容资产、知识库、切片和采集结果的读取权限继续沿用现有业务授权。
- 读取已有资产不产生供应商调用。
- 普通管理员无法直接触发搜索源连通性测试、供应商采集或系统模型绑定变更。
- 普通管理员保存知识内容后可以形成待同步状态；真正的系统索引任务由 `system` 身份执行并记录独立审计。
- 普通管理员发起内容生成时，实时 Chat 和 Embedding 调用仍按本人或共享模型解析。

### 8.3 向量兼容规则

系统索引保存 Embedding 兼容指纹，至少包含供应商协议、模型标识、向量维度和规范化版本。实时查询时：

1. 从当前管理员个人可用 Embedding 模型中寻找兼容指纹。
2. 共享开启时，再从共享提供方模型中寻找兼容指纹。
3. 找不到兼容模型时执行关键词检索。
4. 界面和运行元数据记录 `vector_disabled_no_compatible_model`，方便管理员补充个人配置或开启共享。

任何不兼容向量都不能进入相似度计算，避免维度错误和无意义召回。

## 9. 队列、撤销与并发规则

### 9.1 执行上下文

新增不可变 `AiExecutionContext` 值对象，至少包含：

```text
execution_scope
model_access_admin_id
ai_config_access_version
requested_model_id
required_capability
source_type
source_id
resolver_policy_version
request_id
```

上下文只保存 ID、枚举和版本，不保存密钥、Endpoint 和 Prompt。队列收到上下文后，在真正调用供应商前从数据库重新解析配置。

### 9.2 两次权限复核

每个异步 AI 调用执行两次复核：

1. 外部调用前检查管理员状态、角色、访问版本、共享提供方、模型状态、所有权和配额。
2. 结果落库或触发后续任务前再次检查访问版本和运行状态。

第二次复核失败时，外部结果丢弃，运行标记为已撤销，后续任务不再派发。供应商可能已经计费，因此用量账本仍记录实际请求及撤销结果。

### 9.3 关闭共享

关闭共享采用事务化流程：

1. 锁定普通管理员记录。
2. 清空 `shared_ai_config_owner_id`。
3. 原子递增 `ai_config_access_version`。
4. 清空引用共享模型的个人默认项。
5. 标记依赖共享模型的待执行任务和运行。
6. 提交事务。
7. 使用 `afterCommit()` 派发幂等对账任务。

对账结果：

- 尚未发起外部请求的运行，以 `ai_config_access_revoked` 永久失败，不进入重试。
- 已发起外部请求的运行允许供应商返回，结果落库前复核失败后丢弃。
- 定时任务保持原配置但进入暂停状态，提示管理员选择个人模型后恢复。
- 共享模型 ID 已保存到业务配置时，界面显示失效原因和修复入口。

### 9.4 并发保护

- 共享切换、账号停用、角色变更和删除使用数据库事务与行锁。
- Worker 领取运行和访问对账使用稳定锁顺序，降低死锁概率。
- 同一运行的对账任务具备幂等键。
- 配额预占、模型切换和用量写入使用原子更新，防止重复计费。
- 共享撤销类授权异常配置为永久失败，队列不重复重试。
- 当前队列 `after_commit=false`，所有新增的事务后派发必须显式调用 `afterCommit()`。

## 10. 管理员与模型生命周期

### 10.1 停用普通管理员

- 立即撤销 Web 会话与 API Token，沿用现有认证版本机制。
- 递增 AI 配置访问版本。
- 本人模型不再进入任何新候选池。
- 尚未发起外部请求的个人运行永久停止。
- 定时任务暂停，保留修复所需的配置元数据。
- 已经保存的内容、运行记录和用量审计继续保留。

### 10.2 删除普通管理员

默认禁止直接删除仍拥有以下依赖的管理员：

- 活动或归档中的个人模型。
- 待执行、运行中或可重试的 AI 任务。
- 作为任务模型访问身份的任务。
- 仍引用个人模型的默认项、标题生成、优化、质检或知识事实运行。

删除页面先生成依赖清单。超级管理员完成停用、归档、任务改配或显式所有权转移后才能删除。`owner_admin_id` 使用限制删除，禁止 `nullOnDelete` 和级联删除。私有模型不会因所有者删除自动变为共享或系统模型。

### 10.3 超级管理员停用、降级或删除

- 自删、自停用和最后一名活动超级管理员继续受保护。
- 作为共享提供方的超级管理员发生停用或降级时，其共享关系立即失效并递增所有受影响普通管理员的访问版本。
- 常规操作通过影响确认页选择：取消操作、把共享关系迁移到另一位超级管理员、或撤销共享并暂停依赖任务。
- 紧急停用可以立即生效，对账任务在事务提交后处理依赖。
- 共享关系迁移只改变调用授权，不自动转移模型所有权。

### 10.4 模型停用、归档与删除

- 优先使用停用或归档保留历史引用。
- 停用后禁止新调用，待执行任务按重新解析规则处理。
- 硬删除前检查任务、默认模型、搜索源绑定、知识切片、标题生成、企业知识、知识事实、AI 质检、AI 优化和活动运行。
- 运行历史可以保留空外键和脱敏模型快照，配置所有权外键不能置空。
- 所有权转移需要原所有者或超级管理员发起，目标管理员确认能力兼容，事务完成并记录前后值。

## 11. 管理端产品设计

### 11.1 新建和编辑普通管理员

在用户截图红框区域新增 `AI 配置使用方式`，使用两张单选卡片：

| 选项 | 默认 | 说明 |
| --- | --- | --- |
| 独立配置 | 新建普通管理员默认 | 该管理员只能使用自己配置的 AI 模型；缺少模型时相关 AI 功能不可用 |
| 共享当前超级管理员配置 | 手动选择 | 先使用该管理员自己的模型，个人模型不可用时再使用当前超级管理员的共享模型 |

开启共享时保存正在执行操作的超级管理员 ID。界面显示共享提供方名称和状态，避免只保存一个缺少来源的布尔值。

编辑历史普通管理员时显示迁移后的共享状态。切换到独立配置前展示影响预览：

- 当前引用的共享默认模型。
- 受影响的活动任务和待执行运行数量。
- 预计失效的 AI 功能。
- 需要补充的个人 Chat 或 Embedding 配置。

### 11.2 AI 配置器

普通管理员界面分成两个区域：

1. `我的 AI 配置`：可创建、编辑、测试、停用、归档和删除。
2. `超级管理员共享配置`：只读展示名称、类型、能力、健康状态和可用性，不展示 API Key、完整 Endpoint、请求头或供应商账号信息。

增加 `有效访问预览`：

- 当前模式：独立或共享。
- 当前共享提供方。
- 自动候选顺序。
- 默认模型是否有效。
- 无可用模型时的修复建议。

超级管理员界面增加：

- 自己的用户内容模型。
- 系统专属模型与采集供应商。
- 普通管理员模型治理清单，字段全部脱敏。
- 共享模型使用者和用量汇总。

### 11.3 管理员列表

增加以下只读信息：

- AI 配置模式。
- 共享提供方。
- 个人活动模型数量。
- 共享模型最近使用时间。
- 待修复 AI 任务数量。

### 11.4 错误与空状态

建议稳定错误码：

| 错误码 | 用户含义 |
| --- | --- |
| `ai_model_not_accessible` | 选择的模型不属于当前可用范围 |
| `ai_config_access_revoked` | 任务排队后 AI 配置授权已经变化 |
| `ai_execution_admin_inactive` | 执行管理员已停用或删除 |
| `ai_config_owner_inactive` | 共享提供方当前不可用 |
| `ai_model_unavailable` | 当前范围内没有可执行模型 |
| `ai_embedding_incompatible` | 没有与当前索引兼容的向量模型 |
| `ai_system_config_super_admin_only` | 当前操作属于超级管理员系统配置 |

普通管理员在无模型时看到配置入口和缺失能力说明。系统不得静默使用任意全局模型。

## 12. API、CLI 与安全边界

### 12.1 Web 与 API

- API Token 继续映射到 `created_by_admin_id`，模型目录和文章接口必须传入该管理员身份。
- Catalog 只返回本人模型及允许使用的共享模型，字段使用脱敏 DTO。
- 跨所有者模型 ID 在校验、准备度检查和实际运行三个层级都拒绝。
- 系统采集 API 不向普通管理员 Token 开放。
- 禁止提供绕过模型访问解析器的 OpenAI 兼容透传接口。

### 12.2 CLI 与计划任务

- 需要模型的 CLI 命令增加显式执行身份参数，或使用受控 `system` 身份。
- 未指定身份且无法从业务记录恢复身份时终止执行。
- 评测命令、数据修复命令和调度器不能使用“第一条活动模型”作为默认值。
- 定时任务从 `tasks.model_access_admin_id` 恢复权限。

### 12.3 密钥安全

- API Key 保存后不回显明文，更新时留空表示不修改。
- 共享模型 DTO 不包含 API Key、完整 Endpoint、自定义认证头和供应商账号标识。
- API Key、Token、Secret 禁止进入 Job payload、运行元数据、审计差异、异常文本和失败队列。
- 延续 `AdminActivityLogger` 的递归敏感字段脱敏，并增加大小写、嵌套和数组场景测试。
- 外部请求日志只保存供应商、模型、状态码、耗时、用量和脱敏请求 ID。

## 13. 历史数据迁移方案

### 13.1 迁移原则

采用扩展、回填、收口三阶段迁移：

1. 新增可空字段、索引和兼容代码，不改变现有行为。
2. 运行可重复、可预检的数据回填命令，生成映射报告。
3. 开启访问解析器并观察，确认无未归属记录后再增加非空和外键约束。

结构迁移和数据回填分开，每个迁移保持单一职责。迁移过程中启用维护门禁，防止新建管理员被历史回填误设为共享。

### 13.2 历史超级管理员选择

- 只有一位活动超级管理员时，默认选为 `legacy_ai_config_owner`。
- 存在多位活动超级管理员时，预检命令终止并要求显式传入所有者 ID。
- 没有活动超级管理员时，预检终止。
- 禁止使用数据库返回的第一位超级管理员作为隐式结果。

### 13.3 回填规则

| 历史数据 | 回填结果 |
| --- | --- |
| 历史全局 AI 模型 | 归属 `legacy_ai_config_owner` |
| 历史普通管理员 | `shared_ai_config_owner_id = legacy_ai_config_owner.id` |
| 新建普通管理员 | `shared_ai_config_owner_id = null` |
| 历史超级管理员 | 只使用本人模型，不保存共享绑定 |
| 系统采集绑定模型 | 标记或复核为 `system_only`；同时被用户内容引用时进入人工分类报告 |
| 历史任务 | 优先从可靠审计和创建身份恢复 `model_access_admin_id`，无法可靠恢复时映射到 legacy owner 并输出报告 |
| 待执行和运行中的历史任务 | 能确认身份则保存快照；无法确认时暂停并要求人工恢复 |

历史模型没有可靠创建人字段，迁移不会猜测普通管理员所有权。默认归属 legacy owner 可以保持升级前的全局使用来源。回填命令输出模型、任务、冲突引用和人工分类清单，超级管理员可以在强制收口前进行显式调整。

### 13.4 灰度开关

建议增加以下功能开关：

- `ai_config_ownership_write`：写入新所有权与执行身份。
- `ai_config_access_shadow`：只记录新旧解析差异，不改变实际选模。
- `ai_config_access_enforce`：正式执行个人与共享访问边界。
- `ai_config_revocation_enforce`：启用撤销阻断和结果落库前复核。

Shadow 阶段重点观察：

- 新旧首选模型差异率。
- 找不到执行管理员的运行数量。
- 找不到模型所有者的配置数量。
- 普通管理员将失去的全局模型调用数量。
- 共享模型实际调用者和用量。

### 13.5 回滚约束

一旦普通管理员创建个人模型，旧版本全局查询代码会把这些私有模型暴露给其他管理员。此后禁止直接回滚到不识别所有权的旧代码。

安全回滚方式：

1. 保留所有权字段和统一解析器。
2. 暂停 AI Worker 与外部调用入口。
3. 关闭最新 UI 或撤销对账功能。
4. 使用兼容发布修复问题后恢复执行。

数据库回滚只适用于尚未写入个人模型、尚未开启强制边界的早期阶段。正式灰度后采用向前修复。

## 14. 实施阶段

### 阶段 0：访问面盘点与发布门禁，1 人日

- 固化 35 个直接 `AiModel` 查询文件和 83 处调用清单。
- 给每处调用标记 `user_content`、`system`、`governance` 或 `migration`。
- 增加架构测试，阻止新的业务代码绕过解析器。
- 增加功能开关和 Shadow 指标。

完成门槛：所有现有调用都有明确归类和负责人。

### 阶段 1：所有权、共享关系与兼容迁移，2 至 3 人日

- 新增数据库字段、模型关系、枚举和值对象。
- 实现迁移预检和可重复回填命令。
- 实现 `AdminAiModelAccessResolver`、`AiModelPolicy` 和脱敏 DTO。
- 新写入路径同步保存所有者和执行身份，实际运行保持 Shadow。

完成门槛：模型、任务和运行记录没有未解释的身份缺口。

### 阶段 2：管理端权限与界面，2 人日

- 新建、编辑管理员增加独立或共享配置。
- AI 配置器拆分我的配置和共享配置。
- 模型 CRUD、测试、默认项和直接 ID 路径接入 Policy。
- 管理员列表增加模式、依赖和用量摘要。
- 搜索源和系统采集配置收口为超级管理员专属。

完成门槛：Web 直接 URL、表单伪造和导航权限矩阵全部通过。

### 阶段 3：运行时与队列全链路改造，4 至 5 人日

- 任务、TaskRun、Worker、定时任务和重试链路接入执行上下文。
- 文章助手、标题、AI 优化、AI 质检、知识事实、企业知识、URL 导入和 AI Workspace 接入解析器。
- API Catalog 和 API Token 调用接入管理员范围。
- 系统采集、知识切片、Embedding 与实时检索按边界分流。
- 实现个人优先、共享兜底和可切换错误分类。

完成门槛：用户内容 AI 调用全部带明确执行身份，普通管理员之间无法交叉调用。

### 阶段 4：撤销、生命周期、审计与用量，2 至 3 人日

- 实现访问版本、两次权限复核和幂等对账。
- 完成管理员停用、删除、角色变更和共享提供方失效流程。
- 完成模型归档、依赖检查和显式所有权转移。
- 上线用量事件账本和共享使用汇总。

完成门槛：共享撤销竞态、账号处置和密钥安全测试全部通过。

### 阶段 5：灰度与收口，1 至 2 人日，另加观察期

- 运行 Shadow 对比并处理未归属数据。
- 先对测试管理员开启强制边界，再扩大到全部普通管理员。
- 观察错误率、模型选择差异、共享用量和队列失败。
- 加入非空、限制删除和最终索引约束。
- 更新运维手册和回滚手册。

完成门槛：灰度期无跨管理员调用、无密钥泄漏、无无法解释的全局模型选择。

## 15. 测试与验收矩阵

### 15.1 迁移与默认值

- 历史普通管理员升级后共享开启。
- 新建普通管理员默认独立。
- 新安装、重复回填和分阶段发布结果稳定。
- 单超级管理员自动选择正确。
- 多超级管理员、无活动超级管理员和无效 owner 参数可控失败。
- 历史模型、任务和运行身份回填数量与报告一致。
- 系统模型和用户内容模型冲突引用进入人工清单。
- 强制边界开启后不存在空模型所有者。

### 15.2 解析优先级

- 个人模型可用时不调用共享模型。
- 多个个人模型按稳定顺序完整尝试后才进入共享池。
- 自动选模遇到个人模型停用或额度耗尽时进入共享池。
- 共享关闭后不展示、不接受、不调用共享模型。
- 任何情况下都不进入其他普通管理员模型池。
- 显式选模保持主模型语义。
- 智能故障切换只处理允许切换的临时错误。
- 访问撤销、401、403、校验失败和能力不兼容不触发共享兜底。
- 超级管理员的用户内容调用不进入普通管理员模型池。

### 15.3 Web 与 API 权限

- 普通管理员只管理本人模型。
- 普通管理员可以查看共享模型脱敏信息，不能编辑、删除、测试或读取密钥。
- 普通管理员猜测其他模型 ID 时返回 404。
- API Token Catalog 只返回本人和授权共享模型。
- API 直接提交越权模型 ID 被拒绝。
- 所有搜索源 GET、POST、PUT、DELETE、Test 和 Binding 路由对普通管理员返回 403。
- 普通管理员导航不显示系统采集配置。
- 更新敏感路由注册表，防止后续新增路由漏加超级管理员门禁。

### 15.4 队列与竞态

- Job 保持排队时的模型访问管理员身份。
- 任务编辑后，已经排队的 TaskRun 身份快照不被静默改写。
- 派发后关闭共享，尚未外呼的任务不发起供应商请求。
- 外呼期间关闭共享，结果返回后不写入业务数据。
- 管理员停用、删除、角色变化和认证版本变化均能阻止新调用。
- 模型停用、归档、删除和共享提供方失效处理一致。
- 定时、重试、恢复和续跑采用同一身份规则。
- 对账任务重复执行保持幂等。
- 并发切换共享、领取任务和预占额度时无跨管理员调用和重复计费。
- `retry_after` 始终大于 Job timeout。

### 15.5 内容与系统链路

- 任务生成、标题生成、文章助手、AI 优化、AI 质检和知识事实均使用正确个人或共享模型。
- AI Workspace 就绪状态按当前管理员有效模型计算。
- URL 导入和企业知识任务保留发起管理员身份。
- 系统采集只使用 `system` 身份和显式绑定模型。
- 普通管理员无法直接触发供应商测试或变更系统模型绑定。
- 实时向量查询只使用指纹兼容的个人或共享 Embedding 模型。
- 没有兼容 Embedding 时使用关键词检索并记录稳定降级原因。

### 15.6 生命周期与密钥

- 停用普通管理员后个人模型不再参与解析。
- 删除存在模型或活动任务的管理员时显示依赖并阻止硬删除。
- 私有模型不会因所有者删除变成共享或系统模型。
- 最后一名超级管理员、自删和自停用受到保护。
- 共享提供方停用时所有使用者立即失效并完成对账。
- API Key 不出现在 HTML、JSON、验证异常、403、404、日志、Job payload 和失败队列。
- 更新模型时掩码字符串不会被误存为新密钥。
- 用量账本正确记录配置所有者、执行管理员和 personal/shared/system 来源。

### 15.7 测试技术要求

- 使用 Laravel Feature Test 覆盖 Policy、Form Request、路由和直接 ID 越权。
- 使用 Laravel AI fake 与 HTTP fake 阻止测试发出真实模型请求。
- 对关键测试启用 `preventStrayPrompts()`、`preventStrayEmbeddings()` 或等价防逃逸断言。
- 增加数据库并发和队列重试测试。
- 增加架构测试，扫描业务层新增的直接 `AiModel::query()`、`AiModel::find()` 和全局 `exists` 规则。

## 16. 观测指标与告警

上线后至少监控：

- 按 personal、shared、system 分类的调用量、成功率、P50、P95 和成本。
- 个人池命中率与共享池兜底率。
- `ai_model_not_accessible` 和 `ai_config_access_revoked` 数量。
- 找不到执行身份或模型所有者的运行数量，目标为 0。
- 共享撤销后仍发起外部调用的数量，目标为 0。
- 跨管理员模型访问拒绝数量和来源路由。
- 无兼容 Embedding 导致的关键词检索降级率。
- 共享提供方停用影响的管理员、任务和运行数量。
- 密钥脱敏测试和日志扫描结果。

告警建议：

- 任意跨普通管理员模型调用直接触发高优先级安全告警。
- 撤销后外呼、空执行身份、空模型所有者触发发布阻断。
- 共享兜底率或单个普通管理员共享用量异常增长触发运营告警。

## 17. 明确不纳入本轮的范围

- 按单个模型授权给指定管理员。
- 管理员组、部门和跨组织共享。
- 普通管理员独立硬预算、充值和计费结算。
- 全量内容资产、任务、文章和知识库的数据租户隔离。
- 普通管理员管理 AI 搜索源和系统采集供应商。
- 共享 API Key 明文查看或复制。
- 自动把普通管理员私有模型提供给超级管理员使用。
- 自动转移离职管理员的私有密钥所有权。

如果未来需要按模型或管理员组共享，可以新增规范化授权表：

```text
ai_model_grants
  model_id
  grantee_type
  grantee_id
  permission
  expires_at
```

本轮单一共享提供方模型可以平滑迁移到该结构。

## 18. 最终验收口径

满足以下全部条件后，本轮才可以发布：

1. 新建普通管理员默认独立，历史普通管理员默认共享指定 legacy owner。
2. 开启共享时，所有自动选模链路都遵循个人模型池优先、共享模型池随后。
3. 关闭共享时，普通管理员的用户内容 AI 外部调用只使用本人模型。
4. 任何管理员都无法通过模型 ID、API Token、队列重试或 CLI 绕过访问边界。
5. 普通管理员无法管理或测试 AI 搜索源和系统采集配置。
6. 队列拥有稳定执行身份，并在外呼前和结果落库前复核权限。
7. 停用、删除、降级和共享撤销不会把私有模型变成公共资源。
8. 共享使用量可以追溯到配置所有者、执行管理员、业务来源和实际模型。
9. 密钥不出现在页面、接口、日志、异常、队列和审计明文中。
10. 35 个直接查询文件全部完成归类和必要改造，架构测试可以阻止回归。
11. 历史迁移预检、灰度、强制收口和安全回滚手册全部验证通过。
12. 自动化测试、静态检查、依赖安全检查和完整回归通过。

## 19. 待确认后执行

本文件当前只完成方案 Review 和最终设计，没有修改业务代码、数据库结构或线上配置。

请确认以下执行基线：

- 共享策略：个人模型优先，共享模型兜底。
- 升级策略：历史普通管理员默认共享，新建普通管理员默认独立。
- 系统边界：AI 搜索源和系统采集配置由超级管理员专属管理。
- 实时检索：无兼容个人或共享 Embedding 模型时降级为关键词检索。
- 历史模型：默认归属显式指定的 legacy 超级管理员，冲突引用进入人工复核清单。
- 生命周期：存在个人模型或活动任务依赖时，默认阻止管理员硬删除。

确认后按第 14 节阶段顺序开始实施，每个阶段独立提交并在进入下一阶段前完成对应门槛。
