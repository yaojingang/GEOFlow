import { prisma } from "@/lib/prisma";
import { getWorkspaceState } from "@/lib/workspace-service";
import type { GeoLesson } from "@prisma/client";

export type LessonOutcome = "worked" | "partial" | "did_not_work";
export type GeoLessonRecord = GeoLesson;

function outcomeLabel(outcome: LessonOutcome) {
  const map = {
    worked: "有效",
    partial: "部分有效",
    did_not_work: "无效",
  };
  return map[outcome];
}

function confidenceForOutcome(outcome: LessonOutcome) {
  if (outcome === "worked") return 82;
  if (outcome === "partial") return 68;
  return 45;
}

function verificationForOutcome(outcome: LessonOutcome) {
  if (outcome === "worked") return "manual_verified";
  if (outcome === "partial") return "partial_verified";
  return "refuted";
}

export async function listGeoLessons(limit = 8) {
  const state = await getWorkspaceState();
  return prisma.geoLesson.findMany({
    where: { workspaceId: state.workspace.id },
    orderBy: [{ verificationStatus: "asc" }, { updatedAt: "desc" }],
    take: Math.max(1, Math.min(limit, 20)),
  });
}

export async function searchGeoLessons(query: string, limit = 5) {
  const state = await getWorkspaceState();
  const words = query
    .split(/\s+/)
    .map((word) => word.trim())
    .filter(Boolean)
    .slice(0, 6);

  const lessons = await prisma.geoLesson.findMany({
    where: {
      workspaceId: state.workspace.id,
      verificationStatus: { not: "refuted" },
      OR:
        words.length > 0
          ? words.flatMap((word) => [
              { title: { contains: word, mode: "insensitive" as const } },
              { tactic: { contains: word, mode: "insensitive" as const } },
              { scenario: { contains: word, mode: "insensitive" as const } },
              { outcome: { contains: word, mode: "insensitive" as const } },
            ])
          : undefined,
    },
    orderBy: [{ confidence: "desc" }, { updatedAt: "desc" }],
    take: Math.max(1, Math.min(limit, 10)),
  });

  return lessons;
}

export async function writeGeoLesson({
  title,
  tactic,
  scenario,
  outcome,
  evidenceUrl,
  reportId,
  notes,
}: {
  title: string;
  tactic: string;
  scenario: string;
  outcome: LessonOutcome;
  evidenceUrl?: string | null;
  reportId?: string | null;
  notes?: string | null;
}) {
  const state = await getWorkspaceState();
  const data = {
    workspaceId: state.workspace.id,
    title,
    tactic,
    scenario,
    outcome: outcomeLabel(outcome),
    verificationStatus: verificationForOutcome(outcome),
    confidence: confidenceForOutcome(outcome),
    evidenceUrl: evidenceUrl || null,
    reportId: reportId || null,
    notes: notes || null,
    workedCount: outcome === "worked" ? 1 : 0,
    partialCount: outcome === "partial" ? 1 : 0,
    didNotWorkCount: outcome === "did_not_work" ? 1 : 0,
  };

  return prisma.geoLesson.create({ data });
}

export async function confirmGeoLesson(id: string, outcome: LessonOutcome, notes?: string | null) {
  const state = await getWorkspaceState();
  const lesson = await prisma.geoLesson.findFirst({
    where: {
      id,
      workspaceId: state.workspace.id,
    },
  });

  if (!lesson) {
    throw new Error("GEO lesson not found");
  }

  const patch = {
    workedCount: outcome === "worked" ? { increment: 1 } : undefined,
    partialCount: outcome === "partial" ? { increment: 1 } : undefined,
    didNotWorkCount: outcome === "did_not_work" ? { increment: 1 } : undefined,
  };
  const workedCount = lesson.workedCount + (outcome === "worked" ? 1 : 0);
  const partialCount = lesson.partialCount + (outcome === "partial" ? 1 : 0);
  const didNotWorkCount = lesson.didNotWorkCount + (outcome === "did_not_work" ? 1 : 0);
  const verificationStatus =
    didNotWorkCount > workedCount + partialCount ? "refuted" : workedCount > 0 ? "manual_verified" : "partial_verified";
  const confidence = Math.max(30, Math.min(95, 55 + workedCount * 12 + partialCount * 6 - didNotWorkCount * 14));

  return prisma.geoLesson.update({
    where: { id },
    data: {
      ...patch,
      verificationStatus,
      confidence,
      notes: notes || lesson.notes,
      outcome: outcomeLabel(outcome),
    },
  });
}

export async function deleteGeoLesson(id: string) {
  const state = await getWorkspaceState();
  const lesson = await prisma.geoLesson.findFirst({
    where: {
      id,
      workspaceId: state.workspace.id,
    },
  });

  if (!lesson) {
    throw new Error("GEO lesson not found");
  }

  await prisma.geoLesson.delete({ where: { id } });
  return lesson;
}

export async function summarizeGeoLessonsForPrompt(query: string) {
  const lessons = await searchGeoLessons(query, 4);
  if (lessons.length === 0) {
    return "";
  }

  return lessons
    .map(
      (lesson) =>
        `- ${lesson.title} [${lesson.verificationStatus}, confidence=${lesson.confidence}]：场景=${lesson.scenario}；动作=${lesson.tactic}；结果=${lesson.outcome}`,
    )
    .join("\n");
}
