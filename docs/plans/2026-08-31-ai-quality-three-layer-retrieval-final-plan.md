# AI 质检三层召回机制最终迭代方案

日期：2026-08-31

状态：已确认并完成实施，等待合并发布

实施说明：三种召回方式、任务与文章配置、准备度与来源快照、失效对账、API 与审计、历史回填、管理端界面和自动化测试均已落地。本文件保留实施前的决策依据、风险门禁与验收口径。

适用范围：GEOFlow 任务管理、文章编辑、AI 质检主链路、质检 API、自动优化与发布门禁

关联依据：`docs/plans/2026-08-30-atomic-facts-system-iteration-plan.md`、`docs/reports/2026-08-30-kb23-atomic-facts-post-upgrade-5-article-benchmark.md`

## 1. 最终结论

本轮建议把 AI 质检升级为三种可选的召回方式，并在任务与文章两个层级形成清晰的配置继承关系：

1. `原子质检`，内部值 `atomic_first`，精度优先。
2. `切片质检`，内部值 `chunk`，准确度、成本与速度均衡。
3. `知识库质检`，内部值 `knowledge_broad`，覆盖优先，噪音、Token 和耗时更高。

任务保存默认方式，文章可以跟随任务或在权限允许时覆盖。独立文章拥有自己的质检知识库绑定。每次质检保存请求方式、实际方式、来源版本和策略版本，历史结果不随配置变化而改写。

正式实施前需要先修复两项现有链路问题：

- 原子主张当前最多处理 24 条，超过上限的内容可能未进入切片回退。
- 超时抽样当前会走固定的切片证据路径，可能改变用户选择的召回方式。

这两项进入第一阶段硬门禁。任一项未通过，原子质检不进入正式可选状态。

## 2. Review 后补齐的关键决策

本版相对初稿补齐以下内容：

- 明确三种方式的算法定义、可用条件、默认顺序和多知识库语义。
- 将 `召回方式` 与 `完整质检或超时抽样`、`主检查或影子检查`、`自动优化阶段` 分成独立维度。
- 增加文章级继承和覆盖模型，并补齐独立文章的知识库来源。
- 增加请求快照、Worker 二次校验和哈希一致性检查，关闭知识更新期间使用旧切片的窗口。
- 增加切片 `serving generation` 与 `pending generation`，同步成功后再原子切换正式服务代次。
- 取消整库层面的静默切换。来源失效时结果进入 `stale`，用户看到稳定错误码和修复入口。
- 保留原子方式内的逐条切片回退，这是 `atomic_first` 的固定算法组成。
- 增加跨知识库冲突门禁、主张溢出保护、抽样继承、优化失效和发布指纹要求。
- 增加托管任务权限、API scope、审计、提示词注入、成本预算和内部错误隔离。
- 增加分阶段迁移、历史回填、灰度、回滚、观测和完整测试矩阵。
- 增加逐检查知识依赖账本、rollout epoch 和 basis hash，支持选择性失效与发布前 CAS 校验。

## 3. 当前实现基线

### 3.1 已有能力

- 任务最多绑定 5 个知识库，任务表单已经具备 AI 质检开关、方案、模型、阈值、超时抽样和自动优化配置。
- 正式质检主链路以切片召回为主，`ArticleAiQualityEvidenceBuilder` 与 `KnowledgeRetrievalService` 已提供结构化证据。
- 原子事实已经具备事实库、审核、发布 revision、确定性比较、影子轨道和正式 rollout 轨道。
- 检查记录已经保存文章快照、知识快照、输入指纹、证据快照、执行元数据和使用量。
- 发布门禁、自动优化、人工放行、API 幂等、队列超时、超时抽样和失效重跑已经存在。

### 3.2 当前事实

- `ArticleAiQualityPolicyResolver::assertExecutable()` 目前只验证提示词、模型和知识库存在性，没有验证切片或原子事实准备度。
- 原子正式轨道当前保持 0%。5 篇文章重复实测中，严重冲突召回为 6/6，安全误阻断为 0/9；输入 Token 仅下降 6.94%，P50 延迟更高，安全文章决策仍有波动。
- 文章编辑页当前只加载任务名称和少量 AI 质检字段，没有独立知识库选择关系。
- `inspection_scope` 已表示 `full` 或 `fallback_sampled`，`evaluation_mode` 已表示主检查、影子检查和优化阶段，`fallback_trigger_code` 已表示超时抽样原因。这三个字段继续保留原语义。
- 任务质检配置更新目前会在事务内外各触发一次失效，新增召回方式前需要收敛为一次 after-commit、可幂等的失效操作。

### 3.3 关键源码证据

| 结论 | 代码位置 |
| --- | --- |
| 现有执行校验未检查切片与原子准备度 | `app/Services/GeoFlow/ArticleAiQualityPolicyResolver.php:202` |
| 原子主张当前最多取 24 条 | `app/Services/GeoFlow/KnowledgeFacts/ArticleAtomicFactInspector.php:124` |
| 检查创建时按完整正文持久化 segment | `app/Services/GeoFlow/ArticleAiQualityInspectionService.php:234`、`:348` |
| 正式原子路径会用 residual 文本替换待检查正文 | `app/Services/GeoFlow/ArticleAiQualityInspectionService.php:647` |
| 同一检查会再次实时解析原子路径 | `app/Services/GeoFlow/ArticleAiQualityInspectionService.php:952`、`:1932` |
| 超时抽样当前固定调用切片 EvidenceBuilder | `app/Services/GeoFlow/ArticleAiQualityInspectionService.php:1257` |
| 同步预约会在正式交换前更新目标来源哈希 | `app/Services/GeoFlow/KnowledgeChunkSyncCoordinator.php:100` |
| 现有切片召回没有强制匹配 serving generation | `app/Services/GeoFlow/KnowledgeRetrievalService.php:208` |
| 任务质检失效当前触发两次 | `app/Services/GeoFlow/TaskLifecycleService.php:410`、`:435` |
| 文章编辑页当前只加载少量任务质检字段 | `app/Http/Controllers/Admin/ArticleController.php:530` |
| 当前重复实测未达到正式原子门槛 | `docs/reports/2026-08-30-kb23-atomic-facts-post-upgrade-5-article-benchmark.md:13` |

## 4. 三种质检方式的产品与算法定义

