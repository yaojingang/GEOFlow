import crypto from "node:crypto";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getOrCreateWorkspace } from "@/lib/workspace-service";

export const availableApiScopes = [
  "read",
  "source:write",
  "source:process",
  "monitor:run",
  "content:write",
  "report:write",
  "getnote:generate",
  "publish:write",
] as const;

export type ApiScope = (typeof availableApiScopes)[number];

export function createPlainApiToken() {
  return `geo_${crypto.randomBytes(24).toString("base64url")}`;
}

export function hashApiToken(token: string) {
  return crypto.createHash("sha256").update(token).digest("hex");
}

export function tokenPrefix(token: string) {
  return token.slice(0, 10);
}

export function normalizeScopes(scopes: unknown): ApiScope[] {
  if (!Array.isArray(scopes)) return ["read"];
  const allowed = new Set<string>(availableApiScopes);
  const normalized = scopes.filter((scope): scope is ApiScope => typeof scope === "string" && allowed.has(scope));
  return normalized.length > 0 ? [...new Set(normalized)] : ["read"];
}

export async function requireApiScope(request: Request, scope: ApiScope) {
  const auth = request.headers.get("authorization") ?? "";
  const match = auth.match(/^Bearer\s+(.+)$/i);

  if (!match) {
    return {
      error: NextResponse.json(
        { error: "API token is required", guide: "请在 Authorization header 使用 Bearer geo_xxx。" },
        { status: 401 },
      ),
    };
  }

  const plainToken = match[1].trim();
  const workspace = await getOrCreateWorkspace();
  const record = await prisma.workspaceApiToken.findFirst({
    where: {
      workspaceId: workspace.id,
      tokenHash: hashApiToken(plainToken),
      revokedAt: null,
      OR: [{ expiresAt: null }, { expiresAt: { gt: new Date() } }],
    },
  });

  if (!record) {
    return {
      error: NextResponse.json({ error: "Invalid or expired API token" }, { status: 401 }),
    };
  }

  const scopes = normalizeScopes(record.scopes);
  if (!scopes.includes(scope) && !(scope !== "read" && scopes.includes("publish:write"))) {
    return {
      error: NextResponse.json(
        { error: "API token scope is not allowed", requiredScope: scope, scopes },
        { status: 403 },
      ),
    };
  }

  await prisma.workspaceApiToken.update({
    where: { id: record.id },
    data: { lastUsedAt: new Date() },
  });

  return { workspace, token: record, scopes };
}
