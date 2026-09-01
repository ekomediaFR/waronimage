import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { exportImageToWordPress } from "@/lib/wpExport";
import { pingPlugin, uploadWpImage } from "@/lib/wpPlugin";

export const dynamic = "force-dynamic";
export const maxDuration = 300;

interface ExportableImage {
  url: string;
  filename: string;
  altText: string;
  title: string;
  caption?: string | null;
  description?: string | null;
}

/**
 * POST /api/export/wordpress
 * Body: { siteId, aiImageIds?: string[], assignmentIds?: string[] }
 * Exports approved images to the site's WordPress media library.
 * Prefers the War on Image Connect plugin (API key), falls back to core REST.
 */
export async function POST(req: NextRequest) {
  const body = await req.json();
  const { siteId, aiImageIds = [], assignmentIds = [] } = body;

  const site = await prisma.site.findUnique({ where: { id: siteId } });
  if (!site) return NextResponse.json({ error: "Site not found" }, { status: 404 });
  if (!site.wpAuthToken) {
    return NextResponse.json(
      { error: "This site has no WordPress key. Connect it in the WP Library tab with the EkoSEO Bridge JUPITER key." },
      { status: 400 }
    );
  }

  const pluginInfo = await pingPlugin(site);
  if (!pluginInfo && !site.wpApiUrl) {
    return NextResponse.json(
      { error: "The War on Image Connect plugin is unreachable and no core REST URL is configured." },
      { status: 502 }
    );
  }

  async function exportOne(image: ExportableImage, wpPostId: number | null, cpt: string): Promise<number> {
    if (pluginInfo) {
      const res = await fetch(absoluteUrl(image.url));
      if (!res.ok) throw new Error(`Could not fetch image binary: HTTP ${res.status}`);
      const base64 = Buffer.from(await res.arrayBuffer()).toString("base64");
      const uploaded = await uploadWpImage(site!, {
        filename: image.filename,
        base64,
        alt: image.altText,
        title: image.title,
        caption: image.caption || "",
        description: image.description || "",
        attach_to: wpPostId ?? undefined,
        set_featured: Boolean(wpPostId),
      });
      return uploaded.id;
    }
    const legacy = await exportImageToWordPress(
      { wpApiUrl: site!.wpApiUrl!, wpAuthToken: site!.wpAuthToken! },
      { ...image, url: absoluteUrl(image.url) },
      wpPostId,
      cpt === "post" ? "posts" : "pages"
    );
    return legacy.mediaId;
  }

  const results: { type: string; id: string; ok: boolean; mediaId?: number; error?: string }[] = [];

  const aiImages = await prisma.aiImage.findMany({
    where: aiImageIds.length ? { id: { in: aiImageIds } } : { status: "approved", exportStatus: null, page: { siteId } },
    include: { page: true },
  });
  for (const img of aiImages) {
    try {
      const mediaId = await exportOne(
        { url: img.imageUrl, filename: img.filename, altText: img.altText, title: img.title, caption: img.caption, description: img.description },
        img.page.wpPostId,
        img.page.cpt
      );
      await prisma.aiImage.update({
        where: { id: img.id },
        data: { exportedMediaId: mediaId, exportStatus: "exported" },
      });
      results.push({ type: "ai", id: img.id, ok: true, mediaId });
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
      const mediaId = await exportOne(
        {
          url: asg.stockImage.originalUrl,
          filename: asg.filename,
          altText: asg.altText,
          title: asg.stockImage.title || asg.page.title,
          caption: asg.stockImage.caption,
          description: asg.stockImage.description,
        },
        asg.page.wpPostId,
        asg.page.cpt
      );
      await prisma.stockAssignment.update({
        where: { id: asg.id },
        data: { exportedMediaId: mediaId, exportStatus: "exported" },
      });
      results.push({ type: "stock", id: asg.id, ok: true, mediaId });
    } catch (err) {
      await prisma.stockAssignment.update({ where: { id: asg.id }, data: { exportStatus: "failed" } });
      results.push({ type: "stock", id: asg.id, ok: false, error: (err as Error).message });
    }
  }

  return NextResponse.json({
    via: pluginInfo ? "plugin" : "core-rest",
    exported: results.filter((r) => r.ok).length,
    failed: results.filter((r) => !r.ok).length,
    results,
  });
}

function absoluteUrl(url: string): string {
  if (url.startsWith("http")) return url;
  return `${process.env.NEXT_PUBLIC_APP_URL || "http://localhost:3000"}${url}`;
}
