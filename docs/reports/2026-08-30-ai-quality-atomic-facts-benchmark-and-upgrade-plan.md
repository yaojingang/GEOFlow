# GEOFlow 原子事实库与 AI 质检升级最终方案

日期：2026-08-30
版本：Final Review v1.0
状态：待产品与技术确认，尚未实施
范围：知识库事实治理、任务文章与单篇文章 AI 质检、文章生成事实约束

## 最终判断

**结论：方案值得实施，推荐采用“版本化原子事实库 + 动态证据质检 + 合规检查”的分层架构。**

企业知识中有一批会被多篇文章重复引用的事实，例如成立时间、注册资本、专利数量、产品型号、认证资质和服务范围。当前质检会为每篇文章重新提取主张、检索知识分块、拼接证据并调用模型。原子事实库把这类高频事实提前提炼为类型化标准值，经人工审核后发布不可变版本，后续文章可以复用，已覆盖事实因而能减少在线检索、上下文 Token 和模型判断。

原子事实库只处理已定义且当前有效的事实。广告合规、禁用表述、引用完整性、上下文逻辑、事实库外的新主张和来源冲突继续使用现有质检能力。这个分工能保住现有覆盖范围，也让数字和日期类事实获得更稳定的比较结果。

本轮 review 对初稿做了十项关键修订：

- 把“事实指标”和“标准值”拆开，一个指标可以保存多个时间切片，避免用最新专利数量否定历史文章。
- 把编辑工作流状态与在线服务状态拆开，生成失败时仍可继续使用上一版已发布事实。
- 证据不再依赖永久稳定的 chunk ID。当前 `KnowledgeChunkSyncService` 会在同步完成时删除并重建全部知识分块，事实证据必须保存内容哈希、来源定位和摘录，并支持自动重连。
- 数字硬拦截增加适用性判断。主体、谓词、时间、地域、口径和单位都可比时，数值冲突才进入硬门禁。
- 文章主张提取增加分句、否定、引用、假设和纠错语境，降低“某报告称 2010 年成立，但正确时间是 2012 年”这类句子的误判。
- AI 生成任务增加模型能力检查、用量预占、源版本固定、并发编辑保护、取消、幂等和数据保留规则。
- 影子语义核验移到现有低优先级队列，不占主质检关键路径。
- “某事实必须出现在文章中”改为任务级规则，知识库事实默认只在文章提及时核验。
- 质量门槛加入样本量和置信区间，确定性数字比较与端到端模型评测分开验收。
- 原子事实问题接入现有 AI 自动优化链路，补充事实 revision 固定、适用性校验、过期补丁阻断和复检约束。

**Entity delta：新增 6 个持久化数据实体、1 组知识库管理能力；公共 API、外部运行时依赖和现有能力删除均为 0。** 预计涉及 40 至 55 个文件，分成六个可独立合并、可独立回滚的阶段。

## 目标、边界与成功条件

### 产品目标

- 用户可以为知识库生成自定义数量的原子事实指标。
- 每个指标可以保存一条或多条带时间、范围和单位的标准值。
- 用户可以新增、编辑、合并、停用、审核、发布和恢复事实库版本。
- 任务文章与单篇文章质检优先使用有效标准事实，未覆盖内容自动回到现有知识检索。
- 文章生成可以读取允许用于生成的标准事实，减少错误数字进入初稿。
- 每次生成、发布、质检和恢复都能追溯到管理员、模型、来源分块和事实库版本。

### 首版边界

- 原子事实没有被文章提及时，不产生缺失问题。
- 必须出现的事实由任务规则定义，首版不把 `required` 放在知识库事实上。
- AI 只能生成工作候选，不能自动发布，也不能覆盖人工确认的活动版本。
- 原子事实快速通道不会替代广告合规、内容完整性、引用检查和现有发布门禁。
- 外部网页搜索、第三方事实数据库、完整知识图谱和跨租户事实市场不进入首版。
- 管理端继续使用当前可配置的 Admin 前缀，首版不增加公开 API。
- 首版归一化器覆盖中文与常见英文数字格式。其他语言内容自动回到现有质检。

### 会削弱收益的前提

原子事实库需要一批跨文章重复使用、可由业务人员确认、更新频率可控的事实。知识内容若以临时观点、长尾案例和频繁变化的非结构化描述为主，快速通道覆盖率会偏低。此时现有质检仍然工作，新增事实治理带来的 Token 和延迟收益会变小。

## GEOFlow 当前基础

GEOFlow 已具备知识库正文、知识分块、来源哈希、混合检索、知识库修订、任务多知识库绑定、AI 质检快照、三态结论、灰度发布、Horizon 队列和失效重算。这项升级可以沿用现有 Laravel 单体架构与 Laravel AI SDK。

当前 `ArticleAiQualityInspectionService` 使用 `ArticleFactCandidateExtractor` 提取本篇文章的动态事实候选，再由 `ArticleAiQualityEvidenceBuilder` 和 `KnowledgeRetrievalService` 组装证据。现有快速链路限制通用检索、补充检索、证据数量和上下文长度，并把事实候选、证据、模型、算法版本和执行信息写入 `ArticleAiQualityCheck`。

现有能力与新能力的关系如下：

| 层次 | 生命周期 | 用途 |
|---|---|---|
| 知识库事实指标 | 跨文章、人工治理 | 定义稳定键、主体、谓词、值类型和别名 |
| 标准事实值 | 可多期、带有效时间 | 保存数字、日期、文本、范围、单位和比较规则 |
| 文章动态事实候选 | 单次质检 | 捕获新主张、长尾信息和事实库未覆盖内容 |
| 知识分块证据 | 随知识库同步 | 原始依据、标准值溯源和回退检索 |

当前实现还有四项直接影响设计的约束：

- `KnowledgeChunkSyncService::finalizeStagingSync()` 会删除知识库的现有 chunks，再插入新 rows。证据外键需要 `nullOnDelete`，并使用 `source_hash`、`content_hash` 和来源定位重连。
- `Task::knowledgeBases()` 的 `sort_order` 表示绑定与检索顺序，没有表达事实权威级别。多知识库冲突不能直接按顺序判定真值。
- `ArticleAiQualityRolloutPolicy` 已有 0、10、25、50、100 五级灰度和冻结机制，原子事实灰度应复用这套控制面。
- `ArticleAiOptimizationCoordinator` 已处理 `knowledge_contradiction` 和 `unsupported_claim`。原子事实问题必须携带稳定版本和类型化比较结果，避免自动优化使用过期标准值。

