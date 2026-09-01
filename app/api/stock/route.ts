import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

/** GET /api/stock?siteId=&status=&niche= — stock image grid data. */
export async function GET(req: NextRequest) {
  const sp = req.nextUrl.searchParams;
  const siteId = sp.get("siteId");
  if (!siteId) return NextResponse.json({ error: "siteId required" }, { status: 400 });

  const status = sp.get("status") || undefined;
  const niche = sp.get("niche") || undefined;

  const images = await prisma.stockImage.findMany({
    where: { siteId, ...(status ? { status } : {}), ...(niche ? { niche } : {}) },
    orderBy: { uploadedAt: "desc" },
    include: { assignments: { include: { page: { select: { id: true, title: true, url: true } } } } },
  });

  return NextResponse.json({ images: images.map((i) => ({ ...i, embedding: undefined })) });
}
