import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

/**
 * GET /api/sites/[siteId]/pages?niche=&cpt=&coverage=none|ai|stock|full&q=
 * Page browser data with image counts.
 */
export async function GET(req: NextRequest, { params }: { params: { siteId: string } }) {
  const sp = req.nextUrl.searchParams;
  const niche = sp.get("niche") || undefined;
  const cpt = sp.get("cpt") || undefined;
  const q = sp.get("q") || undefined;
  const coverage = sp.get("coverage") || undefined;

  const pages = await prisma.page.findMany({
    where: {
      siteId: params.siteId,
      ...(niche ? { niche } : {}),
      ...(cpt ? { cpt } : {}),
      ...(q ? { OR: [{ title: { contains: q, mode: "insensitive" } }, { url: { contains: q, mode: "insensitive" } }] } : {}),
    },
    orderBy: { url: "asc" },
    include: {
      aiImages: {
        where: { status: { in: ["generated", "approved"] } },
        orderBy: { matchScore: "desc" },
      },
      stockAssignments: {
        include: { stockImage: true },
        orderBy: { matchScore: "desc" },
      },
    },
  });

  const filtered = pages.filter((p) => {
    const hasAi = p.aiImages.length > 0;
    const hasStock = p.stockAssignments.length > 0;
    if (coverage === "none") return !hasAi && !hasStock;
    if (coverage === "ai") return hasAi;
    if (coverage === "stock") return hasStock;
    if (coverage === "full") return hasAi || hasStock;
    return true;
  });

  return NextResponse.json({
    pages: filtered.map((p) => ({ ...p, embedding: undefined })),
  });
}
