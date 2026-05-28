import { Platform } from "@prisma/client";
import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace, getWorkspaceState } from "@/lib/workspace-service";

const QuestionsSchema = z.object({
  title: z.string().min(1).max(120).default("豆包问题集"),
  questions: z.array(z.string().min(1).max(240)).min(1).max(200),
});

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = QuestionsSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid question set" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.questionSet.create({
    data: {
      workspaceId: workspace.id,
      title: payload.data.title,
      platform: Platform.Doubao,
      questions: payload.data.questions,
    },
  });

  return NextResponse.json(await getWorkspaceState());
}

export async function DELETE(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const id = new URL(request.url).searchParams.get("id");
  if (!id) {
    return NextResponse.json({ error: "Missing question set id" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.questionSet.deleteMany({ where: { id, workspaceId: workspace.id } });
  return NextResponse.json(await getWorkspaceState());
}
