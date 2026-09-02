import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

/** POST — approve a single assignment. */
export async function POST(_req: NextRequest, { params }: { params: { id: string } }) {
  const assignment = await prisma.assignment.update({
    where: { id: params.id },
    data: { approved: true },
  });
  return NextResponse.json({ assignment });
}
