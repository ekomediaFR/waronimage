import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { analyzeStockImage } from "@/lib/visionAnalysis";
import { embedText } from "@/lib/embeddings";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

const CONCURRENCY = 3;

/**
 * POST /api/stock/analyze
 * Body: { siteId, imageIds?: string[] } — runs GPT-4o Vision on unprocessed
 * stock images (or the given ids), embeds the analysis, updates rows.
 */
export async function POST(req: NextRequest) {
  const body = await req.json();
  const { siteId, imageIds } = body as { siteId?: string; imageIds?: string[] };
  if (!siteId && !imageIds?.length) {
    return NextResponse.json({ error: "siteId or imageIds required" }, { status: 400 });
  }

  const images = await prisma.stockImage.findMany({
    where: imageIds?.length
      ? { id: { in: imageIds } }
      : { siteId, status: "unprocessed" },
    take: 100,
  });

  let analyzed = 0;
  let failed = 0;

  for (let i = 0; i < images.length; i += CONCURRENCY) {
    const batch = images.slice(i, i + CONCURRENCY);
    await Promise.all(
      batch.map(async (img) => {
        try {
          // Vision needs a publicly reachable URL; local /uploads paths won't work for the API.
          const publicUrl = img.originalUrl.startsWith("http")
            ? img.originalUrl
            : `${process.env.NEXT_PUBLIC_APP_URL || ""}${img.originalUrl}`;
          const analysis = await analyzeStockImage(publicUrl, img.filename);
          const embedding = await embedText(`${analysis.contentDescription} ${analysis.tags.join(" ")}`);
          await prisma.stockImage.update({
            where: { id: img.id },
            data: {
              altText: analysis.suggestedAlt,
              title: analysis.suggestedTitle,
              niche: analysis.niche,
              city: analysis.city,
              tags: analysis.tags,
              quality: analysis.quality,
              embedding,
              analysisJson: analysis as object,
              status: "analyzed",
            },
          });
          analyzed++;
        } catch (err) {
          console.error(`[stock/analyze] ${img.id} failed:`, (err as Error).message);
          failed++;
        }
      })
    );
  }

  return NextResponse.json({ total: images.length, analyzed, failed });
}
