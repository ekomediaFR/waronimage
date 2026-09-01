import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { hasRedis, getGenerationQueue } from "@/lib/queue";

export const dynamic = "force-dynamic";

/**
 * GET /api/generate/status?jobId=xxx — single Bull job status
 * GET /api/generate/status?siteId=xxx — aggregate generation status for a site
 */
export async function GET(req: NextRequest) {
  const jobId = req.nextUrl.searchParams.get("jobId");
  const siteId = req.nextUrl.searchParams.get("siteId");

  if (jobId) {
    if (!hasRedis()) return NextResponse.json({ error: "Queue unavailable (no REDIS_URL)" }, { status: 503 });
    const job = await getGenerationQueue().getJob(jobId);
    if (!job) return NextResponse.json({ error: "Job not found" }, { status: 404 });
    const state = await job.getState();
    return NextResponse.json({ jobId, state, data: job.data, failedReason: job.failedReason });
  }

  if (siteId) {
    const [pending, generated, approved, rejected] = await Promise.all([
      prisma.aiImage.count({ where: { status: "pending", page: { siteId } } }),
      prisma.aiImage.count({ where: { status: "generated", page: { siteId } } }),
      prisma.aiImage.count({ where: { status: "approved", page: { siteId } } }),
      prisma.aiImage.count({ where: { status: "rejected", page: { siteId } } }),
    ]);
    let queueCounts = null;
    if (hasRedis()) {
      const q = getGenerationQueue();
      queueCounts = await q.getJobCounts();
    }
    return NextResponse.json({ pending, generated, approved, rejected, queue: queueCounts });
  }

  return NextResponse.json({ error: "jobId or siteId required" }, { status: 400 });
}
