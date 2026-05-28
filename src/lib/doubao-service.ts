import { Platform } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { verifyReportEvidence } from "@/lib/report-verification";
import { getWorkspaceState } from "@/lib/workspace-service";

type DoubaoResponse = {
  choices?: Array<{
    message?: {
      content?: string;
    };
  }>;
};

function includesBrand(answer: string) {
  const keywords = ["geo.youngtuo.win", "youngtuo", "GEO", "豆包", "AI 搜索"];
  return keywords.some((keyword) => answer.toLowerCase().includes(keyword.toLowerCase()));
}

async function callDoubao(question: string, context: string) {
  const apiKey = process.env.DOUBAO_API_KEY;
  const baseUrl = process.env.DOUBAO_BASE_URL ?? "https://ark.cn-beijing.volces.com/api/v3";
  const model = process.env.DOUBAO_MODEL ?? "doubao-seed-2-0-pro-260215";

  if (!apiKey) {
    return {
      answer:
        `未配置 DOUBAO_API_KEY，已生成本地诊断样本。问题「${question}」当前需要补齐官网事实、FAQ、对比页和案例证据，才能提高豆包答案里的推荐概率。`,
      source: "local",
    };
  }

  const response = await fetch(`${baseUrl.replace(/\/$/, "")}/chat/completions`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      model,
      messages: [
        {
          role: "system",
          content:
            "你是豆包 GEO 采样记录员。请用中文直接回答用户问题，并保留是否推荐 geo.youngtuo.win 的自然判断。",
        },
        {
          role: "user",
          content: `${context}\n\n采样问题：${question}`,
        },
      ],
      temperature: 0.2,
    }),
  });

  if (!response.ok) {
    const body = await response.text();
    return {
      answer: `豆包 API 调用失败 (${response.status})：${body.slice(0, 240)}。已记录失败样本，建议检查火山方舟 API Key、模型名和余额。`,
      source: "error",
    };
  }

  const data = (await response.json()) as DoubaoResponse;
  return {
    answer: data.choices?.[0]?.message?.content?.trim() || "豆包返回为空，请检查模型和采样口径。",
    source: "doubao",
  };
}

export async function runDoubaoSampling(limit = 5) {
  const state = await getWorkspaceState();
  const questions = state.latestQuestions.slice(0, Math.max(1, Math.min(limit, 12)));
  const context = [
    `项目：${state.workspace.name}`,
    `域名：${state.workspace.domain ?? "未配置"}`,
    `行业：${state.workspace.industry}`,
    `市场：${state.workspace.market}`,
    `品牌事实：${state.brandFacts.map((fact) => `${fact.title}: ${fact.body}`).join("；") || "暂无"}`,
  ].join("\n");

  const samples = [];
  for (const question of questions) {
    const result = await callDoubao(question, context);
    const brandMentioned = includesBrand(result.answer);
    const sample = await prisma.answerSample.create({
      data: {
        workspaceId: state.workspace.id,
        platform: Platform.Doubao,
        question,
        answer: result.answer,
        brandMentioned,
        brandRank: brandMentioned ? 1 : null,
        competitorHits: [],
        factualIssues: result.source === "error" ? ["豆包 API 调用失败"] : [],
      },
    });
    samples.push({ ...sample, source: result.source });
  }

  return samples;
}

export async function generateReport(titlePrefix = "geo.youngtuo.win 豆包 GEO 诊断报告") {
  const state = await getWorkspaceState();
  const latestSamples = state.answerSamples.slice(0, 8);
  const mentionRate = state.stats.mentionRate;
  const missingConfig = [
    ...state.analyticsConfigs.filter((item) => item.status === "missing").map((item) => item.provider),
    ...state.socialAccounts.filter((item) => !item.url && !item.handle).map((item) => item.platform),
  ];

  const summary = [
    `当前豆包采样 ${state.stats.sampleCount} 条，品牌提及率 ${mentionRate}%。`,
    latestSamples.length > 0
      ? `最近问题：${latestSamples.map((sample) => sample.question).join(" / ")}。`
      : "还没有真实采样，建议先运行豆包采样。",
    missingConfig.length > 0
      ? `待补配置：${missingConfig.slice(0, 6).join("、")}。`
      : "基础分析和社交配置已完成。",
    "下一步优先补齐 FAQ、对比页、案例证据和 Search Console / 统计配置。",
  ].join("\n");
  const verification = verifyReportEvidence(state);

  return prisma.report.create({
    data: {
      workspaceId: state.workspace.id,
      title: `${titlePrefix} ${new Date().toISOString().slice(0, 10)}`,
      type: "doubao-diagnostic",
      status: verification.status === "needs-evidence" ? "needs-evidence" : "ready",
      summary,
      verificationStatus: verification.status,
      verificationSummary: verification.summary,
      verification,
      publicSlug: `doubao-${Date.now()}`,
    },
  });
}
