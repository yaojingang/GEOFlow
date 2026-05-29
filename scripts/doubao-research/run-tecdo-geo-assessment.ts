#!/usr/bin/env node

import { mkdir, writeFile } from "node:fs/promises";
import { join } from "node:path";

type ModelResponse = {
  choices?: Array<{ message?: { content?: string } }>;
};

type PageLedger = {
  url: string;
  sourceType: "official" | "official_meta" | "search_result";
  status: number | "error";
  title: string;
  excerpt: string;
  evidenceTier: "official_confirmed" | "media_claim" | "discussion_signal" | "not_found";
  keywordHits: string[];
};

type SearchResult = {
  query: string;
  rank: number;
  title: string;
  snippet: string;
  url: string;
  keywordHits: string[];
};

type DoubaoClaim = {
  claim: string;
  evidenceLevel: "doubao_claim" | "hallucination_risk" | "needs_check" | "not_found";
  sourceHint?: string;
};

type DoubaoAnswer = {
  question: string;
  answer: string;
  claims: DoubaoClaim[];
  saysHasGeo: boolean;
  saysCompetitor: boolean;
  recommendedReason: string;
};

type Score = {
  dimension: string;
  tecdoScore: number;
  geoflowScore: number;
  reason: string;
};

const outDir = process.env.DOUBAO_RESEARCH_OUT ?? join(process.cwd(), "research-runs/tecdo-geo-assessment");
const requestTimeoutMs = 45_000;
const maxAttempts = 3;

const officialUrls = [
  "https://www.tec-do.com",
  "https://www.tec-do.com/about.html",
  "https://www.tec-do.com/about/",
  "https://www.tec-do.com/navos/",
  "https://www.tec-do.com/tec-chi/",
  "https://www.tec-do.com/cases/",
  "https://www.tec-do.com/news/",
  "https://www.tec-do.com/investor/",
  "https://www.tec-do.com/sitemap.xml",
  "https://www.tec-do.com/robots.txt",
  "https://www.tec-do.com/llms.txt",
];

const searchQueries = [
  "钛动科技 GEO",
  "钛动科技 生成式引擎优化",
  "钛动科技 AI 搜索优化",
  "钛动科技 SEO",
  "钛动科技 Navos",
  "钛动科技 钛极 大模型",
  "Tec-Do GEO",
  "Tec-Do AI search optimization",
  "钛动科技 研报 AI 营销",
  "钛动科技 招聘 SEO",
];

const doubaoQuestions = [
  "钛动科技是什么？请区分官网事实和推断。",
  "钛动科技是否提供 GEO 服务？请给官网证据，如果没有证据请明确说没有找到。",
  "钛动科技为什么适合做 GEO 和 SEO 优化服务？哪些只是推断？",
  "钛动科技 Navos 能不能监测品牌在豆包、ChatGPT、DeepSeek 答案里的可见度？",
  "钛动科技和 GEOFlow 是竞品吗？请按直接竞品、间接竞品、非竞品判断。",
  "钛动科技、增长超人、珍岛集团、GEOFlow 谁更适合做豆包 GEO？请说明依据。",
  "钛动科技有哪些 AI 营销能力可以迁移到 GEO？哪些能力还缺证据？",
];

const evidenceKeywords = [
  "GEO",
  "生成式引擎优化",
  "AI搜索",
  "AI 搜索",
  "搜索优化",
  "SEO",
  "豆包",
  "DeepSeek",
  "ChatGPT",
  "品牌可见度",
  "答案可见度",
  "llms.txt",
  "Navos",
  "钛极",
  "多智能体",
  "大模型",
  "出海",
  "广告",
  "营销",
  "投放",
  "数据分析",
  "市场洞察",
];

function decodeEntities(value: string) {
  return value
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(Number.parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec) => String.fromCodePoint(Number.parseInt(dec, 10)));
}

function stripTags(value: string) {
  return decodeEntities(
    value
      .replace(/<script[\s\S]*?<\/script>/gi, " ")
      .replace(/<style[\s\S]*?<\/style>/gi, " ")
      .replace(/<[^>]+>/g, " ")
      .replace(/\s+/g, " ")
      .trim(),
  );
}

