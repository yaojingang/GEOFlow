#!/usr/bin/env node

import { mkdir, writeFile } from "node:fs/promises";
import { join } from "node:path";

type ModelResponse = {
  choices?: Array<{ message?: { content?: string } }>;
};

type ProductCandidate = {
  name: string;
  category: string;
  description: string;
};

type ProductScore = ProductCandidate & {
  doubaoGeoScore: number;
  seoScore: number;
  gap: number;
  direction: "GEO领先" | "SEO领先" | "接近";
  doubaoGeoReason: string;
  seoReason: string;
  samplingQuestion: string;
  recommendedAction: string;
};

const outDir = process.env.DOUBAO_RESEARCH_OUT ?? join(process.cwd(), "research-runs/doubao-geo-seo-gap");

const products: ProductCandidate[] = [
  {
    name: "豆包答案采样监测",
    category: "监测产品",
    description: "围绕购买、对比、品牌、证据、风险等问题定期采样豆包答案，记录品牌提及、排名、竞品和错误事实。",
  },
  {
    name: "AI 答案来源质量审计",
    category: "审计产品",
    description: "检查豆包答案引用的来源是否官方、第三方可信、聚合页、社媒讨论或内容农场。",
  },
  {
    name: "品牌事实库",
    category: "内容基础设施",
    description: "把品牌定位、产品能力、适用人群、禁用说法、证据 URL 整理成结构化事实卡。",
  },
  {
    name: "llms.txt 与 AI 可发现性配置",
    category: "技术配置",
    description: "为站点生成 llms.txt、sitemap、结构化入口和 AI bot 可读路径，帮助 AI 发现核心页面。",
  },
  {
    name: "FAQ 问答资产",
    category: "内容资产",
    description: "围绕用户真实问题生成短问短答，覆盖购买、对比、价格、风险、操作步骤和证据。",
  },
  {
    name: "竞品对比页",
    category: "内容资产",
    description: "结构化对比品牌与竞品的适用场景、优缺点、证据和选择建议。",
  },
  {
    name: "客户案例证据页",
    category: "内容资产",
    description: "用具体客户、行业、问题、方案、结果和可引用证据说明品牌能力。",
  },
  {
    name: "公开研究报告节点",
    category: "研究资产",
    description: "像 Obsidian 一样沉淀研究节点、来源台账、采样问题、反向链接和方法结论。",
  },
  {
    name: "传统关键词排名监控",
    category: "传统 SEO",
    description: "追踪百度或 Google 某些关键词的自然搜索排名、点击、展示和页面流量。",
  },
  {
    name: "外链建设",
    category: "传统 SEO",
    description: "通过第三方链接、目录、媒体稿和合作页面提高传统搜索权重。",
  },
  {
    name: "长篇 SEO 文章矩阵",
    category: "传统 SEO",
    description: "围绕关键词批量生成长文、栏目页和资讯页，用于覆盖传统搜索长尾流量。",
  },
  {
    name: "Search Console / 百度站长配置",
    category: "传统 SEO",
    description: "提交 sitemap、检查索引、查看搜索展示点击和抓取问题。",
  },
  {
    name: "小红书 / 抖音种草内容",
    category: "社媒资产",
    description: "通过生活化笔记、短视频和真实用户场景提升平台内讨论度和外部社交证明。",
  },
  {
    name: "AI 搜索竞品榜单页",
    category: "内容资产",
    description: "整理品类内服务商、工具、评价维度和选择建议，争取进入 AI 的列表型回答。",
  },
  {
    name: "错误事实修正包",
    category: "修复产品",
    description: "针对豆包答案里的错误事实、过期信息或混淆竞品，补充纠错页面和证据链接并复测。",
  },
];

function clampScore(value: unknown) {
  const number = Number(value);
  if (!Number.isFinite(number)) return 0;
  return Math.max(0, Math.min(100, Math.round(number)));
}

function pickDirection(geo: number, seo: number): ProductScore["direction"] {
  if (geo - seo >= 15) return "GEO领先";
  if (seo - geo >= 15) return "SEO领先";
  return "接近";
}

function extractJson(content: string) {
  const fenced = content.match(/```(?:json)?\s*([\s\S]*?)```/i)?.[1];
  const raw = fenced ?? content;
  const start = raw.indexOf("{");
  const end = raw.lastIndexOf("}");
  if (start === -1 || end === -1 || end <= start) throw new Error("No JSON object in model response");
  return JSON.parse(raw.slice(start, end + 1)) as {
    products?: Array<Partial<ProductScore> & { name?: string }>;
  };
}

