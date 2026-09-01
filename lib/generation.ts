import { prisma } from "./prisma";
import { buildPrompt, generateImageBuffer, processAndStore } from "./imageGen";
import { generateSeoMeta, kebab } from "./seoMeta";
import { cosineSimilarity, embedText, pageEmbeddingInput } from "./embeddings";

export interface GenerationJobData {
  pageId: string;
  styleId: string;
  variantIndex: number;
}

interface StyleFormats {
  ratio?: string;
  width?: number;
  height?: number;
}

/**
 * Pick the best-fitting styles for a page: hard-filter on niche,
 * then score by page-intent affinity.
 */
export async function matchStylesForPage(pageId: string, count = 2) {
  const page = await prisma.page.findUniqueOrThrow({ where: { id: pageId } });
  const styles = await prisma.imageStyle.findMany();
  const eligible = styles.filter((s) => s.niches.length === 0 || s.niches.includes(page.niche));
  const pool = eligible.length ? eligible : styles;

  const scored = pool.map((style) => {
    let score = 0;
    const d = `${style.name} ${style.description}`.toLowerCase();
    if (page.pageIntent === "local-service" && /(team|hero|action|on-site)/.test(d)) score += 2;
    if (page.pageIntent === "hub" && /(establishing|location|city|hero)/.test(d)) score += 2;
    if (page.pageIntent === "faq" && /(close-up|detail|tools)/.test(d)) score += 2;
    if (page.city && /city|location/.test(d)) score += 1;
    return { style, score };
  });
  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, count).map((s) => s.style);
}

/**
 * Full generation pipeline for one (page, style, variant):
 * prompt build → DALL-E → WebP + responsive sizes → SEO metadata → match score → save.
 */
export async function generateVariantForPage(data: GenerationJobData): Promise<string> {
  const page = await prisma.page.findUniqueOrThrow({
    where: { id: data.pageId },
    include: { site: true },
  });
  const style = await prisma.imageStyle.findUniqueOrThrow({ where: { id: data.styleId } });
  const formats = (style.formats ?? {}) as StyleFormats;
  const brandColors = (page.site.brandColors ?? {}) as { primary?: string; secondary?: string; accent?: string };

  const prompt = buildPrompt({
    template: style.promptTemplate,
    niche: page.niche,
    city: page.city,
    service: page.niche,
    contentSummary: page.contentSummary,
    brandColors,
    fontStyle: page.site.fontStyle,
  });

  const record = await prisma.aiImage.create({
    data: {
      pageId: page.id,
      styleId: style.id,
      prompt,
      imageUrl: "",
      filename: "",
      altText: "",
      title: "",
      status: "pending",
      variantIndex: data.variantIndex,
    },
  });

  try {
    const buffer = await generateImageBuffer(prompt, formats.ratio);

    const meta = await generateSeoMeta({
      niche: page.niche,
      city: page.city,
      pageTitle: page.title,
      pageIntent: page.pageIntent,
      styleName: style.name,
      index: data.variantIndex,
    });
    const filename = `${kebab(page.niche)}_${kebab(page.city || "fr")}_${kebab(page.cpt)}_${kebab(page.slug)}_${data.variantIndex}.webp`;

    const stored = await processAndStore(buffer, `ai/${page.siteId}/${page.id}`, filename);

    // Match score: page embedding vs the description of what we generated.
    let matchScore = 0;
    const pageEmbedding = page.embedding.length
      ? page.embedding
      : await embedText(pageEmbeddingInput(page));
    const imageEmbedding = await embedText(`${style.description}. ${meta.altText}. ${meta.description}`);
    if (pageEmbedding.length && imageEmbedding.length) {
      matchScore = cosineSimilarity(pageEmbedding, imageEmbedding);
    }

    await prisma.aiImage.update({
      where: { id: record.id },
      data: {
        imageUrl: stored.url,
        filename,
        altText: meta.altText,
        title: meta.title,
        caption: meta.caption,
        description: meta.description,
        matchScore,
        status: "generated",
      },
    });
    return record.id;
  } catch (err) {
    await prisma.aiImage.update({
      where: { id: record.id },
      data: { status: "rejected", description: `Generation failed: ${(err as Error).message.slice(0, 250)}` },
    });
    throw err;
  }
}
