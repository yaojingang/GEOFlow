import { NextResponse } from "next/server";
import { requireApiScope } from "@/lib/api-token-auth";
import { generateGetNoteFromPayload, readGetNotePayload } from "@/lib/getnote-service";

export async function POST(request: Request) {
  const auth = await requireApiScope(request, "getnote:generate");
  if ("error" in auth) return auth.error;

  const payload = await readGetNotePayload(request);
  if (!payload.success) {
    return NextResponse.json({ error: payload.error }, { status: 422 });
  }

  const result = await generateGetNoteFromPayload(payload.data);
  return NextResponse.json({
    data: result.draft,
    markdown: result.markdown,
  });
}
