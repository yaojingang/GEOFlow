import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { createResearchNote, getResearchIndex } from "@/lib/research-service";

const ResearchNoteSchema = z.object({
  title: z.string().min(1).max(160),
  excerpt: z.string().max(260).optional().nullable(),
  body: z.string().min(1).max(24000),
  type: z.string().max(40).optional().nullable(),
  tags: z.union([z.array(z.string()), z.string()]).optional(),
  status: z.enum(["draft", "published", "archived"]).default("draft"),
  sourceType: z.string().max(40).optional().nullable(),
  sourceId: z.string().max(120).optional().nullable(),
});

export async function GET() {
  return NextResponse.json(await getResearchIndex({ includeDrafts: true }));
}

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = ResearchNoteSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid research note" }, { status: 422 });
  }

  const note = await createResearchNote(payload.data);
  return NextResponse.json({ note, state: await getResearchIndex({ includeDrafts: true }) });
}
