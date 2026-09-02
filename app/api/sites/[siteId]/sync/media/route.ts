import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { finishSyncLog, startSyncLog, syncMedia } from "@/lib/wpSync";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

/** POST — pull ALL images from the WP Media Library into WpMedia. */
export async function POST(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const logId = await startSyncLog(site.id, "media-sync");
  try {
    const count = await syncMedia(site);
    await finishSyncLog(logId, { status: "done", mediaCount: count });
    return NextResponse.json({ synced: count });
  } catch (err) {
    const message = (err as Error).message;
    await finishSyncLog(logId, { status: "error", errorMsg: message });
    return NextResponse.json({ error: message }, { status: 502 });
  }
}
