import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { confirmGeoLesson, deleteGeoLesson, listGeoLessons, writeGeoLesson } from "@/lib/geo-lesson-service";

const LessonSchema = z.object({
  title: z.string().min(1).max(120),
  tactic: z.string().min(1).max(1000),
  scenario: z.string().min(1).max(500),
  outcome: z.enum(["worked", "partial", "did_not_work"]),
  evidenceUrl: z.string().url().optional().or(z.literal("")),
  reportId: z.string().optional().or(z.literal("")),
  notes: z.string().max(1000).optional().or(z.literal("")),
});

const ConfirmSchema = z.object({
  id: z.string().min(1),
  outcome: z.enum(["worked", "partial", "did_not_work"]),
  notes: z.string().max(1000).optional().or(z.literal("")),
});

export async function GET() {
  const lessons = await listGeoLessons(20);
  return NextResponse.json({ data: lessons });
}

export async function POST(request: Request) {
  const adminError = assertAdminRequest(request);
  if (adminError) return adminError;

  const payload = LessonSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid GEO lesson" }, { status: 422 });
  }

  const lesson = await writeGeoLesson({
    ...payload.data,
    evidenceUrl: payload.data.evidenceUrl || null,
    reportId: payload.data.reportId || null,
    notes: payload.data.notes || null,
  });

  return NextResponse.json({ data: await listGeoLessons(20), lesson }, { status: 201 });
}

export async function PATCH(request: Request) {
  const adminError = assertAdminRequest(request);
  if (adminError) return adminError;

  const payload = ConfirmSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid GEO lesson feedback" }, { status: 422 });
  }

  const lesson = await confirmGeoLesson(payload.data.id, payload.data.outcome, payload.data.notes || null);
  return NextResponse.json({ data: await listGeoLessons(20), lesson });
}

export async function DELETE(request: Request) {
  const adminError = assertAdminRequest(request);
  if (adminError) return adminError;

  const { searchParams } = new URL(request.url);
  const id = searchParams.get("id");
  if (!id) {
    return NextResponse.json({ error: "Lesson id is required" }, { status: 422 });
  }

  const lesson = await deleteGeoLesson(id);
  return NextResponse.json({ data: await listGeoLessons(20), lesson });
}
