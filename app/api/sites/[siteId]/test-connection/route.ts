import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { testWpConnection } from "@/lib/wpRest";

export const dynamic = "force-dynamic";
export const maxDuration = 60;

/** POST — probe the WP REST API with the site's stored credentials. */
export async function POST(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });
  const result = await testWpConnection(site);
  return NextResponse.json(result);
}
