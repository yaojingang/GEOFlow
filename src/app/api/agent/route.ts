import { NextResponse } from "next/server";
import { z } from "zod";
import { canUseAdminKey } from "@/lib/admin-auth";
import type { AgentToolCall, AgentToolDecision } from "@/lib/agent-runtime/types";
import { runWorkspaceAgentTurn } from "@/lib/agent-runtime/loop";
import { buildAgentContext, getAgentSessionSnapshot, recordAgentTurn, resolveAgentBranchParent } from "@/lib/agent-runtime/session-store";
import { createWorkspaceToolRegistry } from "@/lib/agent-runtime/workspace-tools";
import { getWorkspaceState } from "@/lib/workspace-service";

const RequestSchema = z.object({
  message: z.string().min(1).max(1000),
  confirmed: z.boolean().default(false),
  parentEntryId: z.string().min(1).nullable().optional(),
});

export async function GET() {
  const state = await getWorkspaceState();
  const registry = createWorkspaceToolRegistry();
  const session = await getAgentSessionSnapshot(state.workspace.id);

  return NextResponse.json({
    mode: state.agentSettings?.mode ?? "Explain",
    canMutateProject: state.agentSettings?.mode === "Control",
    tools: registry.definitions(),
    session,
  });
}

export async function POST(request: Request) {
  const payload = RequestSchema.safeParse(await request.json());

  if (!payload.success) {
    return NextResponse.json({ error: "Invalid message" }, { status: 422 });
  }

  const state = await getWorkspaceState();
  const hasAdminKey = canUseAdminKey(request);
  const registry = createWorkspaceToolRegistry();
  let parentEntryId: string | null = null;

  try {
    parentEntryId = await resolveAgentBranchParent(state.workspace.id, payload.data.parentEntryId);
  } catch {
    return NextResponse.json({ error: "Selected session entry does not exist" }, { status: 400 });
  }
  const context = await buildAgentContext(state.workspace.id, parentEntryId);

  const result = await runWorkspaceAgentTurn({
    message: payload.data.message,
    state,
    registry,
    context,
    beforeToolCall: (call) =>
      decideWorkspaceToolCall({
        call,
        hasAdminKey,
        confirmed: payload.data.confirmed,
        state,
      }),
  });
  const session = await recordAgentTurn({
    workspaceId: state.workspace.id,
    userMessage: payload.data.message,
    reply: result.reply,
    events: result.events,
    toolResults: result.toolResults,
    parentEntryId,
  });

  return NextResponse.json(
    {
      mode: state.agentSettings?.mode ?? "Explain",
      canMutateProject: state.agentSettings?.mode === "Control",
      tools: registry.definitions(),
      session,
      ...result,
    },
    { status: result.needsAdminKey ? 401 : 200 },
  );
}

function decideWorkspaceToolCall({
  call,
  hasAdminKey,
  confirmed,
  state,
}: {
  call: AgentToolCall;
  hasAdminKey: boolean;
  confirmed: boolean;
  state: Awaited<ReturnType<typeof getWorkspaceState>>;
}): AgentToolDecision {
  if (state.agentSettings?.mode !== "Control") {
    return {
      action: "block",
      code: "control_disabled",
      reason: "我可以讲解这一步，但当前不是控制模式，不会直接修改项目。请到设置页开启 Agent 控制模式和对应权限。",
    };
  }

  if (!hasAdminKey) {
    return {
      action: "block",
      code: "needs_admin_key",
      reason: "我可以解释这个动作，但要真正执行，需要先在设置页输入管理员控制 Key。",
    };
  }

  const permission = permissionForTool(call.name);
  if (permission && !state.agentSettings?.[permission]) {
    return {
      action: "block",
      code: "permission_disabled",
      reason: `控制模式已开启，但还没有授权「${labelForPermission(permission)}」。请到设置页勾选后再让我执行。`,
    };
  }

  if (state.agentSettings?.requireConfirmation && !confirmed) {
    return {
      action: "block",
      code: "needs_confirmation",
      reason: `我已准备执行 ${labelForTool(call.name)}。这个动作会写入项目数据；请在操作面板点确认运行。`,
    };
  }

  return { action: "allow" };
}

function permissionForTool(name: string) {
  const map = {
    run_doubao_sampling: "canRunDoubaoSampling",
    generate_report: "canGenerateReports",
    create_content_draft: "canCreateContent",
    search_geo_lessons: null,
    write_geo_lesson: "canGenerateReports",
    confirm_geo_lesson: "canGenerateReports",
    search_research_notes: null,
    write_research_note: "canManageResearch",
    link_research_notes: "canManageResearch",
  } as const;

  return map[name as keyof typeof map];
}

function labelForPermission(permission: NonNullable<ReturnType<typeof permissionForTool>>) {
  const map = {
    canRunDoubaoSampling: "运行豆包采样",
    canGenerateReports: "生成报告",
    canCreateContent: "生成内容草稿",
    canManageResearch: "管理豆包研究中心",
  };

  return map[permission];
}

function labelForTool(name: string) {
  const map: Record<string, string> = {
    run_doubao_sampling: "豆包采样",
    generate_report: "诊断报告生成",
    create_content_draft: "内容草稿生成",
    search_geo_lessons: "历史 GEO 经验检索",
    write_geo_lesson: "GEO 经验沉淀",
    confirm_geo_lesson: "GEO 经验验证更新",
    search_research_notes: "豆包研究节点检索",
    write_research_note: "豆包研究节点写入",
    link_research_notes: "豆包研究节点关联",
  };

  return map[name] ?? name;
}
