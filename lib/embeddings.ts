import { getOpenAI, hasOpenAI } from "./openai";

/** Embed a text with text-embedding-3-small. Returns [] when OpenAI is unavailable. */
export async function embedText(text: string): Promise<number[]> {
  if (!hasOpenAI() || !text.trim()) return [];
  const res = await getOpenAI().embeddings.create({
    model: "text-embedding-3-small",
    input: text.slice(0, 8000),
  });
  return res.data[0].embedding;
}

export function cosineSimilarity(a: number[], b: number[]): number {
  if (!a.length || !b.length || a.length !== b.length) return 0;
  let dot = 0;
  let magA = 0;
  let magB = 0;
  for (let i = 0; i < a.length; i++) {
    dot += a[i] * b[i];
    magA += a[i] * a[i];
    magB += b[i] * b[i];
  }
  const denom = Math.sqrt(magA) * Math.sqrt(magB);
  return denom === 0 ? 0 : dot / denom;
}

export type MatchLabel = "perfect" | "strong" | "possible" | "weak";

export function matchLabel(score: number): MatchLabel {
  if (score >= 0.85) return "perfect";
  if (score >= 0.72) return "strong";
  if (score >= 0.55) return "possible";
  return "weak";
}

export function pageEmbeddingInput(page: {
  title: string;
  contentSummary?: string | null;
  niche: string;
  city?: string | null;
}): string {
  return [page.title, page.contentSummary, page.niche, page.city].filter(Boolean).join(" ");
}
