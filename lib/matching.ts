// Page ↔ media matching for the static phase: pure string similarity
// (Jaccard on token sets + slug/city/niche bonuses). No AI calls — the scorer
// is isolated so embeddings can replace computeScore() later without
// restructuring anything.

import type { WpMedia, WpPage } from "@prisma/client";
import { prisma } from "./prisma";
import { generateAssignmentSeoMeta, nicheKeywords } from "./seoMeta";
import { sanitizeText } from "./utils";

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
  // Text similarity: overlap coefficient blended with Jaccard. Pure Jaccard
  // punishes the length asymmetry between a page (title+excerpt) and an image
  // (short ALT/filename) so hard that even perfect matches never reach the
  // 0.65 auto-approve threshold; the overlap coefficient restores that.
  const intersection = [...pageTokens].filter((t) => mediaTokens.has(t));
  const union = new Set([...pageTokens, ...mediaTokens]);
  const jaccard = union.size > 0 ? intersection.length / union.size : 0;
  const minSize = Math.min(pageTokens.size, mediaTokens.size);
  const overlap = minSize > 0 ? intersection.length / minSize : 0;
  const textScore = 0.7 * overlap + 0.3 * jaccard;

  const mediaText = (media.matchTokens || "").toLowerCase();

  // Bonus: a slug word appears in the media filename or its metadata tokens
  const pageSlugWords = page.slug.split("-").filter((w) => w.length > 3);
  const mediaFilenameClean = (media.filename || "")
    .toLowerCase()
    .replace(/\.\w+$/, "")
    .replace(/[-_]/g, " ");
  const slugBonus = pageSlugWords.some((w) => mediaFilenameClean.includes(w) || mediaText.includes(w))
    ? 0.15
    : 0;

  // Bonus: city match
  const cityBonus = page.city && mediaText.includes(page.city.toLowerCase()) ? 0.1 : 0;

  // Bonus: niche match — via the niche's keyword hints in both languages
  // (page.niche holds the internal key like "moving"; media text is French).
  const nicheBonus =
    page.niche && nicheKeywords(page.niche).some((k) => mediaText.includes(k)) ? 0.1 : 0;

  const total = Math.min(1.0, textScore + slugBonus + cityBonus + nicheBonus);

  const reason = [
    `text:${textScore.toFixed(2)}`,
    `(overlap:${overlap.toFixed(2)} jaccard:${jaccard.toFixed(2)})`,
    slugBonus > 0 ? `slug:+${slugBonus}` : null,
    cityBonus > 0 ? `city:+${cityBonus}` : null,
    nicheBonus > 0 ? `niche:+${nicheBonus}` : null,
    intersection.length ? `matches:[${intersection.slice(0, 5).join(",")}]` : null,
  ]
    .filter(Boolean)
    .join(" ");
  // Tokens come from WP text; a matched token can carry a lone surrogate that
  // would break the Prisma query engine when written as matchReason.
  return { total, reason: sanitizeText(reason) };
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
      select: { pageId: true, mediaId: true, assignedBy: true, approved: true },
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
            // Same media as before — refresh the score and upgrade the approval
            // when it now clears the threshold. Never downgrade one the reviewer
            // (or a previous run) granted; edits and export status stay intact.
            matchScore: best.score.total,
            matchReason: best.score.reason,
            ...(approved && !prior?.approved ? { approved: true } : {}),
          },
    });

    result.matched++;
    if (approved) result.autoApproved++;
  }

  return result;
}