### 4.1 原子质检 `atomic_first`

定位：精准优先，适合已经完成原子事实治理和正式验证的知识库。

执行规则：

1. 从全部已选知识库的活动 revision 编译不可变事实清单。
2. 从原文中提取带 offset 的主张，执行适用性、数值、日期、范围、文本等确定性比较。
3. 已可靠支持或冲突的主张进入统一评分契约。
4. 未覆盖、歧义、范围不完整和超过原子处理上限的主张，按原文 offset 进入切片召回。
5. 跨知识库的同主题事实发生冲突时，继续取切片作为补充证据，最终结论最高为 `needs_review`。

硬约束：

- 主张处理上限只限制原子比较次数，不能截断文章质检范围。
- 保留完整文章分段和原文 offset，不能用拼接后的未覆盖主张替换文章正文。
- 运行必须记录 `detected_claim_count`、`atomic_processed_count`、`overflow_fallback_count` 和 `uninspected_claim_count`。
- `uninspected_claim_count` 必须恒等于 0，违反时禁止自动通过。

界面说明：`使用已发布的原子事实逐条核验，未覆盖主张继续使用切片。`

### 4.2 切片质检 `chunk`

定位：效率均衡，沿用当前正式质检的核心召回方式。

执行规则：

1. 复用生成阶段的有效证据快照。
2. 按文章主张执行关键词与向量混合召回。
3. 统一去重、治理过滤、证据预算和引用校验。
4. 结果继续进入现有评分、人工放行和发布门禁。

界面说明：`按文章主张召回相关切片，兼顾准确度、成本和速度。`

### 4.3 知识库质检 `knowledge_broad`

定位：覆盖优先，适合原子库或切片尚未准备完成、同时知识库正文可以作为质检依据的场景。

本轮定义为 `正文宽范围分层取证`。它不会承诺对任意体积知识库做逐字全量扫描。

执行规则：

1. 读取知识库正文，跳过生成证据复用和相关性排序。
2. 总证据预算沿用 `ai_quality_max_evidence_characters`，默认 6000 字符，服务端硬上限 12000 字符。
3. 多知识库先获得相同的最低公平额度，未使用额度再分配给其他知识库。
4. 单库按标题、章节、段落和前中后位置做确定性取样，保持完整段落。
5. 保存原文 offset、内容哈希、来源哈希、源字符数、送检字符数、覆盖区域和截断原因。
6. 所有正文通过统一 Prompt Renderer 作为不可信 evidence block 注入，不进入系统指令区。

治理规则：

- 高风险且未审核的知识库不可用于正式质检。
- 普通未审核知识库允许取证，结论最高为 `needs_review`。
- 多知识库出现相互冲突的证据时保留双方，结论最高为 `needs_review`。

界面说明：`从知识库正文按段落与前中后区域做宽范围取证，噪音、Token 和耗时更高。`

## 5. 可用性矩阵与默认规则

### 5.1 单知识库准备度

| 方式 | 必须满足的条件 |
| --- | --- |
| 知识库质检 | 正文去空白后非空；知识库存在；治理状态允许；高风险内容已经审核 |
| 切片质检 | 满足知识库条件；`chunk_sync_status=ready`；当前正文哈希、`chunk_source_hash` 和实际 chunk `source_hash` 一致；至少存在 1 条当前切片 |
| 原子质检 | 满足切片条件；事实库 `serving_status=ready`；活动 revision 存在且事实数大于 0；library、revision、chunk 和正文来源哈希一致；正式原子轨道为 100% 且未冻结 |

### 5.2 多知识库准备度

- 所选的每个知识库都满足某种方式，该方式才可选。
- 页面返回逐库 blocker，明确知识库名称、缺少的准备步骤和管理入口。
- 系统禁止在部分知识库缺席时生成看似完整的正式结果。
- 原子逐条切片回退继续属于原子方式内部流程，不计作整库方式切换。

这里采用 `全部所选知识库原子就绪` 的治理约束。现有实现允许部分事实库 ready 时进入混合路径，但已被原子命中的主张可能跳过其他未就绪知识库的冲突探测。首版以完整来源参与和审计一致性为优先目标。

### 5.3 默认顺序

新任务或独立文章在用户尚未手动选择时，服务端按以下顺序选择当前可用的最高方式：

```text
atomic_first > chunk > knowledge_broad
```

具体规则：

- 新建页面随着知识库选择变化实时重算默认值。
- 用户手动选择后设置 `touched` 状态，后续知识库变化不再静默替换。
- 手动选择变为不可用时，清空选择、显示逐库原因并阻止提交。
- 编辑已有任务或文章时保持已保存值，不做自动升级。
- 原子正式轨道低于 100% 时显示 `数据已就绪，正式质检仍在灰度验证`，选项保持不可用。
- 正式轨道 10%、25%、50% 的 cohort 灰度继续由服务端控制，不作为任务级可选配置。

## 6. 任务管理界面

### 6.1 放置位置

在用户截图红框位置新增 `质检方式` 模块，位于：

```text
自动通过分 / 允许人工放行最低分
  ↓
质检方式
  ↓
完整质检超时后的抽样设置
```

### 6.2 视觉结构

- 使用一个浅灰内嵌 fieldset，内部包含三个等宽选择段。
- 桌面端横向排列，移动端纵向排列。
- 选中项使用蓝色浅底和清晰描边；可用状态使用绿色文字；不可用状态使用灰色锁定样式。
- 每个选项显示名称、定位标签、说明、可用状态和不可用原因。
- 不使用额外悬浮阴影和装饰动画。
- 点击区域最小 40px，使用原生 radio、`fieldset`、`legend`、`aria-describedby` 和 `aria-live`。
- 交互反馈控制在 100 至 160ms，并尊重 `prefers-reduced-motion`。

推荐文案：

| 方式 | 标签 | 说明 |
| --- | --- | --- |
| 原子质检 | 精准优先 | 使用已发布的原子事实逐条核验，未覆盖主张继续使用切片。 |
| 切片质检 | 效率均衡 | 按文章主张召回相关切片，兼顾准确度、成本和速度。 |
| 知识库质检 | 覆盖优先 | 从知识库正文按段落与前中后区域做宽范围取证，噪音、Token 和耗时更高。 |

