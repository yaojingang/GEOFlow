#!/usr/bin/env node

import { mkdir, writeFile } from "node:fs/promises";
import { join } from "node:path";

type ModelResponse = {
  choices?: Array<{ message?: { content?: string } }>;
};

type DoubaoPick = {
  query: string;
  name: string;
  doubaoRank: number;
  category: string;
  reason: string;
};

type WebResult = {
  query: string;
  rank: number;
  title: string;
  snippet: string;
  url: string;
};

type GapResult = DoubaoPick & {
  webRank: number;
  gap: number;
  webEvidence: string;
  webUrl: string;
  status: "web_absent_top20" | "web_lower" | "web_close_or_higher";
};

const outDir = process.env.DOUBAO_RESEARCH_OUT ?? join(process.cwd(), "research-runs/doubao-vs-web-rank-gap");
const maxWebRank = 20;
const absentRank = 99;
const requestTimeoutMs = 45_000;
const maxAttempts = 3;

const queries = [
  "生成式引擎优化 GEO 服务 推荐",
  "AI 搜索优化 工具 推荐",
  "AI 答案监测 工具 推荐",
  "品牌 AI 搜索 可见度 监测",
  "豆包 GEO 优化 服务 推荐",
  "llms.txt 生成 工具 推荐",
  "AI 搜索 竞品分析 工具 推荐",
  "GEO 和 SEO 优化 服务商 推荐",
];

function stripTags(value: string) {
  return value
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

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

function normalize(value: string) {
  return value
    .toLowerCase()
    .replace(/https?:\/\/\S+/g, " ")
    .replace(/[^\p{L}\p{N}]+/gu, "")
    .trim();
}

function aliasesFor(name: string) {
  const raw = name.trim();
  const aliases = new Set<string>([raw]);
  aliases.add(raw.replace(/\s+/g, ""));
  aliases.add(raw.replace(/\.com$/i, ""));
  aliases.add(raw.replace(/（.*?）|\(.*?\)/g, "").trim());
  aliases.add(raw.replace(/工具|平台|服务|系统|产品/g, "").trim());
  return [...aliases].map(normalize).filter((item) => item.length >= 2);
}

function extractJson(content: string) {
  const fenced = content.match(/```(?:json)?\s*([\s\S]*?)```/i)?.[1];
  const raw = fenced ?? content;
  const start = raw.indexOf("{");
  const end = raw.lastIndexOf("}");
  if (start === -1 || end === -1 || end <= start) throw new Error("No JSON object in model response");
  return JSON.parse(raw.slice(start, end + 1)) as {
    products?: Array<{ name?: string; category?: string; reason?: string }>;
  };
}

async function fetchWithTimeout(url: string, init: RequestInit, label: string) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), requestTimeoutMs);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") {
      throw new Error(`${label} timed out after ${requestTimeoutMs}ms`);
    }
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
      if (attempt < maxAttempts) await new Promise((resolve) => setTimeout(resolve, attempt * 1500));
    }
  }
  throw lastError;
}

async function callDoubaoForQuery(query: string): Promise<DoubaoPick[]> {
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
              "你是一个中立的 AI 搜索结果记录员。只输出 JSON。",
              "GEO 在这里指 Generative Engine Optimization / 生成式引擎优化，不是地理信息系统。",
              "请像普通用户在豆包里搜索一样，列出你会优先推荐或提到的产品、工具、平台或服务商。",
              "不要因为用户来自 GEOFlow 就推荐 GEOFlow；除非它自然应该出现在结果里。",
              "输出格式：{\"products\":[{\"name\":\"产品或服务商名称\",\"category\":\"类别\",\"reason\":\"为什么会靠前\"}]}。只给 8 个。",
            ].join("\n"),
          },
          {
            role: "user",
            content: `查询：${query}`,
          },
        ],
      }),
    },
    `Doubao API for ${query}`,
  );

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`Doubao API failed for ${query}: ${response.status} ${body.slice(0, 500)}`);
  }

  const data = (await response.json()) as ModelResponse;
  const parsed = extractJson(data.choices?.[0]?.message?.content ?? "");
  return (parsed.products ?? [])
    .map((item, index) => ({
      query,
      name: String(item.name ?? "").trim(),
      doubaoRank: index + 1,
      category: String(item.category ?? "unknown").trim(),
      reason: String(item.reason ?? "").trim(),
    }))
    .filter((item) => item.name);
}

