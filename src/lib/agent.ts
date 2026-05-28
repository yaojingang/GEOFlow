import type { WorkspaceState } from "@/lib/workspace-service";
import type { AgentContextSnapshot } from "@/lib/agent-runtime/session-store";
import { assembleWorkspacePrompt } from "@/lib/agent-runtime/prompt-assembler";

export type AgentIntent = "explain" | "suggest" | "control-request";

export function classifyAgentIntent(message: string): AgentIntent {
  const normalized = message.toLowerCase();
  const controlWords = ["发布", "删除", "运行", "采样", "改配置", "生成第一批", "publish", "delete", "run"];
  if (controlWords.some((word) => normalized.includes(word))) {
    return "control-request";
  }

  const suggestWords = ["下一步", "建议", "优化", "怎么做", "生成什么", "why", "how"];
  if (suggestWords.some((word) => normalized.includes(word))) {
    return "suggest";
  }

  return "explain";
}

export async function agentReply(message: string, state?: WorkspaceState, context?: AgentContextSnapshot): Promise<string> {
  const intent = classifyAgentIntent(message);
  const stats = state?.stats;
  const contextLine = summarizeContext(context);
  const promptContext = state ? await assembleWorkspacePrompt({ message, state, context }) : "";
  const promptLine = promptContext ? summarizePromptContext(promptContext) : "";

  if (intent === "control-request") {
    if (state?.agentSettings?.mode === "Control") {
      return withContext(
        "我可以执行已授权的动作。当前支持：运行豆包采样、生成诊断报告。危险操作仍会按设置要求二次确认。",
        [contextLine, promptLine].filter(Boolean).join("\n"),
      );
    }

    return withContext(
      "我可以帮你准备这个动作，但当前不是控制模式，不会直接修改项目。请到「设置 → Agent 控制权限」开启对应权限；开启后我会列出将执行的任务，并要求你二次确认。",
      [contextLine, promptLine].filter(Boolean).join("\n"),
    );
  }

  if (intent === "suggest") {
    return withContext(
      `建议先补齐资料库和品牌事实，再运行豆包采样。当前采样记录 ${stats?.sampleCount ?? 0} 条，报告 ${stats?.reportCount ?? 0} 份，分析配置完成 ${stats?.configuredAnalytics ?? 0} 项。`,
      [contextLine, promptLine].filter(Boolean).join("\n"),
    );
  }

  return withContext(
    `我理解这个项目的资料、豆包问题集、品牌事实、内容资产、报告和配置状态。当前豆包提及率 ${stats?.mentionRate ?? 0}%，采样记录 ${stats?.sampleCount ?? 0} 条。你可以问我下一步，或在控制模式下让我运行采样/生成报告。`,
    [contextLine, promptLine].filter(Boolean).join("\n"),
  );
}

function summarizeContext(context?: AgentContextSnapshot) {
  if (!context || (!context.summary && context.recent.length === 0)) {
    return "";
  }

  const recent = context.recent
    .filter((entry) => entry.role === "user" || entry.role === "assistant")
    .slice(-3)
    .map((entry) => `${entry.role}: ${entry.label}`)
    .join(" / ");
  const summary = context.summary ? "已有压缩摘要" : "无压缩摘要";

  return `我已参考当前会话路径（${summary}${recent ? `；最近节点：${recent}` : ""}）。`;
}

function withContext(reply: string, contextLine: string) {
  return contextLine ? `${reply}\n\n${contextLine}` : reply;
}

function summarizePromptContext(promptContext: string) {
  const hasLessons = promptContext.includes("## GeoLessons");
  const hasEvidence = promptContext.includes("## Evidence");
  return `已加载项目 prompt sections：Role / Workspace${hasEvidence ? " / Evidence" : ""}${hasLessons ? " / GeoLessons" : ""}。`;
}