辅助说明：`系统会根据所选知识库判断可用方式。新任务默认使用当前可用的最高精度方式。`

## 7. 文章编辑界面

### 7.1 继承模型

文章级配置采用以下规则：

| 文章类型 | 知识库来源 | 默认方式 | 可覆盖范围 |
| --- | --- | --- | --- |
| 有任务文章 | 只读继承任务知识库 | 跟随任务 | 普通任务可覆盖方式；托管任务按受保护工作流权限控制 |
| 独立文章 | 文章质检知识库关联表，最多 5 个 | 当前最高可用方式 | 可以选择三种方式 |
| 任务解除后的文章 | 删除任务前复制任务知识库和当时方式 | 复制后的文章配置 | 可以继续人工复检 |

`ai_quality_retrieval_mode_override = null` 表示跟随任务。有任务文章不在文章页修改知识库，页面提供任务设置入口。独立文章需要先选择质检知识库，再计算三种方式的可用性。

策略优先级固定为：

```text
已保存的文章覆盖 > 任务默认 > 独立文章动态默认 > legacy chunk
```

复检请求不提供临时的一次性方式覆盖。API 需要先通过文章 PATCH 保存配置，再使用新的幂等键发起复检，避免页面配置、请求参数和历史审计出现三套来源。

### 7.2 页面呈现

- 在 AI 质检结果卡的操作区下方增加紧凑版 `质检方式` 模块。
- 展示 `当前配置`、`最近一次实际执行`、知识库来源和继承状态。
- 历史结果始终显示该次检查保存的实际方式与来源 revision，不使用当前配置覆盖历史展示。
- 主操作为 `保存并重新质检`。
- 失败面板的 `重试` 明确使用已保存设置，所有复检入口统一走同一请求解析服务。
- 影子原子结果单独标记为 `验证数据`，不显示成正式执行方式。

### 7.3 权限

- 普通任务文章可以沿用现有文章编辑权限修改覆盖方式。
- 托管任务文章只有具备受保护工作流权限的管理员可以修改覆盖方式。
- API 需要同时满足 `articles:publish` scope 和令牌所属管理员的对应权限。
- API PATCH 只要包含质检方式或文章知识库字段，也必须额外满足 `articles:publish` scope。
- 无权限用户可以查看继承值与历史实际方式，控件保持只读。

## 8. 数据模型

### 8.1 新增字段

| 表 | 字段 | 说明 |
| --- | --- | --- |
| `tasks` | `ai_quality_retrieval_mode` | 任务保存值，最终为非空，默认 `chunk`，应用创建时写入动态最高可用值 |
| `tasks` | `ai_quality_policy_version` | 质量策略版本，方式、阈值、模型、提示词或知识库变化时递增 |
| `articles` | `ai_quality_retrieval_mode_override` | 可空；空值表示跟随任务，独立文章由应用解析默认值 |
| `articles` | `ai_quality_policy_version` | 文章质量策略与生命周期版本，配置、正文、删除或恢复时递增 |
| `article_ai_quality_checks` | `requested_retrieval_mode` | 发起检查时请求的方式 |
| `article_ai_quality_checks` | `effective_retrieval_mode` | 实际正式执行方式；运行前失败时可空 |
| `article_ai_quality_checks` | `retrieval_strategy_version` | 召回算法版本 |
| `article_ai_quality_checks` | `retrieval_failure_code` | 稳定失败或失效原因，不保存原始异常 |
| `article_ai_quality_checks` | `retrieval_basis_hash` | 本次不可变检查依据的哈希，供完成、优化和发布 CAS 校验 |

索引：

- `article_ai_quality_checks(effective_retrieval_mode, created_at)`，用于健康报表和策略对比。
- 现有 `input_fingerprint` 与 `active_dedupe_key` 继续承担去重，输入内容加入请求方式和策略版本。

### 8.2 独立文章知识库关系

新增 `article_ai_quality_knowledge_bases`：

- `article_id`，外键，文章删除时级联删除。
- `knowledge_base_id`，外键，知识库删除时限制删除。
- `sort_order`。
- 时间戳。
- 唯一键 `article_id + knowledge_base_id`。
- 索引 `knowledge_base_id + article_id`，用于删除保护和失效查询。

该表只保存文章当前配置。每次检查继续保存不可变的知识库 ID、来源哈希和 revision 快照。

### 8.3 逐检查知识依赖账本

新增 `article_ai_quality_check_sources`，每个 check、知识库和依赖类型保存一行：

- `article_ai_quality_check_id`，外键，检查删除时级联删除。
- `knowledge_base_id`，可空外键，知识库删除时置空。
- `knowledge_base_name_snapshot`。
- `dependency_kind`，取值 `raw_content`、`chunk` 或 `atomic`。
- `source_hash`、`chunk_serving_generation`、`chunk_manifest_hash`。
- `fact_revision_id`、`fact_library_hash`。
- `readiness_status`、`used_provider`、`used_at`。
- 唯一键 `check_id + knowledge_base_id + dependency_kind`。
- 索引 `knowledge_base_id + dependency_kind + check_id`。

该账本用于精确回答某次检查计划依赖了什么、实际使用了什么，以及某次知识变更需要失效哪些检查。JSON 继续保存面向展示的完整快照。

### 8.4 切片服务代次

在知识库与切片上补齐明确的双缓冲字段：

- 知识库保存 `chunk_serving_generation` 和 `chunk_serving_source_hash`。
- 现有同步 token 与目标来源哈希继续表示 pending generation。
- 切片保存所属 `generation_key`。
- 同步成功事务一次性切换 serving generation 与 serving source hash。
- 同步失败保留旧 serving generation，同时最新正文与 serving source hash 不一致，切片方式保持不可用。
- 所有正式切片查询必须同时匹配冻结的 serving generation。

这样可以防止同步期间用新正文哈希为旧切片背书，也可以让缓存和检查 basis 精确指向一次可复现的切片集合。

### 8.5 Rollout epoch

- 原子正式轨道增加单调递增的 `epoch`。
- promote、rollback、freeze、unfreeze 和事故处置都会产生新 epoch 与事件记录。
- 检查创建时把 epoch、bucket、冻结状态和策略版本写入不可变 basis。
- Worker 不在同一检查内重新解析 rollout。
- 紧急 freeze 主动 stale 或取消旧 epoch 的检查、优化和待应用工作流。

