import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { finishSyncLog, startSyncLog, syncMedia, syncPages } from "@/lib/wpSync";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

/** POST — pull pages then media in sequence. */
export async function POST(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const logId = await startSyncLog(site.id, "pages-sync");
  let pages = 0;
  try {
    pages = await syncPages(site);
    await finishSyncLog(logId, { status: "done", pagesCount: pages });
  } catch (err) {
    await finishSyncLog(logId, { status: "error", errorMsg: (err as Error).message });
    return NextResponse.json({ error: (err as Error).message, pages: 0, media: 0 }, { status: 502 });
  }

  const mediaLogId = await startSyncLog(site.id, "media-sync");
  try {
    const media = await syncMedia(site);
    await finishSyncLog(mediaLogId, { status: "done", mediaCount: media });
    return NextResponse.json({ pages, media });
  } catch (err) {
    await finishSyncLog(mediaLogId, { status: "error", errorMsg: (err as Error).message });
    return NextResponse.json({ error: (err as Error).message, pages, media: 0 }, { status: 502 });
  }
}
