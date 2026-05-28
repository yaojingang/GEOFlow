import { NextResponse } from "next/server";
import { z } from "zod";
import { assertAdminRequest } from "@/lib/admin-auth";
import { availableApiScopes, createPlainApiToken, hashApiToken, normalizeScopes, tokenPrefix } from "@/lib/api-token-auth";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace, getWorkspaceState } from "@/lib/workspace-service";

const CreateTokenSchema = z.object({
  name: z.string().min(1).max(80),
  scopes: z.array(z.enum(availableApiScopes)).default(["read"]),
  expiresAt: z.string().datetime().optional().or(z.literal("")),
});

export async function POST(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const payload = CreateTokenSchema.safeParse(await request.json());
  if (!payload.success) {
    return NextResponse.json({ error: "Invalid API token request" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  const plainToken = createPlainApiToken();
  await prisma.workspaceApiToken.create({
    data: {
      workspaceId: workspace.id,
      name: payload.data.name,
      tokenPrefix: tokenPrefix(plainToken),
      tokenHash: hashApiToken(plainToken),
      scopes: normalizeScopes(payload.data.scopes),
      expiresAt: payload.data.expiresAt ? new Date(payload.data.expiresAt) : null,
    },
  });

  return NextResponse.json({
    ok: true,
    token: plainToken,
    state: await getWorkspaceState(),
  });
}

export async function DELETE(request: Request) {
  const authError = assertAdminRequest(request);
  if (authError) return authError;

  const id = new URL(request.url).searchParams.get("id");
  if (!id) {
    return NextResponse.json({ error: "Missing token id" }, { status: 422 });
  }

  const workspace = await getOrCreateWorkspace();
  await prisma.workspaceApiToken.updateMany({
    where: { id, workspaceId: workspace.id, revokedAt: null },
    data: { revokedAt: new Date() },
  });

  return NextResponse.json(await getWorkspaceState());
}
