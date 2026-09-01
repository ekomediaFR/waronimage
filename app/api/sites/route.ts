import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

export async function GET() {
  const sites = await prisma.site.findMany({
    orderBy: { createdAt: "desc" },
    include: { _count: { select: { pages: true, stockImages: true } } },
  });
  return NextResponse.json({ sites });
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  const { name, domain, sitemapUrl, brandColors, fontStyle, logoUrl, wpApiUrl, wpAuthToken } = body;
  if (!name || !domain || !sitemapUrl) {
    return NextResponse.json({ error: "name, domain and sitemapUrl are required" }, { status: 400 });
  }
  const site = await prisma.site.upsert({
    where: { domain },
    create: {
      name,
      domain,
      sitemapUrl,
      brandColors: brandColors ?? { primary: "#111827", secondary: "#f59e0b", accent: "#dc2626" },
      fontStyle: fontStyle ?? "sans",
      logoUrl,
      wpApiUrl,
      wpAuthToken,
    },
    update: { name, sitemapUrl, brandColors, fontStyle, logoUrl, wpApiUrl, wpAuthToken },
  });
  return NextResponse.json({ site }, { status: 201 });
}
