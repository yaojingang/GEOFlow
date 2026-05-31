# GEO 优化生产系统完善规划

> 目标：把当前 GEOAmplify 从“诊断和报告工具”升级为“关键词机会发现、AI 搜索采集、引用来源评分、仿写生产、图片生成、发布复测”的完整 GEO 优化生产系统。后续新的大模型或工程智能体可以按本规划继续拆解实现。

## 1. 当前基线

当前系统已经具备第一阶段基础能力：

- 企业资料维护：品牌名、别名、产品服务、优势、服务区域、补充事实。
- 关键词维护：管理员可录入 GEO 诊断关键词。
- GEO 诊断任务：按关键词和平台创建诊断问题。
- AI 平台调用：支持站内 Mock 平台，也支持真实 OpenAI 兼容和 Anthropic 兼容模型。
- 报告生成：根据 AI 回答生成 GEO 评分、可见度分析和优化建议。
- 内容草稿：根据报告生成站内文章草稿，并支持编辑、审核、转为正式文章。
- 质量审核：发布前可做基础 GEO 审核。
- 看板指标：展示趋势、内容生产进度和任务状态。

当前系统仍缺少生产级 GEO 优化的核心环节：

- 不能自动批量发现关键词机会。
- 不能批量向大模型搜索目标关键词。
- 不能从大模型回答里抽取引用网站、帖子、文章和内容来源。
- 不能采集来源页面并保存快照。
- 不能对参考内容做质量评分。
- 不能根据高分参考内容生成结构化写作简报。
- 当前文章草稿仍偏模板化，还没有真正基于来源材料和企业资产做 AI 仿写。
- 还没有接入 GPT 图片生成和自有图片资产库。
- 发布后缺少复测、排名变化和转化效果闭环。

## 2. 目标闭环

推荐把系统做成下面这条完整链路：

```mermaid
flowchart LR
    A["企业资料和内容资产"] --> B["关键词机会发现"]
    B --> C["AI 搜索批次"]
    C --> D["引用来源抽取"]
    D --> E["来源页面采集"]
    E --> F["参考内容评分"]
    F --> G["内容简报生成"]
    G --> H["AI 仿写和图片规划"]
    H --> I["审核和发布"]
    I --> J["发布后复测"]
    J --> B
```

系统最终要解决的问题不是只生成文章，而是持续回答四个问题：

- 用户会问什么：批量生成和筛选 GEO 关键词。
- AI 现在引用谁：采集大模型回答里的来源和被引用内容。
- 谁值得模仿：按结构、信息密度、可信度、转化价值和品牌适配度评分。
- 我们怎么超过它：用自己的资料、图片、案例和观点生成更适合被 AI 引用的内容。

## 3. 产品模块

### 3.1 企业资产中心

用途：把企业已有内容整理成可被生成系统调用的资产，而不是每次只靠一段品牌简介。

建议能力：

- 企业基础资料：品牌、别名、服务城市、产品线、价格带、优势、案例。
- 内容素材：官网介绍、产品资料、FAQ、案例文章、客户评价、视频口播稿。
- 图片资产：门店图、案例图、产品图、团队图、证书图、施工过程图。
- 禁用信息：不能宣传的词、不能承诺的效果、敏感表达。
- 事实库版本：每次文章生成保存当时使用的企业事实快照。

建议新增表：

- `geo_brand_assets`
- `geo_brand_asset_files`
- `geo_brand_facts`
- `geo_brand_forbidden_terms`
- `geo_image_assets`

### 3.2 关键词机会引擎

用途：从企业资料、行业词、地区词、竞品词、用户问题中批量生成可优化关键词。

关键词来源：

- 企业资料提取：产品、服务、地区、痛点、场景。
- 大模型扩写：让模型生成用户会问的问题。
- 搜索建议：后续可接搜索引擎 suggest 或第三方关键词 API。
- 竞品来源：从高质量引用页面反推关键词。
- 人工导入：CSV、Excel 或后台批量粘贴。

关键词类型：

