import type { AgentContextSnapshot } from "@/lib/agent-runtime/session-store";
import type { AgentToolCall, AgentToolDefinition } from "@/lib/agent-runtime/types";
import type { WorkspaceState } from "@/lib/workspace-service";

type PlannerResult = {
  source: "model" | "rules";
  reason?: string;
  toolCalls: AgentToolCall[];
};

type PlannerResponse = {
  choices?: Array<{
    message?: {
      content?: string;
    };
  }>;
};

function createCall(name: string, args: Record<string, unknown> = {}): AgentToolCall {
  return {
    type: "toolCall",
    id: `call_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
    name,
    arguments: args,
  };
}

function contentTypeFromMessage(message: string) {
  if (/对比/.test(message)) return "对比页";
  if (/品牌事实|事实页/.test(message)) return "品牌事实页";
  if (/案例/.test(message)) return "案例页";
  if (/社媒|小红书|抖音|短内容|视频号/.test(message)) return "社媒短内容";
  return "FAQ";
}

function outcomeFromMessage(message: string) {
  if (/没效果|无效|没有提升|没提升|失败|did.?not|not work/i.test(message)) return "did_not_work";
  if (/部分|一点|有点|改善|partial/i.test(message)) return "partial";
  return "worked";
}

export async function planWorkspaceToolCalls({
  message,
  state,
  tools,
  context,
}: {
  message: string;
  state: WorkspaceState;
  tools: AgentToolDefinition[];
  context?: AgentContextSnapshot;
}): Promise<PlannerResult> {
  const modelResult = await tryModelPlanner({ message, state, tools, context });
  if (modelResult) {
    return modelResult;
  }

  return {
    source: "rules",
    reason: "未配置可用 planner key，或模型规划失败，已使用规则规划。",
    toolCalls: planWithRules(message),
  };
}

function planWithRules(message: string): AgentToolCall[] {
  const normalized = message.toLowerCase();
  const calls: AgentToolCall[] = [];

  if (/采样|运行豆包|doubao|监测/.test(normalized)) {
    calls.push(createCall("run_doubao_sampling", { limit: 5 }));
  }

  if (/报告|report|诊断/.test(normalized)) {
    calls.push(createCall("generate_report"));
  }

  if (/内容|草稿|faq|对比|案例|社媒|小红书|抖音|短内容|事实页/.test(normalized)) {
    calls.push(createCall("create_content_draft", { type: contentTypeFromMessage(message) }));
  }

  if (/经验|lesson|沉淀|记住|有效|没效果|无效|部分改善|提升了|有提升/.test(normalized)) {
    if (/沉淀|记住|有效|没效果|无效|部分改善|提升了|有提升/.test(normalized)) {
      calls.push(
        createCall("write_geo_lesson", {
          title: "用户反馈的 GEO 优化经验",
          tactic: message.slice(0, 180),
          scenario: "geo.youngtuo.win 豆包可见度优化",
          outcome: outcomeFromMessage(message),
          notes: "由 Agent 对话中用户明确反馈触发。",
        }),
      );
    } else {
      calls.push(createCall("search_geo_lessons", { query: message.slice(0, 120) }));
    }
  }

  if (/研究中心|研究节点|research|公开研究|知识节点|双链|反向链接/.test(normalized)) {
    if (/写入|新增|创建|发布|沉淀|记录/.test(normalized)) {
      calls.push(
        createCall("write_research_note", {
          title: "豆包研究节点",
          body: `# 豆包研究节点\n\n${message.slice(0, 900)}`,
          type: "研究笔记",
          tags: "豆包,GEO,研究中心",
          status: /公开|发布/.test(normalized) ? "published" : "draft",
        }),
      );
    } else {
      calls.push(createCall("search_research_notes", { query: message.slice(0, 120) }));
    }
  }

  return calls;
}

async function tryModelPlanner({
  message,
  state,
  tools,
  context,
}: {
  message: string;
  state: WorkspaceState;
  tools: AgentToolDefinition[];
  context?: AgentContextSnapshot;
}): Promise<PlannerResult | null> {
  const apiKey = process.env.AGENT_PLANNER_API_KEY || process.env.DOUBAO_API_KEY;
  if (!apiKey) {
    return null;
  }

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
        temperature: 0,
        response_format: { type: "json_object" },
        messages: [
          {
            role: "system",
            content:
              "你是 geo.youngtuo.win 的 Agent 工具规划器。只能输出 JSON，不要解释。根据用户意图和项目上下文决定是否调用工具。输出格式：{\"toolCalls\":[{\"name\":\"工具名\",\"arguments\":{}}],\"reason\":\"一句话原因\"}。如果只需要回答，不调用工具，toolCalls 输出空数组。",
          },
          {
            role: "user",
            content: JSON.stringify({
              userMessage: message,
              workspace: {
                name: state.workspace.name,
                domain: state.workspace.domain,
                stats: state.stats,
                latestQuestions: state.latestQuestions.slice(0, 5),
              },
              context: {
                summary: context?.summary || "",
                recent: context?.recent.slice(-6) || [],
              },
              tools: tools.map((tool) => ({
                name: tool.name,
                description: tool.description,
                parameters: tool.parameters,
              })),
              rules: [
                "只有用户明确要求采样、监测、生成报告或生成内容时才调用工具。",
                "不要调用未列出的工具。",
                "采样数量默认 5，最多 12。",
                "内容类型只能使用 FAQ、对比页、品牌事实页、案例页、社媒短内容。",
                "只有用户明确反馈某个动作有效、无效或部分有效时，才调用 write_geo_lesson。",
                "用户只是问经验或参考时，优先调用 search_geo_lessons，不要写入。",
                "confirm_geo_lesson 需要用户提供明确 lesson id。",
                "用户询问豆包研究中心、研究节点或公开知识库时，优先调用 search_research_notes。",
                "只有用户明确要求把结论写入或发布到豆包研究中心时，才调用 write_research_note。",
                "link_research_notes 需要用户提供明确的两个研究节点 ID。",
              ],
            }),
          },
        ],
      }),
    });

    if (!response.ok) {
      return null;
    }

    const data = (await response.json()) as PlannerResponse;
    const parsed = parsePlannerJson(data.choices?.[0]?.message?.content ?? "", tools);
    if (!parsed) {
      return null;
    }

    return {
      source: "model",
      reason: parsed.reason,
      toolCalls: parsed.toolCalls,
    };
  } catch {
    return null;
  }
}

function parsePlannerJson(content: string, tools: AgentToolDefinition[]) {
  const names = new Set(tools.map((tool) => tool.name));

  try {
    const parsed = JSON.parse(content) as {
      toolCalls?: Array<{ name?: unknown; arguments?: unknown }>;
      reason?: unknown;
    };
    const toolCalls = Array.isArray(parsed.toolCalls)
      ? parsed.toolCalls
          .filter((call) => typeof call.name === "string" && names.has(call.name))
          .map((call) => createCall(call.name as string, normalizeArguments(call.arguments)))
      : [];

    return {
      reason: typeof parsed.reason === "string" ? parsed.reason.slice(0, 160) : undefined,
      toolCalls,
    };
  } catch {
    return null;
  }
}

function normalizeArguments(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    return {};
  }

  return value as Record<string, unknown>;
}
