import { NextResponse } from "next/server";
import { requireApiScope } from "@/lib/api-token-auth";
import { processSourceAsset } from "@/lib/source-processing";

type RouteContext = {
  params: Promise<{ id: string }>;
};

export async function POST(request: Request, context: RouteContext) {
  const auth = await requireApiScope(request, "source:process");
  if ("error" in auth) return auth.error;

  const { id } = await context.params;
  const result = await processSourceAsset(id);
  return NextResponse.json({ ok: true, result });
}
