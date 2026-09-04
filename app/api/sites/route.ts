import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { apiError } from "@/lib/apiErrors";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const sites = await prisma.site.findMany({
      orderBy: { createdAt: "desc" },
      include: {
        _count: { select: { pages: true, stockImages: true, wpPages: true, wpMedia: true, assignments: true } },
      },
    });
    return NextResponse.json({ sites });
  } catch (err) {
    return apiError(err);
  }
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  const {
    name,
    domain,
    sitemapUrl,
    brandColors,
    fontStyle,
    logoUrl,
    wpApiUrl,
    wpAuthMethod,
    wpJwtToken,
    wpAppUser,
    wpAppPassword,
    wpAuthToken,
    language,
  } = body;
  if (!name || !domain) {
    return NextResponse.json({ error: "name and domain are required" }, { status: 400 });
  }
  const wpFields = {
    wpApiUrl: wpApiUrl || null,
    wpAuthMethod: wpAuthMethod || "jwt",
    wpJwtToken: wpJwtToken || null,
    wpAppUser: wpAppUser || null,
    wpAppPassword: wpAppPassword || null,
    wpAuthToken: wpAuthToken || null,
    language: language || "fr",
  };
  try {
    const site = await prisma.site.upsert({
      where: { domain },
      create: {
        name,
        domain,
        sitemapUrl: sitemapUrl || null,
        brandColors: brandColors ?? undefined,
        fontStyle: fontStyle ?? "sans",
        logoUrl,
        ...wpFields,
      },
      update: {
        name,
        ...(sitemapUrl !== undefined ? { sitemapUrl: sitemapUrl || null } : {}),
        ...(brandColors !== undefined ? { brandColors } : {}),
        ...(fontStyle !== undefined ? { fontStyle } : {}),
        ...(logoUrl !== undefined ? { logoUrl } : {}),
        ...wpFields,
      },
    });
    return NextResponse.json({ site }, { status: 201 });
  } catch (err) {
    return apiError(err);
  }
}
