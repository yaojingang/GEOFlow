import type { Prisma, ResearchLink, ResearchNote } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace } from "@/lib/workspace-service";

export const researchTypes = ["研究笔记", "研究报告", "豆包机制", "案例观察", "证据卡", "实验记录"] as const;
export const researchStatuses = ["draft", "published", "archived"] as const;

type ResearchNoteWithLinks = ResearchNote & {
  outgoingLinks: Array<ResearchLink & { toNote: ResearchNote }>;
  incomingLinks: Array<ResearchLink & { fromNote: ResearchNote }>;
};

export type ResearchNoteInput = {
  title: string;
  excerpt?: string | null;
  body: string;
  type?: string | null;
  tags?: unknown;
  status?: string | null;
  sourceType?: string | null;
  sourceId?: string | null;
};

export type ResearchLinkInput = {
  fromNoteId: string;
  toNoteId: string;
  label?: string | null;
  strength?: number | null;
};

const starterNotes = [
  {
    slug: "doubao-search-generation-research-report",
    title: "豆包搜索生成研究报告",
    excerpt: "研究豆包联网搜索、来源引用、搜索增强和证据分级，形成可进入豆包研究中心的第一份公开研究报告。",
    type: "研究报告",
    tags: ["豆包", "搜索生成", "联网搜索", "来源引用", "RAG", "研究报告"],
    body: `# 豆包搜索生成研究报告

生成日期：2026-05-28

## 一句话结论

豆包搜索生成研究第一阶段不应直接追求“证明豆包机制”，而应建立一条可持续的证据管线：官方与半官方资料进入证据库，行业报告进入背景层，社媒讨论转为采样问题，技术资料用于解释 RAG、搜索增强和来源 grounding 的可能机制。

这份报告是豆包研究中心的第一份系统节点，用来连接 [[豆包答案可见度]]、[[证据链与资料路由]] 和 [[Agent 对话内核]]。

## 关键发现

### 1. 豆包相关公开资料足够支撑研究节点启动

火山引擎云搜索服务文档显示，AI 搜索中预置了 Doubao-pro、Doubao-lite 和 Doubao-embedding，创建推理服务时可以关联豆包大模型。这说明豆包模型已经出现在云搜索 / AI 搜索产品链路中，适合作为“豆包大模型 × AI 搜索 × 云搜索”的官方证据入口。

火山引擎关于大模型联网搜索的文章把联网搜索描述为解决大模型知识时效局限的方案，强调多源交叉验证、结构化报告输出和参考信息源溯源。这些内容适合转成机制类节点，但不能直接推出“豆包 App 本身一定稳定展示来源引用”的结论。

### 2. “搜索生成”要拆成四个问题

- 模型是否调用搜索工具。
- 答案是否使用搜索结果。
- 答案是否展示引用来源。
- 引用来源是否可被用户复核。

官方文档可以证明能力存在，但不能证明终端产品行为稳定。豆包是否展示来源、是否偏好官方文档、是否在不同时间给出一致答案，需要真实采样验证。

### 3. 研究方法来自对话内核，不是外部 Skill 整包

K-Dense 的 scientific-agent-skills 对本项目有启发，但价值在方法拆解：search、extract、enrich、critique、hypothesis。GEOFlow 不应该整包安装外部 skill，而是把这套方法转写为自己的研究抓取和证据分级流程。

## 来源台账

| 来源 | 类型 | 证据等级 | 用途 |
|---|---|---|---|
| [火山引擎云搜索服务：查看豆包大模型](https://www.volcengine.com/docs/6465/1468048) | 官方文档 | official_confirmed | 豆包模型与 AI 搜索 / 云搜索产品链路证据 |
| [火山引擎：大模型联网搜索使用指南](https://www.volcengine.com/article/39336) | 官方/半官方文章 | official_confirmed | 联网搜索、实时信息、多源验证、结构化报告能力描述 |
| [K-Dense Scientific Agent Skills](https://github.com/K-Dense-AI/scientific-agent-skills) | 开源仓库 | technical_reference | 研究 skill 组织方法参考 |
| [K-Dense SECURITY.md](https://github.com/K-Dense-AI/scientific-agent-skills/blob/main/SECURITY.md) | 安全报告 | technical_reference | 外部 skill 需要审查，不适合整包安装 |

## 研究信号表

| 信号 | 类别 | 结论强度 | 下一步 |
|---|---|---|---|
| 火山引擎 AI 搜索链路中出现豆包大模型和 embedding。 | mechanism_change | confirmed | 入库为官方证据 |
| 联网搜索被描述为弥补 cutoff、多源验证和结构化报告能力。 | mechanism_change | likely | 转研究节点候选 |
| 社媒讨论可发现引用失败、答案漂移、来源缺失等问题。 | needs_sampling | hypothesis | 做豆包采样 |
| 行业报告可补充市场位置，但不能替代产品行为采样。 | market_context | needs_cross_check | 补真实报告来源 |

## 豆包采样问题

- 豆包回答“2026 年国内 AI 搜索产品有哪些变化”时，会不会展示来源？
- 豆包回答“火山引擎联网搜索是什么”时，会不会引用火山引擎官方资料？
- 同一个问题连续采样 5 次，豆包引用来源是否稳定？
- 问题中加入“请给出来源”后，豆包是否改变回答结构？
- 豆包在品牌/产品类问题里更偏好官方文档、媒体报道，还是百科/聚合页面？

## 结论

豆包研究中心应该先把“搜索生成”当成研究对象拆开：能力证据、答案行为、来源展示、引用稳定性、GEO 内容影响。公开层发布整理后的研究节点；真实采样、原始对话和工作台操作留在内部。
`,
  },
  {
    slug: "doubao-chinese-community-signal-report",
    title: "豆包中文社区信号研究报告",
    excerpt: "不再以官方资料作为结论依据，改用 X/Twitter、中文论坛、知乎、小红书、微博等公开社区线索，抓不到正文的链接走 GetNote 转内容流程，全部沉淀为社区信号和待采样问题。",
    type: "研究报告",
    tags: ["豆包", "中文社区", "X", "AI搜索", "用户反馈", "GetNote", "研究报告"],
    body: `# 豆包中文社区信号研究报告

生成日期：2026-05-28

## 研究边界

这份报告不把官方文档作为结论依据。官方资料只能作为背景索引，不能替代社区反馈、真实采样和可复核链接。

本轮目标是建立“中文社区信号 → GetNote 链接转内容 → 豆包采样问题 → 研究节点”的管线。论坛、X、微博、知乎、小红书里的内容先作为线索，不直接发布为事实；只有经过抓取、去重、人工复核和豆包采样后，才升级为公开研究结论。

这份报告连接 [[豆包来源引用采样协议]]、[[豆包答案可见度]] 和 [[Agent 对话内核]]。

## 抓取方法

### 1. 直接可读页面

能被公开访问的网页进入 direct_fetch 流程：

- 抓取 URL、标题、正文、发布时间和作者信息。
- 清洗导航、广告、重复楼层和无关推荐。
- 生成正文 hash 与标题相似度，用于去重。
- 只抽取“用户真实体验、产品对比、搜索生成、来源引用、总结质量、失败案例”相关段落。

### 2. 抓不到正文页面

登录墙、动态渲染、反爬、搜索结果页和平台限制页面进入 GetNote fallback：

- 保存 URL、平台、搜索词、抓取时间、失败原因。
- 使用 GetNote 链接转报告，把链接转成可读摘要、关键摘录和待验证问题。
- 标记为 link_only、getnote_fallback 或 needs_sampling。
- publishable 默认为 false，直到人工复核或豆包采样完成。

### 3. X / Twitter

优先复用本机既有 Hermes / Grok 反推采集路径。已确认 \`/Users/qiuxuanmai/dev/yao-media-station/scripts/x-insights/\` 里有可用实现：通过 Hermes Agent 参考的 xAI OAuth PKCE 客户端获取 token，调用 xAI Responses API 的内置 \`x_search\` 工具，让 Grok 自己检索 X 并输出结构化线索。OAuth token 存在 \`~/.config/yao-x-insights/xai-oauth.json\`，GEOFlow 已新增 \`scripts/doubao-research/fetch-x-community-signals.ts\` 复用这条链路。

注意：这条链路解决“能抓到 X 社区线索”的问题，但不改变证据等级。X 内容仍然只能进入 discussion_signal / needs_sampling，必须转成豆包采样问题后再升级为公开结论。

### 4. 研究升级规则

- discussion_signal：社区讨论出现，但没有可验证样本。
- needs_sampling：讨论指出问题，需要豆包实测。
- sampled_observation：已经用豆包采样验证，但仍需复核来源。
- publishable_note：能公开展示，且不包含私密对话、密钥、客户隐私或未授权全文。

## 社区来源台账

| URL | 平台 | 来源类型 | 抓取状态 | 用途 |
|---|---|---|---|---|
| https://linux.do/t/topic/2223608 | LINUX DO | community_thread | needs_extraction | 论坛讨论线索，待提取正文和楼层观点 |
| https://meta.appinn.net/t/topic/83381 | 小众软件社区 | community_thread | getnote_fallback | 用户对 AI 总结/搜索使用习惯的讨论线索 |
| https://www.zhihu.com/search?type=content&q=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2 | 知乎 | community_search | link_only | 搜索页入口，待 GetNote 或人工摘录具体问答 |
| https://s.weibo.com/weibo?q=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2 | 微博 | community_search | link_only | 热点和抱怨入口，不能直接当事实 |
| https://www.xiaohongshu.com/search_result?keyword=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2 | 小红书 | community_search | link_only | 真实用户场景入口，需防营销号污染 |
| https://x.com/search?q=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2&src=typed_query&f=live | X / Twitter | social_search | hermes_x_search_ready | 通过 Hermes-derived xAI OAuth + Grok x_search 抓取 |
| https://www.reddit.com/search/?q=Doubao%20AI%20search | Reddit | social_search | link_only | 海外讨论入口，作为跨语境对照 |

## 研究信号表

| 信号 | 类别 | 证据等级 | 验证状态 | 下一步 |
|---|---|---|---|---|
| 社区讨论更关心“好不好用、能不能总结、有没有幻觉”，而不是模型架构。 | usage_pattern | discussion_signal | needs_sampling | 用豆包真实问题采样体验差异 |
| AI 搜索、链接总结、网页摘要和资料整理场景经常与豆包一起被讨论。 | content_workflow | discussion_signal | needs_sampling | 建立链接转摘要与来源引用采样集 |
| 用户是否信任答案，关键不只在是否回答，而在是否给可复核来源。 | source_grounding | discussion_signal | needs_sampling | 每个样本记录 hasSource、sourceUrl、sourceOpenable |
| 论坛、小红书、微博、知乎搜索页多数存在动态渲染、登录和反爬限制。 | collection_constraint | confirmed_constraint | observed | 抓不到正文时统一走 GetNote fallback |
| X 适合捕捉实时吐槽和产品变化，已找到 Hermes / Grok x_search 采集路径。 | social_signal | discussion_signal | ready_for_crawl | 跑 \`scripts/doubao-research/fetch-x-community-signals.ts\` 生成社区信号台账 |
| 社区信号不能直接变成公开结论，必须先转成采样问题。 | safety_rule | internal_policy | active | 公开节点只发布整理后的结论 |

## 首轮 Hermes / Grok X 抓取结果

运行记录：\`research-runs/doubao-x-signals/2026-05-28-x-signals.md\`

抓取方式：Hermes-derived xAI OAuth + Responses API \`x_search\`。这轮返回 6 条 X 线索，全部保持 \`publishable=false\`，只进入采样队列。

| X 线索 | 信号 | 证据等级 | 采样问题 |
|---|---|---|---|
| https://x.com/fmdx266979/status/2059955750605291894 | 用户认为豆包处理信息/题目搜索时会被追问态度影响答案。 | discussion_signal | 搜索一个有争议问题，看豆包是否因追问改变答案，并检查来源引用。 |
| https://x.com/rawLux_/status/2059608016878792889 | 用户描述豆包会积极使用工具和联网搜索，甚至搜索大量网页。 | discussion_signal | 让豆包总结一个新闻/资料问题，记录搜索网页数量、来源展示和引用质量。 |
| https://x.com/Mzeeshan4554/status/2051987842562203789 | 用户把豆包归类为中国搜索分层里的 AI 搜索层。 | discussion_signal | 比较豆包与百度/小红书在同一问题上的搜索结果结构。 |
| https://x.com/MomoXCrypto/status/2051564073314349303 | 用户称豆包搜索命中来源与最终答案矛盾，追问后会翻转。 | discussion_signal | 询问有官方来源的争议事件，检查豆包是否引用来源并保持答案一致。 |
| https://x.com/didxga/status/2059867612121870624 | 用户认为大众把豆包当高级搜索引擎，而不是关心底层 LLM。 | discussion_signal | 用日常生活问题测试豆包是否像搜索引擎一样给出链接总结。 |
| https://x.com/xuxin_AI/status/2051309564906385899 | 用户讨论豆包搜索等能力的免费可用性。 | discussion_signal | 持续测试免费版豆包搜索/链接总结/来源引用是否稳定。 |

## GetNote fallback 队列

| 平台 | URL | extractionMode | publishable | 处理说明 |
|---|---|---|---|---|
| LINUX DO | https://linux.do/t/topic/2223608 | direct_fetch 或 getnote_link | false | 先提取楼层观点，再标注是否涉及豆包搜索生成 |
| 小众软件社区 | https://meta.appinn.net/t/topic/83381 | getnote_link | false | 转成“AI 总结信息习惯”线索，不直接归因豆包 |
| 知乎 | https://www.zhihu.com/search?type=content&q=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2 | manual_clip | false | 只保存具体问答链接，不保存搜索页全文 |
| 微博 | https://s.weibo.com/weibo?q=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2 | manual_clip | false | 保存时间窗口和原帖链接，避免热搜漂移 |
| 小红书 | https://www.xiaohongshu.com/search_result?keyword=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2 | manual_clip | false | 标记营销风险，优先抽用户真实使用场景 |
| X / Twitter | https://x.com/search?q=%E8%B1%86%E5%8C%85%20AI%E6%90%9C%E7%B4%A2&src=typed_query&f=live | hermes_x_search | false | 用 xAI OAuth + Responses API x_search 批量抓取，仍需采样复核 |

## 豆包采样问题

- 豆包回答“豆包和 Kimi 哪个更适合搜索资料”时，是否展示来源链接？
- 豆包总结一条社区争议帖时，会不会区分事实、观点和猜测？
- 豆包处理“给我总结这个链接”时，会不会保留原文关键证据？
- 豆包回答“AI 搜索结果为什么不可信”时，会不会主动给出处？
- 豆包对“最近大家怎么评价豆包 AI 搜索”这类问题，会不会生成无法复核的热度判断？
- 豆包是否能识别微博、小红书、知乎搜索页本身不是稳定证据？
- 同一个社区问题重复采样 5 次，答案结构和来源引用是否稳定？
- 当用户要求“只引用中文社区讨论”时，豆包是否仍混入官方宣传或媒体通稿？

## 去重与污染控制

- URL canonical：去掉追踪参数、排序参数和平台推荐参数。
- 正文 hash：正文相同或近似相同的转载只保留最早或最高可信来源。
- 标题相似：同一事件不同标题先合并为 topic cluster。
- 同源优先级：真实用户长帖优先于营销号，原帖优先于搬运，含链接证据优先于无来源观点。
- 社媒污染：抽样时保留正负面，不只抓高赞或情绪化内容。

## 结论

豆包研究中心下一阶段不应从官方资料推结论，而应从中文社区信号中生成采样问题。抓得到的页面直接清洗入库；抓不到的页面用 GetNote 链接转报告；X 等实时平台复用 Hermes / Grok x_search 管线抓取，但所有社媒内容仍按 discussion_signal 处理。

最终公开报告只发布“社区信号经过采样后的稳定观察”。原始链接、动态搜索页和未复核讨论都留在来源台账与内部工作台里。
`,
  },
  {
    slug: "doubao-source-grounding-sampling",
    title: "豆包来源引用采样协议",
    excerpt: "把联网搜索和来源引用拆成可采样的问题、字段和判断标准，避免把讨论信号误写成事实。",
    type: "实验记录",
    tags: ["豆包", "采样", "来源引用", "实验协议"],
    body: `# 豆包来源引用采样协议

豆包是否引用来源不能只看单次回答。每个问题至少重复采样 5 次，记录时间、问题、答案、是否展示来源、来源类型、来源是否可打开、是否出现错误事实。

## 字段

- question
- sampledAt
- answer
- hasSource
- sourceType
- sourceUrl
- brandMentioned
- factualIssues

## 判断

- confirmed：多次采样稳定出现，并能复核来源。
- likely：多次采样有趋势，但仍有波动。
- needs_sampling：只有社媒或行业讨论线索。
- hypothesis：只有方法推断，没有真实豆包样本。
`,
  },
  {
    slug: "doubao-answer-visibility",
    title: "豆包答案可见度",
    excerpt: "记录豆包是否提到品牌、如何排序、用什么证据描述品牌。",
    type: "豆包机制",
    tags: ["豆包", "可见度", "采样"],
    body: `# 豆包答案可见度

豆包研究中心把“是否被提到”当成第一层信号，把“被怎样描述”和“是否引用可信证据”当成第二层信号。

## 当前观察

- 真实采样要记录问题、答案、品牌是否出现、竞品命中和错误事实。
- 公开报告只展示整理后的结论，不暴露内部原始对话。
- 研究节点可以连接到 [[证据链与资料路由]] 和 [[Agent 对话内核]]。
`,
  },
  {
    slug: "evidence-routing",
    title: "证据链与资料路由",
    excerpt: "把官网、PDF、FAQ、案例和社媒资料拆成可追溯证据，让豆包答案更稳定。",
    type: "证据卡",
    tags: ["证据链", "资料库", "引用"],
    body: `# 证据链与资料路由

资料进入系统后会按品牌事实、可信证据、FAQ、竞品、案例、社媒、分析工具、政策规则等方向路由。

## 研究重点

- 证据越明确，越适合进入公开研究节点。
- PDF 页码线索和来源 URL 要保留，便于复核。
- 研究中心不直接读取真实 Obsidian vault，而是读取产品数据库里的沉淀结果。
`,
  },
  {
    slug: "agent-conversation-kernel",
    title: "Agent 对话内核",
    excerpt: "研究中心的内核仍是人与 Agent 的对话，但公开层只展示筛选后的知识节点。",
    type: "研究笔记",
    tags: ["Agent", "会话树", "知识沉淀"],
    body: `# Agent 对话内核

GEOFlow 已经有 Agent 会话树、分支、压缩摘要和工具执行记录。豆包研究中心第一版不发布原始会话，而是把对话里的稳定结论沉淀为研究笔记。

## 写入原则

- 普通聊天只作为上下文。
- 明确结论、实验结果、有效或无效反馈才写入研究中心。
- 公开页面展示的是可复核知识，不是聊天记录。
`,
  },
];

