import type { WorkspaceState } from "@/lib/workspace-service";

export type KeywordExpansionGroup = {
  group: string;
  intent: string;
  keywords: string[];
  questions: string[];
  contentGaps: string[];
};

export type KeywordExpansionResult = {
  source: "model" | "rules";
  groups: KeywordExpansionGroup[];
  nextSteps: string[];
};

type ModelResponse = {
  choices?: Array<{
    message?: {
      content?: string;
    };
  }>;
};

type KeywordExpansionInput = {
  seed: string;
  industry?: string;
  competitors?: string;
  state: WorkspaceState;
};

const defaultGroups = [
  {
    group: "购买意图",
    intent: "客户正在找 GEO 服务商或解决方案。",
    templates: ["{seed}服务商", "{seed}怎么做", "{industry}AI搜索优化", "豆包GEO服务", "品牌可见度提升"],
  },
  {
    group: "对比意图",
    intent: "客户在比较方案、平台或竞品。",
    templates: ["{seed}和SEO区别", "{seed}服务对比", "豆包优化哪家好", "{competitor}替代方案", "GEO服务怎么选"],
  },
  {
    group: "品牌意图",
    intent: "客户已经知道品牌，需要验证可信度。",
    templates: ["geo.youngtuo.win怎么样", "geo.youngtuo.win案例", "geo.youngtuo.win报告", "{seed}客户案例", "{seed}效果证明"],
  },
  {
    group: "问题意图",
    intent: "客户用自然语言询问第一步和操作方法。",
    templates: ["{seed}第一步准备什么", "怎么让豆包推荐我的品牌", "豆包为什么不提到我", "如何证明AI收录提升", "怎么监测豆包答案变化"],
  },
  {
    group: "证据意图",
    intent: "客户或 AI 需要可引用材料。",
    templates: ["{seed}FAQ", "{seed}对比页", "{seed}案例证据", "Search Console收录", "llms.txt怎么写"],
  },
];

export async function expandKeywords(input: KeywordExpansionInput): Promise<KeywordExpansionResult> {
  const modelResult = await tryModelExpansion(input);
  if (modelResult) {
    return modelResult;
  }
  return expandWithRules(input);
}

function expandWithRules({ seed, industry, competitors, state }: KeywordExpansionInput): KeywordExpansionResult {
  const normalizedSeed = seed.trim() || "GEO";
  const normalizedIndustry = industry?.trim() || state.workspace.industry || "AI 搜索优化";
  const firstCompetitor = competitors
    ?.split(/[,，、\n]/)
    .map((item) => item.trim())
    .filter(Boolean)[0] || "竞品";

  const groups = defaultGroups.map((group) => {
    const keywords = group.templates.map((template) =>
      template
        .replaceAll("{seed}", normalizedSeed)
        .replaceAll("{industry}", normalizedIndustry)
        .replaceAll("{competitor}", firstCompetitor),
    );
    return {
      group: group.group,
      intent: group.intent,
      keywords,
      questions: keywords.map((keyword) => questionFromKeyword(keyword, group.group)),
      contentGaps: contentGapsForGroup(group.group),
    };
  });

  return {
    source: "rules",
    groups,
    nextSteps: [
      "把购买、对比、品牌、问题、证据五类问题保存为新豆包问题集。",
      "优先生成 FAQ、对比页、案例页，补充 AI 可引用内容。",
      "提交 sitemap.xml 到 Search Console 后按 Day 7/14/30 复测。",
    ],
  };
}

function questionFromKeyword(keyword: string, group: string) {
  if (group === "对比意图") return `${keyword}，客户应该怎么判断？`;
  if (group === "品牌意图") return `${keyword}，有哪些可信证据？`;
  if (group === "证据意图") return `${keyword}，需要准备哪些资料？`;
  if (group === "问题意图") return keyword.endsWith("？") ? keyword : `${keyword}？`;
  return `${keyword}时，豆包会推荐哪些服务商？`;
}

function contentGapsForGroup(group: string) {
  if (group === "购买意图") return ["服务流程页", "价格/交付说明", "客户案例"];
  if (group === "对比意图") return ["品牌 vs 竞品", "GEO vs SEO", "方案选择 FAQ"];
  if (group === "品牌意图") return ["品牌事实页", "公开报告", "客户见证"];
  if (group === "问题意图") return ["FAQ", "新手第一步指南", "监测教程"];
  return ["证据台账", "llms.txt", "Search Console 指南"];
}

async function tryModelExpansion({ seed, industry, competitors, state }: KeywordExpansionInput): Promise<KeywordExpansionResult | null> {
  const apiKey = process.env.AGENT_PLANNER_API_KEY || process.env.DOUBAO_API_KEY;
  if (!apiKey) return null;

  const baseUrl = process.env.AGENT_PLANNER_BASE_URL || process.env.DOUBAO_BASE_URL || "https://ark.cn-beijing.volces.com/api/v3";
  const model = process.env.AGENT_PLANNER_MODEL || process.env.DOUBAO_MODEL || "doubao-seed-2-0-pro-260215";

  try {
    const response = await fetch(`${baseUrl.replace(/\/$/, "")}/chat/completions`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${apiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        model,
        temperature: 0.2,
        response_format: { type: "json_object" },
        messages: [
          {
            role: "system",
            content:
              "你是 geo.youngtuo.win 的关键词与 AI 收录策略师。只输出 JSON，不要解释。输出格式：{\"groups\":[{\"group\":\"购买意图\",\"intent\":\"...\",\"keywords\":[\"...\"],\"questions\":[\"...\"],\"contentGaps\":[\"...\"]}],\"nextSteps\":[\"...\"]}。必须包含购买意图、对比意图、品牌意图、问题意图、证据意图五组，每组 6-10 个关键词、4-8 个豆包问题、2-4 个内容缺口。",
          },
          {
            role: "user",
            content: JSON.stringify({
              seed,
              industry: industry || state.workspace.industry,
              competitors,
              workspace: {
                name: state.workspace.name,
                domain: state.workspace.domain,
                stats: state.stats,
                latestQuestions: state.latestQuestions.slice(0, 10),
                brandFacts: state.brandFacts.slice(0, 8).map((fact) => `${fact.title}: ${fact.body}`),
              },
            }),
          },
        ],
      }),
    });

    if (!response.ok) return null;
    const data = (await response.json()) as ModelResponse;
    const parsed = parseModelContent(data.choices?.[0]?.message?.content ?? "");
    return parsed ? { source: "model", ...parsed } : null;
  } catch {
    return null;
  }
}

function parseModelContent(content: string): Omit<KeywordExpansionResult, "source"> | null {
  try {
    const parsed = JSON.parse(content) as {
      groups?: KeywordExpansionGroup[];
      nextSteps?: string[];
    };
    if (!Array.isArray(parsed.groups) || parsed.groups.length === 0) return null;
    return {
      groups: parsed.groups.slice(0, 8).map((group) => ({
        group: String(group.group || "关键词组").slice(0, 40),
        intent: String(group.intent || "覆盖客户搜索意图").slice(0, 160),
        keywords: cleanList(group.keywords, 12),
        questions: cleanList(group.questions, 10),
        contentGaps: cleanList(group.contentGaps, 6),
      })),
      nextSteps: cleanList(parsed.nextSteps, 6),
    };
  } catch {
    return null;
  }
}

function cleanList(value: unknown, limit: number) {
  return Array.isArray(value)
    ? value
        .map((item) => String(item).trim())
        .filter(Boolean)
        .slice(0, limit)
    : [];
}