- 信息型：例如“全屋定制怎么避坑”。
- 比较型：例如“重庆全屋定制哪家靠谱”。
- 决策型：例如“重庆全屋定制推荐品牌”。
- 本地服务型：例如“渝北全屋定制门店”。
- 痛点型：例如“小户型柜子怎么设计更能收纳”。

建议新增表：

- `geo_keyword_clusters`
- `geo_keyword_opportunities`
- `geo_keyword_intents`
- `geo_keyword_import_batches`

核心评分：

```text
机会分 = 商业价值 * 0.30
      + 用户意图清晰度 * 0.20
      + 当前品牌可见度缺口 * 0.25
      + 可采集参考内容数量 * 0.15
      + 本地化匹配度 * 0.10
```

### 3.3 AI 搜索批次

用途：把关键词批量投喂给真实大模型，模拟用户在 AI 平台里搜索。

建议能力：

- 按关键词组创建搜索批次。
- 支持选择模型：GPT-5.5、其他 OpenAI 兼容模型、Anthropic 兼容模型。
- 支持问题模板：推荐类、比较类、避坑类、清单类、购买决策类。
- 保存完整回答、品牌是否出现、竞品是否出现、引用来源、推荐排序。
- 支持失败重试、限速、队列执行和成本统计。

建议新增表：

- `geo_ai_search_runs`
- `geo_ai_search_questions`
- `geo_ai_search_answers`
- `geo_ai_search_run_logs`

建议服务类：

- `GeoSearchBatchRunner`
- `GeoSearchQuestionBuilder`
- `GeoAIAnswerAnalyzer`
- `GeoSearchCostEstimator`

### 3.4 引用来源抽取

用途：从大模型回答中识别“AI 引用了哪些网站、帖子、文章、论坛内容、问答页”。

需要抽取的内容：

- URL 链接。
- 网站名或平台名。
- 标题。
- 摘要。
- 被引用段落。
- 引用原因。
- 在回答中的位置。
- 是否推荐品牌或竞品。

抽取方式：

- 规则抽取：识别 URL、Markdown 链接、编号列表。
- 模型抽取：让大模型把回答转为结构化 JSON。
- 二次校验：URL 可访问性、去重、域名归一化。

建议新增表：

- `geo_citation_sources`
- `geo_citation_mentions`
- `geo_source_domains`

建议服务类：

- `GeoCitationExtractor`
- `GeoSourceNormalizer`
- `GeoCitationDeduplicator`

### 3.5 来源采集和快照

用途：把引用来源真正采集下来，避免后续页面变动、打不开或内容丢失。

建议能力：

- 抓取标题、正文、发布时间、作者、图片、结构化数据。
- 保存 HTML 快照和正文纯文本。
- 提取文章结构：标题层级、列表、FAQ、表格、案例、结论。
- 识别平台类型：官网、公众号、知乎、小红书、论坛、新闻、百科、地图页。
- 记录采集状态和失败原因。

合规边界：

- 尊重 robots 和平台访问限制。
- 不绕过登录、验证码和付费墙。
- 页面不可采集时只保存 URL、标题和失败原因。
- 不直接复制正文用于发布，只用于评分和结构参考。

建议新增表：

- `geo_reference_snapshots`
- `geo_reference_assets`
- `geo_crawl_jobs`
- `geo_crawl_job_logs`

建议服务类：

- `GeoSourceCrawler`
- `GeoReadableTextExtractor`
- `GeoReferenceSnapshotStore`

### 3.6 参考内容评分

用途：判断哪些文章值得模仿，哪些只是低质量噪音。

评分维度：

- AI 引用强度：被多少模型、多少关键词引用。
- 搜索可见度：是否来自高权重网站或高频出现域名。
- 内容完整度：是否覆盖用户问题、步骤、对比、FAQ、案例。
- 信息密度：是否有具体参数、价格、流程、时间、注意事项。
- 可信度：作者、来源、日期、证据、真实案例。
- 本地匹配度：是否覆盖目标城市、区域或本地服务场景。
- 转化价值：是否有联系方式、预约、案例、服务承诺。
- 可模仿性：结构清楚、表达自然、没有明显版权风险。

建议评分：