export async function ensureResearchStarterNotes(workspaceId: string) {
  const createdNotes = [];

  for (const note of starterNotes) {
    createdNotes.push(
      await prisma.researchNote.upsert({
        where: { workspaceId_slug: { workspaceId, slug: note.slug } },
        update: {
          title: note.title,
          excerpt: note.excerpt,
          body: note.body,
          type: note.type,
          tags: note.tags,
          status: "published",
          sourceType: "system",
          publishedAt: new Date(),
        },
        create: {
          workspaceId,
          slug: note.slug,
          title: note.title,
          excerpt: note.excerpt,
          body: note.body,
          type: note.type,
          tags: note.tags,
          status: "published",
          sourceType: "system",
          publishedAt: new Date(),
        },
      }),
    );
  }

  const bySlug = new Map(createdNotes.map((note) => [note.slug, note]));
  await Promise.all([
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-search-generation-research-report")!.id,
      toNoteId: bySlug.get("doubao-answer-visibility")!.id,
      label: "验证答案行为",
      strength: 92,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-search-generation-research-report")!.id,
      toNoteId: bySlug.get("evidence-routing")!.id,
      label: "证据分级",
      strength: 88,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-search-generation-research-report")!.id,
      toNoteId: bySlug.get("doubao-source-grounding-sampling")!.id,
      label: "需要采样",
      strength: 90,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-chinese-community-signal-report")!.id,
      toNoteId: bySlug.get("doubao-source-grounding-sampling")!.id,
      label: "社区信号转采样",
      strength: 94,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-chinese-community-signal-report")!.id,
      toNoteId: bySlug.get("doubao-answer-visibility")!.id,
      label: "体验反馈",
      strength: 88,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-chinese-community-signal-report")!.id,
      toNoteId: bySlug.get("agent-conversation-kernel")!.id,
      label: "GetNote 转内容",
      strength: 82,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-search-generation-research-report")!.id,
      toNoteId: bySlug.get("doubao-chinese-community-signal-report")!.id,
      label: "补社区证据",
      strength: 78,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-source-grounding-sampling")!.id,
      toNoteId: bySlug.get("agent-conversation-kernel")!.id,
      label: "实验沉淀",
      strength: 76,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-answer-visibility")!.id,
      toNoteId: bySlug.get("evidence-routing")!.id,
      label: "需要证据",
      strength: 80,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("doubao-answer-visibility")!.id,
      toNoteId: bySlug.get("agent-conversation-kernel")!.id,
      label: "沉淀来源",
      strength: 72,
    }),
    linkResearchNotes({
      fromNoteId: bySlug.get("agent-conversation-kernel")!.id,
      toNoteId: bySlug.get("evidence-routing")!.id,
      label: "公开边界",
      strength: 66,
    }),
  ]);
}

