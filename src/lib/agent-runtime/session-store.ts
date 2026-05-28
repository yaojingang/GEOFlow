import type { Prisma } from "@prisma/client";
import type { AgentEvent, AgentToolResult } from "@/lib/agent-runtime/types";
import { prisma } from "@/lib/prisma";

const defaultSessionTitle = "默认 Agent 会话";
const compactAfterEntries = 28;
const keepRecentEntries = 14;

export type AgentSessionSnapshot = Awaited<ReturnType<typeof getAgentSessionSnapshot>>;
export type AgentContextSnapshot = Awaited<ReturnType<typeof buildAgentContext>>;

export async function getOrCreateAgentSession(workspaceId: string) {
  const existing = await prisma.agentSession.findFirst({
    where: { workspaceId, title: defaultSessionTitle },
    orderBy: { createdAt: "asc" },
  });

  if (existing) {
    return existing;
  }

  return prisma.agentSession.create({
    data: {
      workspaceId,
      title: defaultSessionTitle,
    },
  });
}

export async function getAgentSessionSnapshot(workspaceId: string) {
  const session = await getOrCreateAgentSession(workspaceId);
  const entries = await prisma.agentSessionEntry.findMany({
    where: { sessionId: session.id },
    orderBy: { createdAt: "desc" },
    take: 40,
  });

  return {
    sessionId: session.id,
    title: session.title,
    leafEntryId: session.leafEntryId,
    entries: entries.reverse().map((entry) => ({
      id: entry.id,
      parentId: entry.parentId,
      type: entry.type,
      role: entry.role,
      label: entry.label,
      payload: entry.payload,
      createdAt: entry.createdAt.toISOString(),
    })),
  };
}

export async function recordAgentTurn({
  workspaceId,
  userMessage,
  reply,
  events,
  toolResults,
  parentEntryId,
}: {
  workspaceId: string;
  userMessage: string;
  reply: string;
  events: AgentEvent[];
  toolResults: AgentToolResult[];
  parentEntryId?: string | null;
}) {
  const session = await getOrCreateAgentSession(workspaceId);
  let parentId = parentEntryId ? await resolveBranchParent(session.id, parentEntryId) : session.leafEntryId ?? null;

  const userEntry = await appendEntry({
    sessionId: session.id,
    parentId,
    type: "message",
    role: "user",
    label: summarizeText(userMessage),
    payload: toJson({ text: userMessage }),
  });
  parentId = userEntry.id;

  const eventEntry = await appendEntry({
    sessionId: session.id,
    parentId,
    type: "events",
    role: "system",
    label: summarizeEvents(events),
    payload: toJson({ events: scrubEvents(events) }),
  });
  parentId = eventEntry.id;

  if (toolResults.length > 0) {
    const toolEntry = await appendEntry({
      sessionId: session.id,
      parentId,
      type: "toolResults",
      role: "tool",
      label: summarizeToolResults(toolResults),
      payload: toJson({ toolResults }),
    });
    parentId = toolEntry.id;
  }

  const assistantEntry = await appendEntry({
    sessionId: session.id,
    parentId,
    type: "message",
    role: "assistant",
    label: summarizeText(reply),
    payload: toJson({ text: reply }),
  });

  await prisma.agentSession.update({
    where: { id: session.id },
    data: { leafEntryId: assistantEntry.id },
  });

  await compactIfNeeded(session.id, assistantEntry.id);

  return getAgentSessionSnapshot(workspaceId);
}

export async function resolveAgentBranchParent(workspaceId: string, parentEntryId?: string | null) {
  if (!parentEntryId) {
    return null;
  }

  const session = await getOrCreateAgentSession(workspaceId);
  return resolveBranchParent(session.id, parentEntryId);
}

export async function buildAgentContext(workspaceId: string, parentEntryId?: string | null) {
  const session = await getOrCreateAgentSession(workspaceId);
  const leafId = parentEntryId ?? session.leafEntryId;

  if (!leafId) {
    return {
      leafEntryId: null,
      summary: "",
      recent: [],
      referencedEntries: [],
    };
  }

  const path = await pathToLeaf(session.id, leafId);
  const latestCompaction = path.findLast((entry) => entry.type === "compaction");
  const recentEntries = path
    .filter((entry) => entry.type !== "compaction")
    .slice(-8)
    .map((entry) => ({
      id: entry.id,
      type: entry.type,
      role: entry.role,
      label: entry.label,
      text: entryText(entry.payload),
    }));

  return {
    leafEntryId: leafId,
    summary: latestCompaction ? compactionSummary(latestCompaction.payload) : "",
    recent: recentEntries,
    referencedEntries: path.slice(-12).map((entry) => entry.id),
  };
}

