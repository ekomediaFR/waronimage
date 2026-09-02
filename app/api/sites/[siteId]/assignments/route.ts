import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import type { Prisma } from "@prisma/client";

export const dynamic = "force-dynamic";

/**
 * GET — assignments with their page + media.
 * Filters: ?status=pending|approved|exported|errors  ?minScore=0.4
 */
export async function GET(req: NextRequest, { params }: { params: { siteId: string } }) {
  const status = req.nextUrl.searchParams.get("status");
  const minScore = Number(req.nextUrl.searchParams.get("minScore"));

  const where: Prisma.AssignmentWhereInput = { siteId: params.siteId };
  if (status === "pending") {
    where.approved = false;
  } else if (status === "approved") {
    where.approved = true;
    where.exported = false;
  } else if (status === "exported") {
    where.exported = true;
  } else if (status === "errors") {
    where.exportError = { not: null };
  }
  if (!Number.isNaN(minScore) && minScore > 0) where.matchScore = { gte: minScore };

  const assignments = await prisma.assignment.findMany({
    where,
    include: { page: true, media: true },
    orderBy: [{ matchScore: "desc" }, { createdAt: "asc" }],
  });
  return NextResponse.json({ assignments });
}