### 8.6 字段边界

- `inspection_scope` 继续表示 `full` 或 `fallback_sampled`。
- `evaluation_mode` 继续表示主检查、影子检查或优化阶段。
- `fallback_trigger_code` 继续表示完整检查进入超时抽样的原因。
- 召回方式使用独立字段，防止统计、发布门禁和历史解释混淆。
- `atomic_first` 的逐条切片回退写入 `execution_meta.atomic_facts`，`effective_retrieval_mode` 仍为 `atomic_first`。

### 8.7 追加式质量审计

新增 `ai_quality_audit_events` 作为 append-only 安全事件表：

- 事件 UUID、correlation ID、事件类型和发生时间。
- task、article、check、管理员和 API token 的可空引用。
- 授权结果、策略版本、before hash、after hash、basis hash 和稳定 reason code。
- 幂等 replay 或 conflict、预算结果、缓存 mismatch、删除恢复和 freeze epoch。
- 受限 metadata JSON，只保存 ID、枚举、计数和哈希。

配置变更、人工放行、rollout 操作和删除恢复必须与业务事务原子写入事件或事务 outbox。审计存储失败时，关键操作 fail-closed。知识正文、证据全文和供应商原始异常不进入该表。

## 9. 服务架构

### 9.1 新增核心边界

- `AiQualityRetrievalMode`：定义值、标签、排序和白名单。
- `AiQualityRetrievalReadinessService`：统一计算单库和多库准备度，供页面、保存、检查创建和 Worker 使用。
- `ArticleAiQualityEvidenceStrategy`：统一三种策略的输入输出契约。
- `AtomicFirstEvidenceStrategy`：原子比较、offset 覆盖图和逐条切片回退。
- `ChunkEvidenceStrategy`：封装现有 EvidenceBuilder 与 RetrievalService。
- `KnowledgeBroadEvidenceStrategy`：正文分层取样、公平预算和治理门禁。
- `ArticleAiQualityEvidenceStrategyResolver`：只按已经验证的方式解析策略。
- `ArticleAiQualityRetrievalCoordinator`：计算一次策略结果，并在完整、抽样、评分和解释阶段复用。
- `AiQualityRetrievalResult`：类型化保存证据、覆盖、哈希、实际方式、统计和 blocker。
- `AiQualityRetrievalBasis`：冻结请求方式、rollout epoch、bucket、来源代次、revision、预算和策略版本，并生成 basis hash。

`ArticleAiQualityInspectionService` 只注入 Coordinator，避免继续增加多项底层依赖。

### 9.2 统一策略结果

三种策略返回兼容证据契约：

- `evidence_id`
- `knowledge_base_id`
- `content`
- `content_hash`
- `source_hash`
- `section_path`
- `source_offset_start`
- `source_offset_end`
- `retrieval_strategy`
- `retrieval_strategy_version`
- `governance_status`
- `coverage_meta`

统一覆盖语义同时返回 `claim_total`、`supported`、`contradicted`、`ambiguous`、`uncovered`、逐库状态和 `normalized_coverage`。`execution_meta.retrieval_path` 保存实际经过的 provider 路径，例如原子与逐条切片回退。评分器只消费合并后的 normalized coverage。

知识库宽范围方式可以没有真实 `chunk_id`。结果校验继续依据 evidence ID，不强制伪造 chunk ID。

## 10. 请求到执行的完整时序

```text
用户选择知识库与质检方式
  -> 服务端验证权限、归属、数量和枚举
  -> 在锁事务中计算准备度并保存配置
  -> 创建检查时冻结请求方式、rollout epoch、serving generation、revision 和策略版本
  -> 写入逐检查知识依赖账本与 basis hash
  -> 指纹、活动去重键、API 幂等语义加入请求方式
  -> Worker 启动前重新核对文章、知识库、切片和 revision 快照
  -> Resolver 选择已验证的策略
  -> Coordinator 计算一次证据与覆盖结果
  -> 完整质检或超时抽样复用同一召回方式和来源快照
  -> 统一评分、优化和发布门禁
  -> 保存实际方式、覆盖、Token、延迟、冲突和失败原因
```

### 10.1 TOCTOU 处理

- 页面可用状态只提供交互提示。
- 知识正文更新、pending generation 创建、事实库 stale、相关检查与优化失效、reconcile outbox 在同一事务中提交。
- 保存、创建检查和 Worker 开始执行均重新校验。
- 检查快照包含当前正文哈希、切片来源哈希、活动 revision ID 和 library hash。
- 检查 basis 同时包含 serving generation、chunk manifest hash、rollout epoch 和治理版本。
- 切片查询必须匹配检查 basis 中的 serving generation。
- 运行期间发现来源变化时，将检查置为 `stale`，错误码为 `ai_quality_retrieval_source_stale`，保持文章待审核。
- 同步完成后由现有 reconcile 链路创建新检查，旧检查保留审计记录。
- 系统不在来源失效时自动切换为另一种整库方式并继续自动发布。
- 完成检查、优化候选接收、优化 apply、工作流 apply 和发布门禁都以 basis hash 做 CAS 重验。
- 紧急 freeze 先发布新 epoch 并设置状态围栏，再失效旧 epoch 工作，最后开放 reconcile，避免重检抢跑。
- 任务删除时先锁任务、文章、知识库 pivot 和活动检查，完整复制有序知识库、方式、policy version 与来源快照后再解绑。
- 文章删除和恢复都会提升 policy version。恢复后的文章保持待审核，只能基于当前来源创建新检查。
- 永久删除遵守明确的审计保留或 tombstone 规则；需要保留的历史来源不会因外键级联而失去解释依据。

### 10.2 超时抽样

- 超时抽样只缩减文章检查范围，继续使用原请求方式与来源快照。
- 三种方式分别实现抽样输入，不能统一固定走切片 Builder。
- 知识库宽范围方式的抽样结果默认最高为 `needs_review`。
- 原子抽样只有在 `uninspected_claim_count=0`、跨库冲突已进入门禁、切片回退覆盖充分时才允许自动通过。
- 页面同时显示实际方式和 `抽样质检` 标记。

### 10.3 自动优化与发布