function safeCell(value: string | number | boolean | undefined) {
  return String(value ?? "").replaceAll("|", "/").replace(/\s+/g, " ").trim();
}

function keywordHits(text: string) {
  const lower = text.toLowerCase();
  return evidenceKeywords.filter((keyword) => lower.includes(keyword.toLowerCase()));
}

function pageTitle(html: string, fallback: string) {
  const title = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1];
  return stripTags(title ?? fallback).slice(0, 140);
}

async function fetchWithTimeout(url: string, init: RequestInit, label: string) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), requestTimeoutMs);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") throw new Error(`${label} timed out`);
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

async function retry<T>(label: string, task: () => Promise<T>) {
  let lastError: unknown;
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      if (attempt > 1) console.error(`[retry ${attempt}/${maxAttempts}] ${label}`);
      return await task();
    } catch (error) {
      lastError = error;
      const message = error instanceof Error ? error.message : String(error);
      console.error(`[failed ${attempt}/${maxAttempts}] ${label}: ${message}`);
      if (attempt < maxAttempts) await new Promise((resolve) => setTimeout(resolve, 1200 * attempt));
    }
  }
  throw lastError;
}

async function fetchPageLedger(url: string): Promise<PageLedger> {
  try {
    const response = await fetchWithTimeout(
      url,
      {
        headers: {
          "User-Agent": "Mozilla/5.0 GEOFlow Research Bot; public evidence only",
          "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
        },
      },
      url,
    );
    const text = await response.text();
    const plain = stripTags(text);
    const hits = keywordHits(plain);
    return {
      url,
      sourceType: url.endsWith("sitemap.xml") || url.endsWith("robots.txt") || url.endsWith("llms.txt") ? "official_meta" : "official",
      status: response.status,
      title: pageTitle(text, url),
      excerpt: plain.slice(0, 520),
      evidenceTier: response.ok ? "official_confirmed" : "not_found",
      keywordHits: hits,
    };
  } catch (error) {
    return {
      url,
      sourceType: url.endsWith("sitemap.xml") || url.endsWith("robots.txt") || url.endsWith("llms.txt") ? "official_meta" : "official",
      status: "error",
      title: url,
      excerpt: error instanceof Error ? error.message : String(error),
      evidenceTier: "not_found",
      keywordHits: [],
    };
  }
}

