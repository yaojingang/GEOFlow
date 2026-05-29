#!/usr/bin/env node

import { mkdir, writeFile } from "node:fs/promises";
import { join } from "node:path";

type ModelResponse = {
  choices?: Array<{ message?: { content?: string } }>;
};

type EntityConfig = {
  name: string;
  aliases: string[];
  category: "overseas_tool" | "china_vendor" | "cloud_vendor" | "ai_platform" | "data_tool" | "guardrails";
  officialUrls: string[];
  rankGapReason: string;
  priorQuery: string;
};

type PageLedger = {
  url: string;
  sourceType: "official" | "official_meta" | "search_result";
  status: number | "error";
  title: string;
  excerpt: string;
  evidenceTier: "official_confirmed" | "media_claim" | "discussion_signal" | "not_found";
  keywordHits: string[];
  headings: string[];
  metaDescription: string;
  sitemapUrls: string[];
};

type SearchResult = {
  query: string;
  rank: number;
  title: string;
  snippet: string;
  url: string;
  keywordHits: string[];
  sourceKind: "official" | "media" | "report" | "community" | "search_tool" | "unknown";
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
  saysDirectCompetitor: boolean;
  recommendedReason: string;
};

type EntityAssessment = {
  entity: EntityConfig;
  pages: PageLedger[];
  searchResults: SearchResult[];
  doubao: DoubaoAnswer[];
  conclusions: {
    whatCompanySays: string[];
    whatItDoes: string[];
    onlineMaterials: string[];
    geoEvidence: "confirmed" | "partial" | "not_found";
    competitor: "direct" | "indirect" | "not_direct";
    hallucinationRisk: "high" | "medium" | "low";
    whyDoubaoRanksIt: string[];
    geoflowGap: string[];
    geoflowAdvantage: string[];
  };
  note: {
    slug: string;
    title: string;
    excerpt: string;
    type: string;
    tags: string[];
    body: string;
  };
};

const outDir = process.env.DOUBAO_RESEARCH_OUT ?? join(process.cwd(), "research-runs/rank-gap-company-assessments");
const generatedNotesPath =
  process.env.DOUBAO_RESEARCH_NOTES_OUT ?? join(process.cwd(), "src/lib/generated-rank-gap-company-research.ts");
const requestTimeoutMs = Number(process.env.DOUBAO_RESEARCH_TIMEOUT_MS ?? 60_000);
const maxAttempts = Number(process.env.DOUBAO_RESEARCH_MAX_ATTEMPTS ?? 2);