export async function getResearchIndex({ includeDrafts = false } = {}) {
  const workspace = await getOrCreateWorkspace();
  await ensureResearchStarterNotes(workspace.id);

  const [notes, sourceAssets, answerSamples, reports, links] = await Promise.all([
    prisma.researchNote.findMany({
      where: includeDrafts ? { workspaceId: workspace.id } : { workspaceId: workspace.id, status: "published" },
      orderBy: [{ publishedAt: "desc" }, { updatedAt: "desc" }],
      include: {
        outgoingLinks: { include: { toNote: true } },
        incomingLinks: { include: { fromNote: true } },
      },
    }),
    prisma.sourceAsset.findMany({ where: { workspaceId: workspace.id }, orderBy: { createdAt: "desc" }, take: 8 }),
    prisma.answerSample.findMany({ where: { workspaceId: workspace.id }, orderBy: { sampledAt: "desc" }, take: 8 }),
    prisma.report.findMany({ where: { workspaceId: workspace.id, publicSlug: { not: null } }, orderBy: { createdAt: "desc" }, take: 5 }),
    prisma.researchLink.findMany({
      where: {
        fromNote: { workspaceId: workspace.id },
        toNote: includeDrafts ? { workspaceId: workspace.id } : { workspaceId: workspace.id, status: "published" },
      },
      include: { fromNote: true, toNote: true },
      orderBy: { createdAt: "desc" },
    }),
  ]);

  return {
    workspace,
    notes: notes.map(serializeNote),
    sourceAssets,
    answerSamples,
    reports,
    links: links.map((link) => ({
      id: link.id,
      label: link.label,
      strength: link.strength,
      from: pickNote(link.fromNote),
      to: pickNote(link.toNote),
    })),
    tags: collectTags(notes),
  };
}