async function fetchBingResults(query: string): Promise<SearchResult[]> {
  const url = `https://www.bing.com/search?q=${encodeURIComponent(query)}&cc=cn&setlang=zh-CN&count=10`;
  const response = await fetchWithTimeout(
    url,
    {
      headers: {
        "User-Agent":
          "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125 Safari/537.36",
        "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
      },
    },
    `Bing ${query}`,
  );
  if (!response.ok) throw new Error(`Bing failed ${response.status}`);
  const html = await response.text();
  const blocks = [...html.matchAll(/<li class="b_algo"[\s\S]*?<\/li>/g)].slice(0, 10);
  return blocks
    .map((match, index): SearchResult => {
      const block = match[0];
      const link = block.match(/<h2[^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i);
      const snippet = block.match(/<p[^>]*>([\s\S]*?)<\/p>/i);
      const item = {
        query,
        rank: index + 1,
        title: stripTags(link?.[2] ?? ""),
        snippet: stripTags(snippet?.[1] ?? ""),
        url: decodeEntities(link?.[1] ?? ""),
        keywordHits: [] as string[],
      };
      item.keywordHits = keywordHits(`${item.title} ${item.snippet} ${item.url}`);
      return item;
    })
    .filter((item) => item.title || item.snippet || item.url);
}

function extractJson(content: string) {
  const fenced = content.match(/```(?:json)?\s*([\s\S]*?)```/i)?.[1];
  const raw = fenced ?? content;
  const start = raw.indexOf("{");
  const end = raw.lastIndexOf("}");
  if (start === -1 || end === -1 || end <= start) throw new Error("No JSON object in model response");
  return JSON.parse(raw.slice(start, end + 1)) as Partial<DoubaoAnswer>;
}

async function callDoubao(question: string): Promise<DoubaoAnswer> {
  const apiKey = process.env.DOUBAO_API_KEY;
  if (!apiKey) throw new Error("DOUBAO_API_KEY is not configured");
  const baseUrl = process.env.DOUBAO_BASE_URL ?? "https://ark.cn-beijing.volces.com/api/v3";
  const model = process.env.DOUBAO_MODEL ?? "doubao-seed-2-0-pro-260215";
  const response = await fetchWithTimeout(
    `${baseUrl.replace(/\/$/, "")}/chat/completions`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${apiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        model,
        temperature: 0.1,
        response_format: { type: "json_object" },
        messages: [
          {
            role: "system",
            content: [
              "你是 GEOFlow 的证据审计研究员。只输出 JSON。",
              "GEO 指 Generative Engine Optimization / 生成式引擎优化，不是地理信息。",
              "不要把未见来源的推断写成事实。必须区分官网事实、行业推断、待验证和幻觉风险。",
              "输出格式：{\"answer\":\"...\",\"claims\":[{\"claim\":\"...\",\"evidenceLevel\":\"doubao_claim|hallucination_risk|needs_check|not_found\",\"sourceHint\":\"...\"}],\"saysHasGeo\":false,\"saysCompetitor\":false,\"recommendedReason\":\"...\"}",
            ].join("\n"),
          },
          {
            role: "user",
            content: `研究对象：钛动科技 Tec-Do。\n问题：${question}`,
          },
        ],
      }),
    },
    `Doubao ${question}`,
  );
  if (!response.ok) {
    const body = await response.text();
    throw new Error(`Doubao failed ${response.status}: ${body.slice(0, 500)}`);
  }
  const data = (await response.json()) as ModelResponse;
  const parsed = extractJson(data.choices?.[0]?.message?.content ?? "");
  return {
    question,
    answer: String(parsed.answer ?? "").trim(),
    claims: (parsed.claims ?? []).map((claim) => ({
      claim: String(claim.claim ?? "").trim(),
      evidenceLevel: ["doubao_claim", "hallucination_risk", "needs_check", "not_found"].includes(String(claim.evidenceLevel))
        ? (claim.evidenceLevel as DoubaoClaim["evidenceLevel"])
        : "needs_check",
      sourceHint: String(claim.sourceHint ?? "").trim(),
    })),
    saysHasGeo: Boolean(parsed.saysHasGeo),
    saysCompetitor: Boolean(parsed.saysCompetitor),
    recommendedReason: String(parsed.recommendedReason ?? "").trim(),
  };
}

function hasAny(items: Array<PageLedger | SearchResult>, words: string[]) {
  const haystack = items
    .map((item) => {
      const excerpt = "excerpt" in item ? item.excerpt : "";
      const snippet = "snippet" in item ? item.snippet : "";
      return `${item.title} ${excerpt} ${snippet} ${item.keywordHits.join(" ")}`;
    })
    .join("\n")
    .toLowerCase();
  return words.some((word) => haystack.includes(word.toLowerCase()));
}

