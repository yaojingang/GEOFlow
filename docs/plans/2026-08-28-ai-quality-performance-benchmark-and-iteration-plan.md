# GEOFlow AI 质检性能、质量与稳定性对标及迭代方案

> 状态：已按最终评审版完成代码升级，生产门禁仍保持 v1
> 初稿日期：2026-08-28
> Review 日期：2026-08-28
> 实施日期：2026-08-29
> 证据范围：当前 GEOFlow 工作区、质检记录 #5、队列运行日志、模型配置、现有测试，以及 Promptfoo、DeepEval、Ragas 的仓库与关键实现
> 本文用途：记录已确认的目标、边界、接口、迁移、验收和发布顺序。实施结果见 `docs/reviews/2026-08-29-ai-quality-fast-v2-implementation-report.md`。

## 一、结论摘要

当前单篇文章质检耗时过长，主因已经定位：输入规模被事实候选和证据检索成倍放大，模型调用允许 180 秒超时和 8192 个输出 Token，首次超时后又进入 60 秒固定退避。质检记录 #5 因此历时 7 分 05 秒，其中两次模型调用分别接近 3 分钟。

“数据与引文”过严的核心原因是引文编号缺少稳定语义。文章生成时写入的 `[K#]` 与质检时重新检索后生成的 `[K#]` 属于两套临时编号。当前系统直接比较两套编号，产生 4 条同根的中风险问题，再按每条 6 分独立累计扣分，使该维度从 25 分降到 1 分。

“内容完整性”发现的摘要残留标记和描述截断基本成立，扣分尺度与维度总分不匹配。该维度满分只有 10 分，中风险扣 6 分、低风险扣 2 分，一组局部问题即可损失 80%。

建议采用“确定性预检、物质性主张抽取、稳定证据映射、一次紧凑语义判断、后端校验与校准评分”的快速路径。正文不超过 5000 字的常规中文文章，端到端成功质检 P95 目标为 55 秒。服务为每次在线检查设置 60 秒业务截止时间，供应商调用超时、输出无效等可控错误必须在截止时间内写入终态。

当前工作区已经具备文章质检状态接口、2 秒轮询、动态进度条、分段记录、输入指纹、活动记录去重、分段续跑和定时对账。最终方案保留这些能力，重点补齐真实阶段耗时、截止时间、错误分类和队列隔离。初稿中“新增状态接口与前端轮询”的描述已经修正。

