import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

/** GET — everything the Sync & Match tab needs: stats, pages (with their featured match) and media. */
export async function GET(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const siteId = params.siteId;

  const [pagesCount, mediaCount, assigned, approved, exported, pages, media, lastLogs] = await Promise.all([
    prisma.wpPage.count({ where: { siteId } }),
    prisma.wpMedia.count({ where: { siteId } }),
    prisma.assignment.count({ where: { siteId } }),
    prisma.assignment.count({ where: { siteId, approved: true } }),
    prisma.assignment.count({ where: { siteId, exported: true } }),
    prisma.wpPage.findMany({
      where: { siteId },
      orderBy: [{ wpType: "asc" }, { title: "asc" }],
      include: {
        assignments: {
          where: { assignmentType: "featured", galleryPosition: 0 },
          include: { media: { select: { id: true, thumbUrl: true, sourceUrl: true, filename: true } } },
        },
      },
      take: 1000,
    }),
    prisma.wpMedia.findMany({
      where: { siteId },
      orderBy: { createdAt: "asc" },
      include: { _count: { select: { assignments: true } } },
      take: 1000,
    }),
    prisma.syncLog.findMany({ where: { siteId }, orderBy: { startedAt: "desc" }, take: 5 }),
  ]);

  return NextResponse.json({
    stats: { pagesCount, mediaCount, assigned, approved, exported, pending: assigned - approved },
    pages: pages.map((p) => {
      const a = p.assignments[0];
      return {
        id: p.id,
        wpId: p.wpId,
        wpType: p.wpType,
        title: p.title,
        slug: p.slug,
        url: p.url,
        status: p.status,
        niche: p.niche,
        city: p.city,
        currentFeaturedMediaId: p.currentFeaturedMediaId,
        assignment: a
          ? {
              id: a.id,
              matchScore: a.matchScore,
              approved: a.approved,
              exported: a.exported,
              assignedBy: a.assignedBy,
              media: a.media,
            }
          : null,
      };
    }),
    media: media.map((m) => ({
      id: m.id,
      wpId: m.wpId,
      thumbUrl: m.thumbUrl,
      sourceUrl: m.sourceUrl,
      filename: m.filename,
      width: m.width,
      height: m.height,
      isUsable: m.isUsable,
      usageCount: m._count.assignments,
    })),
    lastLogs,
  });
}
