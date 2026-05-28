import type { MetadataRoute } from "next";
import { siteUrl } from "@/data/workspace";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const reports = process.env.DATABASE_URL
    ? await prisma.report.findMany({
        where: { publicSlug: { not: null } },
        select: { publicSlug: true, createdAt: true },
        orderBy: { createdAt: "desc" },
        take: 20,
      })
    : [];
  const researchNotes = process.env.DATABASE_URL
    ? await prisma.researchNote.findMany({
        where: { status: "published" },
        select: { slug: true, updatedAt: true },
        orderBy: { updatedAt: "desc" },
        take: 50,
      })
    : [];
  const staticPaths = [
    "",
    "/doubao-research",
    "/workspace",
    "/workspace/architecture",
    "/workspace/ai-indexing",
    "/workspace/reports",
    "/workspace/publish",
    "/workspace/settings",
    "/llms.txt",
  ] as const;
  return [
    ...staticPaths.map((path) => ({
      url: `${siteUrl}${path}`,
      lastModified: new Date(),
      changeFrequency: "weekly" as const,
      priority: path === "" ? 1 : 0.7,
    })),
    ...reports
      .filter((report) => report.publicSlug)
      .map((report) => ({
        url: `${siteUrl}/reports/${report.publicSlug}`,
        lastModified: report.createdAt,
        changeFrequency: "weekly" as const,
        priority: 0.8,
      })),
    ...researchNotes.map((note) => ({
      url: `${siteUrl}/doubao-research/${note.slug}`,
      lastModified: note.updatedAt,
      changeFrequency: "weekly" as const,
      priority: 0.75,
    })),
  ];
}