const entities: EntityConfig[] = [
  {
    name: "百度智能云",
    aliases: ["百度智能云", "文心一言", "百度云", "Baidu AI Cloud"],
    category: "cloud_vendor",
    officialUrls: ["https://cloud.baidu.com", "https://cloud.baidu.com/product/wenxinworkshop", "https://yiyan.baidu.com"],
    rankGapReason: "豆包把大模型、搜索和云服务能力组合成“生成式引擎优化解决方案”。",
    priorQuery: "百度智能云是否提供生成式引擎优化 GEO 解决方案？",
  },
  {
    name: "阿里云通义",
    aliases: ["阿里云通义", "通义千问", "Qwen", "阿里云百炼"],
    category: "cloud_vendor",
    officialUrls: ["https://tongyi.aliyun.com", "https://qwenlm.github.io", "https://bailian.console.aliyun.com"],
    rankGapReason: "豆包把通义大模型、阿里云企业服务和内容合规能力迁移到 GEO 服务名。",
    priorQuery: "阿里云通义GEO优化服务是否存在？",
  },
  {
    name: "字节跳动豆包生态",
    aliases: ["字节跳动豆包", "豆包", "火山引擎豆包", "Doubao"],
    category: "cloud_vendor",
    officialUrls: ["https://www.doubao.com", "https://www.volcengine.com/product/doubao", "https://www.volcengine.com/docs"],
    rankGapReason: "豆包把官方生态和 GEO 优化服务绑定，存在高风险官方服务名幻觉。",
    priorQuery: "字节跳动豆包生态开放平台官方GEO优化服务是否存在？",
  },
  {
    name: "Semrush",
    aliases: ["Semrush", "SEMrush"],
    category: "overseas_tool",
    officialUrls: ["https://www.semrush.com", "https://www.semrush.com/features/"],
    rankGapReason: "真实 SEO 平台在豆包里被迁移到 AI 搜索优化和 GEO 套件语境。",
    priorQuery: "Semrush 是否有 GEO 优化套件？",
  },
  {
    name: "Ahrefs",
    aliases: ["Ahrefs"],
    category: "overseas_tool",
    officialUrls: ["https://ahrefs.com", "https://ahrefs.com/features"],
    rankGapReason: "真实 SEO 平台被豆包扩展成 AI SERP / GEO 优化服务。",
    priorQuery: "Ahrefs 是否有 GEO 服务或 AI SERP 优化工具？",
  },
  {
    name: "Frase",
    aliases: ["Frase", "Frase.io"],
    category: "overseas_tool",
    officialUrls: ["https://www.frase.io"],
    rankGapReason: "内容优化工具在豆包里被判断为 AI 搜索优化工具。",
    priorQuery: "Frase 是否适合 AI 搜索优化？",
  },
  {
    name: "BrightEdge",
    aliases: ["BrightEdge"],
    category: "overseas_tool",
    officialUrls: ["https://www.brightedge.com"],
    rankGapReason: "企业 SEO 平台可能有生成式搜索相关叙事，中文网页结果弱。",
    priorQuery: "BrightEdge 是否有生成式搜索或 GEO 产品证据？",
  },
  {
    name: "Surfer SEO",
    aliases: ["Surfer SEO", "SurferSEO"],
    category: "overseas_tool",
    officialUrls: ["https://surferseo.com"],
    rankGapReason: "内容优化工具被豆包迁移成 AI 搜索优化工具。",
    priorQuery: "Surfer SEO 是否有 AI 搜索优化或 GEO 能力？",
  },
  {
    name: "Similarweb",
    aliases: ["Similarweb", "SimilarWeb"],
    category: "data_tool",
    officialUrls: ["https://www.similarweb.com"],
    rankGapReason: "真实流量和竞品分析平台被豆包包装成 AI 洞察版。",
    priorQuery: "Similarweb AI 洞察版是否是公开产品名？",
  },
  {
    name: "OpenAI",
    aliases: ["OpenAI", "ChatGPT"],
    category: "ai_platform",
    officialUrls: ["https://openai.com", "https://platform.openai.com/docs"],
    rankGapReason: "内容审核 API 被豆包混入 AI 答案监测工具，需要区分安全审核和答案可见度。",
    priorQuery: "OpenAI 内容审核 API 是否等于 AI 答案监测工具？",
  },
  {
    name: "Guardrails AI",
    aliases: ["Guardrails AI", "Guardrails"],
    category: "guardrails",
    officialUrls: ["https://www.guardrailsai.com", "https://github.com/guardrails-ai/guardrails"],
    rankGapReason: "guardrails 框架被豆包混入答案监测工具。",
    priorQuery: "Guardrails AI 是否是答案监测工具？",
  },
  {
    name: "LangSmith",
    aliases: ["LangSmith", "LangChain LangSmith"],
    category: "guardrails",
    officialUrls: ["https://www.langchain.com/langsmith", "https://smith.langchain.com"],
    rankGapReason: "LLM 应用观测工具被豆包混入 AI 答案监测。",
    priorQuery: "LangSmith 是否能监测品牌在豆包答案中的可见度？",
  },
  {
    name: "NVIDIA NeMo Guardrails",
    aliases: ["NVIDIA NeMo Guardrails", "NeMo Guardrails"],
    category: "guardrails",
    officialUrls: ["https://github.com/NVIDIA/NeMo-Guardrails", "https://docs.nvidia.com/nemo/guardrails"],
    rankGapReason: "输出约束框架被豆包归入答案监测。",
    priorQuery: "NVIDIA NeMo Guardrails 是否是 AI 答案监测工具？",
  },
  {
    name: "Arthur",
    aliases: ["Arthur AI", "Arthur"],
    category: "guardrails",
    officialUrls: ["https://www.arthur.ai"],
    rankGapReason: "模型监控和风险治理工具被豆包迁移到答案监测。",
    priorQuery: "Arthur 是否覆盖公开 AI 搜索答案监测？",
  },
  {
    name: "5118",
    aliases: ["5118", "5118营销大数据平台"],
    category: "china_vendor",
    officialUrls: ["https://www.5118.com"],
    rankGapReason: "国内 SEO/营销工具被豆包直接扩展成 AI 搜索可见度监测。",
    priorQuery: "5118 有没有 AI 搜索可见度监测或 GEO 工具？",
  },
  {
    name: "珍岛集团",
    aliases: ["珍岛集团", "T云"],
    category: "china_vendor",
    officialUrls: ["https://www.71360.com", "https://www.71360.com/tcloud/"],
    rankGapReason: "传统智能营销服务商被豆包扩展到 GEO/SEO 服务商推荐。",
    priorQuery: "珍岛集团是否提供 GEO 或 AI 搜索优化服务？",
  },
  {
    name: "Chinaz 站长之家",
    aliases: ["Chinaz", "站长工具", "站长之家"],
    category: "china_vendor",
    officialUrls: ["https://www.chinaz.com", "https://tool.chinaz.com"],
    rankGapReason: "站长工具品牌被豆包组合出 AI 搜索监测模块和大模型内容适配工具。",
    priorQuery: "Chinaz 是否有 AI 搜索监测模块？",
  },
  {
    name: "清博智能",
    aliases: ["清博智能", "清博", "GSData"],
    category: "china_vendor",
    officialUrls: ["https://www.gsdata.cn"],
    rankGapReason: "舆情和内容治理心智被豆包迁移到定制化 GEO 解决方案。",
    priorQuery: "清博智能是否有 GEO 解决方案？",
  },
  {
    name: "增长超人",
    aliases: ["增长超人", "GrowthMan"],
    category: "china_vendor",
    officialUrls: ["https://www.growthman.cn"],
    rankGapReason: "少数在网页搜索中也命中的服务商，需要对比它为什么比其他服务商更可见。",
    priorQuery: "增长超人为什么在网页搜索中命中？",
  },
  {
    name: "新榜",
    aliases: ["新榜", "新榜有数", "NewRank"],
    category: "china_vendor",
    officialUrls: ["https://www.newrank.cn"],
    rankGapReason: "内容数据平台被豆包包装成 GEO 内容优化或 AI 搜索监测。",
    priorQuery: "新榜是否有 GEO 内容优化或 AI 搜索监测产品？",
  },
  {
    name: "飞书深诺",
    aliases: ["飞书深诺", "MeetSocial"],
    category: "china_vendor",
    officialUrls: ["https://www.meetsocial.com"],
    rankGapReason: "出海营销能力被豆包迁移到 GEO/SEO 服务商推荐。",
    priorQuery: "飞书深诺是否有 GEO 或 AI 搜索优化服务？",
  },
  {
    name: "天眼查",
    aliases: ["天眼查"],
    category: "data_tool",
    officialUrls: ["https://www.tianyancha.com"],
    rankGapReason: "企业信息查询产品被豆包纳入 AI 竞品分析工具。",
    priorQuery: "天眼查 AI 商业分析平台是否是公开产品名？",
  },
  {
    name: "神策数据",
    aliases: ["神策数据", "Sensors Data"],
    category: "data_tool",
    officialUrls: ["https://www.sensorsdata.cn"],
    rankGapReason: "用户行为分析平台被豆包迁移到 AI 竞品分析。",
    priorQuery: "神策数据 AI 分析平台是否适合竞品分析？",
  },
  {
    name: "数说故事",
    aliases: ["数说故事", "DataStory", "DeepSight"],
    category: "data_tool",
    officialUrls: ["https://www.datastory.com.cn"],
    rankGapReason: "消费洞察/舆情数据平台被豆包包装成 AI 竞品分析模块。",
    priorQuery: "数说故事 DeepSight AI 竞品分析模块是否真实存在？",
  },
  {
    name: "蜜度文修",
    aliases: ["蜜度文修", "蜜度"],
    category: "china_vendor",
    officialUrls: ["https://www.midu.com"],
    rankGapReason: "中文文本审核/校对类产品被豆包混入 AI 答案监测。",
    priorQuery: "蜜度文修是否适合 AI 答案监测？",
  },
];

