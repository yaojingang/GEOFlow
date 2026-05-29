#!/usr/bin/env node

import { mkdir, readFile, readdir, writeFile } from "node:fs/promises";
import { join } from "node:path";

type PriorAssessment = {
  entity: {
    name: string;
    aliases: string[];
    category: string;
    officialUrls: string[];
    rankGapReason: string;
  };
  pages: Array<{
    url: string;
    status: number | "error";
    title: string;
    excerpt: string;
    keywordHits: string[];
    metaDescription?: string;
  }>;
  searchResults: Array<{
    query: string;
    rank: number;
    title: string;
    snippet: string;
    url: string;
    sourceKind?: string;
  }>;
};

type ScanResult = {
  entity: string;
  category: string;
  level: "strong_intent_signal" | "possible_pollution" | "weak_or_noise";
  score: number;
  officialSignals: Signal[];
  searchSignals: Signal[];
  hostSpread: Array<{ host: string; count: number; reason: string }>;
  interpretation: string;
};

type Signal = {
  source: string;
  title: string;
  snippet: string;
  score: number;
  reasons: string[];
};

const outDir = process.env.DOUBAO_RESEARCH_OUT ?? join(process.cwd(), "research-runs/rank-pollution-scan");
const generatedNotePath =
  process.env.DOUBAO_RESEARCH_NOTE_OUT ?? join(process.cwd(), "src/lib/generated-doubao-rank-pollution-scan.ts");
const rankGapDir = join(process.cwd(), "research-runs/rank-gap-company-assessments");
const requestTimeoutMs = Number(process.env.DOUBAO_RESEARCH_TIMEOUT_MS ?? 12_000);

const aiTargetTerms = [
  "豆包",
  "GEO",
  "生成式引擎优化",
  "AI 搜索",
  "AI搜索",
  "AI search",
  "AI visibility",
  "LLM visibility",
  "answer engine",
  "AEO",
  "ChatGPT",
  "DeepSeek",
  "AI Overviews",
  "品牌可见度",
  "答案可见度",
  "llms.txt",
];

const manipulationTerms = [
  "排名",
  "靠前",
  "推荐",
  "收录",
  "可见度",
  "曝光",
  "优化",
  "监测",
  "官方GEO",
  "GEO优化服务",
  "生成式引擎优化解决方案",
  "AI搜索优化",
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

function safeCell(value: string | number | undefined) {
  return String(value ?? "").replaceAll("|", "/").replace(/\s+/g, " ").trim();
}

function hostOf(url: string) {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return "";
  }
}

function officialHosts(entity: PriorAssessment["entity"]) {
  return entity.officialUrls.map((url) => hostOf(url)).filter(Boolean);
}

function hasTerm(text: string, terms: string[]) {
  const lower = text.toLowerCase();
  return terms.filter((term) => lower.includes(term.toLowerCase()));
}

function scoreSignal(input: { title: string; snippet: string; url: string }, entity: PriorAssessment["entity"]): Signal {
  const text = `${input.title} ${input.snippet} ${input.url}`;
  const aiHits = hasTerm(text, aiTargetTerms);
  const manipulationHits = hasTerm(text, manipulationTerms);
  const host = hostOf(input.url);
  const hosts = officialHosts(entity);
  const isOfficial = hosts.some((officialHost) => host.endsWith(officialHost));
  const brandInHost = entity.aliases.some((alias) => {
    const normalized = alias.toLowerCase().replace(/[^a-z0-9]/g, "");
    return normalized.length >= 4 && host.replace(/[^a-z0-9]/g, "").includes(normalized);
  });
  const reasons = [
    aiHits.length ? `AI/GEO 词命中：${aiHits.slice(0, 6).join("、")}` : "",
    manipulationHits.length ? `排名/优化意图词命中：${manipulationHits.slice(0, 6).join("、")}` : "",
    !isOfficial && brandInHost ? "非官方或代理域名含品牌词" : "",
    /llms\.txt/i.test(text) ? "出现 llms.txt 可发现性信号" : "",
    /官方.*GEO|GEO.*官方|生成式引擎优化解决方案/.test(text) ? "出现疑似模型友好服务名" : "",
  ].filter(Boolean);
  const score =
    aiHits.length * 2 +
    manipulationHits.length * 2 +
    (!isOfficial && brandInHost ? 3 : 0) +
    (/llms\.txt/i.test(text) ? 2 : 0) +
    (/官方.*GEO|GEO.*官方|生成式引擎优化解决方案/.test(text) ? 4 : 0);
  return {
    source: input.url,
    title: input.title,
    snippet: input.snippet,
    score,
    reasons,
  };
}

