import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { runMatching } from "@/lib/matching";
import { finishSyncLog, startSyncLog } from "@/lib/wpSync";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

/** POST — run the matching algorithm on all synced pages + media. */
export async function POST(_req: NextRequest, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });

  const logId = await startSyncLog(site.id, "match-run");
  try {
    const result = await runMatching(site.id);
    await finishSyncLog(logId, { status: "done", matchCount: result.matched });
    return NextResponse.json(result);
  } catch (err) {
    const message = (err as Error).message;
    await finishSyncLog(logId, { status: "error", errorMsg: message });
    return NextResponse.json({ error: message }, { status: 500 });
  }
}