async function callDoubao() {
  const apiKey = process.env.DOUBAO_API_KEY;
  if (!apiKey) throw new Error("DOUBAO_API_KEY is not configured");

  const baseUrl = process.env.DOUBAO_BASE_URL ?? "https://ark.cn-beijing.volces.com/api/v3";
  const model = process.env.DOUBAO_MODEL ?? "doubao-seed-2-0-pro-260215";
  const response = await fetch(`${baseUrl.replace(/\/$/, "")}/chat/completions`, {
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
            "你是 GEOFlow 的豆包 GEO 产品研究员。只输出 JSON，不要输出 Markdown。",
            "任务：比较一批产品模块对“豆包 GEO / AI 答案可见度”和“传统 SEO / 搜索排名”的适配度。",
            "评分必须保守：0-100。不要把服务商营销承诺当事实。要说明为什么差异大。",
            "输出格式：{\"products\":[{\"name\":\"...\",\"doubaoGeoScore\":0,\"seoScore\":0,\"doubaoGeoReason\":\"...\",\"seoReason\":\"...\",\"samplingQuestion\":\"...\",\"recommendedAction\":\"...\"}]}",
          ].join("\n"),
        },
        {
          role: "user",
          content: JSON.stringify({
            project: "geo.youngtuo.win",
            question: "找出和传统 SEO 差异最大的豆包 GEO 产品清单。",
            scoringDefinition: {
              doubaoGeoScore:
                "该产品是否直接影响豆包答案里的品牌提及、推荐排序、来源引用、事实正确性、竞品对比、AI 可发现性。",
              seoScore:
                "该产品是否直接影响传统搜索引擎的自然排名、索引、点击、外链权重、关键词覆盖和搜索流量。",
            },
            products,
          }),
        },
      ],
    }),
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`Doubao API failed: ${response.status} ${body.slice(0, 500)}`);
  }

  const data = (await response.json()) as ModelResponse;
  const content = data.choices?.[0]?.message?.content ?? "";
  return { content, model };
}

function normalizeScores(parsed: { products?: Array<Partial<ProductScore> & { name?: string }> }) {
  const byName = new Map(products.map((product) => [product.name, product]));
  return (parsed.products ?? [])
    .filter((item) => item.name && byName.has(item.name))
    .map((item): ProductScore => {
      const base = byName.get(item.name!)!;
      const doubaoGeoScore = clampScore(item.doubaoGeoScore);
      const seoScore = clampScore(item.seoScore);
      const gap = Math.abs(doubaoGeoScore - seoScore);
      return {
        ...base,
        doubaoGeoScore,
        seoScore,
        gap,
        direction: pickDirection(doubaoGeoScore, seoScore),
        doubaoGeoReason: String(item.doubaoGeoReason ?? "").trim(),
        seoReason: String(item.seoReason ?? "").trim(),
        samplingQuestion: String(item.samplingQuestion ?? "").trim(),
        recommendedAction: String(item.recommendedAction ?? "").trim(),
      };
    })
    .sort((a, b) => b.gap - a.gap || b.doubaoGeoScore - a.doubaoGeoScore);
}

function toMarkdown(scores: ProductScore[], model: string) {
  const top = scores.slice(0, 10);
  const geoLead = scores.filter((item) => item.direction === "GEO领先").slice(0, 8);
  const seoLead = scores.filter((item) => item.direction === "SEO领先").slice(0, 8);

  return [
    `# 豆包 GEO 与传统 SEO 产品差异采样`,
    ``,
    `生成时间：${new Date().toISOString()}`,
    `模型：${model}`,
    ``,
    `## Top 差异产品`,
    ``,
    `| 排名 | 产品 | 分类 | 豆包 GEO | 传统 SEO | 差值 | 方向 | 建议 |`,
    `|---|---|---|---:|---:|---:|---|---|`,
    ...top.map(
      (item, index) =>
        `| ${index + 1} | ${item.name} | ${item.category} | ${item.doubaoGeoScore} | ${item.seoScore} | ${item.gap} | ${item.direction} | ${item.recommendedAction.replaceAll("|", "/")} |`,
    ),
    ``,
    `## GEO 领先清单`,
    ``,
    `| 产品 | 差值 | 豆包 GEO 理由 | SEO 理由 | 采样问题 |`,
    `|---|---:|---|---|---|`,
    ...geoLead.map(
      (item) =>
        `| ${item.name} | ${item.gap} | ${item.doubaoGeoReason.replaceAll("|", "/")} | ${item.seoReason.replaceAll("|", "/")} | ${item.samplingQuestion.replaceAll("|", "/")} |`,
    ),
    ``,
    `## SEO 领先清单`,
    ``,
    `| 产品 | 差值 | 豆包 GEO 理由 | SEO 理由 | 采样问题 |`,
    `|---|---:|---|---|---|`,
    ...seoLead.map(
      (item) =>
        `| ${item.name} | ${item.gap} | ${item.doubaoGeoReason.replaceAll("|", "/")} | ${item.seoReason.replaceAll("|", "/")} | ${item.samplingQuestion.replaceAll("|", "/")} |`,
    ),
    ``,
    `## 原始 JSON`,
    ``,
    "```json",
    JSON.stringify({ scores }, null, 2),
    "```",
    ``,
  ].join("\n");
}

async function main() {
  await mkdir(outDir, { recursive: true });
  const { content, model } = await callDoubao();
  const parsed = extractJson(content);
  const scores = normalizeScores(parsed);
  if (scores.length < 8) throw new Error(`Too few scored products: ${scores.length}`);

  const stamp = new Date().toISOString().replace(/[-:]/g, "").replace(/\.\d{3}Z$/, "Z");
  const jsonPath = join(outDir, `${stamp}-geo-seo-gap.json`);
  const mdPath = join(outDir, `${stamp}-geo-seo-gap.md`);
  await writeFile(jsonPath, JSON.stringify({ generatedAt: new Date().toISOString(), model, scores }, null, 2));
  await writeFile(mdPath, toMarkdown(scores, model));

  console.log(JSON.stringify({ jsonPath, mdPath, count: scores.length, top: scores.slice(0, 5) }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});