```text
参考质量分 = AI 引用强度 * 0.25
          + 内容完整度 * 0.20
          + 信息密度 * 0.15
          + 可信度 * 0.15
          + 本地匹配度 * 0.10
          + 转化价值 * 0.10
          + 可模仿性 * 0.05
```

建议新增表：

- `geo_reference_scores`
- `geo_reference_score_items`

建议服务类：

- `GeoReferenceScorer`
- `GeoContentStructureAnalyzer`
- `GeoReferenceRankingService`

### 3.7 内容简报生成

用途：在写文章之前先生成一份“怎么写”的简报，让模型有约束、有依据、有结构。

简报应包含：

- 目标关键词和关键词组。
- 用户意图。
- 推荐标题方向。
- 参考文章 Top 3。
- 参考文章共同结构。
- 必须覆盖的问题。
- 必须加入的企业事实。
- 可用图片。
- 禁止表达。
- 建议 FAQ。
- 建议内链和转化入口。

建议新增表：

- `geo_content_briefs`
- `geo_content_brief_references`

建议服务类：

- `GeoContentBriefBuilder`
- `GeoWritingAnglePlanner`

### 3.8 AI 仿写和原创生成

用途：模仿的是结构、选题角度、信息组织方式，不复制原文表达。

生成策略：

- 从高分参考内容提取结构，而不是复制文字。
- 用企业资产替换参考文章中的品牌、案例、图片和服务信息。
- 生成时强制输出“原创声明”和“事实引用清单”。
- 支持多版本：SEO 版、GEO 版、小红书版、公众号版、官网版。
- 支持人工编辑和二次审核。

建议新增表：

- `geo_content_generation_runs`
- `geo_content_generation_inputs`
- `geo_content_generation_outputs`

建议服务类：

- `GeoAIArticleWriter`
- `GeoArticleVariationGenerator`
- `GeoPlagiarismRiskChecker`
- `GeoArticleFactChecker`

### 3.9 图片生成和自有图片使用

用途：文章生成时优先使用自己的图片，不足时再用 GPT 图片模型补充信息图、封面图、步骤图。

图片来源优先级：

1. 企业自有案例图和产品图。
2. 已授权素材。
3. GPT 图片模型生成的原创图。

建议能力：

- 根据文章简报生成图片需求清单。
- 从自有图片库匹配合适图片。
- 缺图时调用 GPT 图片模型生成封面、场景图或信息图。
- 图片生成记录保存 prompt、模型、用途和关联文章。
- 发布前检查图片是否有不合适文字、水印、侵权风险。

建议新增表：

- `geo_image_generation_jobs`
- `geo_article_images`

建议服务类：

- `GeoImagePlanner`
- `GeoImageAssetMatcher`
- `GeoGPTImageGenerator`

### 3.10 发布和复测

用途：文章发布后重新让 AI 平台搜索同一批关键词，观察品牌可见度是否提高。

建议能力：

- 发布到站内文章系统。
- 后续再接外部分发：公众号、官网、WordPress、小红书、知乎等。
- 发布后 1 天、3 天、7 天、14 天自动复测。
- 对比发布前后的 GEO 分数、品牌提及、引用情况和竞品变化。
- 形成“内容贡献报告”。

建议新增表：

- `geo_publication_targets`
- `geo_post_publish_checks`
- `geo_visibility_snapshots`

建议服务类：

- `GeoPostPublishMonitor`
- `GeoVisibilityComparator`
- `GeoContentImpactReporter`

## 4. 后台页面规划

### 4.1 GEO 工作台

继续保留当前工作台，但增加入口：

- 关键词机会库。
- AI 搜索批次。
- 引用来源库。
- 参考内容评分。
- 内容生产任务。
- 发布复测。

### 4.2 关键词机会库

核心功能：

- 批量生成关键词。
- 按意图、地区、产品线、机会分筛选。
- 选择关键词创建 AI 搜索批次。
- 查看每个关键词的品牌现状和竞品占位。

### 4.3 AI 搜索批次

核心功能：

- 创建批次。
- 选择关键词组、模型、问题模板。
- 查看运行进度、失败原因、成本。
- 查看每个问题的原始 AI 回答和结构化分析。