async function fetchWithTimeout(url: string, label: string) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), requestTimeoutMs);
  try {
    return await fetch(url, {
      signal: controller.signal,
      headers: {
        "User-Agent": "Mozilla/5.0 GEOFlow Rank Pollution Scanner; public evidence only",
        "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
      },
    });
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") throw new Error(`${label} timed out`);
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

async function fetchBing(query: string) {
  const url = `https://www.bing.com/search?q=${encodeURIComponent(query)}&cc=cn&setlang=zh-CN&count=10`;
  const response = await fetchWithTimeout(url, query);
  if (!response.ok) return [];
  const html = await response.text();
  const blocks = html.match(/<li class="b_algo"[\s\S]*?<\/li>/gi) ?? [];
  return blocks.slice(0, 10).map((block, index) => {
    const anchor = block.match(/<a[^>]+href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i);
    const snippet = block.match(/<p[^>]*>([\s\S]*?)<\/p>/i)?.[1] ?? "";
    return {
      query,
      rank: index + 1,
      title: stripTags(anchor?.[2] ?? `Result ${index + 1}`),
      snippet: stripTags(snippet).slice(0, 320),
      url: decodeEntities(anchor?.[1] ?? ""),
    };
  });
}

function scanQueries(entity: PriorAssessment["entity"]) {
  const primary = entity.aliases[0] ?? entity.name;
  return [
    `"${primary}" "豆包" "GEO"`,
    `"${primary}" "豆包" "推荐"`,
    `"${primary}" "ChatGPT" "推荐"`,
    `"${primary}" "DeepSeek" "推荐"`,
    `"${primary}" "AI搜索优化"`,
    `"${primary}" "答案可见度"`,
    `"${primary}" "llms.txt"`,
    `"${primary}" "生成式引擎优化"`,
  ];
}

async function latestRankGapLedger() {
  const files = (await readdir(rankGapDir))
    .filter((file) => file.endsWith("-rank-gap-company-assessments.json"))
    .sort();
  if (!files.length) throw new Error(`No rank gap company assessment ledger found in ${rankGapDir}`);
  return join(rankGapDir, files[files.length - 1]);
}

function hostSpread(signals: Signal[], entity: PriorAssessment["entity"]) {
  const hosts = officialHosts(entity);
  const counts = new Map<string, number>();
  for (const signal of signals) {
    const host = hostOf(signal.source);
    if (!host || hosts.some((officialHost) => host.endsWith(officialHost))) continue;
    counts.set(host, (counts.get(host) ?? 0) + 1);
  }
  return [...counts.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, 8)
    .map(([host, count]) => ({
      host,
      count,
      reason: count >= 3 ? "同一非官方域名多次命中，可能是代理/镜像/铺量。" : "非官方域名命中 AI/GEO 语境。",
    }));
}

async function scanOne(assessment: PriorAssessment): Promise<ScanResult> {
  const entity = assessment.entity;
  const officialSignals = assessment.pages
    .map((page) =>
      scoreSignal(
        {
          title: page.title,
          snippet: `${page.metaDescription ?? ""} ${page.excerpt} ${page.keywordHits.join(" ")}`,
          url: page.url,
        },
        entity,
      ),
    )
    .filter((signal) => signal.score >= 4)
    .sort((a, b) => b.score - a.score)
    .slice(0, 8);

  const freshResults = (
    await Promise.all(
      scanQueries(entity).map(async (query) => {
        try {
          return await fetchBing(query);
        } catch {
          return [];
        }
      }),
    )
  ).flat();

  const combinedSearch = [
    ...assessment.searchResults.map((result) => ({
      title: result.title,
      snippet: result.snippet,
      url: result.url,
    })),
    ...freshResults,
  ];
  const searchSignals = combinedSearch
    .map((result) => scoreSignal(result, entity))
    .filter((signal) => signal.score >= 5)
    .sort((a, b) => b.score - a.score)
    .slice(0, 12);
  const spread = hostSpread(searchSignals, entity);
  const score =
    officialSignals.reduce((total, signal) => total + signal.score, 0) +
    searchSignals.reduce((total, signal) => total + signal.score, 0) +
    spread.reduce((total, item) => total + Math.min(item.count, 4), 0);
  const hasOfficialIntent = officialSignals.some((signal) => signal.reasons.some((reason) => reason.includes("AI/GEO")));
  const hasSpread = spread.some((item) => item.count >= 3);
  const level =
    hasOfficialIntent && score >= 24 ? "strong_intent_signal" : hasSpread || score >= 16 ? "possible_pollution" : "weak_or_noise";
  const interpretation =
    level === "strong_intent_signal"
      ? "公开资料显示该对象主动把自己放进 AI 搜索 / GEO / 答案可见度语境，属于需要重点监测的强意图信号。"
      : level === "possible_pollution"
        ? "搜索结果出现非官方域名、代理页或 AI/GEO 语义铺量，可能影响模型答案，但需要继续复核来源归属。"
        : "当前更多是弱相关或搜索噪声，不能写成刻意污染。";
  return {
    entity: entity.name,
    category: entity.category,
    level,
    score,
    officialSignals,
    searchSignals,
    hostSpread: spread,
    interpretation,
  };
}

function makeReport(results: ScanResult[], timestamp: string) {
  const strong = results.filter((result) => result.level === "strong_intent_signal");
  const possible = results.filter((result) => result.level === "possible_pollution");
  const rows = results
    .sort((a, b) => b.score - a.score)
    .map(
      (result) =>
        `| ${safeCell(result.entity)} | ${safeCell(result.level)} | ${result.score} | ${safeCell(result.interpretation)} | ${safeCell(
          result.officialSignals[0]?.title || result.searchSignals[0]?.title || "无",
        )} |`,
    )
    .join("\n");
  const strongRows =
    strong
      .map((result) => `| ${safeCell(result.entity)} | ${safeCell(result.officialSignals[0]?.title)} | ${safeCell(result.officialSignals[0]?.snippet.slice(0, 180))} | ${safeCell(result.officialSignals[0]?.source)} |`)
      .join("\n") || "| 无 | 无 | 无 | 无 |";
  const possibleRows =
    possible
      .slice(0, 12)
      .map((result) => {
        const hosts = result.hostSpread.map((item) => `${item.host}×${item.count}`).join("；") || "无";
        return `| ${safeCell(result.entity)} | ${safeCell(hosts)} | ${safeCell(result.searchSignals[0]?.title || "无")} | ${safeCell(result.searchSignals[0]?.source || "无")} |`;
      })
      .join("\n") || "| 无 | 无 | 无 | 无 |";

  return `# 豆包提前污染信号扫描：哪些对象在主动铺 AI 搜索语义？

生成日期：${timestamp}

## 一句话结论

本轮没有把任何对象写成“已证实污染豆包”。更严谨的结论是：部分对象已经在公开页面主动铺设 AI search / GEO / AI visibility / ChatGPT / llms.txt 等语义，属于可能让豆包提前推荐的强意图信号；另一些对象则是非官方代理页、镜像页或搜索噪声造成的可疑铺量。

## 判定口径

| 等级 | 含义 |
|---|---|
| strong_intent_signal | 官网或官方资料主动使用 AI 搜索、GEO、AI visibility、ChatGPT visibility、llms.txt 等词。它不等于作弊，但说明它在主动迎合 AI 答案可见度。 |
| possible_pollution | 搜索结果里出现多个非官方域名、代理页、镜像页或“官方 GEO / 生成式引擎优化”等服务名组合，可能影响模型答案，需要复核。 |
| weak_or_noise | 只有弱相关、无关搜索结果或普通品牌资料，不能写成刻意污染。 |

## 总表

| 对象 | 等级 | 分数 | 解释 | 最强证据 |
|---|---|---:|---|---|
${rows}

## 强意图信号：主动把自己放进 AI 搜索语境

| 对象 | 官方/强来源标题 | 证据摘要 | URL |
|---|---|---|---|
${strongRows}

## 可疑铺量：非官方域名、代理页或模型友好服务名

| 对象 | 非官方域名分布 | 最强搜索信号 | URL |
|---|---|---|---|
${possibleRows}

## 怎么用于 GEOFlow

- 把 strong_intent_signal 对象放进高频监测池：它们很可能通过官网文案、llms.txt、AI search 页面或代理资料进入豆包答案。
- 对 possible_pollution 对象不要直接定性，要先查域名归属、页面更新时间、是否复制官网、是否有“官方 GEO”这类模型友好但不可复核的服务名。
- GEOFlow 的反制不是堆关键词，而是建立可复核的事实库、案例页、FAQ、开放报告、llms.txt 和采样记录，让豆包能引用真实证据。

## 下一轮要抓

| 方向 | 目的 |
|---|---|
| 同域名历史快照 | 判断页面是否近期为 AI 搜索/GEO 改写。 |
| 多搜索引擎交叉 | 区分 Bing 噪声和全网真实铺量。 |
| 豆包重复采样 | 观察这些对象是否在豆包答案里稳定提前出现。 |
| 域名归属核验 | 区分官方代理、授权代理、镜像和内容农场。 |
`;
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
  const ledgerPath = await latestRankGapLedger();
  const assessments = JSON.parse(await readFile(ledgerPath, "utf8")) as PriorAssessment[];
  const results: ScanResult[] = [];
  for (const assessment of assessments) {
    console.error(`[scan] ${assessment.entity.name}`);
    results.push(await scanOne(assessment));
  }
  results.sort((a, b) => b.score - a.score);
  const body = makeReport(results, timestamp);
  const note = {
    slug: "doubao-rank-pollution-signal-scan",
    title: "豆包提前污染信号扫描：哪些对象在主动铺 AI 搜索语义？",
    excerpt: "用公开爬虫识别官网 AI 搜索语义、非官方域名铺量、模型友好服务名和搜索噪声，筛出最需要监测的豆包提前推荐污染信号。",
    type: "研究报告",
    tags: ["豆包", "污染信号", "GEO", "AI搜索", "排名差", "证据分级"],
    body,
  };
  const resultPath = join(outDir, `${timestamp}-doubao-rank-pollution-scan.json`);
  const markdownPath = join(outDir, `${timestamp}-doubao-rank-pollution-scan.md`);
  await writeFile(resultPath, `${JSON.stringify({ sourceLedger: ledgerPath, results }, null, 2)}\n`);
  await writeFile(markdownPath, body);
  await writeFile(
    generatedNotePath,
    `// Generated by scripts/doubao-research/run-rank-pollution-scan.ts\n\nexport const doubaoRankPollutionScanNote = ${toTsString(
      note,
    )} as const;\n`,
  );
  console.log(JSON.stringify({ resultPath, markdownPath, generatedNotePath, count: results.length }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
