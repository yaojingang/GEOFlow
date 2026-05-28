# 豆包 X 社区信号抓取运行记录

- generatedAt: 2026-05-28T16:28:12.504Z
- query: 豆包 搜索 来源 OR 豆包 百家号 OR 豆包 内容农场 OR 豆包 引用 OR Doubao source quality
- method: Hermes-derived xAI OAuth + Responses API x_search
- sourcesUsed: 0
- publishPolicy: all X posts are discussion signals; publishable=false until Doubao sampling and review

## Signals

| URL | Author | Tier | Type | Confidence | Why it matters | Doubao sampling question |
|---|---|---|---|---:|---|---|
| https://x.com/JanboMikan/status/2057684743500763471 | @JanboMikan | discussion_signal | source_grounding | 0.85 | 用户直接指出豆包搜索优先百家号和内容农场内容，与ChatGPT形成对比，提示来源质量问题。 | 请搜索并总结最近关于AI监管的新闻，优先列出来源和权威性评估。 |
| https://x.com/Astronaut_1216/status/2033492454599483442 | @Astronaut_1216 | discussion_signal | source_grounding | 0.75 | 用户观察到豆包主要抓取百家号文章，反映内容农场依赖。 | 搜索‘AI监管最新政策’并列出所有引用来源及平台类型。 |
| https://x.com/weiyux2021/status/2054762209054523508 | @weiyux2021 | discussion_signal | source_grounding | 0.8 | 用户分享豆包直接引用SEO虚假数据，需手动核实才能修正，暴露来源验证弱点。 | 根据网络信息总结某行业虚假数据案例，并检查来源真实性。 |
| https://x.com/Triticale_eyyy/status/2057476014612349033 | @Triticale_eyyy | discussion_signal | risk_rule | 0.7 | 用户强调豆包搜索需额外要求来源，尤其医疗健康领域风险提示不足。 | 搜索并回答‘某种健康问题治疗建议’，务必提供来源和免责声明。 |
| https://x.com/leaf_sanren/status/2057447647817191540 | @leaf_sanren | discussion_signal | risk_rule | 0.65 | 用户建议父亲使用豆包时必须检验来源，尤其医疗数据。 | 提供关于某医疗话题的搜索总结，并标注所有数据来源。 |
| https://x.com/maomaofeng99787/status/2055978330357391844 | @maomaofeng99787 | discussion_signal | source_grounding | 0.6 | 讨论豆包等国内AI搜索的独特引用规则，与国际平台差异。 | 对比豆包与Kimi的搜索引用来源类型差异。 |
| https://x.com/drmrzhong/status/2037179741275627536 | @drmrzhong | discussion_signal | content_workflow | 0.7 | 指出豆包等国内AI搜索的平台特定内容布局策略。 | 如何优化内容以被豆包搜索优先引用？ |

## Source Excerpts

### 1. @JanboMikan · 10 likes

- URL: https://x.com/JanboMikan/status/2057684743500763471
- postedAt: 2026-05-22T04:47:27Z
- publishable: false

> 豆包搜索来源都是百家号什么的营销号，以及内容农场，还有大量本来就是ai生成的内容 ChatGPT的搜索就会很注重区分权威来源 并且还要交叉验证

### 2. @Astronaut_1216 · 0 likes

- URL: https://x.com/Astronaut_1216/status/2033492454599483442
- postedAt: 2026-03-16T10:35:56Z
- publishable: false

> 大多都是通过联网搜索的。你像豆包、Deepseek他们抓的都是一些百家号的文章，豆包有一些抖音的数据

### 3. @weiyux2021 · 28 likes

- URL: https://x.com/weiyux2021/status/2054762209054523508
- postedAt: 2026-05-14T03:14:20Z
- publishable: false

> 正常情况下，豆包只会直接引用这些网络信息，不会主动去核实真伪。但只要你主动告知豆包“这条信息有误，请再重新核实一下”，它就能排查虚假内容

### 4. @Triticale_eyyy · 3 likes

- URL: https://x.com/Triticale_eyyy/status/2057476014612349033
- postedAt: 2026-05-21T14:58:02Z
- publishable: false

> 我无论用什么AI我最后都会要求提供信息来源/让它连网搜索去回答问题 本来现在幻觉就严重 豆包没有提示心里或者健康问题 需仔细审核或者询问医师吗

### 5. @leaf_sanren · 0 likes

- URL: https://x.com/leaf_sanren/status/2057447647817191540
- postedAt: 2026-05-21T13:05:19Z
- publishable: false

> 只求他看结果时候，检验一下数据来源；询问医疗建议的时候，宁可使用蚂蚁阿福呢

### 6. @maomaofeng99787 · 0 likes

- URL: https://x.com/maomaofeng99787/status/2055978330357391844
- postedAt: 2026-05-17T11:46:46Z
- publishable: false

> 中国AI搜索——豆包、Kimi、DeepSeek、通义千问——各有各的引用规则

### 7. @drmrzhong · 3 likes

- URL: https://x.com/drmrzhong/status/2037179741275627536
- postedAt: 2026-03-26T14:47:53Z
- publishable: false

> 国内平台的差异化：国内AI（豆包、DeepSeek、元宝、Kimi）与国际平台生态完全不同，需针对性布局
