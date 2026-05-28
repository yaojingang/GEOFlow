import { NextResponse } from "next/server";
import { getWorkspaceState } from "@/lib/workspace-service";

export async function GET() {
  const state = await getWorkspaceState();
  return NextResponse.json(state);
}
