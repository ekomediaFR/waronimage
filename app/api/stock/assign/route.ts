import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { cosineSimilarity } from "@/lib/embeddings";
import { chatJSON, hasOpenAI } from "@/lib/openai";
import { kebab } from "@/lib/seoMeta";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

const STRONG_THRESHOLD = 0.72;
const MIN_THRESHOLD = 0.5;

/**
 * GET /api/stock/assign?siteId=&imageId=  — top 5 matching pages for an image
 * GET /api/stock/assign?siteId=&pageId=   — top 3 matching images for a page
 */
export async function GET(req: NextRequest) {
  const sp = req.nextUrl.searchParams;
  const siteId = sp.get("siteId");
  const imageId = sp.get("imageId");
  const pageId = sp.get("pageId");
  if (!siteId) return NextResponse.json({ error: "siteId required" }, { status: 400 });

  if (imageId) {
    const image = await prisma.stockImage.findUnique({ where: { id: imageId } });
    if (!image) return NextResponse.json({ error: "Image not found" }, { status: 404 });
    const pages = await prisma.page.findMany({ where: { siteId } });
    const matches = pages
      .map((p) => ({ page: { ...p, embedding: undefined }, score: cosineSimilarity(image.embedding, p.embedding) }))
      .sort((a, b) => b.score - a.score)
      .slice(0, 5);
    return NextResponse.json({ matches });
  }

  if (pageId) {
    const page = await prisma.page.findUnique({ where: { id: pageId } });
    if (!page) return NextResponse.json({ error: "Page not found" }, { status: 404 });
    const images = await prisma.stockImage.findMany({ where: { siteId, status: { in: ["analyzed", "assigned"] } } });
    const matches = images
      .map((img) => ({ image: { ...img, embedding: undefined }, score: cosineSimilarity(page.embedding, img.embedding) }))
      .sort((a, b) => b.score - a.score)
      .slice(0, 3);
    return NextResponse.json({ matches });
  }

  return NextResponse.json({ error: "imageId or pageId required" }, { status: 400 });
}

/**
 * POST /api/stock/assign
 * Auto mode — body: { siteId, mode: "auto", position?, threshold? }
 *   For each page without an assignment at `position`, picks the
 *   highest-scoring not-yet-assigned stock image above the threshold.
 * Manual mode — body: { siteId, mode: "manual", pageId, stockImageId, position? }
 */
export async function POST(req: NextRequest) {
  const body = await req.json();
  const { siteId, mode = "auto", position = "thumbnail" } = body;
  if (!siteId) return NextResponse.json({ error: "siteId required" }, { status: 400 });

  if (mode === "manual") {
    const { pageId, stockImageId } = body;
    if (!pageId || !stockImageId) {
      return NextResponse.json({ error: "pageId and stockImageId required for manual mode" }, { status: 400 });
    }
    const page = await prisma.page.findUniqueOrThrow({ where: { id: pageId } });
    const image = await prisma.stockImage.findUniqueOrThrow({ where: { id: stockImageId } });
    const score = cosineSimilarity(page.embedding, image.embedding);
    const assignment = await createAssignment(page, image, score, "manual", position);
    return NextResponse.json({ assignments: [assignment] });
  }

  const threshold = Number(body.threshold) || MIN_THRESHOLD;
  const pages = await prisma.page.findMany({
    where: { siteId, stockAssignments: { none: { position } } },
  });
  const images = await prisma.stockImage.findMany({
    where: { siteId, status: "analyzed", embedding: { isEmpty: false } },
  });

  const usedImageIds = new Set<string>();
  const assignments = [];

  // Greedy: process pages by their best available score, one image per page/position.
  for (const page of pages) {
    if (!page.embedding.length) continue;
    let best: { image: (typeof images)[number]; score: number } | null = null;
    for (const image of images) {
      if (usedImageIds.has(image.id)) continue;
      const score = cosineSimilarity(page.embedding, image.embedding);
      if (score >= threshold && (!best || score > best.score)) best = { image, score };
    }
    if (best) {
      usedImageIds.add(best.image.id);
      assignments.push(await createAssignment(page, best.image, best.score, "auto", position));
    }
  }

  return NextResponse.json({
    assigned: assignments.length,
    strongMatches: assignments.filter((a) => a.matchScore >= STRONG_THRESHOLD).length,
    assignments,
  });
}

async function createAssignment(
  page: { id: string; title: string; niche: string; city: string | null; cpt: string; slug: string },
  image: { id: string; altText: string | null; analysisJson: unknown },
  score: number,
  assignedBy: string,
  position: string
) {
  const altText = await pageSpecificAlt(page, image);
  const filename = `${kebab(page.niche)}_${kebab(page.city || "fr")}_${kebab(page.cpt)}_${kebab(page.slug)}.webp`;

  const assignment = await prisma.stockAssignment.upsert({
    where: { pageId_stockImageId_position: { pageId: page.id, stockImageId: image.id, position } },
    create: { pageId: page.id, stockImageId: image.id, matchScore: score, assignedBy, position, altText, filename },
    update: { matchScore: score, assignedBy, altText, filename },
  });
  await prisma.stockImage.update({ where: { id: image.id }, data: { status: "assigned", matchScore: score } });
  return assignment;
}

async function pageSpecificAlt(
  page: { title: string; niche: string; city: string | null },
  image: { altText: string | null; analysisJson: unknown }
): Promise<string> {
  const contentDescription =
    (image.analysisJson as { contentDescription?: string } | null)?.contentDescription || image.altText || "company photo";
  const fallback = `${contentDescription} — ${page.niche}${page.city ? ` in ${page.city}` : ""}`.slice(0, 125);
  if (!hasOpenAI()) return fallback;
  try {
    const res = await chatJSON<{ altText: string }>(
      "You write SEO image alt text. Return JSON only: {\"altText\": \"...\"}",
      `Given image content: ${contentDescription}
Assigned to page: ${page.title} — ${page.niche}${page.city ? ` in ${page.city}` : ""}
Generate targeted alt text for this specific page context. Max 125 chars.`
    );
    return (res.altText || fallback).slice(0, 125);
  } catch {
    return fallback;
  }
}