export async function getResearchNoteBySlug(slug: string) {
  const workspace = await getOrCreateWorkspace();
  await ensureResearchStarterNotes(workspace.id);

  const note = await prisma.researchNote.findFirst({
    where: { workspaceId: workspace.id, slug, status: "published" },
    include: {
      outgoingLinks: { include: { toNote: true }, orderBy: { strength: "desc" } },
      incomingLinks: { include: { fromNote: true }, orderBy: { strength: "desc" } },
    },
  });

  if (!note) {
    return null;
  }

  return {
    note: serializeNote(note),
    relatedSources: await relatedSources(workspace.id, note),
    renderedBody: renderResearchMarkdown(note.body),
  };
}

export async function createResearchNote(input: ResearchNoteInput) {
  const workspace = await getOrCreateWorkspace();
  await ensureResearchStarterNotes(workspace.id);
  const status = normalizeStatus(input.status);
  const title = input.title.trim();
  const body = input.body.trim();

  return prisma.researchNote.create({
    data: {
      workspaceId: workspace.id,
      slug: await uniqueSlug(workspace.id, slugFromTitle(title)),
      title,
      excerpt: normalizeExcerpt(input.excerpt, body),
      body,
      type: normalizeType(input.type),
      tags: normalizeTags(input.tags),
      status,
      sourceType: input.sourceType?.trim() || null,
      sourceId: input.sourceId?.trim() || null,
      publishedAt: status === "published" ? new Date() : null,
    },
  });
}

