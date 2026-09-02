import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { bulkExport } from "@/lib/wpBulkExport";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

/**
 * POST — bulk export all approved, unexported assignments to WordPress.
 * Body: { limit? } to process a batch (useful for very large sites).
 * Only approved assignments are ever exported.
 */
export async function POST(req: NextRequest, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const body = await req.json().catch(() => ({}));
  const limit = Number(body.limit) > 0 ? Number(body.limit) : undefined;

  try {
    const result = await bulkExport(site.id, limit);
    return NextResponse.json(result);
  } catch (err) {
    return NextResponse.json({ error: (err as Error).message }, { status: 500 });
  }
}
