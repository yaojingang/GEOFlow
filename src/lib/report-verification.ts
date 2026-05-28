import type { WorkspaceState } from "@/lib/workspace-service";

type EvidenceCheck = {
  key: string;
  label: string;
  status: "pass" | "warn" | "fail";
  detail: string;
};

export function verifyReportEvidence(state: WorkspaceState) {
  const processedSources = state.sourceAssets.filter((source) => source.status === "processed");
  const routedSources = processedSources.filter((source) => source.routing);
  const evidenceFacts = state.brandFacts.filter((fact) => fact.evidenceUrl);
  const samples = state.answerSamples;
  const configuredAnalytics = state.analyticsConfigs.filter((item) => item.status === "configured" || item.status === "active");
  const visibleSocials = state.socialAccounts.filter((item) => item.isVisible && (item.url || item.handle));

  const checks: EvidenceCheck[] = [
    {
      key: "processed_sources",
      label: "资料已处理",
      status: processedSources.length > 0 ? "pass" : "fail",
      detail:
        processedSources.length > 0
          ? `已处理 ${processedSources.length} 条资料。`
          : "还没有处理完成的资料，报告缺少可追溯来源。",
    },
    {
      key: "source_routing",
      label: "资料路由",
      status: routedSources.length > 0 ? "pass" : "warn",
      detail:
        routedSources.length > 0
          ? `已有 ${routedSources.length} 条资料带路由，可用于后续 RAG/报告证据。`
          : "资料还没有路由信息，建议重新处理资料。",
    },
    {
      key: "evidence_facts",
      label: "品牌事实证据",
      status: evidenceFacts.length >= 2 ? "pass" : evidenceFacts.length > 0 ? "warn" : "fail",
      detail:
        evidenceFacts.length > 0
          ? `已有 ${evidenceFacts.length} 条品牌事实带 evidenceUrl。`
          : "品牌事实缺少 evidenceUrl，客户报告说服力不足。",
    },
    {
      key: "doubao_samples",
      label: "豆包采样",
      status: samples.length >= 3 ? "pass" : samples.length > 0 ? "warn" : "fail",
      detail:
        samples.length > 0
          ? `已有 ${samples.length} 条豆包/AI 答案采样。`
          : "还没有采样，无法判断豆包提及率和竞品命中。",
    },
    {
      key: "analytics",
      label: "分析工具",
      status: configuredAnalytics.length > 0 ? "pass" : "warn",
      detail:
        configuredAnalytics.length > 0
          ? `已配置/启用 ${configuredAnalytics.length} 个分析工具。`
          : "GA4、Search Console 或百度统计尚未配置，归因数据不足。",
    },
    {
      key: "social_accounts",
      label: "社交账号",
      status: visibleSocials.length > 0 ? "pass" : "warn",
      detail:
        visibleSocials.length > 0
          ? `已有 ${visibleSocials.length} 个可展示社交账号。`
          : "客户展示端缺少社交账号入口。",
    },
  ];

  const failCount = checks.filter((check) => check.status === "fail").length;
  const warnCount = checks.filter((check) => check.status === "warn").length;
  const status = failCount > 0 ? "needs-evidence" : warnCount > 0 ? "verified-with-warnings" : "verified";
  const summary =
    status === "verified"
      ? "证据链完整：资料、品牌事实、采样和配置均可支撑客户报告。"
      : status === "verified-with-warnings"
        ? `证据链可用但有 ${warnCount} 项待补强，建议交付前补齐。`
        : `证据链不足：${failCount} 项关键证据缺失，建议先补资料/事实/采样再对客户交付。`;

  return {
    status,
    summary,
    checks,
    counts: {
      processedSources: processedSources.length,
      routedSources: routedSources.length,
      evidenceFacts: evidenceFacts.length,
      samples: samples.length,
      configuredAnalytics: configuredAnalytics.length,
      visibleSocials: visibleSocials.length,
    },
  };
}