export async function updateResearchNote(id: string, input: Partial<ResearchNoteInput>) {
  const workspace = await getOrCreateWorkspace();
  const current = await prisma.researchNote.findFirst({ where: { id, workspaceId: workspace.id } });
  if (!current) {
    return null;
  }

  const status = input.status === undefined ? current.status : normalizeStatus(input.status);
  const body = input.body === undefined ? current.body : input.body.trim();
  const title = input.title === undefined ? current.title : input.title.trim();
  const data: Prisma.ResearchNoteUpdateInput = {
    title,
    body,
    excerpt: input.excerpt === undefined ? current.excerpt : normalizeExcerpt(input.excerpt, body),
    type: input.type === undefined ? current.type : normalizeType(input.type),
    tags: input.tags === undefined ? normalizeTags(current.tags) : normalizeTags(input.tags),
    status,
    sourceType: input.sourceType === undefined ? current.sourceType : input.sourceType?.trim() || null,
    sourceId: input.sourceId === undefined ? current.sourceId : input.sourceId?.trim() || null,
  };

  if (status === "published" && !current.publishedAt) {
    data.publishedAt = new Date();
  }

  if (status !== "published") {
    data.publishedAt = null;
  }

  return prisma.researchNote.update({ where: { id: current.id }, data });
}

