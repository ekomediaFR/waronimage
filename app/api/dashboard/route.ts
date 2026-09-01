import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

/** GET /api/dashboard?siteId= — analytics metrics (all sites when omitted). */
export async function GET(req: NextRequest) {
  const siteId = req.nextUrl.searchParams.get("siteId") || undefined;
  const pageWhere = siteId ? { siteId } : {};

  const pages = await prisma.page.findMany({
    where: pageWhere,
    include: {
      aiImages: { where: { status: { in: ["generated", "approved"] } }, select: { matchScore: true } },
      stockAssignments: { select: { matchScore: true } },
    },
  });

  const totalPages = pages.length;
  const withAi = pages.filter((p) => p.aiImages.length > 0).length;
  const withStock = pages.filter((p) => p.stockAssignments.length > 0).length;
  const withNone = pages.filter((p) => !p.aiImages.length && !p.stockAssignments.length);

  const allScores = pages.flatMap((p) => [
    ...p.aiImages.map((i) => i.matchScore),
    ...p.stockAssignments.map((a) => a.matchScore),
  ]);
  const avgScore = allScores.length ? allScores.reduce((a, b) => a + b, 0) / allScores.length : 0;

  const byNiche: Record<string, { total: number; covered: number }> = {};
  const byCpt: Record<string, { total: number; covered: number }> = {};
  for (const p of pages) {
    const covered = p.aiImages.length > 0 || p.stockAssignments.length > 0;
    byNiche[p.niche] = byNiche[p.niche] || { total: 0, covered: 0 };
    byNiche[p.niche].total++;
    if (covered) byNiche[p.niche].covered++;
    byCpt[p.cpt] = byCpt[p.cpt] || { total: 0, covered: 0 };
    byCpt[p.cpt].total++;
    if (covered) byCpt[p.cpt].covered++;
  }

  const worstPages = pages
    .map((p) => {
      const scores = [...p.aiImages.map((i) => i.matchScore), ...p.stockAssignments.map((a) => a.matchScore)];
      return { id: p.id, title: p.title, url: p.url, niche: p.niche, bestScore: scores.length ? Math.max(...scores) : 0 };
    })
    .sort((a, b) => a.bestScore - b.bestScore)
    .slice(0, 10);

  const pendingApproval = await prisma.aiImage.count({
    where: { status: "generated", ...(siteId ? { page: { siteId } } : {}) },
  });

  const qualityRows = await prisma.stockImage.groupBy({
    by: ["quality"],
    where: { ...(siteId ? { siteId } : {}), quality: { not: null } },
    _count: true,
  });

  return NextResponse.json({
    totalPages,
    withAi,
    withStock,
    withNone: withNone.length,
    noImagePages: withNone.slice(0, 20).map((p) => ({ id: p.id, title: p.title, url: p.url })),
    avgScore,
    byNiche,
    byCpt,
    worstPages,
    pendingApproval,
    qualityHistogram: qualityRows.map((r) => ({ quality: r.quality, count: r._count })),
  });
}
