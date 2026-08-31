# 原子事实三链路端到端复核、自检与修复报告

日期：2026-08-30
环境：本地 Docker，`http://127.0.0.1:18080`
模型：DeepSeek V4 Pro，模型记录 ID 3
最终原子算法：`atomic-facts-2.2.0`

## 结论

本轮完成了任务生成、单篇文章质检、知识库事实生成三条真实链路。任务 75 成功生成 3 篇文章；三篇文章的最终质检均完成并记录了活动 revision 1；知识库 22 成功生成 10 条事实和 10 条标准答案，全部保留在草稿审核态。

复核共确认并修复 4 个工程问题：通用主体和通用谓词造成过宽召回、文本比较错误使用紧凑 canonical value、模型生成顺序型稳定键、长驻队列继续执行旧代码。另记录 1 个上游瞬时超时和 1 个模型 readiness 前置条件，均通过现有恢复机制闭环。

本地正式原子比例继续保持 0%，影子比例保持 100%。本轮样本用于工程验收，不改变此前 5 篇基准报告的生产门禁结论。

## 链路一：新建任务并生成 3 篇文章

### 配置与结果

- 任务 ID：75
- 名称：`原子事实三链路验收-20260830-225339`
- 知识库：23
- 内容模型与质检模型：3
- AI 质检：开启
- 文章上限：3
- 最终状态：paused
- 任务执行记录：474、475、476，最终全部 completed

生成文章：

| 文章 ID | 标题 | 最终状态 |
|---:|---|---|
| 526 | GEOFlow 主题与首页模块怎么配置?前台搭建全攻略 | draft / pending |
| 527 | GEOFlow 多站点分发实战:一次生成,多渠道发布 | draft / pending |
| 528 | GEOFlow 连接 WordPress 的配置步骤与常见问题 | draft / pending |

生成期间模型接口出现两次瞬时 `Outbound request failed`。任务队列依照既有重试策略恢复，三条任务执行记录最终完成，没有产生重复文章。文章 526 的首轮质检出现 `provider_timeout`，在模型连通性探测恢复后重新质检成功。

最终三篇质检记录：

| 文章 | Check | 分数 | 决策 | Token | 原子命中 | 歧义 | 回退 |
|---:|---:|---:|---|---:|---:|---:|---:|
| 526 | 222 | 94 | needs_review | 10,275 | 1 | 0 | 23 |
| 527 | 223 | 96 | needs_review | 5,507 | 1 | 2 | 23 |
| 528 | 224 | 94 | needs_review | 5,112 | 1 | 6 | 23 |

`needs_review` 来自完整知识库链路的覆盖/问题门禁。三条检查状态均为 completed，原子通道没有产生冲突问题。

## 链路二：单篇文章原子事实质检

文章 526 作为最终验证样本。Check 222 的运行证据：

- 模式：shadow
- 活动事实 revision：1
- 活动事实数量：20
- 处理主张：24
- supported：1
- contradicted：0
- ambiguous：0
- fallback：23
- 原子执行耗时：2.16 ms
- 算法版本：`atomic-facts-2.2.0`

命中的事实为 `公开版本 v2.1.0`，比较方法为确定性 version comparator。未命中的 23 条主张继续进入知识库质检，符合混合路由设计。

### 发现与修复

#### 1. 通用主体扩大召回范围

现象：`subject=GEOFlow` 使“公开版本”事实关联到大量仅包含 GEOFlow 品牌词的主张。首轮文章 527 出现 10 条无关歧义，原子覆盖率为 0。

根因：召回器把主体、谓词、标签和别名视为同等的独立命中条件。

修复：

- `GEOFlow` 等通用产品主体不再独立触发召回。
- 版本事实保留“主体 + 版本号”的确定性专用召回。
- 具体主体继续参与召回，例如 `WordPress REST渠道`。

#### 2. 通用谓词扩大召回范围

现象：`新增`、`包括`、`记录` 等谓词单独命中跨主题主张。文章 526 的一轮旧 worker 结果出现 12 条歧义。

根因：谓词可以脱离主体和指标标签单独触发。

修复：召回入口只接受标签、别名、具体主体和类型化专用规则。通用谓词不再独立召回。

#### 3. 相似文本无法通过

现象：文章中的 WordPress 能力表述与标准答案语义一致，结果仍为 ambiguous。

根因：string 类型比较时优先使用 `canonical_value` 的紧凑列表，丢失了标准答案中的主体和谓词语境；比较器也只支持完全相等或包含关系。

修复：

- string/text 类型使用完整 `canonical_answer`。
- 增加归一化字符 bigram Dice 相似度，阈值为 0.74。
- 高相似表达输出 `supported`，比较方法记录为 `text_similarity`。
- 数字、日期、版本、路径、范围和布尔仍由类型化确定性比较器处理。