- 优化候选继承 baseline 检查的请求方式、策略版本和证据来源。
- 优化期间方式、知识来源或 revision 变化时，将运行置为 stale 并终止自动应用。
- 自动优化最终复检必须使用同一请求方式，除非用户显式保存新配置并重新开始。
- 发布门禁比较最新检查指纹，指纹包含召回方式、策略版本和来源快照。
- 发布状态变更与分发入队在同一事务中绑定当前 `retrieval_basis_hash`，分发执行前再次确认该 basis 仍有效。
- 模式变化立即使旧检查和活动优化失效。
- 已发布历史文章保留原检查；下一次编辑或人工复检使用新配置。

## 11. 验证、权限与安全

### 11.1 Form Request 与服务端验证

- 任务创建和更新请求增加召回方式枚举，并在 `after()` 中调用统一准备度服务。
- 文章保存和复检使用专用请求类，校验方式、知识库数量、知识库归属和文章状态。
- 所有写服务只使用 `validated()` 数据。
- 前端 disabled 状态不能替代服务端校验。
- 管理端和 API 共用同一策略解析与授权服务。
- 定义 `overrideRetrievalMode`、`overrideQualityDecision` 和 `manageIndependentArticleKnowledge` 三项能力，避免方式覆盖与人工放行共用宽泛权限。
- 授权在锁事务内基于最新文章、任务和渠道关系再次执行，防止文章在检查后被切换为托管来源。
- 配置保存、检查创建和 Worker 启动分别验证 actor 或冻结的授权 basis；权限被撤销后旧操作不能继续推进。
- AI 质检关闭时保留已持久化配置，同时忽略请求中对方式和知识库的新修改。再次启用时执行完整准备度与权限校验。

稳定错误码：

- `ai_quality_retrieval_mode_invalid`
- `ai_quality_retrieval_mode_unavailable`
- `ai_quality_retrieval_source_stale`
- `ai_quality_retrieval_permission_denied`
- `ai_quality_retrieval_claim_coverage_incomplete`
- `ai_quality_retrieval_cross_kb_conflict`

未知异常只记录内部日志，用户端显示本地化通用提示。

### 11.2 输出与提示词安全

- Blade 继续使用 `{{ }}` 输出动态知识库名称和 blocker。
- JavaScript 使用 `textContent` 或 `replaceChildren`，禁止用动态字符串写入 `innerHTML`。
- 知识库正文统一放入不可信 evidence block，并使用安全 JSON 编码。
- `reviewed_claim_hashes`、覆盖率和已核查状态由服务端根据有效 evidence ID 计算，模型字段只用于解释。
- 每个已核查主张必须关联属于当前 basis 的 evidence。未知、重复、超量或无证据 claim hash 直接拒绝。
- 模型输出不能清除检索器已经发现的冲突、未覆盖、治理门禁或安全 reason code。
- 知识库名称、章节路径、标题和正文使用同一不可信输入边界；检测到提示词注入风险时结论最高为 `needs_review`。
- 遥测、审计和错误响应不得包含知识正文、证据全文、原始模型输出或供应商异常。
- API 状态只返回方式枚举、安全 reason code、可公开覆盖统计和显示标签。
- 来源哈希、详细 blocker 和 revision 诊断只在具备管理权限的后台显示。
- 响应 DTO 分为公开、文章编辑、知识库管理和受保护工作流四级。无权资源统一返回不存在语义，普通响应不暴露知识库名称、哈希、revision ID 和逐库内部 blocker。

### 11.3 审计

记录以下结构化字段：

- 请求方式和实际方式。
- 任务默认值与文章覆盖值的前后变化。
- 知识库 ID、有限长度哈希、revision ID 和策略版本。
- 准备度结果、失效或冲突 reason code。
- 管理员 ID、API token ID、触发来源和时间。
- 授权拒绝、422、429、预算拒绝、幂等 replay 或 conflict、缓存 mismatch、删除恢复与 freeze 事件。

## 12. 缓存、幂等与并发

- 证据缓存键加入有序知识库 ID、站点命名空间、请求方式、策略版本、治理版本、serving generation、rollout epoch、活动 revision、全部预算参数和抽样范围哈希。
- 缓存 value 保存格式版本、basis 摘要和完整性哈希；命中时验证 schema、大小、evidence ID、KB ID、source hash 和 revision。异常值删除后安全重算。
- `knowledge_broad` 不复用生成证据；`chunk` 与 `atomic_first` 的切片回退可以复用有效生成证据。
- 活动去重键加入请求方式，原子、切片和知识库请求不能相互复用运行记录。
- 同一文章同一输入的并发请求继续由数据库唯一键和锁保护。
- API recheck 要求 `config_version` 或 `If-Match`。幂等上下文加入 article policy version、资源 epoch、basis hash、来源 revision 和治理 epoch。
- 幂等 replay 返回前重新认证、授权并确认文章存在状态；旧配置、删除恢复或权限变化返回 409，不能直接重放旧成功响应。
- 幂等记录定义保留期和清理任务，删除与恢复会提升文章 policy version。
- 现有 `task_revision` 扩展到质检方式、阈值、模型、提示词和有序知识库 ID，防止多人编辑时静默覆盖质量策略。
- 来源更新、任务方式变化、文章覆盖变化、事实 revision 发布或恢复、治理状态变化均触发检查与优化失效。
- `TaskLifecycleService` 的质检失效收敛为一次 after-commit 调用，移除当前重复触发。
- 知识库失效查询同时覆盖任务绑定文章、独立文章 pivot 绑定和历史快照引用。
- 失效事件拆分为 `knowledge_content_changed`、`chunk_generation_changed`、`atomic_revision_changed` 和 `governance_metadata_changed`。
- 每种策略声明依赖类型，失效服务通过 `article_ai_quality_check_sources` 精准选择检查，避免原子 revision 更新触发纯知识库方式的重检风暴。
- rollout freeze 或 rollback 使旧 epoch 的检查、优化候选和工作流 apply 全部失效，已排队发布继续由状态围栏拒绝。

## 13. 性能与预算