GitHub 对标选择 [Promptfoo](https://github.com/promptfoo/promptfoo)、[DeepEval](https://github.com/confident-ai/deepeval) 和 [Ragas](https://github.com/vibrantlabsai/ragas)。截至调研日，三者分别约有 24.6k、17.9k 和 15.5k Stars。选择依据包含社区影响力、维护活跃度、与 GEOFlow 在线内容质检的迁移相关性。OpenAI Evals、Langfuse、MLflow、Opik 也进入候选审阅；前三个入选项目在混合断言、事实忠实度、裁判校准、缓存和并发控制方面更贴合当前问题。

## 二、目标、适用范围与非目标

### 2.1 目标

1. 常规中文文章，正文不超过 5000 字时，端到端成功质检 P50 不超过 25 秒，P95 不超过 55 秒。
2. 供应商超时、限流、网关错误和输出无效等可控错误，在任务开始后 60 秒内进入终态。
3. 显著降低数据与引文、内容完整性维度的误判和重复扣分。
4. 保留对真实数据错误、伪造来源、广告法高风险表达和知识库冲突的高召回。
5. 建立可复现评测集、阶段耗时、模型稳定性与版本对照能力。
6. 后台与 API 可以获得进度和最终结果，失败时展示可操作原因。
7. 评分算法升级和知识变更不会形成无界重检洪峰，也不会挤占人工重检和新文章质检。

### 2.2 适用范围

- 后台文章发布前质检与人工重检。
- 四个现有维度：知识一致性、数据与引文、广告合规、内容完整性。
- 知识库检索、模型评审、后端校验、评分决策、队列和编辑页状态展示。
- 后台状态接口、API 状态契约、CLI 状态查询和 Horizon 队列配置。
- 中文文章为第一阶段校准对象。

### 2.3 本轮非目标

- 模型微调或训练自有裁判模型。
- 自动改写文章正文。
- 放宽已确认的法律风险、重大事实错误或伪造来源拦截。
- 重构整个知识库和文章生成系统。
- 第一阶段覆盖多语言评分标尺。
- 在线抓取文章中的任意外部引用 URL。外部链接只记录“已声明来源”，未进入受管知识库时进入人工确认。
- 在这一轮引入 Promptfoo、DeepEval 或 Ragas 运行时依赖。三个项目只提供设计参考。

## 三、当前基线与问题复盘

### 3.1 真实案例基线

本次以文章 #501、质检记录 #5 为主样本。文章标题为“品牌在 Kimi、元宝、千问中表现不一怎么办？跨平台 GEO 优化方法”，正文约 1648 字。

| 指标 | 当前观测 |
| --- | ---: |
| 创建与开始时间 | 22:15:08 |
| 首次模型调用失败 | 22:18:15 |
| 第二次开始 | 22:19:15 |
| 最终完成 | 22:22:13 |
| 端到端耗时 | 7 分 05 秒 |
| 首次尝试 | 约 3 分 06 秒，超时失败 |
| 固定退避 | 60 秒 |
| 第二次尝试 | 约 2 分 57 秒，成功 |
| 输入 Token | 19,607 |
| 输出 Token | 11,710 |
| 最终得分 | 68 |
| 最终决策 | `blocked` |

本次任务在入队后立即被消费，队列等待占比很低。模型请求、超时和重试策略占据主要时间。

### 3.2 输入膨胀

质检服务将 1648 字正文扩展成约 3.36 万字符的直接输入组件，模型侧统计达到 19,607 个输入 Token。

| 输入组件 | 大小或数量 |
| --- | ---: |
| 标题 | 32 字符 |
| 摘要 | 180 字符 |
| 正文 | 1648 字符 |
| 元描述 | 120 字符 |
| 提示模板 | 3679 字符 |
| 事实候选 | 35 条，JSON 约 11,259 字符 |
| 证据 | 9 条，JSON 约 13,601 字符 |
| 规则 | 17 条，JSON 约 5041 字符 |
| 模型最终 JSON | 约 3345 字符 |

模型允许输出 8192 Token。最终 JSON 体积远小于模型侧报告的 11,710 输出 Token，推测供应商统计包含推理 Token 或额外生成过程。当前遥测未拆分推理与可见输出，因此该项需要在改造中补齐。

### 3.3 事实候选误扩张

`ArticleFactCandidateExtractor` 对标题、摘要、正文、关键词和元描述逐字段扫描，并用通用数字表达式识别数据主张。旧文章里的 `[K1]`、`[K2]` 等内部引文标记包含数字，因此被识别为数值事实。

- 35 条候选中有 30 条类型为 `number`。
- 30 条数值候选中有 24 条带 `[K#]` 标记。
- 另外 5 条因“保证”等词被归为高风险保证类，其中包含普通建议或问句。
- 相同内容会在摘要、元描述和正文中重复形成候选。
- 先清理内部标记再抽取，候选数可从 35 条降到 11 条。
- 对候选文本归一化去重后，唯一主张约 10 条。

候选扩张随后触发 1 次通用检索和 35 次逐候选检索。检索结果最终只保留 9 条证据，说明大部分查询没有带来新的有效证据，却增加了数据库、向量计算、排序和提示体积。

### 3.4 模型预算与重试策略

当前在线路径的关键配置为：

- 单次模型超时 180 秒。
- 默认最大输出 8192 Token。
- 队列任务最多尝试 3 次。
- 固定退避为 60、300、900 秒。
- 先尝试结构化输出，失败后再尝试 JSON 回退。
- 当结构化调用耗尽 180 秒预算时，JSON 回退已经没有可用时间。
- 出站安全层将底层超时包装成通用异常，最终错误码为 `inspection_failed`，重试层无法准确区分超时、限流、配额和不可重试错误。

现有模型就绪探测约 6.5 秒，只验证普通与流式调用。质量质检使用的结构化输出能力没有形成独立的已验证能力档案。

### 3.5 “数据与引文”过严的原因

文章生成阶段使用可见的 `[K#]` 标记，质检阶段重新检索知识库并按照新结果顺序再次分配 K 编号。K 编号只代表当次检索数组位置，缺少稳定来源身份。

质检记录 #5 的 4 条 `citation_scope_mismatch` 均来自这一编号错配。模型同时指出新证据集合里存在相关支持内容，说明主要冲突发生在编号空间，未形成 4 个独立的语义事实错误。

当前评分器对全维度使用统一扣分：

| 严重度 | 每条扣分 |
| --- | ---: |
| critical | 20 |
| high | 12 |
| medium | 6 |
| low | 2 |

数据与引文满分 25。4 条同根 `medium` 问题累计扣 24 分，最终只剩 1 分。去重键包含问题代码、字段、原文片段和知识引用，同一根因在不同段落出现时仍会重复计分。

当前工作区已经加入 `ArticleCitationMarkerCleaner` 和清理历史内部标记的迁移，文章生成提示也开始禁止输出 K 标记。这项修复可以消除公开正文里的临时编号。生成阶段仍未持久化“主张对应哪条知识库记录和分块”的稳定映射，因此质检侧还需要旁路溯源数据来完成语义闭环。

### 3.6 “内容完整性”过严的原因

该案例包含一条摘要残留 K 标记并出现不完整句子的中风险问题，以及一条元描述截断的低风险问题。问题本身具有修订价值，当前统一扣分表使 10 分维度一次损失 8 分。

完整性维度需要采用维度内标尺和类别上限。SEO 摘要截断、内部标记残留、模板占位符、正文结构断裂、伪造来源具有不同的业务影响，应当分别处理。

### 3.7 页面进度已有修复，运行阶段仍缺少真实时间信息

截图对应的历史状态只能显示“质检中”。当前工作区已经增加以下能力：

- `GET admin/articles/{articleId}/ai-quality/status` 返回最新质检状态。
- `ArticleAiQualityProgressPresenter` 输出 queued、evidence、inspecting、summarizing 和终态进度。
- `article-ai-quality-progress.js` 默认每 2 秒轮询，终态后刷新页面。
- 前端对会话失效、接口 404、轮询超时和模块加载失败都有降级提示。
- 功能测试和 JavaScript 测试覆盖活动状态、终态刷新与错误脱敏。

现有进度百分比由状态和已完成分段推算。单次模型调用接近 3 分钟时，页面会长期停在 evidence 或 inspecting，用户看不到已用时间、剩余业务预算和具体错误类型。API 提供异步重检入口，目前缺少对应的轻量状态查询接口；CLI 也只能发起重检或人工放行。

### 3.8 当前已经具备的基础与本轮缺口

| 能力 | 当前状态 | 本轮处理 |
| --- | --- | --- |
| 状态接口与前端轮询 | 已具备 | 保留，增加已用时间、截止时间和安全错误码 |
| 输入指纹与活动去重 | 已具备 | 延续现有字段，补充 v2 版本和缓存边界 |
| 相同输入自动复用 | 自动路径已具备，人工重检强制新建 | 保持现有产品语义 |
| 长文分段与失败分段续跑 | 已具备，当前逐段串行 | 常规文章维持单段，长文章采用分级目标 |
| 定时对账与异常恢复 | 已具备，默认每 5 分钟 | 调整恢复时限和回填节流 |
| 质检进度 | 已具备推算进度 | 增加真实阶段计时和业务截止时间 |
| 引文标记清理 | 已具备 | 质检抽取前复用，评分不再解释临时 K 编号 |
| 稳定证据身份 | 证据已有 chunk、内容哈希，模型仍主要引用 K 编号 | 改用稳定来源键，K 编号只用于展示 |
| 模型故障切换 | 已具备候选遍历 | 增加共享截止时间、能力健康和剩余预算判断 |
| 错误分类 | 依赖异常消息文本 | 改为异常类型、HTTP 状态和供应商错误码映射 |
| 质量校准集 | 缺失 | 新增脱敏黄金集、隔离测试集和离线评测命令 |
| 队列隔离 | 与生成、标题等任务共享 `geoflow` | 新增质检前台与回填优先级，避免互相阻塞 |

## 四、GitHub 对标选择方法

### 4.1 筛选口径

候选项目按以下条件审阅：

1. 直接面向 LLM、RAG 或智能体的质量评测。
2. GitHub 社区影响力高，或维护方具有行业权威性。
3. 仓库持续维护，核心实现和文档可以核验。
4. 能为在线文章质检提供可迁移方法，重点关注事实忠实度、裁判评分、性能、缓存、重试和稳定性。

Star 数据为 2026-08-28 调研快照，后续会自然变化。

### 4.2 候选概览

| 项目 | 约 Stars | 定位 | 与 GEOFlow 的直接相关性 | 结论 |
| --- | ---: | --- | --- | --- |
| [Promptfoo](https://github.com/promptfoo/promptfoo) | 24.6k | LLM 测试、评测与红队 | 混合断言、阈值、缓存、调度、延迟门禁 | 入选 |
| [DeepEval](https://github.com/confident-ai/deepeval) | 17.9k | LLM 专用测试框架 | 忠实度、G-Eval、并发与裁判解释 | 入选 |
| [Ragas](https://github.com/vibrantlabsai/ragas) | 15.5k | RAG 评测框架 | 原子主张、证据支持率、裁判校准 | 入选 |
| [OpenAI Evals](https://github.com/openai/evals) | 19.3k | 模型与系统基准注册表 | 权威，偏离线基准与注册表 | 参考候选 |
| [Langfuse](https://github.com/langfuse/langfuse) | 33k+ | LLM 可观测平台 | 范围较广，适合后续观测平台化 | 参考候选 |
| [MLflow](https://github.com/mlflow/mlflow) | 27k+ | ML 与生成式 AI 生命周期平台 | 范围较广，在线文章门禁迁移成本高 | 参考候选 |
| [Opik](https://github.com/comet-ml/opik) | 21k+ | LLM 评测与可观测平台 | 适合平台化，核心质检算法聚焦度较低 | 参考候选 |

主对标项目按“高社区影响力与高迁移相关性”的交集确定。OpenAI Evals 继续作为权威基准设计参考，当前三项实施主线可以从入选项目获得更直接的代码级借鉴。

## 五、三个项目的重点学习结论

### 5.1 Promptfoo：混合断言、延迟门禁与自适应调度

Promptfoo 将确定性断言和模型评分断言放在同一套评测配置里，支持阈值、权重和断言集合。延迟、成本、JSON 合法性可以直接成为评测条件。其调度层只对限流、瞬态网络错误、超时和特定网关错误执行重试，同时通过指数退避、抖动和供应商级并发调整减少拥塞。缓存默认启用，并按输入和命名空间隔离。

可迁移做法：

1. 内部标记、占位符、截断、长度、JSON 结构等问题先用确定性规则检查。
2. 事实一致性、引用语义和表达风险交给模型判断。
3. 为在线质检增加硬性延迟门禁、Token 门禁和成本门禁。
4. 重试基于错误类型和剩余截止时间，配额错误直接结束，限流和短暂网关错误允许快速切换或退避。
5. 以文章指纹、提示版本、规则版本、证据版本、模型参数组成完整缓存键。

关键源码与文档：

- [断言、阈值与权重](https://github.com/promptfoo/promptfoo/blob/2c45764ca1daf4587c83d68b940ba0eb14cb7ac4/site/docs/configuration/expected-outputs/index.md)
- [调度架构](https://github.com/promptfoo/promptfoo/blob/2c45764ca1daf4587c83d68b940ba0eb14cb7ac4/docs/scheduler-architecture.md)
- [重试策略](https://github.com/promptfoo/promptfoo/blob/2c45764ca1daf4587c83d68b940ba0eb14cb7ac4/src/scheduler/retryPolicy.ts)
- [缓存实现](https://github.com/promptfoo/promptfoo/blob/2c45764ca1daf4587c83d68b940ba0eb14cb7ac4/src/cache.ts)

### 5.2 DeepEval：原子主张、可校准阈值与受控并发

DeepEval 的忠实度指标先从回答中生成主张，再根据检索上下文逐项判定支持情况，最终计算支持比例。G-Eval 允许用明确评价步骤和 Rubric 形成可解释分数，严格模式属于可选项。执行层支持异步并发、信号量、单任务截止时间和缓存。

可迁移做法：

1. 将文章中的“物质性主张”作为最小判定单元，减少对普通数字、编号和无风险措辞的误捕获。
2. 事实维度输出支持、冲突、证据不足三种状态，并返回简短理由和稳定证据身份。
3. 评分采用阈值与 Rubric，严格模式仅用于重大事实、伪造来源和明确法律红线。
4. 离线评测可以并行执行，在线路径使用较低并发和明确单任务截止时间。
5. 缓存键覆盖输入、上下文、模型、参数和评测配置，防止跨版本误用。

关键源码与文档：

- [Faithfulness 设计](https://github.com/confident-ai/deepeval/blob/9404fb2d47fd3b0f87b25de9e46fe89bc0b922a7/docs/content/docs/%28rag%29/metrics-faithfulness.mdx)
- [异步与并发配置](https://github.com/confident-ai/deepeval/blob/9404fb2d47fd3b0f87b25de9e46fe89bc0b922a7/deepeval/evaluate/configs.py)
- [端到端执行控制](https://github.com/confident-ai/deepeval/blob/9404fb2d47fd3b0f87b25de9e46fe89bc0b922a7/deepeval/evaluate/execute/e2e.py)
- [评测缓存](https://github.com/confident-ai/deepeval/blob/9404fb2d47fd3b0f87b25de9e46fe89bc0b922a7/deepeval/test_run/cache.py)

### 5.3 Ragas：证据支持率、检索质量分离与裁判对齐

Ragas 的 Faithfulness 将回复拆成原子陈述，逐条判断是否可以从检索上下文推出，分数等于被支持陈述占比。Context Precision 单独评价检索结果排序与相关性，还提供非 LLM 和基于稳定 ID 的评价路径。官方裁判对齐指南使用专家标注样本分析假阳性与假阴性，再调整提示和语义等价规则。执行器提供工作线程、批量、取消、重试和缓存能力。

可迁移做法：

1. 分开衡量“检索是否找到正确证据”和“文章主张是否被证据支持”。
2. 有稳定来源 ID 时优先使用 ID 与内容哈希，减少模型对引用编号的主观判断。
3. 建立中文专家标注集，定期分析假阳性、假阴性和裁判漂移。
4. 每次实验只改变一个主要变量，并记录模型、提示版本、Token、响应时间和随机性设置。
5. 高风险低置信案例进入深度复核，普通案例使用轻量快速裁判。

关键源码与文档：

- [Faithfulness 指标](https://github.com/vibrantlabsai/ragas/blob/298b68274234c060deacab3cf5fb52aa3a20e885/docs/concepts/metrics/available_metrics/faithfulness.md)
- [Context Precision 指标](https://github.com/vibrantlabsai/ragas/blob/298b68274234c060deacab3cf5fb52aa3a20e885/docs/concepts/metrics/available_metrics/context_precision.md)
- [裁判与专家标注对齐](https://github.com/vibrantlabsai/ragas/blob/298b68274234c060deacab3cf5fb52aa3a20e885/docs/howtos/applications/align-llm-as-judge.md)
- [运行配置](https://github.com/vibrantlabsai/ragas/blob/298b68274234c060deacab3cf5fb52aa3a20e885/src/ragas/run_config.py)
- [并发执行器](https://github.com/vibrantlabsai/ragas/blob/298b68274234c060deacab3cf5fb52aa3a20e885/src/ragas/executor.py)

## 六、推荐方案与边界

### 6.1 推荐方案

推荐继续使用 Laravel 单体、PostgreSQL、Redis、Horizon 和 Laravel AI SDK，在现有质检服务内完成 v2 升级，不引入新的语言、外部评测平台或独立微服务。

```text
文章内容 + 现有风险扫描 + 可选生成证据快照
                    │
                    ▼
确定性预检
内部标记、占位符、截断、格式、现有广告红线结果
                    │
                    ▼
物质性主张抽取
归一化、跨字段去重、重要度、主张哈希、出现位置列表
                    │
                    ▼
共享候选池 + 未覆盖高重要度主张补检
稳定键 = knowledge_base_id + chunk_id + content_hash
                    │
                    ▼
一次紧凑结构化语义判断
supported / contradicted / unverified + confidence
                    │
                    ▼
后端校验 + 根因归并 + 评分 v2 + 发布门禁
                    │
             ┌──────┴──────┐
             ▼             ▼
执行状态 status       业务结论 decision
queued → running      null → passed / needs_review / blocked
   ↓
completed / failed / stale / cancelled
失败结论：failed + error
```

执行状态和业务结论保持分离。`needs_review` 与 `blocked` 继续作为已完成质检的 `decision`，不会新增同名执行状态。供应商异常使用 `status=failed, decision=error`，兼容现有数据和页面逻辑。

### 6.2 最小备选方案

最小方案只处理 K 标记预清理、候选去重、证据预算、2048 输出 Token、35 秒模型超时和维度扣分。它能明显缩短案例 #5 的时间，也能修复最严重的重复扣分。队列拥塞、版本重检洪峰、裁判校准和稳定来源身份仍会保留，因此不作为最终推荐。

### 6.3 本轮不采用多裁判并行

按四个维度分别调用四个模型，或同时使用多个裁判投票，可以提高部分边界案例的解释能力。它会直接增加模型调用数、成本和供应商波动，难以满足 1 分钟目标。v2 使用一次裁判调用，低置信结果进入人工复核。多裁判只用于离线黄金集实验。

### 6.4 最脆弱假设与依赖

本方案假设至少 95% 的日常文章正文不超过 5000 字，且选定供应商能够在 35 秒内完成约 6000 输入 Token、2048 输出 Token 上限的结构化请求。若真实文章长度分布或供应商 P95 不满足这一条件，常规文章的 55 秒 SLO 会失效。实施第一步会用近 30 天文章长度和真实模型探测确认该假设；假设失败时，发布门禁继续安全关闭，产品目标按长文章分级，或更换满足响应预算的质检模型。

依赖全部来自现有系统：PostgreSQL、Redis、Horizon、Laravel AI SDK、知识库切片和已配置模型供应商。无需新增 API Key、第三方账号或开源项目运行依赖。

## 七、详细设计

### 7.1 SLI 口径与分级 SLO

端到端耗时从 `article_ai_quality_checks.created_at` 计到 `finished_at`。队列等待为 `started_at - created_at`，执行耗时为 `finished_at - started_at`。前端 650 毫秒刷新延迟单独记录，不计入服务端 SLO。

| 文章范围 | 性能目标 | 输入预算 | 说明 |
| --- | --- | ---: | --- |
| 正文不超过 5000 字，单段 | 成功 P50 ≤ 25 秒，P95 ≤ 55 秒，P99 ≤ 60 秒 | P95 ≤ 6000 Token | 主验收范围 |
| 5001 至 12000 字，单段 | 成功 P95 ≤ 90 秒 | P95 ≤ 12000 Token | 页面持续显示真实阶段与已用时间 |
| 超过 12000 字，多段 | 成功 P95 ≤ 180 秒 | 每段受独立输入预算约束 | 逐段续跑，发布等待全部完成 |

主 SLO 在以下条件下统计：供应商健康、前台质检队列没有超过配置容量、文章知识库不超过现有 5 个关联库。供应商超时、429、502、503、504 和输出无效等可控错误，从任务开始计 60 秒内写入终态。机器掉电、Worker 被强杀和 Redis 故障采用独立恢复指标，P95 在 3 分钟内由 Horizon 失败处理或对账任务收敛。

### 7.2 常规文章的 60 秒预算

| 阶段 | P95 预算 |
| --- | ---: |
| 前台队列等待 | 5 秒 |
| 确定性预检与主张抽取 | 2 秒 |
| 证据检索与组装 | 7 秒 |
| 模型评审与一次预算内故障切换 | 38 秒 |
| 校验、评分与持久化 | 3 秒 |
| 服务端总预算 | 55 秒 |
| 硬终止余量 | 5 秒 |

服务在开始执行时建立单一截止时间。每个模型候选、结构化输出和 JSON 模式共享剩余预算。主请求最多使用 35 秒；仅当失败发生较早且剩余时间不少于 15 秒时，才进行一次候选切换或 JSON 回退。服务端 55 秒停止发起新动作，60 秒前写入 `failed/error`。

### 7.3 质检队列隔离与回填优先级

当前质检任务与文章生成、标题生成等任务共享 `geoflow` 队列。满足队列等待 SLO 需要独立资源边界：

1. 新增 `ai-quality` 前台队列，接收人工重检和新文章质检。
2. 新增 `ai-quality-backfill` 低优先级队列，接收算法升级、知识变更和异常补偿。
3. Horizon 增加两个 Supervisor。`ai-quality` 默认并发 2，专供前台；`ai-quality-backfill` 默认并发 1，并在前台压力或供应商配额不足时暂停。单 Job 超时均为 70 秒。
4. `config/horizon.php` 增加两个队列的等待告警，前台为 10 秒，回填为 120 秒。
5. 本地 `composer dev` 和 Docker 队列服务同步加入新队列，防止开发环境出现“已入队但无人消费”。
6. 回填每批最多 25 篇。当前台队列等待超过 10 秒、供应商熔断或当日额度不足时，回填暂停并保留游标。
7. 前台默认并发 2 的理论上限约为每分钟 3 至 4 篇 35 秒模型调用。真实流量超过该容量时，根据供应商并发和配额提高 Supervisor 上限，禁止只增加 Worker 数量而忽略供应商限制。

### 7.4 确定性预检与主张抽取

现有 `ArticleRiskScanner` 和 `ArticlePublicationQualityGate` 继续负责确定性风险扫描与统一发布门禁，v2 不重复实现一套广告红线扫描。

主张抽取按以下顺序执行：

1. 复用 `ArticleCitationMarkerCleaner` 清理抽取副本，原文快照保持不变，便于历史定位。
2. 清理 HTML 装饰、Markdown 引用标记和不可见字符。
3. 识别金额、比例、日期、规模、排名、资格、统计值、保证和比较性主张。步骤序号、产品代号、内部 K 编号和普通列表编号直接排除。
4. 疑问句、建议句、否定句和政策原文中的“保证、首个、第一”结合上下文判断，避免按词命中。
5. 生成独立于字段的 `claim_hash`，把标题、摘要、元描述和正文中的同一主张合为一个根因；各字段位置保存在 `occurrences`。
6. 常规文章最多保留 12 条物质性主张。金额、比例、日期、排名、资格和承诺优先。超出预算的高重要度主张进入 `unverified`，最终至少为 `needs_review`，禁止静默丢弃。

输出字段固定为 `claim_id`、`normalized_claim`、`claim_hash`、`type`、`materiality` 和 `occurrences`。`occurrences` 保存字段、字符起止位置和原文片段。

### 7.5 证据检索与稳定来源身份

检索分两层执行：

1. 使用标题、摘要、正文提纲和高重要度主张生成一个文章级查询，召回最多 20 条候选证据。
2. 在候选池中按各主张重排。仍未覆盖的高重要度主张最多补充 6 个查询，查询归一化去重，并通过批量接口处理。
3. 每条主张最多关联 3 条证据，全文最终注入模型的唯一证据最多 12 条、正文总量最多 6000 字符。
4. 证据排序继续使用现有向量、关键词、标题、治理状态和时效分数。`review_status`、`effective_date`、`risk_level` 和来源冲突结果保留到证据快照。
5. 稳定来源键固定为 `knowledge_base_id + chunk_id + content_hash`。`K1` 只作为当前页面的短显示编号，模型输出和评分不再比较 K 数字。
6. 支持关系使用三态：`supported`、`contradicted`、`unverified`。没有检索到证据只能得出 `unverified`，不能提升为知识库冲突。

自动生成文章可选保存 `generation_evidence_snapshot` JSON，内容为生成时实际使用的知识块身份、内容哈希、来源哈希和截断片段。质检优先验证并复用这些候选，再检索编辑后新增或变化的主张。该字段可空，不对历史文章执行推测性回填。人工编辑文章和缺少快照的旧文章继续使用质检时检索。

### 7.6 数据与引文的判定边界

以下内容属于需要证据的物质性主张：金额和价格、比例和统计、具体日期、排名和唯一性、资质、直接引语、政策要求、可验证的性能结果，以及正文明确写成“研究、报告、数据显示”的结论。

普通经验建议、操作步骤、场景假设和不含事实断言的观点不强制要求引用。文章声明了外部 URL 但该来源没有进入受管知识库时，系统记录 `source_declared_unverified` 并进入人工确认。在线质检不会自动抓取任意 URL，避免 SSRF、网页漂移和额外延迟。

判定规则固定为：

| 证据状态 | 处理 |
| --- | --- |
| 稳定来源内容支持主张 | 不生成问题 |
| 稳定来源内容与主张在主体、数值、时间或范围上明确冲突 | 生成已确认问题，可触发高风险门禁 |
| 没有找到证据 | 生成不确定项或缺少来源问题，通常进入 `needs_review` |
| 只有 K 编号不同，语义内容一致 | 忽略编号差异 |
| 来源过期、未审核或多个来源冲突 | 标记低置信证据，进入 `needs_review` |

### 7.7 提示与结构化输出

提示继续保留现有不可信数据边界和提示注入防护。压缩只删除重复说明、漂亮打印和可由后端推导的字段。

1. 广告规则分为全局高风险规则与场景规则。全局高风险规则始终注入，场景规则由现有风险扫描结果和发布语境选择。
2. 事实候选与证据使用紧凑 JSON。证据只包含稳定键、必要元数据和截断正文。
3. 模型输出上限固定为 2048 Token，问题最多 16 条，不确定项最多 6 条。模型优先返回所有 hard blocker，再按重要度输出其余根因。
4. 输出字段固定为 `summary`、`promotion_context`、`issues`、`uncertainties` 和 `truncated_issue_count`。
5. 每个 issue 只返回 `code`、`severity`、`claim_hash`、`field`、`quote`、`evidence_keys`、`evidence_status`、`reason`、`suggestion` 和 `confidence`。字符位置、引用合法性、维度、根因键和门禁效果由后端生成。
6. `truncated_issue_count > 0` 时结果至少进入 `needs_review`，防止输出预算造成静默放行。
7. 模型温度设为 0 或供应商支持的最低确定性参数。供应商不支持时记录实际参数，不伪造可复现性。
8. `finish_reason=length`、Schema 缺字段或引用不存在均视为无效输出，只在剩余预算足够时执行一次快速回退。

现有模型就绪档案增加质检专用结构化探测，记录 `structured_output.status`、Schema 通过率、P50、P95、最近成功时间和配置指纹。执行前根据档案直接选择结构化模式或严格 JSON 模式，避免每次先失败再回退。

### 7.8 后端校验与评分 v2

评分 v2 保留四个维度和 100 分总分。严重度描述“已确认问题的影响”，重要度描述“主张的业务影响”，置信度描述“证据与裁判的确定程度”。三者分开保存。

初始扣分表固定如下。它先用于离线和影子评测，达到黄金集门槛后才接管发布门禁。

| 维度 | critical | high | medium | low | 类别上限 |
| --- | ---: | ---: | ---: | ---: | --- |
| 知识一致性，满分 35 | 20 | 10 | 5 | 2 | 同一主张同根问题最多扣 10 |
| 数据与引文，满分 25 | 15 | 8 | 4 | 1 | 引文格式与来源声明类合计最多扣 4 |
| 广告合规，满分 30 | 20 | 12 | 6 | 2 | 同一法规、同一表达根因只计一次 |
| 内容完整性，满分 10 | 10 | 5 | 3 | 1 | 摘要与 SEO 截断类合计最多扣 3 |

根因键按问题类型生成。事实与引文使用 `code_family + claim_hash + stable_source_set`，完整性使用 `code_family + normalized_fragment`。一个根因可以保存多个 `occurrences`，数值扣分只执行一次。类别上限只限制分数损失，critical 与 hard blocker 的展示和发布门禁不会被上限削弱。

后端校验规则：

1. 逐字原文必须存在，字符位置由后端重新计算。
2. `evidence_keys` 必须存在于本次稳定证据快照。
3. 数值冲突需要后端标准化比较主体、数值、单位、时间和范围。仅凭模型返回 `data_mismatch` 不自动升级为 critical。
4. `unverified` 留在不确定项，只有明确要求引用的物质性主张才生成 `citation_missing`。
5. K 编号不参与语义校验。
6. 结构性问题由确定性检查优先定位，模型结果用于补充原因和建议。

决策顺序固定为：

1. 存在已确认 hard blocker 或总分低于任务人工放行线，`decision=blocked`。
2. 总分低于自动通过线、存在高重要度不确定项、证据覆盖为 partial/insufficient、结果被截断或引用无法验证，`decision=needs_review`。
3. 其余结果为 `decision=passed`。
4. 执行失败使用 `status=failed, decision=error`。

页面和 API 继续返回现有 `score`、`dimension_scores` 和 `decision`，新增 `confidence`、`evidence_coverage`、`gate_reasons`、`scoring_version`。高不确定性不会直接扣质量分，它会改变门禁结论。

### 7.9 错误分类、重试与故障切换

`FinalOutboundSecurityPolicy` 和模型运行层需要保留异常类型、HTTP 状态、供应商错误码和上游 cause。`safeErrorCode` 停止依赖异常消息文本。前端只接收安全错误码和本地化提示。

| 错误码 | 是否预算内重试 | 最终处理 |
| --- | --- | --- |
| `provider_timeout` | 剩余不少于 15 秒时切换一次候选 | `failed/error` |
| `provider_rate_limited` | 尊重短 `Retry-After`，仍受总截止时间限制 | `failed/error` |
| `provider_gateway_error` | 502、503、504 可切换一次候选 | `failed/error` |
| `provider_quota_exhausted` | 否 | `failed/error`，暂停该模型 |
| `provider_authentication_failed` | 否 | `failed/error`，模型标记不可用 |
| `structured_output_unsupported` | 能力档案未知且剩余充足时改用 JSON | `failed/error` |
| `invalid_model_output` | 剩余充足时进行一次 JSON 回退 | `failed/error` |
| `evidence_retrieval_failed` | 单次短重试 | `failed/error` |
| `inspection_deadline_exceeded` | 否 | `failed/error` |

模型供应商错误在服务内完成快速处理。`ProcessArticleAiQualityJob` 不再执行 60、300、900 秒的队列级延迟重试，建议改为 `$tries=1`、`$timeout=70`、`$failOnTimeout=true`。Worker 被强杀等基础设施异常交给失败回调和对账任务恢复，避免同一活动记录跨越数分钟仍显示运行中。

供应商连续 5 次可重试错误或最近 10 次错误率达到 50% 时，熔断 60 秒；半开状态只放行一次探测。阈值保存在代码配置中，第一阶段不增加管理员可调界面。

### 7.10 指纹、缓存与显式重检

现有自动路径已经会复用相同 `input_fingerprint` 的 completed 结果，活动记录也通过 `active_dedupe_key` 去重。v2 保留这一行为。

- 文章生成、对账和发布门禁触发的自动检查可以复用相同指纹的完成结果。
- 用户点击“重新 AI 质检”继续创建新审计记录并真实调用模型，符合当前 `force=true` 语义。
- 人工重检可以复用同指纹的证据检索缓存，不能直接复用旧模型结论。
- 证据缓存键包含文章内容哈希、知识库来源哈希、候选主张哈希集合、检索算法版本和治理状态，默认有效 24 小时。
- 提示、规则、模型、证据、评分版本任一变化都会产生新指纹。
- 缓存命中与失效原因写入 `execution_meta`，不新增面向普通管理员的缓存开关。

### 7.11 进度、API 与 CLI

后台沿用现有状态接口和轮询组件，新增以下字段：`elapsed_ms`、`deadline_at`、`timings`、`safe_error_code`、`retryable`。阶段继续使用 queued、evidence、inspecting、summarizing 和 finished。

页面调整：

1. 显示已用时间和“本次检查最长约 1 分钟”的预期。
2. evidence 与 inspecting 阶段显示真实开始时间，避免百分比长期不动却没有解释。
3. 55 秒后显示“正在结束本次检查”，终态后自动刷新结果。
4. `needs_review` 展示需要人工确认的事项，`blocked` 只列 hard blocker 和已确认门禁原因。
5. 轮询接口保持只读，超期终态化由 Job 或对账任务处理。

API 新增 `GET /api/v1/articles/{article}/ai-quality/status`，使用 `articles:read` 权限与读取限流。CLI 新增 `geoflow article ai-quality-status ARTICLE_ID`。现有 POST 重检和人工放行契约保持不变。

### 7.12 遥测、安全与存储

`execution_meta.timings_ms` 固定记录 `queue_wait`、`precheck`、`claim_extraction`、`evidence_retrieval`、`prompt_render`、`model_total`、各模型 attempt、`validation`、`scoring`、`persistence` 和 `total`。进程内阶段使用单调时钟，跨进程总耗时使用数据库时间戳。

`usage_meta` 统一兼容 snake_case 与 camelCase。供应商没有返回 `total_tokens` 时，用 prompt 与 completion 相加，修复案例 #5 总 Token 为 0 的遥测缺口。能取得推理 Token 时单独写入 `reasoning_tokens`，取不到时保持空值。

安全边界：

- 文章、证据、提示词和模型原始输出不写入普通应用日志。
- API Key、鉴权头、完整供应商响应和异常堆栈继续脱敏。
- 提示压缩保留文章、知识和规则的不可信数据标签，加入提示注入回归样本。
- 管理后台的状态接口不返回完整文章、证据正文或供应商内部错误。
- `raw_model_output` 和单段结果设置 64 KiB 保存上限，超限时记录截断标记，已校验的问题与门禁理由完整保存。
- 本轮保留现有历史审计记录，不自动清理文章、证据和提示快照。看板增加快照存储量，达到 5 GB 或月增长 20% 时再单独评审保留期限。

### 7.13 版本发布与防止重检洪峰

当前 `ArticleAiQualityFingerprint::ALGORITHM_VERSION` 会影响所有指纹，对账任务也会重检旧算法记录。直接全局升级会在部署后形成集中回填。新版把执行链和评分规则分开控制，避免性能改造与门禁语义相互绑定。

1. `GEOFLOW_AI_QUALITY_EXECUTION_VERSION=legacy|fast_v2` 控制执行链。
2. `GEOFLOW_AI_QUALITY_FAST_V2_PERCENT=0..100` 控制快速执行链的稳定金丝雀比例。
3. `GEOFLOW_AI_QUALITY_SCORING_V2_PERCENT=0..100` 控制评分 v2 接管门禁的比例。
4. `GEOFLOW_AI_QUALITY_SHADOW_V2_PERCENT=0..100` 控制只计算、不影响门禁的评分 v2 样本比例，默认 0，生产验证期设为 10。
5. 文章按稳定哈希分组。哈希输入包含可用的租户或工作区身份、文章 ID 和实验名称；单工作区部署使用固定命名空间。同一文章在比例不变时保持固定分组，也不会形成跨租户相关偏差。
6. `algorithm_version` 使用可解析的组合版本，例如 `execution=fast-v2;retrieval=2;prompt=2;scoring=1`。`execution_meta` 同步保存各子版本、实验组和生效配置摘要。
7. legacy、fast_v2、scoring_v1 和 scoring_v2 在一个发布周期内同时可读。发布门禁只读取当前分组下最新、指纹一致且执行成功的非影子结果。
8. 影子记录标记 `gate_applied=false` 并保存对应基线记录 ID，不能成为发布门禁、自动复用或“最新结果”查询的默认返回值。
9. 回填只进入 `ai-quality-backfill`，默认每批 25 条，使用稳定游标和幂等键，前台队列等待超阈值时自动暂停。
10. 已发布历史文章不因版本升级自动批量重检。文章编辑、新增分发、人工重检或现有策略要求时再创建新版检查。
11. 达到 100% 并稳定一个发布周期后再停止旧写路径。旧历史结果和解析逻辑继续保留，清理另行经过数据保留评审。

## 八、分阶段实施方案

### 阶段一：60 秒快速路径与真实进度

目标：修复案例 #5 的耗时链路，评分继续由 v1 负责，便于隔离性能变化。

实施项：

1. 抽取前复用引文标记清理，增加跨字段主张去重和 12 条预算。
2. 检索改为文章级共享候选池，最多补检 6 个未覆盖高重要度主张；模型证据限制为 12 条、6000 字符。
3. 压缩提示输入，保留安全边界，输出上限固定为 2048 Token。
4. 实现 55 秒软截止、60 秒业务截止和剩余预算感知的模型切换。
5. 移除队列级长退避，Job 改为 70 秒安全超时和失败终态。
6. 用异常类型和 HTTP 状态替代字符串猜测，修复 Token 合计遥测。
7. 新增 `ai-quality`、`ai-quality-backfill` 队列与独立 Horizon Supervisor。
8. 在现有进度接口中增加已用时间、截止时间、阶段计时和安全错误码。
9. 增加 API 状态接口与 CLI 状态命令。
10. 更新运行手册、Docker、`composer dev` 和队列配置。

验收标准：

- 不超过 5000 字的成功质检，端到端 P50 ≤ 25 秒、P95 ≤ 55 秒、P99 ≤ 60 秒。
- 可控错误从任务开始计 60 秒内进入 `failed/error`。
- 前台队列等待 P95 ≤ 5 秒，回填任务无法挤占前台队列。
- 输入 Token P95 ≤ 6000，输出 Token P95 ≤ 1500。
- 证据检索查询数从案例 #5 的 36 次降到最多 7 次。
- `usage_meta.total_tokens` 始终等于供应商总量，或在缺失时等于 prompt 与 completion 之和。
- 当前后台轮询、终态刷新、登录失效和错误脱敏测试继续通过。
- 同一输入不会因并发或异常产生两个活动检查。

独立价值：阶段一可以单独发布，评分语义保持 v1，用户等待和排障体验立即改善。

回滚：将 `GEOFLOW_AI_QUALITY_FAST_V2_PERCENT` 设为 0 并把执行版本切回 `legacy`。Horizon Supervisor 在回滚窗口继续监听新旧队列，待新队列排空后再移除。新增 API 为只读接口，回滚时可以保留。

### 阶段二：评分 v2 与黄金评测集

目标：修复数据与引文、内容完整性的误判和重复扣分，让评分变化先通过人类标注验证。

实施项：

1. 建立 240 篇脱敏中文黄金集，分为 120 篇校准集、60 篇固定回归集、60 篇盲测集。
2. 样本覆盖四个维度、四档风险、来源缺失、来源冲突、旧 K 标记、外部 URL、长短文本和提示注入。
3. 两名评审独立标注问题、严重度、证据状态和门禁结论；分歧由第三名评审裁决。盲测集在参数冻结前不参与调参。
4. 新增 `geoflow:evaluate-ai-quality` 离线评测命令。默认读取脱敏夹具并生成本地报告，只有显式 `--live` 才调用配置模型。
5. 实现 supported、contradicted、unverified 三态、稳定证据键、后端数值标准化和评分 v2。
6. 根因归并、维度扣分、类别上限、置信度和门禁原因按 7.8 节执行。
7. 文章 #501 的脱敏快照加入固定回归集，不写入真实客户资料。
8. v2 先在离线评测通过，再对 10% 生产检查执行影子计算。影子数据不能改变发布门禁。

验收标准：

- 盲测安全样本误拦截率 ≤ 3%。
- 重大事实冲突、伪造来源和法律高风险问题的 decision 级召回率 ≥ 95%。
- 问题级 Macro F1 ≥ 0.85。
- v2 与最终人工裁决的 Cohen's Kappa ≥ 0.75。
- 同一输入重复运行 5 次，门禁结论一致率 ≥ 95%，分数标准差 ≤ 3 分。
- 旧 K 数字差异本身不再生成 `citation_scope_mismatch`。
- 同一主张在摘要、元描述和正文重复出现时只执行一次主扣分。
- 案例 #501 保留真实的摘要与元描述完整性问题；K 编号错配不触发 `blocked`。最终分数由黄金集裁决确定，不为单个案例预设目标分。

独立价值：阶段二完成后，v2 可以按稳定分组逐步接管门禁，v1 历史结果和回退路径仍然可用。

回滚：将 `GEOFLOW_AI_QUALITY_SCORING_V2_PERCENT` 和 `GEOFLOW_AI_QUALITY_SHADOW_V2_PERCENT` 设为 0。已生成的 v2 记录保留审计，后续检查按 v1 评分；发布门禁不读取失配版本或影子结果。

### 阶段三：生成证据快照、缓存与供应商治理

目标：减少重复检索，提升模型选择稳定性，控制版本升级和知识变更造成的后台负载。

实施项：

1. 为自动生成文章保存可空的 `generation_evidence_snapshot`，并在质检时校验来源哈希后优先复用。
2. 证据检索加入 24 小时精确指纹缓存。人工重检继续真实调用模型。
3. 扩展现有模型 readiness profile，增加质检 Schema、响应时间和最近错误率。
4. 加入供应商熔断、半开探测和受供应商配额约束的并发控制。
5. 版本回填使用低优先级队列、25 条批次、前台压力保护和稳定游标。
6. 增加运行看板、存储量指标和自动告警。
7. 更新生成服务、编辑器生成路径和质量快照兼容逻辑。没有生成证据快照的文章继续走现有检索路径。

验收标准：

- 精确指纹命中的自动检查在 3 秒内返回已有完成结果。
- 人工“重新 AI 质检”始终产生新的模型审计记录。
- 证据缓存没有跨文章、跨知识版本、跨治理状态或跨算法版本污染。
- 结构化输出 Schema 通过率 ≥ 99.5%。
- 单一供应商异常时，常规文章在 60 秒内完成候选切换或形成明确错误。
- 回填运行期间，前台队列等待 P95 仍 ≤ 5 秒。
- 可按模型、供应商、文章长度、版本、触发来源、缓存状态和错误类型筛选运行数据。

独立价值：阶段三在阶段一、二的基础上减少重复工作，生成证据快照和缓存均为可空、可降级能力。

回滚：停止写入生成证据快照并关闭证据缓存和自适应路由。历史 JSON 字段保留，质检回到实时检索与固定模型顺序；评分 v2 和快速路径继续可用。

## 九、预计代码影响范围

本方案跨越数据、领域服务、队列、后台、API、CLI 和运维配置，预计涉及超过 20 个文件，并新增一个版本策略类和一个离线评测命令。三个阶段必须分别提交、验证和发布，每个阶段都能独立运行与回滚。

| 边界 | 主要文件或模块 | 变化 |
| --- | --- | --- |
| 主张抽取 | `ArticleFactCandidateExtractor.php` | 标记预清理、物质性识别、去重、上限、哈希 |
| 引文清理 | `ArticleCitationMarkerCleaner.php` | 复用现有能力，清理抽取副本，不改历史快照 |
| 证据构建 | `ArticleAiQualityEvidenceBuilder.php`、`KnowledgeRetrievalService.php` | 共享候选池、批量补检、稳定来源键、缓存与体积预算 |
| 提示渲染 | `ArticleAiQualityPromptRenderer.php`、质检提示模板 | 紧凑输入、按需规则、简化输出字段 |
| 模型评审 | `LaravelArticleAiQualityReviewer.php`、两个质检 Agent | 共享截止时间、2048 Token、模式选择、用量归一化 |
| 模型能力 | `AiWorkspaceModelCapabilityProbe.php`、readiness profile | 增加质检结构化输出与延迟探测 |
| 结果校验 | `ArticleAiQualityResultValidator.php` | 新 Schema、数值标准化、稳定证据引用、兼容旧结果 |
| 评分决策 | `ArticleAiQualityScorer.php` | 维度标尺、根因归并、类别上限、置信度分离 |
| 流程编排 | `ArticleAiQualityInspectionService.php` | 阶段预算、遥测、缓存语义、状态收敛 |
| 版本策略 | 新增 `ArticleAiQualityVersionPolicy.php` | 稳定分组、v1/v2 并存、回填选择 |
| 策略解析 | `ArticleAiQualityPolicyResolver.php`、`ArticleAiQualityFingerprint.php` | 规则选择、子版本、精确失效 |
| 队列 | `ProcessArticleAiQualityJob.php`、`ReconcileArticleAiQualityJob.php` | 前台与回填队列、截止时间、批次和压力保护 |
| Horizon 与本地运行 | `config/horizon.php`、`composer.json`、Docker Compose | 独立 Supervisor、队列监听、等待告警 |
| 出站安全 | `app/Services/Outbound/FinalOutboundSecurityPolicy.php`、出站异常类 | 保留 cause、HTTP 状态和安全错误映射 |
| 后台接口 | `ArticleController.php`、`ArticleAiQualityProgressPresenter.php`、Web 路由 | 扩展现有状态载荷与计时字段 |
| 后台页面 | `form.blade.php`、现有质检进度脚本 | 显示已用时间、截止提示和门禁原因 |
| API | API `ArticleController`、`ArticleGeoFlowService`、API 路由 | 增加只读状态查询 |
| CLI | `OperationRegistry.php`、`ArticleHandler.php`、命令规格和 CLI 文档 | 增加 `ai-quality-status` |
| 离线评测 | 新增 `EvaluateArticleAiQualityCommand.php`、脱敏夹具与报告格式 | 黄金集对比、盲测、稳定性和成本报告 |
| 配置 | `config/geoflow.php`、`.env.example`、`.env.prod.example` | 预算、队列、执行版本、影子比例和评分金丝雀比例 |
| 数据 | `Article.php`、质检模型、可逆迁移 | 可空生成证据快照；检查记录增加 `gate_applied`、`evaluation_mode`、`baseline_check_id` 和必要索引 |
| 生成链路 | `WorkerExecutionService.php`、`ArticleContentGenerationService.php` | 保存结构化生成证据，缺失时安全降级 |
| 多语言 | `lang/*/admin.php` | 新进度、错误和状态提示，避免回退键 |
| 测试 | Unit、Feature、JavaScript、PostgreSQL、浏览器测试 | 性能、评分、错误、状态、权限和回归覆盖 |

## 十、测试与评测方案

### 10.1 单元测试

- `[K#]`、步骤编号和产品代号不会成为数据事实。
- 金额、比例、日期、排名、资格和明确统计仍可识别。
- 摘要、元描述和正文中的同一主张只形成一个 `claim_hash`，并保留全部 occurrences。
- supported、contradicted、unverified 和外部来源已声明四种情形按设计处理。
- 同根问题保留所有定位点，只扣一次主分；类别上限不削弱 hard blocker。
- 数值标准化覆盖货币、百分比、单位、日期、主体和范围。
- 评分决策严格遵守 hard blocker、人工放行线、自动通过线和不确定项顺序。
- 旧版模型结果可以兼容读取，新版结果通过严格 Schema 校验。
- 缓存键对内容、证据、治理状态、规则、提示、模型和参数变化敏感。
- 版本策略对同一文章稳定分组，比例回退后不读取版本失配结果。
- 用量归一化在供应商缺少总 Token 时得到正确合计。

### 10.2 集成测试

- 模拟 429、502、503、504、连接超时、读取超时和配额不足。
- 模拟结构化输出不支持、JSON 截断、字段缺失和无效引用。
- 验证首模型快速失败后备用模型在剩余预算内成功。
- 验证主模型耗尽预算后任务在 60 秒内形成明确错误。
- 验证前台队列优先级、回填暂停、活动去重、任务取消、缓存并发锁和数据库写入。
- 验证人工重检创建新模型记录，自动相同指纹复用完成结果。
- 验证算法版本变化不会一次性把全部历史文章压入前台队列。
- 验证管理后台与 API 状态接口的权限、限流、脱敏和终态契约。
- 验证 Worker 被终止后失败回调或对账任务可以收敛状态。
- 验证 SQLite 与 PostgreSQL 行为一致。

### 10.3 黄金集评测

- 仓库只保存合成或充分脱敏样本，禁止提交客户名称、内部数据、个人信息和未公开来源。
- 120 篇校准集用于提示、扣分和阈值调整；60 篇固定回归集用于每次变更；60 篇盲测集只在参数冻结后运行。
- 各维度包含无问题、轻微问题、边界问题、高风险问题，以及证据不足和证据冲突。
- 标注保存问题代码、严重度、主张重要度、证据状态、根因组和最终门禁结论。
- 每次实验只改变一个主要变量，并记录裁判输入、原始输出、规则、提示、模型、参数、Token 和阶段耗时。
- 报告 decision 级混淆矩阵、安全样本误拦截率、重大风险召回、问题级 Macro F1、Kappa、P50、P95、Token 和重复运行稳定性。

### 10.4 性能与故障测试

- 使用至少 100 篇不同长度的脱敏文章做离线检索与提示体积测试。
- 使用至少 50 次真实供应商金丝雀请求验证响应时间，结果按模型和文章长度分桶。
- 依次测试单用户、2 个并发、5 个并发和回填同时运行。并发超过供应商配额时应排队或快速失败，禁止形成请求风暴。
- 分别观察冷缓存、热缓存、主模型异常、备用模型异常、Redis 暂停和 Worker 中断。
- 验证页面在任务完成、失败、超时和会话失效时都能结束或安全重试轮询。

### 10.5 安全与隐私测试

- 文章正文和知识证据中的“忽略系统指令”、伪造 JSON、闭合标签等内容不能改变系统指令。
- 出站异常、状态接口、运行日志和评测报告不包含 API Key、鉴权头、完整证据或原始供应商响应。
- 外部 URL 不触发在线抓取，受管知识库来源 URL 只作为证据元数据。
- API 状态接口只允许 `articles:read`，重检和人工放行继续使用现有更高权限。
- 64 KiB 原始输出上限不会截断已经校验并持久化的问题和门禁理由。

### 10.6 实施时必须执行的验证命令

```bash
php artisan test --compact \
  tests/Unit/ArticleFactCandidateExtractorTest.php \
  tests/Unit/ArticleAiQualityEvidenceBuilderTest.php \
  tests/Unit/ArticleAiQualityPromptRendererTest.php \
  tests/Unit/ArticleAiQualityResultValidatorTest.php \
  tests/Unit/ArticleAiQualityScorerTest.php \
  tests/Unit/ArticleAiQualityFingerprintTest.php

php artisan test --compact \
  tests/Feature/ArticleAiQualityInspectionServiceTest.php \
  tests/Feature/AdminArticleAiQualityTest.php \
  tests/Feature/ArticleAiQualityGateTest.php \
  tests/Feature/AiQualityTaskConfigurationTest.php \
  tests/Feature/ApiV1ContractTest.php

node --test tests/JavaScript/article-ai-quality-progress.test.js
npm run build
php artisan route:list --json
vendor/bin/phpunit -c phpunit.postgresql.xml
php artisan test --compact
node --test tests/JavaScript/*.test.js
```

真实供应商验收单独执行，不能混入默认 CI。命令使用明确的 `--live` 参数，并在开始前输出预计文章数和 Token 上限，避免误触发批量费用。

### 10.7 手工验收场景

1. 用案例 #501 的脱敏快照检查 K 编号、摘要残留和元描述截断。
2. 构造一个真实价格冲突，确认稳定证据支持 hard blocker。
3. 构造一个无来源市场份额，确认进入 `needs_review`，不被写成知识冲突。
4. 构造一个外部 URL 来源，确认系统不抓取 URL，并提示人工核验。
5. 主模型超时，确认备用模型只在剩余预算充足时启动。
6. 同时启动人工重检和后台回填，确认人工请求优先。
7. 编辑质检中的文章，确认旧记录 stale，新内容生成新指纹。
8. 桌面与移动端查看进度、已用时间、终态和错误提示，不调整用户现有浏览器窗口。

## 十一、上线与观测

### 11.1 发布顺序

每个阶段独立发布，阶段一的执行链和阶段二的评分门禁使用不同开关。

1. 发布准备：记录最近 7 天基线，核对供应商配额、Horizon 进程数、数据库空间、队列等待和当前失败率。所有新增数据列使用可空或有默认值的向后兼容迁移。
2. 兼容部署：先部署可同时读取旧、新记录的代码，所有新比例保持 0；再启动 `ai-quality` 和 `ai-quality-backfill` Supervisor，确认 Worker、Redis、数据库和状态接口健康。
3. 阶段一快速链路：离线与故障测试通过后，将 fast_v2 从 10% 提升到 50%，再提升到 100%。10% 至少观察 100 次合格检查或 1 个业务日；50% 至少观察 300 次合格检查并覆盖一个业务高峰。
4. 阶段二影子评分：评分 v2 完成盲测后，对 10% 稳定样本执行影子计算，至少收集 200 对结果或 3 个业务日。影子任务复用本次输入、证据和模型输出，在请求外异步评分，不发起第二次在线模型调用。
5. 阶段二门禁：评分 v2 依次接管 10%、50% 和 100% 门禁。10% 至少观察 200 次，50% 至少观察 500 次并覆盖一个业务高峰，100% 稳定一个发布周期后停止旧评分写路径。
6. 阶段三能力：生成证据快照、证据缓存和供应商熔断分别用独立开关放量。每项从 10% 开始，单项达到验收门槛后再继续，便于定位回归来源。
7. 回填控制：启用前输出待处理数量、预估 Token 和费用；每批 25 条，仅使用低优先级队列。前台队列等待 P95 超过 5 秒或供应商剩余配额低于安全水位时自动暂停。

每次提升比例前保存配置快照、样本量、指标截图和审批人。迁移在整个回滚窗口内保留，回滚只切开关和执行版本，不执行破坏性数据回退。

### 11.2 核心看板

- 流量：创建数、开始数、完成数、失败数、取消数、stale 数和各触发来源占比。
- 时延：队列等待、预检、主张抽取、证据检索、模型、校验、评分、持久化和端到端 P50、P95、P99。
- 模型：输入、可见输出、推理和总 Token，结构化输出通过率，候选切换率，按模型与供应商划分的错误率。
- 质量：各维度问题数、根因数、扣分、门禁贡献、人工放行率、人工推翻率和高风险漏检复盘。
- 检索：查询数、唯一证据数、证据覆盖率、无证据主张数、缓存命中率、失效原因和节省时间。
- 队列：`ai-quality` 与 `ai-quality-backfill` 的长度、等待时间、吞吐、运行进程和失败任务数。
- 成本：每篇 Token、每篇估算费用、每日总费用、影子评测费用和回填预算使用率。
- 存储：原始输出、证据快照、分段结果和评测报告的总量与月增长。

所有时延和成功率至少按文章长度档、执行版本、评分版本、模型、供应商、触发来源、缓存状态和实验组分桶，避免总体均值掩盖长文或单一供应商问题。

### 11.3 告警、停止放量与回退

合格检查定义为正文不超过 5000 字、成功入队、没有人工取消且模型配置在任务开始时可用的检查。技术指标满足最小样本 30，并在 30 分钟窗口持续触发任一条件时，系统暂停 fast_v2 放量并切回上一稳定比例：

- 合格检查端到端 P95 超过 55 秒。
- 60 秒内进入成功或明确失败终态的比例低于 99%。
- `inspection_deadline_exceeded` 比例超过 1%。
- 结构化输出 Schema 通过率低于 99.5%。
- 前台队列等待 P95 超过 5 秒，且持续 10 分钟。
- 同一错误码在 10 分钟内超过 20 次，或单一供应商最近 10 次错误率达到 50%。

质量指标在每次评分放量前作为人工发布门禁。盲测安全样本误拦截率超过 3%、重大风险召回低于 95%、问题级 Macro F1 低于 0.85 或 Kappa 低于 0.75 时，评分 v2 比例保持或回到上一稳定值。生产中的人工推翻率连续 3 天高于基线 5 个百分点时暂停放量并执行抽样复盘。

回退后保留失败请求 ID、版本、模型尝试轨迹、阶段耗时和安全错误码。值班手册明确提供队列停发、比例回退、供应商暂停、失败记录重置和恢复验证步骤。

### 11.4 部署完成判据

一个阶段只有同时满足以下条件才标记完成：

1. 代码、配置、迁移、运行手册和告警规则已部署。
2. 新旧记录均可读取，Web、API 和 CLI 状态契约一致。
3. 默认 CI、PostgreSQL 测试、前端构建和 JavaScript 测试通过。
4. 真实供应商金丝雀达到该阶段样本量和 SLO。
5. 回退演练成功，旧版本可以继续处理新创建的兼容记录。
6. 本阶段指标看板可以按规定维度筛选，且没有完整正文、证据或密钥泄漏。

## 十二、实施风险与控制

| 风险 | 影响 | 控制措施 |
| --- | --- | --- |
| 压缩提示后遗漏重要上下文 | 事实召回下降 | 黄金集对比、物质性优先、按需追加证据 |
| 2048 Token 仍发生 JSON 截断 | 任务失败或问题遗漏 | 紧凑 Schema、问题数量上限、hard blocker 优先、截断后进入人工确认 |
| 稳定证据映射受人工编辑影响 | 引用重连失败 | 内容哈希、偏移、编辑后重连、无法确认时进入人工复核 |
| 缓存复用过度 | 结果陈旧 | 完整指纹、知识库版本和规则版本进入缓存键 |
| 评分 v2 放松过度 | 真实风险漏检 | 重大事实与法律红线保持硬门禁、影子运行与黄金集召回门槛 |
| 多供应商切换导致判定漂移 | 分数不稳定 | 模型能力档案、统一 Rubric、重复运行稳定性门槛 |
| 新队列未被某个部署环境监听 | 页面长期停在 queued | 发布前验证 Supervisor、队列健康探针、超过等待阈值告警并终态化 |
| 前台和回填共享供应商配额 | 在线检查变慢 | 独立队列、前台保留并发、回填压力保护和费用上限 |
| 队列超时早于业务收敛 | 记录长期 running | Job 超时高于业务截止，失败回调和对账任务都写入幂等终态 |
| 供应商无法满足 55 秒目标 | SLO 持续失败 | 上线前实测分桶、候选能力档案、快速失败和明确错误，不宣称未验证的供应商保证 |
| 结构化输出能力探测过期 | 每次先失败再回退 | 配置指纹失效、周期探测、最近成功时间和半开探测 |
| 外部 URL 来源未在线核验 | 真实引用进入人工队列 | 明确 `source_declared_unverified`，支持后续受控入库，禁止静默判错 |
| 生成证据快照在编辑后过期 | 旧证据被错误复用 | 内容哈希校验，只复用仍匹配主张，变化主张重新检索 |
| 知识治理状态变化后缓存仍命中 | 使用撤回或过期来源 | 治理状态与来源哈希进入缓存键，状态变化主动失效 |
| 版本比例变化导致结果选择混乱 | 门禁读取错误记录 | 稳定分组、组合版本、影子标记和当前指纹四重约束 |
| 算法升级触发历史重检洪峰 | 队列和费用失控 | 禁止已发布历史自动批量重检，低优先级批次和成本预估 |
| 黄金集包含客户敏感信息 | 隐私和合规风险 | 仅保存合成或充分脱敏样本，提交前扫描，真实快照留在受控存储 |
| 校准集泄漏到盲测集 | 指标虚高 | 固定三段划分、盲测访问控制、参数冻结后运行 |
| 提示压缩削弱注入防护 | 模型服从文章内恶意指令 | 保留不可信边界，加入注入回归集和输出引用校验 |
| 原始模型输出快速增长 | 数据库膨胀 | 64 KiB 上限、存储看板和达到阈值后的独立保留评审 |
| 长文章被强行套用 60 秒指标 | 体验承诺失真 | 按长度分级 SLO，页面显示对应预计时间和分段进度 |
| 前端轮询增加请求量 | 后台压力 | 保持 2 秒默认间隔，终态停止、页面离开时取消、接口使用轻量字段和限流 |

## 十三、最终建议与待确认决策

本轮 Review 建议锁定以下默认值。用户确认本文件后，实施阶段按这些约束执行；任何门禁阈值、数据保留策略或外部访问边界的实质变更都重新请求确认。

1. 正文不超过 5000 字的常规文章使用 P50 25 秒、P95 55 秒和 60 秒业务截止。更长文章使用 90 秒或 180 秒分级目标，页面如实展示对应预计时间。
2. 质检使用独立 `ai-quality` 前台队列和 `ai-quality-backfill` 低优先级队列，初始前台并发为 2、回填并发为 1。上线前以真实配额和压测结果调整并发。
3. K 编号只用于当次页面展示。K 数字差异不生成问题，稳定来源内容与文章主张明确冲突时才形成知识或引文问题。
4. 在线质检不抓取文章中的任意外部 URL。已声明但未受管的来源进入 `source_declared_unverified` 和人工确认。
5. 高重要度证据不足、输出截断和来源无法核验进入 `needs_review`。不确定项不直接扣质量分，已确认问题按评分表扣分。
6. 评分 v2 使用 7.8 节固定的四维扣分表、根因归并和类别上限。hard blocker 的门禁不受类别扣分上限影响。
7. 常规文章最多抽取 12 条物质性主张，最多执行 1 次文章级检索和 6 次补检，最多向模型注入 12 条唯一证据、6000 字符证据正文，输出上限为 2048 Token。
8. 自动触发且指纹一致的检查可以复用完成结果。人工“重新 AI 质检”始终创建新的模型审计记录，只允许复用精确匹配的证据缓存。
9. 黄金集采用 120 篇校准、60 篇固定回归和 60 篇盲测划分，两人独立标注，分歧由第三人裁决。
10. 阶段一发布快速执行链并保留评分 v1；阶段二通过离线、10% 影子、10%、50%、100% 门禁放量评分 v2；阶段三逐项放量生成证据快照、缓存和供应商治理。
11. 执行链和评分版本分别控制，已发布历史文章不因版本变化自动批量重检。回填按 25 条批次进入低优先级队列。
12. 本轮不新增外部运行时依赖或微服务，继续使用现有 Laravel、PostgreSQL、Redis、Horizon 和 Laravel AI SDK。

### 13.1 确认后的实施交付顺序

1. 先提交阶段一的数据库兼容改动、执行链、队列、状态契约、测试和运行手册，并完成 fast_v2 金丝雀。
2. 阶段一验收后提交黄金集框架和评分 v2。盲测通过且评审记录完整后才启用生产影子。
3. 评分 v2 稳定后提交阶段三，每项缓存或治理能力单独开启和回退。
4. 每个阶段提供代码变更清单、迁移说明、配置清单、测试证据、性能数据、费用估算、已知限制和回滚记录。
5. 任一阶段未达到验收标准时保持上一稳定版本，不提前实施依赖该阶段的后续门禁切换。

本文件即为升级实施基线。本轮停在方案确认环节，没有修改业务代码、配置、数据库或队列。

## 十四、Review 修订记录

本轮 Review 已补齐或修正以下事项：

1. 核实当前后台已经存在状态接口、2 秒轮询、动态进度、分段续跑、指纹复用和定时对账，删除重复建设描述。
2. 将执行状态与业务结论分开，明确 `status` 和 `decision` 的合法组合。
3. 将“一分钟以内”限定到不超过 5000 字的常规文章，并增加长文分级 SLO、错误终态和队列等待目标。
4. 增加独立队列、容量保护、部署顺序、停止放量条件和回滚完成判据。
5. 明确稳定证据身份、外部 URL 边界、生成证据快照失效规则和人工重检语义。
6. 将评分 v2、根因归并、扣分表、类别上限、hard blocker 和不确定项处理写成可直接验收的规则。
7. 增加黄金集规模与隔离方法、质量门槛、性能故障测试、安全隐私测试和真实供应商费用保护。
8. 分离执行链版本、影子评分和门禁评分开关，防止算法升级触发历史重检或记录选择混乱。

## 十五、参考资料

### 主对标项目

- [Promptfoo 仓库](https://github.com/promptfoo/promptfoo)
- [DeepEval 仓库](https://github.com/confident-ai/deepeval)
- [Ragas 仓库](https://github.com/vibrantlabsai/ragas)

### 补充候选

- [OpenAI Evals](https://github.com/openai/evals)
- [Langfuse](https://github.com/langfuse/langfuse)
- [MLflow](https://github.com/mlflow/mlflow)
- [Opik](https://github.com/comet-ml/opik)

### GEOFlow 当前实现

- `app/Services/GeoFlow/ArticleAiQualityInspectionService.php`
- `app/Services/GeoFlow/ArticleFactCandidateExtractor.php`
- `app/Services/GeoFlow/ArticleAiQualityEvidenceBuilder.php`
- `app/Services/GeoFlow/ArticleAiQualityPromptRenderer.php`
- `app/Services/GeoFlow/LaravelArticleAiQualityReviewer.php`
- `app/Services/GeoFlow/ArticleAiQualityResultValidator.php`
- `app/Services/GeoFlow/ArticleAiQualityScorer.php`
- `app/Services/GeoFlow/ArticleCitationMarkerCleaner.php`
- `app/Jobs/ProcessArticleAiQualityJob.php`
- `app/Jobs/ReconcileArticleAiQualityJob.php`
- `app/Services/GeoFlow/ArticleAiQualityFingerprint.php`
- `app/Services/GeoFlow/ArticleAiQualityGate.php`
- `app/Support/Admin/ArticleAiQualityProgressPresenter.php`
- `app/Services/AiWorkspace/AiWorkspaceModelCapabilityProbe.php`
- `app/Services/Outbound/FinalOutboundSecurityPolicy.php`
- `app/Http/Controllers/Admin/ArticleController.php`
- `app/Http/Controllers/Api/V1/ArticleController.php`
- `app/Console/GeoFlowCli/ArticleHandler.php`
- `app/Console/GeoFlowCli/OperationRegistry.php`
- `config/horizon.php`
- `routes/web.php`
- `routes/api.php`
- `resources/prompts/article-quality-cn-v1.txt`
- `resources/views/admin/articles/form.blade.php`
- `resources/js/admin/article-ai-quality-progress.js`
- `tests/Feature/AdminArticleAiQualityTest.php`
- `tests/Feature/ArticleAiQualityInspectionServiceTest.php`
- `tests/JavaScript/article-ai-quality-progress.test.js`
