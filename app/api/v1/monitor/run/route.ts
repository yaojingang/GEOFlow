import { NextResponse } from "next/server";
import { requireApiScope } from "@/lib/api-token-auth";
import { generateReport, runDoubaoSampling } from "@/lib/doubao-service";

export async function POST(request: Request) {
  const auth = await requireApiScope(request, "monitor:run");
  if ("error" in auth) return auth.error;

  const body = await request.json().catch(() => ({}));
  const limit = typeof body.limit === "number" ? body.limit : 5;
  const samples = await runDoubaoSampling(limit);
  const report = await generateReport("geo.youngtuo.win API 监测报告");

  return NextResponse.json({ ok: true, samples, report });
}