### 4.4 引用来源库

核心功能：

- 展示被 AI 引用的 URL、平台、域名、标题。
- 显示引用次数、关联关键词、关联模型。
- 展示采集状态和页面快照。
- 支持标记“值得参考”“不相关”“不可采集”。

### 4.5 参考内容评分

核心功能：

- 查看参考文章质量分。
- 展开评分明细。
- 对比 Top 3 参考内容结构。
- 一键生成内容简报。

### 4.6 内容生产任务

核心功能：

- 根据简报生成文章。
- 选择写作版本：官网、公众号、SEO、GEO、小红书。
- 图片规划和图片生成。
- 人工编辑、审核、发布。

### 4.7 发布复测

核心功能：

- 查看发布前后评分变化。
- 查看品牌是否开始被 AI 推荐。
- 查看 AI 是否引用了我们自己的文章。
- 查看还需要补哪些关键词和内容。

## 5. 推荐实施阶段

### 阶段一：关键词机会和 AI 搜索批次

优先级最高，因为它直接复用当前已经跑通的真实 AI 调用能力。

交付内容：

- 关键词机会表和后台页面。
- 关键词批量生成服务。
- AI 搜索批次表和运行服务。
- AI 回答结构化分析。
- 批次结果页面。

验收标准：

- 管理员输入企业资料后，可批量生成 30 个以上关键词机会。
- 可选择关键词创建真实 AI 搜索批次。
- 每个回答保存原文、品牌是否出现、竞品是否出现、初步评分。
- 单个模型失败不会扣点，也不会破坏整个批次。

### 阶段二：引用来源抽取和采集

交付内容：

- 从 AI 回答中抽取 URL 和引用来源。
- 保存来源库。
- 对公开网页做标题、正文、结构采集。
- 页面不可访问时保存失败原因。

验收标准：

- 能从带链接的 AI 回答中抽取来源。
- 能对重复 URL 去重。
- 能采集普通 HTML 页面正文并保存快照。
- 能在报告中看到“AI 常引用哪些网站”。

### 阶段三：参考内容评分和内容简报

交付内容：

- 参考内容评分服务。
- Top 参考内容排行。
- 内容结构提取。
- 内容简报生成。

验收标准：

- 每个关键词能看到推荐参考内容 Top 3。
- 简报能明确告诉写作模型：标题方向、结构、必写问题、企业事实和图片需求。
- 评分明细可解释，不是只有一个黑盒分数。

### 阶段四：AI 仿写生产和图片规划

交付内容：

- 基于简报和企业资产生成文章。
- 接入真实大模型写作。
- 接入 GPT 图片模型。
- 自有图片优先匹配。
- 发布前事实检查和重复风险检查。

验收标准：

- 文章不复制参考内容原文。
- 文章能自动加入企业自己的案例、服务、图片和 FAQ。
- 缺图时可生成合适的封面或配图。
- 人工审核能看到生成依据和来源。

### 阶段五：发布、复测和增长闭环

交付内容：

- 站内发布后自动复测。
- 可扩展外部分发。
- GEO 可见度变化报告。
- 关键词和内容下一步建议。

验收标准：

- 发布前后同一关键词组可对比。
- 能看到品牌提及、推荐排序、引用来源是否变化。
- 系统能自动建议下一批要写的关键词。

## 6. 工程拆分建议

下一位工程智能体建议从阶段一开始，不要先做图片和发布分发。原因是关键词、AI 搜索、引用来源是整个系统的数据地基。

推荐第一轮任务：

1. 新增关键词机会相关 migrations、models、factories。
2. 新增 `GeoKeywordDiscoveryService`，先支持基于企业资料和真实 AI 模型生成关键词。
3. 新增 `GeoSearchBatchRunner`，把当前单次诊断能力扩展成批量搜索。
4. 新增 `GeoAIAnswerAnalyzer`，把回答解析为品牌提及、竞品提及、引用 URL。
5. 新增后台页面：关键词机会库和 AI 搜索批次。
6. 增加 feature tests，覆盖创建机会、创建批次、运行批次、失败保护。

建议第一轮不要做：

