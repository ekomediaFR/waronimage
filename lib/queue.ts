import Bull from "bull";
import type { GenerationJobData } from "./generation";

export const GENERATION_QUEUE = "waronimage-generation";

let queue: Bull.Queue<GenerationJobData> | null = null;

export function hasRedis(): boolean {
  return Boolean(process.env.REDIS_URL);
}

/** Lazy Bull queue — only constructed when Redis is configured. */
export function getGenerationQueue(): Bull.Queue<GenerationJobData> {
  if (!hasRedis()) {
    throw new Error("REDIS_URL is not set — the generation queue is unavailable.");
  }
  if (!queue) {
    queue = new Bull(GENERATION_QUEUE, process.env.REDIS_URL!, {
      defaultJobOptions: {
        attempts: 2,
        backoff: { type: "exponential", delay: 5000 },
        removeOnComplete: 200,
        removeOnFail: 200,
      },
    });
  }
  return queue;
}
