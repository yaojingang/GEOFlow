import { NextResponse } from "next/server";
import { z } from "zod";
import { runMonitorCycle } from "@/lib/monitor-runner";

const MonitorCronSchema = z.object({
  day: z.number().int().optional(),
  sampleLimit: z.number().int().min(1).max(12).optional(),
});

function assertCronRequest(request: Request) {
  const expected = process.env.CRON_SECRET;

  if (!expected) {
    return NextResponse.json(
      {
        error: "CRON_SECRET is not configured",
        guide: "请在生产环境配置 CRON_SECRET 后再启用自动监测入口。",
      },
      { status: 503 },
    );
  }

  const auth = request.headers.get("authorization") ?? "";
  const token = auth.startsWith("Bearer ") ? auth.slice(7) : request.headers.get("x-cron-secret") ?? "";

  if (token !== expected) {
    return NextResponse.json(
      {
        error: "Cron secret is required",
        guide: "自动监测入口只允许服务器 cron 或受信任调度器调用。",
      },
      { status: 401 },
    );
  }

  return null;
}

export async function POST(request: Request) {
  const authError = assertCronRequest(request);
  if (authError) return authError;

  const json = await request.json().catch(() => ({}));
  const payload = MonitorCronSchema.safeParse(json);
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid monitor cron request" }, { status: 422 });
  }

  const result = await runMonitorCycle(payload.data);
  return NextResponse.json({ ok: true, result });
}
