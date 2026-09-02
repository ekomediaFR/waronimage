import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { finishSyncLog, startSyncLog, syncPages } from "@/lib/wpSync";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

/** POST — pull ALL pages/posts/CPTs from the WP REST API into WpPage. */
export async function POST(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const logId = await startSyncLog(site.id, "pages-sync");
  try {
    const count = await syncPages(site);
    await finishSyncLog(logId, { status: "done", pagesCount: count });
    return NextResponse.json({ synced: count });
  } catch (err) {
    const message = (err as Error).message;
    await finishSyncLog(logId, { status: "error", errorMsg: message });
    return NextResponse.json({ error: message }, { status: 502 });
  }
}
