import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace, getWorkspaceState } from "@/lib/workspace-service";

const BrandFactSchema = z.object({
  title: z.string().min(1).max(120),
  body: z.string().min(1).max(1200),
  evidenceUrl: z.string().url().optional().or(z.literal("")),
  confidence: z.number().int().min(0).max(100).default(70),
});

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = BrandFactSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid brand fact" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.brandFact.create({
    data: {
      workspaceId: workspace.id,
      title: payload.data.title,
      body: payload.data.body,
      evidenceUrl: payload.data.evidenceUrl || null,
      confidence: payload.data.confidence,
    },
  });

  return NextResponse.json(await getWorkspaceState());
}

export async function DELETE(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const id = new URL(request.url).searchParams.get("id");
  if (!id) {
    return NextResponse.json({ error: "Missing brand fact id" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.brandFact.deleteMany({ where: { id, workspaceId: workspace.id } });
  return NextResponse.json(await getWorkspaceState());
}