async function resolveBranchParent(sessionId: string, parentEntryId: string) {
  const entry = await prisma.agentSessionEntry.findFirst({
    where: {
      id: parentEntryId,
      sessionId,
    },
    select: { id: true },
  });

  if (!entry) {
    throw new Error("Selected session entry does not exist.");
  }

  return entry.id;
}

async function appendEntry(data: {
  sessionId: string;
  parentId: string | null;
  type: string;
  role: string;
  label: string;
  payload: Prisma.InputJsonValue;
}) {
  return prisma.agentSessionEntry.create({
    data,
  });
}

function scrubEvents(events: AgentEvent[]) {
  return events.map((event) => {
    if (event.type !== "tool_execution_end") {
      return event;
    }

    return {
      ...event,
      result: {
        ...event.result,
        details: event.result.details ?? null,
      },
    };
  });
}

function summarizeText(value: string) {
  const normalized = value.replace(/\s+/g, " ").trim();
  return normalized.length > 52 ? `${normalized.slice(0, 52)}...` : normalized || "空消息";
}

function summarizeEvents(events: AgentEvent[]) {
  const toolPlan = events.find((event) => event.type === "tool_plan");
  if (toolPlan?.type === "tool_plan" && toolPlan.toolCalls.length > 0) {
    return `计划：${toolPlan.toolCalls.map((call) => call.name).join(", ")}`;
  }

  return "直接回答";
}

function summarizeToolResults(toolResults: AgentToolResult[]) {
  const failed = toolResults.filter((result) => result.isError).length;
  return failed > 0 ? `工具结果：${toolResults.length} 个，失败 ${failed} 个` : `工具结果：${toolResults.length} 个`;
}

function toJson(value: unknown): Prisma.InputJsonValue {
  return JSON.parse(JSON.stringify(value)) as Prisma.InputJsonValue;
}

async function compactIfNeeded(sessionId: string, leafEntryId: string) {
  const path = await pathToLeaf(sessionId, leafEntryId);
  const latestCompactionIndex = path.findLastIndex((entry) => entry.type === "compaction");
  const entriesAfterLastCompaction =
    latestCompactionIndex === -1 ? path : path.slice(latestCompactionIndex + 1);

  if (entriesAfterLastCompaction.length <= compactAfterEntries) {
    return;
  }

  const oldEntries = entriesAfterLastCompaction.slice(0, -keepRecentEntries);
  const recentEntries = entriesAfterLastCompaction.slice(-keepRecentEntries);
  const summary = summarizeSessionEntries(oldEntries);
  const compaction = await appendEntry({
    sessionId,
    parentId: leafEntryId,
    type: "compaction",
    role: "system",
    label: `压缩摘要：${oldEntries.length} 个旧节点`,
    payload: toJson({
      summary,
      summarizedEntryIds: oldEntries.map((entry) => entry.id),
      firstKeptEntryId: recentEntries[0]?.id ?? null,
      tokensBefore: estimateEntryTokens(entriesAfterLastCompaction),
    }),
  });

  await prisma.agentSession.update({
    where: { id: sessionId },
    data: { leafEntryId: compaction.id },
  });
}

async function pathToLeaf(sessionId: string, leafEntryId: string) {
  const entries = await prisma.agentSessionEntry.findMany({
    where: { sessionId },
    orderBy: { createdAt: "asc" },
  });
  const byId = new Map(entries.map((entry) => [entry.id, entry]));
  const path = [];
  let current = byId.get(leafEntryId);

  while (current) {
    path.unshift(current);
    current = current.parentId ? byId.get(current.parentId) : undefined;
  }

  return path;
}

function summarizeSessionEntries(entries: Array<{ type: string; role: string | null; label: string | null }>) {
  return entries
    .map((entry) => `${entry.role ?? entry.type}: ${entry.label ?? entry.type}`)
    .join("\n")
    .slice(0, 2200);
}

function estimateEntryTokens(entries: Array<{ label: string | null }>) {
  return entries.reduce((sum, entry) => sum + Math.ceil((entry.label?.length ?? 0) / 2), 0);
}

function entryText(payload: Prisma.JsonValue) {
  if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
    return "";
  }

  const record = payload as Record<string, unknown>;
  if (typeof record.text === "string") {
    return record.text.slice(0, 360);
  }

  if (Array.isArray(record.toolResults)) {
    return record.toolResults
      .map((result) => {
        if (typeof result !== "object" || result === null) return "";
        const item = result as Record<string, unknown>;
        return `${String(item.toolName ?? "tool")}: ${String(item.isError ? "failed" : "ok")}`;
      })
      .filter(Boolean)
      .join("；");
  }

  if (Array.isArray(record.events)) {
    return `events: ${record.events.length}`;
  }

  return "";
}

function compactionSummary(payload: Prisma.JsonValue) {
  if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
    return "";
  }

  const summary = (payload as Record<string, unknown>).summary;
  return typeof summary === "string" ? summary.slice(0, 1800) : "";
}