function scoreAssessment(pages: PageLedger[], results: SearchResult[], doubao: DoubaoAnswer[]): Score[] {
  const officialGeo = hasAny(pages.filter((page) => page.evidenceTier === "official_confirmed"), ["生成式引擎优化", "AI搜索可见度", "答案可见度", "豆包 GEO", "GEO服务"]);
  const officialAi = hasAny(pages, ["Navos", "钛极", "多智能体", "大模型", "AI"]);
  const officialAds = hasAny(pages, ["出海", "广告", "营销", "投放", "Meta", "Google", "TikTok"]);
  const officialData = hasAny(pages, ["数据分析", "市场洞察", "数据", "洞察"]);
  const seoEvidence = hasAny([...pages, ...results], ["SEO", "搜索优化"]);
  const doubaoRecommended = doubao.some((answer) => /适合|推荐|靠前|营销|出海|AI/.test(answer.answer + answer.recommendedReason));
  return [
    {
      dimension: "AI 营销基础设施",
      tecdoScore: officialAi ? 5 : 2,
      geoflowScore: 3,
      reason: "钛动有 Navos / 钛极等公开 AI 营销叙事；GEOFlow 更偏研究与答案采样内核。",
    },
    {
      dimension: "出海/广告投放能力",
      tecdoScore: officialAds ? 5 : 2,
      geoflowScore: 1,
      reason: "钛动的主阵地是全球营销、广告投放和出海客户；GEOFlow 当前不做广告投放资源网络。",
    },
    {
      dimension: "内容与创意生成能力",
      tecdoScore: officialAi ? 4 : 2,
      geoflowScore: 3,
      reason: "钛动强调广告创意/素材链路；GEOFlow 有内容资产生成，但围绕豆包问题和证据页。",
    },
    {
      dimension: "数据分析与洞察能力",
      tecdoScore: officialData ? 4 : 2,
      geoflowScore: 3,
      reason: "钛动有营销数据/洞察叙事；GEOFlow 的数据更集中在答案样本、来源质量和 rank gap。",
    },
    {
      dimension: "SEO/搜索优化公开证据",
      tecdoScore: seoEvidence ? 2 : 1,
      geoflowScore: 3,
      reason: "钛动公开资料没有明显传统 SEO 服务主线；GEOFlow 有 sitemap、llms.txt、内容库和关键词分析。",
    },
    {
      dimension: "GEO/AI 答案可见度公开证据",
      tecdoScore: officialGeo ? 3 : 1,
      geoflowScore: 5,
      reason: officialGeo
        ? "钛动存在部分 AI 搜索/GEO 相关公开信号，但仍需复核。"
        : "未发现钛动官网明确 GEO / AI 答案可见度监测服务；GEOFlow 已有豆包答案采样、rank gap、来源审计和研究中心。",
    },
    {
      dimension: "豆包答案推荐强度",
      tecdoScore: doubaoRecommended ? 4 : 2,
      geoflowScore: 2,
      reason: "豆包容易把钛动的 AI 营销、出海和广告能力迁移到 GEO/SEO 服务商语境；GEOFlow 还缺外部品牌心智。",
    },
    {
      dimension: "与 GEOFlow 的直接竞争程度",
      tecdoScore: officialGeo ? 3 : 2,
      geoflowScore: 5,
      reason: "钛动是泛 AI 营销/出海营销强相关对象，不是已验证的豆包 GEO 采样与证据链直接竞品。",
    },
  ];
}

