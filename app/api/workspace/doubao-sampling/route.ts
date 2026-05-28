import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { runDoubaoSampling } from "@/lib/doubao-service";
import { getWorkspaceState } from "@/lib/workspace-service";

const SamplingSchema = z.object({
  limit: z.number().int().min(1).max(12).default(5),
  confirmed: z.boolean().default(false),
});

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) {
    return authError;
  }

  const payload = SamplingSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid sampling request" }, { status: 422 });
  }

  const state = await getWorkspaceState();
  if (state.agentSettings?.mode !== "Control" || !state.agentSettings.canRunDoubaoSampling) {
    return NextResponse.json(
      {
        error: "Agent control permission is not enabled",
        guide: "请到设置页开启「控制模式」和「允许运行豆包采样」，再运行采样。",
      },
      { status: 403 },
    );
  }

  if (state.agentSettings.requireConfirmation && !payload.data.confirmed) {
    return NextResponse.json(
      {
        error: "Confirmation required",
        guide: "该操作会消耗模型额度或写入采样记录，请确认后重试。",
      },
      { status: 409 },
    );
  }

  const samples = await runDoubaoSampling(payload.data.limit);
  return NextResponse.json({ ok: true, samples });
}
