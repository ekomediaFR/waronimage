import { chatJSON, hasOpenAI } from "./openai";

export interface PageAnalysis {
  pageIntent: string;
  contentSummary: string;
  niche: string;
  cpt: string;
  city: string | null;
}

export interface SeoImageMeta {
  filename: string;
  altText: string;
  title: string;
  caption: string;
  description: string;
}

/** kebab-case, ASCII only, no accents — for SEO filenames. */
export function kebab(input: string): string {
  return input
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

export function seoFilename(parts: (string | null | undefined)[], ext = "webp"): string {
  const base = parts.filter(Boolean).map((p) => kebab(String(p))).filter(Boolean).join("_");
  return `${base || "image"}.${ext}`;
}

const NICHE_HINTS: Record<string, string[]> = {
  moving: ["demenagement", "demenageur", "moving", "mover", "umzug"],
  locksmith: ["serrurier", "serrurerie", "locksmith", "depannage-serrure"],
  plumbing: ["plombier", "plomberie", "plumbing", "plumber", "fuite"],
  storage: ["garde-meuble", "stockage", "storage", "self-storage", "box"],
  electrical: ["electricien", "electricite", "electrical", "electrician"],
  cleaning: ["nettoyage", "cleaning", "menage"],
};

/** Heuristic fallback when OpenAI is not configured: derive metadata from the URL. */
export function heuristicPageAnalysis(url: string, title: string): PageAnalysis {
  const lower = url.toLowerCase();
  let niche = "other";
  for (const [key, hints] of Object.entries(NICHE_HINTS)) {
    if (hints.some((h) => lower.includes(h))) {
      niche = key;
      break;
    }
  }
  const isFaq = /faq|questions/i.test(lower);
  const isBlog = /\/blog\/|\/actualites\//i.test(lower);
  const cityMatch = lower.match(/(?:-a-|-in-|_)([a-z-]+?)(?:-\d+e?)?\/?$/);
  return {
    pageIntent: isFaq ? "faq" : isBlog ? "blog" : "landing",
    contentSummary: `Page about ${title}.`,
    niche,
    cpt: isBlog ? "post" : "page",
    city: cityMatch ? cityMatch[1].replace(/-/g, " ") : null,
  };
}

/** GPT-4o page analysis per the ingestion spec, with heuristic fallback. */
export async function analyzePage(url: string, title: string, meta?: string | null): Promise<PageAnalysis> {
  if (!hasOpenAI()) return heuristicPageAnalysis(url, title);
  try {
    const result = await chatJSON<PageAnalysis>(
      "You are an SEO expert analyzing a WordPress page. Return JSON only.",
      `Given:
- URL: ${url}
- Title: ${title}
- Meta description: ${meta || "n/a"}

Extract and return JSON only:
{
  "pageIntent": "local-service | hub | corridor | faq | category | landing | blog",
  "contentSummary": "2-sentence plain English description of what this page is about",
  "niche": "moving | locksmith | plumbing | storage | electrical | cleaning | other",
  "cpt": "page | post | service | city | corridor",
  "city": "city name or null"
}`
    );
    return {
      pageIntent: result.pageIntent || "landing",
      contentSummary: result.contentSummary || `Page about ${title}.`,
      niche: result.niche || "other",
      cpt: result.cpt || "page",
      city: result.city ?? null,
    };
  } catch (err) {
    console.error("[seoMeta] analyzePage failed, using heuristic:", (err as Error).message);
    return heuristicPageAnalysis(url, title);
  }
}

interface SeoMetaContext {
  niche: string;
  city?: string | null;
  pageTitle: string;
  pageIntent: string;
  styleName?: string;
  contentDescription?: string;
  index?: number;
}

/** Generate the 5 required SEO fields for an image, with a deterministic fallback. */
export async function generateSeoMeta(ctx: SeoMetaContext): Promise<SeoImageMeta> {
  const fallback: SeoImageMeta = {
    filename: seoFilename([ctx.niche, ctx.city, ctx.pageTitle, String(ctx.index ?? 1)]),
    altText: truncate(
      `Professional ${ctx.niche} service${ctx.city ? ` in ${ctx.city}` : ""} — ${ctx.pageTitle}`,
      125
    ),
    title: truncate(`${cap(ctx.niche)} ${ctx.city ?? ""}`.trim() || ctx.pageTitle, 60),
    caption: truncate(`${ctx.pageTitle}${ctx.city ? ` — ${ctx.city}` : ""}.`, 160),
    description: truncate(
      `Image for the page "${ctx.pageTitle}" (${ctx.pageIntent}). ${cap(ctx.niche)} service${ctx.city ? ` in ${ctx.city}` : ""}.`,
      300
    ),
  };
  if (!hasOpenAI()) return fallback;
  try {
    const result = await chatJSON<SeoImageMeta>(
      "You are an SEO expert generating image metadata for a WordPress media library. Return JSON only.",
      `Given this image was generated for a page about:
- Niche: ${ctx.niche}
- City: ${ctx.city || "n/a"}
- Page title: ${ctx.pageTitle}
- Page intent: ${ctx.pageIntent}
- Image style: ${ctx.styleName || "n/a"}
- Image content: ${ctx.contentDescription || "n/a"}

Generate SEO metadata in JSON:
{
  "filename": "kebab-case-with-underscores like ${ctx.niche}_${kebab(ctx.city || "city")}_service_${ctx.index ?? 1}.webp — ascii only, no accents",
  "altText": "max 125 chars, descriptive, includes niche + city + action",
  "title": "max 60 chars, keyword-rich",
  "caption": "max 160 chars, 1 sentence, human-readable context",
  "description": "max 300 chars, 2 sentences, for media library description"
}`
    );
    return {
      filename: sanitizeFilename(result.filename) || fallback.filename,
      altText: truncate(result.altText || fallback.altText, 125),
      title: truncate(result.title || fallback.title, 60),
      caption: truncate(result.caption || fallback.caption, 160),
      description: truncate(result.description || fallback.description, 300),
    };
  } catch (err) {
    console.error("[seoMeta] generateSeoMeta failed, using fallback:", (err as Error).message);
    return fallback;
  }
}

// ─── Static-phase SEO metadata for WP assignments ─────────
// Pure rules, no AI call — generateSeoMeta() above is the drop-in AI
// replacement once the OpenAI phase is enabled for assignments too.

/** French display labels for the internal niche keys. */
const NICHE_LABELS_FR: Record<string, string> = {
  moving: "déménagement",
  locksmith: "serrurerie",
  plumbing: "plomberie",
  storage: "garde-meuble",
  electrical: "électricité",
  cleaning: "nettoyage",
};

const KNOWN_CITIES = [
  "paris", "lyon", "marseille", "bordeaux", "nantes", "toulouse",
  "nice", "rennes", "strasbourg", "montpellier", "lille", "grenoble",
  "toulon", "angers", "dijon", "reims", "villeurbanne", "clermont-ferrand",
];

/** All keywords (FR + EN) associated with a niche key, for cross-language matching. */
export function nicheKeywords(nicheKey: string): string[] {
  const hints = NICHE_HINTS[nicheKey] || [];
  const label = NICHE_LABELS_FR[nicheKey];
  return [nicheKey.toLowerCase(), ...(label ? [kebab(label)] : []), ...hints];
}

/** Niche key from a token string, using the same hints as page analysis. */
export function detectNiche(tokens: string): string | null {
  const lower = tokens.toLowerCase();
  for (const [key, hints] of Object.entries(NICHE_HINTS)) {
    if (hints.some((h) => lower.includes(h))) return key;
  }
  return null;
}

/** City name from a token string. */
export function detectCity(tokens: string): string | null {
  const lower = tokens.toLowerCase();
  return KNOWN_CITIES.find((c) => lower.includes(c)) || null;
}

export interface AssignmentSeoMeta {
  seoAltText: string;
  seoTitle: string;
  seoCaption: string;
  seoDescription: string;
  seoFilename: string;
}

interface AssignmentSeoPage {
  title: string;
  slug: string;
  excerpt?: string | null;
  metaDesc?: string | null;
  wpType: string;
  niche?: string | null;
  city?: string | null;
  matchTokens?: string | null;
}

/** Rule-based SEO metadata for a media ↔ page assignment (French output). */
export function generateAssignmentSeoMeta(page: AssignmentSeoPage): AssignmentSeoMeta {
  const nicheKey = page.niche || detectNiche(page.matchTokens || "");
  const nicheFr = nicheKey ? NICHE_LABELS_FR[nicheKey] || nicheKey : null;
  const cityRaw = page.city || detectCity(page.matchTokens || "");
  const city = cityRaw ? cityRaw.split(/[\s-]/).map(cap).join("-") : null;
  const cpt = page.wpType;

  const seoAltText = truncate(
    nicheFr || city
      ? `${cap(nicheFr || "Service")}${city ? ` à ${city}` : ""} — ${page.title}`.trim()
      : page.title,
    125
  );

  const seoTitle = truncate(
    city ? `${nicheFr ? `${cap(nicheFr)} ` : ""}${city} — ${page.title}` : page.title,
    60
  );

  const seoCaption = truncate(
    `${page.title}${city ? ` à ${city}` : ""}${nicheFr ? ` — ${nicheFr}` : ""}`,
    160
  );

  const seoDescription = truncate(
    page.metaDesc ||
      page.excerpt ||
      `${page.title}. ${nicheFr ? `Service de ${nicheFr}` : "Service"} ${city ? `à ${city}` : "en France"}.`,
    300
  );

  const filenameParts = [nicheFr, cityRaw, cpt !== "page" && cpt !== "post" ? cpt : null,
    page.slug.split("-").slice(0, 4).join("-")]
    .filter(Boolean)
    .map((p) => kebab(String(p)))
    .filter(Boolean);
  const seoFilename = `${(filenameParts.join("-") || "image").slice(0, 55).replace(/-+$/, "")}.webp`;

  return { seoAltText, seoTitle, seoCaption, seoDescription, seoFilename };
}

function sanitizeFilename(name?: string): string | null {
  if (!name) return null;
  const noExt = name.replace(/\.[a-z0-9]+$/i, "");
  const clean = kebab(noExt).replace(/-/g, "_");
  return clean ? `${clean}.webp` : null;
}

function truncate(s: string, max: number): string {
  return s.length <= max ? s : s.slice(0, max - 1).trimEnd() + "…";
}

function cap(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
