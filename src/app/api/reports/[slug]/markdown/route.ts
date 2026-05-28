import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { buildReportMarkdown } from "@/lib/report-export";

type MarkdownRouteProps = {
  params: Promise<{ slug: string }>;
};

export async function GET(_request: Request, { params }: MarkdownRouteProps) {
  const { slug } = await params;
  const report = await prisma.report.findUnique({
    where: { publicSlug: slug },
    include: {
      workspace: {
        include: {
          answerSamples: { orderBy: { sampledAt: "desc" }, take: 20 },
          brandFacts: { orderBy: { confidence: "desc" }, take: 20 },
          sourceAssets: { orderBy: { createdAt: "desc" }, take: 20 },
          questionSets: { orderBy: { createdAt: "desc" }, take: 5 },
          analyticsConfigs: { orderBy: { provider: "asc" } },
          socialAccounts: { orderBy: { platform: "asc" } },
        },
      },
    },
  });

  if (!report) {
    return NextResponse.json({ error: "Report not found" }, { status: 404 });
  }

  const markdown = buildReportMarkdown(report);
  return new Response(markdown, {
    headers: {
      "Content-Type": "text/markdown; charset=utf-8",
      "Content-Disposition": `attachment; filename="${slug}.md"`,
    },
  });
}
