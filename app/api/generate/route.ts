import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { hasRedis, getGenerationQueue } from "@/lib/queue";
import { hasOpenAI } from "@/lib/openai";
import { matchStylesForPage, generateVariantForPage, GenerationJobData } from "@/lib/generation";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

const VARIANTS_PER_PAGE = 3;

/**
 * POST /api/generate
 * Body: { pageIds: string[], styleId?: string, variants?: number }
 * Enqueues generation jobs on Bull (Redis). Without Redis, runs inline
 * (dev/serverless fallback) so the feature still works end to end.
 */
export async function POST(req: NextRequest) {
  if (!hasOpenAI()) {
    return NextResponse.json(
      { error: "OPENAI_API_KEY is not configured — image generation is unavailable." },
      { status: 503 }
    );
  }

  const body = await req.json();
  const pageIds: string[] = body.pageIds || (body.pageId ? [body.pageId] : []);
  const variants: number = Math.min(Number(body.variants) || VARIANTS_PER_PAGE, 4);
  if (!pageIds.length) {
    return NextResponse.json({ error: "pageIds is required" }, { status: 400 });
  }

  const jobs: GenerationJobData[] = [];
  for (const pageId of pageIds) {
    const styles = body.styleId
      ? await prisma.imageStyle.findMany({ where: { id: body.styleId } })
      : await matchStylesForPage(pageId, 2);
    if (!styles.length) continue;
    for (let v = 1; v <= variants; v++) {
      jobs.push({ pageId, styleId: styles[(v - 1) % styles.length].id, variantIndex: v });
    }
  }

  if (hasRedis()) {
    const queue = getGenerationQueue();
    const queued = await Promise.all(jobs.map((data) => queue.add(data)));
    return NextResponse.json({ mode: "queued", jobIds: queued.map((j) => String(j.id)), count: jobs.length });
  }

  // Inline fallback — no Redis available (limit to protect route duration).
  const inlineJobs = jobs.slice(0, 4);
  const results: { pageId: string; ok: boolean; error?: string }[] = [];
  for (const job of inlineJobs) {
    try {
      await generateVariantForPage(job);
      results.push({ pageId: job.pageId, ok: true });
    } catch (err) {
      results.push({ pageId: job.pageId, ok: false, error: (err as Error).message });
    }
  }
  return NextResponse.json({
    mode: "inline",
    note: jobs.length > inlineJobs.length ? `Processed ${inlineJobs.length}/${jobs.length} — set REDIS_URL for full batches.` : undefined,
    results,
  });
}
