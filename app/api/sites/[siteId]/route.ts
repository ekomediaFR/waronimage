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

export async function PATCH(req: NextRequest, { params }: Params) {
  const body = await req.json();
  const site = await prisma.site.update({ where: { id: params.siteId }, data: body });
  return NextResponse.json({ site });
}

export async function DELETE(_req: NextRequest, { params }: Params) {
  await prisma.site.delete({ where: { id: params.siteId } });
  return NextResponse.json({ ok: true });
}