- 任务和文章表单不加载知识库正文，只加载 ID、名称、准备度所需状态与计数。
- Controller 或专用查询服务批量 eager load，Blade 内不执行查询。
- 表单初始状态使用轻量投影；保存和运行时再计算权威哈希。
- 单任务最多 5 个知识库的限制保持不变。
- 知识库宽范围方式继续受 6000 字符默认预算和 12000 字符硬上限保护。
- 宽范围策略禁止一次 hydrate 多个完整正文。优先使用现有 section 元数据；缺少切片时通过数据库 bounded substring 分段读取前中后窗口，单次进程读取量控制在证据预算与边界窗口之内。
- 保存与入队前检查聚合原始字节、字符、段落数和单段长度；5 个 8MB 知识库的压力测试必须在 128MB worker 条件下通过。
- 原子事实清单设置可评测硬上限，超过上限的主张完整进入切片回退。
- 原子编译结果按 `library_id + active_hash + algorithm_version` 缓存，每次检查只执行一次匹配。
- 切片策略先提取全部主张，每个知识库批量收集一次有界候选，再在内存候选集中按主张排序，避免最多 35 轮重复检索和小库全量加载。
- 单次检查记录召回查询数、读取行数、缓存命中和 evidence p95，发布门槛要求新路径不高于现有基线。
- 队列保持现有 180 秒级预算关系，确认 `retry_after > worker timeout > job timeout`。
- `knowledge_broad` 使用独立队列和并发上限，防止宽范围任务占满普通质检 worker。
- 所有复检入口共用管理员、token、文章和站点维度 limiter，覆盖保存并复检、失败重试、API、reconcile 和优化最终复检。
- 入队前预占日 Token、费用、并发与排队预算，完成后按实际 usage 结算，失败与 fallback 采用明确退补规则。
- 知识变更事件按 `KB + generation 或 revision` 合并、去重和冷却，设置最大 fan-out 与 backpressure。
- 策略结果只计算一次，完整质检、抽样、评分与展示复用同一快照。

## 14. 数据迁移与兼容

采用 expand、backfill、contract 三步：

### 14.1 Expand

- 单独迁移新增任务质检方式与 policy version，先允许方式为空。
- 单独迁移新增文章覆盖方式与 policy version。
- 单独迁移新增检查请求方式、实际方式、策略版本、失败码与 basis hash。
- 单独迁移创建文章质检知识库关联表及索引。
- 单独迁移创建逐检查知识依赖账本。
- 单独迁移增加轻量准备度投影，持久化正文哈希、正文长度与已发布原子事实数量。
- 单独迁移增加切片 serving generation 字段与索引。
- 单独迁移增加原子 rollout epoch。
- 单独迁移创建 append-only 质量审计事件表。
- 代码将空任务方式解释为 legacy `chunk`，确保滚动部署兼容。

### 14.2 Backfill

- 使用可重复、可分批的命令回填，禁止在 DDL 迁移中执行大批量 DML。
- 历史任务回填 `chunk`，保持现有用户行为。
- 历史检查根据正式原子执行元数据推断 `atomic_first`，其余正式检查回填 `chunk`；影子原子结果不改变正式方式。
- 历史检查来源账本按批次从现有执行元数据补齐；无法可靠推断的行标记 `legacy_unknown`，不参与当前发布门禁。
- 现有切片按知识库生成初始 serving generation 和 manifest hash，回填完成前切片方式保持 legacy 兼容读取，完成后启用 generation 强校验。
- 文章 override 保持空值，继续跟随任务。
- 回填过程输出数量、异常样本和可复核报告。

### 14.3 Contract

- 回填确认后将任务方式设置为非空，并保留数据库默认 `chunk`。
- 新任务仍由应用在提交时写入动态最高可用方式。
- 旧版代码回滚时可以忽略新增字段与关联表，不破坏已有检查数据。

## 15. 观测与评测

每次检查增加以下指标：

- `requested_retrieval_mode`
- `effective_retrieval_mode`
- `retrieval_strategy_version`
- `inspection_scope`
- `retrieval_basis_hash` 与 rollout epoch
- 每阶段 Token 和延迟
- 证据字符数、知识库数量和逐库覆盖率
- 原子主张总数、处理数、溢出回退数、未检查数
- 原子支持、冲突、歧义和切片回退比例
- 跨知识库冲突数
- stale、失败和人工复核 reason code
- basis mismatch、generation mismatch 和 freeze 后旧 epoch 推进数量
- 召回查询数、读取行数和缓存命中率

健康报告和评测报告分别按 `effective_retrieval_mode` 与 `inspection_scope` 分组，避免把方式差异与抽样差异混在一起。

正式原子开放门槛：

| 指标 | 门槛 |
| --- | ---: |
| 严重事实冲突召回率 | 大于等于 97% |
| 安全内容误阻断率 | 小于等于 3% |
| 问题、分数、最终决策一致率 | 大于等于 95% |
| 已覆盖样本输入 Token 降幅 | 大于等于 35% |
| 原子 P50 延迟 | 不高于切片方式 |
| 未检查主张数 | 0 |
| 来源漂移后继续发布数 | 0 |
| freeze 后旧 epoch 完成、优化应用或发布数 | 0 |
| pending 或 failed 知识库使用旧切片的新检查数 | 0 |

评测集使用 30 至 50 篇人工标注文章，每篇三次重复，并在同一黄金集上比较三种方式。

原子轨道 promotion 只接受通过真实 `ArticleAiQualityInspectionService`、优化和发布门禁的端到端报告，拒绝通用 reviewer 报告。报告矩阵必须覆盖三种方式、full 与 sampled、单库、多库全部就绪、多库冲突、任务默认、文章覆盖、管理端、API 和优化；缺少任一必需单元格时拒绝推进。

## 16. 测试矩阵

### 16.1 单元测试

- 三种方式的值、排序、显示和序列化。
- 单库、多库、部分就绪、哈希漂移、治理状态和 rollout 准备度。
- 默认值解析、用户 touched 行为和不可用时阻断。
- 三种策略的统一证据契约。
- 知识库宽范围的公平分配、章节取样、offset、预算、去重和确定性。
- 指纹、活动去重键和证据缓存按方式隔离。
- basis hash、serving generation、rollout epoch 和依赖账本序列化稳定。
- 跨知识库同值、冲突值、不同时间范围和不同业务 scope。
- 25 条、50 条主张、长段落和多 segment 原子覆盖，`uninspected_claim_count=0`。
- 真实 claim hash 复制、中英文 jailbreak、零宽字符、Bidi、Base64、知识库名称和 section path 注入。
- 缓存同正文不同 KB、跨站点同 hash、治理切换、缺字段、超大 payload 和过期格式。