修复后的真实文章 527 成功识别 WordPress REST 渠道能力，状态为 supported。

## 链路三：知识库生成原子事实与标准答案

### 运行结果

- 知识库 ID：22
- 名称：`GEOFlow 官方模型与 RAG 知识（2026-08）`
- 事实库 ID：2
- Generation run：3
- 目标数：10
- 候选数：10
- 创建事实：10
- 冲突：0
- 批次状态：completed
- 执行时间：2026-08-30 23:04:08 至 23:05:10，约 62 秒
- 工作流状态：review_required
- 事实审核态：10/10 draft
- 标准值审核态：10/10 draft

### 证据与答案核验

自动一致性检查结果：

- 10/10 evidence 关联到知识库 22 的当前 chunk。
- 10/10 chunk content hash 一致。
- 10/10 excerpt hash 一致。
- 10/10 摘录可在当前 chunk 原文定位。
- 10/10 每条事实均有一个主要证据。
- 7/10 的标准答案或 canonical value 可经归一化后直接包含匹配。
- 3/10 为列表标点、否定句展开或 Provider 分类的等价改写，逐条对照完整 chunk 后确认有依据。

本轮没有审核或发布 KB22 的事实。事实和标准值继续等待人工逐条审核，活动 revision 保持为空。

### 稳定键问题与修复

现象：模型返回 `fact-1` 至 `fact-10`。多个知识库独立生成后容易出现同名键，跨知识库编译可能形成伪冲突。

根因：生成提示只要求格式合法，服务端接受顺序编号。

修复：

- 提示词要求模型生成可跨批次复用的语义键。
- 新增 `KnowledgeFactStableKeyPolicy`。
- `fact-1`、`item_2`、`atomic10` 等顺序键会转换为 `fact.<24位语义哈希>`。
- 哈希身份包含 subject、predicate、label，支持同一主体和谓词下的多个不同指标。
- 有意义的领域键，例如 `product.public_version`，原样保留。
- KB22 已生成的 10 条草稿键及 run 3 候选快照已经同步修复，通用顺序键剩余 0 条。

## 运行与容器处理

长驻 worker 在代码修复后仍持有旧类定义，导致一次复测继续出现旧歧义数据。已重启以下服务：

- app
- queue
- knowledge-queue
- ai-quality-queue（2 个实例）
- ai-quality-backfill-queue

没有重建镜像。PostgreSQL 健康，Redis、Web 和上述服务均处于 Up 状态。

## 测试门禁

### PHP

- 97 tests passed
- 460 assertions passed
- 覆盖类型化比较、召回适用性、生成、审核、发布、取消、恢复、质检服务、灰度和评测命令

新增回归覆盖：

- 通用产品主体不会召回无关版本事实。
- 通用谓词不会召回无关指标。
- 等价 WordPress 能力表述输出 supported。
- 顺序型稳定键转换为语义哈希。
- 同主体、同谓词、不同标签不会发生哈希碰撞。

### JavaScript 与构建

- 29 JavaScript tests passed
- Vite production build passed
- 原子生成弹窗、进度恢复、取消、完成跳转、质检进度和通用操作弹窗覆盖通过

### 静态与运行检查

- Pint：passed（目标文件）
- `git diff --check`：passed
- 工作台路由：index/store/publish/update/archive/merge/review/split 均已注册
- 目标容器：Up

## 当前灰度与后续建议

当前灰度：

- `atomic_shadow_percent=100`
- `atomic_fact_percent=0`
- `atomic_fact_frozen=false`
- 全局冻结=false

建议继续维持影子 100%、正式 0%。本轮验证确认调用链、恢复链和生成治理链可用，同时也显示 KB23 的 20 条事实只能覆盖新文章 24 条主张中的 1 条。下一轮优化重点应放在事实库覆盖面、谓词/别名治理和歧义语义批处理。正式比例的提升继续以既定 5 篇黄金集与 Token 门槛为准。

## 回滚说明

代码回滚点：

- 删除 `KnowledgeFactStableKeyPolicy` 注入即可撤销顺序键归一化。
- 回退 `ArticleAtomicFactInspector` 与 `AtomicFactComparator` 可恢复上一版召回和文本判断。
- `atomic_fact_percent` 当前为 0，正式质检决策不会受本轮原子 shadow 结果影响。

数据状态：

- 任务 75 已暂停，3 篇文章和质检历史保留用于复核。
- KB22 的 10 条事实均为 draft，没有活动 revision，可在工作台编辑、驳回或归档。
- KB23 活动 revision 1 未发生修改。
