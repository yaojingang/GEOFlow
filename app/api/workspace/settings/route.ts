import { AgentMode } from "@prisma/client";
import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace, getWorkspaceState } from "@/lib/workspace-service";

const SettingsSchema = z.object({
  analytics: z
    .array(
      z.object({
        provider: z.string().min(1),
        propertyId: z.string().optional(),
        status: z.string().optional(),
      }),
    )
    .default([]),
  socials: z
    .array(
      z.object({
        platform: z.string().min(1),
        handle: z.string().optional(),
        url: z.string().optional(),
        isVisible: z.boolean().optional(),
      }),
    )
    .default([]),
  agent: z
    .object({
      mode: z.enum(["Explain", "Assist", "Control"]).default("Explain"),
      canRunDoubaoSampling: z.boolean().default(false),
      canGenerateReports: z.boolean().default(false),
      canCreateContent: z.boolean().default(false),
      canEditContent: z.boolean().default(false),
      canPublish: z.boolean().default(false),
      canManageSources: z.boolean().default(false),
      canManageResearch: z.boolean().default(false),
      canModifySettings: z.boolean().default(false),
      requireConfirmation: z.boolean().default(true),
    })
    .optional(),
});

export async function PATCH(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) {
    return authError;
  }

  const payload = SettingsSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid settings" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();

  for (const item of payload.data.analytics) {
    const current = await prisma.analyticsConfig.findFirst({
      where: { workspaceId: workspace.id, provider: item.provider },
    });
    const status = item.status ?? (item.propertyId ? "configured" : "missing");

    if (current) {
      await prisma.analyticsConfig.update({
        where: { id: current.id },
        data: { propertyId: item.propertyId ?? null, status },
      });
    } else {
      await prisma.analyticsConfig.create({
        data: {
          workspaceId: workspace.id,
          provider: item.provider,
          propertyId: item.propertyId,
          status,
          guide: "已由设置页新增，请按平台完成验证后再运行采样报告。",
        },
      });
    }
  }

  for (const item of payload.data.socials) {
    const current = await prisma.socialAccount.findFirst({
      where: { workspaceId: workspace.id, platform: item.platform },
    });
    const data = {
      handle: item.handle ?? "",
      url: item.url ?? "",
      isVisible: Boolean(item.isVisible),
    };

    if (current) {
      await prisma.socialAccount.update({ where: { id: current.id }, data });
    } else {
      await prisma.socialAccount.create({
        data: {
          workspaceId: workspace.id,
          platform: item.platform,
          ...data,
        },
      });
    }
  }

  if (payload.data.agent) {
    await prisma.agentSetting.upsert({
      where: { workspaceId: workspace.id },
      update: {
        ...payload.data.agent,
        mode: payload.data.agent.mode as AgentMode,
      },
      create: {
        workspaceId: workspace.id,
        ...payload.data.agent,
        mode: payload.data.agent.mode as AgentMode,
      },
    });
  }

  return NextResponse.json(await getWorkspaceState());
}