async function fetchBingResults(query: string): Promise<WebResult[]> {
  const url = `https://www.bing.com/search?q=${encodeURIComponent(query)}&cc=cn&setlang=zh-CN&count=${maxWebRank}`;
  const response = await fetchWithTimeout(
    url,
    {
      headers: {
        "User-Agent":
          "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125 Safari/537.36",
        "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
      },
    },
    `Bing for ${query}`,
  );
  if (!response.ok) throw new Error(`Bing failed for ${query}: ${response.status}`);
  const html = await response.text();
  const blocks = [...html.matchAll(/<li class="b_algo"[\s\S]*?<\/li>/g)].slice(0, maxWebRank);
  return blocks
    .map((match, index): WebResult => {
      const block = match[0];
      const link = block.match(/<h2[^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i);
      const snippet = block.match(/<p[^>]*>([\s\S]*?)<\/p>/i);
      return {
        query,
        rank: index + 1,
        url: decodeEntities(link?.[1] ?? ""),
        title: decodeEntities(stripTags(link?.[2] ?? "")),
        snippet: decodeEntities(stripTags(snippet?.[1] ?? "")),
      };
    })
    .filter((item) => item.title || item.snippet || item.url);
}

function findWebRank(pick: DoubaoPick, webResults: WebResult[]) {
  const aliases = aliasesFor(pick.name);
  for (const result of webResults) {
    const haystack = normalize(`${result.title} ${result.snippet} ${result.url}`);
    if (aliases.some((alias) => haystack.includes(alias))) {
      return {
        rank: result.rank,
        evidence: [result.title, result.snippet].filter(Boolean).join(" - ").slice(0, 220),
        url: result.url,
      };
    }
  }
  return {
    rank: absentRank,
    evidence: "网页搜索前 20 未命中该名称",
    url: "",
  };
}

function computeGaps(picks: DoubaoPick[], webByQuery: Map<string, WebResult[]>) {
  const seen = new Set<string>();
  const gaps: GapResult[] = [];
  for (const pick of picks) {
    const key = `${pick.query}::${normalize(pick.name)}`;
    if (seen.has(key)) continue;
    seen.add(key);
    const webMatch = findWebRank(pick, webByQuery.get(pick.query) ?? []);
    const gap = webMatch.rank - pick.doubaoRank;
    gaps.push({
      ...pick,
      webRank: webMatch.rank,
      gap,
      webEvidence: webMatch.evidence,
      webUrl: webMatch.url,
      status: webMatch.rank === absentRank ? "web_absent_top20" : gap >= 5 ? "web_lower" : "web_close_or_higher",
    });
  }
  return gaps.sort((a, b) => b.gap - a.gap || a.doubaoRank - b.doubaoRank);
}

function aggregateByProduct(gaps: GapResult[]) {
  const best = new Map<string, GapResult>();
  for (const gap of gaps) {
    const key = normalize(gap.name);
    const current = best.get(key);
    if (!current || gap.gap > current.gap) {
      best.set(key, gap);
    }
  }
  return [...best.values()].sort((a, b) => b.gap - a.gap || a.doubaoRank - b.doubaoRank);
}

function toMarkdown(gaps: GapResult[], webByQuery: Map<string, WebResult[]>) {
  const top = aggregateByProduct(gaps).filter((item) => item.gap > 0).slice(0, 20);
  return [
    `# 豆包靠前但网页靠后的产品差异报告`,
    ``,
    `生成时间：${new Date().toISOString()}`,
    ``,
    `## 方法`,
    ``,
    `- 豆包侧：生产环境 Doubao API，对 8 个 GEO / AI 搜索优化相关查询分别取前 8 个产品、工具、平台或服务商。`,
    `- 网页侧：同一查询抓取 Bing 网页搜索前 ${maxWebRank} 条自然结果。`,
    `- 差值：网页排名 - 豆包排名；网页前 ${maxWebRank} 未命中记为 ${absentRank}。差值越大，表示豆包越靠前但网页越靠后。`,
    `- 边界：这是一次搜索/答案快照，不等于长期稳定排名；需要后续 Day 7 / 14 / 30 复测。`,
    ``,
    `## 差异最大清单`,
    ``,
    `| 排名 | 产品/服务 | 查询 | 豆包排名 | 网页排名 | 差值 | 状态 | 豆包靠前原因 | 网页证据 |`,
    `|---|---|---|---:|---:|---:|---|---|---|`,
    ...top.map(
      (item, index) =>
        `| ${index + 1} | ${item.name.replaceAll("|", "/")} | ${item.query.replaceAll("|", "/")} | ${item.doubaoRank} | ${item.webRank === absentRank ? "未进前20" : item.webRank} | ${item.gap} | ${item.status} | ${item.reason.replaceAll("|", "/")} | ${item.webEvidence.replaceAll("|", "/")} |`,
    ),
    ``,
    `## 查询覆盖`,
    ``,
    `| 查询 | 网页结果数 | 网页 Top 3 |`,
    `|---|---:|---|`,
    ...[...webByQuery.entries()].map(([query, results]) => {
      const top3 = results
        .slice(0, 3)
        .map((item) => `${item.rank}. ${item.title}`)
        .join(" / ")
        .replaceAll("|", "/");
      return `| ${query} | ${results.length} | ${top3} |`;
    }),
    ``,
  ].join("\n");
}

async function main() {
  await mkdir(outDir, { recursive: true });
  const picks: DoubaoPick[] = [];
  const webByQuery = new Map<string, WebResult[]>();

  for (const query of queries) {
    try {
      console.error(`[doubao] ${query}`);
      const queryPicks = await retry(`Doubao ${query}`, () => callDoubaoForQuery(query));
      console.error(`[doubao] ${query}: ${queryPicks.length} picks`);
      console.error(`[bing] ${query}`);
      const webResults = await retry(`Bing ${query}`, () => fetchBingResults(query));
      console.error(`[bing] ${query}: ${webResults.length} results`);
      picks.push(...queryPicks);
      webByQuery.set(query, webResults);
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      console.error(`[skip] ${query}: ${message}`);
    }
  }

  const gaps = computeGaps(picks, webByQuery);
  const generatedAt = new Date().toISOString();
  const stamp = generatedAt.replace(/[-:]/g, "").replace(/\.\d{3}Z$/, "Z");
  const jsonPath = join(outDir, `${stamp}-doubao-vs-web-rank-gap.json`);
  const mdPath = join(outDir, `${stamp}-doubao-vs-web-rank-gap.md`);
  await writeFile(jsonPath, JSON.stringify({ generatedAt, queries, picks, webByQuery: Object.fromEntries(webByQuery), gaps }, null, 2));
  await writeFile(mdPath, toMarkdown(gaps, webByQuery));

  console.log(JSON.stringify({ jsonPath, mdPath, count: gaps.length, top: aggregateByProduct(gaps).slice(0, 8) }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
