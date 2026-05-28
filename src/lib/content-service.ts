import { prisma } from "@/lib/prisma";
import { getWorkspaceState } from "@/lib/workspace-service";

function sampleList(items: string[]) {
  return items.length > 0 ? items.map((item) => `- ${item}`).join("\n") : "- 暂无";
}

export async function generateContentDraft(type: string, imageId?: string | null) {
  const state = await getWorkspaceState();
  const mainQuestion = state.latestQuestions[0] ?? "客户如何提升豆包里的品牌可见度？";
  const facts = state.brandFacts.slice(0, 5).map((fact) => `${fact.title}：${fact.body}`);
  const sources = state.sourceAssets.slice(0, 5).map((source) => `${source.title}${source.url ? ` (${source.url})` : ""}`);
  const imageAssets = state.sourceAssets.filter((source) => (source.kind === "image" || source.mimeType?.startsWith("image/")) && source.url);
  const heroImage = (imageId ? imageAssets.find((source) => source.id === imageId) : null) ?? imageAssets[0];
  const gaps = state.answerSamples
    .filter((sample) => !sample.brandMentioned)
    .slice(0, 5)
    .map((sample) => sample.question);

  const titleMap: Record<string, string> = {
    FAQ: `豆包 GEO 常见问题：${mainQuestion}`,
    "对比页": "GEO 服务和传统 SEO 有什么区别？",
    "品牌事实页": "geo.youngtuo.win 如何提升豆包答案可见度",
    "案例页": "从资料到豆包采样的 GEO 项目流程案例",
    "社媒短内容": "豆包 GEO 服务短内容包",
  };

  const title = titleMap[type] ?? `${type}：${mainQuestion}`;
  const body = [
    `# ${title}`,
    "",
    ...(heroImage?.url ? [`![${heroImage.title}](${heroImage.url})`, ""] : []),
    "## 目标问题",
    "",
    mainQuestion,
    "",
    "## 建议回答",
    "",
    "当客户想提升豆包里的品牌可见度时，第一步不是直接堆文章，而是先把品牌事实、证据来源、客户问题和采样口径整理清楚。geo.youngtuo.win 的工作台会把资料、事实、问题、采样、报告和发布拆开，让客户每一步都知道要做什么，也知道系统会得到什么。",
    "",
    "## 可引用事实",
    "",
    sampleList(facts),
    "",
    "## 证据来源",
    "",
    sampleList(sources),
    "",
    "## 当前内容缺口",
    "",
    sampleList(gaps.length > 0 ? gaps : ["补齐 FAQ、对比页、案例证据和 Search Console / 统计配置。"]),
    "",
    "## 发布建议",
    "",
    "- 先发布 FAQ 和品牌事实页，解决豆包答案缺证据的问题。",
    "- 再发布对比页和案例页，让推荐场景里有可引用材料。",
    ...(heroImage?.url ? ["- 发布前复核首图是否适合当前渠道，不适合时从图片库替换。"] : []),
    "- 发布后 7/14/30 天复测豆包提及率和错误事实。",
  ].join("\n");

  return {
    type,
    title,
    body,
    targetGap: gaps[0] ?? "补齐 FAQ、对比页、案例证据和 Search Console / 统计配置。",
  };
}

export async function createGeneratedContent(type: string, imageId?: string | null) {
  const state = await getWorkspaceState();
  const draft = await generateContentDraft(type, imageId);

  return prisma.contentAsset.create({
    data: {
      workspaceId: state.workspace.id,
      type: draft.type,
      title: draft.title,
      body: draft.body,
      targetGap: draft.targetGap,
      status: "draft",
    },
  });
}
