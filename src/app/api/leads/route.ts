import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace } from "@/lib/workspace-service";

const LeadSchema = z.object({
  name: z.string().trim().min(1).max(80),
  phone: z.string().trim().min(5).max(40),
  company: z.string().trim().min(1).max(120),
  industry: z.string().trim().min(1).max(80),
  goal: z.string().trim().min(1).max(1000),
});

export async function POST(request: Request) {
  const payload = LeadSchema.safeParse(await request.json());

  if (!payload.success) {
    return NextResponse.json({ error: "请补齐姓名、手机、公司、行业和想解决的问题。" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  const lead = await prisma.lead.create({
    data: {
      workspaceId: workspace.id,
      ...payload.data,
    },
    select: {
      id: true,
      createdAt: true,
    },
  });

  return NextResponse.json({
    ok: true,
    leadId: lead.id,
    createdAt: lead.createdAt,
    nextStep: "我们会基于品牌、行业和目标市场生成一份豆包 GEO 初诊断清单。",
  });
}
