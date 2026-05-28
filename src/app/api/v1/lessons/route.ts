import { NextResponse } from "next/server";
import { z } from "zod";
import { requireApiScope } from "@/lib/api-token-auth";
import { listGeoLessons, writeGeoLesson } from "@/lib/geo-lesson-service";

const LessonSchema = z.object({
  title: z.string().min(1).max(120),
  tactic: z.string().min(1).max(1000),
  scenario: z.string().min(1).max(500),
  outcome: z.enum(["worked", "partial", "did_not_work"]),
  evidenceUrl: z.string().url().optional().or(z.literal("")),
  reportId: z.string().optional(),
  notes: z.string().max(1000).optional(),
});

export async function GET(request: Request) {
  const auth = await requireApiScope(request, "read");
  if ("error" in auth) return auth.error;

  const lessons = await listGeoLessons(20);
  return NextResponse.json({ data: lessons });
}

export async function POST(request: Request) {
  const auth = await requireApiScope(request, "report:write");
  if ("error" in auth) return auth.error;

  const payload = LessonSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid GEO lesson" }, { status: 422 });
  }

  const lesson = await writeGeoLesson({
    ...payload.data,
    evidenceUrl: payload.data.evidenceUrl || null,
  });

  return NextResponse.json({ data: lesson }, { status: 201 });
}
