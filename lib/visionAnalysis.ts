import { chatJSON, hasOpenAI } from "./openai";

export interface VisionAnalysis {
  contentDescription: string;
  niche: string;
  subjects: string[];
  setting: string;
  city: string | null;
  quality: number;
  suggestedAlt: string;
  suggestedTitle: string;
  suggestedFilename: string;
  tags: string[];
  matchableCPTs: string[];
}

/** GPT-4o Vision analysis of a company stock photo. */
export async function analyzeStockImage(imageUrl: string, filename: string): Promise<VisionAnalysis> {
  const fallback: VisionAnalysis = {
    contentDescription: `Company photo (${filename})`,
    niche: "other",
    subjects: [],
    setting: "unknown",
    city: null,
    quality: 5,
    suggestedAlt: filename.replace(/\.[a-z0-9]+$/i, "").replace(/[-_]+/g, " "),
    suggestedTitle: filename.replace(/\.[a-z0-9]+$/i, "").replace(/[-_]+/g, " ").slice(0, 60),
    suggestedFilename: filename,
    tags: [],
    matchableCPTs: [],
  };
  if (!hasOpenAI()) return fallback;
  try {
    const result = await chatJSON<VisionAnalysis>(
      "You analyze company photos for SEO image management. Return JSON only.",
      `Analyze this company photo and return JSON only:
{
  "contentDescription": "what is literally shown in this image",
  "niche": "moving | locksmith | plumbing | storage | electrical | other",
  "subjects": ["team", "tools", "vehicle", "location", "customer", "equipment"],
  "setting": "indoor | outdoor | urban | residential | commercial",
  "city": "detected city name or null",
  "quality": 7,
  "suggestedAlt": "SEO-optimized alt text max 125 chars",
  "suggestedTitle": "SEO title max 60 chars",
  "suggestedFilename": "niche_subjects_index.webp",
  "tags": ["array", "of", "descriptive", "tags"],
  "matchableCPTs": ["which WordPress CPTs this image suits"]
}`,
      imageUrl
    );
    return { ...fallback, ...result, quality: clampQuality(result.quality) };
  } catch (err) {
    console.error("[visionAnalysis] failed:", (err as Error).message);
    return fallback;
  }
}

function clampQuality(q: unknown): number {
  const n = Number(q);
  if (!Number.isFinite(n)) return 5;
  return Math.min(10, Math.max(1, Math.round(n)));
}
