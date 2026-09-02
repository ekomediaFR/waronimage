import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { fetchSitemapUrls, slugFromUrl, titleFromSlug } from "@/lib/sitemap";
import { analyzePage } from "@/lib/seoMeta";
import { embedText, pageEmbeddingInput } from "@/lib/embeddings";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

const ANALYSIS_CONCURRENCY = 5;

/**
 * POST /api/sites/[siteId]/ingest
 * Fetches the site's sitemap (index-aware), analyzes each page with GPT-4o
 * (heuristic fallback without an API key), embeds it, and upserts Page rows.
 * Body: { maxPages?: number, reanalyze?: boolean }
 */
export async function POST(req: NextRequest, { params }: { params: { siteId: string } }) {
  const site = await prisma.site.findUnique({ where: { id: params.siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });
  if (!site.sitemapUrl) {
    return NextResponse.json({ error: "This site has no sitemap URL — add one in the site settings." }, { status: 400 });
  }

  const body = await req.json().catch(() => ({}));
  const maxPages: number = Math.min(Number(body.maxPages) || 500, 2000);
  const reanalyze: boolean = Boolean(body.reanalyze);

  let entries;
  try {
    entries = await fetchSitemapUrls(site.sitemapUrl);
  } catch (err) {
    return NextResponse.json({ error: `Sitemap fetch failed: ${(err as Error).message}` }, { status: 502 });
  }
  const urls = entries.slice(0, maxPages);

  const existing = await prisma.page.findMany({
    where: { siteId: site.id },
    select: { url: true },
  });
  const existingUrls = new Set(existing.map((p) => p.url));
  const todo = reanalyze ? urls : urls.filter((e) => !existingUrls.has(e.url));

  let processed = 0;
  let failed = 0;

  for (let i = 0; i < todo.length; i += ANALYSIS_CONCURRENCY) {
    const batch = todo.slice(i, i + ANALYSIS_CONCURRENCY);
    await Promise.all(
      batch.map(async (entry) => {
        try {
          const slug = slugFromUrl(entry.url);
          const title = titleFromSlug(slug);
          const analysis = await analyzePage(entry.url, title);
          const embedding = await embedText(
            pageEmbeddingInput({ title, contentSummary: analysis.contentSummary, niche: analysis.niche, city: analysis.city })
          );
          await prisma.page.upsert({
            where: { siteId_url: { siteId: site.id, url: entry.url } },
            create: {
              siteId: site.id,
              url: entry.url,
              slug,
              title,
              pageIntent: analysis.pageIntent,
              contentSummary: analysis.contentSummary,
              niche: analysis.niche,
              cpt: analysis.cpt,
              city: analysis.city,
              embedding,
            },
            update: {
              pageIntent: analysis.pageIntent,
              contentSummary: analysis.contentSummary,
              niche: analysis.niche,
              cpt: analysis.cpt,
              city: analysis.city,
              embedding,
            },
          });
          processed++;
        } catch (err) {
          console.error(`[ingest] failed for ${entry.url}:`, (err as Error).message);
          failed++;
        }
      })
    );
  }

  return NextResponse.json({
    totalInSitemap: entries.length,
    considered: urls.length,
    processed,
    skippedExisting: urls.length - todo.length,
    failed,
  });
}
