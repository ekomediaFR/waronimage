/**
 * Bull queue worker for AI image generation.
 * Run alongside `npm run dev` with: npm run worker
 * Requires REDIS_URL (and OPENAI_API_KEY / DATABASE_URL) in .env.local.
 */
import { config } from "dotenv";
config({ path: ".env.local" });
config();

import { getGenerationQueue, hasRedis } from "../lib/queue";
import { generateVariantForPage, GenerationJobData } from "../lib/generation";
import type { Job } from "bull";

const CONCURRENCY = Number(process.env.WORKER_CONCURRENCY) || 2;

async function main() {
  if (!hasRedis()) {
    console.error("REDIS_URL is not set — the worker cannot start. Add it to .env.local.");
    process.exit(1);
  }

  const queue = getGenerationQueue();

  queue.process(CONCURRENCY, async (job: Job<GenerationJobData>) => {
    console.log(`[worker] job ${job.id}: page=${job.data.pageId} style=${job.data.styleId} v${job.data.variantIndex}`);
    const aiImageId = await generateVariantForPage(job.data);
    return { aiImageId };
  });

  queue.on("completed", (job) => console.log(`[worker] job ${job.id} completed`));
  queue.on("failed", (job, err) => console.error(`[worker] job ${job.id} failed: ${err.message}`));

  console.log(`[worker] waronimage generation worker started (concurrency ${CONCURRENCY})`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
