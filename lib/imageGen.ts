import sharp from "sharp";
import { getOpenAI } from "./openai";
import { storeImage } from "./supabase";

export interface GeneratedImage {
  url: string;
  urls: { full: string; medium: string; thumb: string };
}

type DalleSize = "1792x1024" | "1024x1024" | "1024x1792";

function pickSize(ratio?: string): DalleSize {
  if (ratio === "1:1") return "1024x1024";
  if (ratio === "9:16" || ratio === "2:3") return "1024x1792";
  return "1792x1024"; // 16:9 default
}

/** Generate one image with DALL-E 3 / gpt-image-1 and return the raw PNG buffer. */
export async function generateImageBuffer(prompt: string, ratio?: string): Promise<Buffer> {
  const openai = getOpenAI();
  const model = process.env.OPENAI_IMAGE_MODEL || "dall-e-3";
  const res = await openai.images.generate({
    model,
    prompt,
    n: 1,
    size: pickSize(ratio),
    ...(model === "dall-e-3" ? { quality: "hd" as const, style: "natural" as const } : {}),
    response_format: "b64_json",
  });
  const b64 = res.data?.[0]?.b64_json;
  if (!b64) throw new Error("Image generation returned no data");
  return Buffer.from(b64, "base64");
}

/**
 * Convert to WebP, produce responsive sizes (1280w / 800w / 400w),
 * store all of them and return public URLs.
 */
export async function processAndStore(
  buffer: Buffer,
  storageDir: string,
  filename: string
): Promise<GeneratedImage> {
  const base = filename.replace(/\.webp$/i, "");
  const sizes = [
    { key: "full" as const, width: 1280, suffix: "" },
    { key: "medium" as const, width: 800, suffix: "-800w" },
    { key: "thumb" as const, width: 400, suffix: "-400w" },
  ];
  const urls: Record<string, string> = {};
  for (const size of sizes) {
    const webp = await sharp(buffer)
      .resize({ width: size.width, withoutEnlargement: true })
      .webp({ quality: 82 })
      .toBuffer();
    urls[size.key] = await storeImage(webp, `${storageDir}/${base}${size.suffix}.webp`);
  }
  return { url: urls.full, urls: urls as GeneratedImage["urls"] };
}

interface PromptContext {
  template: string;
  niche: string;
  city?: string | null;
  service?: string | null;
  contentSummary?: string | null;
  brandColors: { primary?: string; secondary?: string; accent?: string };
  fontStyle: string;
}

/** Fill the style template and append brand + context guidance. */
export function buildPrompt(ctx: PromptContext): string {
  const prompt = ctx.template
    .replaceAll("{niche}", ctx.niche)
    .replaceAll("{city}", ctx.city || "France")
    .replaceAll("{service}", ctx.service || ctx.niche)
    .replaceAll("{primary_color}", ctx.brandColors.primary || "#1a1a1a")
    .replaceAll("{brand_colors}", [ctx.brandColors.primary, ctx.brandColors.secondary].filter(Boolean).join(", "));

  const brandBits = [
    ctx.brandColors.primary &&
      `Color palette: primary ${ctx.brandColors.primary}${ctx.brandColors.secondary ? `, secondary ${ctx.brandColors.secondary}` : ""}.`,
    ctx.fontStyle && `Font style: ${ctx.fontStyle}.`,
    ctx.contentSummary && `Page context: ${ctx.contentSummary}`,
    "No text or lettering in the image. Photorealistic, professional quality.",
  ].filter(Boolean);

  return `${prompt} ${brandBits.join(" ")}`.trim();
}
