# Doubao GEO signal run

Run date: 2026-05-28

Purpose: narrow the Doubao Research Center from broad Doubao ecosystem signals to Doubao-specific GEO: answer visibility, citation/source routing, brand recommendation, monitoring, prompt/question sets, and safety boundaries.

## Query set

| Query | Mode | Result use |
|---|---|---|
| `豆包 GEO 生成式引擎优化 豆包 答案 可见度` | Web search | Find Chinese Doubao GEO services and claims |
| `豆包 AI 搜索 GEO 品牌 推荐 答案 优化` | Web search | Find brand recommendation and ranking claims |
| `site:github.com 豆包 GEO AI搜索 品牌 答案` | Web/GitHub search | Check whether Doubao GEO has OSS tooling |
| `doubao geo in:readme,description sort:updated-desc` | GitHub repository search API | Find direct GitHub matches |
| `llms.txt doubao geo in:readme,description sort:updated-desc` | GitHub repository search API | Find AEO/GEO audit tooling adjacent to llms.txt |

## Snapshot findings

| Source | Snapshot signal | Evidence tier | Research use |
|---|---|---|---|
| https://www.bosougeo.com/ | Service page claims Doubao ranking cases and category-specific GEO outcomes. | vendor_claim | Market signal only; requires independent sampling |
| https://anymorph.ai/guide/china-ai-search-optimization-for-doubao-deepseek-kimi-ernie | Guide frames China GEO as model-specific AI visibility work and claims Doubao needs ByteDance ecosystem / interaction signal attention. | vendor_method_claim | Method hypothesis; not direct product proof |
| https://arxiv.org/abs/2311.09735 | GEO paper formalizes generative engine visibility as black-box optimization and reports visibility improvements in generative responses. | academic_reference | Measurement framework and visibility metric foundation |
| https://github.com/webappski/aeo-platform | Open-source AEO/GEO CLI measures brands across answer engines, tracks mention/position/sentiment/citations and outputs action plans. | technical_reference | Adapt measurement concepts to Doubao |
| https://github.com/jiguang9/geo-audit | Small GEO audit skill repository. | weak_oss_signal | Shows audit-skill direction, not Doubao-specific |
| https://github.com/KnightMafiaLau/agent-ready-skill | Agent-readiness skill checks LLM bot permissions and AI crawlability. | technical_reference | Useful for Doubao-source-readiness audit |
| GitHub repository search for `doubao geo` | Direct Doubao GEO OSS hits were weak/noisy; no mature Doubao-specific open-source GEO tracker found in this pass. | negative_signal | Product opportunity: build Doubao-specific sampler |

## Research signals

| Signal | Claim type | Verification status | Publishable |
|---|---|---|---|
| Doubao GEO is currently more visible in vendor/service pages than in mature open-source tooling. | market_structure | web_snapshot | true |
| Service pages often claim ranking outcomes, but these must be treated as vendor claims until independent repeated sampling confirms them. | evidence_boundary | active | true |
| Doubao GEO needs measurement at the answer level: mention, rank, source citation, source quality, source openability, sentiment and factual drift. | measurement_method | derived | true |
| GitHub AEO tools are useful for schema and workflow, but need adaptation because Doubao does not expose the same official answer-engine API surface. | technical_gap | derived | true |
| GEO work can become AI-search poisoning if it optimizes low-quality pages or fabricated claims; Doubao research must keep source quality and claim verification central. | safety_rule | active | true |

## Candidate research note

Slug: `doubao-geo-signal-map`

Title: `豆包 GEO 信号地图：品牌如何进入答案`

Core thesis: Doubao GEO should be studied as an answer-level measurement and source-routing problem, not as a generic SEO copywriting problem. The first useful product is not a promise of ranking, but a repeatable sampler that records whether Doubao mentions the brand, why it mentions it, what sources it appears to rely on, and whether those sources are trustworthy.

## Sampling fields

- question
- intent_type
- brand_mentioned
- mention_rank
- competitor_mentions
- answer_position
- sentiment
- source_visible
- source_urls
- source_type
- source_quality
- source_openable
- answer_claims
- factual_issues
- hallucination_risk
- search_transparency
- byte_ecosystem_signal
- recommended_content_asset

