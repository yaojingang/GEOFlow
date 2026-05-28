import { NextResponse } from "next/server";
import { assertAdminRequest } from "@/lib/admin-auth";
import { generateReport } from "@/lib/doubao-service";
import { getWorkspaceState } from "@/lib/workspace-service";

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) {
    return authError;
  }

  const state = await getWorkspaceState();
  if (state.agentSettings?.mode !== "Control" || !state.agentSettings.canGenerateReports) {
    return NextResponse.json(
      {
        error: "Agent report permission is not enabled",
        guide: "请到设置页开启「控制模式」和「允许生成报告」，再生成报告。",
      },
      { status: 403 },
    );
  }

  const report = await generateReport();
  return NextResponse.json({ ok: true, report });
}
