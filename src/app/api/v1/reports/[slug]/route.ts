import { NextResponse } from "next/server";
import { requireApiScope } from "@/lib/api-token-auth";
import { prisma } from "@/lib/prisma";

type RouteContext = {
  params: Promise<{ slug: string }>;
};

export async function GET(request: Request, context: RouteContext) {
  const auth = await requireApiScope(request, "read");
  if ("error" in auth) return auth.error;

  const { slug } = await context.params;
  const report = await prisma.report.findFirst({
    where: {
      workspaceId: auth.workspace.id,
      OR: [{ publicSlug: slug }, { id: slug }],
    },
    include: {
      workspace: {
        include: {
          answerSamples: { orderBy: { sampledAt: "desc" }, take: 20 },
          brandFacts: { orderBy: { confidence: "desc" }, take: 20 },
          sourceAssets: { orderBy: { createdAt: "desc" }, take: 20 },
        },
      },
    },
  });

  if (!report) {
    return NextResponse.json({ error: "Report not found" }, { status: 404 });
  }

  return NextResponse.json({ data: report });
}