const evidenceKeywords = [
  "GEO",
  "生成式引擎优化",
  "AI搜索",
  "AI 搜索",
  "AEO",
  "answer engine",
  "AI visibility",
  "AI search",
  "LLM visibility",
  "搜索优化",
  "SEO",
  "豆包",
  "DeepSeek",
  "ChatGPT",
  "品牌可见度",
  "答案可见度",
  "llms.txt",
  "大模型",
  "智能体",
  "AI",
  "营销",
  "监测",
  "竞品",
  "内容优化",
  "guardrails",
  "observability",
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

function metaDescription(html: string) {
  const match =
    html.match(/<meta[^>]+name=["']description["'][^>]+content=["']([^"']+)["'][^>]*>/i) ??
    html.match(/<meta[^>]+content=["']([^"']+)["'][^>]+name=["']description["'][^>]*>/i) ??
    html.match(/<meta[^>]+property=["']og:description["'][^>]+content=["']([^"']+)["'][^>]*>/i);
  return stripTags(match?.[1] ?? "").slice(0, 260);
}

function pageHeadings(html: string) {
  return [...html.matchAll(/<h[1-3][^>]*>([\s\S]*?)<\/h[1-3]>/gi)]
    .map((match) => stripTags(match[1]))
    .filter(Boolean)
    .slice(0, 8);
}

function extractSitemapUrls(text: string, officialHosts: string[]) {
  const urls = [...text.matchAll(/<loc>\s*([^<]+)\s*<\/loc>/gi)]
    .map((match) => decodeEntities(match[1].trim()))
    .filter((url) => {
      try {
        return officialHosts.some((host) => new URL(url).hostname.endsWith(host));
      } catch {
        return false;
      }
    });
  const scored = urls
    .map((url) => {
      const lower = url.toLowerCase();
      const score = [
        "product",
        "solution",
        "solutions",
        "case",
        "cases",
        "blog",
        "news",
        "feature",
        "features",
        "seo",
        "ai",
        "marketing",
        "about",
        "docs",
      ].reduce((total, token) => total + (lower.includes(token) ? 1 : 0), 0);
      return { url, score };
    })
    .sort((a, b) => b.score - a.score);
  return Array.from(new Set(scored.filter((item) => item.score > 0).map((item) => item.url))).slice(0, 5);
}

function sourceKind(url: string, title: string, snippet: string, officialHosts: string[]): SearchResult["sourceKind"] {
  const host = hostOf(url);
  if (officialHosts.some((officialHost) => host.endsWith(officialHost))) return "official";
  const text = `${host} ${title} ${snippet}`.toLowerCase();
  if (/github|tool\.chinaz|5118|semrush|ahrefs|similarweb/.test(text)) return "search_tool";
  if (/研报|证券|research|report|pdf|券商|招股|公告|年报/.test(text)) return "report";
  if (/知乎|小红书|微博|reddit|linux.do|v2ex|twitter|x\.com|社区|论坛/.test(text)) return "community";
  if (/36kr|虎嗅|钛媒体|搜狐|网易|腾讯|新浪|财联社|亿欧|媒体|news|press|prnewswire/.test(text)) return "media";
  return "unknown";
}

function hostOf(url: string) {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return "";
  }
}

function slugify(value: string) {
  const known: Record<string, string> = {
    百度智能云: "baidu-ai-cloud",
    阿里云通义: "aliyun-tongyi",
    字节跳动豆包生态: "bytedance-doubao-ecosystem",
    "Chinaz 站长之家": "chinaz",
    清博智能: "qingbo-gsdata",
    增长超人: "growthman",
    新榜: "newrank",
    飞书深诺: "meetsocial",
    天眼查: "tianyancha",
    神策数据: "sensors-data",
    数说故事: "datastory",
    珍岛集团: "trueland",
    蜜度文修: "midu-wenxiu",
  };
  if (known[value]) return known[value];
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function entitySlug(entity: EntityConfig) {
  return `rank-gap-${slugify(entity.name)}-geo-assessment`;
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
      if (attempt < maxAttempts) await new Promise((resolve) => setTimeout(resolve, 1000 * attempt));
    }
  }
  throw lastError;
}

async function fetchPageLedger(url: string, officialHosts: string[] = []): Promise<PageLedger> {
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
    const headings = pageHeadings(text);
    const description = metaDescription(text);
    return {
      url,
      sourceType: url.endsWith("sitemap.xml") || url.endsWith("robots.txt") || url.endsWith("llms.txt") ? "official_meta" : "official",
      status: response.status,
      title: pageTitle(text, url),
      excerpt: plain.slice(0, 520),
      evidenceTier: response.ok ? "official_confirmed" : "not_found",
      keywordHits: keywordHits(plain),
      headings,
      metaDescription: description,
      sitemapUrls: url.endsWith("sitemap.xml") ? extractSitemapUrls(text, officialHosts) : [],
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
      headings: [],
      metaDescription: "",
      sitemapUrls: [],
    };
  }
}

async function fetchBingResults(query: string, entity: EntityConfig): Promise<SearchResult[]> {
  const url = `https://www.bing.com/search?q=${encodeURIComponent(query)}&cc=cn&setlang=zh-CN&count=10`;
  const response = await fetchWithTimeout(
    url,
    {
      headers: {
        "User-Agent": "Mozilla/5.0 GEOFlow Research Bot; public evidence only",
        "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
      },
    },
    query,
  );
  const html = await response.text();
  const blocks = html.match(/<li class="b_algo"[\s\S]*?<\/li>/gi) ?? [];
  const officialHosts = entity.officialUrls.map((url) => new URL(url).hostname.replace(/^www\./, ""));
  return blocks.slice(0, 10).map((block, index) => {
    const anchor = block.match(/<a[^>]+href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i);
    const snippet = block.match(/<p[^>]*>([\s\S]*?)<\/p>/i)?.[1] ?? "";
    const title = stripTags(anchor?.[2] ?? `Result ${index + 1}`);
    const plainSnippet = stripTags(snippet);
    return {
      query,
      rank: index + 1,
      title,
      snippet: plainSnippet.slice(0, 320),
      url: decodeEntities(anchor?.[1] ?? ""),
      keywordHits: keywordHits(`${title} ${plainSnippet}`),
      sourceKind: sourceKind(decodeEntities(anchor?.[1] ?? ""), title, plainSnippet, officialHosts),
    };
  });
}

function getSearchQueries(entity: EntityConfig) {
  const [primary] = entity.aliases;
  return [
    `${primary} 是什么`,
    `${primary} 做什么`,
    `${primary} 官网 产品`,
    `${primary} 新闻 资料`,
    `${primary} 研报`,
    `${primary} 招聘 SEO AI`,
    `${primary} GEO`,
    `${primary} 生成式引擎优化`,
    `${primary} AI 搜索优化`,
    `${primary} AI 答案监测`,
    `${primary} SEO`,
    `${primary} llms.txt`,
    `${primary} 官网`,
    entity.priorQuery,
  ];
}

function getDoubaoQuestions(entity: EntityConfig) {
  return [
    `${entity.name}是否提供 GEO、生成式引擎优化、AI搜索优化或 AI 答案可见度监测？请只给可验证证据，没有官网证据就说没有找到。`,
    `${entity.name}为什么会被豆包放进 GEO/SEO/AI搜索相关推荐？哪些是合理迁移，哪些可能是幻觉？`,
  ];
}

function extractJson(value: string) {
  const fenced = value.match(/```json\s*([\s\S]*?)```/i)?.[1];
  const raw = fenced ?? value.match(/\{[\s\S]*\}/)?.[0] ?? "{}";
  try {
    return JSON.parse(raw) as {
      answer?: string;
      claims?: DoubaoClaim[];
      saysHasGeo?: boolean;
      saysDirectCompetitor?: boolean;
      recommendedReason?: string;
    };
  } catch {
    return { answer: value, claims: [] };
  }
}

async function callDoubao(entity: EntityConfig, question: string): Promise<DoubaoAnswer> {
  const apiKey = process.env.DOUBAO_API_KEY;
  const baseUrl = process.env.DOUBAO_BASE_URL ?? "https://ark.cn-beijing.volces.com/api/v3";
  const model = process.env.DOUBAO_MODEL ?? "doubao-seed-1-6-250615";
  if (!apiKey) {
    return {
      question,
      answer: "未配置 DOUBAO_API_KEY，本轮只生成公开网页侧证据。",
      claims: [],
      saysHasGeo: false,
      saysDirectCompetitor: false,
      recommendedReason: "doubao_api_skipped",
    };
  }

  let response: Response;
  try {
    response = await retry(`doubao ${entity.name}`, () =>
      fetchWithTimeout(
        `${baseUrl.replace(/\/$/, "")}/chat/completions`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${apiKey}`,
          },
          body: JSON.stringify({
            model,
            temperature: 0.2,
            messages: [
              {
                role: "system",
                content:
                  "你是GEO研究采样器。只输出JSON，不要Markdown。字段：answer:string, claims:[{claim,evidenceLevel,sourceHint}], saysHasGeo:boolean, saysDirectCompetitor:boolean, recommendedReason:string。evidenceLevel只允许 doubao_claim / hallucination_risk / needs_check / not_found。必须区分官网证据和推断。",
              },
              {
                role: "user",
                content: `研究对象：${entity.name}。别名：${entity.aliases.join(" / ")}。\n问题：${question}`,
              },
            ],
          }),
        },
        `doubao ${entity.name}`,
      ),
    );
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return {
      question,
      answer: `豆包 API 采样失败：${message}`,
      claims: [{ claim: "豆包 API 采样失败，本题不能作为豆包立场证据。", evidenceLevel: "needs_check", sourceHint: message }],
      saysHasGeo: false,
      saysDirectCompetitor: false,
      recommendedReason: "doubao_api_failed",
    };
  }

  if (!response.ok) {
    const body = await response.text();
    const message = `Doubao failed ${response.status}: ${body.slice(0, 500)}`;
    return {
      question,
      answer: `豆包 API 采样失败：${message}`,
      claims: [{ claim: "豆包 API 采样失败，本题不能作为豆包立场证据。", evidenceLevel: "needs_check", sourceHint: message }],
      saysHasGeo: false,
      saysDirectCompetitor: false,
      recommendedReason: "doubao_api_failed",
    };
  }

  const data = (await response.json()) as ModelResponse;
  const parsed = extractJson(data.choices?.[0]?.message?.content ?? "");
  return {
    question,
    answer: String(parsed.answer ?? "").trim(),
    claims: (parsed.claims ?? []).map((claim) => ({
      claim: String(claim.claim ?? "").trim(),
      evidenceLevel: ["doubao_claim", "hallucination_risk", "needs_check", "not_found"].includes(String(claim.evidenceLevel))
        ? claim.evidenceLevel
        : "needs_check",
      sourceHint: String(claim.sourceHint ?? "").trim(),
    })),
    saysHasGeo: Boolean(parsed.saysHasGeo),
    saysDirectCompetitor: Boolean(parsed.saysDirectCompetitor),
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

function topUnique(items: string[], limit: number) {
  return Array.from(new Set(items.map((item) => item.replace(/\s+/g, " ").trim()).filter(Boolean))).slice(0, limit);
}

function summarizeOfficialPromotion(pages: PageLedger[]) {
  const okPages = pages.filter((page) => page.evidenceTier === "official_confirmed" && page.sourceType === "official");
  const statements = okPages.flatMap((page) => {
    const base = page.metaDescription || page.excerpt;
    return [
      page.title ? `官网标题：${page.title}` : "",
      base ? `官网描述：${base.slice(0, 180)}` : "",
      ...page.headings.slice(0, 4).map((heading) => `官网栏目/标题：${heading}`),
    ];
  });
  return topUnique(statements, 8);
}

function inferWhatItDoes(entity: EntityConfig, pages: PageLedger[], searchResults: SearchResult[]) {
  const officialText = pages
    .filter((page) => page.evidenceTier === "official_confirmed")
    .map((page) => `${page.title} ${page.metaDescription} ${page.excerpt} ${page.headings.join(" ")}`)
    .join(" ");
  const searchText = searchResults.map((result) => `${result.title} ${result.snippet}`).join(" ");
  const text = `${officialText} ${searchText}`.toLowerCase();
  const items = [
    entity.category === "cloud_vendor" && /大模型|model|云|智能体|agent|api/.test(text)
      ? "大模型、云服务、开发平台或企业 AI 基础设施。"
      : "",
    entity.category === "overseas_tool" && /seo|content|keyword|traffic|rank|search/.test(text)
      ? "SEO、内容优化、关键词/流量分析或搜索营销工具。"
      : "",
    entity.category === "china_vendor" && /营销|seo|内容|舆情|广告|投放|品牌|站长/.test(text)
      ? "国内营销、内容、舆情、站长工具或企业数字化服务。"
      : "",
    entity.category === "data_tool" && /数据|分析|洞察|竞品|流量|企业信息|用户行为/.test(text)
      ? "数据分析、竞品洞察、流量分析、企业信息或用户行为分析。"
      : "",
    entity.category === "guardrails" && /guardrail|observability|monitor|eval|安全|风险|llm|agent/.test(text)
      ? "LLM 应用治理、监控、评测、guardrails 或 AI 风险控制。"
      : "",
    /ai|大模型|智能体|agent|chatgpt|deepseek|豆包/.test(text) ? "公开资料里存在 AI / 大模型 / 智能体叙事，可被豆包迁移进 GEO 语境。" : "",
    /geo|生成式引擎优化|ai search|ai visibility|答案可见度|品牌可见度/.test(text)
      ? "公开资料里出现 GEO / AI 搜索 / 答案可见度相关词，需要继续做来源级确认。"
      : "",
  ].filter(Boolean);
  return topUnique(items, 6);
}

function summarizeOnlineMaterials(searchResults: SearchResult[]) {
  const groups = new Map<SearchResult["sourceKind"], SearchResult[]>();
  for (const result of searchResults) {
    groups.set(result.sourceKind, [...(groups.get(result.sourceKind) ?? []), result]);
  }
  return (["official", "report", "media", "community", "search_tool", "unknown"] as const)
    .map((kind) => {
      const items = (groups.get(kind) ?? []).slice(0, 3);
      if (!items.length) return "";
      return `${kind}: ${items.map((item) => `${item.title}（${hostOf(item.url) || "unknown"}）`).join("；")}`;
    })
    .filter(Boolean);
}

function assessEntity(entity: EntityConfig, pages: PageLedger[], searchResults: SearchResult[], doubao: DoubaoAnswer[]) {
  const officialOk = pages.filter((page) => page.evidenceTier === "official_confirmed");
  const geoOfficial = hasAny(officialOk, ["生成式引擎优化", "GEO", "AI search", "AI visibility", "LLM visibility", "answer engine", "答案可见度", "品牌可见度"]);
  const geoSearch = hasAny(searchResults, ["生成式引擎优化", "GEO", "AI search", "AI visibility", "答案可见度", "品牌可见度"]);
  const saysHasGeo = doubao.some((answer) => answer.saysHasGeo);
  const hallucinationRisk = saysHasGeo && !geoOfficial ? "high" : geoSearch && !geoOfficial ? "medium" : "low";
  const competitor = entity.category === "china_vendor" || entity.category === "overseas_tool" ? "indirect" : "not_direct";

  const whyDoubaoRanksIt = [
    entity.rankGapReason,
    entity.category === "cloud_vendor" ? "云厂商/大模型厂商天然拥有模型、搜索、内容合规和企业服务心智。" : "",
    entity.category === "overseas_tool" ? "海外 SEO / 内容优化工具有英文知识强度，豆包容易把它迁移到 AI 搜索优化。" : "",
    entity.category === "china_vendor" ? "国内营销、SEO、站长或内容数据心智容易被豆包迁移到豆包 GEO。" : "",
    entity.category === "guardrails" ? "guardrails / observability 能力容易被豆包误并入“AI 答案监测”。" : "",
    entity.category === "data_tool" ? "数据洞察、竞品分析或企业信息能力容易被豆包并入 AI 竞品分析。" : "",
  ].filter(Boolean);

  return {
    whatCompanySays: summarizeOfficialPromotion(pages),
    whatItDoes: inferWhatItDoes(entity, pages, searchResults),
    onlineMaterials: summarizeOnlineMaterials(searchResults),
    geoEvidence: geoOfficial ? "confirmed" : geoSearch ? "partial" : "not_found",
    competitor,
    hallucinationRisk,
    whyDoubaoRanksIt,
    geoflowGap: [
      entity.category === "overseas_tool" ? "海外工具的品牌认知和英文资料覆盖强于 GEOFlow。" : "",
      entity.category === "cloud_vendor" ? "云厂商的大模型、开发者生态和官方文档体量强于 GEOFlow。" : "",
      entity.category === "china_vendor" ? "国内营销服务商已有客户、销售网络和传统 SEO/营销心智。" : "",
      entity.category === "data_tool" ? "数据平台在流量、企业信息或用户行为数据上强于 GEOFlow。" : "",
      entity.category === "guardrails" ? "guardrails / observability 工具在开发者生态和技术可信度上更成熟。" : "",
    ].filter(Boolean),
    geoflowAdvantage: [
      "GEOFlow 更聚焦豆包答案采样、品牌提及率、rank gap 和来源质量审计。",
      "GEOFlow 把豆包说法和网页证据分开，不把模型推荐直接写成事实。",
      "GEOFlow 有研究中心证据链，可以把 hallucination_risk、needs_check 和 official_confirmed 分层发布。",
    ],
  } satisfies EntityAssessment["conclusions"];
}

function tableRows<T>(items: T[], mapper: (item: T) => string) {
  return items.length ? items.map(mapper).join("\n") : "| 无 | 无 | 无 | 无 |";
}

function makeBody(assessment: Omit<EntityAssessment, "note">, timestamp: string) {
  const { entity, pages, searchResults, doubao, conclusions } = assessment;
  const officialRows = tableRows(pages, (page) =>
    `| ${safeCell(page.url)} | ${safeCell(page.status)} | ${safeCell(page.evidenceTier)} | ${safeCell(page.keywordHits.slice(0, 10).join("、") || "未命中")} | ${safeCell(page.title)} | ${safeCell((page.metaDescription || page.excerpt).slice(0, 160))} |`,
  );
  const searchRows = tableRows(searchResults.slice(0, 12), (result) =>
    `| ${safeCell(result.query)} | ${result.rank} | ${safeCell(result.sourceKind)} | ${safeCell(result.title)} | ${safeCell(result.snippet.slice(0, 180))} | ${safeCell(result.url)} |`,
  );
  const doubaoRows = tableRows(doubao, (answer) =>
    `| ${safeCell(answer.question)} | ${answer.saysHasGeo ? "是" : "否/不确定"} | ${answer.saysDirectCompetitor ? "是" : "否/不确定"} | ${safeCell(answer.answer.slice(0, 220))} | ${safeCell(answer.recommendedReason || "未给出")} |`,
  );
  const claimRows = doubao
    .flatMap((answer) => answer.claims.map((claim) => ({ question: answer.question, ...claim })))
    .slice(0, 10)
    .map((claim) => `| ${safeCell(claim.question)} | ${safeCell(claim.claim)} | ${safeCell(claim.evidenceLevel)} | ${safeCell(claim.sourceHint || "无")} |`)
    .join("\n");

  const geoConclusion =
    conclusions.geoEvidence === "confirmed"
      ? "本轮找到官方或强公开资料支持其与 GEO / AI 搜索 / AI 可见度相关。仍需继续确认是否覆盖豆包答案采样。"
      : conclusions.geoEvidence === "partial"
        ? "本轮只看到搜索结果或语义相关线索，未形成官网 confirmed。适合标为 needs_check。"
        : "本轮没有找到足够证据证明它公开销售 GEO、生成式引擎优化或豆包答案可见度监测。";

  return `# ${entity.name} GEOFlow 评估：豆包为什么提到它？

生成日期：${timestamp}

## 一句话结论

${entity.name} 在上一篇 [[豆包靠前但网页靠后的产品差异报告]] 中属于“豆包靠前、网页证据弱或需要复核”的对象。本轮先重新爬它自己的官网宣传，再看网上公开资料，最后判断它和 GEOFlow 的关系。

${geoConclusion}

对 GEOFlow 来说，${entity.name} ${conclusions.competitor === "indirect" ? "是间接竞品或心智竞争对象" : "暂不是直接竞品"}。重点不是判断它好坏，而是判断豆包为什么会把它放入 GEO / AI 搜索 / 答案监测语境，以及这个说法能不能被公开证据证明。

## 它自己怎么宣传

${conclusions.whatCompanySays.map((item) => `- ${item}`).join("\n") || "- 官网可抓取内容不足，需要后续人工补抓动态页面、PDF 或案例页。"}

## 它到底做什么

${conclusions.whatItDoes.map((item) => `- ${item}`).join("\n") || "- 公开资料不足以稳定归纳主营业务，只能先按上一篇 rank gap 的实体类别处理。"}

## 网上公开资料怎么说

${conclusions.onlineMaterials.map((item) => `- ${item}`).join("\n") || "- 搜索结果可用资料不足，后续应扩展到更多搜索引擎和行业库。"}

## 原始异常信号

| 字段 | 内容 |
|---|---|
| 上一篇中的追问 | ${safeCell(entity.priorQuery)} |
| 豆包靠前原因假设 | ${safeCell(entity.rankGapReason)} |
| 实体类别 | ${safeCell(entity.category)} |
| 本轮 GEO 证据判断 | ${safeCell(conclusions.geoEvidence)} |
| 幻觉风险 | ${safeCell(conclusions.hallucinationRisk)} |
| 与 GEOFlow 关系 | ${safeCell(conclusions.competitor)} |

## 官网与公开页面爬虫台账

| 来源 | 状态 | 证据等级 | 命中信号 | 标题 | 摘要 |
|---|---:|---|---|---|---|
${officialRows}

## 公开搜索结果

| 查询 | 排名 | 来源类型 | 标题 | 摘要 | URL |
|---|---:|---|---|---|---|
${searchRows}

## 豆包 API 怎么描述它

| 问题 | 豆包是否说有 GEO | 豆包是否说是直接竞品 | 豆包核心说法 | 推荐理由 |
|---|---|---|---|---|
${doubaoRows}

## 豆包声明台账

| 采样问题 | 声明 | 证据等级 | 来源提示 |
|---|---|---|---|
${claimRows || "| 无 | 无 | 无 | 无 |"}

## 两套资料对比

| 判断项 | 结论 | 处理方式 |
|---|---|---|
| 是否能写成已确认 GEO 服务商 | ${conclusions.geoEvidence === "confirmed" ? "可以谨慎写，但仍要标注具体证据 URL。" : "不能。"} | 未找到官网强证据前，不进入 confirmed 清单。 |
| 豆包靠前是否有解释 | 有。 | ${safeCell(conclusions.whyDoubaoRanksIt.join("；"))} |
| 是否是 GEOFlow 直接竞品 | ${conclusions.competitor === "direct" ? "是" : "否。"} | 当前按间接心智竞争或方法参照处理。 |
| 最大风险 | ${conclusions.hallucinationRisk} | 豆包可能把真实品牌与新概念组合成不存在的产品名。 |

## 为什么豆包会把它排前

${conclusions.whyDoubaoRanksIt.map((item) => `- ${item}`).join("\n")}

## GEOFlow 不如它的地方

${conclusions.geoflowGap.map((item) => `- ${item}`).join("\n") || "- 本轮没有发现 GEOFlow 在核心豆包 GEO 能力上明显不如它，但对方品牌或生态体量仍可能更强。"}

## GEOFlow 更专的地方

${conclusions.geoflowAdvantage.map((item) => `- ${item}`).join("\n")}

## 下一步采样问题

| 问题 | 目的 |
|---|---|
| “${entity.name} 是否提供 GEO / 生成式引擎优化？请只给官网链接。” | 验证豆包是否继续生成无来源服务名。 |
| “${entity.name} 能否监测品牌在豆包答案里的提及率和排名？” | 区分答案可见度监测和普通 SEO / 数据分析。 |
| “${entity.name} 和 GEOFlow 哪个更适合做豆包 GEO？请列证据。” | 检查豆包推荐依据是否偏品牌体量、SEO 还是证据链能力。 |
| “${entity.name} 的公开页面有没有 llms.txt、sitemap、FAQ、案例和可引用事实库？” | 转成 GEOFlow 可执行的网站资产审计。 |

## 结论

${entity.name} 的研究价值在于暴露豆包的推荐迁移路径：真实品牌、已有产品能力和新兴 GEO 概念会被模型自动组合。

GEOFlow 的处理原则是：可以把它放入监测池，但不能把豆包的推荐理由直接发布为事实。只有官网、权威报告、可打开产品页或可重复采样支持时，才升级为 confirmed。
`;
}

async function assessOne(entity: EntityConfig, timestamp: string): Promise<EntityAssessment> {
  console.error(`[entity] ${entity.name}`);
  const initialUrls = Array.from(
    new Set([
      ...entity.officialUrls,
      ...entity.officialUrls.flatMap((url) => {
        const base = url.replace(/\/$/, "");
        return [`${base}/robots.txt`, `${base}/sitemap.xml`, `${base}/llms.txt`];
      }),
    ]),
  );
  const officialHosts = entity.officialUrls.map((url) => new URL(url).hostname.replace(/^www\./, ""));
  const initialPages = await Promise.all(
    initialUrls.map((url) => retry(`page ${entity.name} ${url}`, () => fetchPageLedger(url, officialHosts))),
  );
  const sitemapPages = initialPages.filter((page) => page.url.endsWith("sitemap.xml") && page.status === 200);
  const discoveredUrls = Array.from(
    new Set(sitemapPages.flatMap((page) => page.sitemapUrls)),
  ).slice(0, 8);
  const discoveredPages = await Promise.all(
    discoveredUrls.map((url) => retry(`discovered page ${entity.name} ${url}`, () => fetchPageLedger(url, officialHosts))),
  );
  const pages = [...initialPages, ...discoveredPages];
  const searchResults = (
    await Promise.all(getSearchQueries(entity).map((query) => retry(`bing ${entity.name} ${query}`, () => fetchBingResults(query, entity))))
  ).flat();
  const doubao = [];
  for (const question of getDoubaoQuestions(entity)) {
    doubao.push(await callDoubao(entity, question));
  }
  const conclusions = assessEntity(entity, pages, searchResults, doubao);
  const body = makeBody({ entity, pages, searchResults, doubao, conclusions }, timestamp);
  return {
    entity,
    pages,
    searchResults,
    doubao,
    conclusions,
    note: {
      slug: entitySlug(entity),
      title: `${entity.name} GEOFlow 评估：豆包为什么提到它？`,
      excerpt: `${entity.name} 在豆包排名差报告中被提到。本报告用公开爬虫和豆包 API 对照判断它是否真有 GEO / AI 搜索优化 / 答案可见度能力，以及和 GEOFlow 的关系。`,
      type: "研究报告",
      tags: [entity.name, "GEO", "豆包", "排名差", "竞品分析", "证据分级"],
      body,
    },
  };
}

function toTsString(value: unknown) {
  return JSON.stringify(value, null, 2)
    .replace(/<\\\/script/gi, "<\\\\/script")
    .replace(/\u2028/g, "\\u2028")
    .replace(/\u2029/g, "\\u2029");
}

async function main() {
  const timestamp = new Date().toISOString().replace(/[-:]/g, "").replace(/\.\d{3}Z$/, "Z");
  await mkdir(outDir, { recursive: true });
  const selectedNames = new Set(
    (process.env.DOUBAO_RESEARCH_ENTITIES ?? "")
      .split(",")
      .map((item) => item.trim())
      .filter(Boolean),
  );
  const selected = selectedNames.size ? entities.filter((entity) => selectedNames.has(entity.name)) : entities;

  const assessments: EntityAssessment[] = [];
  for (const entity of selected) {
    assessments.push(await assessOne(entity, timestamp));
  }

  const ledgerPath = join(outDir, `${timestamp}-rank-gap-company-assessments.json`);
  const markdownPath = join(outDir, `${timestamp}-rank-gap-company-assessments.md`);
  await writeFile(ledgerPath, `${JSON.stringify(assessments, null, 2)}\n`);
  await writeFile(
    markdownPath,
    assessments
      .map((assessment) => assessment.note.body)
      .join("\n\n---\n\n"),
  );
  await writeFile(
    generatedNotesPath,
    `// Generated by scripts/doubao-research/run-rank-gap-company-assessments.ts\n\nexport const rankGapCompanyResearchNotes = ${toTsString(
      assessments.map((assessment) => assessment.note),
    )} as const;\n\nexport const rankGapCompanyResearchSlugs = rankGapCompanyResearchNotes.map((note) => note.slug);\n`,
  );
  console.log(
    JSON.stringify(
      {
        ledgerPath,
        markdownPath,
        generatedNotesPath,
        count: assessments.length,
      },
      null,
      2,
    ),
  );
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
