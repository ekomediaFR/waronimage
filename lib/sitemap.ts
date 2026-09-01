import { parseStringPromise } from "xml2js";

export interface SitemapEntry {
  url: string;
  lastmod?: string;
}

const MAX_CHILD_SITEMAPS = 50;
const IGNORED_SITEMAP_PATTERNS = [/image/i, /video/i, /news/i, /attachment/i, /\.pdf$/i];

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type XmlDoc = any;

async function fetchXml(url: string): Promise<XmlDoc> {
  const res = await fetch(url, {
    headers: { "User-Agent": "waronimage/1.0 (+https://waronimage.vercel.app)" },
    // sitemaps can be slow; Next extends fetch with caching we don't want here
    cache: "no-store",
  });
  if (!res.ok) throw new Error(`Failed to fetch sitemap ${url}: HTTP ${res.status}`);
  const text = await res.text();
  return parseStringPromise(text, { explicitArray: true, trim: true });
}

/**
 * Fetch a sitemap URL and return all page URLs.
 * Supports <urlset> and <sitemapindex> (recursively), dedupes URLs,
 * and skips media/attachment child sitemaps.
 */
export async function fetchSitemapUrls(sitemapUrl: string): Promise<SitemapEntry[]> {
  const seen = new Map<string, SitemapEntry>();
  await collect(sitemapUrl, seen, 0);
  return Array.from(seen.values());
}

async function collect(url: string, seen: Map<string, SitemapEntry>, depth: number): Promise<void> {
  if (depth > 2) return;
  const doc = await fetchXml(url);

  if (doc?.sitemapindex?.sitemap) {
    const children: string[] = doc.sitemapindex.sitemap
      .map((s: XmlDoc) => s.loc?.[0])
      .filter(Boolean)
      .filter((loc: string) => !IGNORED_SITEMAP_PATTERNS.some((p) => p.test(loc)))
      .slice(0, MAX_CHILD_SITEMAPS);
    for (const child of children) {
      try {
        await collect(child, seen, depth + 1);
      } catch (err) {
        console.error(`[sitemap] skipping child ${child}:`, (err as Error).message);
      }
    }
    return;
  }

  if (doc?.urlset?.url) {
    for (const entry of doc.urlset.url) {
      const loc: string | undefined = entry.loc?.[0];
      if (!loc) continue;
      if (/\.(jpg|jpeg|png|gif|webp|svg|pdf|mp4)$/i.test(loc)) continue;
      if (!seen.has(loc)) {
        seen.set(loc, { url: loc, lastmod: entry.lastmod?.[0] });
      }
    }
  }
}

export function slugFromUrl(url: string): string {
  try {
    const { pathname } = new URL(url);
    const parts = pathname.split("/").filter(Boolean);
    return parts[parts.length - 1] || "home";
  } catch {
    return "home";
  }
}

export function titleFromSlug(slug: string): string {
  return slug
    .replace(/[-_]+/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase())
    .trim();
}
