import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { exportImageToWordPress } from "@/lib/wpExport";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

/**
 * POST /api/export/wordpress
 * Body: { siteId, aiImageIds?: string[], assignmentIds?: string[] }
 * Exports approved images to the site's WordPress media library,
 * sets SEO metadata and (when the page has a wpPostId) the featured image.
 */
export async function POST(req: NextRequest) {
  const body = await req.json();
  const { siteId, aiImageIds = [], assignmentIds = [] } = body;

  const site = await prisma.site.findUnique({ where: { id: siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });
  if (!site.wpApiUrl || !site.wpAuthToken) {
    return NextResponse.json(
      { error: "This site has no WordPress credentials (wpApiUrl / wpAuthToken). Edit the site first." },
      { status: 400 }
    );
  }
  const wp = { wpApiUrl: site.wpApiUrl, wpAuthToken: site.wpAuthToken };

  const results: { type: string; id: string; ok: boolean; mediaId?: number; error?: string }[] = [];

  const aiImages = await prisma.aiImage.findMany({
    where: aiImageIds.length ? { id: { in: aiImageIds } } : { status: "approved", exportStatus: null, page: { siteId } },
    include: { page: true },
  });
  for (const img of aiImages) {
    try {
      const res = await exportImageToWordPress(
        wp,
        { url: absoluteUrl(img.imageUrl), filename: img.filename, altText: img.altText, title: img.title, caption: img.caption, description: img.description },
        img.page.wpPostId,
        img.page.cpt === "post" ? "posts" : "pages"
      );
      await prisma.aiImage.update({
        where: { id: img.id },
        data: { exportedMediaId: res.mediaId, exportStatus: "exported" },
      });
      results.push({ type: "ai", id: img.id, ok: true, mediaId: res.mediaId });
    } catch (err) {
      await prisma.aiImage.update({ where: { id: img.id }, data: { exportStatus: "failed" } });
      results.push({ type: "ai", id: img.id, ok: false, error: (err as Error).message });
    }
  }

  const assignments = await prisma.stockAssignment.findMany({
    where: assignmentIds.length
      ? { id: { in: assignmentIds } }
      : { approved: true, exportStatus: null, page: { siteId } },
    include: { page: true, stockImage: true },
  });
  for (const asg of assignments) {
    try {
      const res = await exportImageToWordPress(
        wp,
        {
          url: absoluteUrl(asg.stockImage.originalUrl),
          filename: asg.filename,
          altText: asg.altText,
          title: asg.stockImage.title || asg.page.title,
          caption: asg.stockImage.caption,
          description: asg.stockImage.description,
        },
        asg.page.wpPostId,
        asg.page.cpt === "post" ? "posts" : "pages"
      );
      await prisma.stockAssignment.update({
        where: { id: asg.id },
        data: { exportedMediaId: res.mediaId, exportStatus: "exported" },
      });
      results.push({ type: "stock", id: asg.id, ok: true, mediaId: res.mediaId });
    } catch (err) {
      await prisma.stockAssignment.update({ where: { id: asg.id }, data: { exportStatus: "failed" } });
      results.push({ type: "stock", id: asg.id, ok: false, error: (err as Error).message });
    }
  }

  return NextResponse.json({
    exported: results.filter((r) => r.ok).length,
    failed: results.filter((r) => !r.ok).length,
    results,
  });
}

function absoluteUrl(url: string): string {
  if (url.startsWith("http")) return url;
  return `${process.env.NEXT_PUBLIC_APP_URL || "http://localhost:3000"}${url}`;
}
