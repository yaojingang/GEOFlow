# Doubao GitHub ecosystem signal run

Run date: 2026-05-28

Purpose: extend the Doubao Research Center from X/community discourse into GitHub ecosystem signals. GitHub repositories are treated as developer intent and integration evidence, not as product-performance proof.

## Query set

| Query | Mode | Result use |
|---|---|---|
| `topic:doubao sort:stars-desc` | GitHub repository search API | Measure broad Doubao-tagged OSS surface |
| `doubao web search in:name,description,readme sort:stars-desc` | GitHub repository search API | Find search-generation and web-search adjacent projects |
| `豆包 联网搜索 in:readme,description sort:stars-desc` | GitHub repository search API | Find Chinese README mentions of Doubao web search |
| `doubao volcengine web search in:readme,description sort:stars-desc` | GitHub repository search API | Find Volcengine and Doubao search integration signals |
| `doubao obsidian in:readme,description sort:stars-desc` | GitHub repository search API | Find knowledge-base / Obsidian adjacent signals |

## Snapshot findings

| Repository / source | Snapshot signal | Evidence tier | Research use |
|---|---|---|---|
| https://github.com/topics/doubao | GitHub topic page showed 110 public repositories tagged `doubao`, across Python, TypeScript, JavaScript, Rust, Kotlin, Go, HTML, C, Vue and Dart. | github_ecosystem_signal | Developer ecosystem breadth, not usage proof |
| https://github.com/langgptai/LangGPT | Topic result: about 12.1k stars; Doubao appears as one model/provider in structured prompt practice. | github_ecosystem_signal | Prompt ecosystem signal |
| https://github.com/ripperhe/Bob | Topic result: about 9.7k stars; Doubao appears alongside other providers in translation/OCR workflow. | github_ecosystem_signal | Utility workflow integration signal |
| https://github.com/Ayush0Chaudhary/blurr | Topic result: about 922 stars; Android operator / mobile-use project tags include Doubao/open-doubao. | github_ecosystem_signal | Mobile agent surface signal |
| https://github.com/BryceWG/BiBi-Keyboard | Topic result: about 650 stars; ASR keyboard supports cloud/local engines and LLM post-processing; Doubao appears in tags. | github_ecosystem_signal | Voice-input and daily text-entry signal |
| https://github.com/TarsLab/obsidian-tars | Topic result: about 299 stars; Obsidian plugin supports text generation with Doubao among many providers. | github_ecosystem_signal | Knowledge-base integration signal |
| https://github.com/LLM-Red-Team/doubao-free-api | README/about positions it as reverse Doubao API with web-search strength, testing-only, commercial use should go official. | risk_signal | Reverse ecosystem and policy-risk signal |
| https://github.com/YuhaoYeSteve/Doubao-VolcEngine | README/changelog mentions Volcengine Web Search integration, “thinking/searching” status, and stream parsing improvements. | implementation_signal | Search UX and transparency pattern |
| https://github.com/bytedance/deer-flow | GitHub search result strongly surfaced ByteDance open-source long-horizon research/coding agent harness. | infrastructure_context | Background: ByteDance is exposing agent/research infrastructure patterns |
| https://github.com/volcengine/OpenViking | GitHub search result surfaced Volcengine context database for AI agents. | infrastructure_context | Background: memory/context infrastructure relevant to GEOFlow research center |

## Research signals

| Signal | Claim type | Verification status | Publishable |
|---|---|---|---|
| Doubao is visible in broad OSS provider lists rather than only in single-purpose Doubao apps. | ecosystem_presence | verified_by_github_snapshot | true |
| Developer usage clusters around prompt workflows, utilities, mobile/voice input, Obsidian/knowledge base, gateway/provider integration and reverse API access. | usage_pattern | github_snapshot_only | true |
| Reverse API projects indicate demand for Doubao app-like capabilities, especially web-search behavior, but they are not safe production integration evidence. | risk_signal | github_snapshot_only | true |
| GitHub does not prove Doubao answer quality, source quality, hallucination rate or real user satisfaction. | boundary_rule | active | true |
| GitHub projects can produce sampling questions for `/doubao-research`, especially around search transparency, provider switching and Obsidian-style knowledge workflows. | research_method | active | true |

## Candidate research note

Slug: `doubao-github-ecosystem-signal-map`

Title: `豆包 GitHub 生态信号地图`

Core thesis: GitHub shows that Doubao is being treated less as a standalone chat product and more as an embeddable provider / workflow component. The strongest research value is not “which repo is popular”, but the developer mental model revealed by integrations: prompt, translation/OCR, voice input, Obsidian knowledge base, mobile agent, web-search clone and reverse API.

## Sampling questions generated

- When Doubao is used as one provider among many, does it win on search freshness, Chinese language quality, price, speed, or convenience?
- In Obsidian-style workflows, can Doubao reliably summarize notes with source preservation, or does it compress citations away?
- In voice-input workflows, does speech mode change question length, ambiguity and answer reliability?
- In web-search UI clones, does exposing “thinking/searching” state increase user trust even when sources are weak?
- Can reverse API demand be used as a proxy for missing official developer ergonomics, without normalizing unsafe integration?