function toMarkdown({
  generatedAt,
  pages,
  searchResults,
  doubaoAnswers,
  scores,
}: {
  generatedAt: string;
  pages: PageLedger[];
  searchResults: SearchResult[];
  doubaoAnswers: DoubaoAnswer[];
  scores: Score[];
}) {
  const officialGeoHits = pages.filter((page) => page.evidenceTier === "official_confirmed" && keywordHits(`${page.title} ${page.excerpt}`).some((hit) => ["GEO", "生成式引擎优化", "AI搜索", "AI 搜索", "答案可见度", "品牌可见度"].includes(hit)));
  const searchTop = searchResults.filter((item) => item.rank <= 3).slice(0, 24);
  const claims = doubaoAnswers.flatMap((answer) => answer.claims.map((claim) => ({ question: answer.question, ...claim }))).slice(0, 28);
  const geoflowBetter = scores.filter((score) => score.geoflowScore > score.tecdoScore);
  const tecdoBetter = scores.filter((score) => score.tecdoScore > score.geoflowScore);

  return [
    `# 钛动科技 GEOFlow 评估：为什么豆包把它排前？`,
    ``,
    `生成时间：${generatedAt}`,
    ``,
    `## 一句话结论`,
    ``,
    `钛动科技是 AI 出海营销和广告智能体能力很强的公司，但本轮公开资料没有确认它已经把 GEO / 生成式引擎优化 / 豆包答案可见度监测作为明确服务售卖。豆包把它排前，更像是把“AI营销 + 出海广告 + 数据洞察 + 多智能体”迁移到 GEO/SEO 服务商语境。它是 GEOFlow 的间接参照对象，不是已验证的直接竞品。`,
    ``,
    `## 资料台账：GPT 爬虫抓到了什么`,
    ``,
    `| 来源 | 状态 | 证据等级 | 命中词 | 摘要 |`,
    `|---|---:|---|---|---|`,
    ...pages.map(
      (page) =>
        `| ${safeCell(page.url)} | ${safeCell(page.status)} | ${page.evidenceTier} | ${safeCell(page.keywordHits.join(", "))} | ${safeCell(`${page.title} - ${page.excerpt}`).slice(0, 260)} |`,
    ),
    ``,
    `## 搜索结果：公开网页如何描述钛动`,
    ``,
    `| 查询 | 排名 | 标题 | URL | 命中词 |`,
    `|---|---:|---|---|---|`,
    ...searchTop.map((item) => `| ${safeCell(item.query)} | ${item.rank} | ${safeCell(item.title)} | ${safeCell(item.url)} | ${safeCell(item.keywordHits.join(", "))} |`),
    ``,
    `## 豆包 API 怎么描述钛动科技`,
    ``,
    `| 问题 | 是否说有 GEO | 是否说竞品 | 推荐理由 | 回答摘要 |`,
    `|---|---|---|---|---|`,
    ...doubaoAnswers.map(
      (answer) =>
        `| ${safeCell(answer.question)} | ${answer.saysHasGeo ? "是" : "否/不确定"} | ${answer.saysCompetitor ? "是" : "否/间接"} | ${safeCell(answer.recommendedReason).slice(0, 160)} | ${safeCell(answer.answer).slice(0, 260)} |`,
    ),
    ``,
    `## 两套资料对比：豆包说法 vs 网页证据`,
    ``,
    `| 豆包声明 | 来源提示 | 证据等级 | 对应问题 | GEOFlow 判断 |`,
    `|---|---|---|---|---|`,
    ...claims.map((claim) => {
      const judgment =
        claim.evidenceLevel === "hallucination_risk"
          ? "高风险，不能写成事实"
          : claim.evidenceLevel === "not_found"
            ? "网页侧未确认"
            : claim.evidenceLevel === "doubao_claim"
              ? "豆包说法，待网页复核"
              : "需要进一步找官网/研报";
      return `| ${safeCell(claim.claim).slice(0, 180)} | ${safeCell(claim.sourceHint).slice(0, 120)} | ${claim.evidenceLevel} | ${safeCell(claim.question).slice(0, 120)} | ${judgment} |`;
    }),
    ``,
    `## 钛动科技是否真的有 GEO`,
    ``,
    officialGeoHits.length
      ? `本轮发现 ${officialGeoHits.length} 条官方页面含 GEO / AI 搜索 / 可见度相关强词，但仍需人工复核具体语义：${officialGeoHits.map((page) => page.url).join("、")}。`
      : `本轮未在钛动官网、sitemap、robots、llms.txt、Navos、钛极、案例和新闻入口中发现明确的“GEO / 生成式引擎优化 / 豆包答案可见度监测”服务页。`,
    ``,
    `更稳妥的结论是：钛动具备可迁移到 GEO 的 AI 营销、广告投放、数据洞察和多智能体能力，但“已经提供 GEO 服务”目前只能标为 needs_check，不能标为 official_confirmed。`,
    ``,
    `## 为什么它在豆包里靠前`,
    ``,
    `| 触发信号 | 为什么会让豆包推荐它 | 证据状态 |`,
    `|---|---|---|`,
    `| AI 营销公司 | GEO 被豆包理解成 AI 时代营销优化，所以会把 AI 营销公司迁移过来 | official_confirmed / semantic_inference |`,
    `| Navos 多智能体 | 多 Agent 可覆盖洞察、创意、投放、分析，和 GEOFlow 的 Agent 工作流有表面相似性 | official_confirmed |`,
    `| 钛极大模型 | 自研/自有大模型叙事会增强“懂 AI 搜索”的印象 | official_confirmed |`,
    `| 出海广告资源 | SEO/GEO 服务商常与品牌增长、渠道分发、内容投放绑定 | official_confirmed / semantic_inference |`,
    `| 数据洞察与优化 | 豆包容易把广告数据优化泛化成搜索/答案优化 | semantic_inference |`,
    ``,
    `## 它是不是 GEOFlow 竞品`,
    ``,
    `| 判断 | 结论 | 原因 |`,
    `|---|---|---|`,
    `| 直接竞品 | 否 | 未发现钛动公开提供豆包答案采样、品牌提及率、来源质量审计、rank gap、事实纠错等 GEOFlow 核心服务。 |`,
    `| 间接竞品 | 是 | 它有 AI 营销、出海增长、广告投放和数据产品包装，可能抢占客户对“AI 营销服务商”的预算和心智。 |`,
    `| 参照对象 | 是 | 它展示了泛 AI 营销公司如何靠品牌、客户、资源和 Agent 产品叙事进入豆包推荐。 |`,
    ``,
    `## GEOFlow 不如它的地方`,
    ``,
    `| 维度 | 钛动分 | GEOFlow分 | 差距原因 |`,
    `|---|---:|---:|---|`,
    ...tecdoBetter.map((score) => `| ${score.dimension} | ${score.tecdoScore} | ${score.geoflowScore} | ${safeCell(score.reason)} |`),
    ``,
    `## GEOFlow 比它更专的地方`,
    ``,
    `| 维度 | 钛动分 | GEOFlow分 | GEOFlow 优势 |`,
    `|---|---:|---:|---|`,
    ...geoflowBetter.map((score) => `| ${score.dimension} | ${score.tecdoScore} | ${score.geoflowScore} | ${safeCell(score.reason)} |`),
    ``,
    `## 下一步采样问题和产品建议`,
    ``,
    `| 问题/动作 | 目的 |`,
    `|---|---|`,
    `| 用豆包追问“钛动科技是否提供 GEO 服务？只给官网链接” | 验证豆包是否继续生成无来源服务名。 |`,
    `| 用同题比较钛动、增长超人、珍岛、GEOFlow | 看豆包的推荐维度到底偏广告营销、SEO 还是答案可见度。 |`,
    `| 为 GEOFlow 新增“豆包答案可见度监测”公开产品页 | 避免被泛 AI 营销公司占据 GEO 心智。 |`,
    `| 为 GEOFlow 发布 rank gap、来源审计、事实纠错案例页 | 把“更专”变成可被豆包引用的证据。 |`,
    `| 复测钛动在 Day 7 / 14 / 30 的豆包排名 | 判断它靠前是否稳定，还是单次语义迁移。 |`,
    ``,
  ].join("\n");
}