### 16.2 Feature 与 API 测试

- 新任务无知识库、仅正文、切片就绪、原子就绪和原子灰度中的默认状态。
- 篡改 disabled 选项提交时返回 422。
- 已有任务保持 `chunk`，不自动升级。
- 文章跟随任务、普通覆盖、托管任务授权、独立文章知识库选择和任务删除前复制。
- 知识库删除保护与知识变更失效覆盖独立文章。
- Web 保存并复检、失败重试、API PATCH、API 复检、状态查询和幂等。
- 同一幂等键在方式、revision、治理、删除恢复和管理员权限变化后返回 409，不重放旧成功。
- Worker 开始前来源漂移，检查进入 stale，文章保持待审核。
- 知识库更新后切片同步失败，旧 serving generation 不能获得新正文哈希，也不能通过发布门禁。
- Freeze 发生在 queued、running、评分、优化 apply 和 workflow apply 时，旧 epoch 均无法继续推进。
- 三种方式分别覆盖完整超时、抽样、自动放行门禁和人工复核。
- 方式变化使检查、发布指纹和活动优化失效。
- 影子原子结果不影响分数、决策、发布和自动优化。
- 并发复检只产生一个有效活动检查。
- 两位管理员同时编辑任务时，质检方式、阈值和有序知识库 ID 进入 `task_revision`，旧页面提交被拒绝。
- 任务删除后的 sending 分发继续标记 `outcome_unknown`，并进入远端核对和人工告警，不宣称已经撤回。
- AI 关闭时篡改方式、重复或超过 5 个 KB、旧页面提交、重新启用前来源变化均被安全处理。
- 51 次连续人工请求仍保留完整 append-only 审计，审计 outbox 重投不产生重复事件。
- 公开、文章编辑、知识库管理和受保护工作流四级 DTO 不泄露越权来源信息。

### 16.3 前端与视觉测试

- 任务和文章共用选择器的默认、选中、不可用、错误和只读状态。
- 知识库选择变化后的实时重算与 touched 行为。
- 键盘操作、焦点、ARIA 状态和减少动态效果。
- 1280px、1440px 桌面，以及隔离测试浏览器中的 375px、320px 布局。
- 中文、英文、日文、俄文、西班牙文和葡萄牙文长文案布局。
- 浏览器检查使用隔离上下文，不修改用户现有浏览器窗口尺寸。

### 16.4 验证命令

实施时按改动范围执行：

- 聚焦 PHPUnit 单元与 Feature 测试。
- JavaScript 测试。
- 前端构建。
- `vendor/bin/pint --dirty`。
- 相关 AI 质检、优化、发布、任务和知识库回归测试。
- 迁移回滚与 PostgreSQL 索引验证。

## 17. 实施阶段

### 阶段 1：安全基础与策略内核

交付：

- 数据字段、文章知识库关联表、值对象、准备度服务和策略接口。
- 三种策略及统一 Coordinator。
- 修复 24 条主张溢出和 segment 拓扑问题。
- 修复抽样改变召回方式的问题。
- 增加 serving generation、rollout epoch、不可变 basis、逐检查知识依赖账本和选择性失效。
- Worker 二次校验、指纹、缓存、审计和失效链路。
- 幂等上下文版本、共享复检限流、预算预占和 append-only 审计。
- 评测命令支持三种方式，但管理端暂不开放选择。

合并条件：默认正式行为继续等同 `chunk`；`uninspected_claim_count=0`；来源漂移 fail-closed；三种策略可以通过评测命令复核。

### 阶段 2：任务与文章配置闭环

交付：

- 任务红框位置的完整选择器。
- 文章紧凑选择器、继承状态、独立文章知识库选择和历史实际方式。
- 管理端与 API 验证、权限、重试、状态响应和本地化。
- TaskLifecycle、知识库删除保护、优化和发布门禁联动。

合并条件：切片与知识库方式可以完整使用；原子选项受 100% 正式门禁控制；旧任务不改变方式。

### 阶段 3：原子正式开放

交付：

- 30 至 50 篇黄金集三方式重复评测。
- 修复稳定性、覆盖率、Token 和延迟未达标项。
- rollout 按 0%、10%、25%、50%、100% 推进。
- 达到 100% 且未冻结后，任务和文章可以选择原子质检，新建配置默认优先原子。

合并条件：全部正式门槛通过，影子与正式结果语义一致，回滚演练完成。

## 18. 预计文件范围

以下为实施时的目标范围，具体迁移时间戳由 Artisan 生成。

### 新增

- `app/Support/GeoFlow/AiQualityRetrievalMode.php`
- `app/Services/GeoFlow/AiQualityRetrievalReadinessService.php`
- `app/Contracts/ArticleAiQualityEvidenceStrategy.php`
- `app/Services/GeoFlow/AtomicFirstEvidenceStrategy.php`
- `app/Services/GeoFlow/ChunkEvidenceStrategy.php`
- `app/Services/GeoFlow/KnowledgeBroadEvidenceStrategy.php`
- `app/Services/GeoFlow/ArticleAiQualityEvidenceStrategyResolver.php`
- `app/Services/GeoFlow/ArticleAiQualityRetrievalCoordinator.php`
- `app/Support/GeoFlow/AiQualityRetrievalResult.php`
- `app/Support/GeoFlow/AiQualityRetrievalBasis.php`
- `app/Models/ArticleAiQualityCheckSource.php`
- `app/Models/AiQualityAuditEvent.php`
- `app/Services/GeoFlow/AiQualityAuditService.php`
- `app/Http/Requests/Admin/SaveTaskRequest.php`
- `app/Http/Requests/Admin/SaveArticleRequest.php`
- `app/Http/Requests/Admin/ArticleAiQualityRecheckRequest.php`
- `app/Http/Requests/Api/StoreArticleRequest.php`
- `app/Http/Requests/Api/UpdateArticleRequest.php`
- `app/Http/Requests/Api/ArticleAiQualityRecheckRequest.php`
- `resources/views/components/admin/ai-quality-retrieval-selector.blade.php`
- `resources/js/admin/ai-quality-retrieval-selector.js`
- `tests/Unit/AiQualityRetrievalReadinessServiceTest.php`
- `tests/Unit/KnowledgeBroadEvidenceStrategyTest.php`
- `tests/Feature/AiQualityRetrievalModeTest.php`
- `tests/JavaScript/ai-quality-retrieval-selector.test.js`
- 9 个聚焦 DDL 迁移：任务配置、文章配置、文章知识库关系、检查审计字段、检查来源账本、轻量准备度投影、切片 serving generation、rollout epoch、append-only 质量审计事件。
- 1 个可重复执行的历史回填命令及测试。

