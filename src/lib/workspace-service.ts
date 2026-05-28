import { AgentMode, Platform } from "@prisma/client";
import { prisma } from "@/lib/prisma";

export const defaultWorkspaceKey = "geo.youngtuo.win";
const defaultDoubaoBaseUrl = "https://ark.cn-beijing.volces.com/api/v3";
const defaultDoubaoModel = "doubao-seed-2-0-pro-260215";

function configured(value: string | undefined) {
  return Boolean(value?.trim());
}

const defaultQuestions = [
  "豆包推荐做 GEO 服务的公司时，怎样让它提到我们？",
  "如果客户想做 AI 搜索优化，应该先准备哪些资料？",
  "豆包答案里为什么会推荐竞品而不是我们？",
  "怎么判断一个品牌在豆包里的可见度有没有提升？",
  "GEO 服务和传统 SEO 的区别是什么？",
];

const defaultAnalyticsGuides = [
  ["GA4", "missing", "新建 Web Stream，填写 Measurement ID，后续用于线索和页面事件归因。"],
  ["Search Console", "missing", "验证 geo.youngtuo.win 域名属性，提交 sitemap.xml。"],
  ["百度统计", "missing", "新建站点并粘贴统计脚本，适合国内客户查看访问趋势。"],
  ["Doubao GEO Monitor", "active", "内置采样记录，跟踪提及率、推荐排名、竞品命中和错误事实。"],
] as const;

const defaultSocialAccounts = [
  ["微信公众号", "", "", false],
  ["小红书", "", "", false],
  ["抖音", "", "", false],
  ["视频号", "", "", false],
  ["LinkedIn", "", "", false],
] as const;

export async function getOrCreateWorkspace() {
  const existing = await prisma.workspace.findFirst({
    where: { domain: defaultWorkspaceKey },
  });

  if (existing) {
    await ensureWorkspaceChildren(existing.id);
    return existing;
  }

  const workspace = await prisma.workspace.create({
    data: {
      name: defaultWorkspaceKey,
      domain: defaultWorkspaceKey,
      industry: "AI 搜索优化 / GEO",
      market: "China",
      summary: "主攻豆包答案可见度的 GEO 客户项目工作台。",
      agentSettings: {
        create: {
          mode: AgentMode.Explain,
        },
      },
      questionSets: {
        create: {
          title: "豆包初始问题集",
          platform: Platform.Doubao,
          questions: defaultQuestions,
        },
      },
      brandFacts: {
        create: [
          {
            title: "主战场",
            body: "项目优先优化豆包里的品牌答案、推荐排名、竞品对比和错误事实。",
            evidenceUrl: "https://geo.youngtuo.win",
            confidence: 90,
          },
        ],
      },
    },
  });

  await ensureWorkspaceChildren(workspace.id);
  return workspace;
}

async function ensureWorkspaceChildren(workspaceId: string) {
  const setting = await prisma.agentSetting.findUnique({ where: { workspaceId } });
  if (!setting) {
    await prisma.agentSetting.create({ data: { workspaceId, mode: AgentMode.Explain } });
  }

  const questionCount = await prisma.questionSet.count({ where: { workspaceId } });
  if (questionCount === 0) {
    await prisma.questionSet.create({
      data: {
        workspaceId,
        title: "豆包初始问题集",
        platform: Platform.Doubao,
        questions: defaultQuestions,
      },
    });
  }

  for (const [provider, status, guide] of defaultAnalyticsGuides) {
    await prisma.analyticsConfig.upsert({
      where: {
        id: `${workspaceId}-${provider}`,
      },
      update: {},
      create: {
        id: `${workspaceId}-${provider}`,
        workspaceId,
        provider,
        status,
        guide,
      },
    });
  }

  for (const [platform, handle, url, isVisible] of defaultSocialAccounts) {
    const current = await prisma.socialAccount.findFirst({ where: { workspaceId, platform } });
    if (!current) {
      await prisma.socialAccount.create({
        data: {
          workspaceId,
          platform,
          handle,
          url,
          isVisible,
        },
      });
    }
  }
}

