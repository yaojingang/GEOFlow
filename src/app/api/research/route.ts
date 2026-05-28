import { NextResponse } from "next/server";
import { getResearchIndex } from "@/lib/research-service";

export const dynamic = "force-dynamic";

export async function GET() {
  const index = await getResearchIndex();
  return NextResponse.json(index);
}
