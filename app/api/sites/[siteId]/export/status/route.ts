import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

/**
 * GET — live export progress. Counts come straight from the Assignment table,
 * which the bulk exporter updates row by row, so polling this while an export
 * runs shows real progress.
 */
export async function GET(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const siteId = params.siteId;
  const [approved, exported, pending, errors, lastLog] = await Promise.all([
    prisma.assignment.count({ where: { siteId, approved: true } }),
    prisma.assignment.count({ where: { siteId, approved: true, exported: true } }),
    prisma.assignment.count({ where: { siteId, approved: true, exported: false } }),
    prisma.assignment.findMany({
      where: { siteId, exportError: { not: null } },
      include: { page: { select: { title: true, url: true } } },
      orderBy: { updatedAt: "desc" },
      take: 50,
    }),
    prisma.syncLog.findFirst({
      where: { siteId, type: "bulk-export" },
      orderBy: { startedAt: "desc" },
    }),
  ]);

  return NextResponse.json({
    approved,
    exported,
    pending,
    running: lastLog?.status === "running",
    lastLog,
    errors: errors.map((a) => ({
      assignmentId: a.id,
      pageTitle: a.page.title,
      pageUrl: a.page.url,
      error: a.exportError,
    })),
  });
}
