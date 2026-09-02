import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

/** GET — the most recent sync log of each type, newest first. */
export async function GET(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const logs = await prisma.syncLog.findMany({
    where: { siteId: params.siteId },
    orderBy: { startedAt: "desc" },
    take: 20,
  });
  return NextResponse.json({ logs });
}
