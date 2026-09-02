// Pull ALL pages/posts/CPTs and ALL media from a WordPress site into WpPage / WpMedia.
// Non-destructive: upserts by (siteId, wpId) — a re-sync refreshes data but never
// deletes rows, so manual assignments and approvals survive.

import type { Site } from "@prisma/client";
import { prisma } from "./prisma";
import { buildAuthHeaders, discoverPostTypes, wpFetch, wpV2Base } from "./wpRest";
import { heuristicPageAnalysis } from "./seoMeta";

const PER_PAGE = 100;
const MAX_PAGES_PER_TYPE = 50; // safety: 5 000 items per post type
const MAX_MEDIA_PAGES = 200; // safety: 20 000 media items

// ─── Match token builders ─────────────────────────────────

/** lowercase, strip HTML + accents, keep [a-z0-9 -]. */
function clean(s: string): string {
  return s
    .toLowerCase()
    .replace(/<[^>]+>/g, "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9\s-]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

export function buildPageMatchTokens(title: string, excerpt: string, slug: string, type: string): string {
  return [clean(title), clean(excerpt).slice(0, 200), clean(slug.replace(/-/g, " ")), type]
    .filter(Boolean)
    .join(" ");
}

export function buildMediaMatchTokens(
  title: string,
  alt: string,
  caption: string,
  desc: string,
  filename: string
): string {
  return [
    clean(title),
    clean(alt),
    clean(caption).slice(0, 150),
    clean(desc).slice(0, 150),
    clean(filename.replace(/[-_]/g, " ").replace(/\.\w+$/, "")),
  ]
    .filter(Boolean)
    .join(" ");
}

// ─── Sync log helpers ─────────────────────────────────────

export async function startSyncLog(siteId: string, type: string): Promise<string> {
  const log = await prisma.syncLog.create({ data: { siteId, type, status: "running" } });
  return log.id;
}

export async function finishSyncLog(
  id: string,
  data: {
    status: "done" | "error";
    pagesCount?: number;
    mediaCount?: number;
    matchCount?: number;
    exportCount?: number;
    errorMsg?: string;
  }
): Promise<void> {
  await prisma.syncLog.update({ where: { id }, data: { ...data, finishedAt: new Date() } }).catch(() => undefined);
}

// ─── Pages sync ───────────────────────────────────────────

interface WpRestItem {
  id: number;
  slug?: string;
  link?: string;
  title?: { rendered?: string };
  excerpt?: { rendered?: string };
  status?: string;
  type?: string;
  featured_media?: number;
}

const PAGE_FIELDS = "id,slug,link,title,excerpt,status,type,featured_media";

/**
 * Pull every viewable post type. Tries publish+draft first (needs auth for drafts)
 * and silently falls back to publish-only when the site rejects the status filter.
 * Returns the number of upserted rows.
 */
export async function syncPages(site: Site): Promise<number> {
  const headers = buildAuthHeaders(site);
  const v2 = wpV2Base(site);
  const types = await discoverPostTypes(site);
  let totalSynced = 0;

  for (const type of types) {
    const endpoint = `${v2}/${type.restBase}`;
    let statusFilter: string | null = "publish,draft";
    let page = 1;

    while (page <= MAX_PAGES_PER_TYPE) {
      const query =
        `${endpoint}?per_page=${PER_PAGE}&page=${page}&_fields=${PAGE_FIELDS}` +
        (statusFilter ? `&status=${statusFilter}` : "");
      const res = await wpFetch(query, { headers });

      if (!res.ok) {
        // publish,draft needs edit rights — retry the first page without the filter.
        if (statusFilter && page === 1 && [400, 401, 403].includes(res.status)) {
          statusFilter = null;
          continue;
        }
        break; // past the last page (WP answers 400) or the type is unreadable
      }

      const totalPages = parseInt(res.headers.get("X-WP-TotalPages") || "1", 10);
      const items = (await res.json()) as WpRestItem[];
      if (!Array.isArray(items) || !items.length) break;

      for (const item of items) {
        const title = item.title?.rendered || "";
        const excerpt = item.excerpt?.rendered?.replace(/<[^>]+>/g, "").trim() || "";
        const slug = item.slug || "";
        const wpType = item.type || type.slug;
        const url = item.link || "";
        const matchTokens = buildPageMatchTokens(title, excerpt, slug, wpType);
        const analysis = heuristicPageAnalysis(url || slug, title);

        await prisma.wpPage.upsert({
          where: { siteId_wpId_wpType: { siteId: site.id, wpId: item.id, wpType } },
          create: {
            siteId: site.id,
            wpId: item.id,
            wpType,
            restBase: type.restBase,
            url,
            slug,
            title,
            excerpt: excerpt || null,
            status: item.status || "publish",
            currentFeaturedMediaId: item.featured_media || null,
            niche: analysis.niche !== "other" ? analysis.niche : null,
            city: analysis.city,
            pageIntent: analysis.pageIntent,
            matchTokens,
          },
          update: {
            restBase: type.restBase,
            url,
            slug,
            title,
            excerpt: excerpt || null,
            status: item.status || "publish",
            currentFeaturedMediaId: item.featured_media || null,
            matchTokens,
          },
        });
        totalSynced++;
      }

      if (page >= totalPages) break;
      page++;
    }
  }

  return totalSynced;
}

// ─── Media sync ───────────────────────────────────────────

interface WpMediaItem {
  id: number;
  source_url?: string;
  slug?: string;
  title?: { rendered?: string };
  alt_text?: string;
  caption?: { rendered?: string };
  description?: { rendered?: string };
  mime_type?: string;
  media_details?: {
    width?: number;
    height?: number;
    filesize?: number;
    sizes?: Record<string, { source_url?: string; width?: number; height?: number }>;
  };
}

const MEDIA_FIELDS = "id,source_url,slug,title,alt_text,caption,description,mime_type,media_details";

/** Pull the entire image media library. Returns the number of upserted rows. */
export async function syncMedia(site: Site): Promise<number> {
  const headers = buildAuthHeaders(site);
  const v2 = wpV2Base(site);
  let page = 1;
  let totalSynced = 0;

  while (page <= MAX_MEDIA_PAGES) {
    const res = await wpFetch(
      `${v2}/media?per_page=${PER_PAGE}&page=${page}&media_type=image&_fields=${MEDIA_FIELDS}`,
      { headers }
    );
    if (!res.ok) break;

    const totalPages = parseInt(res.headers.get("X-WP-TotalPages") || "1", 10);
    const items = (await res.json()) as WpMediaItem[];
    if (!Array.isArray(items) || !items.length) break;

    for (const item of items) {
      const sourceUrl = item.source_url || "";
      if (!sourceUrl) continue;
      const title = item.title?.rendered || "";
      const alt = item.alt_text || "";
      const caption = item.caption?.rendered?.replace(/<[^>]+>/g, "").trim() || "";
      const desc = item.description?.rendered?.replace(/<[^>]+>/g, "").trim() || "";
      const filename = sourceUrl.split("/").pop() || item.slug || `media-${item.id}`;
      const details = item.media_details || {};
      const width = details.width ?? null;
      const height = details.height ?? null;
      const sizes = details.sizes || {};
      const thumbUrl =
        sizes.medium?.source_url || sizes.thumbnail?.source_url || sizes.medium_large?.source_url || null;
      const matchTokens = buildMediaMatchTokens(title, alt, caption, desc, item.slug || filename);
      const isUsable = (width ?? 0) >= 400 && (height ?? 0) >= 225;

      await prisma.wpMedia.upsert({
        where: { siteId_wpId: { siteId: site.id, wpId: item.id } },
        create: {
          siteId: site.id,
          wpId: item.id,
          sourceUrl,
          thumbUrl,
          filename,
          mimeType: item.mime_type || "image/jpeg",
          width,
          height,
          fileSizeKb: details.filesize ? Math.round(details.filesize / 1024) : null,
          wpTitle: title || null,
          wpAltText: alt || null,
          wpCaption: caption || null,
          wpDescription: desc || null,
          matchTokens,
          isUsable,
        },
        update: {
          sourceUrl,
          thumbUrl,
          filename,
          mimeType: item.mime_type || "image/jpeg",
          width,
          height,
          fileSizeKb: details.filesize ? Math.round(details.filesize / 1024) : null,
          wpTitle: title || null,
          wpAltText: alt || null,
          wpCaption: caption || null,
          wpDescription: desc || null,
          matchTokens,
          isUsable,
        },
      });
      totalSynced++;
    }

    if (page >= totalPages) break;
    page++;
  }

  return totalSynced;
}