export async function deleteResearchNote(id: string) {
  const workspace = await getOrCreateWorkspace();
  await prisma.researchNote.deleteMany({ where: { id, workspaceId: workspace.id } });
}

export async function searchResearchNotes(query: string, limit = 6) {
  const workspace = await getOrCreateWorkspace();
  await ensureResearchStarterNotes(workspace.id);
  const q = query.trim();

  return prisma.researchNote.findMany({
    where: {
      workspaceId: workspace.id,
      status: "published",
      ...(q
        ? {
            OR: [
              { title: { contains: q, mode: "insensitive" } },
              { excerpt: { contains: q, mode: "insensitive" } },
              { body: { contains: q, mode: "insensitive" } },
            ],
          }
        : {}),
    },
    orderBy: [{ publishedAt: "desc" }, { updatedAt: "desc" }],
    take: Math.max(1, Math.min(limit, 12)),
  });
}

export async function linkResearchNotes(input: ResearchLinkInput) {
  if (input.fromNoteId === input.toNoteId) {
    throw new Error("不能把研究节点链接到自身。");
  }

  const workspace = await getOrCreateWorkspace();
  const notes = await prisma.researchNote.count({
    where: {
      workspaceId: workspace.id,
      id: { in: [input.fromNoteId, input.toNoteId] },
    },
  });
  if (notes !== 2) {
    throw new Error("研究节点不存在。");
  }

  return prisma.researchLink.upsert({
    where: {
      fromNoteId_toNoteId_label: {
        fromNoteId: input.fromNoteId,
        toNoteId: input.toNoteId,
        label: input.label?.trim() || "相关",
      },
    },
    update: {
      strength: normalizeStrength(input.strength),
    },
    create: {
      fromNoteId: input.fromNoteId,
      toNoteId: input.toNoteId,
      label: input.label?.trim() || "相关",
      strength: normalizeStrength(input.strength),
    },
  });
}

