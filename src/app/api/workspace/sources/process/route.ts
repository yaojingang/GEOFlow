import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { processSourceAsset } from "@/lib/source-processing";
import { getWorkspaceState } from "@/lib/workspace-service";

const ProcessSchema = z.object({
  id: z.string().min(1),
});

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = ProcessSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid source processing request" }, { status: 422 });
  }

  try {
    const result = await processSourceAsset(payload.data.id);
    return NextResponse.json({ ok: true, result, state: await getWorkspaceState() });
  } catch (error) {
    return NextResponse.json(
      {
        error: "Source processing failed",
        guide: error instanceof Error ? error.message : "请检查资料链接是否可访问。",
      },
      { status: 500 },
    );
  }
}
