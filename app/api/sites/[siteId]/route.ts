import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

interface Params {
  params: { siteId: string };
}

export async function GET(_req: NextRequest, { params }: Params) {
  const site = await prisma.site.findUnique({
    where: { id: params.siteId },
    include: { _count: { select: { pages: true, stockImages: true } } },
  });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });
  return NextResponse.json({ site });
}

const EDITABLE_FIELDS = [
  "name", "domain", "sitemapUrl", "brandColors", "fontStyle", "logoUrl",
  "wpApiUrl", "wpAuthMethod", "wpJwtToken", "wpAppUser", "wpAppPassword", "wpAuthToken",
  "language",
] as const;

export async function PATCH(req: NextRequest, { params }: Params) {
  const body = await req.json();
  const data: Record<string, unknown> = {};
  for (const field of EDITABLE_FIELDS) {
    if (field in body) data[field] = body[field];
  }
  const site = await prisma.site.update({ where: { id: params.siteId }, data });
  return NextResponse.json({ site });
}

export async function DELETE(_req: NextRequest, { params }: Params) {
  await prisma.site.delete({ where: { id: params.siteId } });
  return NextResponse.json({ ok: true });
}
