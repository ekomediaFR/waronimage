// Page ↔ media matching for the static phase: pure string similarity
// (Jaccard on token sets + slug/city/niche bonuses). No AI calls — the scorer
// is isolated so embeddings can replace computeScore() later without
// restructuring anything.

import type { WpMedia, WpPage } from "@prisma/client";
import { prisma } from "./prisma";
import { generateAssignmentSeoMeta } from "./seoMeta";

export const AUTO_APPROVE_THRESHOLD = 0.65;

const STOP_WORDS = new Set([
  "les", "des", "une", "pour", "dans", "sur", "avec", "par", "que", "qui",
  "est", "son", "ses", "leur", "tout", "plus", "mais", "pas", "vous", "nous",
  "the", "and", "for", "with", "from", "this", "that", "are", "not", "your",
  "our", "all", "can", "has", "have", "service", "services",
]);

export function tokenize(text: string): Set<string> {
  return new Set(
    text
      .toLowerCase()
      .split(/\s+/)
      .filter((t) => t.length > 2)
      .filter((t) => !STOP_WORDS.has(t))
  );
}

interface Score {
  total: number;
  reason: string;
}

export function computeScore(
  pageTokens: Set<string>,
  mediaTokens: Set<string>,
  page: Pick<WpPage, "slug" | "city" | "niche">,
  media: Pick<WpMedia, "filename" | "matchTokens">
): Score {
  // Jaccard similarity between the two token sets
  const intersection = [...pageTokens].filter((t) => mediaTokens.has(t));
  const union = new Set([...pageTokens, ...mediaTokens]);
  const jaccard = union.size > 0 ? intersection.length / union.size : 0;

  // Bonus: a slug word appears in the media filename
  const pageSlugWords = page.slug.split("-").filter((w) => w.length > 3);
  const mediaFilenameClean = (media.filename || "")
    .toLowerCase()
    .replace(/\.\w+$/, "")
    .replace(/[-_]/g, " ");
  const slugBonus = pageSlugWords.some((w) => mediaFilenameClean.includes(w)) ? 0.15 : 0;

  // Bonus: city match
  const mediaText = (media.matchTokens || "").toLowerCase();
  const cityBonus = page.city && mediaText.includes(page.city.toLowerCase()) ? 0.1 : 0;

  // Bonus: niche match
  const nicheBonus = page.niche && mediaText.includes(page.niche.toLowerCase()) ? 0.1 : 0;

  const total = Math.min(1.0, jaccard + slugBonus + cityBonus + nicheBonus);

  const reason = [
    `jaccard:${jaccard.toFixed(2)}`,
    slugBonus > 0 ? `slug:+${slugBonus}` : null,
    cityBonus > 0 ? `city:+${cityBonus}` : null,
    nicheBonus > 0 ? `niche:+${nicheBonus}` : null,
    intersection.length ? `matches:[${intersection.slice(0, 5).join(",")}]` : null,
  ]
    .filter(Boolean)
    .join(" ");

  return { total, reason };
}

export interface MatchRunResult {
  matched: number;
  autoApproved: number;
  skippedManual: number;
}

/**
 * Score every usable media item against every page and upsert one featured
 * Assignment per page (galleryPosition 0). Manual assignments are never touched.
 * Auto assignments whose media changes get fresh SEO metadata, a reset export
 * status and a recomputed approval; unchanged ones keep the reviewer's edits.
 */
export async function runMatching(siteId: string): Promise<MatchRunResult> {
  const [pages, media, existing] = await Promise.all([
    prisma.wpPage.findMany({ where: { siteId } }),
    prisma.wpMedia.findMany({ where: { siteId, isUsable: true } }),
    prisma.assignment.findMany({
      where: { siteId, assignmentType: "featured", galleryPosition: 0 },
      select: { pageId: true, mediaId: true, assignedBy: true },
    }),
  ]);

  const result: MatchRunResult = { matched: 0, autoApproved: 0, skippedManual: 0 };
  if (!pages.length || !media.length) return result;

  const existingByPage = new Map(existing.map((a) => [a.pageId, a]));
  const mediaTokens = media.map((m) => ({ media: m, tokens: tokenize(m.matchTokens || "") }));

  for (const page of pages) {
    const prior = existingByPage.get(page.id);
    if (prior?.assignedBy === "manual") {
      result.skippedManual++;
      continue;
    }

    const pageTokens = tokenize(page.matchTokens || "");
    let best: { media: WpMedia; score: Score } | null = null;
    for (const { media: m, tokens } of mediaTokens) {
      const score = computeScore(pageTokens, tokens, page, m);
      if (!best || score.total > best.score.total) best = { media: m, score };
    }
    if (!best) continue;

    const approved = best.score.total >= AUTO_APPROVE_THRESHOLD;
    const seo = generateAssignmentSeoMeta(page);
    const mediaChanged = prior ? prior.mediaId !== best.media.id : false;

    await prisma.assignment.upsert({
      where: {
        pageId_assignmentType_galleryPosition: {
          pageId: page.id,
          assignmentType: "featured",
          galleryPosition: 0,
        },
      },
      create: {
        siteId,
        pageId: page.id,
        mediaId: best.media.id,
        matchScore: best.score.total,
        matchReason: best.score.reason,
        assignedBy: "auto",
        approved,
        seoAltText: seo.seoAltText,
        seoTitle: seo.seoTitle,
        seoCaption: seo.seoCaption,
        seoDescription: seo.seoDescription,
        seoFilename: seo.seoFilename,
      },
      update: mediaChanged
        ? {
            mediaId: best.media.id,
            matchScore: best.score.total,
            matchReason: best.score.reason,
            assignedBy: "auto",
            approved,
            exported: false,
            exportedAt: null,
            exportError: null,
            seoAltText: seo.seoAltText,
            seoTitle: seo.seoTitle,
            seoCaption: seo.seoCaption,
            seoDescription: seo.seoDescription,
            seoFilename: seo.seoFilename,
          }
        : {
            // Same media as before — refresh the score, keep the reviewer's
            // approval, edits and export status intact.
            matchScore: best.score.total,
            matchReason: best.score.reason,
          },
    });

    result.matched++;
    if (approved) result.autoApproved++;
  }

  return result;
}
