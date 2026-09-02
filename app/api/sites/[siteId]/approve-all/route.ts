import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { AUTO_APPROVE_THRESHOLD } from "@/lib/matching";

export const dynamic = "force-dynamic";

/** POST — approve every pending assignment at or above the threshold (body: { threshold? }). */
export async function POST(req: NextRequest, { params }: { params: { siteId: string } }) {
  const body = await req.json().catch(() => ({}));
  const threshold = typeof body.threshold === "number" ? body.threshold : AUTO_APPROVE_THRESHOLD;

  const result = await prisma.assignment.updateMany({
    where: { siteId: params.siteId, approved: false, matchScore: { gte: threshold } },
    data: { approved: true },
  });
  return NextResponse.json({ approved: result.count, threshold });
}
