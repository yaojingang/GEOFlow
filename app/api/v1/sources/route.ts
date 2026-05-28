import { NextResponse } from "next/server";
import { z } from "zod";
import { requireApiScope } from "@/lib/api-token-auth";
import { prisma } from "@/lib/prisma";

const SourceSchema = z.object({
  type: z.string().min(1).max(40).default("官网链接"),
  kind: z.string().min(1).max(40).optional(),
  title: z.string().min(1).max(160),
  url: z.string().url().optional().or(z.literal("")),
  summary: z.string().max(1000).optional(),
});

export async function GET(request: Request) {
  const auth = await requireApiScope(request, "read");
  if ("error" in auth) return auth.error;

  const sources = await prisma.sourceAsset.findMany({
    where: { workspaceId: auth.workspace.id },
    orderBy: { createdAt: "desc" },
    take: 100,
  });

  return NextResponse.json({ data: sources });
}

export async function POST(request: Request) {
  const auth = await requireApiScope(request, "source:write");
  if ("error" in auth) return auth.error;

  const payload = SourceSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid source asset" }, { status: 422 });
  }

  const source = await prisma.sourceAsset.create({
    data: {
      workspaceId: auth.workspace.id,
      type: payload.data.type,
      kind: payload.data.kind ?? "brand",
      title: payload.data.title,
      url: payload.data.url || null,
      summary: payload.data.summary || null,
      status: "ready",
    },
  });

  return NextResponse.json({ data: source }, { status: 201 });
}
