import { NextResponse } from "next/server";
import { assertAdminRequest } from "@/lib/admin-auth";
import { processUploadedSourceAsset } from "@/lib/source-processing";
import { getOrCreateWorkspace, getWorkspaceState } from "@/lib/workspace-service";

export const runtime = "nodejs";

const maxUploadBytes = 8 * 1024 * 1024;

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const formData = await request.formData();
  const file = formData.get("file");

  if (!(file instanceof File)) {
    return NextResponse.json({ error: "Missing upload file" }, { status: 422 });
  }

  if (file.size > maxUploadBytes) {
    return NextResponse.json({ error: "Upload file is larger than 8MB" }, { status: 413 });
  }

  const workspace = await getOrCreateWorkspace();
  const type = String(formData.get("type") || "本地资料").slice(0, 40);
  const title = String(formData.get("title") || file.name).slice(0, 160);
  const summary = String(formData.get("summary") || "").slice(0, 1000);
  const buffer = Buffer.from(await file.arrayBuffer());

  try {
    const result = await processUploadedSourceAsset({
      workspaceId: workspace.id,
      type,
      title,
      fileName: file.name,
      mimeType: file.type || "application/octet-stream",
      buffer,
      summary: summary || null,
    });

    return NextResponse.json({ ok: true, result, state: await getWorkspaceState() }, { status: 201 });
  } catch (error) {
    return NextResponse.json(
      {
        error: "Source upload failed",
        guide: error instanceof Error ? error.message : "请检查文件是否为可解析的 PDF 或文本资料。",
      },
      { status: 500 },
    );
  }
}
