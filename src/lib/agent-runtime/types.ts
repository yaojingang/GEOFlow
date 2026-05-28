export type AgentTextContent = {
  type: "text";
  text: string;
};

export type AgentToolCall = {
  type: "toolCall";
  id: string;
  name: string;
  arguments: Record<string, unknown>;
};

export type AgentToolResult = {
  toolCallId: string;
  toolName: string;
  content: AgentTextContent[];
  details?: unknown;
  isError: boolean;
};

export type AgentToolDefinition = {
  name: string;
  description: string;
  parameters: Record<string, unknown>;
};

export type AgentToolExecutionResult = {
  content: AgentTextContent[];
  details?: unknown;
};

export type AgentEvent =
  | { type: "agent_start" }
  | { type: "turn_start"; turn: number }
  | { type: "planner"; source: "model" | "rules"; reason?: string }
  | { type: "tool_plan"; toolCalls: AgentToolCall[] }
  | {
      type: "tool_permission";
      toolCallId: string;
      toolName: string;
      action: "allow" | "block" | "rewrite";
      reason?: string;
      code?: "needs_admin_key" | "needs_confirmation" | "permission_disabled" | "control_disabled";
    }
  | { type: "tool_execution_start"; toolCallId: string; toolName: string; args: Record<string, unknown> }
  | { type: "tool_execution_end"; toolCallId: string; toolName: string; result: AgentToolResult; isError: boolean }
  | { type: "message_update"; delta: string }
  | { type: "turn_end"; turn: number }
  | { type: "agent_end" };

export type AgentToolDecision =
  | { action: "allow"; reason?: string }
  | {
      action: "block";
      reason: string;
      code?: "needs_admin_key" | "needs_confirmation" | "permission_disabled" | "control_disabled";
    }
  | { action: "rewrite"; args: Record<string, unknown>; reason?: string };

export type AgentTurnResult = {
  reply: string;
  toolResults: AgentToolResult[];
  events: AgentEvent[];
  needsAdminKey?: boolean;
  needsConfirmation?: boolean;
  action?: string;
};

export function text(textValue: string): AgentTextContent {
  return { type: "text", text: textValue };
}