## 开源项目对标

调研优先使用官方仓库、论文与实际实现。算法类项目提供事实拆分和核验方法，知识工程类项目提供来源、时间和版本建模思路。

| 项目 | 主要方法 | GEOFlow 可吸收的设计 | 采用边界 |
|---|---|---|---|
| [FActScore](https://github.com/shmsw25/FActScore) / [EMNLP 2023](https://aclanthology.org/2023.emnlp-main.741/) | 长文本拆成原子事实，逐条检索并判断支持 | 原子拆分、逐事实证据、聚合评分 | 面向单次回答，缺少持久化标准事实和人工发布 |
| [SAFE / LongFact](https://github.com/google-deepmind/long-form-factuality) / [DeepMind 论文页](https://deepmind.google/research/publications/85420/) | 把事实改写为可独立核验的陈述，多轮搜索证据 | 自包含事实、相关性过滤、证据轨迹 | 外部搜索延迟和成本较高，适合离线评测参考 |
| [RefChecker](https://github.com/amazon-science/RefChecker) / [论文](https://arxiv.org/abs/2405.14486) | 主语、谓词、宾语三元组，三态核验与多种聚合 | 结构化事实、Entailment / Neutral / Contradiction、批量核验 | 通用三元组容易把中文企业描述切得过细 |
| [Ragas Faithfulness](https://docs.ragas.io/en/stable/concepts/metrics/available_metrics/faithfulness/) / [GitHub](https://github.com/vibrantlabsai/ragas) | 回答拆成陈述，再对检索上下文做 NLI 支持判断 | 拆分器和核验器解耦、批量判定、支持率指标 | 上下文缺失需要保留为未知，避免直接计错 |
| [Microsoft GraphRAG](https://github.com/microsoft/graphrag) / [默认数据流](https://microsoft.github.io/graphrag/index/default_dataflow/) | Claims 带主体、类型、状态、时间和来源文本 | 有效时间、来源定位、版本化索引产物 | Claim 提取要求提示词调优，完整运行时成本高；官方仓库当前处于维护模式 |
| [OpenSPG KAG](https://github.com/OpenSPG/KAG) | 知识结构与文本块双向索引，提供数值和语义算子 | 事实与来源双向关联、类型化数值比较 | 完整平台会增加 Python、图谱和运维面 |
| [OpenFactCheck](https://github.com/openfactcheck-research/openfactcheck) / [EMNLP 2024 Demo](https://aclanthology.org/2024.emnlp-demo.23/) | Claim Processor、Retriever、Verifier、Reviser 可组合 | 组件边界、来源元数据、可替换核验器 | v2 仍在开发，代码采用 AGPL-3.0，当前只作设计参考 |

FActScore、SAFE 和 Ragas 验证了逐条事实核验的可行性。RefChecker 的三态协议更适合生产门禁，未知事实需要进入回退检索或人工复核。GraphRAG 与 KAG 说明数字事实要带来源、有效时间和范围。OpenFactCheck 的组件拆分可以映射到 GEOFlow 现有 Laravel 服务，无需引入新运行时。

调研时核对的仓库快照为：FActScore `f28272d`、SAFE `9d27158`、RefChecker `1df1b25`、Ragas `298b682`、GraphRAG `f40e9a2`、KAG `fdab15b`、OpenFactCheck `d72a87f`。

## 目标架构

```text
知识库正文 / 文件
        │
        ▼
分块同步 + chunk_source_hash
        │
        ├──────────────► 现有知识检索与证据链路
        │
        ▼
事实候选生成任务
  固定源哈希 + 结构化输出 + 证据引用
        │
        ▼
事实指标工作副本 + 多期标准值 + 证据快照
        │
        ▼
人工编辑 / 审核 / 冲突处理
        │
        ▼
发布不可变事实库版本 + fact_library_hash
        │
        ├─────────────────────┐
        ▼                     ▼
文章生成事实约束          文章分句与主张角色识别
                              │
                              ▼
                    原子事实召回与适用性判断
                              │
                   ┌──────────┴──────────┐
                   ▼                     ▼
           类型化确定性比较         批量语义核验
                   │                     │
                   └──────────┬──────────┘
                              ▼
         supported / contradicted / not_covered / ambiguous
                              │
             ┌────────────────┴─────────────────┐
             ▼                                  ▼
      原子事实快速结果                 现有检索与模型质检回退
             │                                  │
             └────────────────┬─────────────────┘
                              ▼
                  校验、评分、门禁、审计与灰度
```

在线运行时只读取已发布 revision 的不可变 manifest。工作副本可以继续编辑和生成候选，活动版本在下一次发布前保持不变。

## 数据模型

### 六个持久化实体

| 表 | 职责 | 关键字段 |
|---|---|---|
| `knowledge_fact_libraries` | 每个知识库一条事实库控制记录 | `knowledge_base_id`、`workflow_status`、`serving_status`、`working_version`、`active_revision_id`、`active_hash`、`active_source_hash`、`active_health_json` |
| `knowledge_facts` | 指标定义 | `library_id`、`stable_key`、`label`、`subject`、`predicate`、`value_type`、`locale`、`aliases_json`、`importance`、`usage_scope`、`review_status`、`is_enabled`、`lock_version` |
| `knowledge_fact_values` | 一个指标的一条时间切片标准值 | `fact_id`、`canonical_value_json`、`canonical_answer`、`scope_json`、`valid_from`、`valid_to`、`observed_at`、`comparison_policy_json`、`review_status`、`conflict_status`、`lock_version` |
| `knowledge_fact_evidences` | 标准值与知识分块证据 | `fact_value_id`、`knowledge_chunk_id`、`source_hash`、`content_hash`、`source_locator_json`、`excerpt`、`is_primary` |
| `knowledge_fact_generation_runs` | AI 生成任务与批次状态 | `library_id`、`mode`、`target_count`、`source_hash`、`base_working_version`、`status`、`model_id`、`prompt_version`、`usage_json`、`error_code`、`cancel_requested_at` |
| `knowledge_fact_library_revisions` | 发布时的不可变运行快照 | `library_id`、`version`、`library_hash`、`source_hash`、`manifest_json`、`published_by_admin_id`、`published_at`、`restored_from_revision_id` |

关系如下：

```text
KnowledgeBase 1─1 KnowledgeFactLibrary
KnowledgeFactLibrary 1─N KnowledgeFact
KnowledgeFact 1─N KnowledgeFactValue
KnowledgeFactValue 1─N KnowledgeFactEvidence
KnowledgeFactEvidence N─0..1 KnowledgeChunk
KnowledgeFactLibrary 1─N KnowledgeFactGenerationRun
KnowledgeFactLibrary 1─N KnowledgeFactLibraryRevision
```

### 指标与标准值分离

`stable_key` 标识指标，例如 `company.founded_at`、`company.patents.invention.valid_count`。它在单个事实库内唯一。标准值放在 `knowledge_fact_values`，同一指标可以保存多个互不重叠的时间切片。

```json
{
  "fact": {
    "stable_key": "company.patents.invention.valid_count",
    "label": "有效发明专利数量",
    "subject": "示例公司",
    "predicate": "拥有有效发明专利",
    "value_type": "count",
    "locale": "zh-CN",
    "aliases": ["发明专利数", "有效发明专利数量"],
    "importance": "critical",
    "usage_scope": "quality_and_generation"
  },
  "value": {
    "canonical_value": {
      "value": 268,
      "unit": "件"
    },
    "canonical_answer": "截至 2026 年 6 月 30 日，拥有 268 件中国境内有效发明专利。",
    "observed_at": "2026-06-30",
    "scope": {
      "region": "中国境内",
      "patent_type": "发明专利",
      "status": "有效"
    },
    "comparison_policy": {
      "operator": "equal",
      "tolerance": 0
    }
  }
}
```

成立日期通常只有一个标准值。专利数量、门店数量、营收和员工数量会随时间变化，可以保留多个 `observed_at` 或有效时间区间。文章明确写了历史日期时，比较器选择对应时间切片；文章没有时间信息且指标随时间变化时，结果进入 `ambiguous`，不会用最新值直接判错。

### 数据约束

- `knowledge_fact_libraries.knowledge_base_id` 唯一，知识库删除时级联删除工作数据与发布版本。
- `knowledge_facts` 对 `library_id + stable_key` 建唯一索引。
- `knowledge_fact_values` 对 `fact_id`、有效时间和审核状态建查询索引。时间区间重叠由发布事务中的服务校验，保持 SQLite 与 PostgreSQL 测试兼容。
- `knowledge_fact_evidences.knowledge_chunk_id` 可空并使用 `nullOnDelete`。证据摘录、哈希和来源定位永久保留，chunk 重建后按 `source_hash` 优先、`content_hash + section_path` 次优的顺序重连。
- 所有用户可编辑记录保存 `created_by_admin_id`、`updated_by_admin_id` 和 `lock_version`。
- 发布 manifest 包含指标、标准值、比较规则、证据快照和算法版本。运行时不读取正在编辑的 rows。
- 恢复历史版本时创建一个新的 revision，并写入 `restored_from_revision_id`，历史版本保持不可变。

### 工作流状态与在线状态分开

一个事实库可以同时处于“生成失败”和“旧版本在线可用”。因此控制表使用两个状态字段。

| `workflow_status` | 含义 |
|---|---|
| `idle` | 工作副本与任务空闲 |
| `generating` | AI 正在生成候选 |
| `review_required` | 有未审核候选或人工修改 |
| `publishing` | 正在执行发布事务 |
| `failed` | 最近一次工作流失败，错误详情在 generation run |

| `serving_status` | 在线行为 |
|---|---|
| `unavailable` | 没有活动版本，全量走现有质检 |
| `ready` | 活动版本全部有效，启用快速通道 |
| `partial` | 部分值陈旧或冲突，有效值继续使用，其余回退 |
| `stale` | 没有可安全使用的活动值，全量回退 |

`active_health_json` 保存活动 revision 中每个值的 `valid`、`stale` 或 `conflict` 状态和原因。知识分块同步后重新计算，revision manifest 仍保持不可变。

## 原子事实生成与人工治理

### 生成前置条件

生成只在以下条件满足时启动：

- 知识库 `chunk_sync_status=ready`，并且存在知识分块。
- 请求保存当前 `chunk_source_hash` 和事实库 `working_version`。
- 选中的 AI 模型处于 active chat 状态，并通过结构化输出能力检查。
- `AiUsageQuotaService` 成功预占本次批次调用额度。
- 同一知识库、源哈希和生成模式没有正在运行的同类任务。

生成器使用 Laravel AI SDK `HasStructuredOutput`。知识正文按不可信数据处理，模型没有工具调用权限。输入中的每个证据块使用由后端生成的稳定引用键，模型输出的引用必须精确命中本次输入集合。

AI 调用失败时，任务进入 `failed` 或 `partial`。系统不会用规则模板猜测标准答案。存在旧活动版本时，在线质检继续读取旧版本。

### 自定义数量与批次

用户输入的是“希望事实库拥有的指标总数”。`initial` 模式从空库生成，`supplement` 模式只补足去重后的缺口，`refresh_stale` 模式只生成陈旧值的更新建议。目标数量是上限与期望值，证据不足时允许少生成，run 以 `partial` 完成并解释缺口，不能为了凑数生成低可信事实。

单次请求范围为 1 至 200，界面建议 20 至 50。生成服务按章节和来源类型分层抽样，避免候选集中在知识库开头，并在 run 中记录分块覆盖率、候选数、去重数、冲突数和最终可审核数。每批最多生成 25 个指标，使用现有 `job_batches` 和 `knowledge` 队列。单个 Job 的超时小于 180 秒，继续满足 `job < worker 210 秒 < retry_after 960 秒`。总事实数可以通过多次补充扩展，列表和发布校验必须分页、分块处理。

### 并发、漂移与幂等

- generation run 保存 `source_hash` 与 `base_working_version`。
- 知识库在生成期间发生变化时，完成任务标记为 `obsolete`，候选保留为可查看建议，不自动合并。
- 管理员在生成期间编辑工作副本时，结果只追加新候选；相同稳定键进入冲突列表。
- 编辑和审核请求提交 `expected_lock_version`。版本不一致返回 409，并展示最新值。
- 发布服务对事实库记录执行 `lockForUpdate()`，重新检查源哈希、工作版本、审核状态、时间区间和证据有效性。
- 发布成功后通过 `afterCommit()` 调用一次 `ArticleAiQualityInvalidationService`。草稿编辑不会触发文章重检。
- 生成批次、发布与恢复都使用可重复的 request key，重试不会重复写入事实或 revision。

### 证据重连与陈旧处理

知识分块同步完成后，`KnowledgeFactStalenessService` 按以下顺序处理活动 revision：

1. 使用完全相同的 `source_hash` 重连新 chunk。
2. 来源路径发生轻微变化时，尝试 `content_hash + section_path`。
3. 找不到等价证据时，把对应标准值标记为 `stale`。
4. 重新计算 `serving_status` 和 `active_health_json`。
5. 复用 chunk 同步已有的知识库失效事件，避免新增重复重检任务。

陈旧值可以生成更新建议，AI 不会直接改写活动 revision。人工审核并发布后，新值才进入在线质检。

### 人工管理与审计

知识库详情页增加“原子事实”页签，支持新增、编辑、复制、合并、拆分、停用、批量审核、证据跳转、生成取消和历史恢复。数字字段用结构化表单编辑，界面同时展示自然语言标准答案和时间范围。

`usage_scope` 提供两个首版选项：

- `quality_only`：只用于质检，文章生成不会注入。
- `quality_and_generation`：可以用于质检和文章生成。

生成、取消、新增、编辑、审核、停用、发布和恢复全部写入现有 `AdminActivityLogger`，日志保存目标知识库、事实 ID、revision、变更哈希和管理员，不记录完整敏感正文。

发布事务要求：知识库分块处于 ready、源哈希与审核时一致、全部启用指标和标准值已审核、每个标准值至少有一条有效证据、critical 数字值有主证据、时间区间无重叠、活动范围内没有未处理冲突。任一条件变化都会中止发布并返回可定位的错误。

生成 run 默认保留解析后的候选、用量、模型和错误信息。原始 prompt 与原始 response 默认不长期保存；需要诊断时采用有限保留期并做密钥和敏感字段清理。建议默认保留 90 天，由定时清理命令执行。

## 质检算法

### 主张提取与角色识别

当前 `ArticleFactCandidateExtractor` 以句号、问号、分号和换行切句，并通过数字、日期、资质、引用等模式选择事实候选。原子事实通道在此基础上增加：

- 逗号、转折词和并列词驱动的原子分句，保留原始字符偏移。
- 基于事实主体、谓词和别名的句子扫描，捕获没有显式数字的文本事实。
- `claim_role`：`asserted`、`attributed`、`hypothetical`、`question`、`correction`。
- 否定词、条件词、比较操作符和时间短语提取。

问题句跳过事实结论。引用、假设和纠错语境继续保留原文角色，比较器据此决定是否形成文章事实冲突。包含“错误说法 + 正确说法”的句子会拆成两条子主张，避免把被否定的数字当成作者结论。

### 候选事实召回

召回按成本从低到高执行：

1. 主体、谓词、稳定别名和规范化文本的确定性匹配。
2. 当前本地向量用于语义候选召回，在线路径不请求远程 Embedding。
3. 每条文章主张只保留少量候选事实，进入适用性判断。

语义相似度只负责召回。高相似度无法识别否定、主体交换、历史时间和地域差异，不能单独产生 `supported`。

### 适用性判断

比较标准值前依次检查：

1. 主体是否指向同一实体，简称和别名能否解析。
2. 谓词是否表达同一属性或关系。
3. 文章时间与标准值 `valid_from`、`valid_to`、`observed_at` 是否可比。
4. 地域、产品范围、专利类型、币种等 scope 是否一致。
5. 比较操作符是否一致，例如等于、约等于、至少、至多和区间。

缺少必要时间或范围时返回 `ambiguous`。主体、谓词或范围不对应时返回 `not_covered`。只有适用性检查通过，数字差异才可以成为 `contradicted`。

### 类型化值归一化

`KnowledgeFactValueNormalizer` 首版覆盖：

- 中文数字、阿拉伯数字、千分位和全角字符。
- 万、亿、千、百万等数量级。
- 百分比、比例与百分点。
- 人民币、美元等币种。
- 日期、月份、年份、财年和日期范围的精度。
- “约”“超过”“不少于”“介于”等范围语义。
- 件、人、家、台、套等计数单位。
- 文本枚举、布尔值和列表的顺序无关比较。

数值规范化使用 decimal 字符串或整数最小单位，避免浮点误差。货币先验证币种，首版不做实时汇率换算。百分比与百分点分别建模。人工设置的容差必须存在于活动 revision 的 `comparison_policy`。

### 确定性比较与语义核验

数字、日期、布尔和枚举优先走确定性比较。文本改写先用规范化文本和别名判断，仍有歧义的候选进入一次批量结构化语义核验。

语义核验输出：

| 结果 | 含义 | 后续动作 |
|---|---|---|
| `supported` | 主张与活动标准值一致 | 记录通过，不再检索该事实 |
| `contradicted` | 主体、口径和时间可比，值或关系冲突 | 生成事实冲突问题 |
| `not_covered` | 活动事实库没有可比标准值 | 进入现有证据链路 |
| `ambiguous` | 信息不足、引用角色不清或范围不完整 | 补充检索，仍不确定时映射为当前 `unverified` |
| `stale` / `conflict` | 活动值当前不可安全使用 | 跳过快速通道并回退 |

数字冲突可以覆盖模型给出的文本支持结论，前提是适用性检查已经通过。语义核验把文章和事实行作为结构化数据，禁用工具，并用后端重新校验事实 ID、文章偏移和数值结果，降低提示词注入和输出伪造风险。

### 多知识库冲突

任务绑定多个知识库时，相同主体、谓词、时间和范围的标准值先去重。不同时间切片可以共存。相同时间和范围出现不同值时，系统生成 `knowledge_fact_conflict`，相关主张进入现有证据链路，并在管理端提示管理员处理。

当前 `task_knowledge_bases.sort_order` 继续表示检索顺序，不承担真值裁决。后续如需权威来源优先级，应增加显式的任务级权威规则，并单独评审。

### 预算、缓存与失败行为

- 活动 manifest 按 `fact_library_hash` 缓存，旧版本缓存可以自然过期，不会污染新版本。
- 先完成全部确定性比较，再收集有限数量的歧义对进入一次语义调用。
- Phase 0 校准默认上限，建议初始每篇最多处理 24 条原子主张、最多 16 个语义核验对。
- 主质检剩余时间不足、模型不可用或语义输出无效时，相关主张回到现有质检，原子通道不会返回推测性通过。
- 原子事实服务异常时回到现有质检路径，现有质检和发布门禁继续工作。

首版只增加五个有明确运维用途的 `config/geoflow.php` 配置：

| 配置 | 默认值 | 用途 |
|---|---:|---|
| `knowledge_fact_generation_max_per_run` | 200 | 单次生成目标上限 |
| `knowledge_fact_generation_batch_size` | 25 | 单个模型批次的候选上限 |
| `knowledge_fact_generation_retention_days` | 90 | generation run 诊断数据保留期 |
| `ai_quality_atomic_fact_max_claims` | 24 | 每篇文章进入原子通道的主张上限 |
| `ai_quality_atomic_fact_semantic_pair_limit` | 16 | 单次批量语义核验上限 |

灰度和冻结继续由数据库 rollout 管理，不增加环境变量。

## 与现有系统的集成点

### 质检指纹与审计

`ArticleAiQualityPolicyResolver` 的指纹输入加入每个知识库的活动 revision、库哈希和 serving 状态。`ArticleAiQualityCheck` 复用现有 JSON 字段保存：

- `fact_library_versions`：知识库 ID、revision、库哈希和源哈希。
- `atomic_fact_matches`：文章位置、子句、角色、事实键、标准值 ID、规范化值、比较结果和方法。
- `atomic_fact_metrics`：覆盖率、确定性比较率、语义核验率、回退率、冲突率和陈旧率。
- `algorithm_versions`：分句、角色识别、归一化、比较和聚合版本。

发布、恢复和 serving 状态变化触发质检失效。工作副本编辑不改变指纹。

### 知识分块同步

`KnowledgeChunkSyncService` 完成新 chunks 的事务提交后执行证据重连与 serving 状态更新，再复用当前 `invalidateKnowledgeBase()`。陈旧检测失败时，事实库按 `stale` 回退，chunk 同步结果本身不回滚。

### 影子评测

确定性匹配可以在主质检内以严格时间预算运行。需要模型的影子语义核验通过独立 `EvaluateArticleAtomicFactsShadowJob` 派发到现有 `ai-quality-backfill` 队列，结果写入基线 check 的 `execution_meta.atomic_fact_shadow`。Job 以 `check_id + article_hash + fact_library_hash` 去重，发现 check 已被替代、文章已变化或任务超过最大队列年龄时直接结束。影子任务失败不会延长主质检时间，也不会改变分数和门禁。

### AI 自动优化

现有 `ArticleAiOptimizationCoordinator` 已把 `knowledge_contradiction` 和 `unsupported_claim` 分派到知识修复链路。原子事实结果继续使用这些稳定问题代码，并在 issue 元数据中补充事实库 revision、事实和值 ID、文章原句与偏移、类型化标准值、比较策略和证据引用，供优化预览、补丁校验和复检共同使用。

数字冲突只有同时满足主体、谓词、时间、范围和运算符适用性完整，活动值健康，比较策略为严格匹配，且正文中存在唯一局部修改位置时，才允许自动生成替换补丁。`ambiguous`、`stale`、`conflict` 和多处候选位置继续交给人工处理或现有证据链路，避免优化器把不确定判断写回文章。

优化 run 固定使用 `source_check_id` 对应的事实库 revision。应用补丁或候选复检前，协调器重新验证文章哈希和当前活动事实库指纹；任一条件变化时，将旧候选交给现有 reconciliation 机制处理，不应用过期标准值。候选文章通过当前活动指纹下的新质检后，才能成为 `best_check_id` 或 `final_check_id`。

### 文章生成

Phase 5 在 `ArticleContentPromptRenderer` 增加 `canonical_facts`。只选择 `usage_scope=quality_and_generation`、当前有效且与标题和关键词相关的事实。模型仍可使用原有知识上下文处理事实库未覆盖内容；涉及已发布指标时使用标准值，缺少标准值的数字主张应省略或转入待确认流程。

生成记录保存事实库 revision 和实际注入的事实值 ID，后续质检可以复用同一快照。

## 管理端接口与界面

路由全部放在现有 `admin.knowledge-bases.*` 命名空间下，通过 route helper 生成 URL，不硬编码 `/admin` 前缀。嵌套资源查询必须同时限制 `knowledgeBaseId`、library、fact 和 value 的归属。

| 路由名 | 方法 | 用途 |
|---|---|---|
| `admin.knowledge-bases.facts.index` | GET | 事实列表、状态、冲突和发布历史 |
| `admin.knowledge-bases.facts.store` | POST | 人工新增指标与首个标准值 |
| `admin.knowledge-bases.facts.update` | PUT | 编辑指标、别名、重要级别和使用范围 |
| `admin.knowledge-bases.facts.values.store` | POST | 为指标新增一个时间切片标准值 |
| `admin.knowledge-bases.facts.values.update` | PUT | 编辑标准值、时间、范围、比较规则和证据 |
| `admin.knowledge-bases.facts.values.archive` | POST | 停用工作副本中的标准值 |
| `admin.knowledge-bases.facts.merge` | POST | 合并重复指标并保留来源 |
| `admin.knowledge-bases.facts.split` | POST | 把混合指标拆为多个独立指标 |
| `admin.knowledge-bases.facts.review` | POST | 审核或退回 |
| `admin.knowledge-bases.facts.archive` | POST | 停用工作事实 |
| `admin.knowledge-bases.facts.generate` | POST | 创建生成任务，提交模式和目标数量 |
| `admin.knowledge-bases.facts.runs.show` | GET | 查询生成进度、批次、用量和错误 |
| `admin.knowledge-bases.facts.runs.cancel` | POST | 请求取消未完成批次 |
| `admin.knowledge-bases.facts.publish` | POST | 发布不可变 revision |
| `admin.knowledge-bases.facts.revisions.restore` | POST | 从历史 revision 创建恢复版本 |

系统知识库继续沿用 `canManageProtectedWorkflows()`。普通知识库使用当前 Admin 权限边界。所有写请求采用 Form Request、CSRF 和 `$request->validated()`，过期 `lock_version` 返回 409。

界面包含状态卡、事实指标表、多期标准值编辑器、证据预览、生成面板、冲突面板和发布历史。移动端支持查看、审核与轻量编辑；批量生成、冲突处理和发布以桌面端为主。空库、生成中、旧版继续服务、部分陈旧、全部陈旧和失败状态都有独立说明。

## 分阶段实施计划

六个阶段都能单独合并。生产功能开关初始保持关闭。

### Phase 0：确定协议与评测基线

**用户价值**：固定判断口径，避免实现完成后再争论时间、范围、引用和未知事实该如何评分。

工作内容：

- 将现有 6 条框架样本扩展为 240 条脱敏确定性基线：60 条通用质检样本与 180 条原子事实专项样本；校准、回归、盲测分别为 120、60、60 条。
- 新增至少 180 条专项样本：正确改写 40、数字单位与范围 50、历史时间 30、否定引用与纠错 25、来源冲突与陈旧 20、事实库外主张 15。
- 新增至少 250 条确定性归一化与比较单元样例。
- 固定事实 schema、四态协议、`claim_role`、适用性规则、时间切片规则和人工标注手册。
- 扩展 `geoflow:evaluate-ai-quality`，输出点估计、95% 置信区间、Token、延迟和回退数据。

主要文件：

- `tests/Fixtures/ai-quality/atomic-facts-golden-v1.json`
- `docs/reports/ai-quality-atomic-facts-baseline-v1.md`
- `docs/ai-quality-inspection-runbook.md`
- `app/Console/Commands/EvaluateArticleAiQualityCommand.php`
- 对应 Unit 与 Feature 测试

验收：数据集可重复执行，当前 v2 形成基线，协议可以覆盖多期事实、缺失时间、引用纠错和多知识库冲突。

### Phase 1：手工事实库与版本发布

**用户价值**：管理员可以手工建立、编辑、审核和发布事实库，在线质检行为保持不变。

工作内容：

- 新增事实库、指标、标准值、证据和 revision 五张表及对应模型、关系、索引和约束。
- 实现工作副本、乐观锁、发布事务、不可变 manifest、恢复新 revision、证据重连和双状态机。
- 增加管理端路由、Form Request、控制器、详情页签、编辑器、活动日志和多语言文案。
- 知识分块同步后更新活动 revision 健康状态。

主要文件：

- `database/migrations/*_create_knowledge_fact_libraries_table.php`
- `database/migrations/*_create_knowledge_facts_table.php`
- `database/migrations/*_create_knowledge_fact_values_table.php`
- `database/migrations/*_create_knowledge_fact_evidences_table.php`
- `database/migrations/*_create_knowledge_fact_library_revisions_table.php`
- `app/Models/KnowledgeFactLibrary.php`
- `app/Models/KnowledgeFact.php`
- `app/Models/KnowledgeFactValue.php`
- `app/Models/KnowledgeFactEvidence.php`
- `app/Models/KnowledgeFactLibraryRevision.php`
- `app/Services/GeoFlow/KnowledgeFactLibraryService.php`
- `app/Services/GeoFlow/KnowledgeFactPublicationService.php`
- `app/Services/GeoFlow/KnowledgeFactStalenessService.php`
- `app/Http/Controllers/Admin/KnowledgeFactController.php`
- `app/Http/Requests/Admin/KnowledgeFact*.php`
- `resources/views/admin/knowledge-bases/_atomic-facts.blade.php`
- `resources/js/admin/knowledge-facts.js`
- `routes/web.php`、`resources/js/app.js`、`lang/*/admin.php`

验收：工作副本与活动版本隔离；时间切片不重叠；发布、恢复、冲突、并发编辑、chunk 重建重连和陈旧回退全部有测试。

回滚：不创建或不发布事实库时，产品行为保持当前状态。表保留，不接入在线质检。

### Phase 2：AI 生成候选与人工治理

**用户价值**：用户可以输入目标数量，让系统从知识库生成事实候选，再人工修改和发布。

工作内容：

- 新增 generation runs 表与模型，以及结构化生成 Agent、批次 Job、完成 Job、取消、幂等、源漂移检测和运行记录清理。
- 复用模型结构化输出 readiness、智能 failover 和 `AiUsageQuotaService`。
- 验证证据引用、类型化值、时间范围、去重、冲突和绝对化风险。
- 生成结果只进入工作副本，过期任务进入 `obsolete`。

主要文件：

- `app/Ai/Agents/KnowledgeFactDraftGeneratorAgent.php`
- `database/migrations/*_create_knowledge_fact_generation_runs_table.php`
- `app/Models/KnowledgeFactGenerationRun.php`
- `app/Jobs/GenerateKnowledgeFactBatchJob.php`
- `app/Jobs/FinalizeKnowledgeFactGenerationJob.php`
- `app/Services/GeoFlow/KnowledgeFactGenerationService.php`
- `app/Services/GeoFlow/KnowledgeFactValidationService.php`
- `app/Console/Commands/PruneKnowledgeFactGenerationRunsCommand.php`
- `config/geoflow.php`、`routes/console.php`
- 管理端生成面板与对应测试

验收：1 至 200 的目标数量能分批处理；无效引用不能入库；额度、取消、部分失败、知识变化和管理员并发编辑都有确定结果；AI 失败不会生成猜测标准值。

回滚：停用生成入口，手工事实库继续可用。

### Phase 3：异步影子质检

**用户价值**：管理端可以看到原子事实命中和差异，文章分数与发布门禁仍由当前 v2 决定。

工作内容：

- 实现分句、角色识别、事实召回、适用性判断、归一化、确定性比较和批量语义核验。
- 确定性影子结果受严格时间预算限制；语义影子 Job 进入 `ai-quality-backfill`。
- 指纹加入事实库 revision，质检详情展示原句、角色、标准值、时间范围、方法和回退原因。
- 采集覆盖率、准确率、Token、延迟、缓存、冲突和陈旧数据。

主要文件：

- `app/Services/GeoFlow/KnowledgeFactIndexCompiler.php`
- `app/Services/GeoFlow/ArticleAtomicClaimExtractor.php`
- `app/Services/GeoFlow/KnowledgeFactMatcher.php`
- `app/Services/GeoFlow/KnowledgeFactApplicability.php`
- `app/Services/GeoFlow/KnowledgeFactValueNormalizer.php`
- `app/Services/GeoFlow/KnowledgeFactComparator.php`
- `app/Services/GeoFlow/KnowledgeFactSemanticVerifier.php`
- `app/Ai/Agents/KnowledgeFactVerifierAgent.php`
- `app/Jobs/EvaluateArticleAtomicFactsShadowJob.php`
- `app/Services/GeoFlow/ArticleAiQualityInspectionService.php`
- `app/Services/GeoFlow/ArticleAiQualityPolicyResolver.php`
- 质检详情界面与测试

验收：影子失败不影响主质检；文章偏移和事实版本可追溯；空库、部分陈旧、历史事实、引用纠错、提示词注入和多知识库冲突正确回退。

回滚：停止影子派发，现有质检不受影响。

### Phase 4：混合快速通道与灰度门禁

**用户价值**：已覆盖事实减少证据检索和长上下文核验，明确数字冲突进入正式质检结果。

工作内容：

- `supported` 原子事实不再占用后续证据预算。
- 适用性完整的 `contradicted` 进入 v2 校验器和评分器，关键数字冲突进入硬门禁。
- `not_covered`、`ambiguous`、陈旧和冲突事实走当前证据链路。
- 在 `ArticleAiQualityRollout` 增加 `atomic_fact_shadow_percent`、`atomic_fact_percent` 和 `atomic_fact_frozen`，复用 0、10、25、50、100 灰度。
- 扩展 `ManageArticleAiQualityRolloutCommand` 的 track，支持 `atomic-shadow` 和 `atomic-fact`，冻结操作可以只影响原子事实通道。
- 健康检查增加活动 revision 读取、缓存命中、回退和比较异常。
- 自动优化链路读取原子事实 issue 元数据，只对适用性完整且修改位置唯一的数字冲突生成补丁。
- 应用补丁和候选复检前校验文章哈希、来源 check 与事实库 revision，变化后进入 reconciliation，不使用过期标准值。

主要文件：

- `app/Services/GeoFlow/ArticleAiQualityEvidenceBuilder.php`
- `app/Services/GeoFlow/ArticleAiQualityInspectionService.php`
- `app/Services/GeoFlow/ArticleAiQualityResultValidator.php`
- `app/Services/GeoFlow/ArticleAiQualityScorerV2.php`
- `app/Services/GeoFlow/ArticleAiQualityRolloutPolicy.php`
- `app/Services/GeoFlow/ArticleAiQualityHealthService.php`
- `app/Services/GeoFlow/ArticleAiOptimizationCoordinator.php`
- `app/Services/GeoFlow/ArticleAiOptimizationPatchValidator.php`
- `app/Services/GeoFlow/ArticleAiOptimizationReconciliationService.php`
- `app/Console/Commands/ManageArticleAiQualityRolloutCommand.php`
- `app/Models/ArticleAiQualityRollout.php`
- rollout 增量迁移、管理命令、运行手册和测试
- `tests/Feature/ArticleAiOptimizationCoordinatorTest.php`
- `tests/Feature/ArticleAiOptimizationPatchValidatorTest.php`

验收：灰度为 0 时结果与当前 v2 一致；开启后只使用活动且健康的值；独立冻结后立即回到当前证据链路；全局 `frozen` 仍拥有最高优先级；自动优化只修复适用性完整的唯一数字冲突，事实 revision 或文章哈希变化时不应用旧补丁。

回滚：设置 `atomic_fact_percent=0` 或 `atomic_fact_frozen=true`，无需回滚数据库。

### Phase 5：文章生成事实约束

**用户价值**：任务批量生成与单篇生成使用已发布标准事实，减少错误数字进入初稿。

工作内容：

- `ArticleContentPromptRenderer` 增加 `canonical_facts` 上下文。
- 只注入 `quality_and_generation` 且当前健康的值。
- 保存生成使用的 revision 和事实值 ID，质检优先复用同一快照。
- 缺少事实库、事实陈旧或冲突时使用现有生成路径。

主要文件：

- `app/Services/GeoFlow/ArticleContentPromptRenderer.php`
- 文章生成协调服务与现有生成快照
- 文章生成提示词、运行手册和测试

验收：生成结果能追溯到事实版本；历史值不会被最新值覆盖；无事实库任务保持当前行为；专项数字篡改样本能被质检拦截。

回滚：关闭生成事实注入，质检原子通道可以继续使用。

## 质量、性能与成本门槛

### 质量硬门槛

| 指标 | 门槛 |
|---|---|
| 至少 250 条确定性归一化与比较样例 | 100% 通过 |
| 当前 240 条基线整体质量 | 点估计与 95% 置信区间均无显著退化 |
| 关键数字冲突端到端召回 | 点估计 ≥ 99%，95% 置信区间下界 ≥ 95% |
| 已覆盖事实的支持判断精确率 | 点估计 ≥ 98%，95% 置信区间下界 ≥ 95% |
| 安全文章误拦截率 | 点估计 ≤ 2%，95% 置信区间上界 ≤ 5% |
| 人工复核一致性 Cohen's Kappa | ≥ 0.75 |
| 空库、陈旧、冲突、超时和模型失败回退 | 100% |
| 提示词注入与伪造事实 ID 专项测试 | 100% 拒绝或回退 |

### 性能目标

| 指标 | 目标 |
|---|---|
| 原子事实覆盖率 ≥ 60% 的文章，输入 Token 中位数 | 比当前链路降低 ≥ 40% |
| 同类文章 P95 质检延迟 | 比当前链路降低 ≥ 25%，并保持当前绝对 P95 目标 |
| 影子模式主质检 P95 | 不出现可统计的退化 |
| 确定性比较缓存命中率 | 灰度后按知识库稳定在可接受区间，具体门槛由 Phase 3 数据确定 |

质量硬门槛决定 Phase 4 能否启用。性能目标决定快速通道是否实现了用户期望的效率收益，未达到时继续影子优化。

成本报告分开计算一次性事实生成、人工审核时长和在线质检 Token。事实生成成本按每个知识库后续 10、50、100 篇文章三个使用量摊销，避免用单篇在线成本掩盖前置治理成本。

在线指标包括：`atomic_fact_coverage`、`deterministic_compare_rate`、`semantic_verify_rate`、`fallback_rate`、`numeric_conflict_count`、`stale_value_rate`、`fact_cache_hit_rate`、影子队列等待时间、原子通道 Token 和耗时。

## 测试与验证

实现阶段按聚焦测试、路由与构建、全量测试执行：

```bash
php artisan test --compact tests/Feature/KnowledgeFactLibraryTest.php
php artisan test --compact tests/Feature/KnowledgeFactGenerationTest.php
php artisan test --compact tests/Feature/KnowledgeFactEvidenceRelinkTest.php
php artisan test --compact tests/Feature/KnowledgeFactConcurrencyTest.php
php artisan test --compact tests/Feature/ArticleAiQualityAtomicFactTest.php
php artisan test --compact tests/Unit/KnowledgeFactValueNormalizerTest.php
php artisan test --compact tests/Unit/KnowledgeFactTemporalComparatorTest.php
php artisan test --compact tests/Unit/KnowledgeFactPromptInjectionTest.php
php artisan test --compact tests/Unit/ArticleAiQualityFingerprintTest.php
php artisan geoflow:evaluate-ai-quality --dataset=tests/Fixtures/ai-quality/atomic-facts-golden-v1.json
php artisan route:list --json --path=knowledge-bases --except-vendor
node --test tests/JavaScript/knowledge-facts.test.js
vendor/bin/pint --dirty
npm run build
php artisan test --compact
```

测试矩阵覆盖：权限与 CSRF、系统知识库保护、嵌套资源越权、乐观锁冲突、生成幂等、结构化输出失败、额度耗尽、取消、源版本漂移、证据引用伪造、chunk 删除重建与重连、多期值和区间重叠、百分比与百分点、币种、日期精度、历史陈述、否定、引用、假设、纠错、同一事实多次出现、多知识库冲突、提示词注入、空库回退、影子异步失败、灰度冻结、指纹失效、队列超时链、缓存失效和数据清理。

本项目同时支持 SQLite 测试和 PostgreSQL 生产。迁移与约束需要在两种数据库上验证，时间区间重叠校验放在领域服务与事务锁中，避免依赖 PostgreSQL 专属约束造成测试行为分叉。

## 迁移、发布、运维与回滚

数据库变更以新增表和带安全默认值的 rollout 字段为主，不需要历史回填。部署后所有知识库的事实库状态为 `unavailable`，文章生成和质检保持当前行为。

发布顺序：Phase 0 协议与基线，Phase 1 手工治理，Phase 2 AI 生成，Phase 3 全量影子，Phase 4 分级灰度，Phase 5 生成约束。首轮选择两个事实密集且更新频率不同的知识库，一个稳定企业介绍库，一个定期更新数量指标的业务库。

Phase 4 每次提升灰度前至少观察 7 天并积累 500 篇符合条件的文章，样本不足时延长观察期。评测分别查看企业介绍、产品资料、政策规则和案例库。10% 阶段先限制到选定知识库，25% 以后再扩大文章样本。

紧急回滚通过 `php artisan geoflow:ai-quality-rollout freeze --track=atomic-fact` 或 `php artisan geoflow:ai-quality-rollout rollback --track=atomic-fact --to=0` 调整数据库 rollout。配置回退值固定为 0，数据库不可用时自动关闭原子事实执行。事实表和 revisions 保留，恢复后继续使用。生产数据库采用前向修复迁移，不执行逆向删表。

需要补充的运行手册内容：

- 事实库生成、发布、恢复和取消操作。
- serving 状态、陈旧原因和证据重连排查。
- Horizon `knowledge` 与 `ai-quality-backfill` 队列容量观察。
- 原子事实灰度、冻结、评测报告和事故代码。
- generation run 清理、审计保留和敏感数据处理。

## 风险清单与控制措施

| 风险 | 触发场景 | 控制措施 |
|---|---|---|
| 历史事实被最新值误判 | 专利、门店、员工等随时间变化 | 指标和值分离，多期时间切片，缺时间时返回 ambiguous |
| chunk ID 重建导致证据断裂 | 任意知识库重新分块 | nullable FK、证据快照、哈希重连、陈旧回退 |
| 引用或纠错句误判 | 文章同时出现错误说法和正确答案 | 原子分句、claim role、否定与纠错识别 |
| AI 生成伪造来源 | 模型输出不存在的证据键 | 输入引用白名单、后端精确校验、人工审核 |
| 提示词注入 | 知识正文或文章包含指令文本 | 数据与指令隔离、无工具、结构化输出、后端复核 |
| 生成覆盖人工编辑 | 长任务期间管理员修改事实 | working version、lock version、obsolete 状态、只追加候选 |
| 多知识库互相冲突 | 相同时间与口径出现不同值 | 显式 conflict、停止硬门禁、回退现有证据链路 |
| 影子评测拖慢主质检 | 语义核验调用耗时 | 语义影子任务异步进入 backfill 队列 |
| 自动优化写入过期标准值 | 质检后文章或活动事实 revision 已变化 | run 固定来源 revision，应用前复核双哈希，变化后进入 reconciliation |
| 事实库长期失修 | 来源已更新，活动值过期 | chunk 同步后健康检查、partial/stale 状态、更新建议 |
| 前置治理成本高于收益 | 低复用知识库或文章量小 | 覆盖率与 10/50/100 篇摊销报告，按知识库选择启用 |
| 敏感事实被用于生成 | 内部知识只应质检 | `usage_scope=quality_only`，活动日志不保存完整正文 |

## 待确认的最终决策

建议批准 Phase 0、Phase 1 和 Phase 2 的总体方向。Phase 3 可以同步设计接口，合并和上线保持独立。Phase 4 必须通过质量硬门槛和影子数据审核。Phase 5 在混合质检稳定后启用。

确认后建议先完成 Phase 0 的协议与数据集，再实现 Phase 1 的手工事实库。这个顺序能尽早验证多期事实、证据重连和并发发布三项基础设计，AI 生成和在线质检都依赖这些约束。