### 修改

- `app/Models/Task.php`
- `app/Models/Article.php`
- `app/Models/ArticleAiQualityCheck.php`
- `app/Models/KnowledgeBase.php`
- `app/Models/KnowledgeChunk.php`
- `app/Http/Controllers/Admin/TaskController.php`
- `app/Http/Controllers/Admin/ArticleController.php`
- `app/Http/Controllers/Admin/KnowledgeBaseController.php`
- `app/Http/Controllers/Api/V1/TaskController.php`
- `app/Http/Controllers/Api/V1/ArticleController.php`
- `app/Http/Requests/Api/StoreTaskRequest.php`
- `app/Http/Requests/Api/UpdateTaskRequest.php`
- `app/Services/GeoFlow/TaskLifecycleService.php`
- `app/Services/GeoFlow/ArticleGeoFlowService.php`
- `app/Services/GeoFlow/ArticleAiQualityPolicyResolver.php`
- `app/Services/GeoFlow/ArticleAiQualityInspectionService.php`
- `app/Services/GeoFlow/ArticleAiQualityInvalidationService.php`
- `app/Services/GeoFlow/ArticleAiQualityEvidenceCache.php`
- `app/Services/GeoFlow/ArticleAiQualityFingerprint.php`
- `app/Services/GeoFlow/ArticleAiQualityResultValidator.php`
- `app/Services/GeoFlow/ArticleAiOptimizationCoordinator.php`
- `app/Services/GeoFlow/AiUsageQuotaService.php`
- `app/Services/Api/IdempotencyService.php`
- `app/Services/GeoFlow/KnowledgeRetrievalService.php`
- `app/Services/GeoFlow/KnowledgeChunkSyncCoordinator.php`
- `app/Services/GeoFlow/KnowledgeChunkSyncService.php`
- `app/Services/GeoFlow/KnowledgeFacts/ArticleAtomicFactInspector.php`
- `app/Services/GeoFlow/MaterialLibraryService.php`
- `app/Console/Commands/ManageArticleAiQualityRolloutCommand.php`
- `app/Providers/AppServiceProvider.php`
- `app/Support/Admin/ArticleAiQualityProgressPresenter.php`
- `config/geoflow.php`
- `config/horizon.php`
- `routes/web.php`
- `routes/api.php`
- `resources/views/admin/tasks/create.blade.php`
- `resources/views/admin/articles/form.blade.php`
- `resources/js/admin/task-form.js`
- `resources/js/app.js`
- `lang/zh_CN/admin.php`、`lang/en/admin.php`、`lang/ja/admin.php`、`lang/ru/admin.php`、`lang/es/admin.php`、`lang/pt_BR/admin.php`
- 现有任务、文章、质检、优化、发布、知识召回和迁移契约测试。

## 19. 灰度与回滚

### 19.1 灰度

- 阶段 1 与阶段 2 保持正式原子轨道 0%，只运行影子评测。
- 10%、25%、50% 只用于系统 cohort，页面原子选项仍不可选。
- 达到全部门槛后推进 100%，页面开放原子选择并启用动态默认。
- 每个阶段至少观察一个完整业务周期，检查冲突漏检、误阻断、回退率、延迟、Token 和 stale 数量。

### 19.2 回滚

- 原子异常：将正式原子轨道回到 0% 或冻结，已有任务配置保留，页面显示暂不可用，新检查 fail-closed。
- Freeze 会先提升 epoch 并围栏旧 basis，随后取消活动检查、优化和待应用工作流。已排队分发取消，sending 分发进入远端核对。
- 知识库宽范围异常：从服务端支持列表暂时隐藏该方式，已完成历史记录继续可读。
- UI 异常：回滚 Blade 与 JavaScript，服务端字段和历史快照保持兼容。
- 代码回滚：新增字段在旧代码中保持可忽略，关联表不做破坏性删除。
- 数据修复使用前向迁移或回填命令，避免对已运行迁移做修改。

## 20. 明确不采用的方案

- 在 `ArticleAiQualityInspectionService` 内增加三段大型条件分支。该类已经承担较多职责，继续扩张会放大完整、抽样和优化路径的漂移。
- 把文章知识库和当前方式只存入历史 policy JSON。配置与运行历史会混合，删除保护、查询和失效难以可靠实现。
- 在整库准备度失效时静默切换到另一方式。用户配置、实际行为和审计结果会失去一致性。
- 首版直接对最多 40MB 的多知识库正文执行单次全量模型调用。现有队列、上下文和成本边界无法稳定支持。
- 在正式原子轨道低于 100% 时开放任务级原子选择。任务配置会与文章 ID cohort 的实际执行长期不一致。

## 21. 确认项

确认本方案即表示接受以下产品定义：

1. `知识库质检` 是受预算保护的正文宽范围分层取证，不承诺任意体积内容的逐字扫描。
2. `原子质检` 固定包含未覆盖主张的逐条切片回退。
3. 多知识库采用全部就绪语义，任一来源缺席会阻止该方式运行。
4. 整库方式不做静默降级，来源变化时检查进入 stale 并重新排队。
5. 原子任务级选择只在正式轨道达到 100% 且未冻结后开放。
6. 旧任务保持切片质检，只有新任务或用户显式修改才使用新的动态默认。
7. 独立文章可以绑定最多 5 个质检知识库；有任务文章的知识库继续由任务统一管理。
8. 功能实施按三个阶段推进，每个阶段完成代码审查、测试和可回滚验证后再进入下一阶段。
9. 原子质检要求全部所选知识库原子就绪，避免部分来源未参与冲突探测。
10. 切片同步采用 serving 与 pending 双代次，成功交换前旧切片不能代表新正文。

本文件确认前只作为实施依据，不启动功能代码修改。
