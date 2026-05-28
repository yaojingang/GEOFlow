import { NextResponse } from "next/server";
import { generateGetNoteFromPayload, readGetNotePayload } from "@/lib/getnote-service";

export async function POST(request: Request) {
  const payload = await readPayload(request);
  if (!payload.success) {
    return NextResponse.json({ error: payload.error }, { status: 422 });
  }

  const result = await generateGetNoteFromPayload(payload.data);
  return NextResponse.json({ ...result.draft, markdown: result.markdown });
}

const readPayload = readGetNotePayload;
