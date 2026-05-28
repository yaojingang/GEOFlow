import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { createGeneratedContent } from "@/lib/content-service";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace, getWorkspaceState } from "@/lib/workspace-service";

const ContentSchema = z.object({
  type: z.string().min(1).max(40),
  title: z.string().min(1).max(180).optional(),
  body: z.string().min(1).max(20000).optional(),
  targetGap: z.string().max(1000).optional(),
  imageId: z.string().max(80).optional(),
  generate: z.boolean().default(false),
});

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = ContentSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid content asset" }, { status: 422 });
  }

  if (payload.data.generate) {
    await createGeneratedContent(payload.data.type, payload.data.imageId);
    return NextResponse.json(await getWorkspaceState());
  }

  if (!payload.data.title || !payload.data.body) {
    return NextResponse.json({ error: "Title and body are required" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.contentAsset.create({
    data: {
      workspaceId: workspace.id,
      type: payload.data.type,
      title: payload.data.title,
      body: payload.data.body,
      targetGap: payload.data.targetGap || null,
      status: "draft",
    },
  });

  return NextResponse.json(await getWorkspaceState());
}

export async function DELETE(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const id = new URL(request.url).searchParams.get("id");
  if (!id) {
    return NextResponse.json({ error: "Missing content id" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.contentAsset.deleteMany({ where: { id, workspaceId: workspace.id } });
  return NextResponse.json(await getWorkspaceState());
}