async function main() {
  await mkdir(outDir, { recursive: true });
  const generatedAt = new Date().toISOString();
  const stamp = generatedAt.replace(/[-:]/g, "").replace(/\.\d{3}Z$/, "Z");

  const pages: PageLedger[] = [];
  for (const url of officialUrls) {
    console.error(`[page] ${url}`);
    pages.push(await retry(`page ${url}`, () => fetchPageLedger(url)));
  }

  const searchResults: SearchResult[] = [];
  for (const query of searchQueries) {
    console.error(`[search] ${query}`);
    const results = await retry(`search ${query}`, () => fetchBingResults(query));
    searchResults.push(...results);
  }

  const doubaoAnswers: DoubaoAnswer[] = [];
  for (const question of doubaoQuestions) {
    console.error(`[doubao] ${question}`);
    doubaoAnswers.push(await retry(`doubao ${question}`, () => callDoubao(question)));
  }

  const scores = scoreAssessment(pages, searchResults, doubaoAnswers);
  const gptLedgerPath = join(outDir, `${stamp}-gpt-crawler-ledger.json`);
  const doubaoLedgerPath = join(outDir, `${stamp}-doubao-api-ledger.json`);
  const assessmentPath = join(outDir, `${stamp}-geo-flow-assessment.md`);
  await writeFile(gptLedgerPath, JSON.stringify({ generatedAt, officialUrls, searchQueries, pages, searchResults }, null, 2));
  await writeFile(doubaoLedgerPath, JSON.stringify({ generatedAt, doubaoQuestions, doubaoAnswers }, null, 2));
  await writeFile(assessmentPath, toMarkdown({ generatedAt, pages, searchResults, doubaoAnswers, scores }));

  console.log(JSON.stringify({ gptLedgerPath, doubaoLedgerPath, assessmentPath, pages: pages.length, searchResults: searchResults.length, doubaoAnswers: doubaoAnswers.length }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