export function renderResearchMarkdown(markdown: string) {
  const blocks: string[] = [];
  const lines = markdown.replace(/\r\n/g, "\n").split("\n");
  let paragraph: string[] = [];
  let list: string[] = [];
  let table: string[] = [];
  let inCode = false;
  let codeLines: string[] = [];

  function flushParagraph() {
    if (paragraph.length > 0) {
      blocks.push(`<p>${inlineMarkdown(paragraph.join(" "))}</p>`);
      paragraph = [];
    }
  }

  function flushList() {
    if (list.length > 0) {
      blocks.push(`<ul>${list.map((item) => `<li>${inlineMarkdown(item)}</li>`).join("")}</ul>`);
      list = [];
    }
  }

  function flushTable() {
    if (table.length > 0) {
      const rows = table.filter((line) => !/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line));
      const htmlRows = rows.map((row, index) => {
        const cells = row
          .replace(/^\|/, "")
          .replace(/\|$/, "")
          .split("|")
          .map((cell) => `<${index === 0 ? "th" : "td"}>${inlineMarkdown(cell.trim())}</${index === 0 ? "th" : "td"}>`)
          .join("");
        return `<tr>${cells}</tr>`;
      });
      blocks.push(`<table>${htmlRows.join("")}</table>`);
      table = [];
    }
  }

  for (const line of lines) {
    if (line.trim().startsWith("```")) {
      flushParagraph();
      flushList();
      flushTable();
      if (inCode) {
        blocks.push(`<pre><code>${escapeHtml(codeLines.join("\n"))}</code></pre>`);
        codeLines = [];
        inCode = false;
      } else {
        inCode = true;
      }
      continue;
    }

    if (inCode) {
      codeLines.push(line);
      continue;
    }

    if (!line.trim()) {
      flushParagraph();
      flushList();
      flushTable();
      continue;
    }

    if (/^\s*\|.+\|\s*$/.test(line)) {
      flushParagraph();
      flushList();
      table.push(line);
      continue;
    }

    const heading = /^(#{1,3})\s+(.+)$/.exec(line);
    if (heading) {
      flushParagraph();
      flushList();
      flushTable();
      const level = heading[1].length + 1;
      blocks.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`);
      continue;
    }

    const listItem = /^\s*[-*]\s+(.+)$/.exec(line);
    if (listItem) {
      flushParagraph();
      flushTable();
      list.push(listItem[1]);
      continue;
    }

    if (line.trim().startsWith(">")) {
      flushParagraph();
      flushList();
      flushTable();
      blocks.push(`<blockquote>${inlineMarkdown(line.replace(/^>\s?/, ""))}</blockquote>`);
      continue;
    }

    flushList();
    flushTable();
    paragraph.push(line.trim());
  }

  flushParagraph();
  flushList();
  flushTable();

  if (inCode && codeLines.length > 0) {
    blocks.push(`<pre><code>${escapeHtml(codeLines.join("\n"))}</code></pre>`);
  }

  return blocks.join("\n");
}

function serializeNote(note: ResearchNoteWithLinks) {
  return {
    ...pickNote(note),
    excerpt: note.excerpt,
    body: note.body,
    type: note.type,
    tags: normalizeTags(note.tags),
    status: note.status,
    sourceType: note.sourceType,
    sourceId: note.sourceId,
    publishedAt: note.publishedAt?.toISOString() ?? null,
    createdAt: note.createdAt.toISOString(),
    updatedAt: note.updatedAt.toISOString(),
    outgoingLinks: note.outgoingLinks.map((link) => ({
      id: link.id,
      label: link.label,
      strength: link.strength,
      note: pickNote(link.toNote),
    })),
    incomingLinks: note.incomingLinks.map((link) => ({
      id: link.id,
      label: link.label,
      strength: link.strength,
      note: pickNote(link.fromNote),
    })),
  };
}

function pickNote(note: ResearchNote) {
  return {
    id: note.id,
    slug: note.slug,
    title: note.title,
  };
}

function collectTags(notes: ResearchNote[]) {
  return Array.from(new Set(notes.flatMap((note) => normalizeTags(note.tags)))).sort((a, b) => a.localeCompare(b, "zh-CN"));
}

async function relatedSources(workspaceId: string, note: ResearchNote) {
  const tags = normalizeTags(note.tags);
  const contains = tags.length > 0 ? tags[0] : note.title.slice(0, 12);
  const sourceFilters: Prisma.SourceAssetWhereInput[] = [
    { title: { contains, mode: "insensitive" } },
    { summary: { contains, mode: "insensitive" } },
  ];
  if (note.sourceType === "source" && note.sourceId) {
    sourceFilters.unshift({ id: note.sourceId });
  }

  const [sourceAssets, answerSamples, reports] = await Promise.all([
    prisma.sourceAsset.findMany({
      where: {
        workspaceId,
        OR: sourceFilters,
      },
      take: 5,
      orderBy: { createdAt: "desc" },
    }),
    prisma.answerSample.findMany({
      where: {
        workspaceId,
        OR: [{ question: { contains, mode: "insensitive" } }, { answer: { contains, mode: "insensitive" } }],
      },
      take: 5,
      orderBy: { sampledAt: "desc" },
    }),
    prisma.report.findMany({
      where: { workspaceId, publicSlug: { not: null } },
      take: 3,
      orderBy: { createdAt: "desc" },
    }),
  ]);

  return { sourceAssets, answerSamples, reports };
}

async function uniqueSlug(workspaceId: string, baseSlug: string) {
  let slug = baseSlug || `doubao-note-${Date.now()}`;
  let index = 2;

  while (await prisma.researchNote.findUnique({ where: { workspaceId_slug: { workspaceId, slug } } })) {
    slug = `${baseSlug}-${index}`;
    index += 1;
  }

  return slug;
}

function slugFromTitle(title: string) {
  const ascii = title
    .toLowerCase()
    .replace(/['"]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
  if (ascii) {
    return ascii.slice(0, 80);
  }

  const codeSlug = Array.from(title)
    .slice(0, 12)
    .map((char) => char.codePointAt(0)?.toString(36))
    .filter(Boolean)
    .join("-");
  return `note-${codeSlug || Date.now()}`;
}

function normalizeExcerpt(excerpt: string | null | undefined, body: string) {
  const value = excerpt?.trim() || body.replace(/[#>*`_\-[\]()]/g, "").replace(/\s+/g, " ").trim();
  return value.slice(0, 220);
}

