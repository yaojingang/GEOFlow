import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { deleteResearchNote, getResearchIndex, updateResearchNote } from "@/lib/research-service";

const ResearchNotePatchSchema = z.object({
  title: z.string().min(1).max(160).optional(),
  excerpt: z.string().max(260).optional().nullable(),
  body: z.string().min(1).max(24000).optional(),
  type: z.string().max(40).optional().nullable(),
  tags: z.union([z.array(z.string()), z.string()]).optional(),
  status: z.enum(["draft", "published", "archived"]).optional(),
  sourceType: z.string().max(40).optional().nullable(),
  sourceId: z.string().max(120).optional().nullable(),
});

type ResearchNoteRouteProps = {
  params: Promise<{ id: string }>;
};

export async function PATCH(request: Request, { params }: ResearchNoteRouteProps) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = ResearchNotePatchSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid research note" }, { status: 422 });
  }

  const { id } = await params;
  const note = await updateResearchNote(id, payload.data);
  if (!note) {
    return NextResponse.json({ error: "Research note not found" }, { status: 404 });
  }

  return NextResponse.json({ note, state: await getResearchIndex({ includeDrafts: true }) });
}

export async function DELETE(request: Request, { params }: ResearchNoteRouteProps) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const { id } = await params;
  await deleteResearchNote(id);
  return NextResponse.json(await getResearchIndex({ includeDrafts: true }));
}
