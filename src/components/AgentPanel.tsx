"use client";

import { Bot, CheckCircle2, GitBranch, ListTree, Send, ShieldAlert, Wrench } from "lucide-react";
import { useEffect, useState } from "react";
import { Badge } from "@/components/Badge";

type ChatMessage = {
  role: "agent" | "user";
  body: string;
};

type AgentToolDefinition = {
  name: string;
  description: string;
};

type AgentEvent = {
  type: string;
  toolName?: string;
  action?: string;
  reason?: string;
  source?: string;
  delta?: string;
  isError?: boolean;
  toolCalls?: Array<{ name: string }>;
};

type AgentResponse = {
  reply?: string;
  error?: string;
  events?: AgentEvent[];
  tools?: AgentToolDefinition[];
  needsConfirmation?: boolean;
  action?: string;
  session?: AgentSessionSnapshot;
};

type AgentSessionEntry = {
  id: string;
  parentId: string | null;
  type: string;
  role: string | null;
  label: string | null;
  createdAt: string;
};

type AgentSessionSnapshot = {
  sessionId: string;
  title: string;
  leafEntryId: string | null;
  entries: AgentSessionEntry[];
};

export function AgentPanel() {
  const [messages, setMessages] = useState<ChatMessage[]>([
    {
      role: "agent",
      body: "我是项目 Agent。现在会先规划工具，再检查权限，最后才执行。控制权限没打开时，我只讲解，不写项目数据。",
    },
  ]);
  const [input, setInput] = useState("");
  const [isSending, setIsSending] = useState(false);
  const [events, setEvents] = useState<AgentEvent[]>([]);
  const [tools, setTools] = useState<AgentToolDefinition[]>([]);
  const [pending, setPending] = useState<{ message: string; action: string } | null>(null);
  const [session, setSession] = useState<AgentSessionSnapshot | null>(null);
  const [branchParentId, setBranchParentId] = useState<string | null>(null);

  useEffect(() => {
    async function loadAgentState() {
      const response = await fetch("/api/agent");
      const data = (await response.json()) as AgentResponse;
      setTools(data.tools ?? []);
      setSession(data.session ?? null);
    }

    void loadAgentState();
  }, []);

  async function submit(confirmed = false, confirmedMessage?: string) {
    const message = confirmedMessage ?? input.trim();
    if (!message || isSending) {
      return;
    }

    if (!confirmedMessage) {
      setInput("");
    }
    setIsSending(true);
    setMessages((current) => [
      ...current,
      { role: "user", body: confirmed ? `${message}（确认执行）` : message },
    ]);

    const response = await fetch("/api/agent", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-geo-admin-key": window.localStorage.getItem("geo-admin-key") ?? "",
      },
      body: JSON.stringify({ message, confirmed, parentEntryId: branchParentId }),
    });
    const data = (await response.json()) as AgentResponse;
    setEvents(data.events ?? []);
    setTools(data.tools ?? []);
    setPending(data.needsConfirmation && data.action ? { message, action: data.action } : null);
    setSession(data.session ?? null);
    if (!data.needsConfirmation) {
      setBranchParentId(null);
    }
    setMessages((current) => [...current, { role: "agent", body: data.reply ?? data.error ?? "我暂时无法回答，请稍后再试。" }]);
    setIsSending(false);
  }

  return (
    <aside id="agent" className="rounded-lg bg-white p-4 shadow-panel ring-1 ring-line">
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <div className="flex size-9 items-center justify-center rounded-md bg-doubao/10 ring-1 ring-doubao/40">
            <Bot className="size-4 text-doubao" />
          </div>
          <div>
            <h2 className="text-sm font-semibold text-ink">项目 Agent</h2>
            <p className="text-xs text-ink/55">像 Obsidian 一样理解整个项目</p>
          </div>
        </div>
        <Badge tone="doubao">讲解模式</Badge>
      </div>

      <div className="mt-4 flex items-start gap-2 rounded-md bg-doubao/10 p-3 text-xs text-ink/75 ring-1 ring-doubao/25">
        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-doubao" />
        <p>控制权限默认关闭。Agent 会列出工具计划；要真正运行采样、生成报告或写内容，需要设置页授权并在这里确认。</p>
      </div>

      <div className="mt-4 flex max-h-[300px] flex-col gap-3 overflow-y-auto pr-1">
        {messages.map((message, index) => (
          <div
            key={`${message.role}-${index}`}
            className={message.role === "agent" ? "rounded-md bg-panel p-3 ring-1 ring-line" : "ml-6 rounded-md bg-doubao/10 p-3 ring-1 ring-doubao/25"}
          >
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-ink/45">{message.role === "agent" ? "Agent" : "You"}</p>
            <p className="mt-2 text-sm leading-6 text-ink/82">{message.body}</p>
          </div>
        ))}
      </div>

      {pending ? (
        <div className="mt-3 rounded-md bg-paper p-3 ring-1 ring-doubao/30">
          <div className="flex items-start gap-2">
            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-doubao" />
            <div className="min-w-0">
              <p className="text-sm font-semibold">等待确认</p>
              <p className="mt-1 text-xs leading-5 text-ink/58">动作：{pending.action}</p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => void submit(true, pending.message)}
            className="mt-3 w-full rounded-md bg-doubao px-3 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
          >
            确认运行
          </button>
        </div>
      ) : null}

      <div className="mt-4 grid gap-3">
        <section className="rounded-md bg-panel p-3 ring-1 ring-line">
          <div className="flex items-center gap-2">
            <Wrench className="size-4 text-doubao" />
            <h3 className="text-sm font-semibold">可用工具</h3>
          </div>
          <div className="mt-3 grid gap-2">
            {(tools.length > 0 ? tools : defaultTools).map((tool) => (
              <div key={tool.name} className="rounded-md bg-white px-3 py-2 ring-1 ring-line/70">
                <p className="font-mono text-xs text-ink">{tool.name}</p>
                <p className="mt-1 line-clamp-2 text-xs leading-5 text-ink/52">{tool.description}</p>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-md bg-panel p-3 ring-1 ring-line">
          <div className="flex items-center gap-2">
            <ListTree className="size-4 text-doubao" />
            <h3 className="text-sm font-semibold">执行过程</h3>
          </div>
          <div className="mt-3 flex max-h-48 flex-col gap-2 overflow-y-auto pr-1">
            {events.length === 0 ? (
              <p className="text-xs text-ink/45">发送一句话后，这里会显示工具计划、权限检查和执行结果。</p>
            ) : (
              events.map((event, index) => (
                <div key={`${event.type}-${index}`} className="rounded-md bg-white px-3 py-2 text-xs ring-1 ring-line/70">
                  <p className="font-mono text-ink/70">{eventLabel(event)}</p>
                  {event.reason ? <p className="mt-1 leading-5 text-ink/52">{event.reason}</p> : null}
                </div>
              ))
            )}
          </div>
        </section>

        <section className="rounded-md bg-panel p-3 ring-1 ring-line">
          <div className="flex items-center gap-2">
            <GitBranch className="size-4 text-doubao" />
            <h3 className="text-sm font-semibold">会话树</h3>
          </div>
          <div className="mt-3 flex max-h-48 flex-col gap-2 overflow-y-auto pr-1">
            {!session || session.entries.length === 0 ? (
              <p className="text-xs text-ink/45">还没有持久化会话。发送一句话后会生成主线节点。</p>
            ) : (
              session.entries.map((entry) => (
                <div
                  key={entry.id}
                  className={
                    entry.id === branchParentId
                      ? "rounded-md bg-ink px-3 py-2 text-xs text-paper ring-1 ring-ink"
                      : entry.id === session.leafEntryId
                      ? "rounded-md bg-doubao/10 px-3 py-2 text-xs ring-1 ring-doubao/35"
                      : "rounded-md bg-white px-3 py-2 text-xs ring-1 ring-line/70"
                  }
                >
                  <div className="flex items-center justify-between gap-2">
                    <p className={entry.id === branchParentId ? "font-mono text-paper/75" : "font-mono text-ink/70"}>
                      {entry.role ?? entry.type}
                    </p>
                    <p className={entry.id === branchParentId ? "shrink-0 text-paper/45" : "shrink-0 text-ink/35"}>
                      {shortTime(entry.createdAt)}
                    </p>
                  </div>
                  <p className={entry.id === branchParentId ? "mt-1 line-clamp-2 leading-5 text-paper/70" : "mt-1 line-clamp-2 leading-5 text-ink/58"}>
                    {entry.label ?? entry.type}
                  </p>
                  <button
                    type="button"
                    onClick={() => setBranchParentId(entry.id)}
                    className={entry.id === branchParentId ? "mt-2 text-xs font-semibold text-paper" : "mt-2 text-xs font-semibold text-doubao"}
                  >
                    从这里继续
                  </button>
                </div>
              ))
            )}
          </div>
        </section>
      </div>

      <div className="mt-4 flex gap-2">
        <input
          value={input}
          onChange={(event) => setInput(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter") {
              void submit();
            }
          }}
          className="min-w-0 flex-1 rounded-md border-0 bg-paper px-3 py-2 text-sm text-ink outline-none ring-1 ring-line/10 focus:ring-doubao"
          placeholder={branchParentId ? "从选中的历史节点继续..." : "问：我现在该做什么？"}
        />
        <button
          type="button"
          onClick={() => void submit()}
          className="flex size-10 items-center justify-center rounded-md bg-doubao text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
          aria-label="发送"
        >
          <Send className="size-4" />
        </button>
      </div>
      {branchParentId ? (
        <div className="mt-2 flex items-center justify-between gap-2 rounded-md bg-doubao/10 px-3 py-2 text-xs text-ink/65 ring-1 ring-doubao/25">
          <span>下一条会从选中节点开分支</span>
          <button type="button" onClick={() => setBranchParentId(null)} className="font-semibold text-doubao">
            取消
          </button>
        </div>
      ) : null}
    </aside>
  );
}

