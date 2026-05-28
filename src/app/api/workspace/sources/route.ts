import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace, getWorkspaceState } from "@/lib/workspace-service";

const SourceSchema = z.object({
  type: z.string().min(1).max(40),
  kind: z.string().min(1).max(40).optional(),
  title: z.string().min(1).max(160),
  url: z.string().url().optional().or(z.literal("")),
  summary: z.string().max(1000).optional(),
  status: z.string().max(40).optional(),
});

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = SourceSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid source asset" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.sourceAsset.create({
    data: {
      workspaceId: workspace.id,
      type: payload.data.type,
      kind: payload.data.kind ?? "brand",
      title: payload.data.title,
      url: payload.data.url || null,
      summary: payload.data.summary || null,
      status: payload.data.status || "ready",
    },
  });

  return NextResponse.json(await getWorkspaceState());
}

export async function DELETE(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const id = new URL(request.url).searchParams.get("id");
  if (!id) {
    return NextResponse.json({ error: "Missing source id" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.sourceAsset.deleteMany({ where: { id, workspaceId: workspace.id } });
  return NextResponse.json(await getWorkspaceState());
}
