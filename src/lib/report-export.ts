import type { Prisma } from "@prisma/client";

export type PublicReport = Prisma.ReportGetPayload<{
  include: {
    workspace: {
      include: {
        answerSamples: true;
        brandFacts: true;
        sourceAssets: true;
        questionSets: true;
        analyticsConfigs: true;
        socialAccounts: true;
      };
    };
  };
}>;

export function getReportStats(report: PublicReport) {
  const samples = report.workspace.answerSamples;
  const mentionRate =
    samples.length === 0
      ? 0
      : Math.round((samples.filter((sample) => sample.brandMentioned).length / samples.length) * 100);

  return {
    mentionRate,
    sampleCount: samples.length,
    sourceCount: report.workspace.sourceAssets.length,
    factCount: report.workspace.brandFacts.length,
    questionSetCount: report.workspace.questionSets.length,
  };
}

export function buildReportMarkdown(report: PublicReport) {
  const stats = getReportStats(report);
  const verification = report.verification as
    | {
        summary?: string;
        checks?: Array<{ label: string; status: string; detail: string }>;
      }
    | null;
  const configuredAnalytics = report.workspace.analyticsConfigs.filter((item) => item.status === "configured" || item.status === "active");
  const visibleSocials = report.workspace.socialAccounts.filter((item) => item.isVisible && (item.url || item.handle));
  const reportUrl = report.publicSlug ? `https://geo.youngtuo.win/reports/${report.publicSlug}` : "未生成公开链接";
  const nextActions = [
    stats.sourceCount < 3 ? "补齐官网、案例、FAQ 三类资料，并重新处理资料库。" : "继续补充高质量案例和客户问题，扩大证据覆盖面。",
    stats.sampleCount < 10 ? "再运行至少 3 条真实豆包采样，形成更稳定的趋势基线。" : "按 Day 7/14/30 计划复测，观察提及率和错误事实变化。",
    configuredAnalytics.some((item) => item.provider === "Search Console")
      ? "保留 Search Console 与统计数据，后续用于收录和归因复盘。"
      : "补 Search Console 并提交 sitemap.xml，让报告具备搜索收录依据。",
    visibleSocials.length >= 3 ? "用现有社媒入口承接咨询和案例分发。" : "至少补 3 个客户可见社媒入口，方便从报告进入账号。",
  ];
  const lines = [
    `# ${report.title}`,
    "",
    `- 项目：${report.workspace.name}`,
    `- 域名：${report.workspace.domain ?? "未配置"}`,
    `- 状态：${report.status}`,
    `- 生成时间：${report.createdAt.toISOString().slice(0, 10)}`,
    "",
    "## 摘要",
    "",
    report.summary,
    "",
    "## 核心指标",
    "",
    `- 豆包提及率：${stats.mentionRate}%`,
    `- 采样记录：${stats.sampleCount}`,
    `- 资料数量：${stats.sourceCount}`,
    `- 品牌事实：${stats.factCount}`,
    `- 问题集版本：${stats.questionSetCount}`,
    "",
    "## 执行结论",
    "",
    ...nextActions.map((action, index) => `${index + 1}. ${action}`),
    "",
    "## 交付清单",
    "",
    `- 公开报告：${reportUrl}`,
    `- Markdown：${report.publicSlug ? `/api/reports/${report.publicSlug}/markdown` : "未生成公开链接"}`,
    `- 分析工具：${configuredAnalytics.length}/${report.workspace.analyticsConfigs.length}，${configuredAnalytics.map((item) => item.provider).join("、") || "待配置"}`,
    `- 社媒入口：${visibleSocials.length}/${report.workspace.socialAccounts.length}，${visibleSocials.map((item) => item.platform).join("、") || "待配置"}`,
    "",
    "## 证据链检查",
    "",
    `- 状态：${report.verificationStatus}`,
    `- 结论：${report.verificationSummary ?? verification?.summary ?? "尚未检查"}`,
    "",
    ...(verification?.checks ?? []).map((check) => `- ${check.label}：${check.status}，${check.detail}`),
    "",
    "## 品牌事实",
    "",
    ...report.workspace.brandFacts.map((fact) => `- **${fact.title}** (${fact.confidence})：${fact.body}`),
    "",
    "## 最近采样",
    "",
    ...report.workspace.answerSamples.slice(0, 10).map((sample) => [
      `### ${sample.question}`,
      "",
      `- 平台：${sample.platform}`,
      `- 品牌提及：${sample.brandMentioned ? "是" : "否"}`,
      "",
      sample.answer,
      "",
    ].join("\n")),
    "## 下一步",
    "",
    ...nextActions.map((action) => `- ${action}`),
  ];

  return lines.join("\n");
}
