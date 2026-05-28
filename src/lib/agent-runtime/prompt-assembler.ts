import { summarizeGeoLessonsForPrompt } from "@/lib/geo-lesson-service";
import type { AgentContextSnapshot } from "@/lib/agent-runtime/session-store";
import type { WorkspaceState } from "@/lib/workspace-service";

type PromptSection = {
  name: string;
  priority: number;
  content: string;
};

export async function assembleWorkspacePrompt({
  message,
  state,
  context,
}: {
  message: string;
  state: WorkspaceState;
  context?: AgentContextSnapshot;
}) {
  const sections: PromptSection[] = [
    {
      name: "Role",
      priority: 1,
      content: "你是 geo.youngtuo.win 的项目 Agent，目标是让客户按步骤提升豆包答案可见度。",
    },
    {
      name: "Workspace",
      priority: 2,
      content: [
        `项目：${state.workspace.name}`,
        `域名：${state.workspace.domain ?? "未配置"}`,
        `行业：${state.workspace.industry}`,
        `市场：${state.workspace.market}`,
        `豆包提及率：${state.stats.mentionRate}%`,
        `采样：${state.stats.sampleCount}，报告：${state.stats.reportCount}，资料：${state.stats.sourceCount}，事实：${state.stats.brandFactCount}`,
      ].join("\n"),
    },
    {
      name: "Evidence",
      priority: 3,
      content: [
        "证据链：",
        `- 已处理资料：${state.sourceAssets.filter((item) => item.status === "processed").length}`,
        `- 已路由资料：${state.sourceAssets.filter((item) => item.routing).length}`,
        `- 带证据 URL 的品牌事实：${state.brandFacts.filter((item) => item.evidenceUrl).length}`,
        `- 最新报告证据状态：${state.reports[0]?.verificationStatus ?? "unchecked"}`,
      ].join("\n"),
    },
    {
      name: "ActiveSkills",
      priority: 4,
      content: [
        "当前可调用能力：",
        "- run_doubao_sampling：运行豆包采样",
        "- generate_report：生成并校验证据链报告",
        "- create_content_draft：生成 FAQ/对比页/案例/社媒草稿",
        "- search_geo_lessons：查找历史 GEO 经验",
        "- write_geo_lesson：只在用户明确反馈有效/无效后沉淀经验",
        "- confirm_geo_lesson：更新已有经验的验证结果",
      ].join("\n"),
    },
  ];

  const lessonSummary = await summarizeGeoLessonsForPrompt(message);
  if (lessonSummary) {
    sections.push({
      name: "GeoLessons",
      priority: 5,
      content: `历史 GEO 经验：\n${lessonSummary}`,
    });
  }

  if (context?.summary || context?.recent.length) {
    sections.push({
      name: "SessionContext",
      priority: 6,
      content: [
        context.summary ? `压缩摘要：${context.summary}` : "",
        context.recent.length
          ? `最近节点：\n${context.recent
              .slice(-6)
              .map((entry) => `- ${entry.role ?? entry.type}: ${entry.label}`)
              .join("\n")}`
          : "",
      ]
        .filter(Boolean)
        .join("\n"),
    });
  }

  return sections
    .sort((a, b) => a.priority - b.priority)
    .map((section) => `## ${section.name}\n${section.content}`)
    .join("\n\n---\n\n");
}
