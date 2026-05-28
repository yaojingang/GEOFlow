import { NextResponse } from "next/server";
import { getResearchNoteBySlug } from "@/lib/research-service";

type ResearchRouteProps = {
  params: Promise<{ slug: string }>;
};

export const dynamic = "force-dynamic";

export async function GET(_request: Request, { params }: ResearchRouteProps) {
  const { slug } = await params;
  const note = await getResearchNoteBySlug(slug);

  if (!note) {
    return NextResponse.json({ error: "Research note not found" }, { status: 404 });
  }

  return NextResponse.json(note);
}
