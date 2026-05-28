import { NextResponse } from "next/server";
import { z } from "zod";
import { expandKeywords } from "@/lib/keyword-expansion";
import { getWorkspaceState } from "@/lib/workspace-service";

const KeywordExpansionSchema = z.object({
  seed: z.string().trim().min(1).max(120),
  industry: z.string().trim().max(120).optional(),
  competitors: z.string().trim().max(500).optional(),
});

export async function POST(request: Request) {
  const payload = KeywordExpansionSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "请输入种子词，例如 GEO、豆包优化、AI 搜索优化。" }, { status: 422 });
  }

  const state = await getWorkspaceState();
  const result = await expandKeywords({
    ...payload.data,
    state,
  });

  return NextResponse.json(result);
}
