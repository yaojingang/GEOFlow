import { NextResponse } from "next/server";
import { requireApiScope } from "@/lib/api-token-auth";
import { prisma } from "@/lib/prisma";

export async function GET(request: Request) {
  const auth = await requireApiScope(request, "read");
  if ("error" in auth) return auth.error;

  const reports = await prisma.report.findMany({
    where: { workspaceId: auth.workspace.id },
    orderBy: { createdAt: "desc" },
    take: 50,
    select: {
      id: true,
      title: true,
      type: true,
      status: true,
      publicSlug: true,
      verificationStatus: true,
      verificationSummary: true,
      createdAt: true,
    },
  });

  return NextResponse.json({ data: reports });
}
