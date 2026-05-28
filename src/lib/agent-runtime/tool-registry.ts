import type { AgentToolDefinition, AgentToolExecutionResult } from "@/lib/agent-runtime/types";

type ToolExecutor = (args: Record<string, unknown>) => Promise<AgentToolExecutionResult>;

type RegisteredTool = AgentToolDefinition & {
  execute: ToolExecutor;
};

export class AgentToolRegistry {
  private readonly tools = new Map<string, RegisteredTool>();

  register(tool: RegisteredTool) {
    this.tools.set(tool.name, tool);
  }

  definitions(): AgentToolDefinition[] {
    return Array.from(this.tools.values()).map(({ name, description, parameters }) => ({
      name,
      description,
      parameters,
    }));
  }

  async execute(name: string, args: Record<string, unknown>) {
    const tool = this.tools.get(name);

    if (!tool) {
      throw new Error(`Agent tool not found: ${name}`);
    }

    return tool.execute(args);
  }
}