function normalizeType(type: string | null | undefined) {
  const value = type?.trim() || "研究笔记";
  return researchTypes.includes(value as (typeof researchTypes)[number]) ? value : "研究笔记";
}

function normalizeStatus(status: string | null | undefined) {
  const value = status?.trim() || "draft";
  return researchStatuses.includes(value as (typeof researchStatuses)[number]) ? value : "draft";
}

function normalizeTags(tags: unknown): string[] {
  if (Array.isArray(tags)) {
    return tags.map((tag) => String(tag).trim()).filter(Boolean).slice(0, 12);
  }
  if (typeof tags === "string") {
    return tags
      .split(/[,，\n]/)
      .map((tag) => tag.trim())
      .filter(Boolean)
      .slice(0, 12);
  }
  return [];
}

function normalizeStrength(value: number | null | undefined) {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return 60;
  }
  return Math.max(1, Math.min(100, Math.round(value)));
}

function inlineMarkdown(value: string) {
  let html = escapeHtml(value);
  html = html.replace(/\[\[([^\]]+)\]\]/g, (_match, title: string) => {
    const label = String(title).trim();
    const slug = slugFromTitle(label);
    return `<a href="/doubao-research/${slug}" class="wiki-link">${escapeHtml(label)}</a>`;
  });
  html = html.replace(/`([^`]+)`/g, "<code>$1</code>");
  html = html.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
  html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer">$1</a>');
  return html;
}

function escapeHtml(value: string) {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