- 大规模爬虫。
- 多平台发布。
- 复杂图片生成工作流。
- 过早接入第三方关键词商业 API。

## 7. 代码接入点

当前相关代码位置：

- `app/Http/Controllers/Admin/GeoWorkspaceController.php`
- `app/Services/Geo/GeoDiagnosisRunner.php`
- `app/Services/Geo/GeoAIPlatformClient.php`
- `app/Services/Geo/GeoReportBuilder.php`
- `app/Services/Geo/GeoArticleDraftGenerator.php`
- `app/Services/Geo/GeoArticleAuditService.php`
- `resources/views/admin/geo/`
- `tests/Feature/AdminGeoWorkspaceTest.php`
- `tests/Feature/AdminAiModelsPageTest.php`

建议新增代码位置：

- `app/Models/GeoKeywordOpportunity.php`
- `app/Models/GeoKeywordCluster.php`
- `app/Models/GeoAiSearchRun.php`
- `app/Models/GeoAiSearchQuestion.php`
- `app/Models/GeoAiSearchAnswer.php`
- `app/Models/GeoCitationSource.php`
- `app/Models/GeoReferenceSnapshot.php`
- `app/Models/GeoReferenceScore.php`
- `app/Models/GeoContentBrief.php`
- `app/Services/Geo/GeoKeywordDiscoveryService.php`
- `app/Services/Geo/GeoSearchBatchRunner.php`
- `app/Services/Geo/GeoAIAnswerAnalyzer.php`
- `app/Services/Geo/GeoCitationExtractor.php`
- `app/Services/Geo/GeoReferenceScorer.php`
- `app/Services/Geo/GeoContentBriefBuilder.php`
- `app/Services/Geo/GeoAIArticleWriter.php`
- `app/Services/Geo/GeoImagePlanner.php`

## 8. 队列和成本控制

生产级系统必须避免同步页面请求里跑大量 AI 调用和采集。

建议原则：

- 批量关键词生成、AI 搜索、来源采集、图片生成都走队列。
- 每个批次有最大问题数、最大模型数、最大成本。
- 每次 AI 调用保存 request 摘要、response 摘要、token 或点数成本。
- 失败任务可以重试，但必须有最大重试次数。
- 模型调用失败时只标记当前问题失败，不影响已完成结果。
- 页面只负责创建任务、查看进度和手动重试。

## 9. 合规和质量边界

这套系统的核心不是搬运，而是基于公开参考内容做结构学习，再用自己的企业事实生成原创内容。

必须内置的规则：

- 不复制参考文章原文。
- 不绕过登录、验证码、付费墙。
- 不伪造案例、评价、资质、价格和承诺。
- 所有文章生成都要保存使用过的企业事实和参考来源。
- 高风险词和禁用词在发布前必须检查。
- 采集失败的来源不能当作已验证事实。
- 图片优先使用自有素材，生成图必须标记来源和用途。

## 10. 后续模型交接说明

工作目录：

```bash
/Users/renjianghua/.config/superpowers/worktrees/geo-intelligence-system/geo-mvp
```

建议先运行：

```bash
php artisan test --filter=AdminGeoWorkspaceTest
php artisan test --filter=AdminAiModelsPageTest
php artisan test
```

实现前先阅读：

```bash
app/Http/Controllers/Admin/GeoWorkspaceController.php
app/Services/Geo/GeoDiagnosisRunner.php
app/Services/Geo/GeoAIPlatformClient.php
app/Services/Geo/GeoArticleDraftGenerator.php
tests/Feature/AdminGeoWorkspaceTest.php
```

第一期推荐目标：

> 做出“关键词机会库 + AI 搜索批次 + 回答结构化分析”，先让系统可以批量发现机会和批量获得真实 AI 搜索结果。引用采集、评分、仿写和图片生成都依赖这批数据。

第一期完成后，系统应该能从“我想优化这个公司 GEO”变成：

1. 自动生成一批值得做的关键词。
2. 批量问真实大模型。
3. 保存 AI 回答和品牌可见度。
4. 找出竞品和引用来源。
5. 告诉管理员下一步应该模仿哪些内容、写哪些文章。