const defaultTools: AgentToolDefinition[] = [
  { name: "run_doubao_sampling", description: "运行豆包问题采样，写入监测记录。" },
  { name: "generate_report", description: "基于当前资料和采样生成诊断报告。" },
  { name: "create_content_draft", description: "生成 FAQ、对比页、案例页等内容草稿。" },
  { name: "search_research_notes", description: "检索已发布的豆包研究节点。" },
];

function eventLabel(event: AgentEvent) {
  if (event.type === "tool_plan") {
    const names = event.toolCalls?.map((call) => call.name).join(", ") || "direct_reply";
    return `tool_plan: ${names}`;
  }

  if (event.type === "planner") {
    return `planner: ${event.source ?? "unknown"}`;
  }

  if (event.type === "tool_permission") {
    return `permission ${event.action}: ${event.toolName}`;
  }

  if (event.type === "tool_execution_start") {
    return `tool_start: ${event.toolName}`;
  }

  if (event.type === "tool_execution_end") {
    return `tool_end: ${event.toolName}${event.isError ? " error" : ""}`;
  }

  if (event.type === "message_update") {
    return `reply: ${event.delta?.slice(0, 36) ?? ""}`;
  }

  return event.type;
}

function shortTime(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  return date.toLocaleTimeString("zh-CN", {
    hour: "2-digit",
    minute: "2-digit",
  });
}
