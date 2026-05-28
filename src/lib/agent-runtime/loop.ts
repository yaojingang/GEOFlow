import { agentReply } from "@/lib/agent";
import type { AgentToolRegistry } from "@/lib/agent-runtime/tool-registry";
import type { AgentEvent, AgentToolCall, AgentToolDecision, AgentToolResult, AgentTurnResult } from "@/lib/agent-runtime/types";
import { text } from "@/lib/agent-runtime/types";
import { planWorkspaceToolCalls } from "@/lib/agent-runtime/planner";
import type { WorkspaceState } from "@/lib/workspace-service";
import type { AgentContextSnapshot } from "@/lib/agent-runtime/session-store";

type RunWorkspaceAgentTurnOptions = {
  message: string;
  state: WorkspaceState;
  registry: AgentToolRegistry;
  beforeToolCall: (call: AgentToolCall) => AgentToolDecision | Promise<AgentToolDecision>;
  context?: AgentContextSnapshot;
};

export async function runWorkspaceAgentTurn(options: RunWorkspaceAgentTurnOptions): Promise<AgentTurnResult> {
  const events: AgentEvent[] = [];
  const toolResults: AgentToolResult[] = [];
  const emit = (event: AgentEvent) => events.push(event);
  const plan = await planWorkspaceToolCalls({
    message: options.message,
    state: options.state,
    tools: options.registry.definitions(),
    context: options.context,
  });
  const toolCalls = plan.toolCalls;

  emit({ type: "agent_start" });
  emit({ type: "turn_start", turn: 1 });
  emit({ type: "planner", source: plan.source, reason: plan.reason });
  emit({ type: "tool_plan", toolCalls });

  if (toolCalls.length === 0) {
      const reply = await agentReply(options.message, options.state, options.context);
    emit({ type: "message_update", delta: reply });
    emit({ type: "turn_end", turn: 1 });
    emit({ type: "agent_end" });
    return { reply, toolResults, events };
  }

  for (const call of toolCalls) {
    const decision = await options.beforeToolCall(call);
    emit({
      type: "tool_permission",
      toolCallId: call.id,
      toolName: call.name,
      action: decision.action,
      reason: decision.reason,
      code: decision.action === "block" ? decision.code : undefined,
    });

    if (decision.action === "block") {
      const blockedResult: AgentToolResult = {
        toolCallId: call.id,
        toolName: call.name,
        content: [text(decision.reason)],
        details: { blocked: true, code: decision.code },
        isError: true,
      };
      toolResults.push(blockedResult);
      emit({ type: "tool_execution_end", toolCallId: call.id, toolName: call.name, result: blockedResult, isError: true });
      emit({ type: "message_update", delta: decision.reason });
      emit({ type: "turn_end", turn: 1 });
      emit({ type: "agent_end" });
      return {
        reply: decision.reason,
        toolResults,
        events,
        needsAdminKey: decision.code === "needs_admin_key",
        needsConfirmation: decision.code === "needs_confirmation",
        action: call.name,
      };
    }

    const executableCall = decision.action === "rewrite" ? { ...call, arguments: decision.args } : call;
    emit({
      type: "tool_execution_start",
      toolCallId: executableCall.id,
      toolName: executableCall.name,
      args: executableCall.arguments,
    });

    try {
      const result = await options.registry.execute(executableCall.name, executableCall.arguments);
      const toolResult: AgentToolResult = {
        toolCallId: executableCall.id,
        toolName: executableCall.name,
        content: result.content,
        details: result.details,
        isError: false,
      };
      toolResults.push(toolResult);
      emit({
        type: "tool_execution_end",
        toolCallId: executableCall.id,
        toolName: executableCall.name,
        result: toolResult,
        isError: false,
      });
    } catch (error) {
      const toolResult: AgentToolResult = {
        toolCallId: executableCall.id,
        toolName: executableCall.name,
        content: [text(error instanceof Error ? error.message : String(error))],
        isError: true,
      };
      toolResults.push(toolResult);
      emit({
        type: "tool_execution_end",
        toolCallId: executableCall.id,
        toolName: executableCall.name,
        result: toolResult,
        isError: true,
      });
    }
  }

  const successful = toolResults.filter((result) => !result.isError);
  const failed = toolResults.filter((result) => result.isError);
  const baseReply =
    failed.length > 0
      ? `我执行了 ${successful.length} 个工具，但有 ${failed.length} 个工具失败：${failed
          .flatMap((result) => result.content.map((item) => item.text))
          .join("；")}`
      : successful.flatMap((result) => result.content.map((item) => item.text)).join("\n");
  const contextLine = summarizeContext(options.context);
  const reply = contextLine ? `${baseReply}\n\n${contextLine}` : baseReply;

  emit({ type: "message_update", delta: reply });
  emit({ type: "turn_end", turn: 1 });
  emit({ type: "agent_end" });

  return {
    reply,
    toolResults,
    events,
  };
}

function summarizeContext(context?: AgentContextSnapshot) {
  if (!context || (!context.summary && context.recent.length === 0)) {
    return "";
  }

  return `已参考当前会话路径：${context.summary ? "包含压缩摘要，" : ""}最近 ${context.recent.length} 个节点。`;
}