export async function getWorkspaceState() {
  const workspace = await getOrCreateWorkspace();

  const [
    agentSettings,
    analyticsConfigs,
    socialAccounts,
    questionSets,
    answerSamples,
    reports,
    sourceAssets,
    brandFacts,
    contentAssets,
    apiTokens,
  ] = await Promise.all([
    prisma.agentSetting.findUnique({ where: { workspaceId: workspace.id } }),
    prisma.analyticsConfig.findMany({ where: { workspaceId: workspace.id }, orderBy: { provider: "asc" } }),
    prisma.socialAccount.findMany({ where: { workspaceId: workspace.id }, orderBy: { platform: "asc" } }),
    prisma.questionSet.findMany({ where: { workspaceId: workspace.id }, orderBy: { createdAt: "desc" } }),
    prisma.answerSample.findMany({ where: { workspaceId: workspace.id }, orderBy: { sampledAt: "desc" }, take: 20 }),
    prisma.report.findMany({ where: { workspaceId: workspace.id }, orderBy: { createdAt: "desc" }, take: 10 }),
    prisma.sourceAsset.findMany({ where: { workspaceId: workspace.id }, orderBy: { createdAt: "desc" }, take: 20 }),
    prisma.brandFact.findMany({ where: { workspaceId: workspace.id }, orderBy: { confidence: "desc" }, take: 20 }),
    prisma.contentAsset.findMany({ where: { workspaceId: workspace.id }, orderBy: { updatedAt: "desc" }, take: 20 }),
    prisma.workspaceApiToken.findMany({ where: { workspaceId: workspace.id }, orderBy: { createdAt: "desc" }, take: 20 }),
  ]);

  const latestQuestionSet = questionSets[0];
  const latestQuestions = Array.isArray(latestQuestionSet?.questions)
    ? (latestQuestionSet.questions as string[])
    : defaultQuestions;
  const doubaoSamples = answerSamples.filter((sample) => sample.platform === Platform.Doubao);
  const mentionRate =
    doubaoSamples.length === 0
      ? 0
      : Math.round((doubaoSamples.filter((sample) => sample.brandMentioned).length / doubaoSamples.length) * 100);

  return {
    workspace,
    agentSettings,
    analyticsConfigs,
    socialAccounts,
    questionSets,
    latestQuestions,
    answerSamples,
    reports,
    sourceAssets,
    brandFacts,
    contentAssets,
    apiTokens: apiTokens.map((token) => ({
      id: token.id,
      name: token.name,
      tokenPrefix: token.tokenPrefix,
      scopes: token.scopes,
      lastUsedAt: token.lastUsedAt,
      expiresAt: token.expiresAt,
      revokedAt: token.revokedAt,
      createdAt: token.createdAt,
    })),
    stats: {
      mentionRate,
      sampleCount: answerSamples.length,
      reportCount: reports.length,
      sourceCount: sourceAssets.length,
      brandFactCount: brandFacts.length,
      contentCount: contentAssets.length,
      configuredAnalytics: analyticsConfigs.filter((item) => item.status === "configured" || item.status === "active").length,
      visibleSocialAccounts: socialAccounts.filter((item) => item.isVisible).length,
    },
    runtimeConfig: {
      doubao: {
        configured: configured(process.env.DOUBAO_API_KEY),
        baseUrl: process.env.DOUBAO_BASE_URL ?? defaultDoubaoBaseUrl,
        model: process.env.DOUBAO_MODEL ?? defaultDoubaoModel,
      },
      planner: {
        configured: configured(process.env.AGENT_PLANNER_API_KEY) || configured(process.env.DOUBAO_API_KEY),
        dedicatedKey: configured(process.env.AGENT_PLANNER_API_KEY),
        baseUrl: process.env.AGENT_PLANNER_BASE_URL || process.env.DOUBAO_BASE_URL || defaultDoubaoBaseUrl,
        model: process.env.AGENT_PLANNER_MODEL || process.env.DOUBAO_MODEL || defaultDoubaoModel,
      },
    },
  };
}

export type WorkspaceState = Awaited<ReturnType<typeof getWorkspaceState>>;
